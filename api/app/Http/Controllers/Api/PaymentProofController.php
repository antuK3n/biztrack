<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentProofResource;
use App\Models\Application;
use App\Models\PaymentProof;
use App\Models\User;
use App\Support\Audit;
use App\Support\PermitFees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof of a payment made at the City Hall counter (thesis panel revisions,
 * matrix item 4: "The applicant can now upload a photo of the receipt as
 * proof of payment, or enter the OR number and the date it was paid").
 *
 * The owner chooses Over the Counter on the Pay page, pays at City Hall, and
 * sends what they were given: the OR number and the date paid, a photo of the
 * receipt, or both. Sending it marks NOTHING paid — BPLO's Mark as paid does
 * that (PaymentController::counterPayment), reads this proof, and links it to
 * the payment it records.
 *
 * Who reads it: the filing's owner, and the two readers who hold
 * `application.view_any_office` — BPLO, who marks the bill paid, and the super
 * admin, who audits. No clearance office: a receipt is BPLO's business, and
 * the Tax Order of Payment it settles was never theirs.
 */
class PaymentProofController extends Controller
{
    /** Where the photos go on the private local disk, one folder per filing. */
    private const DIRECTORY = 'private/payment-proofs';

    /** How the fields are named in a refusal Laravel words itself. */
    public const ATTRIBUTES = [
        'or_number' => 'OR number',
        'or_date' => 'date paid',
        'photo' => 'photo of the receipt',
    ];

    /**
     * The owner sends proof. A second send before BPLO confirms REPLACES the
     * pending one — the old row is stamped superseded and kept, file and all.
     */
    public function store(Request $request, Application $application): JsonResponse
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );

        /*
         * Only for a bill that is actually owed, with the owner's own Pay
         * refusals word for word (PaymentController::pay). Proof of paying a
         * filing BPLO has not billed, or one already settled, is proof of
         * nothing anyone can act on.
         */
        $this->refuseWhenNothingIsOwed($application);

        $data = $request->validate([
            // The pair is one answer: an OR number means nothing without its date.
            'or_number' => ['nullable', 'string', 'max:30', 'required_without:photo', 'required_with:or_date'],
            'or_date' => ['nullable', 'date', 'before_or_equal:today', 'required_with:or_number'],
            // The same files a requirement takes (DocumentController::store).
            'photo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'or_number.required_without' => 'Enter the OR number and the date paid, or attach a photo of the receipt.',
            'or_number.required_with' => 'Enter the OR number.',
            'or_date.required_with' => 'Enter the date paid.',
            'or_date.before_or_equal' => 'The date paid cannot be in the future.',
            'photo.max' => 'The file may not be larger than 10MB.',
            'photo.mimes' => 'Upload a PDF, JPG, or PNG file.',
        ], self::ATTRIBUTES);

        $orNumber = isset($data['or_number']) ? trim((string) $data['or_number']) : null;
        $photo = $request->file('photo');
        $stored = $photo instanceof UploadedFile ? self::storePhoto($photo, $application) : null;

        $proof = DB::transaction(function () use ($application, $request, $data, $orNumber, $stored) {
            $replaced = PaymentProof::pendingFor($application);
            $replaced?->update(['superseded_at' => now()]);

            $proof = PaymentProof::create([
                'application_id' => $application->id,
                'fee_assessment_id' => $application->feeAssessment?->id,
                'submitted_by_user_id' => $request->user()->id,
                'or_number' => $orNumber === '' ? null : $orNumber,
                'or_date' => $data['or_date'] ?? null,
                ...($stored ?? []),
                'submitted_at' => now(),
            ]);

            /*
             * Who, when (the row's own), which filing, which fields and which
             * file — and the proof it replaced, so the trail reads as one story
             * rather than two unrelated submissions.
             */
            Audit::log('payment_proof.submitted', $proof, [
                'application_id' => $application->id,
                'tracking_id' => $application->tracking_id,
                'or_number' => $proof->or_number,
                'or_date' => optional($proof->or_date)->toDateString(),
                'file' => $proof->original_filename,
                'replaces_proof_id' => $replaced?->id,
            ]);

            return $proof;
        });

        return response()->json(['data' => new PaymentProofResource($proof)], 201);
    }

    /**
     * The proof waiting for BPLO on this filing, or `data: null`.
     *
     * The owner's Pay page shows it back ("what was sent"); BPLO's Mark as paid
     * dialog shows it and pre-fills the OR from it.
     */
    public function pending(Request $request, Application $application): JsonResponse
    {
        abort_unless(self::mayRead($request->user(), $application), 403, 'You may not access this application.');

        $proof = PaymentProof::pendingFor($application);

        return response()->json(['data' => $proof ? new PaymentProofResource($proof) : null]);
    }

    /** The photo of the receipt, to the same readers as `pending()`. */
    public function file(Request $request, PaymentProof $paymentProof): StreamedResponse
    {
        $application = $paymentProof->application;

        abort_unless(
            $application !== null && self::mayRead($request->user(), $application),
            403,
            'You may not access this document.'
        );
        abort_unless(
            $paymentProof->file_path !== null && Storage::disk('local')->exists($paymentProof->file_path),
            404,
            'File not found.'
        );

        return Storage::disk('local')->download($paymentProof->file_path, $paymentProof->original_filename);
    }

    /**
     * Keep a receipt photo on the private disk and answer the columns that
     * describe it. Shared with Mark as paid, where the officer may attach one.
     *
     * @return array{file_path: string, original_filename: string, mime_type: ?string, size_bytes: int|false}
     */
    public static function storePhoto(UploadedFile $photo, Application $application): array
    {
        $ext = $photo->getClientOriginalExtension() ?: $photo->guessExtension();
        $filename = Str::uuid()->toString().'.'.$ext;
        $directory = self::DIRECTORY.'/'.$application->id;

        Storage::disk('local')->putFileAs($directory, $photo, $filename);

        return [
            'file_path' => "{$directory}/{$filename}",
            'original_filename' => $photo->getClientOriginalName(),
            'mime_type' => $photo->getClientMimeType(),
            'size_bytes' => $photo->getSize(),
        ];
    }

    /** The filing's owner, BPLO, or the super admin. */
    public static function mayRead(User $user, Application $application): bool
    {
        return $application->applicant_user_id === $user->id
            || $user->hasPermission('application.view_any_office');
    }

    /** PaymentController::pay's three refusals, in its words, before anything is kept. */
    private function refuseWhenNothingIsOwed(Application $application): void
    {
        $closed = in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true);

        if (! $closed && ! $application->status->isBillable()) {
            throw ValidationException::withMessages([
                'status' => ['BPLO has not approved this application yet, so there is nothing to pay.'],
            ]);
        }

        if ($application->status === ApplicationStatus::PendingPayment) {
            return;
        }

        if ($closed) {
            throw ValidationException::withMessages([
                'status' => ['This application is closed, so there is nothing left to pay.'],
            ]);
        }

        if (PermitFees::balance($application)['balance_due'] <= 0) {
            throw ValidationException::withMessages([
                'status' => ['This application has nothing outstanding.'],
            ]);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentProofResource;
use App\Models\Application;
use App\Models\PaymentProof;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\Audit;
use App\Support\DownloadLog;
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
 * BPLO hears of each proof in the bell, "Proof of payment received" (revision
 * matrix item 4, follow-up): before that the officer found it only on opening
 * Mark as paid. See WorkflowService::tellBploOfPaymentProof.
 *
 * Who reads it: the filing's owner, and the two readers who hold
 * `application.view_any_office` — BPLO, who marks the bill paid, and the super
 * admin, who audits. No clearance office: a receipt is BPLO's business, and
 * the Tax Order of Payment it settles was never theirs.
 *
 * BPLO judges it (Ken, 3 November 2026). Mark as paid needs a pending proof,
 * and the officer holding the filing may instead reject it with a reason
 * (`reject`). The owner is told in the bell and by e-mail, reads the reason
 * on the Pay page (`latest`), and sends a new one, which tells BPLO again.
 * Until BPLO acts, the owner cannot send another (client, 10 November 2026;
 * see `store`).
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

    public function __construct(private WorkflowService $workflow) {}

    /**
     * The owner sends proof. One receipt waits for BPLO at a time (client,
     * 10 November 2026): while one is pending, another send is refused with
     * one line, so BPLO hears of each receipt once. Until then a second send
     * REPLACED the pending one (the old row stamped `superseded_at`) and told
     * BPLO again, which let an owner spam the bell. Once BPLO rejects the
     * receipt it is no longer pending, so the owner may send a new one.
     *
     * Older filings may already hold several pending rows from the replace
     * era; `PaymentProof::pendingFor` answers the newest of them, so they
     * still read as one receipt waiting.
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

        // Before anything is checked or stored: a second receipt is refused whatever it holds.
        $this->refuseWhileOneIsPending($application);

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
            /*
             * Asked again here for a double press: the first send may have
             * committed while this one was storing its photo. The photo this
             * send stored is removed, since no row will ever point at it.
             */
            if (PaymentProof::pendingFor($application) !== null) {
                if ($stored !== null) {
                    Storage::disk('local')->delete($stored['file_path']);
                }
                $this->refuseWhileOneIsPending($application);
            }

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
             * file. No `replaces_proof_id` any more: nothing is replaced since
             * 10 November 2026, though older rows still carry it.
             */
            Audit::log('payment_proof.submitted', $proof, [
                'application_id' => $application->id,
                'tracking_id' => $application->tracking_id,
                'or_number' => $proof->or_number,
                'or_date' => optional($proof->or_date)->toDateString(),
                'file' => $proof->original_filename,
            ]);

            /*
             * BPLO is told once this commits, never on a send refused above.
             * Asked here, inside the transaction, as WorkflowService's own
             * notices are: the notice waits for the commit (DB::afterCommit)
             * and a failed one cannot undo or 500 the proof.
             */
            $this->workflow->tellBploOfPaymentProof($application);

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

    /**
     * The owner's newest proof on this bill, waiting or rejected, or
     * `data: null` (PaymentProof::latestFor).
     *
     * A second read beside `pending()` rather than a change to it: Mark as
     * paid and Reject receipt must only ever be shown the proof that is
     * waiting, while the owner's Pay page needs the rejected one too, with
     * its reason, so it can say why and ask for another. Same readers.
     */
    public function latest(Request $request, Application $application): JsonResponse
    {
        abort_unless(self::mayRead($request->user(), $application), 403, 'You may not access this application.');

        $proof = PaymentProof::latestFor($application);

        return response()->json(['data' => $proof ? new PaymentProofResource($proof) : null]);
    }

    /**
     * BPLO rejects the owner's receipt, with a reason (Ken, 3 November 2026).
     *
     * Who: the BPLO officer holding the filing, the same person and the same
     * refusal as Mark as paid (PaymentController::authorizeCaseHolder). The
     * super admin, a colleague at BPLO, a clearance office and the owner are
     * all refused with that one sentence.
     *
     * What: only the proof that is waiting. One already superseded, rejected
     * or linked to a payment is refused, so a dialog left open while the owner
     * sent a newer receipt cannot reject the newer one by mistake.
     *
     * The row and its file are kept (PaymentProof), audited, and the owner is
     * told — in the bell and by e-mail, once this commits.
     */
    public function reject(Request $request, PaymentProof $paymentProof): JsonResponse
    {
        $application = $paymentProof->application;
        abort_if($application === null, 404, 'Application not found.');

        PaymentController::authorizeCaseHolder($request->user(), $application);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Enter a reason.',
        ]);
        $reason = trim($data['reason']);

        $proof = DB::transaction(function () use ($request, $application, $paymentProof, $reason) {
            if (PaymentProof::pendingFor($application)?->id !== $paymentProof->id) {
                throw ValidationException::withMessages([
                    'proof' => ['This receipt is no longer waiting for BPLO.'],
                ]);
            }

            $paymentProof->update([
                'rejected_at' => now(),
                'rejected_by_user_id' => $request->user()->id,
                'rejection_reason' => $reason,
            ]);

            // Who and when are the row's own; which filing, which receipt, and why.
            Audit::log('payment_proof.rejected', $paymentProof, [
                'application_id' => $application->id,
                'tracking_id' => $application->tracking_id,
                'or_number' => $paymentProof->or_number,
                'reason' => $reason,
            ]);

            // Written once this commits, and never able to undo or 500 the rejection.
            $this->workflow->tellOwnerOfRejectedProof($application, $reason);

            return $paymentProof;
        });

        return response()->json(['data' => new PaymentProofResource($proof->fresh())]);
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

        // On the audit trail like every other file served (matrix item 2).
        return DownloadLog::record(
            Storage::disk('local')->download($paymentProof->file_path, $paymentProof->original_filename),
            'payment_proof',
            $paymentProof,
        );
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

    /**
     * One receipt waits for BPLO at a time (client, 10 November 2026). A
     * rejected one is not pending (PaymentProof::pendingFor), so the owner
     * may send again after a rejection.
     */
    private function refuseWhileOneIsPending(Application $application): void
    {
        if (PaymentProof::pendingFor($application) !== null) {
            throw ValidationException::withMessages([
                'proof' => ['Your receipt is already with BPLO.'],
            ]);
        }
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

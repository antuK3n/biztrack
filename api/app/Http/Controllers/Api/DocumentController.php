<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\OcrLite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document uploads (private local disk) + policy-checked downloads.
 */
class DocumentController extends Controller
{
    /**
     * The permit an application is FOR. You cannot hand in a copy of the thing
     * you are asking to be issued, so it is never offered as "already held" —
     * a renewal proves the previous one through the PRIOR_PERMIT requirement.
     */
    private const OUTCOME_PERMIT_CODE = 'BUSINESS';

    /** Document-type code prefix for a clearance the applicant already holds. */
    private const HELD_CODE_PREFIX = 'HELD_';

    public function store(Request $request, Application $application): JsonResponse
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );

        $data = $request->validate([
            /*
             * Exactly one of these says what is being attached: an ordinary
             * documentary requirement (document_type_id), or a clearance the
             * applicant already holds and is submitting instead of applying
             * for it again (permit_type_id — checklist item 59).
             */
            'document_type_id' => ['required_without:permit_type_id', 'nullable', 'exists:document_types,id'],
            'permit_type_id' => ['nullable', 'exists:permit_types,id'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'document_type_id.required_without' => 'Say which requirement this file is for.',
            'file.max' => 'The file may not be larger than 10MB.',
            'file.mimes' => 'Upload a PDF, JPG, or PNG file.',
        ]);

        $permitType = empty($data['permit_type_id'])
            ? null
            : PermitType::findOrFail($data['permit_type_id']);

        if ($permitType) {
            abort_if(
                $permitType->code === self::OUTCOME_PERMIT_CODE,
                422,
                "The Mayor's / Business Permit is what this application is for, so it can’t be submitted as one you already hold."
            );
            abort_unless(
                in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true),
                422,
                'A permit you already hold can only be submitted while the application is a draft or has been returned to you.'
            );
            $documentTypeId = $this->heldPermitDocumentType($permitType)->id;
        } else {
            $documentTypeId = (int) $data['document_type_id'];
        }

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $filename = Str::uuid()->toString().'.'.$ext;
        $path = "private/documents/{$application->id}/{$filename}";

        Storage::disk('local')->putFileAs(
            "private/documents/{$application->id}",
            $file,
            $filename
        );

        $doc = ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type_id' => $documentTypeId,
            'permit_type_id' => $permitType?->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        Audit::log('document.uploaded', $doc);

        // One certificate per clearance: re-submitting replaces, so the office
        // never has to work out which of two sanitary permits is the live one.
        if ($permitType) {
            $this->forgetPreviousHeldPermit($application, $permitType, $doc->id);
        }

        $payload = ['data' => new DocumentResource($doc->load('documentType'))];

        // OCR-lite: parse the PDF text layer for suggestions only (never applied).
        if (str_contains(strtolower((string) $file->getClientMimeType()), 'pdf')
            || strtolower((string) $ext) === 'pdf') {
            $suggestions = OcrLite::extract(Storage::disk('local')->path($path));
            if (! empty($suggestions)) {
                $payload['ocr_suggestions'] = $suggestions;
            }
        }

        return response()->json($payload, 201);
    }

    public function download(Request $request, ApplicationDocument $document): StreamedResponse
    {
        $document->loadMissing('application');
        $app = $document->application;

        /*
         * Item 56: `application.view_all` is no longer "every filing" — it is
         * "filings other than my own, in the offices I am routed to". Checking
         * the bare permission here would have handed a sanitary officer any
         * file on any application, id-guessing included, which is the exact
         * leak the scoping exists to close. The owner's own path is unchanged:
         * canView() answers true for the applicant first.
         */
        abort_unless(
            $app !== null && ApplicationVisibility::canView($request->user(), $app),
            403,
            'You may not access this document.'
        );

        abort_unless(Storage::disk('local')->exists($document->stored_path), 404, 'File not found.');

        return Storage::disk('local')->download($document->stored_path, $document->original_filename);
    }

    /**
     * The document type used for a certificate the applicant already holds.
     *
     * Created on demand rather than seeded: the set of clearances is data, so
     * an LGU that adds a permit type gets the matching "already held" slot
     * without a migration. The name carries the permit, which is what makes
     * the attachment readable in an officer's document list.
     */
    private function heldPermitDocumentType(PermitType $permitType): DocumentType
    {
        return DocumentType::firstOrCreate(
            ['code' => self::HELD_CODE_PREFIX.$permitType->code],
            [
                'name' => $permitType->name.' (already held)',
                'help_text' => 'A copy of the '.$permitType->name
                    .' this business already holds, submitted instead of applying for it again.',
            ],
        );
    }

    /** Drop any earlier certificate for the same clearance, file and all. */
    private function forgetPreviousHeldPermit(Application $application, PermitType $permitType, int $keepId): void
    {
        $stale = ApplicationDocument::where('application_id', $application->id)
            ->where('permit_type_id', $permitType->id)
            ->whereKeyNot($keepId)
            ->get();

        foreach ($stale as $old) {
            if ($old->stored_path && Storage::disk('local')->exists($old->stored_path)) {
                Storage::disk('local')->delete($old->stored_path);
            }
            Audit::log('document.removed', $old);
            $old->delete();
        }
    }
}

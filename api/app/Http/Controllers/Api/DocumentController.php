<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Application;
use App\Models\ApplicationDocument;
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
    public function store(Request $request, Application $application): JsonResponse
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );

        $data = $request->validate([
            /*
             * A documentary requirement, and only that.
             *
             * `permit_type_id` stood beside it until 4 October 2026 — a
             * clearance the applicant already held, submitted instead of
             * applied for — and the client had the whole route removed:
             * *"IT IS NOT POSSIBLE FOR THE USER TO SUBMIT A COPY OF AN OTHER
             * PERMIT."* It was the second door into that feature and never a
             * reachable one; the note this replaces said so itself, that
             * `documents.upload`'s optional `permitTypeId` was passed by
             * nobody. A back door into a removed feature is worse than the
             * feature.
             */
            'document_type_id' => ['required', 'exists:document_types,id'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'document_type_id.required' => 'Say which requirement this file is for.',
            'file.max' => 'The file may not be larger than 10MB.',
            'file.mimes' => 'Upload a PDF, JPG, or PNG file.',
        ]);

        $documentTypeId = (int) $data['document_type_id'];

        $file = $request->file('file');

        /*
         * ── The same bytes twice is not a second copy ──────────────────
         *
         * An applicant answering a return uploaded one file twice, five
         * minutes apart, and the officer's Section C showed it as both the
         * current copy and the newest "earlier copy" — same name, same
         * size, same date. Nobody can act on the difference between two
         * identical files, because there is none.
         *
         * Compared against the NEWEST copy only. Sending A, then B, then A
         * again is a real statement — the applicant has gone back to the
         * first version — and that is history worth keeping.
         *
         * `file_hash` has been on this table since the beginning and was
         * never written to; this is what it was for.
         */
        $hash = hash_file('sha256', $file->getRealPath());
        $sameAgain = ApplicationDocument::where('application_id', $application->id)
            ->where('document_type_id', $documentTypeId)
            ->whereNull('permit_type_id')
            ->latest('id')
            ->first();

        if ($sameAgain !== null && $sameAgain->file_hash === $hash) {
            /*
             * Answered as a success with the copy already held. A 422 would
             * be technically defensible and useless: the applicant has the
             * file they meant to send on the filing, which is what they
             * were trying to achieve.
             */
            return response()->json(['data' => new DocumentResource($sameAgain)], 201);
        }

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
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            /* What the duplicate check above reads on the next upload. */
            'file_hash' => $hash,
        ]);

        Audit::log('document.uploaded', $doc);


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

        /*
         * SEP-8, the second half. Filtering the list is not a boundary.
         *
         * `canView` above answers "may you open this filing", which every office
         * routed to it can. An attachment carrying a `permit_type_id` is a
         * permit the applicant already holds, filed as that office's evidence —
         * so the finer question has to be asked too, or the sanitary officer is
         * one typed id away from a Fire Safety Inspection Certificate the list
         * correctly declined to show them. Document ids are sequential.
         *
         * Attachments with no permit type are shared requirements and are
         * untouched: `readsOfficeSheet` is only consulted when there is an
         * office to consult it about.
         */
        abort_unless(
            $document->permit_type_id === null
                || ApplicationVisibility::readsOfficeSheet(
                    $request->user(),
                    $document->loadMissing('permitType')->permitType?->issuing_department_id,
                ),
            403,
            'This certificate was filed with another office.'
        );

        abort_unless(Storage::disk('local')->exists($document->stored_path), 404, 'File not found.');

        return Storage::disk('local')->download($document->stored_path, $document->original_filename);
    }
}

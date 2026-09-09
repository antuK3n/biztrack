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
use App\Support\HeldPermits;
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
                ! $permitType->isClearance(),
                422,
                "The Mayor's / Business Permit is what this application is for, so it can’t be submitted as one you already hold."
            );
            /*
             * When a certificate the applicant already holds may be uploaded
             * here: while the filing is still theirs to edit.
             *
             * ── CLR-5 · what this comment used to claim ──────────────────
             *
             * It read: *"This is the wizard's path, and the wizard only exists
             * before the filing leaves the applicant. The post-payment
             * clearance stage takes the same upload through
             * ClearanceController, which has its own gate (the stage opens on
             * payment) — so the two windows do not overlap and neither has to
             * know about the other."*
             *
             * That became false when `4fb2d54` moved the clearance stage from
             * after payment to before submission: `isUnlocked` became
             * `status === Draft`, its window a SUBSET of this one rather than a
             * successor, and on a draft both endpoints were open at once.
             *
             * ── And it is TRUE again as of 6 September 2026 ──────────────────
             *
             * The verified counter procedure puts the clearance stage back after
             * payment, so `ClearanceService::isUnlocked` is now
             * `status->isPaid()`. This endpoint allows Draft and Returned. Those
             * two sets are disjoint — a paid filing is neither — so the windows
             * genuinely do not overlap and neither endpoint can write the state
             * the other refuses.
             *
             * Do NOT read that as the guard being unnecessary. It is one
             * `isUnlocked` edit away from overlapping again, and the invariant
             * below is what would break first.
             *
             * What that used to cost: `ClearanceController::storeHeld` refused
             * a held copy while the same permit type was attached to the filing,
             * because a clearance was either one the applicant holds or one they
             * are asking us to issue, never both. This endpoint performs no such
             * check, so a direct POST with `permit_type_id` could write the state
             * that refusal existed to prevent.
             *
             * That invariant INVERTED on 6 September 2026 and the exposure went
             * with it. Five of the six permits are required now, so every one of
             * them is on the pivot from submission whichever way it will be
             * satisfied, and `application_permit_types.mode` — not the presence
             * or absence of the row — is what says whether the applicant filled
             * a form or handed in a copy. There is no longer a contradictory
             * state for the two records to be in. What `storeHeld` refuses
             * instead is narrower and about timing: you cannot swap the evidence
             * after the office has accepted it.
             *
             * It is not reachable from the product: `documents.upload`'s
             * optional `permitTypeId` (web/src/lib/resources.ts) is passed by
             * nobody, and both wizard call sites send three arguments. Every
             * held copy in the register arrived through ClearanceController.
             * That is why this is a corrected comment and not a new guard —
             * adding one means deciding what a Returned filing's held copy is
             * worth (this path allows Returned, the clearance stage does not,
             * and a copy filed on a returned filing can only be removed
             * through a third endpoint the clearance UI never calls), and that
             * is a rule about money and scope, not a docblock fix. Recorded
             * here so the next person to give this parameter a caller knows
             * they are the one who has to answer it.
             */
            abort_unless(
                in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true),
                422,
                'A permit you already hold can only be submitted while the application is a draft or has been returned to you.'
            );
            $documentTypeId = HeldPermits::documentType($permitType)->id;
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
            HeldPermits::forgetAllExcept($application, $permitType, $doc->id);
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

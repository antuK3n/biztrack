<?php

namespace App\Http\Controllers\Api;

use App\Enums\OfficerRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfficerRequestResource;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\OfficerRequest;
use App\Services\NotificationService;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Other Requirements" — officers ask applicants for extra documents/messages;
 * applicants respond (optionally with a document); officers close the loop.
 * Thin controller: rows + audit + notifications. Application status untouched.
 */
class OfficerRequestController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    /** Officer creates a request against an application (request.create). */
    public function store(Request $request, Application $application): JsonResponse
    {
        // An office may only ask for requirements on a filing it is part of;
        // asking is itself a read of the application (checklist item 56).
        ApplicationVisibility::authorize(
            $request->user(),
            $application,
            'You may not raise a requirement on another office’s application.'
        );

        // Compat: web/mobile send subject/body (docs/api-contract.md); the paper
        // schema uses title/description. Accept either name for each.
        $request->merge([
            'title' => $request->input('title', $request->input('subject')),
            'description' => $request->input('description', $request->input('body')),
        ]);

        $data = $request->validate([
            /*
             * Optional, and "document" when unsaid.
             *
             * An Other Requirement IS a document request — the client is
             * explicit that there is no Type to choose — so the composer no
             * longer asks and no longer sends one. The rule stays permissive
             * rather than being deleted because `message` and `meeting` are
             * real rows in this table with their own callers; removing the
             * field outright would break them to tidy up a form.
             */
            'request_type' => ['sometimes', 'in:document,message,meeting'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date'],
            // The note written when the requirement is raised — NOT the review
            // verdict, which is `remarks` and is written by close().
            'additional_remarks' => ['nullable', 'string', 'max:2000'],
            // A blank form, a template, a sample of a valid certificate.
            'reference' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            // Meeting fields (officer-provided; no live calendar call — future work).
            'meeting_scheduled_at' => ['nullable', 'date', 'required_if:request_type,meeting'],
            'meeting_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'meeting_link' => ['nullable', 'string', 'max:500'],
            'meeting_platform' => ['nullable', 'string', 'max:50'],
        ]);

        /*
         * ── The office is taken from the account, never from the request ─────
         *
         * "Do not allow an Admin to manually change the office assigned to the
         * request. The office must be retrieved from the authenticated account
         * ... enforced by the backend, not only by hiding the field in the
         * frontend." So `department_id` is not in the rules above and is not
         * read here: a caller may post one and it is ignored, which is the only
         * version of this that a hidden form field cannot be talked out of.
         *
         * An account with no office cannot raise a requirement at all. That is
         * the super admin, by construction — it belongs to no office, oversees
         * the register rather than working inside it, and holds no
         * `request.create` in the RBAC matrix, so this is a second lock on a
         * door already shut rather than a new restriction. Said out loud
         * because the alternative — writing NULL — produced requirements the
         * applicant saw as coming from nobody, and which no office's list
         * could ever match.
         */
        $office = $request->user()->department_id;
        if ($office === null) {
            throw ValidationException::withMessages([
                'department_id' => ['This account belongs to no office, so it cannot raise a requirement. Requirements are always raised by an office.'],
            ]);
        }

        $reference = $this->storeReferenceFile($request, $application);

        $officerRequest = OfficerRequest::create([
            'application_id' => $application->id,
            'requested_by_user_id' => $request->user()->id,
            'department_id' => $office,
            'request_type' => $data['request_type'] ?? 'document',
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'additional_remarks' => $data['additional_remarks'] ?? null,
            'reference_path' => $reference['path'],
            'reference_name' => $reference['name'],
            'due_date' => $data['due_date'] ?? null,
            'meeting_scheduled_at' => $data['meeting_scheduled_at'] ?? null,
            'meeting_duration_minutes' => $data['meeting_duration_minutes'] ?? 30,
            'meeting_link' => $data['meeting_link'] ?? null,
            'meeting_platform' => $data['meeting_platform'] ?? 'google_meet',
            // Nothing has been submitted, so the applicant owes a document.
            'status' => OfficerRequestStatus::Pending,
        ]);

        Audit::log('request.created', $officerRequest, ['department_id' => $office]);

        $application->loadMissing('applicant');
        if ($application->applicant) {
            $this->notify->requestCreated($officerRequest->load('application'), $application->applicant);
        }

        return response()->json([
            'data' => new OfficerRequestResource($officerRequest->load($this->eager())),
        ], 201);
    }

    /**
     * Put the office's optional reference file on the private disk.
     *
     * Private, like every other upload here: it is attached to one filing and
     * readable by that filing's applicant and the office that raised the
     * requirement, which is a decision the download route makes per request —
     * not one a public URL can make at all.
     *
     * @return array{path: ?string, name: ?string}
     */
    private function storeReferenceFile(Request $request, Application $application): array
    {
        $file = $request->file('reference');
        if (! $file) {
            return ['path' => null, 'name' => null];
        }

        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $filename = Str::uuid()->toString().'.'.$ext;
        $dir = "private/requirement-references/{$application->id}";
        Storage::disk('local')->putFileAs($dir, $file, $filename);

        return ['path' => "{$dir}/{$filename}", 'name' => $file->getClientOriginalName()];
    }

    /**
     * Download the office's reference file.
     *
     * Two readers, and no third: the applicant the requirement was addressed
     * to, and the office that raised it. `ApplicationVisibility::authorize`
     * covers the office side and lets the filing's own applicant through, so
     * the check is the same one that guards reading the requirement itself —
     * a file that could be fetched by anyone holding the id would make the
     * office boundary decorative.
     */
    public function reference(Request $request, OfficerRequest $officerRequest)
    {
        $officerRequest->loadMissing('application');
        abort_unless($officerRequest->application, 404, 'The application behind this request no longer exists.');
        abort_unless($officerRequest->reference_path, 404, 'This requirement has no reference file.');

        ApplicationVisibility::authorize(
            $request->user(),
            $officerRequest->application,
            'This requirement belongs to another office’s application.'
        );

        abort_unless(
            Storage::disk('local')->exists($officerRequest->reference_path),
            404,
            'The reference file is no longer on file.'
        );

        return Storage::disk('local')->download(
            $officerRequest->reference_path,
            $officerRequest->reference_name ?? 'reference'
        );
    }

    /**
     * List — owner sees requests on own apps; officer sees dept-visible/created.
     *
     * Paginated, newest first. 123 rows was 157 KB unpaged: each row carries the
     * full reply thread, so this list is heavy per row rather than long, and it
     * grows with conversation rather than with filings.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = OfficerRequest::with($this->eager());
        $user = $request->user();

        if (ApplicationVisibility::readsEveryOffice($user)) {
            // BPLO and the super admin coordinate every office's requests.
        } elseif ($user->hasPermission('application.view_all')) {
            /*
             * Checklist item 111: an office sees its OWN requirements, not every
             * requirement on a filing it happens to share.
             *
             * The previous rule was "any request on an application my department
             * is assigned to". That reads correctly until you remember what
             * WorkflowService::routeToDepartments actually does: it creates one
             * assignment per office that issues a requested permit type, so a
             * normal six-clearance filing is shared by six offices at once. Every
             * one of them then matched `application.assignments`, and each office
             * read all six offices' requirements. Against the tester register the
             * sanitary officer's list came back 100 rows: 37 raised by BPLO, 21 by
             * the fire office, 10 by planning — only 32 its own. That is the
             * "other requirements from other accounts from other offices show up"
             * the client reported, and it is a privacy defect, not a filter bug.
             *
             * The office boundary is `officer_requests.department_id` — the column
             * the composer has written since item 57 and which the applicant
             * already sees as `from_office`. Matching on it is what makes "from
             * the City Health Office" and "visible to the City Health Office" the
             * same statement.
             *
             * `requested_by_user_id` stays as a second, deliberate door: an
             * officer may raise a requirement on another office's behalf (that is
             * exactly why department_id is overridable in store()), and losing
             * sight of something you yourself sent would be a worse bug than the
             * leak. It is scoped to the acting user, not to their office, so it
             * cannot re-open the cross-office view.
             */
            $deptId = $user->department_id;
            $query->where(function ($q) use ($user, $deptId) {
                $q->where('requested_by_user_id', $user->id);
                if ($deptId) {
                    $q->orWhere('department_id', $deptId);
                }
            });
        } else {
            // Owner: requests on their own applications.
            $query->whereHas('application', fn ($a) => $a->where('applicant_user_id', $user->id));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $requests = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => OfficerRequestResource::collection($requests->items()),
            'meta' => $this->pageMeta($requests),
        ]);
    }

    /**
     * Applicant responds (request.respond), optionally attaching a document.
     * Repeatable: one request often needs several uploads or a follow-up note,
     * so every reply is appended to officer_request_responses and the request
     * simply stays `submitted` until the officer closes it.
     */
    public function respond(Request $request, OfficerRequest $officerRequest): JsonResponse
    {
        $officerRequest->loadMissing('application.applicant', 'createdBy');
        // A request whose application has gone is unanswerable, not a 500: the
        // ownership check below dereferences it, so say so before it does.
        abort_unless($officerRequest->application, 404, 'The application behind this request no longer exists.');
        abort_unless(
            $officerRequest->application->applicant_user_id === $request->user()->id,
            403,
            'This request is not yours to respond to.'
        );
        /*
         * Only a CLOSED requirement stops accepting replies.
         *
         * Pending and Submitted always did. NeedsResubmission now does too, and
         * that is the point of the state: an office that asked for a clearer
         * copy has to be able to receive one. Before this, rejecting made the
         * requirement permanently unanswerable, so the resubmission the office
         * had just asked for was refused by the same endpoint.
         *
         * The rule lives on the enum so this check and the UI cannot drift.
         */
        if (! $officerRequest->status?->acceptsResponse()) {
            throw ValidationException::withMessages(['status' => ['This request is closed, so you can no longer respond to it.']]);
        }

        $data = $request->validate([
            // A file-only reply is valid, which is what the Respond form already allows.
            'body' => ['required_without:document', 'nullable', 'string', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            // Optional: the Respond form has no type picker, so an unlabelled
            // upload files itself under the "Other Requirements" type.
            'document_type_id' => ['nullable', 'exists:document_types,id'],
        ]);

        DB::transaction(function () use ($request, $officerRequest, $data) {
            $documentId = null;
            $fileName = null;
            $filePath = null;
            if ($file = $request->file('document')) {
                $app = $officerRequest->application;
                $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
                $filename = Str::uuid()->toString().'.'.$ext;
                $dir = "private/documents/{$app->id}";
                Storage::disk('local')->putFileAs($dir, $file, $filename);

                $doc = ApplicationDocument::create([
                    'application_id' => $app->id,
                    'document_type_id' => $data['document_type_id']
                        ?? DocumentType::where('code', 'OTHER')->value('id'),
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => "{$dir}/{$filename}",
                    'mime_type' => $file->getClientMimeType(),
                    'size_bytes' => $file->getSize(),
                ]);
                Audit::log('document.uploaded', $doc);
                $documentId = $doc->id;
                $fileName = $doc->original_filename;
                $filePath = $doc->stored_path;
            }

            $officerRequest->responses()->create([
                'user_id' => $request->user()->id,
                'body' => $data['body'] ?? null,
                'application_document_id' => $documentId,
                'file_name' => $fileName,
                'file_path' => $filePath,
            ]);

            // Mirror the latest reply onto the parent for v2-contract clients
            // (response_body / responded_at).
            $mirror = [
                'status' => OfficerRequestStatus::Submitted,
                'applicant_response' => $data['body'] ?? null,
                'submitted_at' => now(),
            ];
            // The document pointer only moves when this reply carried a file,
            // so a later text-only reply does not orphan an earlier upload.
            if ($documentId !== null) {
                $mirror += [
                    'application_document_id' => $documentId,
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                ];
            }
            $officerRequest->update($mirror);
        });

        Audit::log('request.responded', $officerRequest);
        if ($officerRequest->createdBy) {
            $this->notify->requestResponded($officerRequest->fresh()->load('application'), $officerRequest->createdBy);
        }

        return response()->json([
            'data' => new OfficerRequestResource($officerRequest->fresh()->load($this->eager())),
        ]);
    }

    /** Officer closes the request (request.create gate) with an outcome. */
    public function close(Request $request, OfficerRequest $officerRequest): JsonResponse
    {
        $officerRequest->loadMissing('application.applicant');
        abort_unless($officerRequest->application, 404, 'The application behind this request no longer exists.');
        // Closing a request reads and decides on the filing behind it, so the
        // same office boundary applies (checklist item 56).
        ApplicationVisibility::authorize(
            $request->user(),
            $officerRequest->application,
            'This request belongs to another office’s application.'
        );

        /*
         * Item 111: reading the filing is not enough — the requirement has to be
         * yours to close.
         *
         * ApplicationVisibility answers "may this office open this filing", and on
         * a shared six-clearance filing it says yes to all six offices. Closing is
         * a stronger act than reading: marking a requirement fulfilled or rejected
         * decides whether the applicant's answer satisfied the office that asked,
         * and only the office that asked knows that. Without this the fire office
         * could accept a water potability result on the City Health Office's
         * behalf, and the audit row would carry the wrong department's judgement.
         *
         * Same two doors as index(): your own office, or a request you raised
         * yourself on another office's behalf. BPLO and the super admin keep the
         * wider hand deliberately — BPLO coordinates every other office's
         * clearance and has to be able to unblock a filing when an office has
         * gone quiet, which is the whole reason it holds view_any_office.
         */
        $user = $request->user();
        if (! ApplicationVisibility::readsEveryOffice($user)) {
            abort_unless(
                $officerRequest->requested_by_user_id === $user->id
                    || ($user->department_id && $officerRequest->department_id === $user->department_id),
                403,
                'This requirement was raised by another office, so only that office can close it.'
            );
        }

        $data = $request->validate([
            'outcome' => ['required', 'in:fulfilled,needs_resubmission,rejected'],
            /*
             * A remark is required for anything but acceptance.
             *
             * The column existed and nothing ever wrote to it, so an applicant
             * whose document was turned down saw the status change and no
             * reason anywhere — the one thing they need in order to act. Saying
             * "Needs Resubmission" without saying what was wrong just moves the
             * question to a phone call.
             *
             * Accepting needs no words: the outcome is the whole message.
             */
            'remarks' => ['required_unless:outcome,fulfilled', 'nullable', 'string', 'max:2000'],
        ], [
            'remarks.required_unless' => 'Say what was wrong, so the applicant knows what to fix.',
        ]);

        $outcome = OfficerRequestStatus::from($data['outcome']);

        DB::transaction(function () use ($officerRequest, $request, $data, $outcome) {
            $officerRequest->update([
                'status' => $outcome,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                // Kept on acceptance too when one was given, and cleared when it
                // was not: a stale remark from an earlier rejection sitting under
                // an approved requirement reads as a fresh complaint.
                'remarks' => $data['remarks'] ?? null,
            ]);

            /*
             * Stamp the verdict on the submission it actually judged.
             *
             * The parent carries one status and one remark, which are always
             * the LATEST verdict — so once a requirement goes round twice the
             * history read "Submission #1, Submission #2" with a single remark
             * floating above both, belonging to neither, and changing under the
             * applicant each time an officer ruled. An office reviews the
             * newest submission, so that is the row this belongs on.
             *
             * A requirement can also be closed with nothing submitted at all —
             * withdrawn as raised in error — and then there is no submission to
             * stamp and none is invented.
             */
            $latest = $officerRequest->responses()->latest('id')->first();
            $latest?->update([
                'review_outcome' => $outcome->value,
                'review_remarks' => $data['remarks'] ?? null,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $request->user()->id,
            ]);
        });

        Audit::log('request.closed', $officerRequest, [
            'outcome' => $data['outcome'],
        ]);

        if ($officerRequest->application->applicant) {
            // Same ping either way — the applicant needs to know the office has
            // ruled, and "needs resubmission" is the outcome they most need to
            // hear because it is the one that asks something of them.
            $this->notify->requestClosed($officerRequest->fresh()->load('application'), $officerRequest->application->applicant);
        }

        return response()->json([
            'data' => new OfficerRequestResource($officerRequest->fresh()->load($this->eager())),
        ]);
    }

    private function eager(): array
    {
        return [
            'createdBy.department',
            // The office the composer attributed this to (`from_office`), which
            // is not always the requester's own — see OfficerRequestResource.
            'department',
            'application.business:id,name',
            // The recipient (item 89). Named on the letter so both sides can see
            // who it went to rather than inferring it from the application.
            'application.applicant:id,name',
            'responses.author:id,name',
        ];
    }
}

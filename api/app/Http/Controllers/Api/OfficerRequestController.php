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
            'request_type' => ['required', 'in:document,message,meeting'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date'],
            /*
             * Which office the applicant should see this coming from. Defaults
             * to the requester's own, but the super admin has no department, so
             * without this their requests reach the applicant attributed to
             * nobody. Officers may also raise one on another office's behalf.
             */
            'department_id' => ['nullable', 'exists:departments,id'],
            // Meeting fields (officer-provided; no live calendar call — future work).
            'meeting_scheduled_at' => ['nullable', 'date', 'required_if:request_type,meeting'],
            'meeting_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'meeting_link' => ['nullable', 'string', 'max:500'],
            'meeting_platform' => ['nullable', 'string', 'max:50'],
        ]);

        $officerRequest = OfficerRequest::create([
            'application_id' => $application->id,
            'requested_by_user_id' => $request->user()->id,
            'department_id' => $data['department_id'] ?? $request->user()->department_id,
            'request_type' => $data['request_type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'meeting_scheduled_at' => $data['meeting_scheduled_at'] ?? null,
            'meeting_duration_minutes' => $data['meeting_duration_minutes'] ?? 30,
            'meeting_link' => $data['meeting_link'] ?? null,
            'meeting_platform' => $data['meeting_platform'] ?? 'google_meet',
            'status' => OfficerRequestStatus::Pending,
        ]);

        Audit::log('request.created', $officerRequest);

        $application->loadMissing('applicant');
        if ($application->applicant) {
            $this->notify->requestCreated($officerRequest->load('application'), $application->applicant);
        }

        return response()->json([
            'data' => new OfficerRequestResource($officerRequest->load($this->eager())),
        ], 201);
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
        // Only a closed request stops accepting replies; pending and submitted both do.
        if (in_array($officerRequest->status, [OfficerRequestStatus::Fulfilled, OfficerRequestStatus::Rejected], true)) {
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
            'outcome' => ['required', 'in:fulfilled,rejected'],
        ]);

        $officerRequest->update([
            'status' => OfficerRequestStatus::from($data['outcome']),
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        Audit::log('request.closed', $officerRequest, ['outcome' => $data['outcome']]);

        if ($officerRequest->application->applicant) {
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

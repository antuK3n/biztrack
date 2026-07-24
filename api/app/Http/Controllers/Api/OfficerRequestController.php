<?php

namespace App\Http\Controllers\Api;

use App\Enums\OfficerRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfficerRequestResource;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\OfficerRequest;
use App\Services\NotificationService;
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
            // Meeting fields (officer-provided; no live calendar call — future work).
            'meeting_scheduled_at' => ['nullable', 'date', 'required_if:request_type,meeting'],
            'meeting_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'meeting_link' => ['nullable', 'string', 'max:500'],
            'meeting_platform' => ['nullable', 'string', 'max:50'],
        ]);

        $officerRequest = OfficerRequest::create([
            'application_id' => $application->id,
            'requested_by_user_id' => $request->user()->id,
            'department_id' => $request->user()->department_id,
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

    /** List — owner sees requests on own apps; officer sees dept-visible/created. */
    public function index(Request $request): JsonResponse
    {
        $query = OfficerRequest::with($this->eager());
        $user = $request->user();

        if ($user->hasPermission('application.view_all')) {
            // Officer: requests they created OR on applications in their department queue.
            $deptId = $user->department_id;
            $query->where(function ($q) use ($user, $deptId) {
                $q->where('requested_by_user_id', $user->id);
                if ($deptId) {
                    $q->orWhereHas('application.assignments', fn ($a) => $a->where('department_id', $deptId));
                }
            });
        } else {
            // Owner: requests on their own applications.
            $query->whereHas('application', fn ($a) => $a->where('applicant_user_id', $user->id));
        }

        $requests = $query->orderByDesc('created_at')->get();

        return response()->json(['data' => OfficerRequestResource::collection($requests)]);
    }

    /** Applicant responds (request.respond), optionally attaching a document. */
    public function respond(Request $request, OfficerRequest $officerRequest): JsonResponse
    {
        $officerRequest->loadMissing('application.applicant', 'createdBy');
        abort_unless(
            $officerRequest->application->applicant_user_id === $request->user()->id,
            403,
            'This request is not yours to respond to.'
        );
        if ($officerRequest->status !== OfficerRequestStatus::Pending) {
            throw ValidationException::withMessages(['status' => ['This request has already been answered.']]);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'document_type_id' => ['required_with:document', 'exists:document_types,id'],
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
                    'document_type_id' => $data['document_type_id'],
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

            $officerRequest->update([
                'status' => OfficerRequestStatus::Submitted,
                'applicant_response' => $data['body'],
                'application_document_id' => $documentId,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'submitted_at' => now(),
            ]);
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
        return ['createdBy.department', 'application.business:id,name'];
    }
}

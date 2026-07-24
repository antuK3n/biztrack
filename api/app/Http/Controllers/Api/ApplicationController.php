<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationListResource;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Business;
use App\Services\WorkflowService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Application lifecycle. All state changes are delegated to WorkflowService —
 * this controller never mutates applications.status directly.
 */
class ApplicationController extends Controller
{
    public function __construct(private WorkflowService $workflow) {}

    private array $fullEager = [
        'business.address.barangay', 'business.lines.psicCode', 'applicant', 'permitTypes',
        'documents.documentType', 'feeAssessment', 'payments',
        'assignments.department', 'assignments.officer',
        'inspections.department', 'inspections.inspector', 'permits.permitType', 'permits.business', 'permits.application',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Application::with(['business:id,name', 'permitTypes:id,code,name']);

        if (! $request->user()->hasPermission('application.view_all')) {
            $query->where('applicant_user_id', $request->user()->id);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('application_type', $type);
        }
        if ($q = $request->query('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('tracking_id', 'like', "%{$q}%")
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', "%{$q}%"));
            });
        }

        $apps = $query->orderByDesc('created_at')->get();

        return response()->json(['data' => ApplicationListResource::collection($apps)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'exists:businesses,id'],
            'application_type' => ['required', 'in:new,renewal,amendment'],
            'permit_type_ids' => ['required', 'array', 'min:1'],
            'permit_type_ids.*' => ['exists:permit_types,id'],
            'prior_permit_id' => ['nullable', 'exists:permits,id'],
        ]);

        $business = Business::findOrFail($data['business_id']);
        abort_unless($business->owner_user_id === $request->user()->id, 403, 'This business is not yours.');

        if ($business->isBlockedFromApplying()) {
            throw ValidationException::withMessages([
                'business_id' => ['This business currently can’t file applications. Please contact the LGU to resolve its account status.'],
            ]);
        }

        // A prior permit (for renewals/amendments) must belong to the same business.
        if (! empty($data['prior_permit_id'])) {
            $priorOk = $business->permits()->whereKey($data['prior_permit_id'])->exists();
            abort_unless($priorOk, 422, 'The selected prior permit does not belong to this business.');
        }

        $app = Application::create([
            'business_id' => $business->id,
            'applicant_user_id' => $request->user()->id,
            'application_type' => $data['application_type'],
            'status' => ApplicationStatus::Draft,
            'prior_permit_id' => $data['prior_permit_id'] ?? null,
        ]);
        $app->permitTypes()->sync($data['permit_type_ids']);

        Audit::log('application.created', $app);

        return response()->json([
            'data' => new ApplicationResource($app->load($this->fullEager)),
        ], 201);
    }

    public function show(Request $request, Application $application): JsonResponse
    {
        $this->authorizeView($request, $application);

        return response()->json([
            'data' => new ApplicationResource($application->load($this->fullEager)),
        ]);
    }

    public function update(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        abort_unless(
            $application->status === ApplicationStatus::Draft,
            422,
            'Only draft applications can be edited.'
        );

        $data = $request->validate([
            'business_id' => ['sometimes', 'exists:businesses,id'],
            'permit_type_ids' => ['sometimes', 'array', 'min:1'],
            'permit_type_ids.*' => ['exists:permit_types,id'],
        ]);

        if (isset($data['business_id'])) {
            $business = Business::findOrFail($data['business_id']);
            abort_unless($business->owner_user_id === $request->user()->id, 403, 'This business is not yours.');
            $application->update(['business_id' => $business->id]);
        }
        if (isset($data['permit_type_ids'])) {
            $application->permitTypes()->sync($data['permit_type_ids']);
        }

        Audit::log('application.updated', $application);

        return response()->json([
            'data' => new ApplicationResource($application->fresh()->load($this->fullEager)),
        ]);
    }

    public function submit(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        if ($application->status !== ApplicationStatus::Draft) {
            throw ValidationException::withMessages(['status' => ['Only draft applications can be submitted.']]);
        }

        $application = $this->workflow->submit($application);

        return response()->json([
            'data' => new ApplicationResource($application->load($this->fullEager)),
        ]);
    }

    public function resubmit(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        if ($application->status !== ApplicationStatus::Returned) {
            throw ValidationException::withMessages(['status' => ['Only returned applications can be resubmitted.']]);
        }

        $this->workflow->resubmit($application);

        return response()->json([
            'data' => new ApplicationResource($application->fresh()->load($this->fullEager)),
        ]);
    }

    public function cancel(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        $allowed = [
            ApplicationStatus::Draft,
            ApplicationStatus::Submitted,
            ApplicationStatus::PendingPayment,
        ];
        if (! in_array($application->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ['This application can no longer be cancelled.']]);
        }

        $this->workflow->transition($application, ApplicationStatus::Cancelled, 'Cancelled by applicant.');

        return response()->json([
            'data' => new ApplicationResource($application->fresh()->load($this->fullEager)),
        ]);
    }

    public function reject(Request $request, Application $application): JsonResponse
    {
        // Gated by permission:application.review on the route.
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'A rejection reason is required.',
        ]);

        if ($application->status->isTerminal()) {
            throw ValidationException::withMessages(['status' => ['This application is already decided.']]);
        }

        $this->workflow->rejectApplication($application, $data['reason']);

        return response()->json([
            'data' => new ApplicationResource($application->fresh()->load($this->fullEager)),
        ]);
    }

    public function timeline(Request $request, Application $application): JsonResponse
    {
        $this->authorizeView($request, $application);

        $rows = $application->statusHistory()->with('changedBy:id,name')->get();

        $data = $rows->map(fn ($row) => [
            'from_status' => $row->from_status,
            'to_status' => $row->to_status,
            'note' => $row->note,
            'changed_by' => $row->changedBy ? ['name' => $row->changedBy->name] : null,
            'created_at' => optional($row->created_at)->toISOString(),
        ]);

        return response()->json(['data' => $data]);
    }

    // --- authorization helpers ----------------------------------------------
    private function authorizeOwner(Request $request, Application $application): void
    {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );
    }

    private function authorizeView(Request $request, Application $application): void
    {
        if ($application->applicant_user_id === $request->user()->id) {
            return;
        }
        abort_unless($request->user()->hasPermission('application.view_all'), 403, 'You may not view this application.');
    }
}

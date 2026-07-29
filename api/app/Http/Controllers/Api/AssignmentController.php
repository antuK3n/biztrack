<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\AssignmentResource;
use App\Models\ApplicationAssignment;
use App\Models\ComplianceCheck;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Officer review queues, scoped to the caller's department (admin sees all).
 * Approve/return/reject delegate to WorkflowService.
 */
class AssignmentController extends Controller
{
    public function __construct(private WorkflowService $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $query = ApplicationAssignment::with([
            'department', 'officer',
            'application.business:id,name',
        ]);

        $this->scopeToDepartment($request, $query);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $assignments = $query->orderByDesc('assigned_at')->get();

        return response()->json(['data' => AssignmentResource::collection($assignments)]);
    }

    public function show(Request $request, ApplicationAssignment $assignment): JsonResponse
    {
        $this->authorizeDepartment($request, $assignment);

        $assignment->load([
            'department', 'officer',
            'application.business.address.barangay', 'application.business.lines.psicCode',
            'application.applicant', 'application.permitTypes',
            'application.documents.documentType', 'application.feeAssessment',
            'application.officeForms.permitType.department',
            'application.payments', 'application.assignments.department',
            'application.assignments.officer', 'application.inspections.department',
            'application.inspections.inspector', 'application.permits.permitType',
            'complianceChecks',
        ]);

        // Flat assignment shape (matches the list resource + the frontend
        // contract): { ...assignment fields, application: <full>, compliance_checks }.
        $payload = (new AssignmentResource($assignment))->resolve($request);
        $payload['application'] = (new ApplicationResource($assignment->application))->resolve($request);
        $payload['compliance_checks'] = $assignment->complianceChecks->map(fn (ComplianceCheck $c) => [
            'id' => $c->id,
            'application_document_id' => $c->application_document_id,
            'label' => $c->label,
            'is_checked' => (bool) $c->is_checked,
            'note' => $c->note,
        ])->values();

        return response()->json(['data' => $payload]);
    }

    public function approve(Request $request, ApplicationAssignment $assignment): JsonResponse
    {
        $this->authorizeDepartment($request, $assignment);
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        $assignment->update(['officer_user_id' => $request->user()->id]);
        $this->workflow->approveAssignment($assignment, $data['remarks'] ?? null);

        return response()->json([
            'data' => new AssignmentResource($assignment->fresh()->load(['department', 'officer', 'application.business'])),
        ]);
    }

    public function return(Request $request, ApplicationAssignment $assignment): JsonResponse
    {
        $this->authorizeDepartment($request, $assignment);
        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ], [
            'remarks.required' => 'Explain what the applicant needs to fix.',
        ]);

        $assignment->update(['officer_user_id' => $request->user()->id]);
        $this->workflow->returnAssignment($assignment, $data['remarks']);

        return response()->json([
            'data' => new AssignmentResource($assignment->fresh()->load(['department', 'officer', 'application.business'])),
        ]);
    }

    public function checks(Request $request, ApplicationAssignment $assignment): JsonResponse
    {
        $this->authorizeDepartment($request, $assignment);
        $data = $request->validate([
            'application_document_id' => ['nullable', 'exists:application_documents,id'],
            'label' => ['required', 'string', 'max:255'],
            'is_checked' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $check = ComplianceCheck::updateOrCreate(
            [
                'application_assignment_id' => $assignment->id,
                'label' => $data['label'],
            ],
            [
                'application_document_id' => $data['application_document_id'] ?? null,
                'is_checked' => $data['is_checked'],
                'note' => $data['note'] ?? null,
            ]
        );

        return response()->json([
            'data' => [
                'id' => $check->id,
                'application_document_id' => $check->application_document_id,
                'label' => $check->label,
                'is_checked' => (bool) $check->is_checked,
                'note' => $check->note,
            ],
        ]);
    }

    /** OIC: (re)assign an officer to this assignment (permission oic.assign). */
    public function assign(Request $request, ApplicationAssignment $assignment): JsonResponse
    {
        $data = $request->validate([
            'officer_user_id' => ['required', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $officer = User::findOrFail($data['officer_user_id']);
        abort_unless(
            $officer->department_id === $assignment->department_id,
            422,
            'The officer must belong to this assignment’s department.'
        );

        $this->workflow->assignOfficer($assignment, $officer, $data['reason'] ?? null);

        return response()->json([
            'data' => new AssignmentResource($assignment->fresh()->load(['department', 'officer', 'application.business'])),
        ]);
    }

    private function scopeToDepartment(Request $request, $query): void
    {
        $user = $request->user();
        if (! $user->hasRole('admin') && $user->department_id) {
            $query->where('department_id', $user->department_id);
        } elseif (! $user->hasRole('admin')) {
            // Officer with no department sees nothing.
            $query->whereRaw('1 = 0');
        }
    }

    private function authorizeDepartment(Request $request, ApplicationAssignment $assignment): void
    {
        $user = $request->user();
        if ($user->hasRole('admin')) {
            return;
        }
        abort_unless(
            $user->department_id && $assignment->department_id === $user->department_id,
            403,
            'This assignment belongs to another department.'
        );
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\InspectionResource;
use App\Models\Inspection;
use App\Services\WorkflowService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inspection scheduling + results, scoped to the caller's department (or own
 * inspections). recordInspection delegates to WorkflowService.
 */
class InspectionController extends Controller
{
    public function __construct(private WorkflowService $workflow) {}

    private array $eager = [
        'department', 'inspector',
        'application.business.address.barangay',
    ];

    /**
     * The inspection list.
     *
     * Paginated, and it has to be. This returned every inspection ever recorded:
     * with a register carrying three years of history that is 2,850 rows and
     * 1.8 MB of JSON on a single request, each row eager-loading its department,
     * inspector, application, business, address and barangay. The endpoint
     * answered 200 and the browser then tried to render all of them, which is
     * what took the page down. It went unnoticed while the register held sixteen.
     *
     * Newest first, also deliberately. Ascending by scheduled_at meant page one
     * opened on 2023 — correct when the list was "the next few visits", useless
     * once it spans years. An officer wants the visit they are about to do or
     * have just done.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Inspection::with($this->eager);

        $this->scopeToDepartment($request, $query);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $inspections = $query->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => InspectionResource::collection($inspections->items()),
            'meta' => $this->pageMeta($inspections),
        ]);
    }

    public function show(Request $request, Inspection $inspection): JsonResponse
    {
        $this->authorizeDepartment($request, $inspection);

        $inspection->load(array_merge($this->eager, [
            'application.applicant', 'application.permitTypes',
            'application.documents.documentType',
        ]));

        // Flat inspection shape (matches the list resource + frontend contract):
        // { ...inspection fields, application: <full> }.
        $payload = (new InspectionResource($inspection))->resolve($request);
        $payload['application'] = (new ApplicationResource($inspection->application))->resolve($request);

        return response()->json(['data' => $payload]);
    }

    public function conduct(Request $request, Inspection $inspection): JsonResponse
    {
        $this->authorizeDepartment($request, $inspection);

        $data = $request->validate([
            'result' => ['required', 'in:passed,failed,conditional'],
            'findings' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['string'],
        ]);

        if (! $inspection->inspector_user_id) {
            $inspection->update(['inspector_user_id' => $request->user()->id]);
        }

        $this->workflow->recordInspection(
            $inspection,
            InspectionResult::from($data['result']),
            $data['findings'] ?? null,
            $data['photos'] ?? [],
        );

        return response()->json([
            'data' => new InspectionResource($inspection->fresh()->load($this->eager)),
        ]);
    }

    public function reschedule(Request $request, Inspection $inspection): JsonResponse
    {
        $this->authorizeDepartment($request, $inspection);

        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
        ]);

        $inspection->update([
            'scheduled_at' => $data['scheduled_at'],
            'status' => InspectionStatus::Rescheduled,
        ]);
        Audit::log('inspection.rescheduled', $inspection, ['scheduled_at' => $data['scheduled_at']]);

        return response()->json([
            'data' => new InspectionResource($inspection->fresh()->load($this->eager)),
        ]);
    }

    private function scopeToDepartment(Request $request, $query): void
    {
        $user = $request->user();
        if ($user->hasRole('admin')) {
            return;
        }
        if ($user->department_id) {
            $query->where('department_id', $user->department_id);
        } else {
            $query->where('inspector_user_id', $user->id);
        }
    }

    private function authorizeDepartment(Request $request, Inspection $inspection): void
    {
        $user = $request->user();
        if ($user->hasRole('admin')) {
            return;
        }
        $ok = ($user->department_id && $inspection->department_id === $user->department_id)
            || $inspection->inspector_user_id === $user->id;
        abort_unless($ok, 403, 'This inspection belongs to another department.');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\InspectionResource;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\Inspection;
use App\Models\PermitType;
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

        /*
         * The detail read goes deeper than the list on purpose.
         *
         * `business.owner` and `business.lines.psicCode` are here so
         * InspectionResource::particulars() can put the owner's name and the
         * declared line(s) of business on the sheet — the two facts an officer
         * standing at the premises could not previously see. They stay out of
         * `$this->eager` because the list renders neither, and this endpoint
         * reads one row while the list reads a page of them.
         *
         * `permitTypes` doubles as the gate particulars() checks: it is loaded
         * here and in no other inspection response, so a payload that carries
         * it is exactly a payload that went and looked.
         */
        $inspection->load(array_merge($this->eager, [
            'application.applicant', 'application.permitTypes',
            'application.documents.documentType',
            'application.business.owner', 'application.business.lines.psicCode',
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

    /**
     * The office books its FIRST visit on one permit, choosing the date.
     *
     * New on 6 September 2026, and it exists because the automatic scheduler
     * went. Visits used to be booked by `WorkflowService::scheduleInspectionFor`
     * two working days out the instant an office approved its review — a
     * promise made to the applicant by a scheduler that did not know whether
     * anyone was free. The client's verified procedure is "Select Inspection
     * Date and Approve Inspection": the office says when.
     *
     * Addressed by permit type rather than by inspection id, because there is no
     * inspection yet — that is the point of the endpoint. The office is
     * identified from the permit's issuing department and checked against the
     * caller's, so an officer cannot book a visit in another office's name.
     *
     * `reschedule` moves a booking that already exists; `reinspect` books a
     * second one after a failure. This is the only one that opens the first.
     */
    public function schedule(Request $request, Application $application, string $code): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
        ], [
            'scheduled_at.required' => 'Choose the date of the inspection.',
        ]);

        $type = PermitType::where('code', strtoupper($code))->firstOrFail();

        $user = $request->user();
        abort_unless(
            $user->hasRole('admin') || $user->department_id === $type->issuing_department_id,
            403,
            'This permit is issued by another office.'
        );

        $row = ApplicationPermitType::where('application_id', $application->id)
            ->where('permit_type_id', $type->id)
            ->firstOrFail();

        $visit = $this->workflow->scheduleClearanceInspection($row, $data['scheduled_at']);

        return response()->json([
            'data' => new InspectionResource($visit->load($this->eager)),
        ], 201);
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

    /**
     * Book a fresh visit after a failed one (POST /inspections/{id}/reinspect).
     *
     * Distinct from `reschedule`, and the difference is the point. Rescheduling
     * MOVES a visit that has not happened yet; it overwrites `scheduled_at` on
     * the row and nothing is lost, because there is nothing yet to lose. This
     * books a SECOND visit and leaves the failed one untouched, so the filing's
     * record keeps saying that the premises failed on 02 August and passed on
     * the 12th. Rescheduling the failed row would have been the smaller change
     * and would have erased exactly the fact the client asked to keep.
     *
     * 422 rather than 403 when the visit cannot be re-inspected: the caller is
     * allowed to act on this inspection, it is the inspection that is in the
     * wrong state (already passed, superseded by a later visit, or on a filing
     * that has since been decided). The message says which, because the officer
     * reading it is looking at a screen that offered them the button.
     */
    public function reinspect(Request $request, Inspection $inspection): JsonResponse
    {
        $this->authorizeDepartment($request, $inspection);

        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
        ]);

        abort_unless(
            $inspection->canBeReinspected(),
            422,
            'A re-inspection can only be scheduled from an office’s most recent failed visit, '
                .'while the application is still for inspection.'
        );

        $visit = $this->workflow->scheduleReinspection($inspection, $data['scheduled_at']);

        // 201 with the NEW visit, not the failed one: the caller has to navigate
        // to it to record the result, and it is a different row with a different
        // id. Answering with the row they posted to would send the officer back
        // to a conducted visit that has no controls.
        return response()->json([
            'data' => new InspectionResource($visit->load($this->eager)),
        ], 201);
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

    /**
     * A visit belongs to the office that booked it, or to the inspector named
     * on it. The `admin` exemption that used to open this method was REMOVED
     * rather than replaced with a permission check (INS-7).
     *
     * Same reasoning as AssignmentController::authorizeDepartment(), and the two
     * must stay one rule — the client's model is that each office owns its own
     * visit, and this method is where that is enforced for conduct, reschedule,
     * reinspect and show. `admin` no longer holds `inspection.manage`, so the
     * route gate refuses it first and this branch could never fire; what it did
     * do was state, in code, that a role named `admin` may conduct any office's
     * inspection. That is one seeder row away from being true, and nobody
     * decided it.
     *
     * It is not replaced by `application.view_any_office`, because that is BPLO's
     * cross-office READ and this is the authority to record an inspection RESULT
     * against another office's premises visit.
     *
     * WorkflowReinspectionTest.php:258 asserts the super admin is refused a
     * re-inspection, and its own comment notes it passes on the route gate
     * without ever reaching this method. It keeps passing, and now for the
     * reason it appears to be about.
     *
     * The `inspector_user_id` disjunct is deliberately kept and is deliberately
     * not department-scoped — but note it survives a department transfer
     * (INS-6): an officer moved between offices in the admin user editor keeps
     * write access to their old office's open visits. leastLoadedInspector()
     * only ever names a user from the booking department, so this is safe at
     * booking time and is a separate, un-fixed finding.
     */
    private function authorizeDepartment(Request $request, Inspection $inspection): void
    {
        $user = $request->user();
        $ok = ($user->department_id && $inspection->department_id === $user->department_id)
            || $inspection->inspector_user_id === $user->id;
        abort_unless($ok, 403, 'This inspection belongs to another department.');
    }
}

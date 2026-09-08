<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ClearanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\AssignmentResource;
use App\Models\ApplicationAssignment;
use App\Models\ComplianceCheck;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\Ra11032;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Officer review queues, scoped to the caller's department (admin sees all).
 * Approve/return/reject delegate to WorkflowService.
 */
class AssignmentController extends Controller
{
    public function __construct(private WorkflowService $workflow) {}

    /**
     * The officer review queue.
     *
     * Paginated, newest assignment first. Unpaged this answered 4,620 rows and
     * 2.2 MB to the super admin and 1,293 rows to a single office — every
     * assignment ever routed, including years of completed ones, on the request
     * that renders "what is waiting for me".
     *
     * `application_status` is the other half of the fix and it is not optional.
     * The queue screen splits its two tabs by the *application's* status
     * (submitted/under_review/... vs for_inspection/approved), and it did that
     * in the browser over the full list. Bound the list without moving that
     * filter to SQL and each tab silently filters one page: an office with 60
     * pending reviews would show whichever of them happened to land in the first
     * 50 rows and call it the queue.
     *
     * Note that `status` and `application_status` are different columns —
     * `status` is the assignment's own pending/completed/returned. Both are
     * accepted because both are asked for.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            // Repeatable or comma-separated, like application_status below:
            // ?status=pending,in_progress,returned. It was a single value until
            // checklist item 111 needed "everything except completed".
            'status' => ['sometimes'],
            // Repeatable or comma-separated: ?application_status=for_approval,returned
            'application_status' => ['sometimes'],
            /*
             * Repeatable or comma-separated: ?clearance_status=for_inspection
             *
             * The office's OWN permit on this filing, which is the second state
             * machine and the only one that answers "what is waiting on me".
             *
             * The assignment's own status stopped being able to. Under the flow
             * verified on 6 September 2026 an office's assignment is marked
             * `completed` by `approveClearance()` — at the moment the paperwork
             * is accepted and the permit moves to `for_inspection`, which is
             * when the office's real work, the site visit, has not happened yet.
             * So `status=completed` covers both "inspecting" and "finished", and
             * `status=pending` no longer covers everything outstanding. A queue
             * built on it alone puts a booked inspection in the same bucket as an
             * issued clearance.
             */
            'clearance_status' => ['sometimes'],
            /*
             * Free-text search over the tracking ID and the business name.
             *
             * Bounded for the same reason ApplicationController bounds its own:
             * the value goes into two unanchored LIKE patterns, and an
             * unbounded needle is a cheap way to make the database scan the
             * whole table on every keystroke.
             */
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = ApplicationAssignment::with([
            'department', 'officer',
            'application:id,tracking_id,business_id,application_type,status',
            'application.business:id,name',
            /*
             * For `AssignmentResource::clearanceRow()`, which reports the state
             * of this office's own permit. It reads the loaded collection and
             * returns null rather than lazy-loading, so leaving this out would
             * quietly blank the status chip on every row of the queue instead of
             * erroring — hence naming the columns it needs here.
             */
            'application.permitTypes:id,code,name,issuing_department_id,requires_inspection',
        ]);

        $this->scopeToDepartment($request, $query);

        $assignmentStatuses = $this->assignmentStatuses($request);
        if ($assignmentStatuses !== []) {
            $query->whereIn('status', $assignmentStatuses);
        }

        $applicationStatuses = $this->applicationStatuses($request);
        if ($applicationStatuses !== []) {
            $query->whereHas('application', fn ($a) => $a->whereIn('status', $applicationStatuses));
        }

        /*
         * Narrow to the state of THIS OFFICE'S permit on the filing.
         *
         * The join condition is the whole point: `whereColumn` ties the permit
         * type's issuing office to the assignment's own department, so an office
         * is matched on its own clearance and never on one beside it. Without
         * it, a filing whose FSIC is out for inspection would surface in the
         * City Health Office's inspection tab — the office boundary
         * (`ApplicationVisibility`) restated in a query it does not run.
         *
         * BPLO matches it like any other office — it issues the Mayor's /
         * Business Permit — but its row is not useful to filter on, and the
         * queue does not. That permit is the filing's OUTCOME rather than one of
         * the five clearances, and its pivot moves accordingly:
         * `approveMainForm()` puts it at `for_approval` and `approveOverall()`
         * takes it to `approved`, so it reads `for_approval` for the entire
         * middle of the flow and answers "is BPLO waiting?" with a yes that is
         * true from Pending Payment to Final Approval. BPLO's two acts are keyed
         * to the APPLICATION's status, which distinguishes them; this does not.
         *
         * The one thing that follows for free: `for_inspection` never appears on
         * that pivot, so filtering the inspection tab on it excludes BPLO
         * without a special case.
         */
        $clearanceStatuses = $this->clearanceStatuses($request);
        if ($clearanceStatuses !== []) {
            $query->whereHas('application.permitTypes', function ($q) use ($clearanceStatuses) {
                $q->whereColumn('permit_types.issuing_department_id', 'application_assignments.department_id')
                    ->whereIn('application_permit_types.status', $clearanceStatuses);
            });
        }

        /*
         * Search the whole scoped queue, not the page the officer happens to
         * have loaded.
         *
         * This feed had no `q` at all, so the queue screen filtered the rows it
         * already held and reported "Showing 0 of the 13 loaded". Two things
         * went wrong at once: a filing past the first page could not be found,
         * and — worse — search was scoped to the open tab, so an officer on For
         * Approval searching for a filing that had moved to For Inspection was
         * told it did not exist. It did; it was one tab away, and the screen
         * said "Nothing matches".
         *
         * The same two columns as ApplicationController::index deliberately:
         * the tracking ID an applicant quotes over the phone, and the business
         * name an officer actually remembers. Both are matched here rather than
         * only the ID, because "check the tracking ID, or search by the business
         * name instead" is what the empty state already promises.
         *
         * Applied to the ASSIGNMENT query through whereHas, so it narrows
         * within the department scoping above rather than round it — an office
         * must not be able to search its way to a filing it was never routed.
         */
        if ($q = $request->query('q')) {
            $query->whereHas('application', function ($a) use ($q) {
                $a->where('tracking_id', 'like', "%{$q}%")
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', "%{$q}%"));
            });
        }

        /*
         * Counts over the whole scoped set, not the page. The tabs show "For
         * Approval" and "For Inspection" totals; computed from the page they
         * would be wrong in the confident, unnoticeable way — a number that is
         * always ≤ 50 and looks plausible.
         */
        $counts = $this->statusCounts($request);

        $assignments = $query
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => AssignmentResource::collection($assignments->items()),
            'meta' => $this->pageMeta($assignments) + ['application_status_counts' => $counts],
        ]);
    }

    /**
     * The `application_status` filter, as a list of valid enum values.
     *
     * Unknown values are dropped rather than 422'd: the queue tabs send a fixed
     * list of statuses, and one of them going stale after a rename should narrow
     * the queue, not break the screen.
     *
     * @return list<string>
     */
    private function applicationStatuses(Request $request): array
    {
        return $this->statusList(
            $request->query('application_status'),
            array_map(fn (ApplicationStatus $s) => $s->value, ApplicationStatus::cases()),
        );
    }

    /**
     * The `status` filter — the ASSIGNMENT's own status, not the application's.
     *
     * Checklist item 111, "after approving an application it still shows
     * approval". WorkflowService::approveAssignment marks this office's
     * assignment `completed` but deliberately leaves the application in
     * `under_review` until every office has signed off (see afterReviewProgress).
     * That is correct — the filing genuinely is still under review by the others
     * — but the queue filtered on the application's status alone, so the office
     * that had just approved was shown the same row again and asked to approve
     * work it had already done. The screen was reporting the filing's state where
     * the officer was asking about their own.
     *
     * @return list<string>
     */
    private function assignmentStatuses(Request $request): array
    {
        return $this->statusList(
            $request->query('status'),
            array_map(fn (AssignmentStatus $s) => $s->value, AssignmentStatus::cases()),
        );
    }

    /**
     * The `clearance_status` filter — this office's own permit on the filing.
     *
     * @return list<string>
     */
    private function clearanceStatuses(Request $request): array
    {
        return $this->statusList(
            $request->query('clearance_status'),
            array_map(fn (ClearanceStatus $s) => $s->value, ClearanceStatus::cases()),
        );
    }

    /**
     * Parse a repeatable/comma-separated status filter down to known values.
     *
     * Unknown values are dropped rather than 422'd: the queue tabs send a fixed
     * list of statuses, and one of them going stale after a rename should narrow
     * the queue, not break the screen.
     *
     * @param  list<string>  $valid
     * @return list<string>
     */
    private function statusList(mixed $raw, array $valid): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        // `?application_status[][]=x` arrives as a nested array; casting one of
        // those to string is a TypeError, so anything non-scalar is dropped
        // before it is trimmed rather than after.
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $values = array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $values,
        );

        return array_values(array_intersect(array_filter($values), $valid));
    }

    /**
     * How many assignments this caller has per application status, over the
     * whole department-scoped set. One grouped query, not one per tab.
     *
     * @return array<string, int>
     */
    private function statusCounts(Request $request): array
    {
        /*
         * The same assignment-status filter the list uses (item 111). A tab
         * badge counting rows the list then refuses to show is its own bug: the
         * office that has approved everything would read "For Approval 12" over
         * an empty queue and reasonably conclude the screen was broken.
         */
        $assignmentStatuses = $this->assignmentStatuses($request);

        $counts = ApplicationAssignment::query()
            ->tap(fn ($q) => $this->scopeToDepartment($request, $q))
            ->when(
                $assignmentStatuses !== [],
                fn ($q) => $q->whereIn('application_assignments.status', $assignmentStatuses),
            )
            ->join('applications', 'applications.id', '=', 'application_assignments.application_id')
            ->whereNull('applications.deleted_at')
            ->groupBy('applications.status')
            ->pluck(
                DB::raw('count(*) as aggregate'),
                'applications.status'
            );

        return $counts->map(fn ($c) => (int) $c)->all();
    }

    public function show(Request $request, ApplicationAssignment $assignment): JsonResponse
    {
        $this->authorizeDepartment($request, $assignment);

        $assignment->load([
            'department', 'officer',
            'application.business.address.barangay', 'application.business.lines.psicCode',
            'application.applicant', 'application.permitTypes',
            /*
             * Who set the RA 11032 tier, for the For Office Use Only panel. One
             * constant query, and without it the sheet cannot tell an officer
             * whether they are overriding somebody's decision or our automatic
             * guess — which is the only reason that control is safe to show.
             */
            'application.complexitySetBy:id,name',
            'application.documents.documentType', 'application.feeAssessment',
            'application.officeForms.permitType.department',
            'application.payments', 'application.assignments.department',
            'application.assignments.officer', 'application.inspections.department',
            'application.inspections.inspector',
            /*
             * InspectionResource serialises an application stub; without this it
             * lazy-loaded one per inspection on every review page open.
             *
             * The stub carries `business`, and selecting only id + tracking_id
             * left that relation unloaded — so the resource reported the business
             * as null, i.e. removed from the register, on filings whose business
             * is alive (checklist item 87). `business_id` is the foreign key the
             * relation needs; the chain is four constant queries because every
             * inspection here belongs to the one application already loaded.
             */
            'application.inspections.application:id,tracking_id,business_id',
            'application.inspections.application.business.address.barangay',
            'application.permits.permitType',
            // PermitResource serialises business and application stubs too.
            'application.permits.business:id,name', 'application.permits.application:id,tracking_id',
            /*
             * The progression rail and the "what actually happened" log on the
             * review sheet. Loaded here rather than fetched by the browser from
             * /applications/{id}/timeline because this request already carries
             * the entire filing — two constant queries against one already-open
             * screen beats a second authenticated round trip that answers a
             * question this response was always in a position to answer.
             */
            'application.statusHistory.changedBy:id,name',
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

        /*
         * The claim is written only once the approval is known to be accepted.
         *
         * This used to stamp officer_user_id first and call the workflow after,
         * which quietly undid the care taken on the other side of that call:
         * approveAssignment() refuses BEFORE any write precisely so that a
         * refused approval leaves nothing behind. Since the RA 11032 gate, that
         * refusal is reachable in ordinary use — approve an uncategorised filing
         * and it throws — and the old order left the refusing officer's name
         * recorded against a review they had just been told they could not make.
         *
         * Both writes now sit on the accepted path, in the order a reader would
         * expect: decide, then record who decided.
         */
        $this->workflow->approveAssignment($assignment, $data['remarks'] ?? null);
        $assignment->update(['officer_user_id' => $request->user()->id]);

        return response()->json([
            'data' => new AssignmentResource($assignment->fresh()->load(['department', 'officer', 'application.business', 'application.permitTypes'])),
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
            'data' => new AssignmentResource($assignment->fresh()->load(['department', 'officer', 'application.business', 'application.permitTypes'])),
        ]);
    }

    /**
     * Set this filing's RA 11032 processing category (the client's request).
     *
     * ── Why it hangs off the ASSIGNMENT ───────────────────────────────────────
     *
     * "Allow all office admins to set the application category during their
     * edit mode when trying to approve the application." Every word of that is
     * about the seat the officer is already sitting in: the review sheet, which
     * is `GET /assignments/{id}`. Putting the write on the same resource means
     * the caller has to hold an assignment on this filing to reach it, and
     * `authorizeDepartment()` — the same check that guards approve, return and
     * the compliance checklist — decides whether it is theirs.
     *
     * That is what makes "ALL office admins" true without a new permission and
     * without widening anything: each of the seven offices holds its own
     * assignment on a filing it is reviewing, so each of them reaches this for
     * that filing and none of them reaches it for a filing they were never
     * routed. An applicant has no assignment at all, and the route sits behind
     * `permission:application.review`, which no owner holds — so the field
     * stays an office field on both counts.
     *
     * It is deliberately NOT on OfficeFormController. Those sheets are per
     * permit type and their answers belong to one office's clearance; the tier
     * belongs to the FILING and there is exactly one of it, shared by every
     * office on the application. Storing it as an office-form key would have
     * given a filing up to seven contradictory categories and no way to say
     * which one the statutory clock was running on.
     *
     * The tier itself, the terminal-filing guard and the deadline recomputation
     * are all in WorkflowService::classify(); read it before changing anything
     * here. This method validates and authorises, nothing more.
     */
    public function classify(Request $request, ApplicationAssignment $assignment): JsonResponse
    {
        $this->authorizeDepartment($request, $assignment);

        /*
         * `Rule::in` over `Ra11032::tierKeys()`, not a written-out list. The
         * three tiers and their day counts are statute; a validation rule with
         * its own copy of them is a fourth tier waiting to be typed.
         */
        $data = $request->validate([
            'tier' => ['required', 'string', Rule::in(Ra11032::tierKeys())],
        ], [
            'tier.in' => 'RA 11032 recognises only simple, complex and highly technical transactions.',
        ]);

        $application = $this->workflow->classify(
            $assignment->application,
            $data['tier'],
            $request->user(),
        );

        /*
         * The whole application back, loaded the way the review sheet reads it
         * — the tier, its new provenance and the RECOMPUTED deadline all land
         * on the same screen that just changed them, so the officer sees the
         * consequence of the change rather than being told it saved.
         */
        return response()->json([
            'data' => new ApplicationResource(
                $application->load(['business', 'complexitySetBy:id,name']),
            ),
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

    /**
     * OIC: (re)assign an officer to this assignment (permission oic.assign).
     *
     * The department check was missing here while every other action on this
     * controller had it. Today only the super admin holds `oic.assign` and the
     * admin short-circuits the check anyway, so nothing was exploitable — but
     * the permission exists to be given to an office's OIC, and the day it is,
     * this endpoint would have let that OIC reshuffle every other office's
     * queue. Fixing the hole is cheaper than remembering it.
     */
    public function assign(Request $request, ApplicationAssignment $assignment): JsonResponse
    {
        $this->authorizeOicReassignment($request, $assignment);

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
            'data' => new AssignmentResource($assignment->fresh()->load(['department', 'officer', 'application.business', 'application.permitTypes'])),
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

    /**
     * An assignment belongs to one department, and only that department reviews
     * it. The `admin` exemption that used to open this method is GONE (INS-7).
     *
     * Removed rather than replaced with a permission, for the reads and the
     * decisions this now gates — show, approve, return, checks. There is no
     * account for whom "City Health's review may be signed off by someone else"
     * should be true. The super admin audits the system; it does not review
     * clearances, and the RBAC seeder says so — `admin` holds neither
     * `application.review` nor `inspection.manage`, so the route gate 403s it
     * before this method runs and the exemption was already dead code. What it
     * was NOT was harmless: it stated, in code, that a role named `admin` may
     * approve any office's review, one seeder row away from being true.
     *
     * It was not swapped for a permission because the only permission with the
     * right shape is `application.view_any_office` — held by BPLO, whose job is
     * to READ across offices and coordinate them. Wiring that in here would hand
     * BPLO the power to approve and return every other office's review, a live
     * escalation the by-name check never granted. A cross-office READ permission
     * is not a cross-office WRITE permission, and approve/return are writes.
     *
     * The one genuine cross-office action on this controller is OIC
     * reassignment; it is carved out in authorizeOicReassignment() below rather
     * than smuggled in here, so that the exception is visible at the one call
     * site it applies to.
     *
     * Asymmetry left standing on purpose: scopeToDepartment() above still lets
     * `admin` LIST every department's assignments. Narrowing a listing is a
     * different decision — it is INS-8's subject, the super admin's queue
     * rendering one filing once per assignment — and does not belong in a fix
     * about who may act.
     */
    private function authorizeDepartment(Request $request, ApplicationAssignment $assignment): void
    {
        $user = $request->user();
        abort_unless(
            $user->department_id && $assignment->department_id === $user->department_id,
            403,
            'This assignment belongs to another department.'
        );
    }

    /**
     * Naming who handles a case is not deciding it, so this one action crosses
     * offices — for a central coordinator, and only for them.
     *
     * The discriminator is a structural fact rather than a role name: the caller
     * holds `oic.assign` (the route enforces that) and has no department of
     * their own. That is precisely the shape of a city-wide administrator, and
     * it preserves the intent already written on assign() above — the day
     * `oic.assign` is granted to an OFFICE's OIC, that OIC has a department, so
     * they fall through to the strict check and still cannot reshuffle another
     * office's queue.
     *
     * Deliberately narrower than what it replaces. `hasRole('admin')` exempted
     * the super admin from every action on this controller, approve and return
     * included; this exempts nobody from anything except naming an officer.
     */
    private function authorizeOicReassignment(Request $request, ApplicationAssignment $assignment): void
    {
        if ($request->user()->department_id === null) {
            return;
        }

        $this->authorizeDepartment($request, $assignment);
    }
}

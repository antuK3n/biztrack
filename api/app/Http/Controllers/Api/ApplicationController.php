<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationListResource;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Business;
use App\Services\WorkflowService;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        'inspections.department', 'inspections.inspector',
        /*
         * InspectionResource emits an `application` stub, so the relation has to
         * be here. It was not, and the resource lazy-loaded it one row at a time
         * — a query per inspection on every application detail view. Two columns
         * are enough for what the stub actually serialises.
         */
        'inspections.application:id,tracking_id',
        'permits.permitType', 'permits.business', 'permits.application',
    ];

    /**
     * The filing list. Paginated, newest first.
     *
     * Unpaged this returned 1,668 rows and 832 KB to the super admin — every
     * filing in the register on one request, most of them years decided. The
     * ordering was already right; the bound was the missing half.
     *
     * `q` is capped because it goes into two LIKE patterns: an unbounded search
     * string is a free full-table scan per keystroke on a register this size.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', 'max:40'],
            'type' => ['sometimes', 'string', 'max:40'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Application::with(['business:id,name', 'permitTypes:id,code,name']);

        // Owners see their own; an office sees the filings routed to it; BPLO
        // and the super admin see the register. A 403 on show would mean
        // nothing if the list still leaked the row (checklist item 56).
        ApplicationVisibility::scope($query, $request->user());

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

        $apps = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => ApplicationListResource::collection($apps->items()),
            'meta' => $this->pageMeta($apps),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'exists:businesses,id'],
            'application_type' => ['required', 'in:new,renewal,amendment'],
            // The applicant's own name for this filing; blank falls back to the
            // business name everywhere it is displayed.
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Ordinance Sec. 2N: annual (first 20 days of January) or quarterly
            // (first 20 days of Jan/Apr/Jul/Oct). No semi-annual option exists.
            'payment_mode' => ['sometimes', 'in:annual,quarterly'],
            'permit_type_ids' => ['required', 'array', 'min:1'],
            'permit_type_ids.*' => ['exists:permit_types,id'],
            'prior_permit_id' => ['nullable', 'exists:permits,id'],
            ...$this->feeProfileRules($request),
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
            'title' => $this->cleanTitle($data['title'] ?? null),
            'status' => ApplicationStatus::Draft,
            'prior_permit_id' => $data['prior_permit_id'] ?? null,
            'payment_mode' => $data['payment_mode'] ?? 'annual',
            'fee_profile' => $data['fee_profile'] ?? null,
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
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'permit_type_ids' => ['sometimes', 'array', 'min:1'],
            'permit_type_ids.*' => ['exists:permit_types,id'],
            'payment_mode' => ['sometimes', 'in:annual,quarterly'],
            ...$this->feeProfileRules($request),
        ]);

        if (array_key_exists('title', $data)) {
            $application->update(['title' => $this->cleanTitle($data['title'])]);
        }
        if (isset($data['business_id'])) {
            $business = Business::findOrFail($data['business_id']);
            abort_unless($business->owner_user_id === $request->user()->id, 403, 'This business is not yours.');
            $application->update(['business_id' => $business->id]);
        }
        if (isset($data['permit_type_ids'])) {
            $application->permitTypes()->sync($data['permit_type_ids']);
        }
        if (array_key_exists('fee_profile', $data)) {
            $application->update(['fee_profile' => $data['fee_profile']]);
        }
        if (isset($data['payment_mode'])) {
            $application->update(['payment_mode' => $data['payment_mode']]);
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

    /**
     * End the application (permission `application.reject` on the route).
     *
     * The office boundary applies to the strongest decision in the flow as much
     * as to reading it. `application.reject` is BPLO and the super admin today,
     * both of whom read every office, so this changes no behaviour — it stops
     * the check being the one thing that has to be remembered if the permission
     * is ever widened.
     */
    public function reject(Request $request, Application $application): JsonResponse
    {
        $this->authorizeView($request, $application);

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

    /**
     * Remove one uploaded requirement (tester checklist item 47).
     *
     * The wizard could replace a file but never take one back, so a document
     * attached to the wrong requirement stayed attached. Only the applicant
     * may remove one, only while the application is still theirs to edit, and
     * the stored file goes with the row — a "removed" document that still sits
     * on disk is not removed.
     */
    public function destroyDocument(Request $request, Application $application, ApplicationDocument $document): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        abort_unless($document->application_id === $application->id, 404, 'That document is not part of this application.');
        abort_unless(
            in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true),
            422,
            'Documents can only be removed while the application is a draft or has been returned to you.'
        );

        Audit::log('document.removed', $document);

        if ($document->stored_path && Storage::disk('local')->exists($document->stored_path)) {
            Storage::disk('local')->delete($document->stored_path);
        }
        $document->delete();

        return response()->json(['data' => ['id' => $document->id]]);
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

    /** Owner, an office routed this filing, or BPLO/admin. See ApplicationVisibility. */
    private function authorizeView(Request $request, Application $application): void
    {
        ApplicationVisibility::authorize(
            $request->user(),
            $application,
            'You may not view this application.'
        );
    }

    /** Trimmed title, or null so readers fall back to the business name. */
    private function cleanTitle(?string $title): ?string
    {
        $trimmed = trim((string) $title);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Fee-profile facts the revenue-code calculator consumes
     * (docs/revenue-code-extract.md Appendix B; database/data/revenue_code/SCHEMA.md).
     *
     * Everything stays optional because drafts autosave half-typed: what the
     * rules enforce is that whatever IS present is a sane number. Negatives,
     * words in money fields, and amounts past the sanity ceiling are rejected
     * here as well as in the wizard (tester checklist item 39) — the browser
     * is not the only way into this endpoint.
     */
    private function feeProfileRules(Request $request): array
    {
        // Peso amounts: 10 billion is far beyond any Malabon filing and still
        // clear of the decimal(14,2) columns the assessment lands in.
        $money = ['nullable', 'numeric', 'min:0', 'max:10000000000'];
        $count = ['nullable', 'integer', 'min:0', 'max:100000'];

        /*
         * A ceiling on the whole object, not just on the keys named below.
         *
         * The rules cover the fields the calculator reads, but `fee_profile`
         * itself is stored verbatim as JSON, so anything else the caller sends
         * is kept unchallenged — a sixty-level nested array came back 201. The
         * office-form endpoint already caps its opaque payload at 16 KB; this is
         * the same guard on the other opaque payload.
         */
        $boundedProfile = function (string $attribute, mixed $value, callable $fail) {
            if (is_array($value) && strlen((string) json_encode($value)) > 16384) {
                $fail('The fee details are too large (max 16KB).');
            }
        };

        return [
            'fee_profile' => ['sometimes', 'nullable', 'array', $boundedProfile],
            'fee_profile.lines' => ['sometimes', 'array', 'max:200'],
            // Ties a line back to the PSIC selection so reopened drafts restore.
            'fee_profile.lines.*.psic_code_id' => ['nullable', 'integer'],
            'fee_profile.lines.*.category' => ['required_with:fee_profile.lines', 'string', 'max:80'],
            'fee_profile.lines.*.gross_sales' => $money,
            'fee_profile.lines.*.capitalization' => $money,
            'fee_profile.gross_sales' => $money,
            'fee_profile.capitalization' => $money,
            'fee_profile.floor_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'fee_profile.employees' => $count,
            // Unified form asks how many of those live in the city — which can
            // never be more than the headcount it is a subset of.
            'fee_profile.employees_in_lgu' => [
                ...$count,
                function (string $attribute, mixed $value, callable $fail) use ($request) {
                    $total = $request->input('fee_profile.employees');
                    if (is_numeric($total) && is_numeric($value) && (int) $value > (int) $total) {
                        $fail('Employees residing in Malabon can’t be more than your total number of employees.');
                    }
                },
            ],
            'fee_profile.tax_incentive_grantor' => ['nullable', 'string', 'max:120'],
            'fee_profile.storeys' => ['nullable', 'integer', 'min:0', 'max:200'],
            'fee_profile.doors' => $count,
            'fee_profile.rooms' => $count,
            'fee_profile.beds' => $count,
            'fee_profile.stall_count' => $count,
            'fee_profile.delivery_vehicles_motorized' => $count,
            'fee_profile.delivery_vehicles_other' => $count,
            'fee_profile.construction_cost' => $money,
            'fee_profile.business_structure' => ['nullable', 'in:sole_proprietorship,partnership,corporation,cooperative'],
            'fee_profile.goods_class' => ['nullable', 'in:flammables,chemicals,dry_goods,perishables'],
            'fee_profile.office_location' => ['nullable', 'in:within,outside'],
            'fee_profile.warehouse_location' => ['nullable', 'in:within,outside'],
            'fee_profile.factory_location' => ['nullable', 'in:within,outside'],
            'fee_profile.property_use' => ['nullable', 'in:residential,non_residential'],
            'fee_profile.building_type' => ['nullable', 'string', 'max:40'],
            'fee_profile.occupancy_group' => ['nullable', 'string', 'max:20'],
            'fee_profile.flags' => ['sometimes', 'array'],
            'fee_profile.flags.*' => ['string', 'max:60'],
        ];
    }
}

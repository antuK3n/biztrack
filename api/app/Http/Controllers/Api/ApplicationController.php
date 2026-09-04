<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationListResource;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\StatusHistoryResource;
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
         * — a query per inspection on every application detail view.
         *
         * The stub carries `business` and `address` (docs/api-contract.md
         * §Inspections). Narrowing the select to id + tracking_id meant the
         * business relation was never loaded, so the resource emitted
         * `business: null` — which every reader takes to mean "removed from the
         * register". The business was alive; only the query was missing. Those
         * are different facts and the payload was stating the wrong one
         * (checklist item 87).
         *
         * `business_id` joins the select because the relation cannot resolve
         * without its foreign key. The chain costs four constant queries, not one
         * per inspection: every inspection on a filing shares one application row.
         */
        'inspections.application:id,tracking_id,business_id',
        'inspections.application.business.address.barangay',
        'permits.permitType', 'permits.business', 'permits.application',
        /*
         * The transition log. Two constant queries, and it keeps
         * `status_history` from being a field that is present but empty on the
         * one endpoint named after the whole record — a reader who trusted that
         * would conclude a filing had never moved.
         */
        'statusHistory.changedBy:id,name',
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
            // Comma-separated, so `max:40` no longer fits a whole stage. 120 is
            // the same ceiling `q` carries and holds every status name at once.
            'status' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:40'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        // `applicant` is two columns and one query for the whole page; without
        // it ApplicationListResource emits a null recipient and the request
        // composer cannot name who it is writing to (item 89).
        $query = Application::with([
            'business:id,name', 'applicant:id,name', 'permitTypes:id,code,name',
        ]);

        // Owners see their own; an office sees the filings routed to it; BPLO
        // and the super admin see the register. A 403 on show would mean
        // nothing if the list still leaked the row (checklist item 56).
        ApplicationVisibility::scope($query, $request->user());

        /*
         * `status` takes a list, not just one value: ?status=submitted,pending_payment
         *
         * The officer queue's Pending Payment tab asks for a whole *stage* of the
         * flow, and a stage is more than one status. It has to arrive as one
         * request because `meta.total` is what the screen prints beside the rows:
         * two requests would have to be added up in the browser, and a total
         * assembled from pages is the exact failure the two existing tabs were
         * built to avoid (see AssignmentController::index).
         *
         * Unknown values are deliberately NOT dropped here, which is where this
         * parts company with AssignmentController's identical-looking filter.
         * That feed is already narrowed to one office, so widening it on a typo
         * costs a few extra rows; this one is every filing in the city. A
         * misspelt status must keep returning nothing — the way `where()` always
         * did — because the failure mode of the alternative is handing back the
         * whole register, and that is a leak wearing a filter's clothes.
         */
        if ($status = $request->query('status')) {
            $wanted = array_values(array_filter(
                array_map('trim', explode(',', (string) $status)),
                fn (string $s) => $s !== '',
            ));
            $query->whereIn('status', $wanted);
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
            /*
             * Every permit the renewal covers. `prior_permit_id` above stays
             * the primary — the renewal chain is keyed on it and analytics
             * counts it — and this is the full set the counter was told about.
             * Sent as well as, never instead of, the primary: a caller that
             * sends only this array still gets a chain-keyed filing because
             * the first id becomes the primary below.
             */
            'prior_permit_ids' => ['sometimes', 'array'],
            'prior_permit_ids.*' => ['exists:permits,id'],
            // The ticked escape, carried on the create call so a draft opened
            // from the identify dialog already holds the answer given there
            // rather than waiting on the follow-up PUT to land.
            'prior_permit_declared_none' => ['sometimes', 'boolean'],
            ...$this->amendmentRules(),
            ...$this->feeProfileRules($request),
        ]);

        $business = Business::findOrFail($data['business_id']);
        abort_unless($business->owner_user_id === $request->user()->id, 403, 'This business is not yours.');

        if ($business->isBlockedFromApplying()) {
            throw ValidationException::withMessages([
                'business_id' => ['This business currently can’t file applications. Please contact the LGU to resolve its account status.'],
            ]);
        }

        /*
         * Every prior permit named — primary and set alike — must belong to
         * this business.
         *
         * Checked as one list rather than the primary alone. When the dialog
         * became multi-select the old single check would have waved through an
         * array holding another shop's permit as long as the primary was
         * clean, which is the same hole in a wider doorway: office separability
         * is a boundary, and a filing that names a permit it has no claim to is
         * how a reader learns about a business it may not see.
         */
        $priorIds = $this->priorPermitIds($data);
        if ($priorIds !== []) {
            $ownedCount = $business->permits()->whereKey($priorIds)->count();
            abort_unless(
                $ownedCount === count($priorIds),
                422,
                'A selected prior permit does not belong to this business.'
            );
        }

        $app = Application::create([
            'business_id' => $business->id,
            'applicant_user_id' => $request->user()->id,
            'application_type' => $data['application_type'],
            'title' => $this->cleanTitle($data['title'] ?? null),
            'status' => ApplicationStatus::Draft,
            'prior_permit_id' => $priorIds[0] ?? null,
            // Contradictory answers resolve the same way they do in
            // PriorPermitController: a named permit wins and the escape is not
            // recorded, so the submit gate can never pass on both at once.
            // "Named" now means any permit in the set, not just the primary —
            // ticking three permits and also declaring there are none is the
            // same contradiction it always was.
            'prior_permit_declared_none' => $priorIds === []
                && (bool) ($data['prior_permit_declared_none'] ?? false),
            'payment_mode' => $data['payment_mode'] ?? 'annual',
            'fee_profile' => $data['fee_profile'] ?? null,
            ...$this->amendmentAttributes($data, $data['application_type']),
        ]);
        $app->permitTypes()->sync($data['permit_type_ids']);
        $app->priorPermits()->sync($priorIds);
        $this->syncLineCapitalization($app);

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
            ...$this->amendmentRules(),
            ...$this->feeProfileRules($request),
        ]);

        /*
         * What is being amended can change while the draft is open — an
         * applicant who ticked Location and then realised ownership moved too
         * has to be able to say so. Keyed on any of the four being present so a
         * fee-profile-only autosave does not blank the answer.
         */
        if (array_intersect_key($data, array_flip(self::AMENDMENT_INPUTS))) {
            $application->update(
                $this->amendmentAttributes($data, $application->application_type?->value)
            );
        }

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
            $this->syncLineCapitalization($application);
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

        /*
         * Checklist items 82/84 — an amendment amending nothing is not a filing.
         *
         * The wizard blocks Next on the same rule, but the gate belongs here as
         * well: the browser is not the only way into this endpoint, and a
         * filing that reaches BPLO saying only "amendment" gives the counter
         * nothing to act on. Checked at submit rather than at create because
         * drafts autosave half-answered by design.
         */
        if (
            $application->application_type === ApplicationType::Amendment
            && ! $application->has_amendments
        ) {
            throw ValidationException::withMessages([
                'has_amendments' => ['Choose what is being amended: ownership, location, nature of business, or something else you specify.'],
            ]);
        }

        /*
         * A renewal of nothing is not a renewal.
         *
         * `prior_permit_id` is what makes a renewal a renewal and an amendment
         * an amendment: it names the permit being carried forward or altered.
         * 749 of 756 renewals in the register carry it. The seven that do not
         * got there because null was accepted as an answer without anyone ever
         * having to give it — five on businesses holding no permit at all, one
         * where the question was simply skipped past, and one written directly
         * by DemoSeeder.
         *
         * The escape is still open and still needed: in year one most renewals
         * are of permits issued on paper, and those businesses have nothing in
         * the register to name. But it now has to be TAKEN — the applicant
         * ticks "no BizTrack permit" — rather than fallen into. That is the
         * whole difference between the two states this gate can tell apart and
         * a bare null could not.
         *
         * At submit rather than create, for the same reason as the amendment
         * gate above: drafts autosave half-answered by design. And on the
         * server as well as in the wizard, because the browser is not the only
         * way into this endpoint.
         */
        if (
            in_array($application->application_type, [ApplicationType::Renewal, ApplicationType::Amendment], true)
            && $application->prior_permit_id === null
            && ! $application->prior_permit_declared_none
        ) {
            $verb = $application->application_type === ApplicationType::Renewal ? 'renewing' : 'amending';

            throw ValidationException::withMessages([
                'prior_permit_id' => ["Say which permit you are {$verb} — pick it from your permits, or tell us this business has no permit issued through BizTrack."],
            ]);
        }

        /*
         * Last chance to get the declared capital onto the business record.
         *
         * Every autosave already mirrors it, but submit is the moment the
         * numbers stop being editable, and a client that only ever sent its fee
         * profile with the create call would otherwise reach BPLO with the
         * figure on the application and nothing on the business.
         */
        $this->syncLineCapitalization($application);

        $application = $this->workflow->submit($application);

        return response()->json([
            'data' => new ApplicationResource($application->load($this->fullEager)),
        ]);
    }

    /**
     * Copy the capital declared per line on the fee profile onto the business's
     * own `business_lines` rows.
     *
     * Capital used to be asked twice — against each line in Location & Zoning
     * and again in Business & Tax Profile — and the two answers had different
     * destinations: the first became `business_lines.capitalization`, the second
     * fed the calculator. Only one of them was ever assessed, so the register
     * could hold a figure nobody had been charged on. The question is asked once
     * now, on the fee profile, and this is what keeps the business record in step
     * with it rather than a second answer to the same question.
     *
     * Absent and null are both "not declared" and leave the stored figure alone:
     * a renewal is assessed on gross sales and never restates its capital, and a
     * half-filled draft autosaves many times before it holds one. Only a number
     * actually sent overwrites.
     *
     * Lines match on `psic_code_id`, the same key the wizard restores a draft's
     * categories by. A profile line without one is matched by position, which is
     * how `feeProfileToDraft` reads the older saves that predate the key.
     */
    private function syncLineCapitalization(Application $application): void
    {
        $profile = $application->fee_profile;
        $profileLines = is_array($profile) && is_array($profile['lines'] ?? null)
            ? array_values($profile['lines'])
            : [];

        if ($profileLines === [] || $application->business === null) {
            return;
        }

        $businessLines = $application->business->lines()->orderBy('id')->get();

        foreach ($profileLines as $index => $line) {
            if (! is_array($line) || ! isset($line['capitalization'])) {
                continue;
            }

            $row = isset($line['psic_code_id'])
                ? $businessLines->firstWhere('psic_code_id', (int) $line['psic_code_id'])
                : ($businessLines[$index] ?? null);

            if ($row === null) {
                continue;
            }

            $row->update(['capitalization' => $line['capitalization']]);
        }
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

        return response()->json([
            'data' => StatusHistoryResource::collection($rows),
        ]);
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

    /**
     * The paper form's "Amendment from:" checkboxes, as request keys.
     *
     * `amendment_other` is both the fourth tick and its "(specify)" text: the
     * form has no way to tick Others without naming the other, so the text
     * standing alone as the flag is the form's own rule, not a shortcut.
     */
    private const AMENDMENT_INPUTS = [
        'amendment_ownership', 'amendment_location', 'amendment_nature', 'amendment_other',
        /*
         * A3 belongs in this list as well as in the rules. `update()` keys the
         * whole section-A write on one of these being present, so a payload
         * carrying only From/To — which is exactly what changing the structure
         * and nothing else sends — would otherwise validate cleanly and then be
         * dropped without a write.
         */
        'amendment_from_registration_type', 'amendment_to_registration_type',
    ];

    /** @return array<string, array<int, string>> */
    private function amendmentRules(): array
    {
        return [
            'amendment_ownership' => ['sometimes', 'boolean'],
            'amendment_location' => ['sometimes', 'boolean'],
            'amendment_nature' => ['sometimes', 'boolean'],
            // The column is a plain string; 255 is where it truncates, and a
            // silently truncated answer is worse than a rejected one.
            'amendment_other' => ['sometimes', 'nullable', 'string', 'max:255'],
            /*
             * Section A3, From/To. Constrained to the four structures the paper
             * form prints rather than left free text: the same four are the
             * only values `businesses.registration_type` ever holds, and a
             * fifth spelling arriving here would be a value no other reader in
             * the system knows how to render.
             */
            'amendment_from_registration_type' => [
                'sometimes', 'nullable', 'in:sole_proprietorship,partnership,corporation,cooperative',
            ],
            'amendment_to_registration_type' => [
                'sometimes', 'nullable', 'in:sole_proprietorship,partnership,corporation,cooperative',
            ],
        ];
    }

    /**
     * Every prior permit this filing names, de-duplicated, primary first.
     *
     * One list from two request shapes. `prior_permit_id` is what the old
     * single-select dialog sent and what an existing draft still carries;
     * `prior_permit_ids` is what the multi-select sends. A caller may send
     * either or both, and the primary is whichever the caller named as such —
     * falling back to the first of the set, so a renewal that ticks three
     * permits and names no primary still keys its chain on one of them rather
     * than on null.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>
     */
    private function priorPermitIds(array $data): array
    {
        $ids = array_map('intval', (array) ($data['prior_permit_ids'] ?? []));

        if (! empty($data['prior_permit_id'])) {
            // Primary first, and only once however the caller sent it.
            array_unshift($ids, (int) $data['prior_permit_id']);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The four amendment answers plus the derived `has_amendments`.
     *
     * Derived and never accepted from the caller: it is the OR of the other
     * four, and a client able to set it independently could file an amendment
     * that claims to amend something while naming nothing — which is precisely
     * the state the submit gate above exists to refuse.
     *
     * A filing that is not an amendment is written back to all-false rather
     * than left alone, so that switching a draft's type (or a caller sending
     * the fields on a `new` filing) cannot leave amendment flags on a filing
     * whose form never asked the question.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function amendmentAttributes(array $data, ?string $applicationType): array
    {
        /*
         * Renewals ask section A too, and always did on paper.
         *
         * MCG-BPLO-FO-002 v2.0 opens with A1 "Do you have any changes or
         * amendments in the previous business registration?", and A2/A3 are
         * that question's follow-ups. Gating these columns on
         * `application_type === amendment` meant a renewal that DID change its
         * ownership had nowhere to record it, so the answer was taken from the
         * applicant on paper and thrown away by the system — the BPLO then
         * renewed a sole proprietorship that had become a corporation.
         *
         * `new` still zeroes: a first application amends nothing by definition,
         * and there is no A1 on MCG-BPLO-FO-001 to ask.
         */
        if ($applicationType !== ApplicationType::Amendment->value
            && $applicationType !== ApplicationType::Renewal->value) {
            return [
                'has_amendments' => false,
                'amendment_ownership' => false,
                'amendment_location' => false,
                'amendment_nature' => false,
                'amendment_other' => null,
                'amendment_from_registration_type' => null,
                'amendment_to_registration_type' => null,
            ];
        }

        $ownership = (bool) ($data['amendment_ownership'] ?? false);
        $location = (bool) ($data['amendment_location'] ?? false);
        $nature = (bool) ($data['amendment_nature'] ?? false);
        $other = trim((string) ($data['amendment_other'] ?? ''));
        $hasAmendments = $ownership || $location || $nature || $other !== '';

        /*
         * A3 is only meaningful under a Yes at A1. Written back to null rather
         * than left alone when A1 is No, so an applicant who picked a structure
         * and then changed their answer to "nothing has changed" does not file
         * a renewal that still claims to convert them to a corporation.
         */
        $from = $hasAmendments ? ($data['amendment_from_registration_type'] ?? null) : null;
        $to = $hasAmendments ? ($data['amendment_to_registration_type'] ?? null) : null;

        return [
            'has_amendments' => $hasAmendments,
            'amendment_ownership' => $ownership,
            'amendment_location' => $location,
            'amendment_nature' => $nature,
            'amendment_other' => $other === '' ? null : $other,
            'amendment_from_registration_type' => $from,
            'amendment_to_registration_type' => $to,
        ];
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

        /*
         * The male/female split has to be arithmetically possible against the
         * headcount it divides. Reads both halves off the request rather than
         * the one value it was handed, so the sum is what is checked.
         */
        $splitFitsTotal = function (string $attribute, mixed $value, callable $fail) use ($request) {
            $total = $request->input('fee_profile.employees');
            if (! is_numeric($total)) {
                return;
            }
            $male = $request->input('fee_profile.male_employees');
            $female = $request->input('fee_profile.female_employees');
            $declared = (is_numeric($male) ? (int) $male : 0) + (is_numeric($female) ? (int) $female : 0);
            if ($declared > (int) $total) {
                $fail('Male and female employees together can’t be more than your total number of employees.');
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
            /*
             * BPLO item B2 (new form) / B3 (renewal), and CENRO's "MALE:
             * FEMALE:" box — the split inside the headcount above.
             *
             * It lives on the fee profile rather than on the business record
             * because the total it divides lives here, and because all three
             * papers print the split and the total as ONE item in one box. The
             * `businesses.male_employees` / `female_employees` columns exist, but
             * so do `businesses.total_employees` and `employees_within_lgu`, and
             * nothing in this application has ever written any of the four. The
             * cross-check below is the concrete reason not to separate them: it
             * can only be made while all three numbers are in the same request.
             *
             * "Cannot exceed the total", not "must equal it". Drafts autosave
             * half-typed, so an equality rule would 422 an applicant who has
             * entered the male count and not yet reached the female one — and
             * that failed save is silent, because it happens on a debounce
             * nobody asked for. What this does catch is the contradiction an
             * officer would otherwise have to reconcile at the counter.
             */
            'fee_profile.male_employees' => [...$count, $splitFitsTotal],
            // Attached to BOTH halves, not just the second. A closure rule only
            // runs for an attribute the request actually carries, so hanging the
            // check on `female_employees` alone would let a lone male count of
            // 10 against a total of 5 through untested.
            'fee_profile.female_employees' => [...$count, $splitFitsTotal],
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

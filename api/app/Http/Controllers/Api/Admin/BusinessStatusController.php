<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OwnerSanction;
use App\Services\WorkflowService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin business-status management (permission owner.manage_status). Backs the
 * Owner Status page: list businesses + set active|flagged|suspended|blacklisted.
 */
class BusinessStatusController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private WorkflowService $workflow,
    ) {}

    private const LABELS = [
        'active' => 'Active',
        'flagged' => 'Flagged',
        'suspended' => 'Suspended',
        'blacklisted' => 'Blacklisted',
    ];

    /**
     * What the roster may be ordered by, and the expression for each.
     *
     * Correlated subqueries rather than joins for the two cross-table sorts:
     * this query already eager-loads four relations, and a join onto
     * `permit_fees` would multiply the rows before the paginator counted them —
     * a business with three unpaid fees would be three rows of a twenty-row
     * page. The subquery returns one value per business and leaves the count
     * alone.
     *
     * `registered` is last-registered-first when `dir` is the default `desc`,
     * which is the order this list has always come in.
     */
    private const SORTS = [
        'registered' => 'businesses.created_at',
        'name' => 'businesses.name',
        'status_changed' => 'businesses.status_changed_at',
        'owner' => '(select name from users where users.id = businesses.owner_user_id)',
        'fees' => '(select coalesce(sum(amount), 0) from unbilled_permit_fees
                    where unbilled_permit_fees.business_id = businesses.id
                      and unbilled_permit_fees.billed_at is null)',
    ];

    /**
     * The business roster. Paginated, newest registration first.
     *
     * 705 rows and 122 KB unpaged. `q` and `status` are here so the admin can
     * find the one business they came for instead of paging to it — a roster you
     * can only walk is a roster nobody uses.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            /*
             * `retired` is not a status a business can be SET to — it lists the
             * businesses removed from the register (soft-deleted), which the
             * roster otherwise leaves out (checklist item 21: retired records
             * hidden by default, shown on request).
             */
            'status' => ['sometimes', 'nullable', 'in:active,flagged,suspended,blacklisted,retired'],
            /*
             * ── Ordering, from a whitelist ──────────────────────────────────
             *
             * The roster came back newest-registration-first and only that. An
             * admin owed money by somebody had no way to ask which businesses
             * owe the most, and one holding a name had to page 705 rows to find
             * it [client, 27 September 2026: *"pakilagyan ng mga pwede pang
             * ifilter at isort"*].
             *
             * A whitelist, never the raw parameter: `orderBy($request->input())`
             * is a column name from the internet reaching the query planner.
             */
            'sort' => ['sometimes', 'nullable', 'in:'.implode(',', array_keys(self::SORTS))],
            'dir' => ['sometimes', 'nullable', 'in:asc,desc'],
            /*
             * The two facts an admin narrows by that are not a status: whether
             * the owner is barred (one sanction, several shopfronts) and
             * whether money is outstanding.
             */
            'owner_blacklisted' => ['sometimes', 'nullable', 'boolean'],
            'fees' => ['sometimes', 'nullable', 'in:owing,clear'],
            'filed' => ['sometimes', 'nullable', 'in:yes,never'],
            /*
             * When the business was registered. "Everything registered this
             * quarter" is the shape of half the questions an admin is asked to
             * answer about this register, and sorting by date does not answer
             * it — it puts the quarter at the top of seven hundred rows and
             * leaves the reader to decide where it ends.
             */
            'registered_from' => ['sometimes', 'nullable', 'date'],
            'registered_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:registered_from'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        /*
         * `withCount` and eager-loaded relations, rather than a query per row.
         *
         * The table prints the business's latest filing number and how many it
         * has; doing that off the relation inside the map would be two queries
         * per row and this list is paged at twenty.
         */
        $query = Business::with([
            'owner:id,name,blacklisted_at',
            /*
             * Latest by the CALENDAR, not by insertion order.
             *
             * This was `latest('id')`, and an id is the order rows went into
             * the table rather than the order the filings happened. The client
             * caught it by asking how BIZ-2026-00001 "became" BIZ-2026-00003:
             * Nena's Sari-Sari Store carries a renewal submitted 2026-08-13 as
             * id 1 and the original new filing submitted 2025-10-02 as id 3, so
             * the row showed the 2025 one and called it the latest.
             *
             * `submitted_at` is the date the register keeps, and `created_at`
             * stands in for a draft that has never been handed in — a draft has
             * no tracking id either way, so it can only ever be the fallback
             * for a business whose filings are all drafts.
             */
            'applications' => fn ($a) => $a->select('id', 'business_id', 'tracking_id', 'submitted_at', 'created_at')
                ->orderByRaw('COALESCE(submitted_at, created_at) DESC')
                ->orderByDesc('id')
                ->limit(1),

            /*
             * ── The arrears come down with the row ───────────────────────
             *
             * Client's decision, 17 September 2026: deferred permit fees
             * *"wait indefinitely, and are visible"*. This is the visible
             * half — the business record, wherever an admin looks a business
             * up.
             *
             * Eager-loaded rather than summed per row, for the same reason as
             * the two above: a `sum` per row is the N+1 that makes an admin
             * screen feel broken, and the figure is wanted on every row rather
             * than on demand.
             *
             * `outstanding` in the constraint, not `unclaimed`: a fee already
             * on an unpaid January bill is money the LGU has not received, and
             * an arrears column that hid it would show zero for every business
             * mid-renewal.
             */
            'unbilledPermitFees' => fn ($q) => $q->outstanding()->with('permitType:id,code,name')->orderBy('incurred_at'),
        ])->withCount('applications');

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->whereLike('name', "%{$q}%")
                ->orWhereHas('owner', fn ($o) => $o->whereLike('name', "%{$q}%")));
        }
        if ($request->query('status') === 'retired') {
            $query->onlyTrashed();
        } elseif ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        /*
         * Whose sanction it is. After a blacklisting cascades, three rows of one
         * owner read identically; this is how an admin asks for the businesses
         * caught by somebody else's finding rather than by their own conduct.
         */
        if ($request->has('owner_blacklisted') && $request->query('owner_blacklisted') !== null) {
            $barred = $request->boolean('owner_blacklisted');
            $query->whereHas('owner', fn ($o) => $barred
                ? $o->whereNotNull('blacklisted_at')
                : $o->whereNull('blacklisted_at'));
        }

        // Money outstanding, which is the reason an officer opens this screen
        // when they are not here to sanction anybody.
        if ($fees = $request->query('fees')) {
            $query->when(
                $fees === 'owing',
                fn ($b) => $b->whereHas('unbilledPermitFees', fn ($f) => $f->outstanding()),
                fn ($b) => $b->whereDoesntHave('unbilledPermitFees', fn ($f) => $f->outstanding()),
            );
        }

        /*
         * Registered between two dates. `whereDate`, not a raw comparison
         * against a timestamp: `created_at <= '2026-09-27'` excludes
         * everything registered ON the 27th, because midnight is the earliest
         * moment of the day and every row that day is later than it. An
         * inclusive "to" is what a reader means by a date range.
         */
        if ($from = $request->query('registered_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('registered_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // A business that has never filed is a registration with no paperwork
        // behind it — worth being able to list on its own.
        if ($filed = $request->query('filed')) {
            $query->when(
                $filed === 'yes',
                fn ($b) => $b->has('applications'),
                fn ($b) => $b->doesntHave('applications'),
            );
        }

        /*
         * `orderByRaw` with an expression from the whitelist above, never from
         * the request. `dir` is validated to two words, so it is safe to
         * interpolate; the column never is.
         *
         * `id` last, always: two businesses registered in the same second, or
         * two owing nothing, would otherwise come back in whatever order SQLite
         * felt like — which makes page two repeat rows from page one.
         */
        $sort = $request->query('sort') ?: 'registered';
        $dir = $request->query('dir') ?: ($sort === 'registered' || $sort === 'status_changed' || $sort === 'fees' ? 'desc' : 'asc');

        $page = $query->orderByRaw(self::SORTS[$sort].' '.$dir)
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $businesses = collect($page->items())
            ->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                // The Business Account Number, for the Records table's column.
                'ban' => $b->ban,
                /*
                 * The business number under the name on the table — and it is
                 * a FILING's, which is the thing to be careful about.
                 *
                 * `BIZ-2026-…` is minted per APPLICATION (Numbering::trackingId),
                 * and the tester register already shows what follows: Nena's
                 * Sari-Sari Store holds BIZ-2026-00001 and BIZ-2026-00003,
                 * because every renewal and amendment takes a new one.
                 *
                 * So this is the LATEST — the filing an admin is most likely
                 * holding paperwork for — and the count travels with it. A bare
                 * number on a business with three filings would read as the
                 * business's own, which is the one thing it is not.
                 *
                 * Null when the business has never filed: a business exists in
                 * the register from the moment it is created, and inventing a
                 * number for it would be worse than saying it has none.
                 */
                'tracking_id' => $b->applications->first()?->tracking_id,
                'applications_count' => (int) $b->applications_count,
                /*
                 * The owner, and whether the bar is theirs.
                 *
                 * A row reading "Blacklisted" says nothing about WHY, and
                 * after the cascade most blacklisted rows are blacklisted
                 * because of a finding against the person rather than anything
                 * about that shopfront. The roster marks which, so an admin
                 * looking at three rows of one owner can see it is one
                 * sanction and not three.
                 */
                'owner' => $b->owner ? [
                    'id' => $b->owner->id,
                    'name' => $b->owner->name,
                    'blacklisted' => $b->owner->isBlacklisted(),
                ] : null,
                'status' => $b->status,
                'status_label' => self::LABELS[$b->status] ?? ucfirst((string) $b->status),
                /*
                 * When the business was removed from the register, or null.
                 * A retired row cannot be acted on — every action route binds
                 * the business, and binding skips soft-deleted rows — so the
                 * screen reads this to draw no buttons rather than buttons
                 * that answer 404.
                 */
                'retired_at' => optional($b->deleted_at)->toISOString(),
                'created_at' => optional($b->created_at)->toISOString(),
                /*
                 * Deferred permit fees, as a total AND itemised.
                 *
                 * Both, because they answer different questions and the screen
                 * shows both: the total is what the LGU is owed, and the items
                 * are what it is owed FOR — "Sanitary Permit, June" is what
                 * turns a figure into something an officer can raise with the
                 * owner on the phone.
                 *
                 * An empty list and a zero are the honest answer for a business
                 * with nothing deferred, rather than omitting the keys: a
                 * missing key reads as "not loaded" to a screen that has to
                 * tell those apart.
                 */
                'unbilled_fees' => [
                    /*
                     * Fee plus penalty. A late renewal freezes a surcharge
                     * onto its row (Secs. 8A.04/8A.05) and a total that
                     * counted only `amount` would understate the arrears
                     * on the very screen used to chase them.
                     */
                    'total' => round((float) $b->unbilledPermitFees->sum(
                        fn ($f) => (float) $f->amount + (float) $f->surcharge + (float) $f->interest
                    ), 2),
                    'items' => $b->unbilledPermitFees->map(fn ($fee) => [
                        'permit_type' => $fee->permitType?->name,
                        'permit_code' => $fee->permitType?->code,
                        'amount' => (float) $fee->amount,
                        /*
                         * Separate from the fee, never folded in: the
                         * penalty is the figure a business disputes, and
                         * one merged number gives them nothing to dispute
                         * and the officer nothing to explain.
                         */
                        'surcharge' => (float) $fee->surcharge,
                        'interest' => (float) $fee->interest,
                        'months_late' => (int) $fee->months_late,
                        'incurred_at' => optional($fee->incurred_at)->toISOString(),
                        /*
                         * Claimed but unpaid is a real and different state: the
                         * fee is on a bill the applicant has been shown and has
                         * not settled. An officer chasing it needs to know that
                         * a renewal is already in flight carrying it.
                         */
                        'on_a_bill' => $fee->billed_on_application_id !== null,
                    ])->values(),
                ],
            ])->values();

        return response()->json([
            'data' => $businesses,
            'meta' => $this->pageMeta($page),
        ]);
    }

    /**
     * Who is blacklisted, and everything they own.
     *
     * ── Why this is a register of PEOPLE ──────────────────────────────────
     *
     * The business roster answers "which shopfronts are barred", and after
     * the cascade that is three rows saying the same thing about one person,
     * with nothing on screen to say they are the same thing. The question an
     * admin actually has - who is barred from the register, and what does the
     * bar cover - has no answer on a list of businesses
     * [client, 27 September 2026: *"sa blacklisted ay mismong owner ang naka
     * record don at mga listahan na rin ng mga business nya ay kasama"*].
     *
     * So: one row per person, their reason, the date, who decided, and every
     * business registered to them - including any registered SINCE, which the
     * cascade never touched and which they still cannot file for.
     */
    public function blacklistedOwners(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = User::whereNotNull('blacklisted_at')
            ->with([
                'businesses:id,owner_user_id,name,status,created_at',
                'blacklistedBy:id,name',
            ]);

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('businesses', fn ($b) => $b->where('name', 'like', "%{$q}%")));
        }

        $page = $query->orderByDesc('blacklisted_at')->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (User $owner) => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'mobile_number' => $owner->mobile_number,
                'blacklisted_at' => optional($owner->blacklisted_at)->toISOString(),
                'reason' => $owner->blacklist_reason,
                // Named, because a sanction nobody signed is one nobody can
                // follow up. Null where the record predates this column.
                'blacklisted_by' => $owner->blacklistedBy?->name,
                'businesses' => $owner->businesses->map(fn (Business $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'status' => $b->status,
                    'status_label' => self::LABELS[$b->status] ?? ucfirst((string) $b->status),
                    /*
                     * Registered after the bar was imposed, which the cascade
                     * could not have caught. It is still barred - the owner's
                     * blacklisting is read on every filing attempt - but its
                     * own column says Active, and an admin comparing the two
                     * should be told why rather than left to wonder.
                     */
                    'registered_after' => $b->created_at !== null
                        && $owner->blacklisted_at !== null
                        && $b->created_at->greaterThan($owner->blacklisted_at),
                ])->values(),
            ])->values(),
            'meta' => $this->pageMeta($page),
        ]);
    }

    /*
     * ── Business Owner Status, one row per OWNER ─────────────────────────────
     *
     * [Client, 5 October 2026: "ang nandoon lang tlga specific ay name ng
     * owner, then do drop down na lang din mga businesses nya kung marami,
     * then status, actions (active o blacklisted na lang) then view status
     * history pa rin".] The page lists people. Each row is an owner, their
     * businesses (a dropdown when there are several), the owner's status, and
     * the two actions. Blacklisting and reinstating go through OwnerSanction.
     */
    public function owners(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'in:active,blacklisted'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = User::query()
            ->whereHas('businesses')
            ->with([
                'businesses' => fn ($b) => $b->select('id', 'owner_user_id', 'name', 'ban', 'status', 'created_at')
                    ->with(['applications' => fn ($a) => $a->select('id', 'business_id', 'tracking_id', 'submitted_at', 'created_at')
                        ->orderByRaw('COALESCE(submitted_at, created_at) DESC')
                        ->orderByDesc('id')])
                    ->orderBy('name'),
                'blacklistedBy:id,name',
            ]);

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('businesses', fn ($b) => $b->where('name', 'like', "%{$q}%")));
        }

        match ($request->query('status')) {
            'blacklisted' => $query->whereNotNull('blacklisted_at'),
            'active' => $query->whereNull('blacklisted_at'),
            default => null,
        };

        $page = $query->orderByRaw('blacklisted_at IS NULL')->orderByDesc('blacklisted_at')->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (User $owner) => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'status' => $owner->isBlacklisted() ? 'blacklisted' : 'active',
                'status_label' => $owner->isBlacklisted() ? 'Blacklisted' : 'Active',
                'blacklisted_at' => optional($owner->blacklisted_at)->toISOString(),
                'reason' => $owner->blacklist_reason,
                'blacklisted_by' => $owner->blacklistedBy?->name,
                'businesses' => $owner->businesses->map(fn (Business $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'ban' => $b->ban,
                    'status' => $b->status,
                    'status_label' => self::LABELS[$b->status] ?? ucfirst((string) $b->status),
                    'tracking_id' => $b->applications->first()?->tracking_id,
                    'created_at' => optional($b->created_at)->toISOString(),
                ])->values(),
            ])->values(),
            'meta' => $this->pageMeta($page),
        ]);
    }

    /** Change an owner's status: Active or Blacklisted, with a reason. */
    public function ownerStatus(Request $request, User $owner, OwnerSanction $sanction): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,blacklisted'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'status.in' => 'Choose Active or Blacklisted.',
            'reason.required' => 'Say why. The owner is told, and it is recorded in the status history.',
        ]);

        abort_unless($owner->businesses()->exists(), 422, 'This account holds no business.');

        if ($data['status'] === 'blacklisted') {
            abort_if($owner->isBlacklisted(), 422, 'This owner is already blacklisted.');
            $moved = $sanction->blacklist($owner, $data['reason'], $request->user());
        } else {
            abort_unless($owner->isBlacklisted(), 422, 'This owner is already active.');
            $moved = $sanction->reinstate($owner, $data['reason'], $request->user());
        }

        return response()->json(['data' => [
            'id' => $owner->id,
            'status' => $data['status'],
            'status_label' => $data['status'] === 'blacklisted' ? 'Blacklisted' : 'Active',
            'businesses_moved' => $moved->count(),
        ]]);
    }

    /**
     * An owner's status history: every blacklisting and reinstatement, newest
     * first, with the reason and who decided, and what it did to the
     * businesses. Read off the audit log, like the permit history.
     */
    public function ownerHistory(User $owner): JsonResponse
    {
        $logs = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $owner->id)
            ->whereIn('action', ['owner.blacklisted', 'owner.blacklist_lifted'])
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'to' => $log->action === 'owner.blacklisted' ? 'blacklisted' : 'active',
                'label' => $log->action === 'owner.blacklisted' ? 'Owner blacklisted — businesses suspended' : 'Owner reinstated — businesses restored',
                'reason' => $log->changes['reason'] ?? null,
                'by' => $log->user?->name,
            ]);

        $logs->push([
            'at' => $owner->created_at?->toIso8601String(),
            'to' => 'active',
            'label' => 'Account created',
            'reason' => null,
            'by' => null,
        ]);

        return response()->json(['data' => $logs->values()->all()]);
    }

    public function updateStatus(Request $request, Business $business): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,flagged,suspended,blacklisted'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        /*
         * ── Locked while the owner is blacklisted ────────────────────────────
         *
         * [Client, 5 October 2026: "di dapat basta basta ma eedit status ng
         * mga business na yon".] The business is suspended because its OWNER
         * is barred; changing it here would contradict that finding. The way
         * back is to reinstate the owner, which returns the businesses the
         * blacklisting suspended.
         */
        $business->loadMissing('owner');
        if ($business->owner?->isBlacklisted()) {
            throw ValidationException::withMessages([
                'status' => [
                    "The owner, {$business->owner->name}, is blacklisted, so {$business->name} stays suspended and "
                    .'its status cannot be changed. Reinstate the owner from Business Owner Status first.',
                ],
            ]);
        }

        // Blacklisting is of the owner now; asked of one business, it is asked of the person.
        if ($data['status'] === Business::STATUS_BLACKLISTED && $business->owner !== null) {
            $moved = app(OwnerSanction::class)->blacklist($business->owner, $data['reason'], $request->user());
            $business->refresh();

            return response()->json(['data' => [
                'id' => $business->id,
                'status' => $business->status,
                'status_label' => self::LABELS[$business->status] ?? ucfirst($business->status),
                'owner_blacklisted' => true,
                'others_blacklisted' => max(0, $moved->count() - 1),
                'others_restored' => 0,
            ]]);
        }

        $from = $business->status;

        /*
         * `status_changed_at` is only touched when the status actually moves.
         * It dates a blacklisting on the Business Closure Trend, so re-saving
         * the same status — which the roster lets an admin do, and which the QA
         * sweeps in the audit log did twice — must not shift that closure into
         * the current month. Re-blacklisting an already-blacklisted business is
         * not a second closure.
         *
         * The audit row is still written either way: "an admin looked at this
         * and left it alone, for this reason" is a fact worth keeping, it is
         * just not a status change.
         */
        /*
         * Blacklisting closes a business and suspending takes it out of
         * operation — both retire the record, so the audit row keeps the
         * business as it stood, with its address and lines of business, before
         * the change (Audit Log 1). Only when the status actually moves there:
         * re-saving a blacklisting retires nothing new.
         */
        $snapshot = $data['status'] !== $from && in_array($data['status'], ['blacklisted', 'suspended'], true)
            ? Audit::snapshot($business, ['address', 'lines'])
            : null;

        $changes = ['status' => $data['status']];
        if ($data['status'] !== $from) {
            $changes['status_changed_at'] = now();
        }
        $business->update($changes);

        /*
         * -- Blacklisting is of the OWNER ------------------------------------
         *
         * It used to write one business's column, so an owner barred for
         * falsified documents filed for their other two the same afternoon.
         * A suspension is about a premises that failed an inspection; a
         * blacklisting is a finding against whoever is filing, and it only
         * means anything if it follows the person [client, 27 September 2026:
         * *"once na naka blacklist, mismong owner na tlga yan, bale lahat
         * lahat ng business nya ay blacklisted na"*].
         *
         * Only on a real move, like everything else in this method: re-saving
         * a blacklisting already in force must not re-date the sanction or
         * re-bar businesses an earlier reinstatement had since released.
         */
        $sweep = ['blacklisted' => 0, 'restored' => 0];
        if ($data['status'] !== $from) {
            $sweep = $this->carryToTheOwner($business, $data['status'], $data['reason'], $request);
        }

        Audit::log('business.status_changed', $business, [
            'from' => $from,
            'to' => $data['status'],
            'reason' => $data['reason'],
        ], $snapshot);

        /*
         * ── The certificates follow the business ──────────────────────────
         *
         * Client's decision, 24 September 2026. Until then this endpoint
         * wrote one column and barred the owner from filing, and the permits
         * carried on reading Active — so a business suspended for violations
         * printed a clean certificate and answered VALID to the QR check at
         * the counter. The sanction existed everywhere except the one place
         * an inspector looks.
         *
         * Only when the status actually MOVED, the same condition the audit
         * note and the notification below already use: re-saving a
         * blacklisting an admin has already applied must not re-suspend
         * permits a reinstatement had since brought back.
         *
         * `flagged` deliberately does nothing here. It is a watch marker, not
         * a sanction — `isBlockedFromApplying` ignores it too — and taking a
         * business's certificates away for being watched would be a heavier
         * act than the status means.
         */
        if ($data['status'] !== $from) {
            if (in_array($data['status'], ['suspended', Business::STATUS_BLACKLISTED], true)) {
                $this->workflow->suspendPermitsForBusiness($business, $data['reason']);
            } elseif ($data['status'] === 'active') {
                $this->workflow->restorePermitsForBusiness($business);
            }

            /*
             * Off hold: what waited while the business was suspended moves on
             * now — a payment that landed, a filing that came ready. Flagged
             * as well as Active, because a flag is not a sanction (above).
             */
            if (in_array($data['status'], ['active', 'flagged'], true)) {
                $this->workflow->releaseHeldFilings($business);
            }
        }

        /*
         * Tell the owner — but only when something actually moved.
         *
         * Same condition as `status_changed_at` above and for the same reason:
         * the roster lets an admin re-save the status a business already has,
         * and the QA sweeps in the audit log did exactly that. An audit row for
         * "looked at and left alone" is worth keeping; a notification saying
         * "your business is now Blacklisted" for the second time, weeks later,
         * is a false alarm to the one person least able to check.
         */
        if ($data['status'] !== $from) {
            $this->notifications->businessStatusChanged(
                $business,
                (string) $from,
                $data['status'],
                $data['reason'],
                self::LABELS[$data['status']] ?? $data['status'],
            );
        }

        return response()->json([
            'data' => [
                'id' => $business->id,
                'status' => $business->status,
                'status_label' => self::LABELS[$business->status] ?? ucfirst($business->status),
                /*
                 * What ELSE moved, so the screen can say it rather than making
                 * the admin reload and count. Blacklisting one business of
                 * three changes three rows, and a reply describing only the
                 * one that was clicked is a reply that hides two thirds of
                 * what just happened.
                 */
                'owner_blacklisted' => $business->owner?->isBlacklisted() ?? false,
                'others_blacklisted' => $sweep['blacklisted'],
                'others_restored' => $sweep['restored'],
            ],
        ]);
    }

    /**
     * Lift a blacklisting from the owner, and set every business they hold to
     * one status.
     *
     * ── Why this is one act and not several ────────────────────────────────
     *
     * The sanctions card used to offer Change Status per business, and that
     * was incoherent: a blacklisting falls on the PERSON and reaches
     * everything they own, so releasing one shopfront while the other three
     * stayed barred left a register that contradicted itself — the owner is
     * blacklisted, and one of their businesses says Active
     * [client, 28 September 2026: *"hindi pwedeng isahang business lang ang
     * mamomodify mo tas yung iba naka tag pa rin sa blacklisted"*].
     *
     * The bar went on as one act. It comes off as one act.
     *
     * ── Why Blacklisted is not among the choices ───────────────────────────
     *
     * They already are. Offering it would be a control whose only effect is to
     * re-date a sanction that is already in force — which is exactly what the
     * "already blacklisted" guard on the business dialog refuses. To bar
     * somebody who is not yet barred, use the business roster; this endpoint
     * is the way back.
     */
    public function liftOwnerBlacklist(Request $request, User $owner): JsonResponse
    {
        $data = $request->validate([
            /*
             * The three a released business can land on, and no more.
             * `blacklisted` is absent by design — see the note above.
             */
            'status' => ['required', 'in:active,flagged,suspended'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'status.in' => 'Choose Active, Flagged, or Suspended. They are already blacklisted.',
        ]);

        abort_unless($owner->isBlacklisted(), 422, 'This owner is not blacklisted.');

        $to = $data['status'];
        $reason = $data['reason'];

        $moved = DB::transaction(function () use ($owner, $to, $reason, $request) {
            $owner->forceFill([
                'blacklisted_at' => null,
                'blacklist_reason' => null,
                'blacklisted_by' => null,
            ])->save();

            Audit::log('owner.blacklist_lifted', $owner, [
                'reason' => $reason,
                'to' => $to,
                'by' => $request->user()?->id,
            ]);

            /*
             * EVERY business they hold, not only the ones the cascade barred.
             * One registered after the blacklisting still reads Active while
             * being refused every filing, and leaving it behind would keep the
             * exact contradiction this endpoint exists to end.
             */
            $businesses = $owner->businesses()->get();

            foreach ($businesses as $business) {
                $from = $business->status;
                if ($from === $to) {
                    continue;
                }

                $business->update(['status' => $to, 'status_changed_at' => now()]);

                Audit::log('business.status_changed', $business, [
                    'from' => $from,
                    'to' => $to,
                    'reason' => "Owner released from blacklist: {$reason}",
                    'released_with_owner' => $owner->id,
                ]);

                /*
                 * Suspended keeps the certificates suspended; the other two
                 * bring them back, and move on whatever was held while the
                 * owner was barred. Flagged is a watch marker and never a
                 * sanction — `isBlockedFromApplying` ignores it — so a flagged
                 * business trades on valid permits like an active one.
                 */
                if ($to === 'suspended') {
                    $this->workflow->suspendPermitsForBusiness($business, $reason);
                } else {
                    $this->workflow->restorePermitsForBusiness($business);
                    $this->workflow->releaseHeldFilings($business);
                }
            }

            return $businesses;
        });

        /*
         * One notice for the person, not one per shopfront. The bar was on
         * them, so its lifting is one piece of news — and four notifications
         * saying the same thing about four businesses is how somebody learns
         * to swipe this app's messages away.
         */
        $this->notifications->push(
            $owner,
            'account_status',
            $to === 'active' ? 'Your account has been restored' : 'Your account is no longer blacklisted',
            $moved->count() === 1
                ? "{$moved->first()->name} is now ".self::LABELS[$to].". Reason: {$reason}"
                : "All {$moved->count()} of your businesses are now ".self::LABELS[$to].". Reason: {$reason}",
            '/dashboard',
        );

        return response()->json([
            'data' => [
                'owner_blacklisted' => false,
                'status' => $to,
                'status_label' => self::LABELS[$to],
                'businesses_moved' => $moved->count(),
            ],
        ]);
    }

    /**
     * Carry a blacklisting - or its lifting - to the owner and their other
     * businesses.
     *
     * Returns how many OTHER businesses moved, so the reply can say so.
     *
     * ── Why reinstating is the mirror and not a separate decision ──────────
     *
     * Setting a blacklisted business back to Active is an admin saying the
     * finding no longer stands. Leaving the owner barred while one of their
     * businesses reads Active would be the worst of both: a roster that says
     * they may trade and an endpoint that refuses every filing, with nothing
     * on either screen explaining the disagreement. So the lifting is
     * owner-wide too, and the businesses that were blacklisted BY the cascade
     * come back with it.
     *
     * Suspended and Flagged are untouched here on purpose. They are facts
     * about a premises, they are set one business at a time, and a business
     * suspended for its own reasons must not be quietly reactivated because a
     * different shopfront was reinstated.
     */
    private function carryToTheOwner(Business $business, string $to, string $reason, Request $request): array
    {
        $owner = $business->owner;
        if ($owner === null) {
            return ['blacklisted' => 0, 'restored' => 0];
        }

        if ($to === Business::STATUS_BLACKLISTED) {
            $owner->forceFill([
                'blacklisted_at' => now(),
                'blacklist_reason' => $reason,
                'blacklisted_by' => $request->user()?->id,
            ])->save();

            Audit::log('owner.blacklisted', $owner, [
                'reason' => $reason,
                'because_of_business_id' => $business->id,
            ]);

            $others = $owner->businesses()
                ->whereKeyNot($business->id)
                ->where('status', '!=', Business::STATUS_BLACKLISTED)
                ->get();

            foreach ($others as $other) {
                $other->update([
                    'status' => Business::STATUS_BLACKLISTED,
                    'status_changed_at' => now(),
                ]);
                Audit::log('business.status_changed', $other, [
                    'from' => $other->getOriginal('status'),
                    'to' => Business::STATUS_BLACKLISTED,
                    // Named as a consequence, because it is: nobody clicked
                    // this row, and an audit trail that cannot tell a decision
                    // from its fallout is one nobody can reconstruct.
                    'reason' => "Owner blacklisted: {$reason}",
                    'cascaded_from_business_id' => $business->id,
                ]);
                $this->workflow->suspendPermitsForBusiness($other, "Owner blacklisted: {$reason}");
            }

            return ['blacklisted' => $others->count(), 'restored' => 0];
        }

        // Lifting it. Only from a blacklisted owner, and only to Active -
        // moving a blacklisted business to Flagged or Suspended is a
        // half-measure nobody asked for and would leave the owner barred.
        if ($to === 'active' && $owner->isBlacklisted()) {
            $owner->forceFill([
                'blacklisted_at' => null,
                'blacklist_reason' => null,
                'blacklisted_by' => null,
            ])->save();

            Audit::log('owner.blacklist_lifted', $owner, [
                'reason' => $reason,
                'because_of_business_id' => $business->id,
            ]);

            $others = $owner->businesses()
                ->whereKeyNot($business->id)
                ->where('status', Business::STATUS_BLACKLISTED)
                ->get();

            foreach ($others as $other) {
                $other->update(['status' => 'active', 'status_changed_at' => now()]);
                Audit::log('business.status_changed', $other, [
                    'from' => Business::STATUS_BLACKLISTED,
                    'to' => 'active',
                    'reason' => "Owner reinstated: {$reason}",
                    'cascaded_from_business_id' => $business->id,
                ]);
                $this->workflow->restorePermitsForBusiness($other);
                $this->workflow->releaseHeldFilings($other);
            }

            return ['blacklisted' => 0, 'restored' => $others->count()];
        }

        return ['blacklisted' => 0, 'restored' => 0];
    }

    /**
     * Move a business to another owner account.
     *
     * ── The other half of an ownership amendment ──────────────────────────
     *
     * MCG-BPLO-FO-003 section II is a CHANGE OF OWNERSHIP, and the client's
     * decision of 21 September 2026 was that the applicant states the new
     * owner and BPLO moves the account. Nothing in this codebase could: the
     * admin "Reassign" screen moves FILINGS BETWEEN OFFICERS, which shares a
     * word and does something else, and `owner_user_id` was written in exactly
     * one place — `BusinessController::store`, from the session. So an
     * approved ownership amendment landed nowhere.
     *
     * ── Why the approval does not do this itself ──────────────────────────
     *
     * Because it cannot know who to move it TO. The applicant types a name;
     * an account is a different thing, may not exist, and matching a person
     * to one by name is how a business ends up with the wrong Maria Reyes.
     * BPLO names the account, having read the Deed of Transfer the filing
     * carries, and that judgement is the step this endpoint exists to record.
     *
     * The permits move with it because they belong to the business, not to the
     * account — nothing about the certificates changes except who can now see
     * them. The OLD owner loses that visibility, which is the point.
     */
    public function transferOwner(Request $request, Business $business): JsonResponse
    {
        /*
         * By EMAIL, not by id. BPLO is holding a Deed of Transfer and a name,
         * and the one identifier they can actually get from the new owner is
         * the address that owner registered with. An id would need a roster of
         * every account in the city to pick from, which is a screen nobody
         * asked for and a cross-account read nobody needs.
         */
        $data = $request->validate([
            'owner_email' => ['required', 'email', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $from = $business->owner_user_id;
        $to = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['owner_email']))])->first();

        /*
         * The case that made this endpoint necessary and is also its commonest
         * dead end: the new owner has no BizTrack account. Said plainly, with
         * the next step in it, because the officer cannot create one for them
         * and would otherwise be looking at "invalid email".
         */
        if ($to === null) {
            throw ValidationException::withMessages([
                'owner_email' => [
                    'No BizTrack account uses that email address. The new owner has to register '
                    .'one before the business can be transferred to them.',
                ],
            ]);
        }

        /*
         * Refused rather than shrugged at. "Transferred" on a screen that did
         * nothing is the failure the Reassign dialog was fixed for on
         * 10 September 2026, one office over — an admin typing a reason,
         * pressing the button and being told it happened.
         */
        if ($to->id === $from) {
            throw ValidationException::withMessages([
                'owner_email' => ['This business already belongs to that account.'],
            ]);
        }

        /*
         * An inactive account cannot file, so transferring to one strands the
         * business: nobody could renew it, and the next January would pass
         * with no filing and no explanation.
         */
        if (! $to->is_active) {
            throw ValidationException::withMessages([
                'owner_email' => [
                    'That account is deactivated, so it could not file for this business. '
                    .'Reactivate it first.',
                ],
            ]);
        }

        $business->update(['owner_user_id' => $to->id]);

        Audit::log('business.owner_transferred', $business, [
            'from_user_id' => $from,
            'to_user_id' => $to->id,
            'reason' => $data['reason'],
        ]);

        /*
         * Both sides are told. The new owner because a business has appeared
         * in their account and they are now the one who must renew it; the
         * previous owner because one has left theirs, and a register that
         * moves a business silently is one nobody can audit from the outside.
         */
        foreach (array_filter([$to, $from === null ? null : User::find($from)]) as $person) {
            $this->notifications->push(
                $person,
                'business',
                $person->id === $to->id
                    ? 'A business was transferred to you'
                    : 'A business was transferred from your account',
                $person->id === $to->id
                    ? "{$business->name} is now registered to you. You are the one who files its "
                        .'renewals from here on.'
                    : "{$business->name} has been transferred to another owner by the BPLO.",
                // `/dashboard`, not `/businesses`: there is no such route in
                // App.tsx, and this link is now also the button in the e-mail
                // the owner gets. Same fix businessStatusChanged() records.
                '/dashboard',
                $business,
            );
        }

        return response()->json([
            'data' => [
                'id' => $business->id,
                'owner_user_id' => $business->owner_user_id,
                'owner_name' => $to->fullName(),
            ],
        ]);
    }
}

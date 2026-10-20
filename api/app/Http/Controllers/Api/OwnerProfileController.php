<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermitStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\ApplicationVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Business owners as an office reads them: a list, and one owner's profile.
 *
 * ── Why this exists [client, 20 October 2026] ──────────────────────────────
 *
 * "Gagawa uli ng another button sa pinaka left gilid para sa mga owner, tas
 * don pwede i click ang mga owner then once click makikita na mga details
 * nya (account information, payment history, status history, etc. — other
 * permits na dapat naview lang kada admin)." And the Owner column on the
 * Permits table links here.
 *
 * ── Who sees whom ──────────────────────────────────────────────────────────
 *
 * The same boundary the Permits table and the queue already draw
 * (ApplicationVisibility). BPLO and the super admin read every office, so
 * every owner. A clearance office reads only the owners whose filings were
 * routed to it, and on each profile only those businesses, those filings and
 * the payments on them. nav.ts gives the reason an office does not browse
 * the whole register of owner accounts; this keeps to it.
 *
 * Inside that boundary the owner's OTHER permits are shown read-only — type,
 * number, status and term, which the public verify page already tells anyone
 * holding the certificate. No certificate opens from here: an office still
 * downloads only what it issued (PermitController::authorizeView).
 */
class OwnerProfileController extends Controller
{
    private const BUSINESS_LABELS = [
        'active' => 'Active',
        'flagged' => 'Flagged',
        'suspended' => 'Suspended',
        'blacklisted' => 'Blacklisted',
    ];

    private const PAYMENT_LABELS = [
        'pending' => 'Pending',
        'completed' => 'Paid',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'in:active,blacklisted'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $reader = $request->user();
        $visible = fn ($b) => $this->visibleBusinesses($b, $reader);

        $query = User::query()
            ->whereHas('businesses', $visible)
            ->with(['businesses' => fn ($b) => $visible($b)
                ->select('id', 'owner_user_id', 'name', 'ban', 'status')
                ->withCount(['permits as active_permits_count' => fn ($p) => $p->where('status', PermitStatus::Active)])
                ->orderBy('name')]);

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('first_name', 'like', "%{$q}%")
                ->orWhere('last_name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('mobile_number', 'like', "%{$q}%")
                ->orWhereHas('businesses', fn ($b) => $visible($b)->where(fn ($n) => $n
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('ban', 'like', "%{$q}%"))));
        }

        match ($request->query('status')) {
            'blacklisted' => $query->whereNotNull('blacklisted_at'),
            'active' => $query->whereNull('blacklisted_at'),
            default => null,
        };

        $page = $query->orderBy('last_name')->orderBy('first_name')->orderBy('name')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (User $owner) => [
                'id' => $owner->id,
                'name' => $this->nameOf($owner),
                'email' => $owner->email,
                'mobile_number' => $owner->mobile_number,
                'status' => $owner->isBlacklisted() ? 'blacklisted' : 'active',
                'status_label' => $owner->isBlacklisted() ? 'Blacklisted' : 'Active',
                'active_permits' => (int) $owner->businesses->sum('active_permits_count'),
                'businesses' => $owner->businesses->map(fn (Business $b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'ban' => $b->ban,
                    'status' => $b->status,
                    'status_label' => self::BUSINESS_LABELS[$b->status] ?? ucfirst((string) $b->status),
                ])->values(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, User $owner): JsonResponse
    {
        $reader = $request->user();

        $businesses = Business::query()
            ->where('owner_user_id', $owner->id)
            ->tap(fn ($b) => $this->visibleBusinesses($b, $reader))
            ->with([
                'address.barangay:id,name',
                'permits' => fn ($p) => $p->with(['permitType.department:id,code,name', 'issuedBy.department:id,code'])
                    ->orderByDesc('issued_at')->orderByDesc('id'),
            ])
            ->orderBy('name')
            ->get();

        // Not "forbidden": to an office, an owner it never handled is not on
        // its register at all, and a 403 would confirm the account exists.
        abort_if($businesses->isEmpty(), 404, 'This owner is not on your register.');

        $owner->loadMissing('blacklistedBy:id,name,first_name,last_name');

        $applications = Application::query()
            ->whereIn('business_id', $businesses->pluck('id'))
            ->tap(fn ($a) => ApplicationVisibility::scope($a, $reader))
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->get(['id', 'business_id', 'tracking_id', 'application_type', 'status', 'submitted_at', 'decided_at']);

        $payments = Payment::query()
            ->whereIn('application_id', $applications->pluck('id'))
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')->orderByDesc('id')
            ->get(['id', 'application_id', 'reference_number', 'amount', 'method', 'status', 'paid_at', 'created_at']);

        $permits = $businesses->flatMap->permits;
        $names = $businesses->pluck('name', 'id');
        $tracking = $applications->pluck('tracking_id', 'id');
        $businessOf = $applications->pluck('business_id', 'id');

        return response()->json(['data' => [
            'account' => [
                'id' => $owner->id,
                'name' => $this->nameOf($owner),
                'first_name' => $owner->first_name,
                'middle_name' => $owner->middle_name,
                'last_name' => $owner->last_name,
                'suffix' => $owner->suffix,
                'gender' => $owner->gender,
                'email' => $owner->email,
                'mobile_number' => $owner->mobile_number,
                'home_address' => collect([
                    $owner->home_street, $owner->home_barangay, $owner->home_city,
                    $owner->home_province, $owner->home_postal_code,
                ])->filter()->implode(', ') ?: null,
                'created_at' => $owner->created_at?->toIso8601String(),
                'email_verified_at' => $owner->email_verified_at?->toIso8601String(),
                'last_login_at' => $owner->last_login_at?->toIso8601String(),
                'is_active' => (bool) $owner->is_active,
                'status' => $owner->isBlacklisted() ? 'blacklisted' : 'active',
                'status_label' => $owner->isBlacklisted() ? 'Blacklisted' : 'Active',
                'blacklisted_at' => $owner->blacklisted_at?->toIso8601String(),
                'blacklist_reason' => $owner->blacklist_reason,
                'blacklisted_by' => $owner->blacklistedBy ? $this->nameOf($owner->blacklistedBy) : null,
            ],
            'summary' => [
                'businesses' => $businesses->count(),
                'active_permits' => $permits->where('status', PermitStatus::Active)->count(),
                'filings' => $applications->count(),
                /*
                 * Permits an officer would act on: lapsed, held or decided
                 * against. It replaced a lifetime "total paid" [client, 20
                 * October 2026: "do admins really need Total paid? kung ano
                 * lang need"] — a sum nobody acts on, where this is the count
                 * that sends an officer to the businesses below.
                 */
                'needs_attention' => $permits->whereIn('status', [
                    PermitStatus::Expired, PermitStatus::Suspended, PermitStatus::Revoked, PermitStatus::Rejected,
                ])->count(),
            ],
            'businesses' => $businesses->map(fn (Business $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'ban' => $b->ban,
                'status' => $b->status,
                'status_label' => self::BUSINESS_LABELS[$b->status] ?? ucfirst((string) $b->status),
                'address' => collect([$b->address?->line1, $b->address?->barangay?->name])->filter()->implode(', ') ?: null,
                'registered_at' => $b->created_at?->toIso8601String(),
                'permits' => $this->orderPermits($b->permits)->map(fn (Permit $p) => [
                    'id' => $p->id,
                    'permit_number' => $p->permit_number,
                    'type_code' => $p->permitType?->code,
                    'type_name' => $p->permitType?->name,
                    'office_code' => $p->permitType?->department?->code,
                    'status' => $p->status->value,
                    'status_label' => $p->status->label(),
                    'valid_from' => $p->valid_from?->toDateString(),
                    'valid_until' => $p->valid_until?->toDateString(),
                ])->values(),
            ])->values(),
            'filings' => $applications->map(fn (Application $a) => [
                'id' => $a->id,
                'tracking_id' => $a->tracking_id,
                'business_name' => $names[$a->business_id] ?? null,
                'type' => $a->application_type?->value,
                'type_label' => $a->application_type?->label(),
                'status' => $a->status?->value,
                'status_label' => $a->status?->label(),
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'decided_at' => $a->decided_at?->toIso8601String(),
            ])->values(),
            'payments' => $payments->map(fn (Payment $p) => [
                'id' => $p->id,
                'reference_number' => $p->reference_number,
                'tracking_id' => $tracking[$p->application_id] ?? null,
                'business_name' => $names[$businessOf[$p->application_id] ?? null] ?? null,
                'amount' => (float) $p->amount,
                'method' => $p->method?->value,
                'method_label' => $p->method?->label(),
                'status' => $p->status?->value,
                'status_label' => self::PAYMENT_LABELS[$p->status?->value] ?? ucfirst((string) $p->status?->value),
                'paid_at' => $p->paid_at?->toIso8601String(),
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values(),
            'history' => $this->history($owner, $reader, $businesses, $permits),
        ]]);
    }

    /**
     * Narrow a Business query to what this reader may see: every business for
     * a reader of every office, otherwise those with a filing routed to them.
     * Takes the builder or the `businesses` relation, and hands it back.
     */
    private function visibleBusinesses(Builder|HasMany $query, User $reader): Builder|HasMany
    {
        if (! ApplicationVisibility::readsEveryOffice($reader)) {
            $query->whereHas('applications', fn ($a) => ApplicationVisibility::scope($a, $reader));
        }

        return $query;
    }

    /**
     * The Mayor's Permit first, then live certificates before the ones a
     * renewal replaced or a term ended, newest first within each.
     *
     * @param  Collection<int, Permit>  $permits
     * @return Collection<int, Permit>
     */
    private function orderPermits(Collection $permits): Collection
    {
        $rank = fn (Permit $p) => match ($p->status) {
            PermitStatus::Superseded => 2,
            PermitStatus::Expired => 1,
            default => 0,
        };

        return $permits->sortBy([
            fn (Permit $a, Permit $b) => ($a->permitType?->code === PermitType::OUTCOME_CODE ? 0 : 1)
                <=> ($b->permitType?->code === PermitType::OUTCOME_CODE ? 0 : 1),
            fn (Permit $a, Permit $b) => $rank($a) <=> $rank($b),
            fn (Permit $a, Permit $b) => ($b->issued_at?->timestamp ?? 0) <=> ($a->issued_at?->timestamp ?? 0),
        ])->values();
    }

    /**
     * One timeline, newest first, each entry saying exactly what it is about:
     * which office's permit, its number, the business, the status it left and
     * the one it reached, why, and who — with their office.
     *
     * ── Whose history an office reads [client, 20 October 2026] ───────────
     *
     * "Zoning status ng Zoning Clearance lang, CHO status ng Sanitary Permit
     * lang …" — a clearance office reads the history of its OWN certificate
     * only. BPLO and the super admin read everything, the account's and each
     * business's status besides, and the screen gives them a filter by office.
     *
     * Each permit's issue is on the timeline too, so an office whose
     * certificate never changed status still sees when it was issued rather
     * than an empty box. Permit events are worded as the Permits page's
     * Status history words them (PermitController::describeHistory).
     *
     * @param  Collection<int, Business>  $businesses
     * @param  Collection<int, Permit>  $permits
     * @return list<array<string, mixed>>
     */
    private function history(User $owner, User $reader, Collection $businesses, Collection $permits): array
    {
        $everyOffice = ApplicationVisibility::readsEveryOffice($reader);
        $names = $businesses->pluck('name', 'id');

        if (! $everyOffice) {
            $permits = $permits->filter(fn (Permit $p) => $reader->department_id !== null
                && $p->permitType?->issuing_department_id === $reader->department_id)->values();
        }

        $base = fn (int $id, ?string $at, string $scope) => [
            'id' => $id,
            'at' => $at,
            'scope' => $scope,
            'office_code' => null,
            'permit_type' => null,
            'permit_number' => null,
            'business_name' => null,
            'label' => null,
            'from' => null,
            'from_label' => null,
            'to' => null,
            'to_label' => null,
            'reason' => null,
            'by' => null,
            'by_office' => null,
        ];
        $actor = fn (AuditLog $log) => [
            'by' => $log->user ? $this->nameOf($log->user) : null,
            'by_office' => $this->officeOf($log->user),
        ];

        $events = collect();

        if ($everyOffice) {
            $events = $events->concat(AuditLog::query()
                ->where('auditable_type', User::class)
                ->where('auditable_id', $owner->id)
                ->whereIn('action', ['owner.blacklisted', 'owner.blacklist_lifted'])
                ->with('user.department:id,code')
                ->get()
                ->map(fn (AuditLog $log) => array_merge($base($log->id, $log->created_at?->toIso8601String(), 'account'), [
                    'label' => $log->action === 'owner.blacklisted' ? 'Owner blacklisted' : 'Owner reinstated',
                    'from' => $log->action === 'owner.blacklisted' ? 'active' : 'blacklisted',
                    'from_label' => $log->action === 'owner.blacklisted' ? 'Active' : 'Blacklisted',
                    'to' => $log->action === 'owner.blacklisted' ? 'blacklisted' : 'active',
                    'to_label' => $log->action === 'owner.blacklisted' ? 'Blacklisted' : 'Active',
                    'reason' => $log->changes['reason'] ?? null,
                ], $actor($log))));

            $events = $events->concat(AuditLog::query()
                ->where('auditable_type', Business::class)
                ->whereIn('auditable_id', $businesses->pluck('id'))
                ->where('action', 'business.status_changed')
                ->with('user.department:id,code')
                ->get()
                ->map(fn (AuditLog $log) => array_merge($base($log->id, $log->created_at?->toIso8601String(), 'business'), [
                    'business_name' => $names[$log->auditable_id] ?? null,
                    'label' => 'Business status changed',
                    'from' => $log->changes['from'] ?? null,
                    'from_label' => self::BUSINESS_LABELS[$log->changes['from'] ?? ''] ?? null,
                    'to' => $log->changes['to'] ?? null,
                    'to_label' => self::BUSINESS_LABELS[$log->changes['to'] ?? ''] ?? null,
                    'reason' => $log->changes['reason'] ?? null,
                ], $actor($log))));
        }

        $byId = $permits->keyBy('id');
        $about = fn (Permit $p) => [
            'office_code' => $p->permitType?->department?->code,
            'permit_type' => $p->permitType?->name,
            'permit_number' => $p->permit_number,
            'business_name' => $names[$p->business_id] ?? null,
        ];

        $events = $events->concat(AuditLog::query()
            ->where('auditable_type', Permit::class)
            ->whereIn('auditable_id', $permits->pluck('id'))
            ->whereIn('action', [
                'permit.status_changed', 'permit.revoked', 'permit.suspended', 'permit.suspended_on_rejection',
                'permit.reinstated', 'permit.suspension_lifted', 'permit.expired', 'permit.revocation_undone',
            ])
            ->with('user.department')
            ->get()
            ->map(function (AuditLog $log) use ($base, $about, $byId) {
                $told = PermitController::describeHistory($log);
                $from = $told['from'] ?? null;

                return array_merge($base($log->id, $told['at'], 'permit'), $about($byId[$log->auditable_id]), [
                    'label' => $told['label'],
                    'from' => $from,
                    'from_label' => $from ? PermitStatus::tryFrom($from)?->label() : null,
                    'to' => $told['to'],
                    'to_label' => $told['to_label'],
                    'reason' => $told['reason'],
                    'by' => $told['by'],
                    // Nobody's office on an automatic event, as nobody's name.
                    'by_office' => $told['by'] ? $this->officeOf($log->user) : null,
                ]);
            }));

        // Each certificate's issue, so the history starts where the permit did.
        $events = $events->concat($permits->filter(fn (Permit $p) => $p->issued_at !== null)
            ->map(fn (Permit $p) => array_merge($base(-$p->id, $p->issued_at->toIso8601String(), 'permit'), $about($p), [
                'label' => 'Issued',
                'to' => PermitStatus::Active->value,
                'to_label' => PermitStatus::Active->label(),
                'by' => $p->issuedBy ? $this->nameOf($p->issuedBy) : null,
                'by_office' => $this->officeOf($p->issuedBy),
            ])));

        $events = $events->sortByDesc(fn (array $e) => [$e['at'] ?? '', $e['id']])->values();

        if ($everyOffice) {
            $events->push(array_merge($base(0, $owner->created_at?->toIso8601String(), 'account'), [
                'label' => 'Account created',
            ]));
        }

        return $events->all();
    }

    /**
     * The office an actor speaks for: their department's code, or "Super
     * Admin" for the seat that administers accounts and belongs to none.
     */
    private function officeOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $user->loadMissing('department:id,code');

        return $user->department?->code
            ?? ($user->hasPermission('user.manage') ? 'Super Admin' : null);
    }

    private function nameOf(User $user): string
    {
        return $user->fullName() ?: (string) $user->name;
    }
}

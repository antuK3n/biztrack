<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Permit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whether a permit may be named on a renewal at all.
 *
 * ── What was missing ────────────────────────────────────────────────────────
 *
 * The picker (`BusinessController::prefill`) offers only Active and Expired
 * permits, and that was the whole of the rule. The API took any permit the
 * business owned, so a revoked one, or one a paid renewal had already
 * superseded, could be renewed by any caller that was not the picker — a
 * stale tab is enough. Each went on to be billed, paid and issued, and the
 * business ended up holding a fresh Active permit it had no right to, or two
 * Active permits for the same term (scenario run, owner-renew 18 and 19).
 *
 * Nor was anything stopping two renewals of ONE permit at once. Both were
 * billed, both paid, and both minted a permit for the same term — ₱12,850
 * collected for one year (owner-renew 20). Ken's decision, 5 October 2026: a
 * permit with a renewal in progress can't be renewed again, and the picker
 * shows it greyed out as `Renewal in progress` (`inProgress` below feeds it).
 *
 * ── Why it lives here ───────────────────────────────────────────────────────
 *
 * Three doors set or carry a renewal's permits — `ApplicationController` on
 * create and on submit, and `PriorPermitController` when the entry dialog's
 * answer is changed on a draft — the same reason `RenewalScope` stands on its
 * own. Asked at submit as well as at create because a draft can wait for
 * weeks, and the permit it named can be revoked or renewed in the meantime.
 *
 * The SUSPENDED permit is not asked about. A business holding one cannot file
 * anything at all (`Business::isBlockedFromApplying`), and that gate runs
 * first on every one of these doors.
 */
final class RenewablePermit
{
    /**
     * A renewal somebody is still working: submitted, and not yet closed.
     *
     * A draft is not one. It is an intention the city has not been handed —
     * the same line `ScanPermits::permitsWithRenewalFiled` draws — so two
     * drafts of one permit may coexist, and the first to be submitted is the
     * one that counts. Paid but unfinished filings ARE in progress: a
     * business-permit renewal carrying a sanitary permit is still renewing
     * that sanitary permit while CHO works it.
     */
    public const IN_PROGRESS = [
        ApplicationStatus::ForApproval,
        ApplicationStatus::Returned,
        ApplicationStatus::PendingPayment,
        ApplicationStatus::AwaitingOtherPermits,
        ApplicationStatus::ForFinalApproval,
    ];

    /**
     * Why these permits may not be renewed, or null.
     *
     * `$exceptApplicationId` is the filing asking, so it does not count as
     * its own rival once it has been submitted.
     */
    public static function refusal(array $permitIds, ?int $exceptApplicationId = null): ?string
    {
        $ids = self::clean($permitIds);
        if ($ids === []) {
            return null;
        }

        $permits = Permit::whereIn('id', $ids)->withExists('renewals')->get(['id', 'status']);

        if ($permits->contains('status', PermitStatus::Revoked)) {
            return 'This permit was revoked, so it can’t be renewed.';
        }

        if ($permits->contains(fn (Permit $p) => self::alreadyRenewed($p))) {
            return 'This permit has already been renewed, so it can’t be renewed again.';
        }

        if (self::inProgress($ids, $exceptApplicationId) !== []) {
            return 'This permit already has a renewal in progress.';
        }

        return null;
    }

    /**
     * Has a renewal already replaced this permit?
     *
     * Superseded says so for a permit renewed while still in force. One
     * renewed AFTER it lapsed keeps `Expired` — its term ran out on its own,
     * and the status says that truthfully (see PermitStatus::Superseded) — so
     * the status alone let the same lapsed certificate be renewed again, and
     * the picker kept offering it (Ken, 5 October 2026). The renewal chain
     * answers for both: a permit some later permit names as its prior has
     * been renewed. Expects `renewals_exists` (`withExists('renewals')`).
     */
    public static function alreadyRenewed(Permit $permit): bool
    {
        return $permit->status === PermitStatus::Superseded || (bool) $permit->renewals_exists;
    }

    /**
     * Which of these permits a renewal in progress already names.
     *
     * @return list<int>
     */
    public static function inProgress(array $permitIds, ?int $exceptApplicationId = null): array
    {
        $ids = self::clean($permitIds);
        if ($ids === []) {
            return [];
        }

        $open = self::renewalsNaming($ids, self::IN_PROGRESS)
            ->when($exceptApplicationId !== null, fn (Builder $q) => $q->whereKeyNot($exceptApplicationId))
            ->pluck('id');

        if ($open->isEmpty()) {
            return [];
        }

        $named = Application::whereKey($open)->whereIn('prior_permit_id', $ids)->pluck('prior_permit_id')
            ->merge(
                DB::table('application_prior_permits')
                    ->whereIn('application_id', $open)
                    ->whereIn('permit_id', $ids)
                    ->pluck('permit_id')
            );

        return $named->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Renewals in one of `$statuses` that name any of these permits.
     *
     * Both places a renewal names a permit are read. The primary
     * (`prior_permit_id`) keys the renewal chain; the set
     * (`application_prior_permits`) holds the rest of the ticks, and a filing
     * written before the set existed has only the primary.
     *
     * @param  list<int>  $permitIds
     * @param  list<ApplicationStatus>  $statuses
     */
    public static function renewalsNaming(array $permitIds, array $statuses): Builder
    {
        return Application::query()
            ->where('application_type', ApplicationType::Renewal->value)
            ->whereIn('status', array_map(fn (ApplicationStatus $s) => $s->value, $statuses))
            ->where(fn (Builder $q) => $q
                ->whereIn('prior_permit_id', $permitIds)
                ->orWhereHas('priorPermits', fn (Builder $p) => $p->whereIn('permits.id', $permitIds)));
    }

    /** @return list<int> */
    private static function clean(array $permitIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $permitIds))));
    }
}

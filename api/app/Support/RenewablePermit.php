<?php

namespace App\Support;

use App\Enums\PermitStatus;
use App\Models\Permit;

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
    /** Why these permits may not be renewed, or null. */
    public static function refusal(array $permitIds): ?string
    {
        $ids = self::clean($permitIds);
        if ($ids === []) {
            return null;
        }

        $statuses = Permit::whereIn('id', $ids)->pluck('status');

        if ($statuses->contains(PermitStatus::Revoked)) {
            return 'This permit was revoked, so it can’t be renewed.';
        }

        if ($statuses->contains(PermitStatus::Superseded)) {
            return 'This permit has already been renewed, so it can’t be renewed again.';
        }

        return null;
    }

    /** @return list<int> */
    private static function clean(array $permitIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $permitIds))));
    }
}

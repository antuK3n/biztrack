<?php

namespace App\Support;

/**
 * How many permits one renewal may carry: one.
 *
 * ── The rule ────────────────────────────────────────────────────────────────
 *
 * Every permit is renewed on its own application — the Mayor's / Business
 * Permit included (Ken, 5 October 2026).
 *
 * The client's rule of 3 October 2026 had already made the other permits one
 * per filing: they are independent (six permits on six expiry dates, the
 * client's rule of 9 September), and CHO's Sanitary Permit and BFP's FSIC have
 * no step, no fee and no office in common. The business permit renewal was
 * left able to carry whatever else was due alongside it, so a January filer's
 * Sanitary fee would not move a year later. Ken removed that exception: a
 * clearance renewed on its own defers its fee to the next renewal season, as
 * it does any other month, and a bundle meant that revoking one carried permit
 * mid-renewal left a filing half about a permit that no longer existed
 * (scenario run, owner-renew 47).
 *
 * ── Why it lives here ───────────────────────────────────────────────────────
 *
 * Two endpoints can set a renewal's permits — `ApplicationController` on store
 * and update, and `PriorPermitController` when the entry dialog's answer is
 * changed on an existing draft. A rule enforced in one of them is a rule a
 * caller can walk around by using the other, and the browser is not the only
 * caller this API has.
 */
final class RenewalScope
{
    /**
     * The reason this set of prior permits may not share one filing, or null.
     *
     * Null for zero or one permit: the paper-permit filing that names none at
     * all is a business permit renewal with nothing to count.
     */
    public static function refusal(array $priorPermitIds): ?string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $priorPermitIds))));

        if (count($ids) <= 1) {
            return null;
        }

        return 'Each permit is renewed on its own application. Choose one here and file the next separately.';
    }
}

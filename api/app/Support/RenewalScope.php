<?php

namespace App\Support;

use App\Models\Permit;
use App\Models\PermitType;

/**
 * How many permits one renewal may carry.
 *
 * ── The rule ────────────────────────────────────────────────────────────────
 *
 * A renewal carries the Mayor's / Business Permit, with whatever else is due
 * alongside it — or exactly ONE other permit, on its own.
 *
 * ── Why those two shapes and not one ────────────────────────────────────────
 *
 * Client, 3 October 2026: *"since all permits are independent of each other
 * (can be renewed in different applications), do you recommend the picking at
 * the start to allow only one renewal application?"*
 *
 * They are independent — the client's own rule of 9 September: the six permits
 * expire on six different dates and a renewal is of whichever are actually due.
 * So a standalone renewal of two clearances at once was the one filing shape
 * that grew a wizard section per permit, and it is the shape this rule removes:
 * CHO's Sanitary Permit and BFP's FSIC have no step, no fee and no office in
 * common, and putting them on one form only ever meant one applicant filling in
 * two unrelated sheets before either office saw anything.
 *
 * The business permit renewal is deliberately NOT narrowed, and the reason is
 * money rather than tidiness. A clearance-only renewal defers its fee to the
 * next January (`Application::defersPayment`), so forcing a January filer to
 * split "renew my Mayor's Permit and my expiring Sanitary Permit" into two
 * would move the sanitary fee a year later than the city collects it today.
 * Bundling also costs no sections there: `officeSteps` returns none when the
 * business permit is on the filing, because those clearances are applied for at
 * the clearance stage after payment, exactly as on a new filing.
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
     * Null for zero or one permit without asking the database anything: the
     * rule is about a SECOND permit, and the paper-permit filing that names
     * none at all is a business permit renewal with nothing to count.
     */
    public static function refusal(array $priorPermitIds): ?string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $priorPermitIds))));

        if (count($ids) <= 1) {
            return null;
        }

        $carriesBusinessPermit = Permit::whereIn('id', $ids)
            ->whereHas('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
            ->exists();

        if ($carriesBusinessPermit) {
            return null;
        }

        /*
         * Names the way out, not just the refusal. An applicant who ticked two
         * clearances wants both renewed; the answer is two filings, and saying
         * so is the difference between a rule and a dead end.
         */
        return 'Each of the other permits is renewed on its own application, because each one is '
            .'read by its own office. Choose one here and file the next separately — or tick your '
            .'Mayor’s / Business Permit, which may carry the others with it.';
    }
}

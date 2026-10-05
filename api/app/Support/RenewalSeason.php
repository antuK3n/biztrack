<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * When the business permit year ends, and when renewing it stops being free.
 *
 * ── 31 December, and the twenty days after it ──────────────────────────────
 *
 * Ken, 5 October 2026, from the Malabon Revenue Code, Ch. III art. A (e): the
 * Mayor's / Business Permit *"expires on the thirty-first (31st) of December
 * following date of issuance … renewed within the first twenty (20) days of
 * January"*. So the term ends on 31 December of the year the permit is issued,
 * new filing or renewal alike, and 1 to 20 January is the window in which it
 * is renewed WITHOUT penalty. The Sec. 8A.04/8A.05 surcharge and interest
 * start after 20 January (`penaltyFreeUntil`).
 *
 * ── What it overrides ──────────────────────────────────────────────────────
 *
 * From 17 September to 5 October 2026 the term ended on 20 January of the
 * year after issue. The client chose that on 1 October, put to them with the
 * consequence that a 31 December expiry leaves the holder uncovered for the
 * twenty days of January in which the LGU accepts renewals; they kept 20
 * January for the business permit and took 31 December for the five
 * clearances. Ken's decision of 5 October overrides that for the business
 * permit, on the ordinance's own words: the permit expires on 31 December and
 * the twenty days are a grace period for renewing, not more term. Migration
 * 2026_10_05_100000 moved the permits issued under the old rule.
 *
 * The twenty days were already in the code from the ordinance rather than
 * from a preference: `ApplicationController` validates the payment mode
 * against "Sec. 2N: annual (first 20 days of January) or quarterly".
 *
 * There is a floor on filing: renewal opens on 1 January after the term ends
 * (`RenewalWindow`, the client's 3 October rule).
 */
final class RenewalSeason
{
    /** The last day of the penalty-free renewal window: 20 January. */
    public const CLOSES_MONTH = 1;

    public const CLOSES_DAY = 20;

    /**
     * When a CLEARANCE first issued on `$from` expires: 31 December of that
     * year — and, since 5 October 2026, the business permit too.
     *
     * *"Sa mga permit, ang expiration ay always end of a year, so laging
     * December 31, 202X, depende kung anong year na ngayon"* [client, 1
     * October 2026]. It replaced continue-the-term — `validFrom + 365` — for
     * the five clearances, at the cost that a clearance issued in November
     * runs about seven weeks. (A renewed clearance runs a year from its
     * renewal instead; see `WorkflowService::issuePermitFor`.)
     *
     * No branches: "the end of the year it was issued in" has no boundary
     * case to get wrong.
     */
    public static function endOfCalendarYearFor(CarbonImmutable $from): CarbonImmutable
    {
        return $from->setDate($from->year, 12, 31)->startOfDay();
    }

    /**
     * When a business permit issued on `$from` expires: 31 December of the
     * year it is issued (Ken, 5 October 2026; see the class note).
     *
     *     issued Jun 2026  → expires 31 Dec 2026   (about 7 months)
     *     issued Dec 2026  → expires 31 Dec 2026   (days)
     *     renewed Jan 2027 → expires 31 Dec 2027   (about 12 months)
     *
     * A short first term is intended, not a defect: a business registering
     * late in the year renews with everybody else in January, which is the
     * point of a common season. A renewal cannot be filed before 1 January
     * (`RenewalWindow`), so a renewed permit always gets its whole year.
     *
     * It returned 20 January of the FOLLOWING year until 5 October 2026.
     */
    public static function endOfTermFor(CarbonImmutable $from): CarbonImmutable
    {
        return self::endOfCalendarYearFor($from);
    }

    /**
     * The last day a business permit expiring on `$expiry` may be renewed
     * without the Sec. 8A.04/8A.05 penalty: 20 January after a 31 December
     * expiry.
     *
     * Any other expiry date is its own last free day, as it was for every
     * permit before 5 October 2026. That is a permit the season never set —
     * one recorded from paper with whatever date it carried — and a term the
     * ordinance did not give it earns no grace period after it.
     */
    public static function penaltyFreeUntil(CarbonImmutable $expiry): CarbonImmutable
    {
        if ($expiry->month === 12 && $expiry->day === 31) {
            return CarbonImmutable::create($expiry->year + 1, self::CLOSES_MONTH, self::CLOSES_DAY)->startOfDay();
        }

        return $expiry->startOfDay();
    }
}

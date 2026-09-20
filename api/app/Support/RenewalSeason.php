<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * When the business permit year ends — Ordinance Sec. 2N.
 *
 * ── Why 20 January, and why it is not a new number ────────────────────────
 *
 * The renewal window is the first twenty days of January. That is already
 * stated twice in this codebase, from the ordinance rather than from a
 * preference: `ApplicationController` validates the payment mode against
 * "Sec. 2N: annual (first 20 days of January) or quarterly", and
 * `ChatbotResponder` has been telling applicants "Business permits are renewed
 * during the first 20 days of January every year" since it was written.
 *
 * So a business permit expiring ON the 20th means renewing inside the window
 * keeps cover continuous: the old permit is good through the last day the LGU
 * accepts a renewal, and the new term begins the moment it ends.
 *
 * ── What this changes, and what it deliberately does not ──────────────────
 *
 * The client's decision of 17 September 2026: *"Business permits always expire
 * on January, regardless of application date."* New filings and renewals
 * alike — so a permit issued in June 2026 expires 20 January 2027, a
 * seven-month first term, and one issued in December 2026 expires the same day
 * after one month.
 *
 * This REVERSES a decision of 9 September, which `WorkflowService::issuePermitFor`
 * records: the calendar-year convention was put to the client then and was not
 * chosen, in favour of "a renewal continues the term". It is reversed for the
 * BUSINESS permit only. The other five keep continue-the-term, because they
 * renew any time and anchoring them would punish renewing early — which is the
 * reasoning the 9 September note gives and which still holds for them.
 *
 * There is NO LOCK on filing outside January, and that is explicit: *"don't add
 * a lock in our system yet for this."* A renewal filed in June is accepted and
 * gets a permit expiring on the next 20 January.
 */
final class RenewalSeason
{
    /** The last day of the renewal window, and so the day a term ends. */
    public const CLOSES_MONTH = 1;

    public const CLOSES_DAY = 20;

    /**
     * When a business permit issued on `$from` expires: 20 January of the
     * FOLLOWING year, always.
     *
     * ── It has no branches, and the first attempt at it did ──────────────────
     *
     * This was written as "the close of the term `$from` falls in" — on or
     * before 20 January takes that January, otherwise the next one — which
     * looks right and is wrong at the one date that matters most. A permit
     * issued on 5 January would have expired on the 20th of the same month:
     * fifteen days of cover, in the middle of the renewal season, for a
     * business that had just paid for a year. Caught by printing the boundary
     * cases rather than by reading the code.
     *
     * The client's own worked examples are the specification and they have no
     * boundary in them:
     *
     *     issued Jun 2026  → expires 20 Jan 2027   (about 7 months)
     *     issued Dec 2026  → expires 20 Jan 2027   (about 1 month)
     *     renewed Jan 2027 → expires 20 Jan 2028   (about 12 months)
     *
     * All three are "20 January of the year after the issue year", and so is
     * every other date: for anything from 21 January to 31 December the next
     * 20 January IS in the following year, and for the first twenty days of
     * January the applicant is buying the year ahead because their current
     * permit already covers them to the 20th.
     *
     * A short first term is intended, not a defect. A business registering in
     * December gets about a month and then renews with everybody else, which is
     * the point of a common season — every permit in the city falls due at once
     * and the LGU's January is the one time it has to process them.
     */
    public static function endOfTermFor(CarbonImmutable $from): CarbonImmutable
    {
        return CarbonImmutable::create(
            $from->year + 1,
            self::CLOSES_MONTH,
            self::CLOSES_DAY,
        )->startOfDay();
    }
}

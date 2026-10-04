<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The date renewal deadlines and late penalties are judged against.
 *
 * Normally the real date. While the Debug page's pretend date is set
 * (SystemSwitches::pretendDate), that date instead, so a defense in October
 * can show a renewal filed late in January — the surcharge, the interest
 * months, the "expired" countdown — without anybody waiting for January
 * [Ken, 2026-10-04].
 *
 * ── Only these, never the clock ───────────────────────────────────────────
 *
 * The global clock is NOT moved. Timestamps, audit rows, payments, the
 * nightly permit scan and its reminders all keep the real time. Only the
 * code that decides a renewal deadline or lateness asks this class:
 *
 *   - WorkflowService::latePenaltyFor   whether a renewal is late, and by how
 *                                       many months (surcharge and interest)
 *   - RenewalWindow::refusalFor         whether a clearance is too early or too
 *                                       late to renew
 *   - Permit::daysUntilExpiry           the countdown on every permit screen
 *                                       and the renewal picker
 *   - ClearanceStanding::forApplication which held clearances count as
 *                                       expired on a renewal
 *
 * A new rule about renewal timing belongs on that list; anything else that
 * asks this class is a bug.
 *
 * Deliberately not on it: the term a permit is issued for
 * (RenewalSeason::endOfTermFor from its real issue date). Issuing is a real
 * act with a real date on the certificate, and a term computed from a pretend
 * year would outlive the demo on a real permit.
 *
 * What is decided while the date is set is frozen like any other decision: a
 * surcharge assessed at submission stays on the bill after the pretend date
 * is cleared, because bills are not re-derived.
 */
final class BusinessDate
{
    public static function pretending(): bool
    {
        return SystemSwitches::pretendDate() !== null;
    }

    /** Today, for renewal deadlines: the pretend date, or the real one (app clock). */
    public static function today(): CarbonImmutable
    {
        return SystemSwitches::pretendDate() ?? CarbonImmutable::today();
    }

    /**
     * When a filing counts as made, for lateness: the pretend date at the
     * real time of day while one is set, the real instant otherwise.
     */
    public static function filedAt(CarbonInterface|string|null $real): CarbonImmutable
    {
        $instant = $real === null ? CarbonImmutable::now() : CarbonImmutable::parse($real);
        $pretend = SystemSwitches::pretendDate();

        return $pretend === null
            ? $instant
            : $pretend->setTime($instant->hour, $instant->minute, $instant->second);
    }
}

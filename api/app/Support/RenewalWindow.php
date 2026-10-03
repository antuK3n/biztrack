<?php

namespace App\Support;

use App\Models\Permit;
use App\Models\PermitType;
use Carbon\CarbonImmutable;

/**
 * When a clearance may be renewed — how early, and how late.
 *
 * ── There was no rule, and nobody had chosen that ──────────────────────────
 *
 * Searched 1 October 2026: nothing in this codebase bounded a renewal at
 * either end. `ScanPermits::THRESHOLDS` sends reminders 30, 15, 7 and 1 days
 * before expiry, which is a notification schedule and not a policy;
 * `BusinessController::renewablePermits` offers EVERY active and expired
 * permit with no date filter at all. So an applicant could renew a sanitary
 * permit eleven months early, or one that lapsed in 2022, and the system took
 * both without comment. That was a default nobody picked, not a decision.
 *
 * ── Both bounds are OFF until the LGU answers ──────────────────────────────
 *
 * The client has not yet asked Malabon what the window is, so switching a
 * limit on now would refuse filings the city currently accepts, on a number we
 * invented. Both settings default to null, which is exactly today's behaviour,
 * and turning either on is one value in `config/biztrack.php` — no code
 * change, no migration, no redeploy of logic.
 *
 * Written now rather than when the answer arrives because the answer is a
 * number and this is the part that takes thought: which permits it binds,
 * where it is enforced, and what the applicant is told.
 *
 * ── It never binds the business permit ─────────────────────────────────────
 *
 * The Mayor's Permit is anchored to 20 January by `RenewalSeason` and the
 * client was explicit that it carries NO filing lock: *"don't add a lock in
 * our system yet for this."* A renewal filed in June is accepted and runs to
 * the next 20 January. So this class governs the five clearances, whose terms
 * are their own rolling year, and leaves the business permit alone. Putting
 * both under one window would quietly reverse a decision that was made on
 * purpose.
 */
final class RenewalWindow
{
    /**
     * How many days before expiry a clearance may be renewed.
     *
     * Null means no limit, which is today's behaviour. A number refuses an
     * application filed earlier than that.
     */
    public static function opensDaysBefore(): ?int
    {
        $value = config('biztrack.renewal_window.opens_days_before');

        return $value === null ? null : (int) $value;
    }

    /**
     * How many months after expiry a lapsed clearance may still be RENEWED
     * rather than applied for afresh.
     *
     * Null means for ever, which is today's behaviour. This is the bound worth
     * asking BPLO about first: with the late surcharge now live, a permit
     * renewed five years on bills the Sec. 8A.05 interest cap, and whether the
     * city wants that as a renewal at all — rather than a new application with
     * its own inspection — is a policy question with money attached.
     */
    public static function closesMonthsAfter(): ?int
    {
        $value = config('biztrack.renewal_window.closes_months_after');

        return $value === null ? null : (int) $value;
    }

    /**
     * Why this permit may not be renewed today, or null if it may.
     *
     * Returns the sentence the applicant reads, because the two refusals are
     * not the same news: too early is "come back on this date", which is a
     * wait, and too late is "file a new application", which is a different
     * form. A single "outside the renewal window" would leave both of them
     * guessing which.
     */
    public static function refusalFor(Permit $prior, ?CarbonImmutable $filedAt = null): ?string
    {
        // The business permit is governed by the season, not by a window.
        if ($prior->permitType?->code === PermitType::OUTCOME_CODE) {
            return null;
        }

        if ($prior->valid_until === null) {
            return null;
        }

        // Today is BusinessDate's: the Debug page's pretend date when one is set.
        $filed = ($filedAt ?? BusinessDate::filedAt(null))->startOfDay();
        $expires = CarbonImmutable::parse($prior->valid_until)->endOfDay();

        $opens = self::opensDaysBefore();
        if ($opens !== null) {
            $opensOn = $expires->subDays($opens)->startOfDay();
            if ($filed->lessThan($opensOn)) {
                return sprintf(
                    'This %s runs to %s and can be renewed from %s. Come back then.',
                    $prior->permitType?->name ?? 'permit',
                    $expires->format('j F Y'),
                    $opensOn->format('j F Y'),
                );
            }
        }

        $closes = self::closesMonthsAfter();
        if ($closes !== null) {
            $closesOn = $expires->addMonths($closes)->endOfDay();
            if ($filed->greaterThan($closesOn)) {
                return sprintf(
                    'This %s expired on %s, more than %d months ago, so it can no longer be renewed. File a New Application instead.',
                    $prior->permitType?->name ?? 'permit',
                    $expires->format('j F Y'),
                    $closes,
                );
            }
        }

        return null;
    }
}

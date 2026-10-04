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
 * ── The business permit has its OWN window: January ────────────────────────
 *
 * It had none. The Mayor's Permit is anchored to 20 January by
 * `RenewalSeason`, and the client was explicit on 1 October 2026 that this
 * carried no filing lock — *"don't add a lock in our system yet for this"* —
 * so a renewal filed in June was accepted and ran to the next 20 January.
 *
 * They reversed that on 3 October: *"make the Mayor's Permit renewable for
 * JANUARY ONLY. Make it January 1 to 20 and 21 onwards will cause an
 * additional charge to the payment."*
 *
 * So the business permit is bounded here too, by a DATE rather than by a
 * count of days: renewal opens on 1 January of the year the term ends, and
 * a filing before that is refused and told when to come back. The five
 * clearances keep the rolling day-count window above, because their terms
 * are their own year and not the city's season.
 *
 * ── The 21 January charge needs nothing here ───────────────────────────────
 *
 * It already works, and it is worth saying where so nobody adds it twice.
 * `WorkflowService::latePenaltyFor` compares the filing date with the prior
 * permit's `valid_until`, which for a Mayor's Permit IS 20 January, and
 * hands the difference to `FeeCalculator::latePenalty`: 25% once under Sec.
 * 8A.04, plus 2% a month under 8A.05, capped at 36 months. A filing on 21
 * January is one month late by Sec. 8A.05's "month or fraction thereof" and
 * is charged accordingly.
 *
 * This class therefore sets the FLOOR only. The ceiling stays
 * `closes_months_after`, so a lapsed business permit is still renewable —
 * with the surcharge — for 36 months, which is where the interest cap makes
 * renewal stop deterring anything.
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
        if ($prior->valid_until === null) {
            return null;
        }

        // Today is BusinessDate's: the Debug page's pretend date when one is set.
        $filed = ($filedAt ?? BusinessDate::filedAt(null))->startOfDay();
        $expires = CarbonImmutable::parse($prior->valid_until)->endOfDay();

        /*
         * ── The business permit's season ────────────────────────────────
         *
         * Its term always ends on 20 January (`RenewalSeason`), so the
         * January it belongs to is the January of `valid_until` — derived
         * from the permit rather than from today, which is what makes this
         * answer the same whoever asks and whenever.
         *
         * Only the floor. Past 20 January the filing is accepted and
         * surcharged by `WorkflowService::latePenaltyFor`; the ceiling is
         * `closes_months_after` below, which this falls through to.
         */
        if ($prior->permitType?->code === PermitType::OUTCOME_CODE) {
            $opensOn = CarbonImmutable::create($expires->year, 1, 1)->startOfDay();

            if ($filed->lessThan($opensOn)) {
                return sprintf('Renewable from %s.', $opensOn->format('j F Y'));
            }

            $closes = self::closesMonthsAfter();
            if ($closes !== null && $filed->greaterThan($expires->addMonths($closes)->endOfDay())) {
                return sprintf(
                    'Expired %s, over %d months ago. File a New Application.',
                    $expires->format('j F Y'),
                    $closes,
                );
            }

            return null;
        }

        $opens = self::opensDaysBefore();
        if ($opens !== null) {
            $opensOn = $expires->subDays($opens)->startOfDay();
            if ($filed->lessThan($opensOn)) {
                return sprintf('Renewable from %s.', $opensOn->format('j F Y'));
            }
        }

        $closes = self::closesMonthsAfter();
        if ($closes !== null) {
            $closesOn = $expires->addMonths($closes)->endOfDay();
            if ($filed->greaterThan($closesOn)) {
                return sprintf(
                    'Expired %s, over %d months ago. File a New Application.',
                    $expires->format('j F Y'),
                    $closes,
                );
            }
        }

        return null;
    }
}

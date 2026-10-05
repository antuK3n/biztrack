<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Application;
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
 * It had none. The Mayor's Permit is anchored to the year's end by
 * `RenewalSeason` (20 January until 5 October 2026, 31 December since), and
 * the client was explicit on 1 October 2026 that this carried no filing lock
 * — *"don't add a lock in our system yet for this"* — so a renewal filed in
 * June was accepted.
 *
 * They reversed that on 3 October: *"make the Mayor's Permit renewable for
 * JANUARY ONLY. Make it January 1 to 20 and 21 onwards will cause an
 * additional charge to the payment."*
 *
 * So the business permit is bounded here too, by a DATE rather than by a
 * count of days: renewal opens on the 1 January after the term ends on 31
 * December, and a filing before that is refused and told when to come back. The five
 * clearances keep the rolling day-count window above, because their terms
 * are their own year and not the city's season.
 *
 * ── The 21 January charge needs nothing here ───────────────────────────────
 *
 * It is worth saying where it lives so nobody adds it twice.
 * `WorkflowService::latePenaltyFor` compares the filing date with the end of
 * the penalty-free window — 20 January after the 31 December expiry
 * (`RenewalSeason::penaltyFreeUntil`) — and hands the difference to
 * `FeeCalculator::latePenalty`: 25% once under Sec. 8A.04, plus 2% a month
 * under 8A.05, capped at 36 months. A filing on 21 January is one month late
 * by Sec. 8A.05's "month or fraction thereof" and is charged accordingly.
 *
 * This class therefore sets the FLOOR only. The ceiling stays
 * `closes_months_after`, counted from that same 20 January, so a lapsed
 * business permit is still renewable — with the surcharge — for 36 months,
 * which is where the interest cap makes renewal stop deterring anything.
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
     *
     * Asks `claimRefusal` first. `$for` is the filing asking, so a submitted
     * renewal is never refused for being the renewal already filed.
     */
    public static function refusalFor(Permit $prior, ?CarbonImmutable $filedAt = null, ?Application $for = null): ?string
    {
        // Whether this is still the permit to renew comes before WHEN it may
        // be renewed: a replaced permit is not "renewable from 1 January".
        if ($refusal = self::claimRefusal($prior, $for)) {
            return $refusal;
        }

        if ($prior->valid_until === null) {
            return null;
        }

        // Today is BusinessDate's: the Debug page's pretend date when one is set.
        $filed = ($filedAt ?? BusinessDate::filedAt(null))->startOfDay();
        $expires = CarbonImmutable::parse($prior->valid_until)->endOfDay();

        /*
         * ── The business permit's season ────────────────────────────────
         *
         * Its term ends on 31 December (`RenewalSeason`), so the January it
         * belongs to is the one that follows: the January of the day after
         * `valid_until`. Derived from the permit rather than from today,
         * which is what makes this answer the same whoever asks and whenever.
         * (A permit still dated 20 January, from before 5 October 2026, gets
         * that same January, as it did then.)
         *
         * Only the floor. Past 20 January the filing is accepted and
         * surcharged by `WorkflowService::latePenaltyFor`; the ceiling is
         * `closes_months_after`, counted from that 20 January as the
         * surcharge's months are.
         */
        if ($prior->permitType?->code === PermitType::OUTCOME_CODE) {
            $opensOn = CarbonImmutable::create($expires->addDay()->year, 1, 1)->startOfDay();

            if ($filed->lessThan($opensOn)) {
                return sprintf('Renewable from %s.', $opensOn->format('j F Y'));
            }

            $closes = self::closesMonthsAfter();
            $lateFrom = RenewalSeason::penaltyFreeUntil($expires)->endOfDay();
            if ($closes !== null && $filed->greaterThan($lateFrom->addMonths($closes))) {
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

    /**
     * Why this permit is not the one to renew any more, or null if it is.
     *
     * ── Two holes browser testing found (5 October 2026) ─────────────────
     *
     * Nothing asked whether the permit named was still the business's
     * CURRENT one, or whether somebody had already filed to renew it. So:
     *
     *  - a permit already replaced by a renewal could be renewed again. Only
     *    an early renewal marks its predecessor `superseded`; one renewed
     *    after it lapsed stays `expired`, and the picker offers every expired
     *    permit — so last year's certificate was offered beside this year's;
     *  - the same permit could be renewed twice at once, two filings in
     *    flight each promising to replace it, each billed for the year.
     *
     * A successor is any permit whose `prior_permit_id` names this one, or
     * this one's status saying it was superseded: the chain is written at
     * issuance by `Permit::booted`, so it is the register's own answer.
     *
     * "Already filed" counts a SUBMITTED filing that is not yet decided. A
     * draft does not count: drafts autosave and are abandoned all the time,
     * and a forgotten draft holding a permit hostage would be a refusal the
     * applicant cannot see the cause of. The filing asking (`$for`) is never
     * counted against itself.
     *
     * Asked by the picker (`PermitResource::renewal_blocked_reason`), at
     * create, when the prior permit is set, and again at submit — the same
     * sentence at every door.
     */
    public static function claimRefusal(Permit $prior, ?Application $for = null): ?string
    {
        if ($prior->status === PermitStatus::Superseded || $prior->renewals()->exists()) {
            return 'Already replaced by a newer permit.';
        }

        $filed = Application::query()
            ->where('application_type', ApplicationType::Renewal->value)
            ->where('status', '!=', ApplicationStatus::Draft->value)
            ->notDecided()
            ->when($for?->id !== null, fn ($q) => $q->whereKeyNot($for->id))
            ->where(fn ($q) => $q
                ->where('prior_permit_id', $prior->id)
                ->orWhereHas('priorPermits', fn ($p) => $p->whereKey($prior->id)))
            ->first(['id', 'tracking_id']);

        if ($filed !== null) {
            // Every submitted filing carries a tracking ID; the fallback is
            // for a row written around submit(), so the refusal still stands.
            return sprintf('A renewal of this permit is already filed (%s).', $filed->tracking_id ?? '#'.$filed->id);
        }

        return null;
    }
}

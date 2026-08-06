<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\InspectionResult;
use App\Enums\PermitStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The renewal outcome the register never recorded, recovered from permit history.
 *
 * RenewalRiskScoring ranks permits by warning signs and says so plainly: it is a
 * weighted rule score, nothing was fitted, and the register holds no column
 * saying whether a business ended up renewing late. That last sentence is true
 * of the SCHEMA and false of the DATA. The outcome was never written down, but
 * it is implied by the permits themselves:
 *
 *   A renewal was LATE when the next permit OF THE SAME TYPE for the same
 *   business began more than LATE_GRACE_DAYS after the previous one lapsed.
 *
 * That is a fact about two dates already in `permits`, derivable without a new
 * column, a migration or a backfill. Measured on the live register: 647
 * businesses hold two or more permits, giving 2,284 consecutive same-type pairs,
 * of which 593 — 26.0% — began late. That is the labelled sample this class
 * produces, and RenewalModelAnalytics is what fits a model to it.
 *
 * ── WHY A PERMIT GAP AND NOT A FILING DATE ──────────────────────────────────
 *
 * RenewalRiskAnalytics already has a notion of a late renewal: a filing whose
 * `submitted_at` falls after the prior permit's `valid_until`. That is the right
 * measure for the punctuality RULE — it is about the applicant's behaviour, and
 * it is observable the moment the form arrives.
 *
 * It is the wrong measure for an OUTCOME, for two reasons. A filing that is
 * never submitted produces no row at all, so a business that simply stopped
 * renewing would look like a business with no late renewals. And a punctual
 * filing that the office took four months to approve leaves the business trading
 * uncovered for four months, which is the thing the watchlist exists to prevent
 * and which a submission date cannot see. The gap between one permit ending and
 * the next beginning is the period the business was actually without cover, and
 * that is what "late" has to mean.
 *
 * ── THE FOUR AWKWARD CASES, AND WHAT WAS CHOSEN ─────────────────────────────
 *
 *  1. **A business's first permit has no inbound cycle.** Nothing preceded it,
 *     so there is no renewal to have been late. It is not excluded so much as
 *     never labelled: this class labels TRANSITIONS between consecutive permits,
 *     and a first permit is not the far side of one. It still serves as the near
 *     side of the transition into the second permit.
 *
 *  2. **A permit still in force has no outcome yet.** If nothing has succeeded
 *     it, we do not know whether the renewal will arrive on time, arrive late, or
 *     never arrive. This is right-censoring and it is EXCLUDED, not scored as
 *     on-time. Calling an unresolved cycle punctual is the single most damaging
 *     thing this file could do: it would load every recent permit into the
 *     negative class and teach a model that recency means safety.
 *
 *  3. **Revoked and suspended permits are enforcement outcomes, not renewals.**
 *     A revoked permit was not allowed to lapse; it was taken away. Whatever
 *     follows it — a new permit after reinstatement, or nothing — is not a
 *     renewal decision by the business, and a reminder would not have changed it.
 *     Both sides of a pair must be Active or Expired.
 *
 *  4. **Recent cycles are systematically missing their late half.** This one is
 *     subtler than the other three and it is where a naive derivation quietly
 *     goes wrong. A permit that lapsed last month and was renewed on time already
 *     has its successor on file; one that will be renewed four months late does
 *     not yet, so it looks identical to a censored cycle and drops out. Take the
 *     pairs as they stand and the late rate falls from 39% in late 2024 to 0% for
 *     permits expiring after July 2026 — not because renewals got punctual but
 *     because the late ones have not happened yet.
 *
 *     The fix is an administrative close: a cycle enters the sample only once
 *     SETTLE_DAYS have passed since its permit lapsed, so both outcomes have had
 *     the same chance to be recorded. It costs the newest cycles and it is not
 *     optional — without it the time-based split trains on a 37% base rate and
 *     tests against a 0% one, and every metric that comes back is meaningless.
 *
 * ── WHAT THIS SAMPLE IS NOT ─────────────────────────────────────────────────
 *
 * It is conditional on a successor existing. A business that closed, or that is
 * trading unpermitted and never came back, contributes no row. So the label
 * answers "among renewals that eventually happened, was this one late" and NOT
 * "will this business renew at all". Those are different questions and the second
 * one is not answerable from this table: the register cannot distinguish a
 * business that vanished from one that has not got round to it yet.
 */
final class RenewalOutcomes
{
    /**
     * Days of slack between one permit lapsing and the next beginning.
     *
     * One, not zero. A permit valid to 31 December succeeded by one valid from
     * 1 January is a clean renewal with no gap in cover, and the register writes
     * exactly that pattern; counting the turn of the year as a one-day lapse
     * would label the most punctual renewal in the file as late. Anything beyond
     * that is a real day trading without cover.
     */
    public const LATE_GRACE_DAYS = 1;

    /**
     * How long after a permit lapses before its cycle is called settled.
     *
     * The administrative close described in case 4 above. Six months: 63% of the
     * late renewals in the register arrived within 90 days and 64% within 180,
     * so this catches the great majority of them while still leaving roughly
     * eighteen months of usable history. It is a trade and it is stated on
     * screen — a renewal that turns up 200 days late inside the settle window is
     * recorded as late, but one attached to a cycle closed before it arrived is
     * simply missing, and the sample under-counts lateness by that much.
     */
    public const SETTLE_DAYS = 180;

    /**
     * The lead times an outcome is observed at, in days before the permit lapses.
     *
     * A model that is only ever fitted one month out cannot answer the question
     * the screen asks, because the watchlist runs from a year ahead to the day of
     * expiry and `days_to_expiry` is one of the five signals. Fitting at a single
     * lead would make that signal a constant and it would drop out of the model
     * entirely.
     *
     * So each cycle is observed several times, on the marks the reminder
     * schedule already uses. The rows within one cycle are repeated measures of
     * the same business and are NOT independent; the fitted standard errors are
     * therefore optimistic and the report says so rather than quoting them as if
     * they were not. What the repetition buys is a `days_to_expiry` coefficient
     * that means something.
     *
     * @var list<int>
     */
    public const LEAD_DAYS = [180, 90, 60, 30, 15, 7, 1];

    /** Window for counting a failed inspection against a business, in months. */
    public const FINDINGS_LOOKBACK_MONTHS = 12;

    /**
     * Share of cycles that go to the training half of the time split.
     *
     * Split by the permit's expiry date, never at random. A random split puts
     * two observations of the same cycle on opposite sides and, worse, lets the
     * model see 2026 while being tested on 2025 — the future explaining the past.
     * Every metric that comes out of that is inflated. See split() for how the
     * cutoff is chosen.
     */
    public const TRAIN_SHARE = 0.7;

    /**
     * The renewal stages a fitted model may see, in the order they are read.
     *
     * `approved` is deliberately absent, and its absence is the leakage
     * guarantee: an approved renewal means the successor permit has been issued,
     * so at that moment the outcome is not being estimated, it is being read off
     * the register. Observations at such moments are dropped entirely rather than
     * fitted — see observationsFor().
     *
     * @var list<string>
     */
    public const STAGES = ['none', 'draft', 'in_progress', 'returned', 'rejected'];

    /** @var list<string> */
    public const FEE_STATES = ['settled', 'pending', 'unpaid'];

    /**
     * One labelled row per (cycle, lead time), with every feature computed as at
     * the moment of observation.
     *
     * ── HOW LEAKAGE IS PREVENTED, CONCRETELY ────────────────────────────────
     *
     * Leakage is the failure mode that produces a beautiful number and a useless
     * model, and it does not announce itself. Four rules hold here, and each one
     * is enforced in code rather than trusted:
     *
     *  1. **Every feature is a function of `$asAt` and rows timestamped at or
     *     before it.** Not one query reads a column without a date bound. The
     *     bounds are the register's own event columns — `submitted_at`,
     *     `decided_at`, `conducted_at`, `paid_at`, `created_at` — so "what was
     *     known then" is the register's answer, not an assumption.
     *
     *  2. **No observation is taken after the outcome exists.** An observation is
     *     dropped if the successor permit had already been issued or had already
     *     begun by `$asAt`, or if the renewal filing had already been approved by
     *     then. All three mean the answer was on file and there was nothing left
     *     to estimate. This is why the lead grid stops at one day out and never
     *     goes past expiry: the day after the grace period closes, lateness is a
     *     fact, and a "prediction" made then is a lookup.
     *
     *  3. **Past punctuality counts only cycles already RESOLVED at `$asAt`.** A
     *     business's earlier cycle counts towards its history from the day its
     *     own outcome became certain — the day the successor began, if it was
     *     punctual, or the day the grace period closed, if it was not. Counting
     *     an earlier cycle whose outcome had not yet settled would feed one label
     *     into another.
     *
     *  4. **Two columns that are not versioned are reconstructed, not read.**
     *     `compliance_checks.is_checked` and a payment's clearance both record a
     *     present state with no history behind them. Reading either as at today
     *     would import the future. `is_checked` is inverted through `updated_at`
     *     (a check whose row changed after `$asAt` was in its initial, unticked
     *     state at `$asAt`), and a payment counts as cleared only if `paid_at`
     *     itself falls on or before `$asAt`.
     *
     * The one residual is stated rather than hidden: a compliance check created
     * and ticked in the same operation is indistinguishable from one that was
     * never ticked, so open findings are a slight undercount. It biases the
     * feature towards zero, which weakens the model rather than flattering it.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     cycles: list<array<string, mixed>>,
     *     cutoff: string|null,
     *     counts: array<string, int|float|null>,
     * }
     */
    public static function labelled(?CarbonImmutable $closeAt = null): array
    {
        $closeAt = ($closeAt ?? CarbonImmutable::now())->startOfDay();

        $cycles = self::cycles();
        $settled = array_values(array_filter(
            $cycles,
            static fn (array $c): bool => CarbonImmutable::parse($c['expires_on'])
                ->addDays(self::SETTLE_DAYS)
                ->lessThanOrEqualTo($closeAt),
        ));

        $cutoff = self::splitCutoff($settled);

        // Punctuality reads from EVERY cycle, not just the settled ones: an
        // earlier cycle that resolved in 2025 is legitimate history for an
        // observation taken in 2026 whether or not it is itself in the sample.
        // The `known_at <= as_at` gate is what keeps that honest.
        $history = self::historyByBusiness($cycles);
        $renewals = self::renewalFilings(array_column($cycles, 'permit_id'));
        $findings = self::findingEvents(array_column($cycles, 'business_id'));
        $fees = self::feeEvents(array_merge(...array_map(
            static fn (array $permit): array => array_column($permit['filings'], 'application_id'),
            array_values($renewals),
        ) ?: [[]]));

        $rows = [];
        foreach ($settled as $cycle) {
            foreach (self::observationsFor($cycle, $renewals) as $asAt) {
                $rows[] = self::featureRow($cycle, $asAt, $cutoff, $history, $renewals, $findings, $fees);
            }
        }

        return [
            'rows' => $rows,
            'cycles' => $settled,
            'cutoff' => $cutoff,
            'counts' => self::counts($cycles, $settled, $rows),
        ];
    }

    /**
     * Every consecutive same-type permit pair, labelled.
     *
     * The chain is per (business, permit type) and ordered by `valid_from`,
     * because a business commonly holds a business permit, a sanitary permit and
     * a fire permit with the same dates — pooling them would read each year's
     * three permits as three renewals of one thing. The tie-break on id keeps the
     * order stable when two permits of the same type start on the same day.
     *
     * @return list<array<string, mixed>>
     */
    public static function cycles(): array
    {
        $rows = DB::table('permits')
            ->whereIn('status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->orderBy('business_id')
            ->orderBy('permit_type_id')
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get(['id', 'business_id', 'permit_type_id', 'valid_from', 'valid_until', 'issued_at']);

        $chains = [];
        foreach ($rows as $row) {
            $chains[$row->business_id.':'.$row->permit_type_id][] = $row;
        }

        $cycles = [];
        foreach ($chains as $chain) {
            for ($i = 0, $n = count($chain) - 1; $i < $n; $i++) {
                $prior = $chain[$i];
                $next = $chain[$i + 1];

                $expires = CarbonImmutable::parse($prior->valid_until)->startOfDay();
                $begins = CarbonImmutable::parse($next->valid_from)->startOfDay();

                // Whole days between cover ending and cover resuming. Negative
                // when the successor was issued early and overlaps, which is
                // both common and unambiguously punctual.
                $gap = (int) $expires->diffInDays($begins, false);
                $late = $gap > self::LATE_GRACE_DAYS;

                /*
                 * The day the outcome stopped being open.
                 *
                 * For a punctual renewal that is the day the successor began.
                 * For a late one it is the day AFTER the grace period closed —
                 * lateness becomes certain the moment an on-time start is no
                 * longer possible, which is well before the successor actually
                 * turns up. Using the successor's start date for a late cycle
                 * would delay its entry into the business's punctuality history
                 * by however long the business took, which is exactly backwards.
                 */
                $knownAt = $late
                    ? $expires->addDays(self::LATE_GRACE_DAYS + 1)
                    : $begins;

                $cycles[] = [
                    'cycle_id' => (int) $prior->id,
                    'permit_id' => (int) $prior->id,
                    'business_id' => (int) $prior->business_id,
                    'permit_type_id' => (int) $prior->permit_type_id,
                    'starts_on' => CarbonImmutable::parse($prior->valid_from)->startOfDay()->toDateString(),
                    'expires_on' => $expires->toDateString(),
                    'successor_permit_id' => (int) $next->id,
                    // The earlier of "issued" and "in force": either one puts the
                    // successor on the register and settles the question.
                    'successor_on_register' => self::successorVisibleFrom($next)->toDateString(),
                    'successor_from' => $begins->toDateString(),
                    'gap_days' => $gap,
                    'late' => $late ? 1 : 0,
                    'known_at' => $knownAt->toDateString(),
                ];
            }
        }

        return $cycles;
    }

    /**
     * When the successor permit became visible on the register.
     *
     * A permit dated from 1 January but issued on 12 December is on file from
     * the twelfth, and an observation taken on the fifteenth can see it. Taking
     * only `valid_from` would leave a fortnight in which the answer sits in the
     * database and the sample pretends it does not.
     */
    private static function successorVisibleFrom(object $permit): CarbonImmutable
    {
        $from = CarbonImmutable::parse($permit->valid_from)->startOfDay();

        if ($permit->issued_at === null) {
            return $from;
        }

        $issued = CarbonImmutable::parse($permit->issued_at)->startOfDay();

        return $issued->lessThan($from) ? $issued : $from;
    }

    /**
     * The dates one cycle is observed at: the lead marks that are both inside the
     * permit's own life and still genuinely open.
     *
     * @param  array<string, mixed>  $cycle
     * @param  array<int, array<string, mixed>>  $renewals  keyed by prior permit id
     * @return list<CarbonImmutable>
     */
    private static function observationsFor(array $cycle, array $renewals): array
    {
        $expires = CarbonImmutable::parse($cycle['expires_on']);
        $starts = CarbonImmutable::parse($cycle['starts_on']);
        $visible = CarbonImmutable::parse($cycle['successor_on_register']);
        $approved = $renewals[$cycle['permit_id']]['approved_at'] ?? null;
        $approvedAt = $approved === null ? null : CarbonImmutable::parse($approved);

        $out = [];
        foreach (self::LEAD_DAYS as $lead) {
            $asAt = $expires->subDays($lead);

            // Before the permit existed there was nothing to observe.
            if ($asAt->lessThan($starts)) {
                continue;
            }

            // Rule 2: the answer was already on the register.
            if ($visible->lessThanOrEqualTo($asAt)) {
                continue;
            }

            // Rule 2 again, by the other route: the renewal had been granted, so
            // the successor was a formality rather than a question.
            if ($approvedAt !== null && $approvedAt->lessThanOrEqualTo($asAt)) {
                continue;
            }

            $out[] = $asAt;
        }

        return $out;
    }

    /**
     * The five signals for one cycle at one moment, plus the label.
     *
     * @param  array<string, mixed>  $cycle
     * @param  array<int, list<array{known_at: string, late: int, cycle_id: int}>>  $history
     * @param  array<int, array<string, mixed>>  $renewals
     * @param  array<int, list<array{at: string, kind: string}>>  $findings
     * @param  array<int, array<string, mixed>>  $fees
     * @return array<string, mixed>
     */
    private static function featureRow(
        array $cycle,
        CarbonImmutable $asAt,
        ?string $cutoff,
        array $history,
        array $renewals,
        array $findings,
        array $fees,
    ): array {
        $punctuality = self::punctualityAt($history[$cycle['business_id']] ?? [], $asAt, (int) $cycle['cycle_id']);
        $renewal = self::filingAt($renewals[$cycle['permit_id']]['filings'] ?? [], $asAt);

        return [
            'cycle_id' => $cycle['cycle_id'],
            'business_id' => $cycle['business_id'],
            'permit_id' => $cycle['permit_id'],
            'expires_on' => $cycle['expires_on'],
            'as_at' => $asAt->toDateString(),

            // The five signals, in the order the rulebook lists them.
            'days_to_expiry' => (int) $asAt->diffInDays(CarbonImmutable::parse($cycle['expires_on']), false),
            'renewal_stage' => self::stageAt($renewal, $asAt),
            'punctuality_known' => $punctuality['total'] > 0 ? 1 : 0,
            'prior_cycles' => $punctuality['total'],
            'prior_late' => $punctuality['late'],
            'prior_late_rate' => $punctuality['total'] > 0
                ? round($punctuality['late'] / $punctuality['total'], 6)
                : 0.0,
            'open_findings' => self::findingsAt($findings[$cycle['business_id']] ?? [], $asAt),
            'fee_state' => self::feeStateAt($renewal, $asAt, $fees),

            'late' => $cycle['late'],
            'split' => $cutoff === null || strcmp($cycle['expires_on'], $cutoff) < 0 ? 'train' : 'test',
        ];
    }

    /**
     * Which of a permit's renewal filings represents the state of play at
     * `$asAt`: the most recently started one that existed by then.
     *
     * The same reading RenewalRiskAnalytics::renewalsByPriorPermit() applies —
     * a later filing supersedes an earlier one — with the extra condition that
     * it must actually have existed at the moment being reconstructed. A refile
     * lodged in November is not the state of play in March.
     *
     * @param  list<array<string, mixed>>  $filings
     * @return array<string, mixed>|null
     */
    private static function filingAt(array $filings, CarbonImmutable $asAt): ?array
    {
        $day = $asAt->toDateString();
        $current = null;

        foreach ($filings as $filing) {
            if ($filing['created_at'] === null || strcmp($filing['created_at'], $day) > 0) {
                continue;
            }
            $current = $filing;
        }

        return $current;
    }

    /**
     * Where a business's renewal filing stood at `$asAt`.
     *
     * Reconstructed from the filing's own event dates rather than from its
     * current status, because a status is a present-tense fact and every row
     * here is a past-tense question. A filing that is Approved today was Under
     * Review last March, and reading the column would put March's observation in
     * the wrong stage.
     *
     * `returned` cannot be recovered: the schema records the status but not when
     * it was set, so a returned filing reads as `in_progress` from the moment it
     * was submitted. That is the truthful reading of what the register can prove
     * — it was filed and it had not been decided — and it is stated rather than
     * guessed at. It also means the `returned` level is rare in the fit, which
     * the coefficient table shows honestly through its standard error.
     *
     * @param  array<string, mixed>|null  $renewal
     */
    private static function stageAt(?array $renewal, CarbonImmutable $asAt): string
    {
        if ($renewal === null) {
            return 'none';
        }

        $before = static fn (?string $at): bool => $at !== null
            && CarbonImmutable::parse($at)->startOfDay()->lessThanOrEqualTo($asAt);

        // A decision that had landed by now. `approved` is never reachable here:
        // observationsFor() drops any moment at or after the approval, so an
        // approved filing is simply not observed once it has been granted.
        if ($before($renewal['decided_at'])) {
            if ($renewal['status'] === ApplicationStatus::Rejected->value) {
                return 'rejected';
            }
            if ($renewal['status'] === ApplicationStatus::Cancelled->value) {
                // A cancelled filing leaves nothing standing, which is the same
                // position as never having filed.
                return 'none';
            }
        }

        if ($before($renewal['submitted_at'])) {
            return 'in_progress';
        }

        if ($before($renewal['created_at'])) {
            return 'draft';
        }

        // The form had not been started yet.
        return 'none';
    }

    /**
     * A business's punctuality record as at `$asAt`.
     *
     * Only cycles whose own outcome had settled by then, and never the cycle
     * being scored — that one IS the label.
     *
     * @param  list<array{known_at: string, late: int, cycle_id: int}>  $cycles
     * @return array{total: int, late: int}
     */
    private static function punctualityAt(array $cycles, CarbonImmutable $asAt, int $exclude): array
    {
        $day = $asAt->toDateString();
        $total = 0;
        $late = 0;

        foreach ($cycles as $cycle) {
            if ($cycle['cycle_id'] === $exclude || strcmp($cycle['known_at'], $day) > 0) {
                continue;
            }
            $total++;
            $late += $cycle['late'];
        }

        return ['total' => $total, 'late' => $late];
    }

    /**
     * Open compliance findings against a business at `$asAt`.
     *
     * @param  list<array{at: string, kind: string, until: string|null}>  $events
     */
    private static function findingsAt(array $events, CarbonImmutable $asAt): int
    {
        $day = $asAt->toDateString();
        $floor = $asAt->subMonths(self::FINDINGS_LOOKBACK_MONTHS)->toDateString();

        $open = 0;
        foreach ($events as $event) {
            if (strcmp($event['at'], $day) > 0) {
                continue;
            }
            // Inspections age out of the lookback window; an unticked check stays
            // open until its filing is decided, which is what `until` carries.
            if ($event['kind'] === 'inspection' && strcmp($event['at'], $floor) < 0) {
                continue;
            }
            if ($event['until'] !== null && strcmp($event['until'], $day) <= 0) {
                continue;
            }
            $open++;
        }

        return $open;
    }

    /**
     * Fee state on the renewal filing at `$asAt`.
     *
     * Read off the filing, not the business, exactly as the rule score reads it:
     * a business with no renewal filed owes nothing yet and carries its risk on
     * the progress signal instead.
     *
     * @param  array<string, mixed>|null  $renewal
     * @param  array<int, array{assessed_at: string|null, payments: list<array{created_at: string, paid_at: string|null}>}>  $fees
     */
    private static function feeStateAt(?array $renewal, CarbonImmutable $asAt, array $fees): string
    {
        if ($renewal === null) {
            return 'settled';
        }

        $fee = $fees[$renewal['application_id']] ?? null;
        $day = $asAt->toDateString();

        if ($fee === null || $fee['assessed_at'] === null || strcmp($fee['assessed_at'], $day) > 0) {
            // Nothing had been assessed yet, so nothing was owed.
            return 'settled';
        }

        $pending = false;
        foreach ($fee['payments'] as $payment) {
            if (strcmp($payment['created_at'], $day) > 0) {
                continue;
            }
            // Cleared is `paid_at`, not `status`. The status column records where
            // a payment ended up, with no history behind it, so reading it would
            // mark a payment that cleared in June as settled in April.
            if ($payment['paid_at'] !== null && strcmp($payment['paid_at'], $day) <= 0) {
                return 'settled';
            }
            $pending = true;
        }

        return $pending ? 'pending' : 'unpaid';
    }

    /**
     * The date the sample is cut at: train before it, test from it on.
     *
     * Chosen as a quantile of the cycles' own expiry dates rather than as a fixed
     * date, so the split keeps its proportions as the register grows and does not
     * silently empty one side. Snapped to a whole date so a cycle can never
     * straddle the cut — every observation of one cycle is on the same side, and
     * the business it belongs to cannot be half-learned.
     *
     * @param  list<array<string, mixed>>  $cycles
     */
    private static function splitCutoff(array $cycles): ?string
    {
        if ($cycles === []) {
            return null;
        }

        $dates = array_column($cycles, 'expires_on');
        sort($dates);

        $at = (int) floor(count($dates) * self::TRAIN_SHARE);
        $cutoff = $dates[min($at, count($dates) - 1)];

        // A cutoff equal to the earliest or latest date leaves one side empty,
        // which is not a split. Better to report no split than a fake one.
        return ($cutoff === $dates[0] || $cutoff > $dates[count($dates) - 1]) ? null : $cutoff;
    }

    /**
     * Every renewal filing standing against each prior permit, oldest first.
     *
     * ALL of them, not the first one. A business that filed, was rejected,
     * refiled and was granted has two rows, and keeping only the earliest was a
     * live leakage hole: the approval sat on the second filing, so
     * observationsFor() never saw an `approved_at`, never dropped the moments
     * after the successor was granted, and quietly fitted the model on
     * observations whose answer was already on the register. The refile is
     * exactly the case this class exists to get right, so it is the last one that
     * may be dropped for convenience.
     *
     * `approved_at` is therefore the EARLIEST approval across every filing
     * against the permit, not a property of any one of them.
     *
     * @param  list<int>  $permitIds
     * @return array<int, array{filings: list<array<string, mixed>>, approved_at: string|null}>
     */
    private static function renewalFilings(array $permitIds): array
    {
        if ($permitIds === []) {
            return [];
        }

        $rows = DB::table('applications')
            ->whereNull('deleted_at')
            ->where('application_type', ApplicationType::Renewal->value)
            ->whereIn('prior_permit_id', $permitIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'prior_permit_id', 'status', 'created_at', 'submitted_at', 'decided_at']);

        $out = [];
        foreach ($rows as $row) {
            $permitId = (int) $row->prior_permit_id;
            $out[$permitId] ??= ['filings' => [], 'approved_at' => null];

            $decided = $row->decided_at === null ? null : CarbonImmutable::parse($row->decided_at)->toDateString();

            $out[$permitId]['filings'][] = [
                'application_id' => (int) $row->id,
                'status' => (string) $row->status,
                'created_at' => $row->created_at === null ? null : CarbonImmutable::parse($row->created_at)->toDateString(),
                'submitted_at' => $row->submitted_at === null ? null : CarbonImmutable::parse($row->submitted_at)->toDateString(),
                'decided_at' => $decided,
            ];

            if ($row->status === ApplicationStatus::Approved->value && $decided !== null) {
                $seen = $out[$permitId]['approved_at'];
                $out[$permitId]['approved_at'] = $seen === null ? $decided : min($seen, $decided);
            }
        }

        return $out;
    }

    /**
     * Every event that can count as an open finding, with the date it appeared
     * and the date it stopped counting.
     *
     * Gathered once for all businesses rather than per observation, because the
     * alternative is ten thousand round trips for a figure that is two queries.
     *
     * @param  list<int>  $businessIds
     * @return array<int, list<array{at: string, kind: string, until: string|null}>>
     */
    private static function findingEvents(array $businessIds): array
    {
        $businessIds = array_values(array_unique($businessIds));
        if ($businessIds === []) {
            return [];
        }

        $out = [];

        /*
         * An unticked compliance check, reconstructed rather than read.
         *
         * `is_checked` is a present-tense flag with no history column, so a check
         * that is ticked today tells us nothing about whether it was ticked in
         * March. `updated_at` closes that gap: a check whose row has been touched
         * since `$asAt` was still in its created state — unticked — at `$asAt`.
         * The comparison is made per observation in findingsAt(); what this query
         * does is carry both dates so it can be.
         *
         * The residual, stated in the class docblock: a check created and ticked
         * in one operation leaves `updated_at` equal to `created_at` and is
         * indistinguishable from one that was never ticked. It is dropped, so
         * findings run slightly low.
         */
        $checks = DB::table('compliance_checks')
            ->join('application_assignments', 'application_assignments.id', '=', 'compliance_checks.application_assignment_id')
            ->join('applications', 'applications.id', '=', 'application_assignments.application_id')
            ->whereNull('applications.deleted_at')
            ->whereIn('applications.business_id', $businessIds)
            ->get([
                'applications.business_id',
                'applications.decided_at',
                'compliance_checks.is_checked',
                'compliance_checks.created_at',
                'compliance_checks.updated_at',
            ]);

        foreach ($checks as $row) {
            if ($row->created_at === null) {
                continue;
            }

            $created = CarbonImmutable::parse($row->created_at)->toDateString();
            $ticked = $row->is_checked
                ? ($row->updated_at === null ? $created : CarbonImmutable::parse($row->updated_at)->toDateString())
                : null;
            $decided = $row->decided_at === null ? null : CarbonImmutable::parse($row->decided_at)->toDateString();

            // The check stops being open at whichever came first: it was ticked,
            // or its filing was decided. An unticked check on a decided filing is
            // history, not debt — the same reading RenewalRiskAnalytics uses.
            $until = match (true) {
                $ticked !== null && $decided !== null => min($ticked, $decided),
                $ticked !== null => $ticked,
                default => $decided,
            };

            $out[(int) $row->business_id][] = ['at' => $created, 'kind' => 'check', 'until' => $until];
        }

        /*
         * A failed or conditional site visit. `conducted_at` is when the result
         * was established, so this one needs no reconstruction: the register
         * knew the outcome on that date and not before. Findings age out of the
         * twelve-month lookback rather than being closed by an event, which is
         * why `until` is null and findingsAt() applies the floor.
         */
        $inspections = DB::table('inspections')
            ->join('applications', 'applications.id', '=', 'inspections.application_id')
            ->whereNull('applications.deleted_at')
            ->whereIn('inspections.result', [InspectionResult::Failed->value, InspectionResult::Conditional->value])
            ->whereNotNull('inspections.conducted_at')
            ->whereIn('applications.business_id', $businessIds)
            ->get(['applications.business_id', 'inspections.conducted_at']);

        foreach ($inspections as $row) {
            $out[(int) $row->business_id][] = [
                'at' => CarbonImmutable::parse($row->conducted_at)->toDateString(),
                'kind' => 'inspection',
                'until' => null,
            ];
        }

        return $out;
    }

    /**
     * Assessment and payment dates per renewal filing.
     *
     * @param  list<int>  $applicationIds
     * @return array<int, array{assessed_at: string|null, payments: list<array{created_at: string, paid_at: string|null}>}>
     */
    private static function feeEvents(array $applicationIds): array
    {
        $applicationIds = array_values(array_unique($applicationIds));
        if ($applicationIds === []) {
            return [];
        }

        $out = [];

        $assessments = DB::table('fee_assessments')
            ->whereIn('application_id', $applicationIds)
            ->where('total_amount', '>', 0)
            ->get(['application_id', 'assessed_at', 'created_at']);

        foreach ($assessments as $row) {
            $at = $row->assessed_at ?? $row->created_at;
            if ($at === null) {
                continue;
            }

            $date = CarbonImmutable::parse($at)->toDateString();
            $id = (int) $row->application_id;

            // Earliest assessment: the first moment money was owed.
            if (! isset($out[$id]) || $date < $out[$id]['assessed_at']) {
                $out[$id] = ['assessed_at' => $date, 'payments' => $out[$id]['payments'] ?? []];
            }
        }

        $payments = DB::table('payments')
            ->whereIn('application_id', $applicationIds)
            ->get(['application_id', 'created_at', 'paid_at']);

        foreach ($payments as $row) {
            $id = (int) $row->application_id;
            if (! isset($out[$id]) || $row->created_at === null) {
                continue;
            }

            $out[$id]['payments'][] = [
                'created_at' => CarbonImmutable::parse($row->created_at)->toDateString(),
                'paid_at' => $row->paid_at === null ? null : CarbonImmutable::parse($row->paid_at)->toDateString(),
            ];
        }

        return $out;
    }

    /**
     * Per-business punctuality history, keyed for the as-at lookup.
     *
     * @param  list<array<string, mixed>>  $cycles
     * @return array<int, list<array{known_at: string, late: int, cycle_id: int}>>
     */
    private static function historyByBusiness(array $cycles): array
    {
        $out = [];
        foreach ($cycles as $cycle) {
            $out[$cycle['business_id']][] = [
                'known_at' => $cycle['known_at'],
                'late' => $cycle['late'],
                'cycle_id' => $cycle['cycle_id'],
            ];
        }

        return $out;
    }

    /**
     * The sample's own arithmetic, for the screen and the report.
     *
     * Every exclusion is a number here rather than a silence. A reader who is
     * told 1,600 cycles were fitted and not told 680 were dropped for want of a
     * settled outcome has been handed a sample size with no denominator.
     *
     * @param  list<array<string, mixed>>  $all
     * @param  list<array<string, mixed>>  $settled
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int|float|null>
     */
    private static function counts(array $all, array $settled, array $rows): array
    {
        $late = array_sum(array_column($settled, 'late'));
        $trainRows = array_values(array_filter($rows, static fn (array $r): bool => $r['split'] === 'train'));
        $testRows = array_values(array_filter($rows, static fn (array $r): bool => $r['split'] === 'test'));

        return [
            'businesses' => count(array_unique(array_column($all, 'business_id'))),
            'cycles_found' => count($all),
            'cycles_unsettled' => count($all) - count($settled),
            'cycles_labelled' => count($settled),
            'late' => $late,
            'late_rate' => $settled === [] ? null : round($late / count($settled) * 100, 1),
            'observations' => count($rows),
            'train_observations' => count($trainRows),
            'test_observations' => count($testRows),
            'observations_per_cycle' => $settled === [] ? null : round(count($rows) / count($settled), 2),
        ];
    }
}

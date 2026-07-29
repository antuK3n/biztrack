<?php

namespace App\Support;

/**
 * Renewal risk scoring — a transparent, weighted rule set over the register.
 *
 * READ THIS BEFORE CHANGING ANY WORDING THAT REACHES A SCREEN.
 *
 * This is NOT a statistical model. Nothing here is fitted, trained, or
 * cross-validated; there is no historical outcome variable, no likelihood, and
 * no probability. It is a checklist of five things the register knows about a
 * permit that is coming up for renewal, each worth a fixed number of points,
 * summed to a 0–100 **risk score**. The score's only claim is ordinal: a permit
 * scoring 70 has more known risk signals against it than one scoring 30, and an
 * officer's day is better spent on the first.
 *
 * It therefore must never be labelled a "probability", a "prediction", a
 * "likelihood", a "confidence", or a percentage of anything. The revised UI
 * mockup labels this column "PROB. DELAY RISK" and prints "88%"; that wording
 * asserts an inference this code does not perform and is not used. The screen
 * says "Risk score 88 / 100" and lists the rules that produced it, so an officer
 * can disagree with the score for a stated reason.
 *
 * Unlike Spc and Des, this has no counterpart in `r/R/*.R` — the R project never
 * modelled renewal risk. There was nothing to port, so the rules below are a
 * design decision, and the weights are judgement calls stated in the open rather
 * than coefficients hidden in a fit.
 *
 * THE RULES, AND WHY EACH WEIGHT IS WHAT IT IS
 *
 *  1. Time to expiry (0–30). The clock is the one signal that is never
 *     ambiguous and never disputable, so it carries the most weight. Its steps
 *     are the paper's expiration-monitoring marks — 30, 15, 7 and 1 day before
 *     expiry — so the score moves on exactly the days a reminder is due. A
 *     permit already lapsed scores the full 30: it is operating without one.
 *  2. Renewal progress (0–25). Almost as decisive, and the actionable half of
 *     the pair — an officer can do something about "nothing filed yet". A filing
 *     already approved zeroes this out.
 *  3. Past punctuality (0–20). Behavioural, and the one rule with real
 *     uncertainty: a business that filed late before may simply have had a bad
 *     year. Weighted below the two factual signals for that reason. A business
 *     in its first renewal cycle has no record either way and takes half the
 *     weight, flagged as unknown rather than clean — a first-timer is genuinely
 *     less predictable, and scoring them zero would bury them.
 *  4. Open compliance findings (0–15). A failed inspection or an unticked
 *     requirement blocks issuance even when the owner files on time.
 *  5. Unsettled fees (0–10). Lowest weight because it is usually the last and
 *     easiest step to clear, but an unpaid assessment does stop a permit.
 *
 * Bands are set so the two factual signals together (30 + 25 = 55) are enough on
 * their own to reach High: a lapsed permit with nothing filed is the case the
 * screen exists to surface, and no behavioural evidence should be needed to
 * raise it.
 */
final class RenewalRiskScoring
{
    /** Score at or above this is High Risk. */
    public const HIGH_THRESHOLD = 50;

    /** Score at or above this (and below HIGH) is Moderate Risk. */
    public const MODERATE_THRESHOLD = 25;

    /** Maximum points per rule. These sum to 100 by construction. */
    public const WEIGHTS = [
        'expiry' => 30,
        'progress' => 25,
        'punctuality' => 20,
        'findings' => 15,
        'fees' => 10,
    ];

    /**
     * Points by how close expiry is. Read as: at or below this many days
     * remaining, award this many points. A lapsed permit (negative days) takes
     * the maximum.
     *
     * The steps are the paper's expiration-monitoring marks — 30, 15, 7 and 1
     * day before expiry (docs/r-integration-spec.md §2) — so a permit's score
     * moves on exactly the days a reminder is due, rather than on a ladder
     * invented here. The two trailing steps at 60 and 90 days exist only so the
     * default 90-day watchlist window can still rank the far end of itself;
     * beyond 90 days the rule contributes nothing.
     *
     * @var list<array{0: int, 1: int}>
     */
    private const EXPIRY_BANDS = [
        [1, 30],
        [7, 25],
        [15, 18],
        [30, 10],
        [60, 4],
        [90, 2],
    ];

    /**
     * Points by how far a renewal filing has got.
     *
     * `rejected` scores the same as `none` because the business is back to
     * square one; `returned` is nearly as bad because the ball is with the
     * applicant, who may not act. `in_progress` keeps a small residue rather
     * than zero: a filing in the queue can still miss the date.
     *
     * @var array<string, int>
     */
    private const PROGRESS_POINTS = [
        'none' => 25,
        'rejected' => 25,
        'draft' => 20,
        'returned' => 18,
        'in_progress' => 5,
        'approved' => 0,
    ];

    /**
     * Renewal counts as due within this many days of expiry — the paper's first
     * expiration-monitoring mark.
     *
     * This gates the `none` case of the progress rule, and the gate matters. A
     * permit with ten months left has no renewal filed against it because none
     * is due, not because anyone is behind; charging it the full 25 points made
     * every permit in the register at least Moderate and emptied the Low band.
     * A filing that was STARTED and then abandoned, returned, or rejected still
     * scores at any distance — that is a real problem whenever it happens.
     */
    public const RENEWAL_DUE_WITHIN_DAYS = 30;

    /** Points for a business whose first renewal cycle this is — half weight. */
    private const PUNCTUALITY_UNKNOWN = 10;

    /**
     * Points by count of open compliance findings.
     *
     * @var list<array{0: int, 1: int}>
     */
    private const FINDINGS_BANDS = [
        [0, 0],
        [2, 8],
    ];

    /** @var array<string, int> */
    private const FEE_POINTS = [
        'settled' => 0,
        'pending' => 6,
        'unpaid' => 10,
    ];

    /**
     * Score one permit.
     *
     * @param  array{
     *     days_to_expiry: int,
     *     renewal_stage: string,
     *     prior_renewals: int,
     *     late_renewals: int,
     *     open_findings: int,
     *     fee_state: string
     * }  $facts
     * @return array{
     *     score: int,
     *     band: string,
     *     band_label: string,
     *     action: string,
     *     action_label: string,
     *     drivers: list<array{rule: string, label: string, points: int, max: int, detail: string}>
     * }
     */
    public static function score(array $facts): array
    {
        $drivers = [
            self::expiryDriver((int) $facts['days_to_expiry']),
            self::progressDriver((string) $facts['renewal_stage'], (int) $facts['days_to_expiry']),
            self::punctualityDriver((int) $facts['prior_renewals'], (int) $facts['late_renewals']),
            self::findingsDriver((int) $facts['open_findings']),
            self::feeDriver((string) $facts['fee_state']),
        ];

        $score = 0;
        foreach ($drivers as $driver) {
            $score += $driver['points'];
        }

        $band = match (true) {
            $score >= self::HIGH_THRESHOLD => 'high',
            $score >= self::MODERATE_THRESHOLD => 'moderate',
            default => 'low',
        };

        // The action is a direct function of the band, not a separate judgement:
        // one number, one recommended next step, no hidden second rule set.
        [$action, $actionLabel] = match ($band) {
            'high' => ['immediate_follow_up', 'Immediate follow-up'],
            'moderate' => ['send_reminder', 'Send reminder'],
            default => ['monitor', 'Monitor'],
        };

        return [
            'score' => $score,
            'band' => $band,
            'band_label' => match ($band) {
                'high' => 'High',
                'moderate' => 'Moderate',
                default => 'Low',
            },
            'action' => $action,
            'action_label' => $actionLabel,
            // Sorted heaviest first: the screen shows the top reasons, and the
            // top reasons should be the ones that moved the score.
            'drivers' => self::sortByPoints($drivers),
        ];
    }

    /**
     * The complete numeric specification of the rule set, in one place.
     *
     * This exists so the same rules can be reimplemented elsewhere — the
     * architecture in docs/r-integration-spec.md makes R the primary statistics
     * engine with this class as the fallback — and the two implementations
     * asserted to agree on shared fixtures rather than drifting apart. Any port
     * should read its constants from here (or from a serialisation of it) instead
     * of copying the literals, and an agreement test should feed both engines the
     * same fact sets and compare scores.
     *
     * Every number the score depends on is reachable from this array. If you add
     * a rule and it is not represented here, the agreement test cannot see it.
     *
     * @return array<string, mixed>
     */
    public static function parameters(): array
    {
        return [
            'weights' => self::WEIGHTS,
            'thresholds' => [
                'high' => self::HIGH_THRESHOLD,
                'moderate' => self::MODERATE_THRESHOLD,
            ],
            // [days_remaining_at_or_below, points]; a lapsed permit takes the
            // expiry weight outright.
            'expiry_bands' => self::EXPIRY_BANDS,
            'progress_points' => self::PROGRESS_POINTS,
            'renewal_due_within_days' => self::RENEWAL_DUE_WITHIN_DAYS,
            'punctuality_unknown_points' => self::PUNCTUALITY_UNKNOWN,
            // [open_findings_at_or_below, points]; anything above the last band
            // takes the findings weight outright.
            'findings_bands' => self::FINDINGS_BANDS,
            'fee_points' => self::FEE_POINTS,
        ];
    }

    /**
     * The rule book, for the "What drives the score" panel. The screen renders
     * this rather than restating the weights in copy that can drift out of sync
     * with the code.
     *
     * @return list<array{rule: string, label: string, max: int, description: string}>
     */
    public static function rulebook(): array
    {
        return [
            [
                'rule' => 'expiry',
                'label' => 'Time to expiry',
                'max' => self::WEIGHTS['expiry'],
                'description' => 'Stepped on the expiry-monitoring marks — 30, 15, 7 and 1 day out. Full '
                    .'weight once the permit has lapsed or expires tomorrow, nothing beyond 90 days out.',
            ],
            [
                'rule' => 'progress',
                'label' => 'Renewal progress',
                'max' => self::WEIGHTS['progress'],
                'description' => 'Full weight when a renewal is due within '.self::RENEWAL_DUE_WITHIN_DAYS
                    .' days and none has been filed, or when one was rejected and must be refiled. A permit not '
                    .'yet due scores nothing here, and neither does one already renewed.',
            ],
            [
                'rule' => 'punctuality',
                'label' => 'Past punctuality',
                'max' => self::WEIGHTS['punctuality'],
                'description' => 'The share of this business\'s earlier renewals filed after the old permit expired. '
                    .'A first renewal cycle has no record and takes half weight.',
            ],
            [
                'rule' => 'findings',
                'label' => 'Open compliance findings',
                'max' => self::WEIGHTS['findings'],
                'description' => 'Unticked requirements and failed or conditional inspections that would block '
                    .'issuance even on a punctual filing.',
            ],
            [
                'rule' => 'fees',
                'label' => 'Unsettled fees',
                'max' => self::WEIGHTS['fees'],
                'description' => 'An assessed fee with no completed payment against it.',
            ],
        ];
    }

    /** @return array{rule: string, label: string, points: int, max: int, detail: string} */
    private static function expiryDriver(int $daysToExpiry): array
    {
        if ($daysToExpiry < 0) {
            return self::driver('expiry', 'Time to expiry', self::WEIGHTS['expiry'],
                'Lapsed '.abs($daysToExpiry).' '.self::plural(abs($daysToExpiry), 'day').' ago');
        }

        foreach (self::EXPIRY_BANDS as [$threshold, $points]) {
            if ($daysToExpiry <= $threshold) {
                return self::driver('expiry', 'Time to expiry', $points,
                    'Expires in '.$daysToExpiry.' '.self::plural($daysToExpiry, 'day'));
            }
        }

        return self::driver('expiry', 'Time to expiry', 0,
            'Expires in '.$daysToExpiry.' days — more than 90 out');
    }

    /** @return array{rule: string, label: string, points: int, max: int, detail: string} */
    private static function progressDriver(string $stage, int $daysToExpiry): array
    {
        $known = array_key_exists($stage, self::PROGRESS_POINTS) ? $stage : 'none';
        $due = $daysToExpiry <= self::RENEWAL_DUE_WITHIN_DAYS;

        // Nothing filed and nothing due yet is not a risk signal.
        if ($known === 'none' && ! $due) {
            return self::driver('progress', 'Renewal progress', 0, 'Not yet due for renewal');
        }

        return self::driver('progress', 'Renewal progress', self::PROGRESS_POINTS[$known], match ($known) {
            'approved' => 'Renewal approved',
            'in_progress' => 'Renewal filed and in the queue',
            'draft' => 'Renewal started but never submitted',
            'returned' => 'Renewal returned to the applicant',
            'rejected' => 'Renewal rejected — must be refiled',
            default => 'No renewal filed yet',
        });
    }

    /** @return array{rule: string, label: string, points: int, max: int, detail: string} */
    private static function punctualityDriver(int $priorRenewals, int $lateRenewals): array
    {
        if ($priorRenewals < 1) {
            return self::driver('punctuality', 'Past punctuality', self::PUNCTUALITY_UNKNOWN,
                'First renewal cycle — no punctuality record either way');
        }

        $late = max(0, min($priorRenewals, $lateRenewals));
        // Half to even, matching R — 1 late of 8 is 2.5 points, which PHP's
        // default rounding would make 3 and R's would make 2. See Rounding.
        $points = (int) Rounding::statistic(($late / $priorRenewals) * self::WEIGHTS['punctuality'], 0);

        return self::driver('punctuality', 'Past punctuality', $points,
            $late === 0
                ? 'All '.$priorRenewals.' earlier '.self::plural($priorRenewals, 'renewal').' filed before expiry'
                : $late.' of '.$priorRenewals.' earlier '.self::plural($priorRenewals, 'renewal').' filed late');
    }

    /** @return array{rule: string, label: string, points: int, max: int, detail: string} */
    private static function findingsDriver(int $openFindings): array
    {
        $points = self::WEIGHTS['findings'];
        foreach (self::FINDINGS_BANDS as [$threshold, $banded]) {
            if ($openFindings <= $threshold) {
                $points = $banded;
                break;
            }
        }

        return self::driver('findings', 'Open compliance findings', $points,
            $openFindings === 0
                ? 'Nothing outstanding'
                : $openFindings.' open '.self::plural($openFindings, 'finding'));
    }

    /** @return array{rule: string, label: string, points: int, max: int, detail: string} */
    private static function feeDriver(string $state): array
    {
        $points = self::FEE_POINTS[$state] ?? 0;

        return self::driver('fees', 'Unsettled fees', $points, match ($state) {
            'unpaid' => 'Assessed fee with no payment recorded',
            'pending' => 'Payment recorded but not yet cleared',
            default => 'Fees settled',
        });
    }

    /** @return array{rule: string, label: string, points: int, max: int, detail: string} */
    private static function driver(string $rule, string $label, int $points, string $detail): array
    {
        return [
            'rule' => $rule,
            'label' => $label,
            'points' => $points,
            'max' => self::WEIGHTS[$rule],
            'detail' => $detail,
        ];
    }

    /**
     * @param  list<array{rule: string, label: string, points: int, max: int, detail: string}>  $drivers
     * @return list<array{rule: string, label: string, points: int, max: int, detail: string}>
     */
    private static function sortByPoints(array $drivers): array
    {
        // Weight breaks ties so the ordering is stable regardless of input order.
        usort($drivers, static fn (array $a, array $b) => [$b['points'], $b['max']] <=> [$a['points'], $a['max']]);

        return $drivers;
    }

    private static function plural(int $count, string $word): string
    {
        return $count === 1 ? $word : $word.'s';
    }
}

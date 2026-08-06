<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Renewal Risk, fitted.
 *
 * RenewalRiskAnalytics ranks permits by a weighted rule score. The weights were
 * chosen, nothing was fitted, and the screen says so. That was the honest thing
 * to say while the register held no outcome to fit against — and it stopped
 * being the whole truth the moment RenewalOutcomes showed the outcome was
 * derivable from permit history all along.
 *
 * This class gathers what a fitted model needs and hands it to R:
 *
 *  - the labelled observations RenewalOutcomes recovers, with the five signals
 *    computed as at each observation moment,
 *  - the permits on the watchlist right now, carrying the same five signals as
 *    at today AND the rule score for each, so both numbers reach the screen from
 *    one round trip and cannot end up describing different permits,
 *  - the split date, the label definition and the honesty statement.
 *
 * R fits `glm(family = binomial)` on the older half, evaluates on the newer half
 * and returns coefficients, AUC, a Brier score and a calibration reading. See
 * r/R/renewal_model.R.
 *
 * ── WHY THIS IS A SEPARATE DATASET AND NOT A FEW MORE KEYS ─────────────────
 *
 * Adding the fitted figure to the renewal-risk payload was the shorter route and
 * it is the wrong one, for three reasons that all point the same way.
 *
 *  1. **It would break the parity contract on the first run.** AnalyticsParityTest
 *     walks the renewal-risk payload against R's golden output key for key in
 *     BOTH directions. A new key there fails immediately, and the fix would be to
 *     loosen the check that exists to catch exactly this.
 *  2. **PHP cannot be the fallback for a fit.** Every other dataset has a PHP
 *     port that computes the same statistics when R is down. There is no
 *     twenty-line PHP port of iteratively reweighted least squares that anyone
 *     should maintain, and a fallback that silently reported the rule score under
 *     a "probability" heading would be the single most dishonest thing this
 *     feature could do. So the fallback here says the model is unavailable, and
 *     that is the whole of its job — see build().
 *  3. **The two numbers are different KINDS of claim.** The rule score is a
 *     transparent ranking that never needed evidence. The fitted figure is a
 *     claim about the world that is worthless without its metrics attached. They
 *     travel together on screen and they are kept apart in the payload, because
 *     a reader has to be able to tell which is which.
 *
 * ── THE CLAIM THIS DATASET IS ALLOWED TO MAKE, AND THE ONE IT IS NOT ───────
 *
 * AnalyticsDefinitionsTest bans the words probability, probable, likelihood,
 * predict, forecast and confidence from the renewal-risk definitions. That ban
 * is not lifted and must not be: it guards the RULE SCORE, which is still not a
 * probability and still has nothing fitted behind it.
 *
 * This dataset's figure is a different object. It is fitted to recorded
 * outcomes, evaluated on a period it never saw, and reported with the
 * calibration that says how far to trust it — so it may be called a probability,
 * and it is called one only here, only of this number, and only alongside those
 * metrics. The test now enforces both halves rather than one.
 *
 * ── AND THE THING THAT OUTRANKS ALL OF IT ──────────────────────────────────
 *
 * Almost every outcome fitted below was written by AnalyticsHistorySeeder. A
 * model fitted on it learns the seeder's renewal behaviour, and it will report a
 * perfectly respectable AUC while doing so. TRAINING_DATA_NOTICE is that
 * sentence; it travels on the payload, it is rendered on the screen above the
 * figures rather than inside a tooltip, and nothing here is allowed to be read
 * without it.
 */
final class RenewalModelAnalytics
{
    /** The R endpoint that fits this dataset. */
    public const R_ENDPOINT = '/renewal-model';

    /** How far ahead the estimated permits are drawn from, in days. */
    public const DEFAULT_HORIZON_DAYS = RenewalRiskAnalytics::DEFAULT_HORIZON_DAYS;

    /** Permits shown in the fitted table. */
    public const DEFAULT_LIMIT = 25;

    /**
     * Fewest training observations R will fit on.
     *
     * Below this the coefficients are noise wearing a standard error, and the
     * screen is better served by "not enough history yet" than by six figures
     * that will move on the next refresh. It is checked in R rather than here so
     * the refusal is the engine's, made against the rows it actually received.
     */
    public const MINIMUM_OBSERVATIONS = 100;

    /**
     * Fewest training rows a factor level needs before it gets its own
     * coefficient.
     *
     * Four draft renewals, all of which went the same way, produced an estimate
     * of -14.17 with a standard error of 378 — a coefficient that is not small
     * or large but undefined, and one an officer would have been invited to read
     * as a finding. Thin levels are folded into the reference and named in
     * `dropped` instead. See .rm_fold_rare() in r/R/renewal_model.R.
     */
    public const MINIMUM_LEVEL_OBSERVATIONS = 25;

    /** Deciles, so each calibration bin holds enough rows to mean something. */
    public const CALIBRATION_BINS = 10;

    /**
     * The sentence that outranks every figure on this screen.
     *
     * Rendered verbatim, above the metrics, never paraphrased and never folded
     * into a tooltip. The client asked for a predictive model; what a model
     * fitted here can honestly claim is bounded by what it was fitted ON, and
     * this register's renewal history was generated by the analytics seeder. The
     * method is real, the pipeline is real, the metrics are computed properly —
     * and the coefficients describe a simulation. Saying so is not a disclaimer,
     * it is the finding.
     */
    public const TRAINING_DATA_NOTICE = 'This model is trained on demonstration data. The renewal history in '
        .'this register was generated for testing, so the figures below measure how well the method works on '
        .'that generated history — not how businesses in Malabon behave. The coefficients and the accuracy '
        .'figures will change once real renewal history is loaded, and they should not be quoted as findings '
        .'about the city until it is.';

    /**
     * How the fitted figure is arrived at, in the reader's terms.
     *
     * Kept server-side for the reason RenewalRiskAnalytics::METHODOLOGY is: an
     * export that shipped the numbers without the method would be worse than one
     * that shipped neither.
     */
    public const METHODOLOGY = 'A renewal is counted as late when the next permit of the same type began more '
        .'than a day after the previous one lapsed — a fact recovered from permit dates, not a field anyone '
        .'filled in. Every one of those past cycles is measured at several points before its permit expired, '
        .'using only what the register knew at that point, and a logistic regression is fitted to the older '
        .'cycles and tested against the newer ones it never saw. The figure it returns is the estimated '
        .'probability that a renewal begins late, for permits that have not yet been renewed. The rule score '
        .'beside it is unchanged and is not a probability.';

    /**
     * What the estimate does and does not cover, shown beside the table.
     */
    public const ESTIMATE_NOTE = 'An estimate is only shown where there is still something to estimate. A permit '
        .'that has already lapsed is late — that is a fact, not a figure — and a permit whose renewal has been '
        .'approved has nothing left to wait for. Both are listed with the reason in place of a number.';

    /**
     * The rows R fits on, the permits it applies the fit to, and the rules.
     *
     * @return array<string, mixed>
     */
    public static function dataset(
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $now = CarbonImmutable::now();
        $labelled = RenewalOutcomes::labelled($now);

        return [
            'params' => ['days' => $horizonDays, 'limit' => $limit],
            'now' => $now->toISOString(),
            'minimum_observations' => self::MINIMUM_OBSERVATIONS,
            'minimum_level_observations' => self::MINIMUM_LEVEL_OBSERVATIONS,
            'calibration_bins' => self::CALIBRATION_BINS,
            'estimate_limit' => $limit,

            'label' => [
                'definition' => 'The next permit of the same type began more than '
                    .RenewalOutcomes::LATE_GRACE_DAYS.' day after the previous one lapsed.',
                'grace_days' => RenewalOutcomes::LATE_GRACE_DAYS,
                'settle_days' => RenewalOutcomes::SETTLE_DAYS,
                'lead_days' => RenewalOutcomes::LEAD_DAYS,
                // The factor levels R pins its contrasts to. Sent rather than
                // inferred so a refresh in which nobody filed a draft cannot
                // silently renumber every coefficient — see .rm_frame().
                'stages' => RenewalOutcomes::STAGES,
                'fee_states' => RenewalOutcomes::FEE_STATES,
            ],

            'split' => [
                'cutoff' => $labelled['cutoff'],
                'train_share' => RenewalOutcomes::TRAIN_SHARE,
                'basis' => 'the expiry date of the permit being renewed',
            ],

            'counts' => $labelled['counts'],
            'rows' => $labelled['rows'],
            'current' => self::currentPermits($horizonDays),

            'training_data' => [
                'synthetic' => true,
                'notice' => self::TRAINING_DATA_NOTICE,
            ],
            'estimate_note' => self::ESTIMATE_NOTE,
            'methodology' => self::METHODOLOGY,
        ];
    }

    /**
     * The permits on the watchlist now, as the model's features plus the rule
     * score.
     *
     * Drawn from RenewalRiskAnalytics::dataset() rather than from a second query
     * of this file's own, and that is the point rather than a shortcut. The five
     * signals the model was fitted on and the five the rule score is computed
     * from have to be the SAME five facts about the same permit at the same
     * moment, or the two numbers the screen puts side by side are answering
     * different questions and the comparison the client asked for is a
     * comparison of nothing. One query, one set of facts, two readings of them.
     *
     * The one thing that is translated is the punctuality pair: the watchlist
     * carries a count of earlier renewals and how many were late, and the model
     * was fitted on the share plus a flag for having any record at all. The
     * arithmetic is done here so R receives the same column names from both
     * sources.
     *
     * @return list<array<string, mixed>>
     */
    private static function currentPermits(int $horizonDays): array
    {
        $rows = [];

        foreach (RenewalRiskAnalytics::dataset($horizonDays)['permits'] as $permit) {
            $facts = [
                'days_to_expiry' => (int) $permit['days_to_expiry'],
                'renewal_stage' => (string) $permit['renewal_stage'],
                'prior_renewals' => (int) $permit['prior_renewals'],
                'late_renewals' => (int) $permit['late_renewals'],
                'open_findings' => (int) $permit['open_findings'],
                'fee_state' => (string) $permit['fee_state'],
            ];

            $scored = RenewalRiskScoring::score($facts);
            $prior = max(0, $facts['prior_renewals']);
            $late = max(0, min($prior, $facts['late_renewals']));

            $rows[] = [
                'permit_id' => (int) $permit['permit_id'],
                'business' => (string) $permit['business'],
                'permit_type' => (string) $permit['permit_type'],
                'barangay' => $permit['barangay'],
                'valid_until' => (string) $permit['valid_until'],

                'days_to_expiry' => $facts['days_to_expiry'],
                'renewal_stage' => $facts['renewal_stage'],
                'punctuality_known' => $prior > 0 ? 1 : 0,
                'prior_late_rate' => $prior > 0 ? round($late / $prior, 6) : 0.0,
                'open_findings' => $facts['open_findings'],
                'fee_state' => $facts['fee_state'],

                'rule_score' => $scored['score'],
                'rule_band' => $scored['band'],
                'rule_band_label' => $scored['band_label'],
            ];
        }

        return $rows;
    }

    /**
     * The local answer, which is "no model".
     *
     * This is the only dataset in the registry whose PHP side does not port the
     * statistics, and the reason is stated in the class docblock: fitting a
     * generalised linear model twice, in two languages, so the second copy can
     * disagree with the first is not a fallback, it is a second engine to keep
     * honest. There is nothing to port and pretending otherwise would produce
     * exactly the drift AnalyticsParityTest exists to prevent.
     *
     * What it must do instead is return the SAME KEYS. RenewalModelKeyParityTest
     * checks that in both directions against R's live output, because the screen
     * reads one shape and a missing key is a blank panel with no explanation
     * while a null is a sentence. `available => false` with a reason is a state a
     * reader can act on ("the statistics service is not running"); a rule score
     * relabelled as a probability would not be, and is the one outcome this
     * method exists to make impossible.
     *
     * @return array<string, mixed>
     */
    public static function build(
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        return self::unavailable(
            'r_did_not_fit',
            RenewalOutcomes::labelled()['counts'],
            CarbonImmutable::now()->toISOString(),
        );
    }

    /**
     * The empty shape, key for key with what R returns fitted.
     *
     * @param  array<string, int|float|null>  $counts
     * @return array<string, mixed>
     */
    public static function unavailable(string $reason, array $counts, string $generatedAt): array
    {
        return [
            'available' => false,
            'unavailable_reason' => $reason,
            'generated_at' => $generatedAt,
            'engine' => 'glm(family = binomial)',

            'label' => [
                'definition' => 'The next permit of the same type began more than '
                    .RenewalOutcomes::LATE_GRACE_DAYS.' day after the previous one lapsed.',
                'grace_days' => RenewalOutcomes::LATE_GRACE_DAYS,
                'settle_days' => RenewalOutcomes::SETTLE_DAYS,
                'lead_days' => RenewalOutcomes::LEAD_DAYS,
            ],
            'split' => [
                'cutoff' => null,
                'basis' => 'the expiry date of the permit being renewed',
                'train_from' => null,
                'train_to' => null,
                'test_from' => null,
                'test_to' => null,
                'random' => false,
            ],
            'training' => ['cycles' => 0, 'observations' => 0, 'late' => 0, 'late_rate' => null],
            'evaluation' => ['cycles' => 0, 'observations' => 0, 'late' => 0, 'late_rate' => null],
            'counts' => self::countsShape($counts),

            'coefficients' => [],
            'dropped' => [],
            'metrics' => [
                'auc' => null,
                'brier' => null,
                'baseline_brier' => null,
                'skill_score' => null,
                'calibration_intercept' => null,
                'calibration_slope' => null,
                'calibrated' => false,
                'observations' => 0,
                'unfitted_levels' => 0,
            ],
            'horizon_auc' => [],
            'calibration' => [],
            'calibration_statement' => '',
            'estimates' => [],
            'estimate_note' => self::ESTIMATE_NOTE,

            'training_data' => [
                'synthetic' => true,
                'notice' => self::TRAINING_DATA_NOTICE,
            ],
            'methodology' => self::METHODOLOGY,
        ];
    }

    /**
     * The counts block, in the order and with the keys R echoes back.
     *
     * @param  array<string, int|float|null>  $counts
     * @return array<string, int|float|null>
     */
    private static function countsShape(array $counts): array
    {
        return [
            'businesses' => (int) ($counts['businesses'] ?? 0),
            'cycles_found' => (int) ($counts['cycles_found'] ?? 0),
            'cycles_unsettled' => (int) ($counts['cycles_unsettled'] ?? 0),
            'cycles_labelled' => (int) ($counts['cycles_labelled'] ?? 0),
            'late' => (int) ($counts['late'] ?? 0),
            'late_rate' => (float) ($counts['late_rate'] ?? 0),
            'observations' => (int) ($counts['observations'] ?? 0),
            'train_observations' => (int) ($counts['train_observations'] ?? 0),
            'test_observations' => (int) ($counts['test_observations'] ?? 0),
        ];
    }
}

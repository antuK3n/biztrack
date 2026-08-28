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
 * This class gathers what a fitted model needs and then fits it:
 *
 *  - the labelled observations RenewalOutcomes recovers, with the five signals
 *    computed as at each observation moment,
 *  - the permits on the watchlist right now, carrying the same five signals as
 *    at today AND the rule score for each, so both numbers reach the screen from
 *    one pass over the data and cannot end up describing different permits,
 *  - the split date, the label definition and the honesty statement.
 *
 * A binomial logistic regression is fitted on the older half, evaluated on the
 * newer half, and reported with coefficients, AUC, a Brier score and a
 * calibration reading. The arithmetic is in Glm; the domain judgement — which
 * terms may enter, what a coefficient means in a sentence, when a permit has
 * nothing left to estimate — is here.
 *
 * ── WHY THE FIT IS IN PHP ───────────────────────────────────────────────────
 *
 * It did not used to be. This dataset was assembled here and posted to a
 * separate statistics service, which fitted the model and posted the numbers
 * back; the PHP side deliberately returned no statistics at all, on the argument
 * that a second implementation of a generalised linear model would be a second
 * engine to keep honest rather than a fallback.
 *
 * That service has been removed from the project. The argument went with it: the
 * choice is no longer "one engine or two" but "this fit or no fit", and no fit
 * means a client-facing screen that permanently reports its own absence. So the
 * fit was ported. There is now exactly one implementation, it is the one below,
 * and it is checked against a frozen input/output pair captured from the
 * service it replaces — RenewalModelFitTest reproduces that golden payload from
 * that golden input, coefficient by coefficient and bin by bin. The port is not
 * asserted to be faithful; it is demonstrated to be, on a real dataset, by a
 * test that needs nothing running.
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
 * metrics. `metrics.calibrated` is the gate on the word: when it is false the
 * screen stops calling the figure a probability and calls it a ranking, which is
 * what an uncalibrated score is.
 *
 * ── AND THE THING THAT OUTRANKS ALL OF IT ──────────────────────────────────
 *
 * Almost every outcome fitted below was written by AnalyticsHistorySeeder. A
 * model fitted on it learns the seeder's renewal behaviour, and it will report a
 * perfectly respectable AUC while doing so. TRAINING_DATA_NOTICE is that
 * sentence; it travels on the payload, it is rendered on the screen above the
 * figures rather than inside a tooltip, and nothing here is allowed to be read
 * without it. Porting the fit changed which process computes the numbers. It
 * changed nothing whatever about what they are evidence of.
 */
final class RenewalModelAnalytics
{
    /** How far ahead the estimated permits are drawn from, in days. */
    public const DEFAULT_HORIZON_DAYS = RenewalRiskAnalytics::DEFAULT_HORIZON_DAYS;

    /** Permits shown in the fitted table. */
    public const DEFAULT_LIMIT = 25;

    /**
     * Fewest training observations this model will fit on.
     *
     * Below this the coefficients are noise wearing a standard error, and the
     * screen is better served by "not enough history yet" than by six figures
     * that will move on the next refresh. Refusing is a result, and compute()
     * returns it as one — with a reason that describes the data.
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
     * `dropped` instead. See foldRare().
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
     * The model's name, as the screen and the PDF print it.
     *
     * This read `glm(family = binomial)` for as long as a separate statistics
     * runtime fitted the model, and it was kept through the port on the argument
     * that the MODEL had not changed — only the process running it — so a caption
     * that moved would have told the reader something happened to the statistics.
     *
     * That argument does not survive contact with the string itself.
     * `glm(family = binomial)` is not neutral notation for a binomial GLM; it is
     * the literal call signature of one particular language's one particular
     * function, down to the `family =` argument name. It is rendered verbatim on
     * the screen (RenewalModelPanel prints `report.engine` under the heading), so
     * a reader who recognises it reads a provenance — "this was fitted by R" —
     * that stopped being true when that runtime was removed. A caption that names
     * a tool nobody runs is a small lie of exactly the kind the rest of this file
     * is arranged to prevent, and it is worse than a caption that moved: it is
     * one the reader cannot detect.
     *
     * So it names the method rather than a vendor's spelling of it. The method is
     * the honest and checkable part — Glm::binomial fits a logistic regression by
     * iteratively reweighted least squares, which is what `glm(family = binomial)`
     * also did, and RenewalModelFitTest demonstrates the two agree to every
     * published decimal place on a frozen dataset. The arithmetic is unchanged and
     * the golden fixture proves it; only the claim about who performs it moved,
     * and it moved because it had become false.
     */
    private const ENGINE = 'logistic regression, fitted by IRLS';

    /**
     * The five signals, in the order they enter the formula.
     *
     * Order is load-bearing twice over: it fixes the row order of the
     * coefficient table a reader scans, and it fixes which label wins when
     * prettyLabel() matches a dummy column name by prefix.
     *
     * @return list<array{term: string, label: string, kind: string}>
     */
    private static function specification(): array
    {
        return [
            ['term' => 'time_remaining', 'label' => 'Time to expiry', 'kind' => 'numeric'],
            ['term' => 'renewal_stage', 'label' => 'Renewal progress', 'kind' => 'factor'],
            ['term' => 'punctuality_known', 'label' => 'Has a punctuality record', 'kind' => 'numeric'],
            ['term' => 'prior_late_rate', 'label' => 'Share of earlier renewals late', 'kind' => 'numeric'],
            ['term' => 'open_findings', 'label' => 'Open compliance findings', 'kind' => 'numeric'],
            ['term' => 'fee_state', 'label' => 'Unsettled fees', 'kind' => 'factor'],
        ];
    }

    /**
     * The rows the model fits on, the permits it applies the fit to, and the
     * rules.
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
                // The factor levels the contrasts are pinned to. Declared rather
                // than inferred so a refresh in which nobody filed a draft cannot
                // silently renumber every coefficient — see frame().
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
     * arithmetic is done here so both sources produce the same column names.
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
     * @return array<string, mixed>
     */
    public static function build(
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        return self::compute(self::dataset($horizonDays, $limit));
    }

    /**
     * Fit the model and report it: dataset in, screen payload out.
     *
     * Pure, and that is a property worth defending rather than a side effect of
     * the refactor. No clock, no database, no network. The train/test split
     * arrives as a date dataset() computed, the fit is deterministic, and the
     * same JSON in always produces the same coefficients out — which is what
     * makes the golden-fixture test possible at all, and what lets anyone check
     * a coefficient a licensing officer disputes without reproducing a database.
     *
     * @param  array<string, mixed>  $dataset  as returned by dataset()
     * @return array<string, mixed>
     */
    public static function compute(array $dataset): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $dataset['rows'] ?? [];
        /** @var list<array<string, mixed>> $current */
        $current = $dataset['current'] ?? [];

        $cutoff = $dataset['split']['cutoff'] ?? null;
        $minimumObservations = (int) ($dataset['minimum_observations'] ?? self::MINIMUM_OBSERVATIONS);
        $minimumLevel = (int) ($dataset['minimum_level_observations'] ?? self::MINIMUM_LEVEL_OBSERVATIONS);
        $bins = (int) ($dataset['calibration_bins'] ?? self::CALIBRATION_BINS);
        $limit = max(1, (int) ($dataset['estimate_limit'] ?? self::DEFAULT_LIMIT));

        if ($rows === [] || ! is_string($cutoff)) {
            return self::unavailable($dataset, 'no_labelled_history');
        }

        $frame = self::frame($rows);
        $train = array_values(array_filter($frame, static fn (array $r): bool => $r['split'] === 'train'));
        $test = array_values(array_filter($frame, static fn (array $r): bool => $r['split'] === 'test'));

        // Rare levels go before anything is fitted. See foldRare().
        [$train, $levels, $foldedOut] = self::foldRare($train, $dataset, $minimumLevel);

        /*
         * Refusing to fit is a result. A model fitted on eighty rows, or on a
         * training period in which nothing was ever late, would still return
         * coefficients and a flattering AUC; saying "not enough history" is the
         * honest output and the screen renders it as such. Each reason names the
         * shortfall in the data, because that is the thing a reader can act on.
         */
        if (count($train) < $minimumObservations || count(array_unique(array_column($train, 'late'))) < 2) {
            return self::unavailable($dataset, 'not_enough_training_history');
        }

        if ($test === [] || count(array_unique(array_column($test, 'late'))) < 2) {
            return self::unavailable($dataset, 'not_enough_evaluation_history');
        }

        $terms = self::usableTerms($train, $levels);

        if ($terms['keep'] === []) {
            return self::unavailable($dataset, 'no_signal_varies');
        }

        /*
         * Test rows are forced onto the levels the fit actually saw. A level that
         * only appears after the cutoff has no coefficient, so a prediction for
         * it would be undefined for the whole row; folding it onto the reference
         * level gives the reader the model's honest answer for "a permit like
         * this one, minus a stage we have never fitted" and the count of rows it
         * happened to is reported as metrics.unfitted_levels.
         */
        [$test, $unfitted] = self::align($test, $terms['keep']);

        $design = Glm::design($train, $terms['design']);
        $outcomes = array_map(static fn (array $r): int => $r['late'], $train);

        $fit = Glm::binomial($design['matrix'], $outcomes);

        if ($fit === null) {
            /*
             * The design survived folding and the constant-term check and still
             * has no finite maximum: either two signals move together exactly, or
             * one of them splits the outcome cleanly enough that the fit runs off
             * rather than settling. Both are properties of the training rows, not
             * of the arithmetic, and both mean the coefficients a reader would be
             * shown are undefined rather than large.
             */
            return self::unavailable($dataset, 'no_finite_fit_exists');
        }

        $testDesign = Glm::design($test, $terms['design']);
        $predicted = Glm::predict($testDesign['matrix'], $fit['coefficients']);
        $testOutcomes = array_map(static fn (array $r): int => $r['late'], $test);

        $auc = Glm::auc($testOutcomes, $predicted);
        $brier = Glm::brier($testOutcomes, $predicted);

        /*
         * The reference point every skill claim is made against: predicting the
         * training period's own late rate for everything, forever. A model that
         * cannot beat that has learned nothing, and the screen should be able to
         * say so.
         */
        $baseRate = array_sum($outcomes) / count($outcomes);
        $baseline = Glm::brier($testOutcomes, array_fill(0, count($test), $baseRate));
        $skill = ($baseline === null || $baseline === 0.0 || $brier === null)
            ? null
            : 1.0 - ($brier / $baseline);

        $calibration = self::calibration($testOutcomes, $predicted, $bins);

        return [
            'available' => true,
            'unavailable_reason' => null,
            'generated_at' => (string) ($dataset['now'] ?? ''),
            'engine' => self::ENGINE,

            'label' => self::labelOut($dataset),
            'split' => self::splitOut($dataset, $train, $test, $cutoff),
            'training' => self::periodOut($train),
            'evaluation' => self::periodOut($test),
            'counts' => self::countsShape($dataset['counts'] ?? []),

            'coefficients' => self::coefficientsOut($design['columns'], $fit, $terms['keep']),
            'dropped' => [...$foldedOut, ...$terms['dropped']],

            'metrics' => [
                'auc' => self::r3($auc),
                'brier' => self::r3($brier),
                'baseline_brier' => self::r3($baseline),
                'skill_score' => self::r3($skill),
                'calibration_intercept' => self::r3($calibration['intercept']),
                'calibration_slope' => self::r3($calibration['slope']),
                /*
                 * The gate on the word. A fitted figure only earns the name
                 * "probability" when it can be read as a rate, and this says
                 * whether this one currently can. When it is false the screen
                 * stops calling the figure a probability and calls it a ranking,
                 * which is what an uncalibrated score is.
                 */
                'calibrated' => $calibration['calibrated'],
                'observations' => count($test),
                'unfitted_levels' => $unfitted,
            ],

            // Discrimination with the clock held still. See horizonAuc().
            'horizon_auc' => self::horizonAuc($test, $predicted),
            'calibration' => $calibration['bins'],
            'calibration_statement' => $calibration['statement'],

            'estimates' => self::estimates($current, $terms, $fit, $limit),
            'estimate_note' => (string) ($dataset['estimate_note'] ?? self::ESTIMATE_NOTE),

            'training_data' => [
                'synthetic' => (bool) ($dataset['training_data']['synthetic'] ?? true),
                'notice' => (string) ($dataset['training_data']['notice'] ?? self::TRAINING_DATA_NOTICE),
            ],
            'methodology' => (string) ($dataset['methodology'] ?? self::METHODOLOGY),
        ];
    }

    /**
     * The model frame: payload columns turned into the numbers the fit needs.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function frame(array $rows): array
    {
        $frame = [];

        foreach ($rows as $row) {
            $days = (float) ($row['days_to_expiry'] ?? 0);

            $frame[] = [
                'late' => (int) ($row['late'] ?? 0),
                'split' => (string) ($row['split'] ?? ''),
                'expires_on' => (string) ($row['expires_on'] ?? ''),
                'cycle_id' => (int) ($row['cycle_id'] ?? 0),
                'days_to_expiry' => $days,
                /*
                 * Time enters on a log scale. The hazard accelerates sharply in
                 * the last fortnight — 41% of still-open cycles are late 180 days
                 * out against 99.6% one day out — and a straight day count cannot
                 * bend like that, so a linear term would misfit both ends at once
                 * and wreck the calibration this whole exercise is judged on. One
                 * coefficient either way, and the reported interpretation is per
                 * doubling of the days remaining, which is a sentence an officer
                 * can check against their own experience.
                 */
                'time_remaining' => log1p(max(0.0, $days)),
                'renewal_stage' => (string) ($row['renewal_stage'] ?? ''),
                'punctuality_known' => (float) ($row['punctuality_known'] ?? 0),
                'prior_late_rate' => (float) ($row['prior_late_rate'] ?? 0),
                'open_findings' => (float) ($row['open_findings'] ?? 0),
                'fee_state' => (string) ($row['fee_state'] ?? ''),
            ];
        }

        return $frame;
    }

    /**
     * Fold factor levels too thin to fit onto the reference level.
     *
     * This is not tidying. Left alone, `renewal_stage = draft` (four training
     * rows, all one outcome) produced an estimate of -14.17 with a standard error
     * of 378 — textbook quasi-separation, meaning "every draft in the training
     * period went the same way, so the fit ran off to infinity and was stopped by
     * the iteration limit". The number is not small or large; it is undefined.
     * Printing it in a coefficient table an officer is invited to read and argue
     * with would be worse than printing nothing, because it looks like a finding.
     *
     * A level survives if it has enough rows AND both outcomes occur in it. The
     * second condition is the one that matters: a level in which nothing was ever
     * late cannot have a finite coefficient however many rows it has. Survivors
     * keep their contrast; the rest are folded into the reference level and named
     * in `dropped`, so the table stays complete by saying what is missing.
     *
     * A level with no training rows at all is neither kept nor named. It was not
     * dropped for a reason a reader would recognise — it simply never happened —
     * and listing it would pad the table with non-events.
     *
     * @param  list<array<string, mixed>>  $train
     * @param  array<string, mixed>  $dataset
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<string>>, 2: list<array<string, string>>}
     */
    private static function foldRare(array $train, array $dataset, int $minimumLevel): array
    {
        $declared = [
            'renewal_stage' => [
                'label' => 'Renewal progress',
                'levels' => self::levelSet($dataset['label']['stages'] ?? null, ['none']),
            ],
            'fee_state' => [
                'label' => 'Unsettled fees',
                'levels' => self::levelSet($dataset['label']['fee_states'] ?? null, ['settled']),
            ],
        ];

        $dropped = [];
        $kept = [];

        foreach ($declared as $term => $spec) {
            $levels = $spec['levels'];

            if ($levels === []) {
                $kept[$term] = [];

                continue;
            }

            $reference = $levels[0];
            $keep = [];

            foreach ($levels as $level) {
                if ($level === $reference) {
                    $keep[] = $level;

                    continue;
                }

                $outcomes = [];

                foreach ($train as $row) {
                    if ($row[$term] === $level) {
                        $outcomes[] = $row['late'];
                    }
                }

                $count = count($outcomes);

                if ($count === 0) {
                    continue;
                }

                $distinct = count(array_unique($outcomes));

                if ($count < $minimumLevel || $distinct < 2) {
                    $dropped[] = [
                        'term' => $term.$level,
                        'label' => sprintf('%s — %s', $spec['label'], str_replace('_', ' ', $level)),
                        'reason' => $distinct < 2
                            ? sprintf(
                                'all %d training rows went the same way, so no finite coefficient exists for it '
                                ."— folded in with '%s'",
                                $count,
                                str_replace('_', ' ', $reference),
                            )
                            : sprintf(
                                "only %d training rows, below the %d needed to estimate it — folded in with '%s'",
                                $count,
                                $minimumLevel,
                                str_replace('_', ' ', $reference),
                            ),
                    ];

                    continue;
                }

                $keep[] = $level;
            }

            foreach ($train as $index => $row) {
                if (! in_array($row[$term], $keep, true)) {
                    $train[$index][$term] = $reference;
                }
            }

            $kept[$term] = $keep;
        }

        return [$train, $kept, $dropped];
    }

    /**
     * Which terms may enter the formula.
     *
     * A factor with one level cannot be fitted at all, and a numeric column that
     * never varies has no finite coefficient either. Both are real states of this
     * register — `fee_state` is 'settled' on all but a handful of rows — so they
     * are detected and the term is dropped WITH A STATED REASON rather than
     * failing the refresh or being quietly absent from a coefficient table the
     * screen presents as complete.
     *
     * @param  list<array<string, mixed>>  $train
     * @param  array<string, list<string>>  $levels  surviving levels per factor term
     * @return array{
     *     keep: list<array{term: string, label: string, kind: string, levels: list<string>}>,
     *     design: list<array{name: string, kind: string, levels: list<string>}>,
     *     dropped: list<array<string, string>>
     * }
     */
    private static function usableTerms(array $train, array $levels): array
    {
        $keep = [];
        $design = [];
        $dropped = [];

        foreach (self::specification() as $spec) {
            $term = $spec['term'];

            if ($spec['kind'] === 'factor') {
                $present = [];

                foreach ($levels[$term] ?? [] as $level) {
                    foreach ($train as $row) {
                        if ($row[$term] === $level) {
                            $present[] = $level;

                            break;
                        }
                    }
                }

                if (count($present) < 2) {
                    $dropped[] = [
                        'term' => $term,
                        'label' => $spec['label'],
                        'reason' => sprintf(
                            'only one value (%s) appears in the training period, so it explains nothing',
                            $present[0] ?? 'none',
                        ),
                    ];

                    continue;
                }

                $keep[] = $spec + ['levels' => $present];
                $design[] = ['name' => $term, 'kind' => 'factor', 'levels' => $present];

                continue;
            }

            if (count(array_unique(array_column($train, $term), SORT_REGULAR)) < 2) {
                $dropped[] = [
                    'term' => $term,
                    'label' => $spec['label'],
                    'reason' => 'the same value on every training row, so it explains nothing',
                ];

                continue;
            }

            $keep[] = $spec + ['levels' => []];
            $design[] = ['name' => $term, 'kind' => 'numeric', 'levels' => []];
        }

        return ['keep' => $keep, 'design' => $design, 'dropped' => $dropped];
    }

    /**
     * Fold levels the fit never saw onto the reference level, and count how often.
     *
     * @param  list<array<string, mixed>>  $test
     * @param  list<array{term: string, label: string, kind: string, levels: list<string>}>  $keep
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private static function align(array $test, array $keep): array
    {
        $folded = 0;

        foreach ($keep as $spec) {
            if ($spec['kind'] !== 'factor' || $spec['levels'] === []) {
                continue;
            }

            foreach ($test as $index => $row) {
                if (! in_array($row[$spec['term']], $spec['levels'], true)) {
                    $test[$index][$spec['term']] = $spec['levels'][0];
                    $folded++;
                }
            }
        }

        return [$test, $folded];
    }

    /**
     * One row per coefficient, with the sentence that says what it means.
     *
     * The interpretation is written here rather than on the screen because it
     * depends on the sign and the size, and a template in the client would either
     * be wrong for half the rows or so hedged as to say nothing. An odds ratio
     * below 1 lowers the chance; above 1 raises it; the reader is told which, in
     * those words.
     *
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $fit
     * @param  list<array{term: string, label: string, kind: string, levels: list<string>}>  $keep
     * @return list<array<string, mixed>>
     */
    private static function coefficientsOut(array $columns, array $fit, array $keep): array
    {
        $out = [];

        foreach ($columns as $index => $term) {
            $estimate = (float) $fit['coefficients'][$index];
            $oddsRatio = exp($estimate);
            $p = (float) $fit['p_values'][$index];

            $out[] = [
                'term' => $term,
                'label' => self::prettyLabel($term, $keep),
                'estimate' => self::r3($estimate),
                'std_error' => self::r3((float) $fit['standard_errors'][$index]),
                'z_value' => self::r3((float) $fit['z_values'][$index]),
                'p_value' => self::pValueOut($p),
                'odds_ratio' => self::r3($oddsRatio),
                'significant' => ! is_nan($p) && $p < 0.05,
                'interpretation' => self::interpretation($term, $estimate, $oddsRatio),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{term: string, label: string, kind: string, levels: list<string>}>  $keep
     */
    private static function prettyLabel(string $term, array $keep): string
    {
        if ($term === '(Intercept)') {
            return 'Baseline';
        }

        foreach ($keep as $spec) {
            if (! str_starts_with($term, $spec['term'])) {
                continue;
            }

            $level = substr($term, strlen($spec['term']));

            return $level === ''
                ? $spec['label']
                : sprintf('%s — %s', $spec['label'], str_replace('_', ' ', $level));
        }

        return $term;
    }

    private static function interpretation(string $term, float $estimate, float $oddsRatio): string
    {
        if ($term === '(Intercept)') {
            return 'Where the model starts before any signal is read.';
        }

        if ($term === 'time_remaining') {
            // The term is log1p(days), so a unit is an e-fold. Reported per
            // doubling because "twice as long left" is a thing an officer can
            // picture.
            $perDouble = exp($estimate * M_LN2);

            return sprintf(
                'Each doubling of the days left %s the odds of a late renewal, by a factor of %s.',
                $perDouble < 1.0 ? 'lowers' : 'raises',
                self::decimals($perDouble, 2),
            );
        }

        return sprintf(
            '%s the odds of a late renewal, by a factor of %s.',
            $oddsRatio < 1.0 ? 'Lowers' : 'Raises',
            self::decimals($oddsRatio, 2),
        );
    }

    /**
     * Discrimination with the clock held still.
     *
     * The single most important honesty check in this file.
     *
     * The evaluation set is a risk set: permits still unrenewed. The share of it
     * that ends up late climbs steeply as expiry approaches, so a model that knew
     * nothing but the date would still separate late from punctual across the
     * pooled set and post a good AUC. Quoting only that number would be quoting
     * the calendar and calling it a model.
     *
     * Splitting the AUC by lead time removes the calendar from the comparison:
     * within one lead, every permit is the same distance from expiry, so any
     * separation left is what the other four signals contribute. If these numbers
     * sit at 0.5 the model is the calendar, whatever the pooled figure says, and
     * the screen shows both so the reader can see which it is.
     *
     * @param  list<array<string, mixed>>  $test
     * @param  list<float>  $predicted
     * @return list<array<string, mixed>>
     */
    private static function horizonAuc(array $test, array $predicted): array
    {
        $leads = array_values(array_unique(array_column($test, 'days_to_expiry'), SORT_REGULAR));
        rsort($leads);

        $out = [];

        foreach ($leads as $lead) {
            $outcomes = [];
            $scores = [];

            foreach ($test as $index => $row) {
                if ($row['days_to_expiry'] === $lead) {
                    $outcomes[] = $row['late'];
                    $scores[] = $predicted[$index];
                }
            }

            $count = count($outcomes);

            $out[] = [
                'days_to_expiry' => (int) $lead,
                'observations' => $count,
                'late' => array_sum($outcomes),
                'late_rate' => self::r3($count === 0 ? null : array_sum($outcomes) / $count),
                'auc' => count(array_unique($outcomes)) < 2 ? null : self::r3(Glm::auc($outcomes, $scores)),
            ];
        }

        return $out;
    }

    /**
     * Calibration.
     *
     * A ranking can be perfect and the numbers still wrong: a model that scores
     * every late cycle above every punctual one, but says 90% when it means 40%,
     * has an AUC of 1.0 and is useless to anyone deciding how many businesses to
     * ring today. So the probabilities are checked against outcomes directly, two
     * ways.
     *
     *   - The regression of the outcome on the predicted log-odds. Its slope is 1
     *     and its intercept 0 when the model is right. A slope under 1 means the
     *     predictions are spread too wide — too confident at both ends. An
     *     intercept away from 0 means they are systematically high or low.
     *   - Deciles of predicted risk against the rate actually observed in each.
     *     This is the one to look at first, because it needs no statistics to
     *     read.
     *
     * @param  list<int>  $y
     * @param  list<float>  $p
     * @return array{
     *     slope: float|null,
     *     intercept: float|null,
     *     bins: list<array<string, mixed>>,
     *     calibrated: bool,
     *     statement: string
     * }
     */
    private static function calibration(array $y, array $p, int $bins): array
    {
        $logOdds = array_map(static fn (float $v): float => Glm::logit($v), $p);

        $slope = null;
        $intercept = null;

        if (count(array_unique($y)) > 1 && self::variance($logOdds) > 0.0) {
            $free = Glm::binomial(
                array_map(static fn (float $v): array => [1.0, $v], $logOdds),
                $y,
            );

            /*
             * Calibration-in-the-large: the intercept with the slope pinned at 1,
             * which is the "are these too high or too low on average" reading.
             * The free-slope intercept answers a different and less useful
             * question, so only the slope is taken from that fit.
             */
            $pinned = Glm::binomial(array_fill(0, count($y), [1.0]), $y, $logOdds);

            if ($free !== null) {
                $slope = $free['coefficients'][1];
            }

            if ($pinned !== null) {
                $intercept = $pinned['coefficients'][0];
            }
        }

        /*
         * Equal-count bins, not equal-width. Predicted risk piles up at the ends
         * here, so equal-width bins would put nearly everything in two of them
         * and report a calibration curve made of noise.
         */
        $sorted = $p;
        sort($sorted);

        $cuts = [];

        for ($i = 0; $i <= $bins; $i++) {
            $cut = Glm::quantile($sorted, $i / $bins);

            if (! in_array($cut, $cuts, true)) {
                $cuts[] = $cut;
            }
        }

        $groups = [];

        foreach ($p as $index => $value) {
            $groups[$index] = count($cuts) < 3 ? 1 : self::binOf($value, $cuts);
        }

        $distinct = array_values(array_unique($groups));
        sort($distinct);

        $rows = [];
        $worst = 0.0;

        foreach ($distinct as $group) {
            $inBin = [];
            $observed = [];

            foreach ($groups as $index => $assigned) {
                if ($assigned === $group) {
                    $inBin[] = $p[$index];
                    $observed[] = $y[$index];
                }
            }

            $meanPredicted = array_sum($inBin) / count($inBin);
            $meanObserved = array_sum($observed) / count($observed);
            $worst = max($worst, abs($meanPredicted - $meanObserved));

            $rows[] = [
                'bin' => $group,
                'observations' => count($inBin),
                'predicted' => self::r3($meanPredicted),
                'observed' => self::r3($meanObserved),
                'lower' => self::r3(min($inBin)),
                'upper' => self::r3(max($inBin)),
            ];
        }

        $meanP = array_sum($p) / count($p);
        $meanY = array_sum($y) / count($y);

        /*
         * The verdict, and the three ways it can fail. All three have to pass,
         * because each catches a different wrongness: the slope catches
         * predictions spread too wide or too narrow, the mean gap catches them
         * being uniformly too high or too low, and the worst decile catches a
         * model that is right on average while being badly wrong somewhere in
         * the middle.
         */
        $calibrated = $slope !== null
            && $slope >= 0.8 && $slope <= 1.25
            && abs($meanP - $meanY) <= 0.05
            && $worst <= 0.10;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'bins' => $rows,
            'calibrated' => $calibrated,
            'statement' => self::calibrationStatement($slope, $worst, $meanY, $meanP),
        ];
    }

    /**
     * Which equal-count bin a value falls in: bounds are open below and closed
     * above, with the very lowest value pulled into the first bin so nothing
     * falls off the bottom edge.
     *
     * @param  list<float>  $cuts
     */
    private static function binOf(float $value, array $cuts): int
    {
        for ($i = 1; $i < count($cuts); $i++) {
            if ($value <= $cuts[$i]) {
                return $i;
            }
        }

        return count($cuts) - 1;
    }

    /**
     * The calibration finding in a sentence a panelist would accept, generated
     * from the figures rather than written once and left to rot.
     *
     * Deliberately blunt: if the predictions are systematically high, this says
     * so, because a calibration statement that only ever reports success is not a
     * check.
     */
    private static function calibrationStatement(?float $slope, float $worst, float $observed, float $predicted): string
    {
        if ($slope === null) {
            return 'Calibration could not be measured: the evaluation period does not '
                .'hold both outcomes across a range of predicted values.';
        }

        if ($predicted > $observed + 0.02) {
            $direction = sprintf(
                'They run high: the model predicts %s%% late across the evaluation period where %s%% actually were.',
                self::decimals($predicted * 100, 0),
                self::decimals($observed * 100, 0),
            );
        } elseif ($predicted < $observed - 0.02) {
            $direction = sprintf(
                'They run low: the model predicts %s%% late across the evaluation period where %s%% actually were.',
                self::decimals($predicted * 100, 0),
                self::decimals($observed * 100, 0),
            );
        } else {
            $direction = sprintf(
                'On average they are right: %s%% predicted against %s%% observed.',
                self::decimals($predicted * 100, 0),
                self::decimals($observed * 100, 0),
            );
        }

        if ($slope < 0.8) {
            $spread = sprintf(
                'The spread is too wide (slope %s, ideal 1.00) — extreme figures are more extreme than the '
                .'outcomes justify, so a 90%% should be read as a strong warning rather than as nine in ten.',
                self::decimals($slope, 2),
            );
        } elseif ($slope > 1.25) {
            $spread = sprintf(
                'The spread is too narrow (slope %s, ideal 1.00) — the model hedges, and the real difference '
                .'between a high and a low figure is larger than it shows.',
                self::decimals($slope, 2),
            );
        } else {
            $spread = sprintf('The spread is about right (slope %s against an ideal 1.00).', self::decimals($slope, 2));
        }

        $band = sprintf(
            'Across the risk deciles the largest gap between predicted and observed is %s percentage points.',
            self::decimals($worst * 100, 0),
        );

        return $direction.' '.$spread.' '.$band;
    }

    /**
     * Applying the fit to the permits on the watchlist now.
     *
     * Three states a current permit can be in, and only one of them gets a
     * number:
     *
     *   - already lapsed. The renewal IS late; there is nothing left to estimate
     *     and a figure here would be a restatement of the expiry date dressed as
     *     a forecast.
     *   - renewal already approved. The successor is granted, so the question the
     *     model answers does not apply — and it is the exact state that was
     *     excluded from the fit, so there is no coefficient for it either.
     *   - still open. Estimated.
     *
     * Saying "not applicable" three different ways is more use than a number that
     * quietly means something different in each.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  array{keep: list<array<string, mixed>>, design: list<array<string, mixed>>, dropped: list<mixed>}  $terms
     * @param  array<string, mixed>  $fit
     * @return list<array<string, mixed>>
     */
    private static function estimates(array $current, array $terms, array $fit, int $limit): array
    {
        if ($current === []) {
            return [];
        }

        $frames = [];
        $states = [];

        foreach ($current as $permit) {
            $days = (float) ($permit['days_to_expiry'] ?? 0);
            $stage = (string) ($permit['renewal_stage'] ?? '');

            $states[] = match (true) {
                $days < 0 => 'lapsed',
                $stage === 'approved' => 'renewed',
                default => 'open',
            };

            $frames[] = [
                'time_remaining' => log1p(max(0.0, $days)),
                'renewal_stage' => $stage,
                'punctuality_known' => (float) ($permit['punctuality_known'] ?? 0),
                'prior_late_rate' => (float) ($permit['prior_late_rate'] ?? 0),
                'open_findings' => (float) ($permit['open_findings'] ?? 0),
                'fee_state' => (string) ($permit['fee_state'] ?? ''),
            ];
        }

        // A watchlist permit can carry a stage the fit never saw. It is folded
        // onto the reference level for the same reason a test row is: the model's
        // answer minus an unfitted stage beats no answer at all.
        [$frames] = self::align($frames, $terms['keep']);

        $design = Glm::design($frames, $terms['design']);
        $probabilities = Glm::predict($design['matrix'], $fit['coefficients']);

        $rows = [];

        foreach ($current as $index => $permit) {
            $state = $states[$index];
            $probability = $state === 'open' ? $probabilities[$index] : null;
            $barangay = $permit['barangay'] ?? null;

            $rows[] = [
                'permit_id' => (int) $permit['permit_id'],
                'business' => (string) $permit['business'],
                'permit_type' => (string) $permit['permit_type'],
                'barangay' => $barangay === null ? null : (string) $barangay,
                'valid_until' => (string) $permit['valid_until'],
                'days_to_expiry' => (int) $permit['days_to_expiry'],
                'renewal_stage' => (string) $permit['renewal_stage'],
                'probability' => self::r3($probability),
                'state' => $state,
                'state_label' => match ($state) {
                    'lapsed' => 'Already lapsed — the renewal is late',
                    'renewed' => 'Renewal already approved',
                    default => 'Estimated',
                },
                /*
                 * The rule score travels beside the fitted figure, computed from
                 * the same facts at the same moment. Carried through rather than
                 * recomputed here so the two numbers on the screen cannot be
                 * about different permits or different days.
                 */
                'rule_score' => (int) $permit['rule_score'],
                'rule_band' => (string) $permit['rule_band'],
                'rule_band_label' => (string) $permit['rule_band_label'],
            ];
        }

        // Riskiest first; the permits with nothing to estimate sort to the back
        // rather than being hidden, because "already lapsed" is the most urgent
        // row on the list and the reader still has to see it.
        usort(
            $rows,
            static fn (array $a, array $b): int => ($b['probability'] ?? -1) <=> ($a['probability'] ?? -1),
        );

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    private static function labelOut(array $dataset): array
    {
        $label = $dataset['label'] ?? [];

        return [
            'definition' => (string) ($label['definition'] ?? ''),
            'grace_days' => (int) ($label['grace_days'] ?? RenewalOutcomes::LATE_GRACE_DAYS),
            'settle_days' => (int) ($label['settle_days'] ?? RenewalOutcomes::SETTLE_DAYS),
            'lead_days' => array_map(intval(...), array_values((array) ($label['lead_days'] ?? []))),
        ];
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @param  list<array<string, mixed>>  $train
     * @param  list<array<string, mixed>>  $test
     * @return array<string, mixed>
     */
    private static function splitOut(array $dataset, array $train, array $test, string $cutoff): array
    {
        $trainDates = array_column($train, 'expires_on');
        $testDates = array_column($test, 'expires_on');

        return [
            'cutoff' => $cutoff,
            'basis' => (string) ($dataset['split']['basis'] ?? 'permit expiry date'),
            'train_from' => min($trainDates),
            'train_to' => max($trainDates),
            'test_from' => min($testDates),
            'test_to' => max($testDates),
            // The single property that stops the whole exercise being worthless:
            // a random split would let the model see the future it is marked on.
            'random' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $period
     * @return array<string, mixed>
     */
    private static function periodOut(array $period): array
    {
        $late = array_sum(array_column($period, 'late'));
        $count = count($period);

        return [
            'cycles' => count(array_unique(array_column($period, 'cycle_id'))),
            'observations' => $count,
            'late' => $late,
            'late_rate' => self::r3($count === 0 ? null : $late / $count),
        ];
    }

    /**
     * The shape returned when nothing can be fitted.
     *
     * Every key the fitted answer carries is present and empty, because the
     * screen reads this schema and a missing key is a crash where a null is a
     * sentence. `available = false` with a reason is a state the reader can act
     * on; a rule score quietly re-labelled as a probability would not be, and is
     * the one outcome this method exists to make impossible.
     *
     * The reasons all describe the DATA — too little labelled history, too little
     * of it before or after the cutoff, no signal that varies. None of them
     * describes a machine, because there is no longer a machine to be down: if
     * this returns unavailable it is because the register does not yet hold
     * enough settled renewals to fit on, and that is a fact about the register
     * that will fix itself as history accumulates.
     *
     * @param  array<string, mixed>  $dataset
     * @return array<string, mixed>
     */
    public static function unavailable(array $dataset, string $reason): array
    {
        return [
            'available' => false,
            'unavailable_reason' => $reason,
            'generated_at' => (string) ($dataset['now'] ?? ''),
            'engine' => self::ENGINE,

            'label' => self::labelOut($dataset),
            'split' => [
                'cutoff' => null,
                'basis' => (string) ($dataset['split']['basis'] ?? 'the expiry date of the permit being renewed'),
                'train_from' => null,
                'train_to' => null,
                'test_from' => null,
                'test_to' => null,
                'random' => false,
            ],
            'training' => ['cycles' => 0, 'observations' => 0, 'late' => 0, 'late_rate' => null],
            'evaluation' => ['cycles' => 0, 'observations' => 0, 'late' => 0, 'late_rate' => null],
            'counts' => self::countsShape($dataset['counts'] ?? []),

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
            'estimate_note' => (string) ($dataset['estimate_note'] ?? self::ESTIMATE_NOTE),

            'training_data' => [
                'synthetic' => (bool) ($dataset['training_data']['synthetic'] ?? true),
                'notice' => (string) ($dataset['training_data']['notice'] ?? self::TRAINING_DATA_NOTICE),
            ],
            'methodology' => (string) ($dataset['methodology'] ?? self::METHODOLOGY),
        ];
    }

    /**
     * The counts block, in the order and with the keys the payload carries.
     *
     * dataset() computes one extra count for its own diagnostics; the payload
     * lists the keys it publishes rather than forwarding whatever arrives, so a
     * new diagnostic upstream cannot silently become part of a contract a screen
     * and a PDF are written against.
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
            'late_rate' => self::r3((float) ($counts['late_rate'] ?? 0)),
            'observations' => (int) ($counts['observations'] ?? 0),
            'train_observations' => (int) ($counts['train_observations'] ?? 0),
            'test_observations' => (int) ($counts['test_observations'] ?? 0),
        ];
    }

    /**
     * A declared factor level set, normalised to a list of strings.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function levelSet(mixed $value, array $default): array
    {
        if (! is_array($value) || $value === []) {
            return $default;
        }

        return array_values(array_map(strval(...), $value));
    }

    /**
     * Three decimal places, half to even, with anything unmeasurable becoming a
     * null rather than a NAN.
     *
     * Three places is the precision every figure on this screen is quoted at, and
     * rounding here rather than at the point of display means the number in the
     * PDF, the number in the API response and the number on the screen are one
     * number. Half-to-even is Rounding's job and the reason it exists.
     */
    private static function r3(float|int|null $value): ?float
    {
        if ($value === null || is_nan((float) $value) || is_infinite((float) $value)) {
            return null;
        }

        return Rounding::statistic((float) $value, 3);
    }

    /**
     * A p-value at the precision this payload has always published it.
     *
     * Three significant figures is the intent — a p-value is read for its order
     * of magnitude, and 1.58e-32 and 0.0222 both say what they need to at three.
     * The second step is stranger and is here on purpose: values above 1e-5 are
     * then cut to four decimal places, so a p of 0.000149 is published as 0.0001.
     *
     * That is not a considered choice about precision, it is the behaviour of the
     * JSON encoder this payload used to be serialised by, which rounded to four
     * decimals unless a value was small enough that doing so would have destroyed
     * it entirely. It became observable the moment a p-value column was rendered
     * from it. Reproducing it keeps the figures on the screen identical across
     * the port instead of shifting a published column by a digit for reasons no
     * reader could account for — and pinning it here, with this note, is what
     * stops it being mistaken later for arithmetic.
     */
    private static function pValueOut(float $p): ?float
    {
        if (is_nan($p) || is_infinite($p)) {
            return null;
        }

        $significant = self::significantFigures($p, 3);

        return abs($significant) <= 1.0e-5 ? $significant : Rounding::statistic($significant, 4);
    }

    /**
     * Round to a number of significant figures rather than decimal places.
     */
    private static function significantFigures(float $value, int $figures): float
    {
        if ($value === 0.0) {
            return 0.0;
        }

        $magnitude = (int) floor(log10(abs($value)));

        return Rounding::statistic($value, $figures - 1 - $magnitude);
    }

    /**
     * A number formatted to fixed decimal places for prose, rounded half to even
     * first so a figure quoted in a sentence agrees with the same figure quoted
     * in the table beside it.
     */
    private static function decimals(float $value, int $places): string
    {
        return number_format(Rounding::statistic($value, $places), $places, '.', '');
    }

    /**
     * Population variance, used only to check that a column varies at all.
     *
     * @param  list<float>  $values
     */
    private static function variance(array $values): float
    {
        $count = count($values);

        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $total = 0.0;

        foreach ($values as $value) {
            $total += ($value - $mean) ** 2;
        }

        return $total / ($count - 1);
    }
}

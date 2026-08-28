<?php

use App\Support\AnalyticsDatasets;
use App\Support\RenewalModelAnalytics;
use App\Support\RenewalOutcomes;

/*
 * ── WHAT THIS FILE GUARDS, NOW THAT THERE IS ONE ENGINE ─────────────────────
 *
 * It used to compare two payloads produced by two different programs: a
 * statistics service that fitted the model, and a PHP side that deliberately
 * fitted nothing and returned an empty shape. The service has been removed and
 * the fit was ported into RenewalModelAnalytics, so the two-programs problem is
 * gone.
 *
 * The property it was protecting is not. The screen renders ONE payload whether
 * or not a model was fitted — a missing key there is a blank panel with no
 * explanation, where a null is a sentence the reader can act on — and there are
 * still two shapes that have to satisfy it: the fitted answer and the refusal.
 * Holding them to each other is now cheaper and stricter than holding either to
 * a service over HTTP, because both come from one pure function and neither test
 * can be skipped for being unable to reach anything.
 *
 * Numerical fidelity of the fit itself is not checked here. That is
 * tests/Unit/RenewalModelFitTest.php, which reproduces a frozen payload captured
 * from the engine this port replaced. What is checked here is the shape, the
 * evidence the payload owes before its figure may be called a probability, and
 * the one thing the model is never allowed to see.
 */

/**
 * The frozen dataset the fit is exercised against.
 *
 * A captured register, not a seeded one: the test database holds too little
 * settled renewal history to fit on, and a test whose real assertions only run
 * when the seeder happens to produce enough rows is a test that reports success
 * for not having run.
 *
 * @return array<string, mixed>
 */
function renewalModelGoldenDataset(): array
{
    $path = __DIR__.'/../fixtures/analytics/renewal-model.dataset.json';

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('gives the refusal exactly the keys a fitted answer has, in both directions', function () {
    $fitted = RenewalModelAnalytics::compute(renewalModelGoldenDataset());
    $refused = RenewalModelAnalytics::unavailable(renewalModelGoldenDataset(), 'no_labelled_history');

    expect($fitted['available'])->toBeTrue('The golden dataset must actually fit, or this compares one shape twice.');
    expect($refused['available'])->toBeFalse();

    /*
     * The top level is the contract the screen is written against, and it is
     * compared strictly in both directions: a key the refusal invents is as much
     * a break as one it drops, because the screen would render neither.
     */
    $fromFit = array_keys($fitted);
    $fromRefusal = array_keys($refused);
    sort($fromFit);
    sort($fromRefusal);

    expect($fromRefusal)->toBe($fromFit, sprintf(
        "The refusal and the fitted answer disagree about the payload's top-level keys.\n"
        ."  Only when fitted:  %s\n  Only when refused: %s",
        implode(', ', array_diff($fromFit, $fromRefusal)) ?: '—',
        implode(', ', array_diff($fromRefusal, $fromFit)) ?: '—',
    ));

    // The scalar blocks a screen reads unconditionally have to match key for key,
    // because those are the ones rendered whether or not a model was fitted.
    foreach (['metrics', 'training', 'evaluation', 'split', 'counts', 'label', 'training_data'] as $block) {
        $fitKeys = array_keys($fitted[$block]);
        $refusalKeys = array_keys($refused[$block]);
        sort($fitKeys);
        sort($refusalKeys);

        expect($refusalKeys)->toBe($fitKeys, sprintf(
            "The [%s] block differs.\n  Only when fitted:  %s\n  Only when refused: %s",
            $block,
            implode(', ', array_diff($fitKeys, $refusalKeys)) ?: '—',
            implode(', ', array_diff($refusalKeys, $fitKeys)) ?: '—',
        ));
    }

    /*
     * And the refusal must not be quietly serving something else under a fitted
     * heading. `available => false` with a reason is a state a reader can act on;
     * a rule score relabelled as a probability would not be, and is the one
     * outcome the empty shape exists to make impossible.
     */
    expect($refused['unavailable_reason'])->not->toBeEmpty();
    expect($refused['estimates'])->toBe([]);
    expect($refused['coefficients'])->toBe([]);
    expect($refused['metrics']['auc'])->toBeNull();
});

it('reports a fitted model with the evidence a fitted model owes', function () {
    $fitted = RenewalModelAnalytics::compute(renewalModelGoldenDataset());

    expect($fitted['available'])->toBeTrue();

    // Split by TIME. The single assertion that stops the whole exercise being
    // worthless: a random split lets the model see the future it is marked on.
    expect($fitted['split']['random'])->toBeFalse();
    expect($fitted['split']['train_to'] <= $fitted['split']['test_from'])->toBeTrue(
        'The training period runs past the start of the evaluation period.',
    );

    // AUC is a probability of correct ordering, so it lives in [0, 1]; a Brier
    // score over binary outcomes cannot exceed 1 either. Both being present and
    // in range is what licenses the word "probability" on this screen at all.
    expect($fitted['metrics']['auc'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);
    expect($fitted['metrics']['brier'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);
    expect($fitted['calibration_statement'])->not->toBe('');
    expect($fitted['calibration'])->not->toBe([]);

    /*
     * Every coefficient must be finite. An infinite one means a factor level
     * separated the outcome perfectly and the fit ran off — which foldRare() is
     * supposed to have caught before this point, and a standard error in the
     * hundreds is what it looks like when it did not.
     */
    expect($fitted['coefficients'])->not->toBe([]);

    foreach ($fitted['coefficients'] as $coefficient) {
        expect(is_finite((float) $coefficient['estimate']))->toBeTrue(
            "Coefficient [{$coefficient['term']}] is not finite.",
        );
        expect((float) $coefficient['std_error'])->toBeLessThan(50.0,
            "Coefficient [{$coefficient['term']}] has a standard error of {$coefficient['std_error']}, which means "
            .'it was not really estimated. Thin levels are supposed to be folded into the reference instead.',
        );
    }

    // No estimate may be offered where there is nothing to estimate.
    expect($fitted['estimates'])->not->toBe([]);

    foreach ($fitted['estimates'] as $estimate) {
        if ($estimate['state'] !== 'open') {
            expect($estimate['probability'])->toBeNull(
                "A permit in state [{$estimate['state']}] was given a figure anyway.",
            );
        }
        // The rule score travels beside it, always, so the screen can show both.
        expect($estimate)->toHaveKeys(['rule_score', 'rule_band', 'rule_band_label']);
    }
});

it('carries the training-data notice on every response, fitted or not', function () {
    /*
     * The honesty requirement that outranks the metrics. Almost every outcome
     * fitted here was written by AnalyticsHistorySeeder, so the model has learned
     * the seeder. This sentence is the finding, not a disclaimer, and it must not
     * be reachable only through a tooltip or only when a fit succeeds.
     */
    $fitted = RenewalModelAnalytics::compute(renewalModelGoldenDataset());
    $refused = RenewalModelAnalytics::unavailable(renewalModelGoldenDataset(), 'no_labelled_history');
    $live = RenewalModelAnalytics::build();

    foreach (['fitted' => $fitted, 'refused' => $refused, 'live' => $live] as $state => $payload) {
        expect($payload['training_data']['synthetic'])->toBeTrue("[{$state}] dropped the synthetic flag.");
        expect($payload['training_data']['notice'])->toBe(
            RenewalModelAnalytics::TRAINING_DATA_NOTICE,
            "[{$state}] dropped or paraphrased the training-data notice.",
        );
    }

    expect(mb_strtolower($fitted['training_data']['notice']))->toContain('demonstration data');
});

it('registers the dataset so the nightly refresh actually builds it', function () {
    expect(AnalyticsDatasets::all())->toHaveKey(AnalyticsDatasets::RENEWAL_MODEL);

    /*
     * One variant, and that is the whole list. See the note in
     * config/analytics.php: the horizon selector changes which permits are
     * estimated, not which cycles the model is fitted to, so five horizons would
     * refit the same regression five times to produce five identical coefficient
     * tables.
     */
    expect(AnalyticsDatasets::variants(AnalyticsDatasets::RENEWAL_MODEL))->toHaveCount(1);
});

it('lets the fit see nothing but features that were knowable when the observation was taken', function () {
    $dataset = RenewalModelAnalytics::dataset();

    $allowed = [
        'cycle_id', 'business_id', 'permit_id', 'expires_on', 'as_at',
        'days_to_expiry', 'renewal_stage', 'punctuality_known', 'prior_cycles',
        'prior_late', 'prior_late_rate', 'open_findings', 'fee_state',
        'late', 'split',
    ];

    foreach (array_slice($dataset['rows'], 0, 50) as $row) {
        /*
         * The dataset is the whole surface a leak can travel across. Anything the
         * fit receives, the fit can be given a coefficient for — so a column
         * added here for debugging (the successor's date, the gap in days, the
         * permit's current status) would become a feature the moment somebody
         * added it to the specification, and it would not look wrong on the way
         * in. Pinning the column list is what makes that a failing test rather
         * than a plausible commit.
         */
        expect(array_keys($row))->toBe($allowed);

        // The observation is strictly inside the permit's life and strictly
        // before its expiry: the day after the grace period closes, lateness is
        // a fact and there is nothing to estimate.
        expect($row['days_to_expiry'])->toBeGreaterThan(0);
        expect(strcmp($row['as_at'], $row['expires_on']) < 0)->toBeTrue();
    }

    expect($dataset['label']['grace_days'])->toBe(RenewalOutcomes::LATE_GRACE_DAYS);
    expect($dataset['split']['cutoff'] === null || is_string($dataset['split']['cutoff']))->toBeTrue();
});

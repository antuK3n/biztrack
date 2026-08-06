<?php

use App\Services\RAnalytics;
use App\Support\AnalyticsDatasets;
use App\Support\RenewalModelAnalytics;
use App\Support\RenewalOutcomes;

/*
 * ── WHY THIS IS NOT IN AnalyticsParityTest ──────────────────────────────────
 *
 * That test compares R's statistics against the PHP port's, value for value, on
 * a shared fixture. It exists because four datasets have two implementations and
 * two implementations drift.
 *
 * This one has one. Fitting a generalised linear model a second time in PHP so
 * the two copies can disagree about coefficients is not a fallback, it is a
 * second engine to keep honest — and a PHP "fallback" that quietly served the
 * rule score under a fitted heading would be the single most dishonest thing
 * this feature could do. So RenewalModelAnalytics::build() returns no statistics
 * at all: `available => false`, with a reason.
 *
 * What still has to hold, and holds harder for exactly that reason, is the
 * SHAPE. The screen reads one payload whether R fitted it or not, and a missing
 * key there is a blank panel with no explanation where a null is a sentence the
 * reader can act on. So the check here is the same check in the same two
 * directions — every key R returns must exist in the fallback, and every key the
 * fallback returns must exist in R's — with the values deliberately not
 * compared, because they are not supposed to match. One is a model and the other
 * is the honest absence of one.
 */

/**
 * Every key path in a payload, so two shapes can be compared without caring
 * about the values under them.
 *
 * Lists are walked through their FIRST element only. A payload's `estimates` is
 * empty in the fallback and twenty-five rows long when fitted, so comparing them
 * element by element would fail on length rather than on shape — and the row
 * shape is what matters. An empty list contributes its own path and stops, which
 * is why the fitted side is the one that must supply the row keys.
 *
 * @return list<string>
 */
function renewalModelKeyPaths(mixed $value, string $path = ''): array
{
    if (! is_array($value)) {
        return [$path];
    }

    // A list: describe the element shape once, from the head.
    if ($value === [] || array_is_list($value)) {
        return $value === []
            ? [$path]
            : renewalModelKeyPaths($value[0], $path.'[]');
    }

    $paths = [];
    foreach ($value as $key => $child) {
        $paths = [...$paths, ...renewalModelKeyPaths($child, $path === '' ? (string) $key : "{$path}.{$key}")];
    }

    return $paths;
}

it('gives the fallback exactly the keys a fitted answer has, in both directions', function () {
    $r = app(RAnalytics::class);

    if ($r->health() === null) {
        test()->markTestSkipped(
            'The R service is not running, so the fitted shape is unchecked. '
            .'Start it with: cd r && Rscript run_api.R'
        );
    }

    $fitted = $r->compute(RenewalModelAnalytics::R_ENDPOINT, RenewalModelAnalytics::dataset());

    expect($fitted)->not->toBeNull('R failed on the renewal model: '.(string) $r->lastError());

    $fallback = RenewalModelAnalytics::build();

    /*
     * The top level is the contract the screen is written against, and it is
     * compared strictly. Nested list ELEMENTS are not: the fallback's
     * `coefficients` and `estimates` are empty by construction, so there is no
     * row to describe, and demanding one would mean inventing a fake row in the
     * fallback purely to satisfy a test — which is how a placeholder ends up on
     * a screen.
     */
    $fromR = array_keys($fitted);
    $fromPhp = array_keys($fallback);

    sort($fromR);
    sort($fromPhp);

    expect($fromPhp)->toBe($fromR, sprintf(
        "The fallback and the fitted answer disagree about the payload's top-level keys.\n  Only in R:   %s\n  Only in PHP: %s",
        implode(', ', array_diff($fromR, $fromPhp)) ?: '—',
        implode(', ', array_diff($fromPhp, $fromR)) ?: '—',
    ));

    // The scalar blocks a screen reads unconditionally have to match key for key,
    // because those are the ones rendered whether or not a model was fitted.
    foreach (['metrics', 'training', 'evaluation', 'split', 'counts', 'label', 'training_data'] as $block) {
        $rKeys = array_keys($fitted[$block]);
        $phpKeys = array_keys($fallback[$block]);
        sort($rKeys);
        sort($phpKeys);

        expect($phpKeys)->toBe($rKeys, sprintf(
            "The [%s] block differs.\n  Only in R:   %s\n  Only in PHP: %s",
            $block,
            implode(', ', array_diff($rKeys, $phpKeys)) ?: '—',
            implode(', ', array_diff($phpKeys, $rKeys)) ?: '—',
        ));
    }
});

it('reports a fitted model with the evidence a fitted model owes', function () {
    $r = app(RAnalytics::class);

    if ($r->health() === null) {
        test()->markTestSkipped('The R service is not running.');
    }

    $fitted = $r->compute(RenewalModelAnalytics::R_ENDPOINT, RenewalModelAnalytics::dataset());

    if ($fitted['available'] !== true) {
        // A legitimate outcome on a register with too little settled history —
        // the seeded test database is one. It has to say why, though: a refusal
        // with no reason is indistinguishable from a bug.
        expect($fitted['unavailable_reason'])->not->toBeEmpty();
        expect($fitted['estimates'])->toBe([]);

        return;
    }

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

    // Every coefficient must be finite. An infinite one means a factor level
    // separated the outcome perfectly and the fit ran off — see .rm_fold_rare(),
    // which is supposed to have caught it before this point.
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
    $fallback = RenewalModelAnalytics::build();

    expect($fallback['training_data']['synthetic'])->toBeTrue();
    expect($fallback['training_data']['notice'])->toBe(RenewalModelAnalytics::TRAINING_DATA_NOTICE);
    expect(mb_strtolower($fallback['training_data']['notice']))->toContain('demonstration data');

    $r = app(RAnalytics::class);
    if ($r->health() === null) {
        return;
    }

    $fitted = $r->compute(RenewalModelAnalytics::R_ENDPOINT, RenewalModelAnalytics::dataset());
    expect($fitted['training_data']['notice'])->toBe(RenewalModelAnalytics::TRAINING_DATA_NOTICE);
});

it('registers the dataset so the nightly refresh actually pushes it', function () {
    expect(AnalyticsDatasets::pushable())->toHaveKey(AnalyticsDatasets::RENEWAL_MODEL);

    $definition = AnalyticsDatasets::get(AnalyticsDatasets::RENEWAL_MODEL);
    expect($definition['endpoint'])->toBe(RenewalModelAnalytics::R_ENDPOINT);

    // One variant, and that is the whole list. See the note in config/analytics.php:
    // the horizon selector changes which permits are estimated, not which cycles
    // the model is fitted to, so five horizons would refit the same regression
    // five times to produce five identical coefficient tables.
    expect(AnalyticsDatasets::variants(AnalyticsDatasets::RENEWAL_MODEL))->toHaveCount(1);
});

it('sends R nothing but features that were knowable when the observation was taken', function () {
    $dataset = RenewalModelAnalytics::dataset();

    $allowed = [
        'cycle_id', 'business_id', 'permit_id', 'expires_on', 'as_at',
        'days_to_expiry', 'renewal_stage', 'punctuality_known', 'prior_cycles',
        'prior_late', 'prior_late_rate', 'open_findings', 'fee_state',
        'late', 'split',
    ];

    foreach (array_slice($dataset['rows'], 0, 50) as $row) {
        /*
         * The payload is the whole surface a leak can travel across. Anything R
         * receives, R can fit on — so a column added here for debugging (the
         * successor's date, the gap in days, the permit's current status) would
         * become a feature the moment somebody added it to the formula, and it
         * would not look wrong on the way in. Pinning the column list is what
         * makes that a failing test rather than a plausible commit.
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

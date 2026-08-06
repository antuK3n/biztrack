<?php

use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsDefinitions;
use App\Support\BusinessGrowthAnalytics;
use App\Support\DashboardAnalytics;
use App\Support\ProcessingTimeAnalytics;
use App\Support\RenewalModelAnalytics;
use App\Support\RenewalRiskAnalytics;

/*
 * The definitions in AnalyticsDefinitions are shown to a reader as an
 * explanation of a number they are being asked to trust. That makes a stale one
 * worse than none: a screen that confidently mis-describes its own arithmetic is
 * harder to catch than a screen that says nothing.
 *
 * Prose cannot be checked against a query automatically. What can be checked is
 * that every definition still points at a figure that exists, and that no panel
 * has been added without one — which is how the drift actually happens.
 */

/** Does `$needle` appear anywhere in `$haystack`, as an array key or a string value? */
function definitionTokenExists(mixed $haystack, string $needle): bool
{
    if (! is_array($haystack)) {
        return is_string($haystack) && $haystack === $needle;
    }

    foreach ($haystack as $key => $value) {
        if ($key === $needle) {
            return true;
        }
        if (definitionTokenExists($value, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * A built payload per dataset, and the panels a reader sees figures in.
 *
 * The panel lists are curated rather than derived from the payload keys. A
 * payload also carries the window it was built over — `window_start`,
 * `generated_at`, `period_months` and friends — and those are labels for the
 * window, not figures derived from it. Listing them would demand a formula for
 * a date. Adding a real panel without adding a definition fails here.
 *
 * @return array<string, array{payload: array<string, mixed>, panels: list<string>}>
 */
function analyticsDefinitionSubjects(): array
{
    return [
        /*
         * `expiry` is on the dashboard payload and is deliberately NOT a panel
         * here. "Permits Approaching Expiry" moved to Renewal Risk Prediction,
         * rebuilt around four named states; the key stays because R computes it
         * and the parity check reads both key sets (see the note where the
         * definition used to be, in AnalyticsDefinitions::dashboard()). This list
         * is the panels a READER sees, and nobody sees that one any more.
         */
        AnalyticsDatasets::DASHBOARD => [
            'payload' => DashboardAnalytics::build(DashboardAnalytics::DEFAULT_WINDOW_MONTHS),
            'panels' => [
                'kpis', 'volume', 'decisions', 'processing_tiers', 'stages', 'compliance',
                'top_barangays', 'top_lines_of_business', 'organization_forms',
                'inspections', 'officer_activity', 'map',
            ],
        ],

        /*
         * `thin` is on the payload but not in this list: it is the departments
         * the chart had to leave out, and it carries its own `reason` string
         * explaining each omission in place. A definition would restate it.
         */
        AnalyticsDatasets::PROCESSING_TIME => [
            'payload' => ProcessingTimeAnalytics::build(),
            'panels' => ['departments', 'completed_reviews'],
        ],

        /*
         * `lifecycle` is spliced in the same way AnalyticsController splices it
         * onto every renewal-risk response, and for the same reason: R does not
         * compute it, so it cannot ride on compute()'s output without failing the
         * parity check in both directions (RenewalRiskAnalytics::lifecycle()
         * carries the argument). The payload this test walks has to be the
         * payload the SCREEN gets, or a definition for a panel the reader sees
         * would go unchecked here.
         */
        AnalyticsDatasets::RENEWAL_RISK => [
            'payload' => RenewalRiskAnalytics::build() + ['lifecycle' => RenewalRiskAnalytics::lifecycle()],
            'panels' => [
                'at_risk', 'counts', 'lifecycle', 'reminders_sent', 'actions', 'rulebook',
                'scored_permits', 'methodology',
            ],
        ],

        /*
         * The fitted model, walked through its UNAVAILABLE shape — which is what
         * RenewalModelAnalytics::build() returns, because PHP does not fit a
         * regression and deliberately does not pretend to.
         *
         * That is the right payload to check here rather than a shortcoming of
         * the fixture. The fallback and the fitted answer carry the same keys by
         * contract (RenewalModelKeyParityTest holds R to it), so a definition
         * that survives this walk names a panel that exists in both states. The
         * list panels are empty in this shape, so their sub-figures are reported
         * as unverified above rather than silently passing.
         */
        AnalyticsDatasets::RENEWAL_MODEL => [
            'payload' => RenewalModelAnalytics::build(),
            'panels' => [
                'estimates', 'metrics', 'calibration', 'coefficients',
                'horizon_auc', 'split', 'training', 'training_data',
            ],
        ],

        AnalyticsDatasets::BUSINESS_GROWTH => [
            'payload' => BusinessGrowthAnalytics::build(),
            'panels' => [
                'growth_rate', 'registrations', 'closures', 'status_summary',
                'cohort_survival', 'top_barangays', 'closure_trend', 'industry_growth',
            ],
        ],
    ];
}

it('defines only figures the payload actually contains', function () {
    foreach (analyticsDefinitionSubjects() as $dataset => $subject) {
        $payload = $subject['payload'];
        $definitions = AnalyticsDefinitions::for($dataset);

        expect($definitions)->not->toBeEmpty("Dataset [{$dataset}] ships no definitions at all.");

        foreach ($definitions as $key => $definition) {
            $segments = explode('.', $key);
            $panel = array_shift($segments);

            // The panel must still be on the payload under the name we describe.
            expect(array_key_exists($panel, $payload))->toBeTrue(
                "Definition [{$dataset}.{$key}] names panel [{$panel}], which the payload no longer has.",
            );

            /*
             * The remaining segments identify the figure within the panel. They
             * are matched loosely — as a key or as a string value — because the
             * payload addresses figures both ways: `kpis.active_businesses` is a
             * key, while `compliance.renewal` is the `indicator` value on a row.
             * Either way, a rename breaks the match, which is the drift worth
             * catching.
             *
             * A panel that is a list can be empty when the register holds
             * nothing to fill it — no permit near expiry, no department with
             * enough completions to chart. There is then no key to match and no
             * rename to catch, so the segment check is skipped rather than
             * failed. It is skipped loudly: an empty panel is reported, so a
             * fixture that has quietly stopped exercising a screen shows up as a
             * gap in coverage rather than as a green test.
             */
            if ($segments !== [] && $payload[$panel] === []) {
                $skipped[] = "{$dataset}.{$key}";

                continue;
            }

            foreach ($segments as $segment) {
                expect(definitionTokenExists($payload[$panel], $segment))->toBeTrue(
                    "Definition [{$dataset}.{$key}] names figure [{$segment}], which no longer appears in the [{$panel}] panel.",
                );
            }
        }
    }

    if (isset($skipped)) {
        fwrite(STDERR, "\n  Unverified against an empty panel: ".implode(', ', $skipped)."\n");
    }
});

it('carries all four fields on every definition, none of them blank', function () {
    foreach (array_keys(analyticsDefinitionSubjects()) as $dataset) {
        $definitions = AnalyticsDefinitions::for($dataset);

        foreach ($definitions as $key => $definition) {
            expect($definition)->toHaveKeys(
                ['label', 'formula', 'covers', 'why'],
                "Definition [{$dataset}.{$key}] is missing a field.",
            );

            foreach ($definition as $field => $text) {
                expect(trim($text))->not->toBe('', "Definition [{$dataset}.{$key}] has an empty [{$field}].");
            }
        }
    }
});

it('leaves no panel unexplained', function () {
    foreach (analyticsDefinitionSubjects() as $dataset => $subject) {
        $definitions = array_keys(AnalyticsDefinitions::for($dataset));

        foreach ($subject['panels'] as $panel) {
            $covered = array_filter(
                $definitions,
                static fn (string $key): bool => $key === $panel || str_starts_with($key, $panel.'.'),
            );

            expect($covered)->not->toBeEmpty("Panel [{$dataset}.{$panel}] has no entry in AnalyticsDefinitions.");
        }
    }
});

/*
 * The renewal risk score is a weighted rule score, not a fitted model. The
 * paper and the mockup both described it as an "Estimated Probability of
 * Delayed Renewal" and printed percentages against it; nothing in the register
 * records whether a business eventually renewed late, so there is no outcome to
 * have trained on and no calibration to report.
 *
 * docs/r-integration-spec.md settles this: keep the number and the banding, do
 * not label it a probability or a prediction confidence. The reason is not
 * pedantry — an officer who reads "88%" as calibrated will act on it as
 * calibrated. This test is the guard on the wording, because the wording is the
 * only place the claim can be made.
 *
 * The four terms the spec names are banned outright, along with `forecast`,
 * and the ban covers denials too — "this is not a probability" fails here just
 * as "88% probability" would. That is deliberate. A definition that has to
 * deny the claim is a definition written in the claim's vocabulary, and the
 * reader is left holding the word. Saying instead that the register records no
 * outcome to have fitted against is both true and harder to misquote.
 *
 * `likely` is not on the list: it is ordinary English long before it is a
 * statistical term, and banning it would only push prose into worse phrasing
 * without closing any gap `likelihood` leaves open.
 */
it('never describes the renewal risk score as a prediction', function () {
    $forbidden = ['probability', 'probable', 'likelihood', 'predict', 'forecast', 'confidence'];

    foreach (AnalyticsDefinitions::for(AnalyticsDatasets::RENEWAL_RISK) as $key => $definition) {
        foreach ($definition as $field => $text) {
            foreach ($forbidden as $word) {
                expect(str_contains(mb_strtolower($text), $word))->toBeFalse(
                    "Definition [renewal_risk.{$key}] uses [{$word}] in [{$field}]. The score is a weighted rule "
                    .'score with nothing fitted behind it — see the honesty constraint in docs/r-integration-spec.md.',
                );
            }
        }
    }
});

/*
 * ── THE OTHER HALF OF THE BAN ───────────────────────────────────────────────
 *
 * The test above is unchanged and stays unchanged. It guards the RULE SCORE, and
 * the rule score is still a weighted rule score with nothing fitted behind it.
 *
 * What has changed is that the register now yields a second figure. The renewal
 * outcome was never a column, but it was always implied by the permit dates —
 * see App\Support\RenewalOutcomes — so there is now a logistic regression fitted
 * to 1,300-odd recovered cycles, evaluated on a period of the register it never
 * saw, and reported with AUC, a Brier score and a calibration reading. That
 * figure may honestly be called a probability.
 *
 * The ban was therefore SCOPED, not lifted. `renewal_risk` remains exactly as
 * constrained as it was; `renewal_model` may use the word — and this test is
 * what stops that licence becoming a loophole. Three conditions, all enforced:
 *
 *  1. The evidence has to travel with the word. If a definition on the fitted
 *     dataset says "probability", the payload must carry the metrics that back
 *     it. A probability with no calibration figure beside it is the exact claim
 *     the original ban existed to prevent, and moving it to a new dataset would
 *     not make it true.
 *  2. `predict`, `forecast` and `confidence` stay banned EVERYWHERE, including
 *     here. A fitted conditional estimate of how often permits in this position
 *     turned out late is a probability; it is not a prediction of what one
 *     business will do, and "confidence" means something specific in statistics
 *     that this figure does not report.
 *  3. The old claim is still caught on the old figure. The renewal-risk
 *     methodology sentence must still deny it in as many words, so a future
 *     edit that quietly upgrades the rule score fails here rather than shipping.
 */
it('lets only the fitted figure be called a probability, and only with its evidence', function () {
    $definitions = AnalyticsDefinitions::for(AnalyticsDatasets::RENEWAL_MODEL);

    expect($definitions)->not->toBeEmpty('The fitted dataset ships no definitions at all.');

    // Condition 2 — the words that claim more than a fitted estimate can support.
    // `probability` is deliberately absent from this list, and only here.
    $forbidden = ['predict', 'forecast', 'confidence'];

    foreach ($definitions as $key => $definition) {
        foreach ($definition as $field => $text) {
            foreach ($forbidden as $word) {
                expect(str_contains(mb_strtolower($text), $word))->toBeFalse(
                    "Definition [renewal_model.{$key}] uses [{$word}] in [{$field}]. A fitted, calibrated estimate "
                    .'may be called a probability; it may not be called a prediction, a forecast, or a confidence.',
                );
            }
        }
    }

    // Condition 1 — where the word is used, the evidence must be on the payload.
    $usesTheWord = collect($definitions)->contains(
        static fn (array $definition): bool => collect($definition)->contains(
            static fn (string $text): bool => str_contains(mb_strtolower($text), 'probability'),
        ),
    );

    expect($usesTheWord)->toBeTrue(
        'No definition on the fitted dataset calls the figure a probability. The scoped exemption exists to be '
        .'used deliberately; if the figure is not being described as one, the exemption should not exist.',
    );

    $payload = RenewalModelAnalytics::build();

    foreach (['auc', 'brier', 'calibration_slope', 'calibrated'] as $metric) {
        expect(array_key_exists($metric, $payload['metrics']))->toBeTrue(
            "The fitted payload calls its figure a probability but does not carry [metrics.{$metric}]. "
            .'The word is licensed by the evidence travelling with it, not by the dataset it sits on.',
        );
    }

    expect(array_key_exists('calibration', $payload))->toBeTrue();
    expect(array_key_exists('notice', $payload['training_data']))->toBeTrue(
        'The fitted payload must carry the training-data notice on every response, fitted or not.',
    );
});

it('still denies the claim on the figure the ban was written for', function () {
    /*
     * The rule score's own sentence, shown on its screen and carried into every
     * export. It has to keep denying the claim in words a reader sees — either
     * phrasing will do, since the constant has been reworded once already, but
     * one of them must be there. If this ever goes quiet, the scoped exemption
     * above has leaked back onto the figure the ban was written for.
     */
    $methodology = mb_strtolower(RenewalRiskAnalytics::METHODOLOGY);

    expect(str_contains($methodology, 'not a prediction') || str_contains($methodology, 'not a probability'))
        ->toBeTrue('The rule score no longer denies being a prediction anywhere in its own methodology statement.');

    // And the fitted dataset must not be quietly serving the rule score under a
    // fitted heading when there is no fit: the fallback says so in its own field.
    $fallback = RenewalModelAnalytics::build();
    expect($fallback['available'])->toBeFalse();
    expect($fallback['unavailable_reason'])->not->toBeNull();
    expect($fallback['estimates'])->toBe([]);
    expect($fallback['metrics']['auc'])->toBeNull();
});

it('ships the definitions in meta, beside the engine rather than inside the figures', function () {
    // Read as BPLO: the dashboard sits on `analytics.view`, which BPLO holds and
    // the super admin does not.
    $response = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard');

    $response->assertOk();

    $body = $response->json();

    expect($body['meta'])->toHaveKey('definitions');
    expect($body['meta']['definitions'])->toHaveKey('decisions.approval_rate');

    // The payload R returns must stay exactly the payload R returned.
    expect($body['data'])->not->toHaveKey('definitions');
});

it('ships definitions on every analytics screen, not only the dashboard', function () {
    /*
     * Each screen is read by whoever actually holds it. The four analytics
     * screens no longer sit behind one permission: `analytics.view` carries the
     * three operational ones and belongs to BPLO, while `analytics.processing_
     * time` carries the oversight one and belongs to the super admin. Reading
     * all four as a single account would 403 on one of them whichever account
     * were chosen, so the caller is part of the fixture here.
     */
    $screens = [
        'processing-time' => 'admin@biztrack.local',
        'renewal-risk' => 'bplo@biztrack.local',
        'business-growth' => 'bplo@biztrack.local',
    ];

    foreach ($screens as $route => $email) {
        $response = test()->withHeaders(authAs($email))
            ->getJson("/api/v1/analytics/{$route}");

        $response->assertOk();

        expect($response->json('meta.definitions'))->not->toBeEmpty(
            "The [{$route}] screen ships no definitions, so every info button on it renders nothing.",
        );
        expect($response->json('data'))->not->toHaveKey('definitions');
    }
});

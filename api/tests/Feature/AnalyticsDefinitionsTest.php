<?php

use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsDefinitions;
use App\Support\BusinessGrowthAnalytics;
use App\Support\DashboardAnalytics;
use App\Support\ProcessingTimeAnalytics;
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
        AnalyticsDatasets::DASHBOARD => [
            'payload' => DashboardAnalytics::build(DashboardAnalytics::DEFAULT_WINDOW_MONTHS),
            'panels' => [
                'kpis', 'volume', 'decisions', 'processing_tiers', 'stages', 'compliance',
                'expiry', 'top_barangays', 'top_lines_of_business', 'organization_forms',
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

        AnalyticsDatasets::RENEWAL_RISK => [
            'payload' => RenewalRiskAnalytics::build(),
            'panels' => [
                'at_risk', 'counts', 'reminders_sent', 'actions', 'rulebook',
                'scored_permits', 'methodology',
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

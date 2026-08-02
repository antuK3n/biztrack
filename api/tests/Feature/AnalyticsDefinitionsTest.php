<?php

use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsDefinitions;
use App\Support\DashboardAnalytics;

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

it('defines only figures the dashboard payload actually contains', function () {
    $payload = DashboardAnalytics::build(DashboardAnalytics::DEFAULT_WINDOW_MONTHS);
    $definitions = AnalyticsDefinitions::for(AnalyticsDatasets::DASHBOARD);

    expect($definitions)->not->toBeEmpty();

    foreach ($definitions as $key => $definition) {
        $segments = explode('.', $key);
        $panel = array_shift($segments);

        // The panel must still be on the payload under the name we describe.
        expect(array_key_exists($panel, $payload))->toBeTrue(
            "Definition [{$key}] names panel [{$panel}], which the dashboard payload no longer has.",
        );

        /*
         * The remaining segments identify the figure within the panel. They are
         * matched loosely — as a key or as a string value — because the payload
         * addresses figures both ways: `kpis.active_businesses` is a key, while
         * `compliance.renewal` is the `indicator` value on a row. Either way, a
         * rename breaks the match, which is the drift worth catching.
         */
        foreach ($segments as $segment) {
            expect(definitionTokenExists($payload[$panel], $segment))->toBeTrue(
                "Definition [{$key}] names figure [{$segment}], which no longer appears in the [{$panel}] panel.",
            );
        }
    }
});

it('carries all four fields on every definition, none of them blank', function () {
    foreach (AnalyticsDefinitions::for(AnalyticsDatasets::DASHBOARD) as $key => $definition) {
        expect($definition)->toHaveKeys(['label', 'formula', 'covers', 'why'], "Definition [{$key}] is missing a field.");

        foreach ($definition as $field => $text) {
            expect(trim($text))->not->toBe('', "Definition [{$key}] has an empty [{$field}].");
        }
    }
});

it('leaves no dashboard panel unexplained', function () {
    $definitions = array_keys(AnalyticsDefinitions::for(AnalyticsDatasets::DASHBOARD));

    /*
     * The panels a reader sees figures in. Context keys (`window_start`,
     * `generated_at` and friends) are labels for the window, not figures derived
     * from it, so they are not listed. Adding a panel to the dashboard without
     * adding a definition fails here.
     */
    $panels = [
        'kpis', 'volume', 'decisions', 'processing_tiers', 'stages', 'compliance',
        'expiry', 'top_barangays', 'top_lines_of_business', 'organization_forms',
        'inspections', 'officer_activity', 'map',
    ];

    foreach ($panels as $panel) {
        $covered = array_filter(
            $definitions,
            static fn (string $key): bool => $key === $panel || str_starts_with($key, $panel.'.'),
        );

        expect($covered)->not->toBeEmpty("Dashboard panel [{$panel}] has no entry in AnalyticsDefinitions.");
    }
});

it('ships the definitions in meta, beside the engine rather than inside the figures', function () {
    $response = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard');

    $response->assertOk();

    $body = $response->json();

    expect($body['meta'])->toHaveKey('definitions');
    expect($body['meta']['definitions'])->toHaveKey('decisions.approval_rate');

    // The payload R returns must stay exactly the payload R returned.
    expect($body['data'])->not->toHaveKey('definitions');
});

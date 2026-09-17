<?php

use App\Support\AnalyticsDatasets;
use App\Support\OfficePerformanceAnalytics;
use App\Support\Ra11032;

/*
 * Office Performance — the six offices on one set of axes (issue #102).
 *
 * The screen's whole claim is that its columns mean the same thing in every
 * row. One of them does not, for one office, and the tests below are ordered
 * around that: the permission, then the six offices being present, then the
 * exclusion that keeps the comparison honest, then the arithmetic.
 *
 * The arithmetic is driven through compute() with a hand-built dataset rather
 * than through the seeder. That is the reason the dataset/compute seam exists
 * in every builder in app/Support: a seeded figure moves whenever the seeder
 * does, so a test written against one can only assert that a number came out,
 * never which number.
 */

/**
 * A dataset in the shape dataset() returns, with only the parts a test varies.
 *
 * @param  list<array{department_code: string, assigned_at: string, completed_at: string, tier: string|null, from_test_business?: bool}>  $holds
 * @param  list<array{department_code: string, assigned_at: string|null}>  $open
 * @return array<string, mixed>
 */
function officeDataset(array $holds, array $open = [], array $notComparable = ['BPLO' => 'stamped twice']): array
{
    return [
        'params' => ['weeks' => 52],
        'now' => '2026-09-17T00:00:00.000000Z',
        'window_start' => '2025-09-15',
        'not_comparable' => $notComparable,
        'test_data_patterns' => ['E2E%'],
        'departments' => [
            ['code' => 'BPLO', 'name' => 'Business Permits and Licensing Office'],
            ['code' => 'CHO', 'name' => 'City Health Office'],
        ],
        'holds' => array_map(
            static fn (array $hold): array => $hold + ['from_test_business' => false],
            $holds,
        ),
        'open' => $open,
        'businesses' => ['total' => 100, 'test_shaped' => 7],
    ];
}

/** One finished hold, spelled as the dataset spells it. */
function officeHold(string $code, string $from, string $to, ?string $tier = 'complex'): array
{
    return [
        'department_code' => $code,
        'assigned_at' => $from.'T09:00:00.000000Z',
        'completed_at' => $to.'T15:00:00.000000Z',
        'tier' => $tier,
    ];
}

it('is the super admin\'s screen and not BPLO\'s', function () {
    /*
     * The rule, stated as the route comment states it: this ranks the
     * departments against each other, BPLO among them, so it belongs to the
     * office doing the oversight rather than to one of the offices being
     * overseen. BPLO holds `analytics.view` and is the office that would most
     * like this screen, which is exactly why the refusal is worth a test.
     */
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/office-performance')
        ->assertForbidden();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/analytics/office-performance')
        ->assertForbidden();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/office-performance')
        ->assertOk();
});

it('names every office the register has, including any that finished nothing', function () {
    /*
     * An office missing from a six-office comparison reads as an office that
     * does not exist. The client reported exactly that against Processing Time
     * when three offices were relegated to a footnote for having too little
     * data; the answer there was to give every office a card, and the same rule
     * holds here for the same reason.
     */
    $response = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/office-performance')
        ->assertOk();

    $codes = array_column($response->json('data.offices'), 'code');

    foreach (['BPLO', 'CHO', 'BFP', 'OBO', 'CENRO', 'CPDO'] as $office) {
        expect($codes)->toContain($office);
    }

    expect($response->json('data.totals.offices'))->toBe(count($codes));
});

it('keeps BPLO\'s volume but not its turnaround, and says why on the row', function () {
    /*
     * ── The defect this whole screen is shaped around ───────────────────────
     *
     * There is one assignment row per (application, department), and BPLO acts
     * on a filing twice — at intake and again at final approval. Both calls go
     * through WorkflowService::completeAssignment, which sets `completed_at` to
     * now() with no guard for a row it has already stamped, so the second
     * overwrites the first and BPLO's recorded time becomes the whole filing's
     * lifetime: every other office's hold, the payment, the inspection wait.
     *
     * Counts of rows are unharmed by that, so BPLO keeps them. Anything derived
     * from the clock is null — never zero, which would read as "instant" — and
     * the reason travels on the row so the blank cell cannot be read as an
     * oversight.
     *
     * When completeAssignment is fixed, this test is the one that should be
     * rewritten first, and its new name is the new rule.
     */
    $report = OfficePerformanceAnalytics::compute(officeDataset([
        officeHold('BPLO', '2026-09-01', '2026-09-14'),
        officeHold('CHO', '2026-09-01', '2026-09-03'),
    ], [
        ['department_code' => 'BPLO', 'assigned_at' => '2026-09-10T09:00:00.000000Z'],
    ]));

    $bplo = collect($report['offices'])->firstWhere('code', 'BPLO');
    $cho = collect($report['offices'])->firstWhere('code', 'CHO');

    // Volume and caseload: real, kept, unaffected by when the row was stamped.
    expect($bplo['handled'])->toBe(1)
        ->and($bplo['open'])->toBe(1)
        ->and($bplo['oldest_open_working_days'])->not->toBeNull();

    // Everything the corrupted clock touches.
    foreach (['mean_working_days', 'median_working_days', 'slowest_working_days', 'breached', 'breach_rate'] as $figure) {
        expect($bplo[$figure])->toBeNull(
            "BPLO still reports [{$figure}], which is the whole filing's lifetime wearing BPLO's name.",
        );
    }

    expect($bplo['turnaround_comparable'])->toBeFalse()
        ->and($bplo['not_comparable_reason'])->not->toBeEmpty();

    // And the office whose row IS its own step is unaffected by any of this.
    expect($cho['turnaround_comparable'])->toBeTrue()
        ->and($cho['mean_working_days'])->not->toBeNull();
});

it('leaves the uncomparable office out of the tier counts as well as the table', function () {
    /*
     * The half of the exclusion that is easy to forget. BPLO's figure already
     * CONTAINS the other five offices' holds by construction, so counting it
     * against a statutory allowance would measure the same waiting twice and
     * would do it in the one panel that quotes the statute.
     */
    $report = OfficePerformanceAnalytics::compute(officeDataset([
        // Thirteen working days: over every tier but the twenty-day one.
        officeHold('BPLO', '2026-08-31', '2026-09-17', 'simple'),
        officeHold('CHO', '2026-08-31', '2026-09-01', 'simple'),
    ]));

    $simple = collect($report['tiers'])->firstWhere('key', 'simple');

    expect($simple['holds'])->toBe(1)
        ->and($simple['over'])->toBe(0)
        ->and($report['totals']['compared_offices'])->toBe(1);
});

it('prints all three statutory tiers even when the window holds none of one', function () {
    /*
     * A tier that disappears when nothing lands in it turns "no highly technical
     * filings this year" into "this City has two tiers". RA 11032 has three and
     * the screen may not appear to disagree.
     */
    $report = OfficePerformanceAnalytics::compute(officeDataset([
        officeHold('CHO', '2026-09-01', '2026-09-02', 'simple'),
    ]));

    expect(array_column($report['tiers'], 'key'))->toBe(Ra11032::tierKeys());

    $technical = collect($report['tiers'])->firstWhere('key', 'highly_technical');
    expect($technical['holds'])->toBe(0)
        ->and($technical['statutory_working_days'])->toBe(20);
});

it('counts working days, so a weekend is not a delay', function () {
    /*
     * The unit has to be the statute's unit or the breach column is measuring a
     * filing against an allowance counted differently. Friday to Monday is one
     * working day; Ra11032::deadlineFor would place the same interval the same
     * way, which is the property that lets the two be compared without a
     * conversion nobody would remember to apply.
     *
     * 2026-09-11 is a Friday and 2026-09-14 the Monday after it.
     */
    $report = OfficePerformanceAnalytics::compute(officeDataset([
        officeHold('CHO', '2026-09-11', '2026-09-14', 'simple'),
    ]));

    $cho = collect($report['offices'])->firstWhere('code', 'CHO');

    expect($cho['mean_working_days'])->toBe(1.0)
        // One working day is inside the three-day simple allowance, so nothing
        // is breached — which is the whole point of counting this way.
        ->and($cho['breached'])->toBe(0);
});

it('calls a hold a breach only when one office outran the whole allowance', function () {
    /*
     * RA 11032 gives the City one allowance for the transaction and does not
     * divide it between offices, so an office cannot be charged a share of one.
     * What is attributable is the one-sided case: an office that alone took
     * longer than the full allowance put that filing past its deadline whatever
     * everyone else did.
     *
     * Four working days against the three-day simple tier is that case. Four
     * against the seven-day complex tier is not, and the same interval must
     * therefore be counted differently depending on the filing's tier.
     *
     * 2026-09-07 is a Monday; 2026-09-11 the Friday of the same week.
     */
    $breaching = OfficePerformanceAnalytics::compute(officeDataset([
        officeHold('CHO', '2026-09-07', '2026-09-11', 'simple'),
    ]));
    $within = OfficePerformanceAnalytics::compute(officeDataset([
        officeHold('CHO', '2026-09-07', '2026-09-11', 'complex'),
    ]));

    expect(collect($breaching['offices'])->firstWhere('code', 'CHO')['breached'])->toBe(1)
        ->and(collect($breaching['offices'])->firstWhere('code', 'CHO')['breach_rate'])->toBe(100.0)
        ->and(collect($within['offices'])->firstWhere('code', 'CHO')['breached'])->toBe(0);
});

it('leaves an unclassified filing out of the breach rate rather than guessing its tier', function () {
    /*
     * Ra11032::statutoryWorkingDays() falls back to `complex` for an unknown
     * tier, which is right when a live filing needs a deadline and wrong when
     * the question is whether a deadline was met — it would report seven-day
     * breaches against filings that may have been entitled to twenty. The count
     * of what was set aside rides on the payload so the reader can see how much
     * the rate is standing on.
     */
    $report = OfficePerformanceAnalytics::compute(officeDataset([
        officeHold('CHO', '2026-09-01', '2026-09-02', 'simple'),
        officeHold('CHO', '2026-08-03', '2026-09-14', null),
    ]));

    $cho = collect($report['offices'])->firstWhere('code', 'CHO');

    expect($cho['handled'])->toBe(2)
        ->and($cho['classified_holds'])->toBe(1)
        ->and($cho['breached'])->toBe(0)
        ->and($report['unclassified_holds'])->toBe(1);
});

it('counts open work as it stands now, not only what the window covers', function () {
    /*
     * A filing that has sat with an office since before the window opened is the
     * most useful row on the screen, and cutting open work to the window is
     * precisely what would hide it.
     */
    $report = OfficePerformanceAnalytics::compute(officeDataset([], [
        // Long before `window_start`, which is 2025-09-15 in this fixture.
        ['department_code' => 'CHO', 'assigned_at' => '2024-01-08T09:00:00.000000Z'],
        // No assignment date on the row: skipped rather than treated as
        // infinitely old. The column is nullable and the register has such rows.
        ['department_code' => 'CHO', 'assigned_at' => null],
    ]));

    $cho = collect($report['offices'])->firstWhere('code', 'CHO');

    expect($cho['open'])->toBe(2)
        ->and($cho['oldest_open_working_days'])->toBeGreaterThan(300.0);
});

it('says how much test data is inside its own averages', function () {
    /*
     * `businesses` has no provenance column — nothing records whether a row came
     * from a citizen, the seeder or a Playwright run — so the count is a guess
     * from the name and the screen prints the patterns it guessed with. A number
     * that cannot be cleaned must at least be declared; silently averaging over
     * it is the failure this exists to prevent.
     */
    $report = OfficePerformanceAnalytics::build();

    expect($report['data_quality']['patterns'])->not->toBeEmpty()
        ->and($report['data_quality']['test_businesses'])->toBeGreaterThanOrEqual(0)
        ->and($report['data_quality']['total_businesses'])
        ->toBeGreaterThanOrEqual($report['data_quality']['test_businesses']);
});

it('dates its figures and says whether they came from a refresh', function () {
    /*
     * Every analytics screen in this product carries provenance, and this one is
     * no different for being new: these are batch figures, as fresh as the last
     * `analytics:refresh` and no fresher, which a reader has to be told rather
     * than left to infer. See AnalyticsResolver and ComputedAt.tsx.
     */
    $response = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/office-performance')
        ->assertOk();

    expect($response->json('meta'))->toHaveKeys([
        'source', 'engine', 'computed_at', 'stale', 'stale_after_hours',
        'fallback_reason', 'notice', 'definitions',
    ]);

    expect($response->json('meta.definitions'))->not->toBeEmpty(
        'Every info button on the screen renders nothing without these.',
    );
    expect($response->json('data'))->not->toHaveKey('definitions');
});

it('precomputes every window the screen offers', function () {
    /*
     * The rule config/analytics.php states in capitals: a window a screen offers
     * but the refresh does not write is recomputed on every page load and labels
     * itself as computed for that request, which puts a staleness panel over
     * correct, intended operation. These four are OfficePerformancePage's
     * WINDOW_OPTIONS, written down here where PHP can see them.
     */
    $variants = AnalyticsDatasets::variants(AnalyticsDatasets::OFFICE_PERFORMANCE);

    expect(array_column($variants, 'weeks'))->toBe([13, 26, 52, 104]);
});

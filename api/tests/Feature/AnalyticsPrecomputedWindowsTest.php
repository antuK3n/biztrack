<?php

use App\Models\AnalyticsSnapshot;
use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsResolver;

/*
 * ── The warning that fired on correct operation ─────────────────────────────
 *
 * Reported: "remove unnecessary warnings that are too technical (e.g. 'Computed
 * locally, not by R…')".
 *
 * The copy was only half of it. `analytics:refresh` precomputes the parameter
 * combinations in config/analytics.php and nothing else; a request outside them
 * is computed on the spot and says so. Only `dashboard: months=12` was listed
 * while the dashboard's Window dropdown offered five choices — so four of five
 * picks raised an orange "something is degraded" panel over figures that were
 * entirely correct. Processing Time was 2 of 4, Business Growth 2 of 5.
 *
 * A warning that fires on the majority of a screen's own options has stopped
 * carrying information, so rewording alone could not have fixed it. The lists
 * grew to mirror the selectors instead, and these tests are what keeps them
 * mirrored: add an option to a dropdown without adding a line to the config and
 * this file goes red, in the same commit, naming the window that would have
 * started warning.
 *
 * The window numbers below are copied from the four selectors deliberately — a
 * test that read the config to check the config would assert nothing. They are
 * the UI's side of the contract, written down where PHP can see it.
 *
 * ── WHAT R'S REMOVAL CHANGED HERE ───────────────────────────────────────────
 *
 * Two of the four miss reasons were about R being a separate process — "R was
 * switched off" and "R has no endpoint for this view" — and went with it. The
 * two that describe THIS register's own coverage stayed: `not_yet_refreshed` and
 * `window_not_precomputed`.
 *
 * The window checks below no longer reach into AnalyticsResolver::missReason()
 * to make their point. They assert one level lower, on the thing that actually
 * decides a hit: the snapshot KEY. A screen looks a window up by key; the refresh
 * writes a set of keys; the test checks the first is in the second. That also
 * catches a variant configured with the wrong key shape, which the old reason
 * check could not distinguish from one that was simply absent.
 *
 * The property being guarded did NOT change, because it was never about R.
 * A window a screen offers but the refresh does not write is still a window that
 * gets recomputed on every page load and still labels itself as computed for
 * this request.
 *
 * ── AND THE DISTINCTION THAT NEARLY GOT LOST WITH IT ────────────────────────
 *
 * During the removal the two miss reasons were briefly collapsed into one, on the
 * reasoning that with a single engine both mean "computed for this request". They
 * do — but they mean different things to a READER, and the screen renders them
 * differently: `not_yet_refreshed` raises a staleness panel with a Refresh
 * button, `window_not_precomputed` is a quiet line.
 *
 * Collapsed, pressing an ordinary band filter on Renewal Risk raised the panel —
 * a supported option, working exactly as designed, reported as a degradation.
 * That is the fault the whole comment above describes, re-introduced by the fix
 * for something else. The last two tests in this file are what stops it coming
 * back a third time.
 */

/**
 * The snapshot keys `analytics:refresh` will write for a dataset.
 *
 * @return list<string>
 */
function precomputedKeysFor(string $dataset): array
{
    return array_map(
        static fn (array $params): string => AnalyticsSnapshot::keyFor($dataset, $params),
        AnalyticsDatasets::variants($dataset),
    );
}

/**
 * Assert that a dataset precomputes every window the given screen offers.
 *
 * @param  list<array<string, int>>  $offered  one entry per option in the selector
 */
function assertEveryOfferedWindowIsPrecomputed(string $dataset, array $offered, string $selector): void
{
    $written = precomputedKeysFor($dataset);

    foreach ($offered as $params) {
        // in_array rather than expect()->toContain(), which reads its extra
        // arguments as further needles rather than as a failure message — and
        // the message is the whole value of this test. It has to name the window
        // and the config key, or the reader gets "an array does not contain a
        // string" and has to work out which dropdown changed.
        expect(in_array(AnalyticsSnapshot::keyFor($dataset, $params), $written, true))->toBeTrue(
            sprintf(
                '%s offers %s but config/analytics.php does not precompute it, so choosing it '.
                'would recompute on every page load and label itself as computed for that request. '.
                "Add it to analytics.variants.{$dataset}, or stop offering it.",
                $selector,
                json_encode($params),
            ),
        );
    }
}

it('precomputes every window the Analytics Dashboard offers', function () {
    // AnalyticsPage.tsx PERIOD_OPTIONS. This is the selector the client met:
    // four of these five used to warn.
    assertEveryOfferedWindowIsPrecomputed(
        'dashboard',
        [['months' => 3], ['months' => 6], ['months' => 12], ['months' => 24], ['months' => 36]],
        'AnalyticsPage PERIOD_OPTIONS',
    );
});

it('precomputes every window Processing Time Monitoring offers', function () {
    // ProcessingTimePage.tsx WINDOW_OPTIONS.
    assertEveryOfferedWindowIsPrecomputed(
        'processing_time',
        [['weeks' => 13], ['weeks' => 26], ['weeks' => 52], ['weeks' => 104]],
        'ProcessingTimePage WINDOW_OPTIONS',
    );
});

it('precomputes every period Business Growth Analysis offers', function () {
    // BusinessGrowthPage.tsx PERIOD_OPTIONS.
    assertEveryOfferedWindowIsPrecomputed(
        'business_growth',
        [['months' => 3], ['months' => 6], ['months' => 12], ['months' => 24], ['months' => 36]],
        'BusinessGrowthPage PERIOD_OPTIONS',
    );
});

it('precomputes every horizon Renewal Risk offers at its default page', function () {
    /*
     * RenewalRiskPage.tsx HORIZON_OPTIONS at the default, unfiltered first page.
     *
     * This screen is the one that cannot fully follow the rule, and the reason is
     * architectural rather than an oversight: the snapshot key carries the page
     * size, the barangay / level / action filters and the offset, so the key
     * space is the product of five horizons, three page sizes, every barangay,
     * every risk level, every action and every offset. Precomputing that is not a
     * longer list, it is a different design.
     *
     * So the plain horizon change — the only one of those the window selector
     * itself drives — is precomputed, and everything else is computed when it is
     * asked for, in about 90ms. That is why a computed-on-request response can
     * never reach zero here, and therefore why the screen must not shape it as an
     * alert. See ComputedAt.tsx.
     */
    assertEveryOfferedWindowIsPrecomputed(
        'renewal_risk',
        [
            ['days' => 30, 'limit' => 25],
            ['days' => 60, 'limit' => 25],
            ['days' => 90, 'limit' => 25],
            ['days' => 180, 'limit' => 25],
            ['days' => 365, 'limit' => 25],
        ],
        'RenewalRiskPage HORIZON_OPTIONS',
    );
});

it('does not precompute a filtered or resized Renewal Risk request', function () {
    /*
     * The other half of the contract, and the reason the notice must stay quiet.
     * These are correct, permanent, intended on-request computations — not a
     * backlog the Refresh button can clear — and asserting it here stops someone
     * "fixing" the gap by adding filter combinations to the config, which is the
     * different design the note above rules out.
     */
    $written = precomputedKeysFor('renewal_risk');

    expect($written)->not->toContain(
        AnalyticsSnapshot::keyFor('renewal_risk', ['days' => 365, 'limit' => 25, 'barangay_id' => 3]),
    );
    expect($written)->not->toContain(
        AnalyticsSnapshot::keyFor('renewal_risk', ['days' => 365, 'limit' => 100]),
    );
});

it('calls a filtered Renewal Risk request unprecomputed rather than pretending it is stale', function () {
    /*
     * The distinction the screen depends on, asserted on the reason the resolver
     * actually emits rather than on the config it comes from.
     *
     * A filtered request is a correct, permanent, intended on-request
     * computation and renders as the ordinary quiet timestamp;
     * `not_yet_refreshed` means the batch job owes this view a result and the
     * Refresh button will produce it. Collapsing the two either puts an alert
     * back on every filter press, or hides the one case a reader can act on.
     * Both have happened; this is the guard.
     */
    $missReason = new ReflectionMethod(AnalyticsResolver::class, 'missReason');

    // A band filter is the exact case that regressed: a supported option on the
    // screen's own toolbar, which must not raise a staleness panel.
    expect($missReason->invoke(null, 'renewal_risk', ['days' => 365, 'limit' => 25, 'band' => 'low']))
        ->toBe('window_not_precomputed')
        ->and($missReason->invoke(null, 'renewal_risk', ['days' => 365, 'limit' => 25, 'barangay_id' => 3]))
        ->toBe('window_not_precomputed')
        ->and($missReason->invoke(null, 'renewal_risk', ['days' => 365, 'limit' => 100]))
        ->toBe('window_not_precomputed')
        // The horizon on its own, unfiltered: precomputed, so a miss here means
        // the refresh has not run — which is the actionable case.
        ->and($missReason->invoke(null, 'renewal_risk', ['days' => 365, 'limit' => 25]))
        ->toBe('not_yet_refreshed');
});

it('gives the two miss reasons different notices, neither naming an engine', function () {
    /*
     * The notices have to differ, because the states differ: one says a refresh
     * owes this view a result, the other says this view was never in the
     * precomputed set. A PDF reader has no other way to tell them apart — the
     * screen has the panel, the document has only this sentence.
     */
    $noticeFor = new ReflectionMethod(AnalyticsResolver::class, 'noticeFor');

    $stale = $noticeFor->invoke(null, 'not_yet_refreshed');
    $uncovered = $noticeFor->invoke(null, 'window_not_precomputed');

    expect($stale)->not->toBe($uncovered);

    // The coverage one must not imply a refresh would help, because it would not.
    expect($uncovered)->toContain('precompute');
    expect($stale)->toContain('refresh');

    foreach ([$stale, $uncovered] as $notice) {
        expect($notice)->not->toContain(' R ');
        expect($notice)->not->toContain('PHP');
        expect($notice)->not->toContain('BizTrack');
        expect($notice)->not->toContain('engine');
    }
});

it('keeps the provenance fields on every response, notice included', function () {
    /*
     * The screens stopped rendering `notice`; the payload did not stop carrying
     * it. The exported PDF reports embed it, because a document is forwarded and
     * quoted months later by a reader who cannot ask how fresh its figures were.
     *
     * Removing screen copy must never remove a field — that is the one way this
     * change could have broken the honesty guarantee it was careful to keep. The
     * same applies to R's removal: `engine` and `engine_version` are now constant
     * rather than informative, and they still travel, because the reports and the
     * screens read them unconditionally.
     */
    $resolved = AnalyticsResolver::resolve(
        'dashboard',
        ['months' => 3],
        fn () => ['figures' => []],
    );

    expect($resolved['meta'])->toHaveKeys([
        'source', 'engine', 'engine_version', 'computed_at',
        'stale', 'stale_after_hours', 'fallback_reason', 'notice', 'definitions',
    ]);

    // No snapshot exists in a fresh test database, so this is the on-request
    // branch, and that branch is where the fields have to be non-null to be of
    // any use to the report that reads them.
    expect($resolved['meta']['source'])->toBe('local')
        ->and($resolved['meta']['engine'])->toBe('BizTrack')
        ->and($resolved['meta']['engine_version'])->toBeNull()
        ->and($resolved['meta']['fallback_reason'])->toBe('not_yet_refreshed')
        ->and($resolved['meta']['notice'])->not->toBeNull();
});

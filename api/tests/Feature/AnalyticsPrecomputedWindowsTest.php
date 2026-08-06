<?php

use App\Support\AnalyticsResolver;

/*
 * ── The warning that fired on correct operation ─────────────────────────────
 *
 * Reported: "remove unnecessary warnings that are too technical (e.g. 'Computed
 * locally, not by R…')".
 *
 * The copy was only half of it. `analytics:refresh` precomputes the parameter
 * combinations in config/analytics.php and nothing else; a request outside them
 * falls to the PHP port and raises a fallback notice. Only `dashboard: months=12`
 * was listed while the dashboard's Window dropdown offered five choices — so four
 * of five picks raised an orange "something is degraded" panel over figures that
 * were entirely correct. Processing Time was 2 of 4, Business Growth 2 of 5.
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
 */

/**
 * Assert that a dataset precomputes every window the given screen offers.
 *
 * Goes through `AnalyticsResolver::missReason` rather than reading the config
 * back, because `window_not_precomputed` is the behaviour that matters and the
 * snapshot key is what actually decides it — a variant listed with the wrong key
 * shape would satisfy a config comparison and still miss.
 *
 * @param  list<array<string, int>>  $offered  one entry per option in the selector
 */
function assertEveryOfferedWindowIsPrecomputed(string $dataset, array $offered, string $selector): void
{
    $missReason = new ReflectionMethod(AnalyticsResolver::class, 'missReason');

    foreach ($offered as $params) {
        $reason = $missReason->invoke(null, $dataset, $params);

        expect($reason)->not->toBe(
            'window_not_precomputed',
            sprintf(
                '%s offers %s but config/analytics.php does not precompute it, so choosing it '.
                'would raise a fallback notice over correct figures. Add it to '.
                "analytics.variants.{$dataset}, or stop offering it.",
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
     * itself drives — is precomputed, and everything else is answered live. That
     * is why `window_not_precomputed` can never reach zero, and therefore why the
     * screen must not shape it as an alert. See ComputedAt.tsx.
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

it('still calls a filtered Renewal Risk request unprecomputed rather than pretending it is stale', function () {
    /*
     * The distinction the screen depends on. A filtered request is a correct,
     * permanent, intended local computation and renders as the ordinary quiet
     * timestamp; `not_yet_refreshed` means the batch job owes this view a result
     * and the Refresh button will produce it. Collapsing the two would either
     * put an alert back on every filter, or hide the one case a reader can act
     * on.
     */
    $missReason = new ReflectionMethod(AnalyticsResolver::class, 'missReason');

    expect($missReason->invoke(null, 'renewal_risk', ['days' => 365, 'limit' => 25, 'barangay_id' => 3]))
        ->toBe('window_not_precomputed')
        ->and($missReason->invoke(null, 'renewal_risk', ['days' => 365, 'limit' => 100]))
        ->toBe('window_not_precomputed')
        // The horizon on its own, unfiltered: precomputed, so a miss here means
        // the refresh has not run — which is the actionable case.
        ->and($missReason->invoke(null, 'renewal_risk', ['days' => 365, 'limit' => 25]))
        ->toBe('not_yet_refreshed');
});

it('keeps the provenance fields on every response, notice included', function () {
    /*
     * The screens stopped rendering `notice`; the payload did not stop carrying
     * it. The exported PDF reports embed it verbatim, because a document is
     * forwarded and quoted months later by a reader who cannot ask which of the
     * two implementations produced the figures. AnalyticsParityTest reads
     * `source` and `engine` for the same reason.
     *
     * Removing screen copy must never remove a field — that is the one way this
     * change could have broken the honesty guarantee it was careful to keep.
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

    // No snapshot exists in a fresh test database, so this is the local branch,
    // and the local branch is where the fields have to be non-null to be of any
    // use to the report that reads them.
    expect($resolved['meta']['source'])->toBe('local')
        ->and($resolved['meta']['engine'])->toBe('PHP')
        ->and($resolved['meta']['fallback_reason'])->not->toBeNull()
        ->and($resolved['meta']['notice'])->not->toBeNull();
});

it('keeps naming the engine in the notice, because the printed report is its only witness', function () {
    /*
     * `notice` is written for paper, not for a dashboard header, and the wording
     * is engine-specific on purpose. If a future change reworded these for a
     * screen, the PDF would lose the only vocabulary that tells its reader which
     * implementation ran — and it is the surface where that genuinely cannot be
     * asked after the fact.
     */
    $noticeFor = new ReflectionMethod(AnalyticsResolver::class, 'noticeFor');

    foreach (['no_r_endpoint', 'r_disabled', 'window_not_precomputed', 'not_yet_refreshed'] as $reason) {
        expect($noticeFor->invoke(null, $reason))->toContain('R');
    }
});

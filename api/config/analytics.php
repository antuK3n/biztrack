<?php

/*
 * Analytics computation settings.
 *
 * Statistics are computed by BizTrack itself, in PHP, by the builders in
 * app/Support. They used to be computed by a separate R (plumber) service that
 * Laravel pushed row sets to; R has been removed and its settings with it. If
 * R_ANALYTICS_ENABLED, R_ANALYTICS_URL or the R timeouts are still set in a .env
 * somewhere, nothing reads them any more — they are inert, not honoured.
 *
 * What did NOT change is that analytics are computed in BATCH rather than per
 * request. `analytics:refresh` walks the registry and stores a snapshot per
 * window; page loads read the snapshot. That was the client's explicit choice
 * and it long outlives the engine that used to do the arithmetic.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Precomputed variants
    |--------------------------------------------------------------------------
    |
    | Statistics are computed in batch, so they can only be served for parameter
    | combinations that were actually computed — control limits for a 52-week
    | window cannot be sliced out of a 26-week result. `analytics:refresh` walks
    | these lists and stores one snapshot per entry.
    |
    | A request outside these combinations is computed on the spot and says so.
    | The figures are the same either way — one implementation computes both — so
    | adding a window here buys a faster page load, not a better number.
    |
    | THESE LISTS MIRROR THE WINDOW SELECTORS THE SCREENS ACTUALLY OFFER. That is
    | the rule, and it is worth stating because the lists drifted away from it
    | once already and the cost was not a wrong number — it was a warning.
    |
    | Only `dashboard: months=12` used to be here while the dashboard's Window
    | dropdown offered five choices, so four of five picks missed the snapshot,
    | were computed on request and raised a notice about it. The figures were
    | right; the screen called correct, intended operation a degradation, on the
    | majority of its own options. The fix is here, not in the copy: if a screen
    | offers a window, this file precomputes it.
    |
    | So when a selector gains an option, it gains a line here in the same
    | commit. If precomputing it is not wanted, the option should not be offered.
    |
    | Renewal Risk's filters were the one selector that could not follow the
    | rule; that screen is gone (checklist 2026-09-27, item 6). A computed-on-
    | request notice can still appear for a request outside these lists and must
    | still not be shaped like an alert.
    |
    */

    'variants' => [
        'dashboard' => [
            // Trailing window in months for the rate and mean panels. The KPI,
            // volume and decision-outcome panels are YTD / this-month whatever
            // this is set to — see DashboardAnalytics' note on windows.
            //
            // Mirrors AnalyticsPage PERIOD_OPTIONS.
            ['months' => 3],
            ['months' => 6],
            ['months' => 12],
            ['months' => 24],
            ['months' => 36],
        ],

        'processing_time' => [
            // weeks — mirrors ProcessingTimePage WINDOW_OPTIONS.
            ['weeks' => 13],
            ['weeks' => 26],
            ['weeks' => 52],
            ['weeks' => 104],
        ],

        'office_performance' => [
            // weeks — mirrors OfficePerformancePage WINDOW_OPTIONS, which are
            // deliberately the same four Processing Time offers. The two screens
            // are read one after the other by one person and a window on one
            // that does not exist on the other invites the reader to compare
            // figures over different spans without noticing.
            ['weeks' => 13],
            ['weeks' => 26],
            ['weeks' => 52],
            ['weeks' => 104],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Staleness
    |--------------------------------------------------------------------------
    |
    | Snapshots never expire on their own — a stale figure with an honest
    | timestamp beats no figure at all, and quietly recomputing because a refresh
    | was skipped would hide that the refresh was skipped. This threshold only
    | decides when the UI calls a snapshot old.
    |
    */

    'stale_after_hours' => (int) env('ANALYTICS_STALE_AFTER_HOURS', 25),

];

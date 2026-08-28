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
    | The one selector that cannot follow the rule is Renewal Risk's — see the
    | note on that dataset below. It is the reason a computed-on-request notice
    | can never be driven to zero and therefore must not be shaped like an alert.
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

        'business_growth' => [
            // months — mirrors BusinessGrowthPage PERIOD_OPTIONS.
            ['months' => 3],
            ['months' => 6],
            ['months' => 12],
            ['months' => 24],
            ['months' => 36],
        ],

        /*
         * days ahead, rows in the watchlist table.
         *
         * The horizons mirror RenewalRiskPage HORIZON_OPTIONS. The row count
         * does not, and cannot: the snapshot key carries the page size, the
         * barangay / level / action filters and the pagination offset
         * (AnalyticsController::renewalRisk), so the key space is the product of
         * five horizons, three page sizes, every barangay, every risk level,
         * every action and every offset. That is thousands of combinations, each
         * storing a snapshot of up to ~1MB — precomputing them is not a bigger
         * list, it is a different architecture.
         *
         * So this dataset precomputes the five horizons at the default,
         * unfiltered first page, and every other combination is computed when it
         * is asked for. Renewal risk builds in about 90ms, so that is a page load
         * nobody notices. It is not a degradation and the screens must not
         * present it as one.
         */
        /*
         * The fitted model. ONE variant, and that is the whole list.
         *
         * The horizon selector on the screen changes which permits get an
         * estimate, not which cycles the model is fitted to — the training set
         * is the whole of permit history and does not move when a reader picks
         * 30 days instead of 365. Precomputing five horizons would therefore
         * refit the same regression five times over the same rows to produce
         * five identical coefficient tables, at roughly two seconds and 2.4MB of
         * JSON each.
         *
         * So the snapshot is the model at the default horizon, and the screen
         * reads that snapshot whatever the watchlist beside it is showing. The
         * estimates it carries cover the full year, which is a superset of every
         * shorter horizon a reader can choose.
         */
        'renewal_model' => [
            ['days' => 365, 'limit' => 25],
        ],

        'renewal_risk' => [
            ['days' => 30, 'limit' => 25],
            ['days' => 60, 'limit' => 25],
            ['days' => 90, 'limit' => 25],
            ['days' => 180, 'limit' => 25],
            ['days' => 365, 'limit' => 25],
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

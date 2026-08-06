<?php

/*
 * Analytics computation settings.
 *
 * R is the statistics engine and it stays a separate program (see
 * docs/r-integration-spec.md). Laravel owns all SQL, pushes row sets to the
 * plumber service, and persists what comes back; page loads read the persisted
 * result and never call R.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | R (plumber) service
    |--------------------------------------------------------------------------
    |
    | The default host is deliberately 127.0.0.1: plumber has no authentication
    | of its own, so anything that can reach it can read register data. The live
    | Cloudflare tunnel forwards only the web port, and this must not become the
    | exception. Point R_ANALYTICS_URL somewhere else only behind a network you
    | control.
    |
    | The timeouts exist because `analytics:refresh` is the only caller and a
    | hung R process must fail the refresh rather than hang it forever. Nothing
    | on the request path waits on these.
    |
    */

    'r' => [
        'enabled' => filter_var(env('R_ANALYTICS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'base_url' => rtrim((string) env('R_ANALYTICS_URL', 'http://127.0.0.1:8787'), '/'),

        // Generous: the SPC and lifecycle endpoints fit a year of review rows.
        'timeout' => (float) env('R_ANALYTICS_TIMEOUT', 60),

        // Tight: if plumber is not up, we want to know in a second, not a minute.
        'connect_timeout' => (float) env('R_ANALYTICS_CONNECT_TIMEOUT', 2),
    ],

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
    | A request outside these combinations is computed locally and says so, which
    | is the honest outcome: adding a window here is what makes it R-backed.
    |
    | THESE LISTS MIRROR THE WINDOW SELECTORS THE SCREENS ACTUALLY OFFER. That is
    | the rule, and it is worth stating because the lists drifted away from it
    | once already and the cost was not a wrong number — it was a warning.
    |
    | Only `dashboard: months=12` used to be here while the dashboard's Window
    | dropdown offered five choices, so four of five picks missed the snapshot,
    | fell to the PHP port and raised a fallback notice. The figures were right;
    | the screen called correct, intended operation a degradation, on the
    | majority of its own options. The fix is here, not in the copy: if a screen
    | offers a window, this file precomputes it.
    |
    | So when a selector gains an option, it gains a line here in the same
    | commit. If precomputing it is not wanted, the option should not be offered.
    |
    | The one selector that cannot follow the rule is Renewal Risk's — see the
    | note on that dataset below. It is the reason the fallback notice can never
    | be driven to zero and therefore must not be shaped like an alert.
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
         * a separate R round trip of up to ~1MB — precomputing them is not a
         * bigger list, it is a different architecture.
         *
         * So this dataset precomputes the five horizons at the default, unfiltered
         * first page, and everything else is answered by the PHP port. That is
         * not a degradation and the screens must not present it as one.
         */
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
    | timestamp beats no figure at all, and silently falling back to a second
    | implementation because a refresh was skipped would hide that the refresh
    | was skipped. This threshold only decides when the UI calls a snapshot old.
    |
    */

    'stale_after_hours' => (int) env('ANALYTICS_STALE_AFTER_HOURS', 25),

];

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
    */

    'variants' => [
        'processing_time' => [
            // weeks
            ['weeks' => 26],
            ['weeks' => 52],
        ],

        'business_growth' => [
            // months
            ['months' => 12],
            ['months' => 24],
            ['months' => 36],
        ],

        'renewal_risk' => [
            // days ahead, rows in the watchlist table
            ['days' => 90, 'limit' => 25],
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

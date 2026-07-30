<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Nightly permit-expiry scan (master plan S6; R integration spec §3). Runnable
 * manually for the demo — it is idempotent, so running it by hand is safe.
 *
 * This is what writes the expiry-reminder ledger (`permit_expiry_notices`) that
 * "Reminders Sent" on the Renewal Risk screen counts. Reminders fire at 30, 15,
 * 7 and 1 day before expiry, once each per permit; ScanPermits documents how the
 * ledger makes that hold across runs.
 *
 * withoutOverlapping because the ledger insert is what authorises a send: two
 * concurrent scans would collide on its unique index rather than double-send,
 * but losing a run to a constraint violation is not a useful outcome either.
 */
Schedule::command('biztrack:scan-permits')->daily()->withoutOverlapping();

/*
 * Push register rows to the R statistics service and store what it computes.
 *
 * This is what makes the analytics screens R-backed: they read the stored
 * snapshot and never call R themselves, so a page load costs one indexed read
 * and cannot be slowed or broken by R being slow or down. Nothing breaks if this
 * does not run — the screens keep serving the last snapshot and say how old it
 * is, and fall back to the PHP port for anything with no snapshot at all. What
 * breaks is freshness, which is why ANALYTICS_STALE_AFTER_HOURS is 25: a figure
 * that has missed a nightly run gets flagged rather than shown as current.
 *
 * Deliberately after scan-permits. That scan writes the expiry-reminder ledger
 * the Renewal Risk screen counts "Reminders Sent" from, so refreshing first would
 * report every night's figure a day late.
 *
 * withoutOverlapping because a refresh spans a year of review history plus the
 * full renewal watchlist, and two concurrent passes would only queue register
 * queries behind each other for the same result.
 */
Schedule::command('analytics:refresh')->dailyAt('03:00')->withoutOverlapping();

// Daily database + uploads backup (R32; spatie/laravel-backup).
Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:run')->daily()->at('02:00');

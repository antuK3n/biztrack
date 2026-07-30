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

// Daily database + uploads backup (R32; spatie/laravel-backup).
Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:run')->daily()->at('02:00');

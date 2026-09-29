<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('biztrack:scan-permits')->daily()->withoutOverlapping();

Schedule::command('analytics:refresh')->dailyAt('03:00')->withoutOverlapping();

Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:run')->daily()->at('02:00');

/*
 * Online payments nobody has confirmed yet (docs/payment-gateway.md). Every
 * minute, but each payment carries its own backoff, so this asks KwikPay about
 * only the ones that are due. Runs in both payment modes: a KwikPay payment
 * made before the switch went off still has to be settled.
 */
Schedule::command('biztrack:reconcile-payments')->everyMinute()->withoutOverlapping();

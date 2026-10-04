<?php

use App\Jobs\QueueHeartbeat;
use App\Support\Heartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The app's clock is UTC, so a bare ->dailyAt() runs eight hours later in
 * Malabon: "02:00" was 10 am, in the middle of office hours. The overnight
 * jobs are pinned to Manila time instead.
 *
 * Except the permit scan, on purpose. It decides "today" from the UTC clock
 * (ScanPermits), and UTC midnight is 8 am Manila: the first moment the Manila
 * date and the UTC date agree for the rest of the working day. Moved to
 * Manila midnight it would see yesterday's date and expire permits a day late.
 */
Schedule::command('biztrack:scan-permits')->daily()->withoutOverlapping();

Schedule::command('analytics:refresh')->dailyAt('03:00')->timezone('Asia/Manila')->withoutOverlapping();

Schedule::command('backup:clean')->dailyAt('01:30')->timezone('Asia/Manila');
Schedule::command('backup:run')->dailyAt('02:00')->timezone('Asia/Manila');

/*
 * Online payments nobody has confirmed yet (docs/payment-gateway.md). Every
 * minute, but each payment carries its own backoff, so this asks KwikPay about
 * only the ones that are due. Runs in both payment modes: a KwikPay payment
 * made before the switch went off still has to be settled.
 */
Schedule::command('biztrack:reconcile-payments')->everyMinute()->withoutOverlapping();

/*
 * Heartbeats for the Debug page's Health section (App\Support\SystemHealth):
 * one written by the scheduler itself, and one put on the queue for a worker
 * to write. If either goes stale, that process has stopped — which nothing
 * else in the app would say until a payment failed to settle or an e-mail
 * never left.
 */
Schedule::call(fn () => Heartbeat::beat(Heartbeat::SCHEDULER))->everyMinute()->name('health:scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyMinute();

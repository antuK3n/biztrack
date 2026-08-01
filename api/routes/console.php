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

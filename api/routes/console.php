<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly permit-expiry scan (master plan S6). Runnable manually for the demo.
Schedule::command('biztrack:scan-permits')->daily();

// Daily database + uploads backup (R32; spatie/laravel-backup).
Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:run')->daily()->at('02:00');

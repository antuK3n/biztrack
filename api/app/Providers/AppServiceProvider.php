<?php

namespace App\Providers;

use App\Services\Sms\LogSmsChannel;
use App\Services\Sms\SmsChannel;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SMS channel driver — swap via SMS_DRIVER env (only `log` in the demo).
        $this->app->bind(SmsChannel::class, function () {
            return match (config('services.sms.driver', env('SMS_DRIVER', 'log'))) {
                default => new LogSmsChannel,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

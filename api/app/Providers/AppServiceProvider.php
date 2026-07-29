<?php

namespace App\Providers;

use App\Services\Sms\LogSmsChannel;
use App\Services\Sms\SmsChannel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // IP-level brute-force guard on /auth/login, on top of the per-account
        // 5-attempt lockout in AuthController. Disabled for the test suite so
        // seeded-login helpers do not trip it.
        RateLimiter::for('login', function (Request $request) {
            return $this->app->runningUnitTests()
                ? Limit::none()
                : Limit::perMinute(10)->by($request->ip());
        });
    }
}

<?php

namespace App\Providers;

use App\Services\Sms\LogSmsChannel;
use App\Services\Sms\SmsChannel;
use App\Services\Sms\SmsGateChannel;
use App\Support\ReportViews;
use App\Support\SystemSwitches;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // System switches read once per request or job (SystemSwitches::stored).
        $this->app->scoped(SystemSwitches::MEMO, fn () => new \ArrayObject);

        // SMS channel driver, chosen by SMS_DRIVER: `log` (default) or `smsgate`.
        $this->app->bind(SmsChannel::class, function () {
            return match (config('services.sms.driver', env('SMS_DRIVER', 'log'))) {
                'smsgate' => SmsGateChannel::fromConfig(),
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

        /*
         * The Forgot Password link opens the WEB app's reset page.
         *
         * Laravel builds it from a route named `password.reset`, and this API
         * has none — the page is web/src/pages/auth/ResetPasswordPage.tsx at
         * /reset-password, which reads `token` and `email` off the query
         * string and posts them to /auth/reset-password. Without this every
         * registered address answered 500 while an unknown one answered 200:
         * nobody could reset a password, and the status told a stranger which
         * addresses had accounts. FRONTEND_URL is the base the permit QR and
         * the owner-update mail already use.
         */
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]));

        // The reporting views step aside while migrations run, so a later
        // `->change()` can alter the tables they read. See ReportViews.
        Event::listen(MigrationsStarted::class, fn (MigrationsStarted $e) => ReportViews::suspend($e));
        Event::listen(MigrationsEnded::class, fn (MigrationsEnded $e) => ReportViews::resume($e));
    }
}

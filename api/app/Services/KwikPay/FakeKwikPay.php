<?php

namespace App\Services\KwikPay;

/**
 * Whether the stand-in KwikPay (App\Http\Controllers\FakeKwikPayController)
 * may exist on this server.
 *
 * Two locks, both required:
 *
 *   1. APP_ENV is `local` or `testing` — never `production`, never `staging`,
 *      never anything unrecognised. The environment is the one thing a
 *      production deploy is guaranteed to set differently.
 *   2. KWIKPAY_FAKE=true — so a developer's machine does not grow a payment
 *      page that marks things paid merely because it is a developer's machine.
 *
 * Routes are registered only when this is true (routes/api.php), AND the
 * controller checks it again on every request, so a cached route file built on
 * a laptop cannot carry the fake onto a server either.
 */
class FakeKwikPay
{
    public static function available(): bool
    {
        return app()->environment(['local', 'testing'])
            && ! app()->isProduction()
            && (bool) config('payments.kwikpay.fake', false);
    }
}

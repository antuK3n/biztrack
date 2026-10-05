<?php

/*
 * How owners pay (docs/payment-gateway.md).
 *
 * Two modes:
 *
 *   simulated  The payment completes the instant the owner presses Pay. No money
 *              moves. This is what the prototype has always done and what runs
 *              during a presentation.
 *   kwikpay    The owner is sent to KwikPay (GCash, Maya, QR Ph, GoTyme) and the
 *              payment completes only when KwikPay confirms it — by a signed
 *              callback or when we ask.
 *
 * PAYMENT_GATEWAY is only the DEFAULT. The live value is the `payment_gateway`
 * row in `settings`, set by the super admin's API or by
 * `php artisan biztrack:payment-gateway on|off`, so it can change on a running
 * server. See App\Support\PaymentMode.
 */

/*
 * `?:` rather than env()'s default argument where a blank would hurt:
 * .env.example lists these keys with empty values, and env() returns "" for
 * `KEY=`, not the default — a copied example file would otherwise leave the
 * base URL blank and the timeout at zero.
 */
return [

    'default' => env('PAYMENT_GATEWAY') ?: 'simulated',

    'kwikpay' => [
        // The KwikPay gateway BizTrack's merchant account is on. It answers the
        // same /api/transfer, /api/query and /api/me as KwikPay's own Back
        // Office (pay4-kwikpay.jd.management). Point it at the fake (below) for
        // a demo without credentials.
        'base_url' => env('KWIKPAY_BASE_URL') ?: 'https://payment-gateway-kwgu.onrender.com',

        // The merchant is an account name, not a secret: it travels in the
        // clear in every request and callback. The key is the secret. It signs
        // every request and verifies every callback, so it lives only in the
        // server's env and must never reach the browser or a log.
        'merchant' => env('KWIKPAY_MERCHANT') ?: 'harson-tech',
        'key' => env('KWIKPAY_KEY'),

        // "1"–"12" in KwikPay's docs, and which are enabled is the gateway's to
        // say. harson-tech's account takes any of "1"–"4".
        'payment_type' => env('KWIKPAY_PAYMENT_TYPE') ?: '1',

        /*
         * The test charge's amount (e.g. "50.00"; ₱50.00 when empty), and the
         * charge switch's DEFAULT: until somebody sets `kwikpay_charge` in
         * `settings`, a positive amount here means KwikPay collects it instead
         * of the bill, and empty means the full bill. Once the switch is set —
         * from the Debug page, the admin API or
         * `php artisan biztrack:payment-gateway test-charge|full-charge` — the
         * switch decides (App\Support\PaymentMode::charge). The bill, the
         * payment record and the receipt keep the real assessed amount either
         * way. See docs/payment-gateway.md.
         */
        'charge_override' => env('KWIKPAY_CHARGE_OVERRIDE') ?: null,

        /*
         * Where KwikPay reaches us. The callback has to be a PUBLIC address, so
         * APP_URL (usually localhost) is only the fallback; set this to the
         * tunnel or the server's real address, without the /api/v1 part.
         */
        'callback_base_url' => env('KWIKPAY_CALLBACK_BASE_URL'),

        /*
         * Optional allowlist for callbacks, comma-separated. KwikPay's docs name
         * 34.21.238.122 as the platform's outbound address. Empty = not enforced
         * (the default): the signature is ALWAYS checked, and that is what
         * actually proves a callback came from someone holding our key. Behind a
         * tunnel or proxy the caller's address is the proxy's, so enforcing this
         * without trusted proxies configured would refuse every real callback.
         */
        'callback_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('KWIKPAY_CALLBACK_IPS', ''))
        ))),

        // Seconds to wait on KwikPay before treating a request as timed out.
        'timeout' => (int) (env('KWIKPAY_TIMEOUT') ?: 15),

        /*
         * A stand-in KwikPay served by this app, for demos and the e2e suite
         * (App\Http\Controllers\FakeKwikPayController). Only ever mounted when
         * APP_ENV is local or testing AND this is true — see
         * App\Services\KwikPay\FakeKwikPay::available().
         */
        'fake' => (bool) env('KWIKPAY_FAKE', false),
    ],

];

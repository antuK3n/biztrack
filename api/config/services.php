<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * SMS (master plan §5.5). `log` writes to storage/logs/sms.log and is the
     * default. `smsgate` sends through the Android phone gateway; see
     * App\Services\Sms\SmsGateChannel.
     */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'gateway' => [
            // Blank or absent: sms-gate.app's public cloud server.
            'url' => env('SMS_GATEWAY_URL'),
            'username' => env('SMS_GATEWAY_USERNAME'),
            'password' => env('SMS_GATEWAY_PASSWORD'),
            // Seconds. Sent from the queue worker, never a request, but an
            // offline phone should not hold the worker for long.
            'timeout' => 10,
        ],
    ],

    /*
     * Cloudflare Turnstile — the sign-in captcha.
     *
     * Chosen over reCAPTCHA and hCaptcha for a government service: it is free
     * at any volume, sets no advertising cookie, and presents no image puzzle,
     * so it does not put a visual task between a citizen and their permit.
     * WCAG 2.1 AA is the stated target (PRODUCT.md) and "identify the traffic
     * lights" fails it for anyone who cannot see them.
     *
     * NO KEY = NO CAPTCHA, on purpose. `App\Support\Turnstile::enabled()` is
     * false when the secret is blank, and the widget on the sign-in page is a
     * no-op when the site key is blank. That is what keeps local development
     * and the Playwright suite working without credentials, and it is also the
     * honest failure mode: a captcha that is half-configured should let people
     * in, not lock the city out of its own permit system.
     *
     * Both keys come from the Cloudflare dashboard and belong in env:
     *   api/.env   TURNSTILE_SECRET_KEY=…
     *   web/.env   VITE_TURNSTILE_SITE_KEY=…
     * The site key is public by design (it ships in the page); the secret is
     * not and is only ever read here.
     */
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env(
            'TURNSTILE_VERIFY_URL',
            'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        ),
        // Seconds to wait on Cloudflare before giving up. Short: this sits in
        // front of every sign-in, and a slow captcha is a slow sign-in.
        'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),
    ],

    /*
     * Gemini — the model the chatbot asks first (App\Services\GeminiChatbot).
     *
     * Flash-Lite, Ken's choice of 5 October 2026: the cheapest model that
     * answers in the owner's own language, on a free-tier key (roughly 15–30
     * requests a minute and a few hundred to ~1,500 a day). GEMINI_MODEL moves
     * it without a deploy.
     *
     * NO KEY = THE RULE-BASED BOT, on purpose, like Turnstile above: with
     * GEMINI_KEY blank nothing is sent anywhere and ChatbotResponder answers
     * every question, exactly as it did before Gemini. Any failure with a key
     * set — timeout, HTTP error, quota, a blocked or unreadable reply — ends
     * the same way, silently. The key travels in the x-goog-api-key header,
     * never in the URL, so it cannot land in an access log.
     *
     *   api/.env   GEMINI_KEY=…
     *
     * `timeout` is short because the owner is watching the typing dots while
     * it runs; the rules answer is already in hand when it expires.
     * `base_url` is the API root, overridable only so a stand-in can answer
     * on a test stack.
     */
    'gemini' => [
        'key' => env('GEMINI_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 8),
    ],

];

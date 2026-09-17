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

    // Simulated SMS channel (master plan §5.5). Only `log` in the demo.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
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

];

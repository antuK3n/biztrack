<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side check of a Cloudflare Turnstile token (checklist item #62).
 *
 * The widget on the sign-in page produces a token; this class asks Cloudflare
 * whether that token is real. A captcha verified only in the browser is
 * decoration — the token is a form field like any other, and a script posting
 * straight to /auth/login never renders the widget at all. So the only check
 * that counts is this one, and it runs inside AuthController::login before the
 * password is looked at.
 *
 * See config/services.php for why Turnstile rather than reCAPTCHA, and for the
 * no-key-no-captcha rule that keeps local dev and the Playwright suite running.
 */
class Turnstile
{
    /**
     * Configured at all? Blank secret means the captcha is not in play.
     *
     * Deliberately keyed on the SECRET rather than on a separate on/off flag.
     * A flag can be true while the credential is missing, and that state fails
     * every sign-in with "captcha check failed" for a reason nobody typing a
     * password can see or fix.
     */
    public static function enabled(): bool
    {
        return filled(config('services.turnstile.secret'));
    }

    /**
     * True when this sign-in may proceed.
     *
     * ── What happens when Cloudflare is unreachable: FAIL OPEN ───────────────
     *
     * A timeout, a DNS failure, a 5xx from siteverify — anything that means we
     * could not get an answer — is treated as a pass, and logged at warning so
     * the outage is visible in the log rather than only in the support queue.
     *
     * That is a deliberate trade, not an oversight. Fail-closed would mean an
     * outage at Cloudflare stops every business owner and every BPLO officer
     * from signing in to the City of Malabon's permit system, for a control
     * that is not the thing protecting the account: the password is, and behind
     * it sit the per-account 5-attempt lockout and the per-IP limiter in
     * AppServiceProvider, neither of which depends on a third party being up.
     * Turnstile raises the cost of automated guessing; it is not the lock.
     *
     * A REJECTION is different from an outage and is not forgiven. A token
     * Cloudflare positively says is invalid, expired, already redeemed — or a
     * request that carries no token at all while the captcha is configured —
     * fails here. Those are the cases a captcha exists for.
     *
     * If the threat picture ever changes (a sustained credential-stuffing run
     * against this endpoint), flip the two `return true`s below to false and
     * say so in the release note: signing in will then stop when Cloudflare
     * does, and that has to be a decision somebody makes on purpose.
     */
    public static function passes(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout', 5))
                ->post((string) config('services.turnstile.verify_url'), array_filter([
                    'secret' => config('services.turnstile.secret'),
                    'response' => $token,
                    // Cloudflare scores the token against the address that
                    // solved it. Optional, and omitted when we have none rather
                    // than sent empty, which siteverify reads as a mismatch.
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $e) {
            Log::warning('Turnstile unreachable; allowing the sign-in.', [
                'exception' => $e->getMessage(),
            ]);

            return true;
        }

        if ($response->failed()) {
            Log::warning('Turnstile answered with an error; allowing the sign-in.', [
                'status' => $response->status(),
            ]);

            return true;
        }

        return $response->json('success') === true;
    }
}

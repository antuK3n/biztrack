<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Cloudflare Turnstile in front of the sign-in [checklist item #62].
 *
 * Two rules matter more than the rest, and they pull in opposite directions:
 *
 *   1. With a key configured, the token is checked SERVER-side. A captcha
 *      verified only in the browser is decoration — the token is a form field,
 *      and a script posting straight to /auth/login never renders the widget.
 *   2. With NO key configured, the captcha is not in play at all. That is what
 *      keeps local development, the mock API and the Playwright suite working
 *      without credentials, and it is the honest failure mode for a
 *      half-configured control on a government service.
 *
 * `Http::fake()` stands in for Cloudflare throughout. The secret is set in the
 * tests that need one, so the default config — no key — is what the rest of the
 * suite runs against, which is the same thing a developer has locally.
 */

it('does not ask for a captcha when no key is configured', function () {
    expect(config('services.turnstile.secret'))->toBeEmpty();

    Http::fake();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertOk();

    // And Cloudflare was never called. An unconfigured captcha that still made
    // a network request would put a third party in front of every sign-in for
    // no benefit at all.
    Http::assertNothingSent();
});

it('verifies the token with Cloudflare when a key is configured', function () {
    config(['services.turnstile.secret' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
        'captcha_token' => 'a-token-the-widget-produced',
    ])->assertOk();

    Http::assertSent(fn ($request) => $request['secret'] === 'test-secret'
        && $request['response'] === 'a-token-the-widget-produced');
});

it('refuses a sign-in whose captcha token Cloudflare rejects', function () {
    config(['services.turnstile.secret' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
        'captcha_token' => 'forged',
    ])->assertStatus(422)->assertJsonValidationErrors('captcha_token');
});

it('refuses a sign-in that carries no captcha token at all when one is required', function () {
    // This is the case the whole feature exists for: a script posting straight
    // at the endpoint, which never renders a widget and so never has a token.
    config(['services.turnstile.secret' => 'test-secret']);
    Http::fake();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertStatus(422)->assertJsonValidationErrors('captcha_token');
});

it('checks the captcha before the password, so a bot cannot spend an account\'s attempts', function () {
    /*
     * Order is load-bearing. If the password were checked first, an attacker
     * who cannot solve the challenge could still drive any known address to its
     * five-attempt lockout — turning a brute-force control into a way to lock
     * a named business owner or officer out for fifteen minutes at a time.
     */
    config(['services.turnstile.secret' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'not-the-password',
        'portal' => 'public',
        'captcha_token' => 'forged',
    ])->assertStatus(422)->assertJsonValidationErrors('captcha_token');

    expect(User::where('email', 'owner@biztrack.local')->firstOrFail()->failed_login_attempts)
        ->toBe(0);
});

it('says nothing about the account when the captcha fails', function () {
    // The check runs before any account is looked up, so the reply is the same
    // for an address that exists and one that does not. A captcha that answered
    // differently for a real address would be an enumeration oracle bolted on
    // in front of the one item #63 just closed.
    config(['services.turnstile.secret' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    $known = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
        'captcha_token' => 'forged',
    ]);

    $unknown = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'biztrack1',
        'portal' => 'public',
        'captcha_token' => 'forged',
    ]);

    expect($known->status())->toBe($unknown->status())
        ->and($known->json('errors'))->toBe($unknown->json('errors'));
});

/*
 * ── Unreachable Cloudflare: FAIL OPEN, deliberately ─────────────────────────
 *
 * The alternative was considered and rejected. Fail-closed means an outage at
 * Cloudflare stops every business owner and every BPLO officer from signing in
 * to the City of Malabon's permit system — for a control that is not the thing
 * protecting the account. The password is, and behind it sit the per-account
 * five-attempt lockout and the per-IP limiter, neither of which depends on a
 * third party being up. Turnstile raises the cost of automated guessing; it is
 * not the lock.
 *
 * A REJECTION is not an outage and is not forgiven — the tests above pin that.
 * These two pin the other half, so that flipping the policy has to be a
 * deliberate act that turns a test red rather than a quiet change of behaviour
 * the first time siteverify has a bad day.
 */
it('lets a sign-in through when Cloudflare cannot be reached', function () {
    config(['services.turnstile.secret' => 'test-secret']);
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
        'captcha_token' => 'a-token-nobody-could-check',
    ])->assertOk();
});

it('lets a sign-in through when Cloudflare answers with an error', function () {
    config(['services.turnstile.secret' => 'test-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response('', 503)]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
        'captcha_token' => 'a-token-nobody-could-check',
    ])->assertOk();
});

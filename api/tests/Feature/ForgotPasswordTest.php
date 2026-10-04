<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/*
 * Forgot Password, end to end (owner-sign-in row 44, account-restriction
 * row 31).
 *
 * `Password::sendResetLink` builds its link from a route named
 * `password.reset`, and this API has none: the reset page is the web app's
 * /reset-password. So every REGISTERED address answered 500 while an unknown
 * one answered 200 — nobody could reset a password, and the status told a
 * stranger which addresses had accounts.
 */

it('answers a registered and an unknown address with the same sentence', function () {
    // No Notification::fake(): the mail is really built, as a real mailer would.
    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@biztrack.local']);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

    $known->assertOk()->assertJsonPath('message', 'If that email is registered, a reset link is on its way.');
    $unknown->assertOk();
    expect($known->json())->toBe($unknown->json());
});

it('mails a link to the web reset page, and the link resets the password', function () {
    Notification::fake();
    config(['app.frontend_url' => 'https://permits.example.test/']);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@biztrack.local'])->assertOk();

    $url = null;
    Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $mail) use ($owner, &$url) {
        $url = $mail->toMail($owner)->actionUrl;

        return true;
    });

    // The page web/src/App.tsx routes at /reset-password, reading ?token= and ?email=.
    expect($url)->toStartWith('https://permits.example.test/reset-password?');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query['email'] ?? null)->toBe('owner@biztrack.local')
        ->and($query['token'] ?? '')->not->toBe('');

    // What that page posts.
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $query['token'],
        'email' => $query['email'],
        'password' => 'New-Pass-2026!',
        'password_confirmation' => 'New-Pass-2026!',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'New-Pass-2026!',
    ])->assertOk();
});

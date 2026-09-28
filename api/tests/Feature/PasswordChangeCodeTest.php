<?php

use App\Models\AuditLog;
use App\Models\EmailCode;
use App\Models\User;
use App\Notifications\PasswordChangeCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * ── A password change is confirmed by email ────────────────────────────────
 *
 * `PUT /auth/password` asked for the current password and nothing else. So a
 * session left open at a shared counter — or a borrowed phone, which is how
 * most people here reach this app — was enough to take the account: type the
 * password its owner had just typed in front of you, set a new one, and the
 * change itself signs every other device out. The owner's way back in is the
 * password they no longer have.
 *
 * A code sent to the registered address breaks that, because the address is
 * the one thing the person at the counter does not have. [Client, 28 September
 * 2026: *"implement email verification in the change password"*.]
 */

/** Ask for a code and read the digits back off the notification that carried them. */
function passwordCode(string $email, string $current = 'biztrack1'): string
{
    Notification::fake();

    test()->withHeaders(authAs($email))
        ->postJson('/api/v1/auth/password/code', ['current_password' => $current])
        ->assertOk();

    $sent = null;
    Notification::assertSentTo(
        User::where('email', $email)->firstOrFail(),
        PasswordChangeCode::class,
        function (PasswordChangeCode $n) use (&$sent) {
            $sent = $n->code;

            return true;
        }
    );

    return $sent;
}

it('will not change a password without a code', function () {
    // The whole point. The current password alone used to be enough.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect(Hash::check('biztrack1', User::where('email', 'owner@biztrack.local')->value('password')))
        ->toBeTrue();
});

it('changes the password when the emailed code is given', function () {
    $code = passwordCode('owner@biztrack.local');

    expect($code)->toMatch('/^\d{6}$/');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
            'code' => $code,
        ])
        ->assertOk();

    expect(Hash::check('Malabon-City-2026!', User::where('email', 'owner@biztrack.local')->value('password')))
        ->toBeTrue();
});

it('will not send a code to somebody who does not know the current password', function () {
    /*
     * Checked before the mail goes, not only when the change lands. Otherwise
     * anybody holding a session could make the owner's inbox ring, and the
     * mail would warn that their password is being changed when nobody had got
     * past the first field — a warning that fires on nothing teaches its reader
     * to ignore the next one.
     */
    Notification::fake();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/auth/password/code', ['current_password' => 'not-the-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    Notification::assertNothingSent();
});

it('masks the address it sent to', function () {
    /*
     * Enough to recognise, not enough to learn. Printing the address in full
     * would hand it to exactly the person this feature exists to stop — they
     * already hold the session, and the address is the one thing they lack.
     */
    Notification::fake();

    $shown = test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/auth/password/code', ['current_password' => 'biztrack1'])
        ->assertOk()
        ->json('email');

    // Double-quoted, because `\u{…}` is only an escape there — in single
    // quotes PHP hands back the nine literal characters.
    expect($shown)->toBe("o\u{2022}\u{2022}\u{2022}\u{2022}@biztrack.local")
        ->and($shown)->not->toContain('owner');
});

it('refuses a wrong code and says how many tries are left', function () {
    passwordCode('owner@biztrack.local');

    $message = test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
            'code' => '000000',
        ])
        ->assertStatus(422)
        ->json('errors.code.0');

    // A count, not "invalid": somebody mistyping a digit should know they have
    // room to try again, and somebody being locked out should see it coming.
    expect($message)->toContain('tries left');
});

it('burns the code after five wrong guesses', function () {
    // Six digits is one in a million, which a script exhausts in minutes. The
    // attempt counter is what makes the ten-minute window mean anything.
    $code = passwordCode('owner@biztrack.local');

    for ($i = 0; $i < EmailCode::MAX_ATTEMPTS; $i++) {
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'biztrack1',
                'password' => 'Malabon-City-2026!',
                'password_confirmation' => 'Malabon-City-2026!',
                'code' => '000000',
            ])
            ->assertStatus(422);
    }

    // Even the RIGHT code is now refused.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
            'code' => $code,
        ])
        ->assertStatus(422);

    expect(Hash::check('biztrack1', User::where('email', 'owner@biztrack.local')->value('password')))
        ->toBeTrue();
});

it('refuses a code that has expired, and says to ask for another', function () {
    $code = passwordCode('owner@biztrack.local');

    $this->travel(EmailCode::TTL_MINUTES + 1)->minutes();

    $message = test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
            'code' => $code,
        ])
        ->assertStatus(422)
        ->json('errors.code.0');

    // "Expired" sends the reader to the Send button; "wrong" sends them back
    // to the mail. One message for both would send half of them the wrong way.
    expect($message)->toContain('expired');
});

it('will not let a code be spent twice', function () {
    $code = passwordCode('owner@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
            'code' => $code,
        ])
        ->assertOk();

    // Replayed with the NEW current password, so only the spent code is what
    // stops it.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'Malabon-City-2026!',
            'password' => 'Second-Change-2026!',
            'password_confirmation' => 'Second-Change-2026!',
            'code' => $code,
        ])
        ->assertStatus(422);

    expect(Hash::check('Malabon-City-2026!', User::where('email', 'owner@biztrack.local')->value('password')))
        ->toBeTrue();
});

it('keeps only one live code, so the newest mail is the one that works', function () {
    /*
     * Two live codes means the one the reader is looking at might not be the
     * one that works, which is indistinguishable from the feature being
     * broken. Asking again retires the first.
     */
    $first = passwordCode('owner@biztrack.local');

    $this->travel(EmailCode::RESEND_SECONDS + 1)->seconds();
    $second = passwordCode('owner@biztrack.local');

    expect($first)->not->toBe($second);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
            'code' => $first,
        ])
        ->assertStatus(422);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
            'code' => $second,
        ])
        ->assertOk();
});

it('will not send a second code within the minute', function () {
    // The button is otherwise an open relay: a held session can post it in a
    // loop and bury the owner's inbox — including the warnings this very
    // feature sends.
    passwordCode('owner@biztrack.local');

    Notification::fake();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/auth/password/code', ['current_password' => 'biztrack1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    Notification::assertNothingSent();
});

it('never writes the code itself to the audit trail', function () {
    // The trail records THAT a code was sent, which is what an auditor needs.
    // The digits are a credential, and a trail anybody can read is the wrong
    // place for one.
    $code = passwordCode('owner@biztrack.local');

    $entries = AuditLog::where('action', 'user.password_code_sent')->get();

    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect(json_encode($entry->changes ?? []))->not->toContain($code);
    }
});

it('still revokes the other sessions when the change goes through', function () {
    // The behaviour that was already here, and which the code step must not
    // have quietly dropped: a hijacked session dies with the old credential.
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->createToken('other-device');

    $code = passwordCode('owner@biztrack.local');
    $before = $user->tokens()->count();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'biztrack1',
            'password' => 'Malabon-City-2026!',
            'password_confirmation' => 'Malabon-City-2026!',
            'code' => $code,
        ])
        ->assertOk();

    expect($user->tokens()->count())->toBeLessThan($before);
});

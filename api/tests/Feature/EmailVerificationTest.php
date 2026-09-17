<?php

use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
 * Email verification, which was worse than unbuilt [checklist item #61].
 *
 * Everything LOOKED present. The column existed, a verify route existed, the
 * Profile screen printed "Not verified yet", and pressing resend answered
 * `{"message": "Verification email sent."}` — from a method whose entire body
 * was that response, under a comment calling verification "simulated for the
 * prototype". Registration mailed nothing. So a tester pressing the button got
 * a green success and an empty inbox, with no way to tell that apart from a
 * spam filter eating a real message.
 *
 * These tests exist to keep the reply and the act in step. Every one of them
 * asserts that a message was actually queued, or that a link actually changed
 * the row — never just that the endpoint answered 200, which is exactly the
 * assertion the old stub would have passed.
 */

it('sends a verification email when an account is registered', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Marisol',
        'last_name' => 'Ramos',
        'gender' => 'F',
        'email' => 'marisol.ramos@example.com',
        'mobile_number' => '09171234567',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'data_privacy_consent' => true,
    ])->assertCreated();

    $user = User::where('email', 'marisol.ramos@example.com')->firstOrFail();
    Notification::assertSentTo($user, VerifyEmailAddress::class);
});

it('registers the account even when the mailer is down', function () {
    /*
     * The row and the token are written before the mail goes out, so a mailer
     * that throws must not turn a completed sign-up into a 500 — the address
     * would be taken, the person would hold no token, and they could neither
     * register again nor sign in. The failure belongs in the log.
     */
    Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp is down'));

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Dominic',
        'last_name' => 'Cruz',
        'gender' => 'M',
        'email' => 'dominic.cruz@example.com',
        'mobile_number' => '09171234568',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'data_privacy_consent' => true,
    ])->assertCreated()->assertJsonStructure(['data' => ['token']]);

    expect(User::where('email', 'dominic.cruz@example.com')->exists())->toBeTrue();
});

it('marks the address verified when the signed link is opened', function () {
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->get(verificationUrlFor($user))->assertRedirectContains('status=verified');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('refuses a verification link whose signature has been tampered with', function () {
    /*
     * The rule this pins is the reason the old endpoint had to go. It was an
     * unauthenticated POST taking `{id, hash}` and marking the row verified
     * when the hash matched sha1 of the account's own email — both of which are
     * public knowledge, so anyone could confirm anyone's address.
     */
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => null])->save();

    $forged = str_replace('signature=', 'signature=0', verificationUrlFor($user));

    $this->get($forged)->assertRedirectContains('status=expired');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('refuses a verification link built by hand from an id and an email address', function () {
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => null])->save();

    $unsigned = "/api/v1/auth/email/verify/{$user->id}/".sha1($user->email);

    $this->get($unsigned)->assertRedirectContains('status=expired');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('refuses a verification link after it has expired', function () {
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => null])->save();

    $url = verificationUrlFor($user);
    $this->travel(config('auth.verification.expire') + 1)->minutes();

    $this->get($url)->assertRedirectContains('status=expired');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('refuses a link whose hash no longer matches the address it was issued for', function () {
    // The hash is sha1 of the address, so changing the address invalidates
    // every link already in flight. That is the property, not a side effect.
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => null])->save();

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1('someone.else@example.com'),
    ]);

    $this->get($url)->assertRedirectContains('status=invalid');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('treats a second click on the same link as already done, not as an error', function () {
    // Mail scanners prefetch links, and people click twice. Neither is a
    // failure, and telling someone their confirmed address failed to confirm
    // sends them looking for a problem that is not there.
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => null])->save();

    $url = verificationUrlFor($user);

    $this->get($url)->assertRedirectContains('status=verified');
    $this->get($url)->assertRedirectContains('status=already');
});

it('actually sends an email when resend is pressed', function () {
    Notification::fake();

    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => null])->save();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/auth/email/resend')
        ->assertOk();

    // The whole point. The stub this replaced would have passed an
    // assertion on the status code alone.
    Notification::assertSentTo($user, VerifyEmailAddress::class);
});

it('throttles resend rather than letting one account mail itself indefinitely', function () {
    Notification::fake();

    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => null])->save();
    $headers = authAs('owner@biztrack.local');

    for ($i = 0; $i < 3; $i++) {
        $this->withHeaders($headers)->postJson('/api/v1/auth/email/resend')->assertOk();
    }

    $this->withHeaders($headers)->postJson('/api/v1/auth/email/resend')->assertStatus(429);

    Notification::assertSentToTimes($user, VerifyEmailAddress::class, 3);
});

it('says so plainly when the address is already confirmed, and sends nothing', function () {
    Notification::fake();

    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['email_verified_at' => now()])->save();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/auth/email/resend')
        ->assertOk()
        ->assertJsonPath('message', 'Your email address is already confirmed.');

    Notification::assertNothingSent();
});

/*
 * ── Enforcement is a POLICY switch, and it ships OFF ────────────────────────
 *
 * The capability is built; turning it into a gate is a decision the LGU makes,
 * not one this change makes for them. Most accounts in the live register have
 * `email_verified_at` NULL — seeded, or registered while resend was a stub —
 * so switching it on today would lock the client's testers out over mail that
 * was never sent. config/auth.php carries the same note and the checklist for
 * turning it on.
 *
 * Both halves are pinned. The default has to stay false, and the gate has to
 * work when it is true, or the flag is a comment rather than a control.
 */
it('lets an unverified account sign in, because enforcement ships off', function () {
    expect(config('auth.verification.required_at_login'))->toBeFalse();

    User::where('email', 'owner@biztrack.local')->firstOrFail()
        ->forceFill(['email_verified_at' => null])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertOk();
});

it('blocks an unverified sign-in once the LGU turns enforcement on', function () {
    config(['auth.verification.required_at_login' => true]);

    User::where('email', 'owner@biztrack.local')->firstOrFail()
        ->forceFill(['email_verified_at' => null])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertStatus(403);
});

it('lets a verified account in with enforcement on', function () {
    config(['auth.verification.required_at_login' => true]);

    User::where('email', 'owner@biztrack.local')->firstOrFail()
        ->forceFill(['email_verified_at' => now()])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertOk();
});

it('does not let the verification gate become a new wrong-door oracle', function () {
    /*
     * Item #63 closed the citizen door's ability to say "this address belongs
     * to City Hall". An unverified STAFF account tried at the citizen door must
     * therefore still answer 422 — if the verification gate ran first it would
     * answer 403 instead, which is a distinguishable reply reached with nothing
     * but a correct password, and the oracle would be back in a new shape.
     */
    config(['auth.verification.required_at_login' => true]);

    User::where('email', 'bplo@biztrack.local')->firstOrFail()
        ->forceFill(['email_verified_at' => null])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertStatus(422)->assertJsonPath('message', 'Invalid credentials.');
});

/** The link the notification would put in the email, built the same way. */
function verificationUrlFor(User $user): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes((int) config('auth.verification.expire', 60)),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );
}

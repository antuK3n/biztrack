<?php

use App\Mail\OneTimeCode;
use App\Models\Application;
use App\Models\EmailCode;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Support\EmailSwitch;
use App\Support\SystemSwitches;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Six-digit e-mail codes [checklist 2026-09-27, Register 1 and Login 5].
 *
 * Every rule here has two halves, and both are pinned. With mail OFF (the
 * shipped `log` mailer, and the tester demo) nothing may change: a password
 * still signs you in, sign-up hands out a session at once, and an unconfirmed
 * owner can still file. With mail ON a right password earns a code, not a
 * session; so does a finished sign-up, whose code confirms the address; and
 * filing still refuses an unconfirmed address. The switch is
 * EmailSwitch::on(); tests turn it on by pointing mail.default at smtp and
 * faking the mailer.
 */

function mailOn(): void
{
    config(['mail.default' => 'smtp']);
    Mail::fake();
}

/** The code in the last OneTimeCode sent, read off the faked mailer. */
function lastCode(string $purpose = EmailCode::LOGIN): string
{
    $code = null;
    Mail::assertSent(OneTimeCode::class, function (OneTimeCode $mail) use (&$code, $purpose) {
        if ($mail->purpose === $purpose) {
            $code = $mail->code;
        }

        return true;
    });

    return (string) $code;
}

function startSignIn(string $email, string $portal = 'public'): string
{
    return test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'biztrack1',
        'portal' => $portal,
    ])->assertOk()->assertJsonPath('data.code_required', true)->json('data.challenge');
}

function wrongCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

// ── The switch ────────────────────────────────────────────────────────────

it('counts only a mailer that delivers somewhere as mail being on', function () {
    foreach (['log' => false, 'array' => false, 'smtp' => true, 'ses' => true, 'failover' => true] as $mailer => $on) {
        config(['mail.default' => $mailer]);
        expect(EmailSwitch::on())->toBe($on, $mailer);
    }
});

// ── Mail off: today's behaviour, untouched ───────────────────────────────

it('signs in with the password alone while mail is off', function () {
    Mail::fake();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
    ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

    Mail::assertNothingSent();
    expect(EmailCode::count())->toBe(0);
});

it('signs a new owner straight in and still sends the confirmation link, not a code, while mail is off', function () {
    Notification::fake();
    Mail::fake();

    $this->postJson('/api/v1/auth/register', registration('off.path@example.com'))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user']])
        ->assertJsonPath('data.user.email_verification_required', false);

    Notification::assertSentTo(User::where('email', 'off.path@example.com')->first(), VerifyEmailAddress::class);
    Mail::assertNothingSent();
});

it('lets an unconfirmed owner file while mail is off', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(['email_verified_at' => null])->save();
    $draft = Application::where('applicant_user_id', $owner->id)->firstOrFail();

    authAs('owner@biztrack.local');
    attachRequiredDocuments($draft->id);
    $response = $this->postJson("/api/v1/applications/{$draft->id}/submit");

    expect($response->status())->not->toBe(403);
    expect($response->json('reason'))->toBeNull();
});

// ── Mail on: sign-in code (Login 5) ───────────────────────────────────────

it('answers a right password with a code by e-mail and no session, for all three doors', function (string $email, string $portal) {
    mailOn();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'biztrack1',
        'portal' => $portal,
    ])->assertOk()
        ->assertJsonPath('data.code_required', true)
        ->assertJsonMissingPath('data.token');

    expect($response->json('data.email'))->toContain('••••@');
    Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $m) => $m->hasTo($email) && $m->purpose === EmailCode::LOGIN);
    expect(User::where('email', $email)->first()->tokens()->count())->toBe(0);
})->with([
    'owner' => ['owner@biztrack.local', 'public'],
    'officer' => ['bplo@biztrack.local', 'staff'],
    'super admin' => ['admin@biztrack.local', 'admin'],
]);

it('stores the code and the challenge only as hashes', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');
    $code = lastCode();

    $row = EmailCode::firstOrFail();
    expect($row->code_hash)->not->toBe($code)->not->toContain($code);
    expect($row->challenge_hash)->not->toBe($challenge);
});

it('finishes the sign-in with the right code, on the door it started at', function () {
    mailOn();
    $challenge = startSignIn('bplo@biztrack.local', 'staff');

    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => lastCode()])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']]);

    expect(User::where('email', 'bplo@biztrack.local')->first()->tokens()->latest('id')->first()->name)->toBe('web:staff');
});

it('accepts a code copied with a space in the middle', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');
    $code = lastCode();

    $this->postJson('/api/v1/auth/login/code', [
        'challenge' => $challenge,
        'code' => substr($code, 0, 3).' '.substr($code, 3),
    ])->assertOk();
});

it('refuses a code once it has been used', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');
    $code = lastCode();

    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $code])->assertOk();
    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $code])
        ->assertStatus(422)->assertJsonPath('reason', 'code_expired');
});

it('says how many tries are left after a wrong code', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');

    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => wrongCode(lastCode())])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That code is not right. You have 4 tries left.');
});

it('closes the code after five wrong guesses, and the right one no longer works', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');
    $code = lastCode();

    foreach (range(1, 4) as $_) {
        $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => wrongCode($code)])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => wrongCode($code)])
        ->assertStatus(422)->assertJsonPath('reason', 'code_expired');

    // The account is locked now, as five wrong passwords would lock it.
    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $code])->assertStatus(429);
    expect(User::where('email', 'owner@biztrack.local')->first()->locked_until)->not->toBeNull();
});

it('counts wrong codes against the account across fresh sign-ins', function () {
    // Otherwise the password holder restarts every five guesses and walks the
    // million codes. Three wrong on one challenge, two on the next: locked.
    mailOn();
    $first = startSignIn('owner@biztrack.local');
    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/login/code', ['challenge' => $first, 'code' => wrongCode(lastCode())]);
    }

    $second = startSignIn('owner@biztrack.local');
    foreach (range(1, 2) as $_) {
        $this->postJson('/api/v1/auth/login/code', ['challenge' => $second, 'code' => '999999']);
    }

    expect(User::where('email', 'owner@biztrack.local')->first()->failed_login_attempts)->toBe(5);
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@biztrack.local', 'password' => 'biztrack1'])
        ->assertStatus(429);
});

it('does not charge a try for something that is not six digits', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');

    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => '12ab'])
        ->assertStatus(422)->assertJsonPath('message', 'Enter the 6 digits from the e-mail.');

    expect(EmailCode::first()->attempts)->toBe(0);
});

it('refuses the sign-in code after ten minutes', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');
    $code = lastCode();

    $this->travel(11)->minutes();

    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $code])
        ->assertStatus(422)->assertJsonPath('reason', 'code_expired');
});

it('refuses a challenge it never issued', function () {
    mailOn();

    $this->postJson('/api/v1/auth/login/code', ['challenge' => str_repeat('x', 64), 'code' => '123456'])
        ->assertStatus(422)->assertJsonPath('reason', 'code_expired');
});

it('makes the resend button wait a minute, then sends a new code that replaces the old', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');
    $old = lastCode();

    $this->postJson('/api/v1/auth/login/code/resend', ['challenge' => $challenge])
        ->assertStatus(429)->assertJsonStructure(['retry_after']);

    $this->travel(61)->seconds();
    Mail::fake();

    $this->postJson('/api/v1/auth/login/code/resend', ['challenge' => $challenge])->assertOk();
    $new = lastCode();

    if ($new !== $old) {
        $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $old])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $new])->assertOk();
});

it('stops resending after five e-mails for one sign-in', function () {
    mailOn();
    $challenge = startSignIn('owner@biztrack.local');

    foreach (range(2, 5) as $_) {
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login/code/resend', ['challenge' => $challenge])->assertOk();
    }

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/login/code/resend', ['challenge' => $challenge])
        ->assertStatus(422)->assertJsonPath('reason', 'code_expired');
});

it('says so, and issues nothing, when the code cannot be sent', function () {
    config(['mail.default' => 'smtp']);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('535 Authentication failed'));

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@biztrack.local', 'password' => 'biztrack1'])
        ->assertStatus(503)
        ->assertJsonMissingPath('data');

    expect(EmailCode::first()->consumed_at)->not->toBeNull();
    expect(User::where('email', 'owner@biztrack.local')->first()->tokens()->count())->toBe(0);
});

it('never sends a code for a wrong password', function () {
    mailOn();

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@biztrack.local', 'password' => 'nope-nope'])
        ->assertStatus(422);

    Mail::assertNothingSent();
});

it('confirms an unconfirmed address when the sign-in code is typed', function () {
    mailOn();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(['email_verified_at' => null])->save();

    $challenge = startSignIn('owner@biztrack.local');
    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => lastCode()])
        ->assertOk()
        ->assertJsonPath('data.user.email_verification_required', false);

    expect($owner->fresh()->email_verified_at)->not->toBeNull();
});

// ── Mail on: confirming the address at sign-up (Register 1) ─────────────

it('asks for the confirmation code at sign-up, and hands out no session until it is typed', function () {
    mailOn();
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', registration('new.owner@example.com'))
        ->assertCreated()
        ->assertJsonPath('data.code_required', true)
        ->assertJsonPath('data.expires_in_minutes', 30)
        ->assertJsonMissingPath('data.token');

    expect($response->json('data.email'))->toBe('n••••@example.com');
    expect($response->json('data.challenge'))->toBeString()->not->toBeEmpty();
    Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $m) => $m->hasTo('new.owner@example.com') && $m->purpose === EmailCode::VERIFY);
    Notification::assertNothingSent();

    $owner = User::where('email', 'new.owner@example.com')->firstOrFail();
    expect($owner->tokens()->count())->toBe(0);
    expect($owner->email_verified_at)->toBeNull();
});

it('confirms the address and signs the new owner in when the sign-up code is typed', function () {
    mailOn();
    $challenge = signUp('typed.code@example.com');

    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => lastCode(EmailCode::VERIFY)])
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']])
        ->assertJsonPath('data.user.email_verification_required', false);

    $owner = User::where('email', 'typed.code@example.com')->firstOrFail();
    expect($owner->email_verified_at)->not->toBeNull();
    expect($owner->tokens()->latest('id')->first()->name)->toBe('web:public');
});

it('refuses a wrong sign-up code, says how many tries are left, and confirms nothing', function () {
    mailOn();
    $challenge = signUp('wrong.code@example.com');

    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => wrongCode(lastCode(EmailCode::VERIFY))])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That code is not right. You have 4 tries left.');

    $owner = User::where('email', 'wrong.code@example.com')->firstOrFail();
    expect($owner->email_verified_at)->toBeNull();
    expect($owner->tokens()->count())->toBe(0);
});

it('resends the sign-up code as a confirmation e-mail, after a minute, and only the newest works', function () {
    mailOn();
    $challenge = signUp('resend.code@example.com');
    $old = lastCode(EmailCode::VERIFY);

    $this->postJson('/api/v1/auth/login/code/resend', ['challenge' => $challenge])->assertStatus(429);

    $this->travel(61)->seconds();
    Mail::fake();
    $this->postJson('/api/v1/auth/login/code/resend', ['challenge' => $challenge])->assertOk();
    Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $m) => $m->hasTo('resend.code@example.com') && $m->purpose === EmailCode::VERIFY);
    $new = lastCode(EmailCode::VERIFY);

    if ($new !== $old) {
        $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $old])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $new])->assertOk();
});

it('refuses the sign-up code after thirty minutes', function () {
    mailOn();
    $challenge = signUp('late.code@example.com');
    $code = lastCode(EmailCode::VERIFY);

    $this->travel(31)->minutes();

    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $code])
        ->assertStatus(422)->assertJsonPath('reason', 'code_expired');
});

it('asks again at the next sign-in when the sign-up code was never typed', function () {
    // With enforcement left at its default (off): mail on is enough.
    mailOn();
    SystemSwitches::set('sign_in_codes', 'off');
    signUp('closed.tab@example.com');

    $challenge = startSignIn('closed.tab@example.com');
    $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => lastCode()])
        ->assertOk()
        ->assertJsonPath('data.user.email_verification_required', false);
});

it('still asks for the code when the confirmation e-mail fails to send at sign-up', function () {
    // The account is written by then; a 500 would leave the address taken and
    // the reader with nothing. They are asked for the code, and "Send a new
    // code" is how they get one once the relay is back.
    config(['mail.default' => 'smtp']);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('535 Authentication failed'));

    $this->postJson('/api/v1/auth/register', registration('relay.down@example.com'))
        ->assertCreated()
        ->assertJsonPath('data.code_required', true)
        ->assertJsonMissingPath('data.token');
});

// ── Mail on: the submit guard, behind the sign-up code ───────────────────

it('still refuses to file for an owner whose address is unconfirmed, then lets it through', function () {
    mailOn();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(['email_verified_at' => null])->save();
    $draft = Application::where('applicant_user_id', $owner->id)->firstOrFail();

    authAs('owner@biztrack.local');
    attachRequiredDocuments($draft->id);
    $this->postJson("/api/v1/applications/{$draft->id}/submit")
        ->assertStatus(403)
        ->assertJsonPath('reason', 'email_unconfirmed');

    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    $this->postJson('/api/v1/auth/email/verify-code', ['code' => lastCode(EmailCode::VERIFY)])
        ->assertOk()
        ->assertJsonPath('data.email_verification_required', false);

    attachRequiredDocuments($draft->id);
    $after = $this->postJson("/api/v1/applications/{$draft->id}/submit");
    expect($after->json('reason'))->toBeNull();
    expect($after->status())->not->toBe(403);
});

it('does not hold back a returned filing over an unconfirmed address', function () {
    // Only the first hand-in is gated; see EnsureEmailConfirmedToFile.
    $routes = collect(app('router')->getRoutes()->getRoutes());
    $resubmit = $routes->first(fn ($r) => str_ends_with($r->uri(), 'applications/{application}/resubmit'));
    $submit = $routes->first(fn ($r) => str_ends_with($r->uri(), 'applications/{application}/submit'));

    expect($submit->gatherMiddleware())->toContain('email.confirmed');
    expect($resubmit->gatherMiddleware())->not->toContain('email.confirmed');
});

it('refuses a wrong confirmation code and says how many tries are left', function () {
    mailOn();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(['email_verified_at' => null])->save();
    authAs('owner@biztrack.local');

    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    $this->postJson('/api/v1/auth/email/verify-code', ['code' => wrongCode(lastCode(EmailCode::VERIFY))])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That code is not right. You have 4 tries left.');

    expect($owner->fresh()->email_verified_at)->toBeNull();
});

it('refuses a confirmation code after thirty minutes', function () {
    mailOn();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(['email_verified_at' => null])->save();
    authAs('owner@biztrack.local');

    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    $code = lastCode(EmailCode::VERIFY);
    $this->travel(31)->minutes();

    $this->postJson('/api/v1/auth/email/verify-code', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This code no longer works. Send yourself a new one.');
});

it('only accepts the newest confirmation code', function () {
    mailOn();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(['email_verified_at' => null])->save();
    authAs('owner@biztrack.local');

    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    $first = lastCode(EmailCode::VERIFY);
    Mail::fake();
    $this->postJson('/api/v1/auth/email/resend')->assertOk();
    $second = lastCode(EmailCode::VERIFY);

    if ($first !== $second) {
        $this->postJson('/api/v1/auth/email/verify-code', ['code' => $first])->assertStatus(422);
    }
    $this->postJson('/api/v1/auth/email/verify-code', ['code' => $second])->assertOk();
});

it('rations the confirmation resend per account', function () {
    mailOn();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(['email_verified_at' => null])->save();
    authAs('owner@biztrack.local');

    foreach (range(1, 3) as $_) {
        $this->postJson('/api/v1/auth/email/resend')->assertOk();
    }
    $this->postJson('/api/v1/auth/email/resend')->assertStatus(429);
});

it('never asks staff to confirm an address before filing', function () {
    mailOn();
    User::where('email', 'bplo@biztrack.local')->update(['email_verified_at' => null]);
    authAs('bplo@biztrack.local');

    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email_verification_required', false);
});

// ── helpers ───────────────────────────────────────────────────────────────

/** Register with mail on and return the challenge the code step needs. */
function signUp(string $email): string
{
    return test()->postJson('/api/v1/auth/register', registration($email))
        ->assertCreated()
        ->assertJsonPath('data.code_required', true)
        ->json('data.challenge');
}

/** @return array<string, mixed> */
function registration(string $email): array
{
    return [
        'first_name' => 'Liza',
        'last_name' => 'Soberano',
        'gender' => 'F',
        'email' => $email,
        'mobile_number' => '09171234567',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'data_privacy_consent' => true,
        ...homeAddress(),
    ];
}

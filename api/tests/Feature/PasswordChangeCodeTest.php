<?php

use App\Mail\OneTimeCode;
use App\Models\AuditLog;
use App\Models\EmailCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/*
 * A code by e-mail before a password change [checklist 2026-09-27, Edit
 * Settings; closes View Profile 3].
 *
 * Two halves, as in EmailCodeTest. With mail OFF (the demo) the current
 * password is enough, exactly as before — ProfileTest's two password tests are
 * that half and are left untouched. With mail ON the change also needs a code
 * from POST /auth/password/code, which checks the current password before it
 * sends anything.
 *
 * Helpers are prefixed `pw` because Pest loads every test file into one
 * process, and EmailCodeTest already declares `mailOn` and `lastCode`.
 */

function pwMailOn(): void
{
    config(['mail.default' => 'smtp']);
    Mail::fake();
}

/** The code in the newest password-change e-mail on the faked mailer. */
function pwLastCode(): string
{
    $code = null;
    Mail::assertSent(OneTimeCode::class, function (OneTimeCode $mail) use (&$code) {
        if ($mail->purpose === EmailCode::PASSWORD) {
            $code = $mail->code;
        }

        return true;
    });

    return (string) $code;
}

function pwOwner(): User
{
    return User::where('email', 'owner@biztrack.local')->firstOrFail();
}

/**
 * A bearer token for the owner, minted directly. With mail on, /auth/login
 * answers with a sign-in code rather than a token, and these tests are about
 * the step after that.
 */
function pwToken(): string
{
    return pwOwner()->createToken('web:public')->plainTextToken;
}

function pwRequestCode(string $token, string $current = 'biztrack1')
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->postJson('/api/v1/auth/password/code', ['current_password' => $current]);
}

function pwChange(string $token, ?string $code, string $new = 'brand-new-pass1')
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->putJson('/api/v1/auth/password', array_filter([
        'current_password' => 'biztrack1',
        'password' => $new,
        'password_confirmation' => $new,
        'code' => $code,
    ], fn ($v) => $v !== null));
}

function pwWrong(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

// ── Mail off: nothing new is asked for ───────────────────────────────────

it('changes the password with the current password alone while mail is off, and sends nothing', function () {
    Mail::fake();
    $token = pwToken();

    pwChange($token, null)->assertOk()
        ->assertJsonPath('message', 'Password updated. Other signed-in devices have been logged out.');

    expect(Hash::check('brand-new-pass1', pwOwner()->password))->toBeTrue();
    Mail::assertNothingSent();
    expect(EmailCode::count())->toBe(0);
});

it('tells the web app no code is needed while mail is off', function () {
    $token = pwToken();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.password_change_code_required', false);

    pwRequestCode($token)->assertOk()->assertJsonPath('data.code_required', false);
    expect(EmailCode::count())->toBe(0);
});

// ── Mail on ──────────────────────────────────────────────────────────────

it('tells the web app a code is needed while mail is on', function () {
    pwMailOn();
    $token = pwToken();
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.password_change_code_required', true);
});

it('refuses a password change without a code while mail is on', function () {
    pwMailOn();
    $token = pwToken();

    pwChange($token, null)->assertStatus(422)->assertJsonValidationErrors('code');

    expect(Hash::check('biztrack1', pwOwner()->password))->toBeTrue();
});

it('sends a code only for the right current password', function () {
    pwMailOn();
    $token = pwToken();

    pwRequestCode($token, 'not-the-password')
        ->assertStatus(422)->assertJsonValidationErrors('current_password');

    Mail::assertNothingSent();
    expect(EmailCode::count())->toBe(0);

    pwRequestCode($token)->assertOk()
        ->assertJsonPath('data.code_required', true)
        ->assertJsonPath('data.expires_in_minutes', 10);
    Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $m) => $m->hasTo('owner@biztrack.local') && $m->purpose === EmailCode::PASSWORD);
});

it('changes the password with the right code, and signs every other device out', function () {
    pwMailOn();
    $other = pwToken();
    $token = pwToken();

    pwRequestCode($token)->assertOk();
    pwChange($token, pwLastCode())->assertOk();

    expect(Hash::check('brand-new-pass1', pwOwner()->password))->toBeTrue();
    expect(pwOwner()->tokens()->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses a wrong code, says how many tries are left, and counts it against the account', function () {
    pwMailOn();
    $token = pwToken();
    pwRequestCode($token)->assertOk();

    pwChange($token, pwWrong(pwLastCode()))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code')
        ->assertJsonPath('message', 'That code is not right. You have 4 tries left.');

    expect(pwOwner()->failed_login_attempts)->toBe(1);
    expect(Hash::check('biztrack1', pwOwner()->password))->toBeTrue();
});

it('locks the account after five wrong codes, across fresh codes, as wrong sign-in codes do', function () {
    pwMailOn();
    $token = pwToken();

    pwRequestCode($token)->assertOk();
    foreach (range(1, 3) as $_) {
        pwChange($token, pwWrong(pwLastCode()))->assertStatus(422);
    }

    // A new code does not buy new guesses: the count is on the account.
    $this->travel(11)->minutes();
    pwRequestCode($token)->assertOk();
    foreach (range(1, 2) as $_) {
        pwChange($token, pwWrong(pwLastCode()))->assertStatus(422);
    }

    expect(pwOwner()->failed_login_attempts)->toBe(5);
    expect(pwOwner()->locked_until?->isFuture())->toBeTrue();

    // Locked: no more codes, no more guesses, and no sign-in elsewhere.
    pwRequestCode($token)->assertStatus(429);
    pwChange($token, pwLastCode())->assertStatus(429);
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@biztrack.local', 'password' => 'biztrack1'])
        ->assertStatus(429);
});

it('does not charge a try for something that is not six digits', function () {
    pwMailOn();
    $token = pwToken();
    pwRequestCode($token)->assertOk();

    pwChange($token, '12ab')->assertStatus(422)
        ->assertJsonPath('message', 'Enter the 6 digits from the e-mail.');

    expect(pwOwner()->failed_login_attempts)->toBe(0);
    expect(EmailCode::first()->attempts)->toBe(0);
});

it('refuses a password code after ten minutes', function () {
    pwMailOn();
    $token = pwToken();
    pwRequestCode($token)->assertOk();
    $code = pwLastCode();

    $this->travel(11)->minutes();

    pwChange($token, $code)->assertStatus(422)
        ->assertJsonPath('message', 'This code no longer works. Send yourself a new one.');
    expect(Hash::check('biztrack1', pwOwner()->password))->toBeTrue();
});

it('refuses a code once it has been used', function () {
    pwMailOn();
    $token = pwToken();
    pwRequestCode($token)->assertOk();
    $code = pwLastCode();

    pwChange($token, $code)->assertOk();

    // The password is now brand-new-pass1; change it back with the same code.
    app('auth')->forgetGuards();
    $this->withToken($token)->putJson('/api/v1/auth/password', [
        'current_password' => 'brand-new-pass1',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'code' => $code,
    ])->assertStatus(422)->assertJsonValidationErrors('code');
});

it('does not accept a sign-in code for a password change', function () {
    pwMailOn();
    $token = pwToken();

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@biztrack.local', 'password' => 'biztrack1'])
        ->assertOk()->assertJsonPath('data.code_required', true);
    $signInCode = null;
    Mail::assertSent(OneTimeCode::class, function (OneTimeCode $m) use (&$signInCode) {
        $signInCode = $m->purpose === EmailCode::LOGIN ? $m->code : $signInCode;

        return true;
    });

    // With no password code ever sent, the sign-in code is not one.
    pwChange($token, $signInCode)->assertStatus(422)->assertJsonValidationErrors('code');

    // And with one sent, the sign-in code still is not it.
    pwRequestCode($token)->assertOk();
    if ($signInCode !== pwLastCode()) {
        pwChange($token, $signInCode)->assertStatus(422)->assertJsonValidationErrors('code');
    }
    expect(Hash::check('biztrack1', pwOwner()->password))->toBeTrue();
});

it('does not accept a password code at the sign-in step', function () {
    pwMailOn();
    $token = pwToken();

    $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'owner@biztrack.local', 'password' => 'biztrack1'])
        ->assertOk()->json('data.challenge');
    $signIn = EmailCode::where('purpose', EmailCode::LOGIN)->firstOrFail();

    pwRequestCode($token)->assertOk();
    $passwordCode = pwLastCode();

    // One time in a million the two numbers are the same, and then typing it
    // at sign-in is simply the right sign-in code.
    if (! Hash::check($passwordCode, $signIn->code_hash)) {
        $this->postJson('/api/v1/auth/login/code', ['challenge' => $challenge, 'code' => $passwordCode])
            ->assertStatus(422);
    }
    // Either way the password code is untouched by the sign-in step.
    expect(EmailCode::where('purpose', EmailCode::PASSWORD)->first()->consumed_at)->toBeNull();
});

it('makes the resend wait a minute, then sends a new code that replaces the old', function () {
    pwMailOn();
    $token = pwToken();

    pwRequestCode($token)->assertOk();
    $old = pwLastCode();

    pwRequestCode($token)->assertStatus(429)->assertJsonStructure(['retry_after']);

    $this->travel(61)->seconds();
    Mail::fake();
    pwRequestCode($token)->assertOk();
    $new = pwLastCode();

    expect(EmailCode::where('purpose', EmailCode::PASSWORD)->count())->toBe(1);
    if ($new !== $old) {
        pwChange($token, $old)->assertStatus(422);
    }
    pwChange($token, $new)->assertOk();
});

it('keeps wrong guesses counted across a resend', function () {
    pwMailOn();
    $token = pwToken();
    pwRequestCode($token)->assertOk();

    pwChange($token, pwWrong(pwLastCode()))->assertStatus(422);
    pwChange($token, pwWrong(pwLastCode()))->assertStatus(422);

    $this->travel(61)->seconds();
    Mail::fake();
    pwRequestCode($token)->assertOk();

    pwChange($token, pwWrong(pwLastCode()))
        ->assertStatus(422)->assertJsonPath('message', 'That code is not right. You have 2 tries left.');
});

it('stops resending after five e-mails for one code', function () {
    pwMailOn();
    $token = pwToken();
    pwRequestCode($token)->assertOk();

    foreach (range(2, 5) as $_) {
        $this->travel(61)->seconds();
        pwRequestCode($token)->assertOk();
    }

    $this->travel(61)->seconds();
    pwRequestCode($token)->assertStatus(429);
    Mail::assertSentCount(5);
});

it('says so, and leaves nothing open, when the code cannot be sent', function () {
    config(['mail.default' => 'smtp']);
    Mail::shouldReceive('to')->andThrow(new RuntimeException('535 Authentication failed'));
    $token = pwToken();

    pwRequestCode($token)->assertStatus(503);

    expect(EmailCode::first()->consumed_at)->not->toBeNull();
});

it('audits the code and the change, and never writes the code or a password into the trail', function () {
    pwMailOn();
    $token = pwToken();
    pwRequestCode($token)->assertOk();
    $code = pwLastCode();
    pwChange($token, $code)->assertOk();

    $rows = AuditLog::whereIn('action', ['user.password_code_sent', 'user.password_changed'])->get();
    expect($rows->pluck('action')->all())->toBe(['user.password_code_sent', 'user.password_changed']);

    $written = $rows->map(fn ($r) => json_encode($r->changes).json_encode($r->snapshot))->implode('');
    expect($written)->not->toContain($code)
        ->not->toContain('biztrack1')
        ->not->toContain('brand-new-pass1');
});

it('requires a session to ask for a code', function () {
    pwMailOn();

    $this->postJson('/api/v1/auth/password/code', ['current_password' => 'biztrack1'])->assertUnauthorized();
    Mail::assertNothingSent();
});

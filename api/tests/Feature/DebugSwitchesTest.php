<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\Setting;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\BusinessDate;
use App\Support\DebugPanel;
use App\Support\OfficeHours;
use App\Support\RenewalWindow;
use App\Support\SystemSwitches;
use Carbon\CarbonImmutable;

/*
 * The Debug page's system switches (App\Support\SystemSwitches): sign-in
 * codes, the captcha, the office-hours notice and the pretend date. Each
 * overrides its env default the moment it is set, refuses to turn on what
 * cannot work, and is audited as `debug.switches`.
 */

/** Flip a switch through the panel, as the super admin. */
function flip(string $switch, ?string $value)
{
    DebugPanel::open(1);
    authAs('admin@biztrack.local');

    return test()->putJson('/api/v1/debug/switches', ['switch' => $switch, 'value' => $value]);
}

/** A password sign-in, as the form sends it. */
function signIn(string $email = 'owner@biztrack.local', array $extra = [])
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'biztrack1',
        'portal' => portalFor($email),
    ] + $extra);
}

/** A mailer that counts as real (EmailSwitch on) but keeps its mail in memory. */
function realishMail(): void
{
    config([
        'mail.mailers.capture' => ['transport' => 'array'],
        'mail.default' => 'capture',
        'mail.from.address' => 'bplo@malabon.gov.ph',
    ]);
}

/** A permit of `$code` running to `$validUntil`, for a renewal to be judged against. */
function pretendPrior(string $code, string $validUntil): Permit
{
    $app = Application::whereNotNull('business_id')->firstOrFail();

    return Permit::create([
        'permit_number' => 'MCB-PRETEND-'.$code.'-'.uniqid(),
        'application_id' => $app->id,
        'business_id' => $app->business_id,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => 'active',
        'valid_from' => CarbonImmutable::parse($validUntil)->subYear()->toDateString(),
        'valid_until' => $validUntil,
        'issued_at' => CarbonImmutable::parse($validUntil)->subYear(),
    ]);
}

it('is behind the panel like the rest of it', function () {
    authAs('admin@biztrack.local');
    $this->getJson('/api/v1/debug/switches')->assertNotFound();
    $this->putJson('/api/v1/debug/switches', ['switch' => 'captcha', 'value' => 'off'])->assertNotFound();

    DebugPanel::open(1);
    authAs('bplo@biztrack.local');
    $this->putJson('/api/v1/debug/switches', ['switch' => 'office_hours', 'value' => 'open'])->assertNotFound();
    expect(Setting::read('switch.office_hours'))->toBeNull();
});

it('turns e-mail sign-in codes off and back on while mail works, and audits each flip', function () {
    realishMail();
    expect(signIn()->assertOk()->json('data.code_required'))->toBeTrue();

    flip('sign_in_codes', 'off')->assertOk()->assertJsonPath('data.sign_in_codes.on', false);
    $plain = signIn()->assertOk();
    expect($plain->json('data.code_required'))->toBeNull()
        ->and($plain->json('data.token'))->not->toBeNull();

    flip('sign_in_codes', 'default')->assertOk()->assertJsonPath('data.sign_in_codes.overridden', false);
    expect(signIn()->assertOk()->json('data.code_required'))->toBeTrue();

    $rows = AuditLog::where('action', 'debug.switches')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->user_id)->toBe(User::where('email', 'admin@biztrack.local')->value('id'))
        ->and($rows[0]->changes['before']['sign_in_codes']['on'])->toBeTrue()
        ->and($rows[0]->changes['after']['sign_in_codes']['on'])->toBeFalse();
});

it('refuses to turn sign-in codes on while mail goes nowhere, and they stay off', function () {
    // The suite's mailer is `array`: a code would reach nobody.
    $refused = flip('sign_in_codes', 'on')->assertStatus(422);
    expect($refused->json('errors.value.0'))->toContain('nobody could sign in');
    expect(SystemSwitches::signInCodes())->toBeFalse()
        ->and(signIn()->assertOk()->json('data.token'))->not->toBeNull()
        ->and(AuditLog::where('action', 'debug.switches')->count())->toBe(0);

    // Switched on while mail worked, then mail stops: off, not a locked door.
    realishMail();
    flip('sign_in_codes', 'on')->assertOk();
    config(['mail.default' => 'log']);
    expect(SystemSwitches::signInCodes())->toBeFalse();
});

it('switches the captcha off and on when it is configured, and says so to the sign-in form', function () {
    config(['services.turnstile.secret' => 'turnstile-secret-not-real']);
    $this->getJson('/api/v1/auth/sign-in-options')->assertOk()->assertJsonPath('data.captcha', true);
    signIn()->assertStatus(422)->assertJsonValidationErrors('captcha_token');

    flip('captcha', 'off')->assertOk()->assertJsonPath('data.captcha.on', false);
    $this->getJson('/api/v1/auth/sign-in-options')->assertOk()->assertJsonPath('data.captcha', false);
    signIn()->assertOk();

    flip('captcha', 'on')->assertOk();
    signIn()->assertStatus(422);
});

it('refuses to turn the captcha on without its secret', function () {
    config(['services.turnstile.secret' => null]);

    expect(flip('captcha', 'on')->assertStatus(422)->json('errors.value.0'))->toContain('TURNSTILE_SECRET_KEY');
    $this->getJson('/api/v1/auth/sign-in-options')->assertJsonPath('data.captcha', false);
    signIn()->assertOk();
});

it('forces the office-hours notice without changing what the audit log records', function () {
    // A Sunday noon in Manila: closed by the clock.
    $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00', 'Asia/Manila'));
    $this->getJson('/api/v1/office-hours')->assertJsonPath('data.open', false)->assertJsonPath('data.forced', null);

    flip('office_hours', 'open')->assertOk()->assertJsonPath('data.office_hours.mode', 'open');
    $this->getJson('/api/v1/office-hours')->assertJsonPath('data.open', true)->assertJsonPath('data.forced', 'open');

    // An officer signing in now is still out of hours, and the trail says so.
    expect(OfficeHours::isOpen())->toBeFalse();
    signIn('bplo@biztrack.local')->assertOk();
    expect(AuditLog::where('action', 'user.signed_in_outside_hours')->count())->toBe(1);

    // A weekday morning, forced closed.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Asia/Manila'));
    flip('office_hours', 'closed')->assertOk();
    $this->getJson('/api/v1/office-hours')->assertJsonPath('data.open', false)->assertJsonPath('data.forced', 'closed');

    flip('office_hours', 'auto')->assertOk();
    $this->getJson('/api/v1/office-hours')->assertJsonPath('data.open', true)->assertJsonPath('data.forced', null);

    flip('office_hours', 'lunch')->assertStatus(422);
});

it('judges a renewal late against the pretend date, and leaves the real timestamps alone', function () {
    $prior = pretendPrior('SANITARY', now()->addMonth()->toDateString());
    $app = Application::create([
        'tracking_id' => 'BIZ-PRETEND-'.uniqid(),
        'business_id' => $prior->business_id,
        'applicant_user_id' => Application::findOrFail($prior->application_id)->applicant_user_id,
        'application_type' => 'renewal',
        'status' => ApplicationStatus::Draft,
        'submitted_at' => now(),
    ]);
    $late = fn () => (new ReflectionMethod(WorkflowService::class, 'latePenaltyFor'))
        ->invoke(app(WorkflowService::class), $app->fresh(), $prior, 1000.0);

    expect($late()['surcharge'])->toBe(0.0);

    // Ten weeks after it expires: late, 25% once and 2% for each of 3 months.
    $pretend = CarbonImmutable::parse($prior->valid_until)->addDays(70)->toDateString();
    flip('pretend_date', $pretend)->assertOk()->assertJsonPath('data.pretend_date.date', $pretend);

    expect($late())->toMatchArray(['surcharge' => 250.0, 'months_counted' => 3])
        ->and($prior->fresh()->daysUntilExpiry())->toBe(-70)
        ->and(BusinessDate::today()->toDateString())->toBe($pretend);

    // Nothing real moved: the filing's own time, the clock, the audit row.
    expect($app->fresh()->submitted_at->isToday())->toBeTrue()
        ->and(now()->toDateString())->not->toBe($pretend)
        ->and(AuditLog::where('action', 'debug.switches')->latest('id')->first()->created_at->isToday())->toBeTrue();

    flip('pretend_date', null)->assertOk()->assertJsonPath('data.pretend_date.date', null);
    expect($late()['surcharge'])->toBe(0.0)
        ->and($prior->fresh()->daysUntilExpiry())->toBeGreaterThan(0);
});

it('opens a clearance’s renewal window by the pretend date', function () {
    config(['biztrack.renewal_window.opens_days_before' => 30]);
    $prior = pretendPrior('SANITARY', now()->addDays(90)->toDateString())->load('permitType');

    expect(RenewalWindow::refusalFor($prior))->toContain('Renewable from');

    flip('pretend_date', CarbonImmutable::parse($prior->valid_until)->subDays(10)->toDateString())->assertOk();
    expect(RenewalWindow::refusalFor($prior))->toBeNull();
});

it('takes only a real date near today as the pretend date', function () {
    foreach (['2027-02-30', '25/01/2027', 'tomorrow', now()->addYears(4)->toDateString()] as $bad) {
        flip('pretend_date', $bad)->assertStatus(422)->assertJsonValidationErrors('value');
    }
    expect(SystemSwitches::pretendDate())->toBeNull();
});

it('tells every signed-in screen the pretend date for its banner, and nobody else', function () {
    $this->getJson('/api/v1/system-notices')->assertUnauthorized();

    authAs('owner@biztrack.local');
    $this->getJson('/api/v1/system-notices')->assertOk()->assertJsonPath('data.pretend_date', null);

    flip('pretend_date', '2027-01-25')->assertOk();
    foreach (['owner@biztrack.local', 'bplo@biztrack.local', 'admin@biztrack.local'] as $email) {
        authAs($email);
        $this->getJson('/api/v1/system-notices')->assertOk()->assertJsonPath('data.pretend_date', '2027-01-25');
    }
});

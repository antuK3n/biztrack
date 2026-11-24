<?php

use App\Jobs\SendOwnerUpdateEmail;
use App\Mail\OneTimeCode;
use App\Mail\OwnerUpdate;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\EmailCode;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Services\NotificationService;
use App\Support\DebugPanel;
use App\Support\SystemSwitches;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * The `owner_emails` system switch (App\Support\SystemSwitches::ownerEmails).
 * Off, an owner still gets every in-app notice but no e-mail copy of it, so
 * testers filing during data collection are not mailed at every step (team
 * lead, 25 November 2026). The codes and the confirmation link are a different
 * path and keep going. With no row nothing changes.
 *
 * No helper functions are declared here: several test files declare their own
 * at the top level, and two of the same name in one run is a fatal error.
 */

it('e-mails an owner a copy of their notice while the switch is left alone', function () {
    Queue::fake();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    app(NotificationService::class)->push($owner, 'status', 'Fee adjusted', 'Your fees were updated.', '/dashboard');

    Queue::assertPushed(SendOwnerUpdateEmail::class, 1);
    Queue::assertPushed(SendOwnerUpdateEmail::class, fn (SendOwnerUpdateEmail $job) => $job->owner->is($owner)
        && $job->title === 'Fee adjusted');
});

it('still writes the owner’s in-app notice but queues no e-mail while the switch is off', function () {
    Queue::fake();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'off');

    app(NotificationService::class)->push($owner, 'status', 'Fee adjusted', 'Your fees were updated.', '/dashboard');

    expect(AppNotification::where('user_id', $owner->id)->where('title', 'Fee adjusted')->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('drops an e-mail already queued when the switch goes off before it is sent', function () {
    Mail::fake();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $job = new SendOwnerUpdateEmail($owner, 'Fee adjusted', 'Your fees were updated.', '/dashboard');

    SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'off');
    $job->handle();
    Mail::assertNothingSent();

    // The same job sends once the switch is back to its default, so the
    // silence above is the switch and not a job that could never send.
    SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'default');
    $job->handle();
    Mail::assertSent(OwnerUpdate::class, fn (OwnerUpdate $mail) => $mail->hasTo($owner->email));
});

it('still sends the sign-up and sign-in codes while the switch is off', function () {
    // A mailer that delivers somewhere, so codes are asked for at all.
    config(['mail.default' => 'smtp']);
    Mail::fake();
    SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'off');

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Liza',
        'last_name' => 'Soberano',
        'gender' => 'F',
        'email' => 'switch.off@example.com',
        'mobile_number' => '09171234567',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'data_privacy_consent' => true,
        ...homeAddress(),
    ])->assertCreated()->assertJsonPath('data.code_required', true);

    Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $mail) => $mail->hasTo('switch.off@example.com')
        && $mail->purpose === EmailCode::VERIFY);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertOk()->assertJsonPath('data.code_required', true);

    Mail::assertSent(OneTimeCode::class, fn (OneTimeCode $mail) => $mail->hasTo('owner@biztrack.local')
        && $mail->purpose === EmailCode::LOGIN);
    Mail::assertNotSent(OwnerUpdate::class);
});

it('still sends the confirmation link at sign-up while the switch is off', function () {
    Notification::fake();
    Mail::fake();
    SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'off');

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Liza',
        'last_name' => 'Soberano',
        'gender' => 'F',
        'email' => 'link.still@example.com',
        'mobile_number' => '09171234567',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'data_privacy_consent' => true,
        ...homeAddress(),
    ])->assertCreated();

    Notification::assertSentTo(User::where('email', 'link.still@example.com')->first(), VerifyEmailAddress::class);
});

it('switches owner e-mails off, on and back to the default, and reports each', function () {
    expect(SystemSwitches::state()[SystemSwitches::OWNER_EMAILS])->toBe(['on' => true, 'overridden' => false]);

    $change = SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'off');
    expect($change['before'])->toBe(['on' => true, 'overridden' => false])
        ->and($change['after'])->toBe(['on' => false, 'overridden' => true])
        ->and(SystemSwitches::ownerEmails())->toBeFalse()
        ->and(Setting::read('switch.owner_emails'))->toBe('off');

    SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'on');
    expect(SystemSwitches::state()[SystemSwitches::OWNER_EMAILS])->toBe(['on' => true, 'overridden' => true]);

    SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'default');
    expect(SystemSwitches::state()[SystemSwitches::OWNER_EMAILS])->toBe(['on' => true, 'overridden' => false])
        ->and(Setting::read('switch.owner_emails'))->toBeNull();

    expect(fn () => SystemSwitches::set(SystemSwitches::OWNER_EMAILS, 'maybe'))
        ->toThrow(InvalidArgumentException::class, 'Use on, off or default.');
});

it('takes the owner e-mail switch through the Debug page’s endpoint, audited', function () {
    DebugPanel::open(1);
    authAs('admin@biztrack.local');

    $this->putJson('/api/v1/debug/switches', ['switch' => 'owner_emails', 'value' => 'off'])
        ->assertOk()
        ->assertJsonPath('data.owner_emails.on', false)
        ->assertJsonPath('data.owner_emails.overridden', true);

    $row = AuditLog::where('action', 'debug.switches')->latest('id')->firstOrFail();
    expect($row->changes['before']['owner_emails']['on'])->toBeTrue()
        ->and($row->changes['after']['owner_emails']['on'])->toBeFalse();
});

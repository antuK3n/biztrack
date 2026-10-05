<?php

use App\Jobs\SendSms;
use App\Models\Application;
use App\Services\NotificationService;
use App\Services\Sms\LogSmsChannel;
use App\Services\Sms\SmsChannel;
use App\Services\Sms\SmsGateChannel;
use App\Support\PhMobile;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Real SMS through the Android phone gateway (sms-gate.app, Cloud Server
 * mode), chosen by SMS_DRIVER=smsgate. `log` stays the default. Never a real
 * request: every test fakes the gateway.
 */

const SMS_GATEWAY = 'https://api.sms-gate.app/3rdparty/v1/messages';

beforeEach(function () {
    Http::preventStrayRequests();
});

function useSmsGateway(?string $username = 'gw-user', ?string $password = 'gw-pass'): void
{
    config([
        'services.sms.driver' => 'smsgate',
        'services.sms.gateway.url' => SMS_GATEWAY,
        'services.sms.gateway.username' => $username,
        'services.sms.gateway.password' => $password,
    ]);
}

it('keeps the log driver unless smsgate is chosen', function () {
    expect(app(SmsChannel::class))->toBeInstanceOf(LogSmsChannel::class);

    useSmsGateway();

    expect(app(SmsChannel::class))->toBeInstanceOf(SmsGateChannel::class);
});

it('posts the text and number to the gateway with basic auth', function () {
    useSmsGateway();
    Http::fake([SMS_GATEWAY => Http::response(['id' => 'abc', 'state' => 'Pending'], 202)]);

    app(SmsChannel::class)->send('+639171234567', 'BizTrack: BIZ-2026-00001 is approved.');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === SMS_GATEWAY
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('gw-user:gw-pass')
        && $request->data() === [
            'textMessage' => ['text' => 'BizTrack: BIZ-2026-00001 is approved.'],
            'phoneNumbers' => ['+639171234567'],
        ]);
});

it('throws when the gateway answers with an error, so the job can retry', function () {
    useSmsGateway();
    Http::fake([SMS_GATEWAY => Http::response('Service Unavailable', 503)]);

    app(SmsChannel::class)->send('+639171234567', 'BizTrack: test.');
})->throws(RequestException::class);

it('sends nothing, and warns, when the gateway credentials are missing', function (?string $username, ?string $password) {
    useSmsGateway($username, $password);
    Http::fake();
    Log::spy();

    app(SmsChannel::class)->send('+639171234567', 'BizTrack: test.');

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'SMS_GATEWAY_USERNAME'))->once();
})->with([
    'no username' => [null, 'gw-pass'],
    'no password' => ['gw-user', ''],
]);

// --- Numbers ------------------------------------------------------------------

it('sends a Philippine mobile as +639', function (string $stored) {
    expect(PhMobile::e164($stored))->toBe('+639171234567');
})->with([
    '09 form' => '09171234567',
    '9 form' => '9171234567',
    '639 form' => '639171234567',
    '+639 form' => '+639171234567',
    'with spaces' => '0917 123 4567',
    'with dashes' => '+63-917-123-4567',
]);

it('refuses anything that is not a Philippine mobile', function (?string $stored) {
    expect(PhMobile::e164($stored))->toBeNull();
})->with([
    'a digit short' => '0917123456',
    'a landline' => '0281234567',
    'not 9 after 0' => '08171234567',
    'letters' => '09d',
    'another country' => '+19162255887',
    'nothing' => null,
]);

// --- Queued, after commit, never in the officer's way ---------------------------

function smsRejectableFiling(string $mobile = '09171234567'): Application
{
    $app = Application::whereIn('status', ['for_approval', 'pending_payment', 'approved', 'for_final_approval', 'returned'])
        ->notDecided()->whereHas('applicant')->firstOrFail();
    $app->applicant->update(['mobile_number' => $mobile]);

    return $app;
}

function smsReject(Application $app)
{
    authAs('bplo@biztrack.local');

    return test()->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'The lease contract is expired.']);
}

it('queues one text, as +639, for a key moment', function () {
    Queue::fake();
    $app = smsRejectableFiling('0917-123-4567');

    smsReject($app)->assertOk();

    Queue::assertPushed(SendSms::class, 1);
    Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->to === '+639171234567'
        && $job->kind === 'applicationRejected'
        && $job->userId === $app->applicant_user_id
        && $job->message === "BizTrack: {$app->tracking_id} was rejected. Open BizTrack for the reason.");
});

it('queues no text for a notice that is not a key moment', function () {
    Queue::fake();
    $app = smsRejectableFiling();

    app(NotificationService::class)->feeAdjusted($app);

    Queue::assertNotPushed(SendSms::class);
});

it('texts nobody about an action that was rolled back', function () {
    Queue::fake();
    $app = smsRejectableFiling();

    try {
        DB::transaction(function () use ($app) {
            app(NotificationService::class)->applicationRejected($app, 'Test.');
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNotPushed(SendSms::class);
});

it('skips, and warns, when the number is not a Philippine mobile', function () {
    Queue::fake();
    Log::spy();
    $app = smsRejectableFiling('0281234567');

    smsReject($app)->assertOk();

    Queue::assertNotPushed(SendSms::class);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'not a Philippine mobile')
        && $context === ['user_id' => $app->applicant_user_id, 'kind' => 'applicationRejected'])->once();
});

it('keeps the decision when the gateway fails, and logs the account and notice, not the text', function () {
    useSmsGateway();
    Http::fake([SMS_GATEWAY => Http::response('Service Unavailable', 503)]);
    Log::spy();
    $app = smsRejectableFiling();

    smsReject($app)->assertOk();

    expect($app->fresh()->status->value)->toBe('rejected');
    Http::assertSentCount(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'SMS could not be sent'
        && $context['user_id'] === $app->applicant_user_id
        && $context['kind'] === 'applicationRejected'
        && ! str_contains(json_encode($context), 'was rejected'))->once();
});

it('keeps the decision when the queue will not take the text', function () {
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('jobs table missing'));
    $app = smsRejectableFiling();

    smsReject($app)->assertOk();

    expect($app->fresh()->status->value)->toBe('rejected');
});

it('retries a gateway error on the worker and sends on a later attempt', function () {
    useSmsGateway();
    config(['queue.default' => 'database']);
    Http::fake([SMS_GATEWAY => Http::sequence()
        ->push('Service Unavailable', 503)
        ->push(['id' => 'abc', 'state' => 'Pending'], 202)]);

    Bus::dispatch(new SendSms('+639171234567', 'BizTrack: test.', 'applicationRejected', 1));
    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('attempts'))->toBe(1);

    $this->travel(61)->seconds();
    $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();

    Http::assertSentCount(2);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('sends nothing, and keeps the decision, when the gateway credentials are missing', function () {
    useSmsGateway(null, null);
    Http::fake();
    $app = smsRejectableFiling();

    smsReject($app)->assertOk();

    Http::assertNothingSent();
});

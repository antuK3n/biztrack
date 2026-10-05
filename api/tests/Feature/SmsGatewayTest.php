<?php

use App\Services\Sms\LogSmsChannel;
use App\Services\Sms\SmsChannel;
use App\Services\Sms\SmsGateChannel;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

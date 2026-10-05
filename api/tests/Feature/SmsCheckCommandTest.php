<?php

use Illuminate\Support\Facades\Http;

/*
 * biztrack:sms-check — one text, now, through the configured driver, for the
 * server once the gateway phone is set up. Prints success or the gateway's
 * error, and never the credentials.
 */

const SMS_CHECK_GATEWAY = 'https://api.sms-gate.app/3rdparty/v1/messages';

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.sms.driver' => 'smsgate',
        'services.sms.gateway.url' => SMS_CHECK_GATEWAY,
        'services.sms.gateway.username' => 'gw-user',
        'services.sms.gateway.password' => 'secret-gw-pass',
    ]);
});

it('sends the test text through the gateway and says so', function () {
    Http::fake([SMS_CHECK_GATEWAY => Http::response(['id' => 'abc', 'state' => 'Pending'], 202)]);

    $this->artisan('biztrack:sms-check', ['number' => '0917 123 4567'])
        ->expectsOutputToContain('driver:  smsgate')
        ->expectsOutputToContain('sent:    the gateway accepted the text to +639171234567.')
        ->doesntExpectOutputToContain('secret-gw-pass')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->data() === [
        'textMessage' => ['text' => 'BizTrack: SMS is working.'],
        'phoneNumbers' => ['+639171234567'],
    ]);
});

it('prints the gateway’s error and fails', function () {
    Http::fake([SMS_CHECK_GATEWAY => Http::response(['message' => 'Unauthorized'], 401)]);

    $this->artisan('biztrack:sms-check', ['number' => '09171234567'])
        ->expectsOutputToContain('gateway: HTTP 401 {"message":"Unauthorized"}')
        ->doesntExpectOutputToContain('secret-gw-pass')
        ->assertFailed();
});

it('sends nothing to a number that is not a Philippine mobile', function () {
    Http::fake();

    $this->artisan('biztrack:sms-check', ['number' => '0281234567'])
        ->expectsOutputToContain('not a Philippine mobile number')
        ->assertFailed();

    Http::assertNothingSent();
});

it('sends nothing without the gateway credentials', function () {
    config(['services.sms.gateway.password' => null]);
    Http::fake();

    $this->artisan('biztrack:sms-check', ['number' => '09171234567'])
        ->expectsOutputToContain('SMS_GATEWAY_USERNAME or SMS_GATEWAY_PASSWORD is not set')
        ->assertFailed();

    Http::assertNothingSent();
});

it('says the log driver only logs', function () {
    config(['services.sms.driver' => 'log']);
    Http::fake();

    $this->artisan('biztrack:sms-check', ['number' => '09171234567'])
        ->expectsOutputToContain('SMS_DRIVER is log, so no text was sent.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

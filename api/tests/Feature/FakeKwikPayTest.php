<?php

use App\Http\Controllers\FakeKwikPayController;
use App\Services\KwikPay\FakeKwikPay;
use App\Services\KwikPay\Signature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/*
 * The stand-in KwikPay used for demos and the e2e suite. What matters most is
 * that it cannot exist on a real server; after that, that it signs and checks
 * the way the merchant docs say KwikPay does, so a flow that works against it
 * is exercising the real rules.
 */

const FK_KEY = 'fake-key-for-tests';

beforeEach(function () {
    config([
        'payments.kwikpay.merchant' => 'FAKE01',
        'payments.kwikpay.key' => FK_KEY,
        'payments.kwikpay.payment_type' => '1',
        'payments.kwikpay.fake' => true,
        'app.frontend_url' => 'https://biztrack.test',
    ]);
    Http::preventStrayRequests();

    // The routes are registered at boot only when the fake is available, and
    // the test environment boots without KWIKPAY_FAKE; mount them here.
    Route::prefix('api/v1/fake-kwikpay')->group(function () {
        Route::post('api/transfer', [FakeKwikPayController::class, 'transfer']);
        Route::post('api/query', [FakeKwikPayController::class, 'query']);
        Route::post('api/me', [FakeKwikPayController::class, 'me']);
        Route::get('pay/{orderId}', [FakeKwikPayController::class, 'page']);
        Route::post('pay/{orderId}/{outcome}', [FakeKwikPayController::class, 'settle']);
        Route::get('qr/{orderId}', [FakeKwikPayController::class, 'qr']);
    });
});

function fkSigned(array $fields): array
{
    $fields = ['merchant' => 'FAKE01'] + $fields;
    $fields['sign'] = Signature::make($fields, FK_KEY);

    return $fields;
}

function fkOrder(string $orderId = 'PAY-2026-000001-AAAAAA', string $bank = 'gcash'): array
{
    return fkSigned([
        'payment_type' => '1',
        'amount' => '1500.00',
        'order_id' => $orderId,
        'bank_code' => $bank,
        'callback_url' => 'https://api.biztrack.test/api/v1/payments/kwikpay/callback',
        'return_url' => 'https://biztrack.test/applications/1/pay?payment=1&returned=1',
    ]);
}

it('is never available in production, whatever the flag says', function () {
    expect(FakeKwikPay::available())->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    expect(FakeKwikPay::available())->toBeFalse();

    app()->detectEnvironment(fn () => 'staging');
    expect(FakeKwikPay::available())->toBeFalse();

    app()->detectEnvironment(fn () => 'testing');
    config(['payments.kwikpay.fake' => false]);
    expect(FakeKwikPay::available())->toBeFalse();
});

it('answers 404 on every action once the fake is switched off', function () {
    config(['payments.kwikpay.fake' => false]);

    $this->postJson('/api/v1/fake-kwikpay/api/transfer', fkOrder())->assertNotFound();
    $this->postJson('/api/v1/fake-kwikpay/api/me', fkSigned([]))->assertNotFound();
});

it('checks the signature and refuses a reused order id, as KwikPay does', function () {
    $bad = fkOrder();
    $bad['sign'] = str_repeat('0', 32);
    $this->postJson('/api/v1/fake-kwikpay/api/transfer', $bad)->assertStatus(401)->assertJson(['status' => '0']);

    $this->postJson('/api/v1/fake-kwikpay/api/transfer', fkOrder())
        ->assertOk()
        ->assertJson(['status' => '1', 'qrcode_url' => '', 'gcash_qr_url' => ''])
        ->assertJsonPath('redirect_url', 'https://biztrack.test/api/v1/fake-kwikpay/pay/PAY-2026-000001-AAAAAA');

    $this->postJson('/api/v1/fake-kwikpay/api/transfer', fkOrder())->assertStatus(409);
});

it('answers QR Ph with a QR code rather than a link', function () {
    $res = $this->postJson('/api/v1/fake-kwikpay/api/transfer', fkOrder('Q-1', 'qrph'))->assertOk();

    expect($res->json('redirect_url'))->toBe('');
    expect($res->json('qrcode_url'))->toBe('https://biztrack.test/api/v1/fake-kwikpay/qr/Q-1');

    $svg = $this->get('/api/v1/fake-kwikpay/qr/Q-1')->assertOk();
    expect($svg->headers->get('Content-Type'))->toContain('image/svg+xml');
    expect($svg->getContent())->toContain('<svg');
});

it('sends a signed multipart callback with a six-decimal amount when the order is paid', function () {
    Http::fake(['api.biztrack.test/*' => Http::response('OK')]);
    $this->postJson('/api/v1/fake-kwikpay/api/transfer', fkOrder())->assertOk();

    $this->postJson('/api/v1/fake-kwikpay/api/query', fkSigned(['order_id' => 'PAY-2026-000001-AAAAAA']))
        ->assertJson(['status' => '1']);

    $this->post('/api/v1/fake-kwikpay/pay/PAY-2026-000001-AAAAAA/pay')
        ->assertRedirect('https://biztrack.test/applications/1/pay?payment=1&returned=1');

    Http::assertSent(function (HttpRequest $r) {
        if (! $r->isMultipart()) {
            return false;
        }
        $fields = collect($r->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])->all();

        return $fields['status'] === '5'
            && $fields['amount'] === '1500.000000'
            && $fields['order_id'] === 'PAY-2026-000001-AAAAAA'
            && Signature::verify($fields, FK_KEY);
    });

    $this->postJson('/api/v1/fake-kwikpay/api/query', fkSigned(['order_id' => 'PAY-2026-000001-AAAAAA']))
        ->assertJson(['status' => '5']);
});

it('reports an order it does not hold as 404 with status 0', function () {
    $this->postJson('/api/v1/fake-kwikpay/api/query', fkSigned(['order_id' => 'NOPE']))
        ->assertNotFound()
        ->assertJson(['status' => '0']);
});

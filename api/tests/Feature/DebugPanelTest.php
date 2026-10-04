<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\DebugPanel;
use App\Support\PaymentMode;

/*
 * The Debug page's gate (App\Support\DebugPanel, middleware `debug.panel`):
 * the super admin AND the panel opened from the server, else 404 — and the
 * Payments section behind it.
 *
 * APP_ENV is `testing` here, so the flag is required, which is the point: the
 * local bypass would otherwise hide the whole gate from these tests.
 */

beforeEach(function () {
    config([
        'payments.kwikpay.base_url' => 'https://kwikpay.test',
        'payments.kwikpay.merchant' => 'M100001',
        'payments.kwikpay.key' => 'test-merchant-key-not-real',
        'payments.kwikpay.payment_type' => '1',
    ]);
});

/** Every route the page has, so "every one answers 404" is checked, not sampled. */
function debugRoutes(): array
{
    return [
        ['GET', '/api/v1/debug/panel', []],
        ['GET', '/api/v1/debug/payments', []],
        // A wrong method must not answer 405: that would say the route exists.
        ['POST', '/api/v1/debug/panel', []],
        ['DELETE', '/api/v1/debug/payments', []],
        ['PUT', '/api/v1/debug/payments', ['charge' => 'full']],
        ['POST', '/api/v1/debug/payments/test', []],
    ];
}

function hitDebug(string $method, string $uri, array $body)
{
    return test()->json($method, $uri, $body);
}

it('answers 404 on every debug route while the panel is closed, even to the super admin', function () {
    expect(DebugPanel::isOpen())->toBeFalse();

    authAs('admin@biztrack.local');
    foreach (debugRoutes() as [$method, $uri, $body]) {
        hitDebug($method, $uri, $body)->assertNotFound();
    }
    expect(Setting::read('kwikpay_charge'))->toBeNull();
});

it('answers 404, never 403 or 401, to anyone but the super admin while it is open', function () {
    DebugPanel::open(2);

    foreach (['owner@biztrack.local', 'bplo@biztrack.local'] as $email) {
        authAs($email);
        foreach (debugRoutes() as [$method, $uri, $body]) {
            hitDebug($method, $uri, $body)->assertNotFound();
        }
    }

    // A guest too: a 401 would say the route exists.
    app('auth')->forgetGuards();
    foreach (debugRoutes() as [$method, $uri, $body]) {
        hitDebug($method, $uri, $body)->assertNotFound();
    }
    expect(Setting::read('kwikpay_charge'))->toBeNull();
});

it('lets the super admin in while the panel is open, and shuts again when it expires', function () {
    DebugPanel::open(6);
    authAs('admin@biztrack.local');

    $this->getJson('/api/v1/debug/payments')->assertOk()->assertJsonPath('data.mode', 'simulated');
    $panel = $this->getJson('/api/v1/debug/panel')->assertOk();
    expect($panel->json('data.local'))->toBeFalse()
        ->and($panel->json('data.open_until'))->toBe(DebugPanel::openUntil()?->toIso8601String());

    $this->travel(5)->hours();
    $this->getJson('/api/v1/debug/payments')->assertOk();

    // Nobody ran `off`: it closes itself.
    $this->travel(61)->minutes();
    expect(DebugPanel::isOpen())->toBeFalse();
    $this->getJson('/api/v1/debug/payments')->assertNotFound();
});

it('needs no flag when APP_ENV is local, but still only for the super admin', function () {
    $this->app['env'] = 'local';
    expect(DebugPanel::isOpen())->toBeTrue();

    authAs('admin@biztrack.local');
    $this->getJson('/api/v1/debug/payments')->assertOk();
    $this->getJson('/api/v1/debug/panel')->assertOk()
        ->assertJsonPath('data.local', true)
        ->assertJsonPath('data.open_until', null);

    authAs('bplo@biztrack.local');
    $this->getJson('/api/v1/debug/payments')->assertNotFound();
});

it('tells the web app whether to show the page, on the signed-in user alone', function () {
    $me = fn (string $email) => $this->withHeaders(authAs($email))->getJson('/api/v1/auth/me')->json('data.debug_panel');

    expect($me('admin@biztrack.local'))->toBeFalse();

    DebugPanel::open(1);
    expect($me('admin@biztrack.local'))->toBeTrue()
        ->and($me('bplo@biztrack.local'))->toBeFalse()
        ->and($me('owner@biztrack.local'))->toBeFalse();
});

it('opens only from the server, for 1 to 24 hours, and audits opening and closing', function () {
    $this->artisan('biztrack:debug-panel status')->expectsOutputToContain('Debug page: closed.')->assertSuccessful();

    $this->artisan('biztrack:debug-panel on --hours=0')->assertFailed();
    $this->artisan('biztrack:debug-panel on --hours=25')->assertFailed();
    $this->artisan('biztrack:debug-panel on --hours=two')->assertFailed();
    expect(DebugPanel::isOpen())->toBeFalse();

    $this->artisan('biztrack:debug-panel on --hours=3')
        ->expectsOutputToContain('It closes itself then.')
        ->assertSuccessful();
    expect(DebugPanel::openUntil()?->diffInMinutes(now()->addHours(3), true))->toBeLessThan(1.0);
    $this->artisan('biztrack:debug-panel status')->expectsOutputToContain('Debug page: open until')->assertSuccessful();

    // The default is six hours.
    $this->artisan('biztrack:debug-panel on')->assertSuccessful();
    expect(DebugPanel::openUntil()?->diffInMinutes(now()->addHours(6), true))->toBeLessThan(1.0);

    $this->artisan('biztrack:debug-panel off')->assertSuccessful();
    expect(DebugPanel::isOpen())->toBeFalse();

    $rows = AuditLog::where('action', 'like', 'debug.panel_%')->orderBy('id')->get();
    expect($rows->pluck('action')->all())->toBe(['debug.panel_opened', 'debug.panel_opened', 'debug.panel_closed'])
        ->and($rows[0]->user_id)->toBeNull()
        ->and($rows[0]->changes['before'])->toBe(['open_until' => null])
        ->and($rows[0]->changes['after']['open_until'])->not->toBeNull()
        ->and($rows[0]->changes)->toMatchArray(['via' => 'artisan', 'hours' => 3])
        ->and($rows[2]->changes['after'])->toBe(['open_until' => null]);

    $this->artisan('biztrack:debug-panel open')->assertFailed();
});

it('has no web or API door that opens the panel', function () {
    authAs('admin@biztrack.local');
    foreach (['/api/v1/debug/panel', '/api/v1/debug', '/api/v1/admin/debug-panel'] as $uri) {
        $this->postJson($uri, ['hours' => 6])->assertNotFound();
        $this->putJson($uri, ['hours' => 6])->assertNotFound();
    }
    expect(DebugPanel::isOpen())->toBeFalse();
});

it('switches how owners pay and what KwikPay collects from the panel, audited as one debug row each', function () {
    DebugPanel::open(2);
    authAs('admin@biztrack.local');

    $res = $this->putJson('/api/v1/debug/payments', ['mode' => 'kwikpay', 'charge' => 'test'])->assertOk();
    expect($res->json('data.mode'))->toBe('kwikpay')
        ->and($res->json('data.charge'))->toBe('test')
        ->and($res->json('data.test_amount'))->toBe('1.00');
    expect(PaymentMode::current())->toBe('kwikpay')->and(PaymentMode::charge())->toBe('test');

    $this->putJson('/api/v1/debug/payments', ['charge' => 'full'])->assertOk();
    expect(PaymentMode::charge())->toBe('full');

    $rows = AuditLog::where('action', 'debug.payments')->orderBy('id')->get();
    $admin = User::where('email', 'admin@biztrack.local')->value('id');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->user_id)->toBe($admin)
        ->and($rows[0]->changes['before'])->toMatchArray(['mode' => 'simulated', 'charge' => 'full'])
        ->and($rows[0]->changes['after'])->toMatchArray(['mode' => 'kwikpay', 'charge' => 'test', 'test_amount' => '1.00'])
        ->and($rows[1]->changes['before'])->toMatchArray(['mode' => 'kwikpay', 'charge' => 'test'])
        ->and($rows[1]->changes['after'])->toMatchArray(['mode' => 'kwikpay', 'charge' => 'full']);
    // One trail: the panel's door does not also write the admin API's rows.
    expect(AuditLog::where('action', 'like', 'payment_gateway.%')->count())->toBe(0);
});

it('refuses a panel switch the way the admin API does, and changes neither half of a refused one', function () {
    DebugPanel::open(2);
    authAs('admin@biztrack.local');

    $this->putJson('/api/v1/debug/payments', [])->assertStatus(422)
        ->assertJsonPath('errors.mode.0', 'Say which way owners pay, or what KwikPay collects.');
    $this->putJson('/api/v1/debug/payments', ['charge' => 'half'])->assertStatus(422)->assertJsonValidationErrors('charge');

    config(['payments.kwikpay.key' => null]);
    $refused = $this->putJson('/api/v1/debug/payments', ['mode' => 'kwikpay', 'charge' => 'test'])->assertStatus(422);
    expect($refused->json('errors.mode.0'))->toContain('KWIKPAY_KEY');
    expect(PaymentMode::current())->toBe('simulated')
        ->and(Setting::read('kwikpay_charge'))->toBeNull()
        ->and(AuditLog::where('action', 'debug.payments')->count())->toBe(0);
});

it('never shows the merchant key on the panel', function () {
    DebugPanel::open(2);
    authAs('admin@biztrack.local');

    $shown = $this->getJson('/api/v1/debug/payments')->assertOk();
    expect($shown->json('data.kwikpay.configured'))->toBeTrue()
        ->and($shown->getContent())->not->toContain('test-merchant-key-not-real');
});

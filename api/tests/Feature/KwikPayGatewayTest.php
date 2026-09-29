<?php

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Payment;
use App\Models\User;
use App\Services\KwikPay\Signature;
use App\Services\WorkflowService;
use App\Support\PaymentMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Paying through KwikPay (docs/payment-gateway.md). No request here reaches
 * KwikPay: every outbound call is answered by Http::fake, and the server's
 * credentials are made-up test values.
 *
 * The rule under test throughout is the merchant docs' own: only a verified
 * callback carrying 5, or a "5" from /api/query, means paid.
 */

const KP_BASE = 'https://kwikpay.test';
const KP_MERCHANT = 'M100001';
const KP_KEY = 'test-merchant-key-not-real';

beforeEach(function () {
    config([
        'payments.kwikpay.base_url' => KP_BASE,
        'payments.kwikpay.merchant' => KP_MERCHANT,
        'payments.kwikpay.key' => KP_KEY,
        'payments.kwikpay.payment_type' => '1',
        'payments.kwikpay.callback_base_url' => 'https://api.biztrack.test',
        'payments.kwikpay.callback_ips' => [],
        'app.frontend_url' => 'https://biztrack.test',
    ]);

    // Nothing may leave the test process, whatever a test forgets to fake.
    Http::preventStrayRequests();
});

/** A filing BPLO has approved, owned by the demo applicant — ready to pay. */
function kpFiling(): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);

    app(WorkflowService::class)->submit($app);

    return bploApprovesForm($app);
}

function kpTransferAccepts(string $field = 'redirect_url', string $url = 'https://pay.kwikpay.test/abc'): void
{
    Http::fake([
        KP_BASE.'/api/transfer' => fn (HttpRequest $r) => Http::response([
            'status' => '1',
            'message' => 'Transfer initiated successfully',
            'amount' => (float) $r['amount'],
            'order_id' => $r['order_id'],
            'redirect_url' => $field === 'redirect_url' ? $url : '',
            'qrcode_url' => $field === 'qrcode_url' ? $url : '',
            'gcash_qr_url' => $field === 'gcash_qr_url' ? $url : '',
            'remark' => null,
        ]),
    ]);
}

/** Open a KwikPay payment through the owner's own endpoint. */
function kpOpen(Application $app, string $method = 'gcash'): Payment
{
    PaymentMode::set(PaymentMode::KWIKPAY);
    kpTransferAccepts();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => $method])
        ->assertCreated();

    return Payment::where('application_id', $app->id)->latest('id')->firstOrFail();
}

/** A deposit callback as KwikPay sends it: raw strings, six-decimal amount, signed. */
function kpCallback(Payment $payment, array $overrides = [], ?string $key = KP_KEY): array
{
    $fields = array_merge([
        'status' => '5',
        'amount' => number_format((float) $payment->amount, 6, '.', ''),
        'message' => '成功',
        'merchant' => KP_MERCHANT,
        'order_id' => $payment->gateway_order_id,
        'callback_url' => 'https://api.biztrack.test/api/v1/payments/kwikpay/callback',
    ], $overrides);
    $fields['sign'] = Signature::make($fields, (string) $key);

    return $fields;
}

function kpPostCallback(array $fields)
{
    // A form post, as KwikPay's multipart/form-data arrives: not JSON.
    return test()->post('/api/v1/payments/kwikpay/callback', $fields);
}

/* ── Opening a payment ───────────────────────────────────────────────────── */

it('leaves a KwikPay payment pending with somewhere to pay, and does not move the application', function () {
    $app = kpFiling();
    $payment = kpOpen($app, 'maya');

    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->gateway)->toBe('kwikpay');
    expect($payment->pay_url)->toBe('https://pay.kwikpay.test/abc');
    expect($payment->pay_url_kind)->toBe('link');
    expect($payment->paid_at)->toBeNull();
    // Accepted is not paid: the filing still waits for its money.
    expect($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);

    Http::assertSent(function (HttpRequest $r) use ($payment) {
        $body = $r->data();

        return $r->url() === KP_BASE.'/api/transfer'
            && $body['merchant'] === KP_MERCHANT
            && $body['bank_code'] === 'PMP'
            && $body['payment_type'] === '1'
            && $body['order_id'] === $payment->gateway_order_id
            && preg_match('/^\d+\.\d{2}$/', $body['amount'])
            && $body['callback_url'] === 'https://api.biztrack.test/api/v1/payments/kwikpay/callback'
            && str_contains($body['return_url'], "/applications/{$payment->application_id}/pay?payment={$payment->id}&returned=1")
            && Signature::verify($body, KP_KEY)
            && ! in_array(KP_KEY, $body, true);
    });
});

it('sends a plain ASCII order id that is not the bare reference number', function () {
    $payment = kpOpen(kpFiling());

    expect($payment->gateway_order_id)->toStartWith($payment->reference_number.'-')
        ->and($payment->gateway_order_id)->toMatch('/^[A-Z0-9-]+$/');
});

it('shows a QR image when KwikPay answers with a QR address instead of a link', function () {
    $app = kpFiling();
    PaymentMode::set(PaymentMode::KWIKPAY);
    kpTransferAccepts('qrcode_url', 'https://pay.kwikpay.test/qr.png');

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'qrph'])
        ->assertCreated();

    expect($res->json('data.pay_url'))->toBe('https://pay.kwikpay.test/qr.png');
    expect($res->json('data.pay_url_kind'))->toBe('qr');
    Http::assertSent(fn (HttpRequest $r) => $r['bank_code'] === 'qrph');
});

it('offers the methods of the current mode, and refuses the others', function () {
    $app = kpFiling();
    $owner = authAs('owner@biztrack.local');

    $simulated = $this->withHeaders($owner)->getJson("/api/v1/applications/{$app->id}/payment-options")->assertOk();
    expect($simulated->json('data.mode'))->toBe('simulated');
    expect(collect($simulated->json('data.methods'))->pluck('value')->all())->toBe(['gcash', 'maya', 'card']);

    // QR Ph is KwikPay's; simulated mode does not take it.
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'qrph'])->assertStatus(422);

    PaymentMode::set(PaymentMode::KWIKPAY);
    $kwik = $this->withHeaders($owner)->getJson("/api/v1/applications/{$app->id}/payment-options")->assertOk();
    expect($kwik->json('data.mode'))->toBe('kwikpay');
    expect(collect($kwik->json('data.methods'))->pluck('value')->all())->toBe(['gcash', 'maya', 'qrph', 'gotyme']);

    // Card exists only while payments are simulated.
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'card'])->assertStatus(422);
    expect(Payment::where('application_id', $app->id)->count())->toBe(0);
});

it('hands back the payment already in flight instead of opening a second order', function () {
    $app = kpFiling();
    $first = kpOpen($app);

    $again = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'maya'])
        ->assertOk();

    expect($again->json('data.id'))->toBe($first->id);
    expect(Payment::where('application_id', $app->id)->count())->toBe(1);
    Http::assertSentCount(1);
});

it('marks the payment failed when KwikPay clearly refuses the order, and tells the owner plainly', function () {
    $app = kpFiling();
    PaymentMode::set(PaymentMode::KWIKPAY);
    Http::fake([KP_BASE.'/api/transfer' => Http::response(['status' => '0', 'message' => 'Unknown bank_code'], 400)]);

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422);

    expect($res->json('errors.method.0'))->toContain('nothing was charged');
    $payment = Payment::where('application_id', $app->id)->sole();
    expect($payment->status)->toBe(PaymentStatus::Failed);
    expect($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);
});

it('leaves a timed-out transfer pending for reconciliation, never failed and never resent', function () {
    $app = kpFiling();
    PaymentMode::set(PaymentMode::KWIKPAY);
    $attempts = 0;
    Http::fake([KP_BASE.'/api/transfer' => function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('cURL error 28: Operation timed out');
    }]);

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422);

    expect($res->json('errors.method.0'))->toContain('did not answer in time');
    $payment = Payment::where('application_id', $app->id)->sole();
    // "No answer is not no payment": the order may exist at KwikPay.
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->pay_url)->toBeNull();
    expect($attempts)->toBe(1);
});

it('treats a 5xx from the transfer as unknown, not as a refusal', function () {
    $app = kpFiling();
    PaymentMode::set(PaymentMode::KWIKPAY);
    Http::fake([KP_BASE.'/api/transfer' => Http::response(['status' => '0', 'message' => 'Internal error (ref: 1a2b3c4d)'], 500)]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422);

    expect(Payment::where('application_id', $app->id)->sole()->status)->toBe(PaymentStatus::Pending);
});

/* ── The callback ────────────────────────────────────────────────────────── */

it('completes the payment on a signed status-5 callback and moves the application on', function () {
    $app = kpFiling();
    $payment = kpOpen($app);

    kpPostCallback(kpCallback($payment))->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed);
    expect($payment->paid_at)->not->toBeNull();
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
    expect(AuditLog::where('action', 'payment.completed')->where('auditable_id', $payment->id)->count())->toBe(1);
});

it('accepts the amount as KwikPay sends it, with six decimals', function () {
    $payment = kpOpen(kpFiling());
    $fields = kpCallback($payment);

    expect($fields['amount'])->toMatch('/^\d+\.000000$|^\d+\.\d{6}$/');
    kpPostCallback($fields)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
});

it('accepts a callback carrying a remark, including an empty one, because it signs what arrived', function () {
    $a = kpOpen(kpFiling());
    kpPostCallback(kpCallback($a, ['remark' => 'gateway ref 991']))->assertOk();
    expect($a->fresh()->status)->toBe(PaymentStatus::Completed);

    // An empty remark is signed as "" — ConvertEmptyStringsToNull must not touch it.
    $b = kpOpen(kpFiling());
    kpPostCallback(kpCallback($b, ['remark' => '']))->assertOk();
    expect($b->fresh()->status)->toBe(PaymentStatus::Completed);
});

it('also accepts the callback as JSON', function () {
    $payment = kpOpen(kpFiling());

    $this->postJson('/api/v1/payments/kwikpay/callback', kpCallback($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
});

it('refuses a callback with a wrong signature and credits nothing', function () {
    $app = kpFiling();
    $payment = kpOpen($app);

    $forged = kpCallback($payment, [], 'somebody-elses-key');
    kpPostCallback($forged)->assertForbidden();

    $tampered = kpCallback($payment);
    $tampered['status'] = '5';
    $tampered['amount'] = '1.000000';
    kpPostCallback($tampered)->assertForbidden();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    expect($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);
    expect(AuditLog::where('action', 'payment.callback_refused')->count())->toBe(2);
});

it('does not credit a callback whose amount differs from the payment, and flags it for staff', function () {
    $payment = kpOpen(kpFiling());

    kpPostCallback(kpCallback($payment, ['amount' => number_format((float) $payment->amount - 1, 6, '.', '')]))
        ->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->flagged_at)->not->toBeNull();

    $admin = User::where('email', 'admin@biztrack.local')->firstOrFail();
    expect(AppNotification::where('user_id', $admin->id)->where('type', 'payment_flagged')->count())->toBe(1);
});

it('does not credit a callback for another merchant, even when it is signed', function () {
    $payment = kpOpen(kpFiling());

    kpPostCallback(kpCallback($payment, ['merchant' => 'M999999']))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    expect(AuditLog::where('action', 'payment.callback_unmatched')->count())->toBe(1);
});

it('does nothing on a second copy of the same callback', function () {
    $app = kpFiling();
    $payment = kpOpen($app);
    $fields = kpCallback($payment);

    kpPostCallback($fields)->assertOk();
    $statusAfterFirst = $app->fresh()->status;
    $historyAfterFirst = $app->fresh()->statusHistory()->count();

    kpPostCallback($fields)->assertOk();

    expect($app->fresh()->status)->toBe($statusAfterFirst);
    expect($app->fresh()->statusHistory()->count())->toBe($historyAfterFirst);
    expect(AuditLog::where('action', 'payment.completed')->where('auditable_id', $payment->id)->count())->toBe(1);
});

it('answers a callback for an order it never opened with 200 and changes nothing', function () {
    $payment = kpOpen(kpFiling());

    kpPostCallback(kpCallback($payment, ['order_id' => 'PAY-2026-999999-NOPE00']))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    expect(AuditLog::where('action', 'payment.callback_unmatched')->count())->toBe(1);
});

it('marks the payment failed on a status-3 callback and leaves the application waiting', function () {
    $app = kpFiling();
    $payment = kpOpen($app);

    kpPostCallback(kpCallback($payment, ['status' => '3', 'message' => '拒絶']))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    expect($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);

    // And the owner can start again with a new order.
    kpTransferAccepts();
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertCreated();
    expect(Payment::where('application_id', $app->id)->count())->toBe(2);
});

it('refuses callbacks from outside the allowlist when one is set', function () {
    $payment = kpOpen(kpFiling());
    config(['payments.kwikpay.callback_ips' => ['34.21.238.122']]);

    kpPostCallback(kpCallback($payment))->assertForbidden();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    $this->withServerVariables(['REMOTE_ADDR' => '34.21.238.122']);
    kpPostCallback(kpCallback($payment))->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
});

/* ── Reconciliation ──────────────────────────────────────────────────────── */

function kpQueryAnswers(string $status, ?float $amount = null, int $http = 200): void
{
    Http::fake([
        KP_BASE.'/api/query' => fn (HttpRequest $r) => $status === '0'
            ? Http::response(['status' => '0', 'message' => 'Order not found'], $http)
            : Http::response([
                'status' => $status,
                'message' => 'x',
                'order_id' => $r['order_id'],
                'amount' => $amount ?? 0,
            ], $http),
    ]);
}

it('leaves a fresh payment alone for the first two minutes', function () {
    $payment = kpOpen(kpFiling());
    kpQueryAnswers('5', (float) $payment->amount);

    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/query'));
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('completes a payment KwikPay reports as 5 when reconciling', function () {
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers('5', (float) $payment->amount);

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/query')
        && $r['order_id'] === $payment->gateway_order_id
        && Signature::verify($r->data(), KP_KEY));
});

it('fails a payment KwikPay reports as 3 when reconciling', function () {
    $payment = kpOpen(kpFiling());
    kpQueryAnswers('3');

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
});

it('leaves a payment pending on 1, and backs off before asking again', function () {
    $payment = kpOpen(kpFiling());
    kpQueryAnswers('1', (float) $payment->amount);

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->check_attempts)->toBe(1);
    expect($payment->next_check_at->isFuture())->toBeTrue();

    // Run again at once: not due yet, so KwikPay is not asked a second time.
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();
    expect($payment->fresh()->check_attempts)->toBe(1);
});

it('never credits or fails a payment on 0', function () {
    $payment = kpOpen(kpFiling());
    kpQueryAnswers('0', null, 404);

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('flags a payment still pending after a day for staff, and does not fail it', function () {
    $payment = kpOpen(kpFiling());
    kpQueryAnswers('1');

    $this->travel(25)->hours();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->flagged_at)->not->toBeNull();

    $status = $this->withHeaders(authAs('admin@biztrack.local'))->getJson('/api/v1/admin/payment-gateway')->assertOk();
    expect($status->json('data.pending'))->toBe(1);
    expect($status->json('data.flagged.0.reference_number'))->toBe($payment->reference_number);
});

it('ignores a late callback for a payment reconciliation already completed', function () {
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers('5', (float) $payment->amount);
    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();
    $history = $app->fresh()->statusHistory()->count();

    kpPostCallback(kpCallback($payment))->assertOk();

    expect($app->fresh()->statusHistory()->count())->toBe($history);
    expect(AuditLog::where('action', 'payment.completed')->where('auditable_id', $payment->id)->count())->toBe(1);
});

it('lets the owner ask once for their payment status', function () {
    $payment = kpOpen(kpFiling());
    kpQueryAnswers('5', (float) $payment->amount);

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/payments/{$payment->id}/check")
        ->assertOk();

    expect($res->json('data.status'))->toBe('completed');
    // Settled: nowhere to pay any more.
    expect($res->json('data.pay_url'))->toBeNull();
});

it('does not let another account poll or check somebody else\'s payment', function () {
    $payment = kpOpen(kpFiling());

    // juan@ is another seeded business owner (see AuthorizationTest).
    authAs('juan@biztrack.local');

    $this->getJson("/api/v1/payments/{$payment->id}")->assertForbidden();
    $this->postJson("/api/v1/payments/{$payment->id}/check")->assertForbidden();
});

it('gives no receipt for a payment that has not gone through', function () {
    $payment = kpOpen(kpFiling());

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->get("/api/v1/payments/{$payment->id}/receipt")
        ->assertStatus(409);
});

/* ── The switch ──────────────────────────────────────────────────────────── */

it('refuses to switch KwikPay on while its credentials are missing', function () {
    config(['payments.kwikpay.key' => null, 'payments.kwikpay.payment_type' => '']);

    $res = $this->withHeaders(authAs('admin@biztrack.local'))
        ->putJson('/api/v1/admin/payment-gateway', ['mode' => 'kwikpay'])
        ->assertStatus(422);

    expect($res->json('errors.mode.0'))->toContain('KWIKPAY_KEY')->toContain('KWIKPAY_PAYMENT_TYPE');
    // Names only — never a value.
    expect($res->getContent())->not->toContain(KP_MERCHANT);
    expect(PaymentMode::current())->toBe('simulated');

    $this->artisan('biztrack:payment-gateway on')->assertFailed();
    expect(PaymentMode::current())->toBe('simulated');
});

it('lets only the super admin see or flip the switch, and audits every flip', function () {
    foreach (['owner@biztrack.local', 'bplo@biztrack.local'] as $email) {
        $this->withHeaders(authAs($email))->getJson('/api/v1/admin/payment-gateway')->assertForbidden();
        $this->withHeaders(authAs($email))->putJson('/api/v1/admin/payment-gateway', ['mode' => 'kwikpay'])->assertForbidden();
        $this->withHeaders(authAs($email))->postJson('/api/v1/admin/payment-gateway/test')->assertForbidden();
    }
    expect(PaymentMode::current())->toBe('simulated');

    $admin = authAs('admin@biztrack.local');
    $shown = $this->withHeaders($admin)->getJson('/api/v1/admin/payment-gateway')->assertOk();
    expect($shown->json('data.kwikpay.configured'))->toBeTrue();
    // The key never leaves the server.
    expect($shown->getContent())->not->toContain(KP_KEY);

    $this->withHeaders($admin)->putJson('/api/v1/admin/payment-gateway', ['mode' => 'kwikpay'])->assertOk();
    expect(PaymentMode::current())->toBe('kwikpay');

    $this->artisan('biztrack:payment-gateway off')->assertSuccessful();
    expect(PaymentMode::current())->toBe('simulated');

    $flips = AuditLog::where('action', 'payment_gateway.switched')->orderBy('id')->get();
    expect($flips)->toHaveCount(2);
    expect($flips[0]->changes)->toMatchArray(['from' => 'simulated', 'to' => 'kwikpay', 'via' => 'api']);
    expect($flips[0]->user_id)->toBe(User::where('email', 'admin@biztrack.local')->value('id'));
    expect($flips[1]->changes)->toMatchArray(['from' => 'kwikpay', 'to' => 'simulated', 'via' => 'artisan']);
});

it('tests the connection with one signed call to /api/me', function () {
    $allowlisted = true;
    // By reference, so flipping it below changes what the fake answers.
    Http::fake([KP_BASE.'/api/me' => function (HttpRequest $r) use (&$allowlisted) {
        return $allowlisted
            ? Http::response([
                'merchant' => $r['merchant'], 'merchant_display_name' => 'Malabon BPLO',
                'balance' => '0.0000', 'pending_balance' => '0.0000', 'sign' => $r['sign'],
            ])
            : Http::response(['status' => '1', 'message' => 'IP not found in allowed IPs for the merchant'], 403);
    }]);

    $res = $this->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/payment-gateway/test')
        ->assertOk();

    expect($res->json('data.ok'))->toBeTrue();
    Http::assertSent(fn (HttpRequest $r) => $r->url() === KP_BASE.'/api/me' && Signature::verify($r->data(), KP_KEY));

    $allowlisted = false;
    $this->artisan('biztrack:payment-gateway test')->assertFailed();
});

it('still confirms a KwikPay payment after the switch is turned off', function () {
    $app = kpFiling();
    $payment = kpOpen($app);

    PaymentMode::set(PaymentMode::SIMULATED);

    // The callback is still accepted…
    kpPostCallback(kpCallback($payment))->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);

    // …and so is reconciliation, for one whose callback never came.
    $app2 = kpFiling();
    $payment2 = kpOpen($app2);
    PaymentMode::set(PaymentMode::SIMULATED);
    kpQueryAnswers('5', (float) $payment2->amount);
    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();
    expect($payment2->fresh()->status)->toBe(PaymentStatus::Completed);
});

it('keeps simulated payments exactly as they were: completed at once, no gateway call', function () {
    $app = kpFiling();

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'card'])
        ->assertCreated();

    expect($res->json('data.status'))->toBe('completed');
    expect($res->json('data.gateway'))->toBe('simulated');
    expect($res->json('data.pay_url'))->toBeNull();
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
    Http::assertNothingSent();
});

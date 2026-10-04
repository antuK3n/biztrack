<?php

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\KwikPay\KwikPayGateway;
use App\Services\KwikPay\Signature;
use App\Services\NotificationService;
use App\Services\WorkflowService;
use App\Support\Heartbeat;
use App\Support\ManilaCalendar;
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
    $GLOBALS['kpQueryFaked'] = false;
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

/*
 * What /api/query answers, changeable within a test. Http::fake stubs are
 * matched first-registered-first, so registering a second stub for the same
 * URL would be silently ignored; one stub reads this instead.
 */
function kpQueryAnswers(string $status, ?float $amount = null, int $http = 200, string $message = 'x'): void
{
    $GLOBALS['kpQuery'] = compact('status', 'amount', 'http', 'message');

    if ($GLOBALS['kpQueryFaked'] ?? false) {
        return;
    }
    $GLOBALS['kpQueryFaked'] = true;

    Http::fake([
        KP_BASE.'/api/query' => function (HttpRequest $r) {
            ['status' => $status, 'amount' => $amount, 'http' => $http, 'message' => $message] = $GLOBALS['kpQuery'];

            if ($status === 'timeout') {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return $status === '0'
                ? Http::response(['status' => '0', 'message' => 'Order not found'], $http)
                : Http::response([
                    'status' => $status,
                    'message' => $message,
                    'order_id' => $r['order_id'],
                    'amount' => $amount ?? 0,
                ], $http);
        },
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
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
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

/*
 * One run asks about at most --limit payments, so which come first decides
 * which wait. A pending payment with no check scheduled at all (the query's
 * whereNull branch: nothing BizTrack writes leaves one, but a row fixed by
 * hand can) is due before any that is merely overdue. Left to the engine,
 * SQLite did that and PostgreSQL did the opposite — NULL sorts last there —
 * so on the production database such a row could wait behind every re-check.
 */
it('asks about a payment with no check scheduled before an overdue one', function () {
    $overdue = kpOpen(kpFiling());
    $unscheduled = kpOpen(kpFiling());
    $overdue->forceFill(['check_attempts' => 1, 'next_check_at' => now()->addMinute()])->save();
    $unscheduled->forceFill(['next_check_at' => null])->save();
    kpQueryAnswers('1', (float) $unscheduled->amount);

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments', ['--limit' => 1])->assertSuccessful();

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/query')
        && $r['order_id'] === $unscheduled->gateway_order_id);
    Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/query')
        && $r['order_id'] === $overdue->gateway_order_id);
});

/*
 * The default since 4 October 2026: KwikPay's answer to /api/query settles
 * nothing, because the gateway answered "5" for an order that expired unpaid.
 * Only the signed callback marks a payment paid.
 */
it('by default marks nothing paid or failed from KwikPay\'s answer when reconciling', function (string $answer) {
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers($answer, (float) $payment->amount);

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->check_attempts)->toBe(1)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);
})->with(['5', '3']);

it('by default sets a payment aside without completing it, whatever KwikPay answers', function () {
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers('5', (float) $payment->amount);

    app(KwikPayGateway::class)->abandon($payment);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->abandoned_at)->not->toBeNull()
        ->and($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);
});

it('by default still completes a payment on KwikPay\'s signed callback', function () {
    $app = kpFiling();
    $payment = kpOpen($app);

    kpPostCallback(kpCallback($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed)
        ->and(PaymentMode::confirm())->toBe(PaymentMode::CONFIRM_CALLBACK);
});

/*
 * `message`: how payment-gateway-kwgu.onrender.com reports an order. Its
 * /api/query answers "5" for every order it finds and puts the state in the
 * message, so "5" alone settles nothing.
 */
it('reads the answer\'s message: completed is paid, waiting is not, failed is failed', function (string $message, PaymentStatus $expected) {
    PaymentMode::setConfirm(PaymentMode::CONFIRM_MESSAGE);
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers('5', (float) $payment->gateway_amount, 200, $message);

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($payment->fresh()->status)->toBe($expected);
    if ($expected === PaymentStatus::Completed) {
        expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
    } else {
        expect($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);
    }
})->with([
    'completed' => ['Transaction completed successfully', PaymentStatus::Completed],
    'waiting' => ['Transaction is waiting to be processed', PaymentStatus::Pending],
    'failed' => ['Transaction failed', PaymentStatus::Failed],
    'unknown' => ['Transaction status unknown', PaymentStatus::Pending],
]);

it('reading the message, settles nothing on an answer that is not a successful lookup', function () {
    PaymentMode::setConfirm(PaymentMode::CONFIRM_MESSAGE);
    $payment = kpOpen(kpFiling());
    kpQueryAnswers('0', null, 400, 'Transaction completed successfully');

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('reading the message, sets a waiting payment aside without completing it', function () {
    PaymentMode::setConfirm(PaymentMode::CONFIRM_MESSAGE);
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers('5', (float) $payment->gateway_amount, 200, 'Transaction is waiting to be processed');

    app(KwikPayGateway::class)->abandon($payment);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->abandoned_at)->not->toBeNull();
});

it('switches to reading the message from the server', function () {
    $this->artisan('biztrack:payment-gateway', ['action' => 'read-message'])->assertSuccessful();
    expect(PaymentMode::confirm())->toBe(PaymentMode::CONFIRM_MESSAGE);
});

it('switches what marks a payment paid from the server, audit-logged', function () {
    $this->artisan('biztrack:payment-gateway', ['action' => 'trust-query'])->assertSuccessful();
    expect(PaymentMode::confirm())->toBe(PaymentMode::CONFIRM_QUERY);

    $this->artisan('biztrack:payment-gateway', ['action' => 'callback-only'])->assertSuccessful();
    expect(PaymentMode::confirm())->toBe(PaymentMode::CONFIRM_CALLBACK)
        ->and(AuditLog::where('action', 'payment_gateway.confirm_switched')->count())->toBe(2);
});

it('fails a payment KwikPay reports as 3 when reconciling', function () {
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
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
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
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

/*
 * Marking a payment paid and moving its filing happen together, or neither
 * does. A throw between the two used to leave a Completed payment on a filing
 * still at Pending Payment, which reconciliation never looked at again
 * (scenario run, payments-kwikpay 19); and one such throw ended the whole
 * reconciliation run (system-scheduler 28).
 */

/** The workflow, real except that moving the given payment's filing throws (any payment when null). */
function kpWorkflowThatFailsOn(?Payment $payment = null, bool $once = false): void
{
    $mock = Mockery::mock(WorkflowService::class, [app(NotificationService::class)])->makePartial();
    $failing = $mock->shouldReceive('onPaymentCompleted')
        ->with(Mockery::on(fn (Payment $p) => $payment === null || $p->id === $payment->id))
        ->andThrow(new RuntimeException('mail server down'));
    if ($once) {
        $failing->once();
    }
    $mock->shouldReceive('onPaymentCompleted')->passthru();
    app()->instance(WorkflowService::class, $mock);
}

it('leaves the payment pending when moving its filing fails, and settles both on the next try', function () {
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
    $app = kpFiling();
    $payment = kpOpen($app);
    kpWorkflowThatFailsOn($payment, once: true);

    kpPostCallback(kpCallback($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);

    // Pay again hands back the same order, not a new one for nothing.
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertOk()
        ->assertJsonPath('data.id', $payment->id);

    kpQueryAnswers('5', (float) $payment->amount);
    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);
});

it('carries on past a payment it cannot settle, and puts that one in front of staff after a day', function () {
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
    $stuck = kpOpen(kpFiling());
    $other = kpOpen(kpFiling());
    // The stuck one is asked about first.
    Payment::whereKey($stuck->id)->update(['next_check_at' => null]);
    kpQueryAnswers('5');
    kpWorkflowThatFailsOn($stuck);

    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($other->fresh()->status)->toBe(PaymentStatus::Completed)
        ->and(Heartbeat::last(Heartbeat::RECONCILE))->not->toBeNull();

    $this->travel(1)->days();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($stuck->fresh()->flagged_at)->not->toBeNull();
});

it('lets the owner ask once for their payment status', function () {
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
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
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
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

/* ── "Pay a different way" ───────────────────────────────────────────────── */

function kpAbandon(Payment $payment)
{
    return test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/payments/{$payment->id}/abandon");
}

it('completes instead of setting aside when KwikPay says the payment went through', function () {
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers('5', (float) $payment->amount);

    $res = kpAbandon($payment)->assertOk();

    expect($res->json('data.status'))->toBe('completed');
    expect($payment->fresh()->abandoned_at)->toBeNull();
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
});

it('marks the payment failed and frees a new one when KwikPay says it failed', function () {
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers('3');

    expect(kpAbandon($payment)->assertOk()->json('data.status'))->toBe('failed');

    $options = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/payment-options")->assertOk();
    expect($options->json('data.in_progress'))->toBeNull();
});

it('sets the payment aside, still pending, when KwikPay says it is waiting, unknown, or does not answer', function (string $answer) {
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers($answer, (float) $payment->amount, $answer === '0' ? 404 : 200);

    $res = kpAbandon($payment)->assertOk();

    // Not failed: the order may still be paid.
    expect($res->json('data.status'))->toBe('pending');
    expect($res->json('data.set_aside'))->toBeTrue();
    expect($res->json('data.pay_url'))->toBeNull();
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending);
    expect($payment->abandoned_at)->not->toBeNull();
    expect(AuditLog::where('action', 'payment.abandoned')->where('auditable_id', $payment->id)->count())->toBe(1);

    // No longer blocks: Pay opens a NEW order with a new order id.
    kpTransferAccepts();
    $new = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'maya'])
        ->assertCreated();
    expect($new->json('data.id'))->not->toBe($payment->id);
    expect(Payment::find($new->json('data.id'))->gateway_order_id)->not->toBe($payment->gateway_order_id);

    // …and it is still reconciled.
    kpQueryAnswers('3');
    $this->travel(3)->hours();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
})->with(['1', '0', 'timeout']);

it('flags a double payment when a set-aside payment turns out paid after the new one', function () {
    $app = kpFiling();
    $first = kpOpen($app);
    kpQueryAnswers('1');
    kpAbandon($first)->assertOk();

    // The owner pays the second way, and it completes.
    kpTransferAccepts();
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'maya'])
        ->assertCreated();
    $second = Payment::where('application_id', $app->id)->latest('id')->first();
    kpPostCallback(kpCallback($second))->assertOk();
    expect($second->fresh()->status)->toBe(PaymentStatus::Completed);
    $history = $app->fresh()->statusHistory()->count();

    // Then the set-aside one's callback arrives: it WAS paid too.
    kpPostCallback(kpCallback($first))->assertOk();

    $first->refresh();
    expect($first->status)->toBe(PaymentStatus::Completed);
    expect($first->refund_review_at)->not->toBeNull();
    // The application does not move a second time.
    expect($app->fresh()->statusHistory()->count())->toBe($history);
    expect(AuditLog::where('action', 'payment.double_paid')->where('auditable_id', $first->id)->count())->toBe(1);

    foreach (['admin@biztrack.local', 'bplo@biztrack.local'] as $email) {
        $user = User::where('email', $email)->firstOrFail();
        expect(AppNotification::where('user_id', $user->id)->where('type', 'payment_double_paid')->count())->toBe(1);
    }

    // The owner sees it in their payment history.
    $mine = collect($this->withHeaders(authAs('owner@biztrack.local'))->getJson('/api/v1/payments?per_page=100')->json('data'));
    expect($mine->firstWhere('id', $first->id)['refund_review'])->toBeTrue();
    expect($mine->firstWhere('id', $second->id)['refund_review'])->toBeFalse();
});

it('does not flag anything when a set-aside payment completes and nothing else was paid', function () {
    $app = kpFiling();
    $payment = kpOpen($app);
    kpQueryAnswers('1');
    kpAbandon($payment)->assertOk();

    kpPostCallback(kpCallback($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
    expect($payment->fresh()->refund_review_at)->toBeNull();
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
});

it('lets only the owner set a payment aside', function () {
    $payment = kpOpen(kpFiling());

    foreach (['juan@biztrack.local', 'bplo@biztrack.local', 'admin@biztrack.local'] as $email) {
        $this->withHeaders(authAs($email))->postJson("/api/v1/payments/{$payment->id}/abandon")->assertForbidden();
    }
    expect($payment->fresh()->abandoned_at)->toBeNull();
});

it('allows at most three set-asides per application per hour', function () {
    $app = kpFiling();
    PaymentMode::set(PaymentMode::KWIKPAY);

    for ($i = 0; $i < 3; $i++) {
        kpTransferAccepts();
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();
        kpQueryAnswers('1');
        kpAbandon(Payment::where('application_id', $app->id)->latest('id')->first())->assertOk();
    }

    kpTransferAccepts();
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();
    $fourth = Payment::where('application_id', $app->id)->latest('id')->first();
    kpQueryAnswers('1');

    $res = kpAbandon($fourth)->assertStatus(422);
    expect($res->json('errors.payment.0'))->toContain('several times in the last hour');
    expect($fourth->fresh()->abandoned_at)->toBeNull();

    $this->travel(61)->minutes();
    kpAbandon($fourth)->assertOk();
    expect($fourth->fresh()->abandoned_at)->not->toBeNull();
});

/* ── Testing with a token charge (KWIKPAY_CHARGE_OVERRIDE, no switch set) ── */

/*
 * These five predate the charge switch and are kept as they were: with no
 * `kwikpay_charge` row, the env override is still what decides, which is the
 * fallback the switch promises. The switch's own tests follow them.
 */

it('asks KwikPay for the override amount while the bill and payment keep the real amount', function () {
    config(['payments.kwikpay.charge_override' => '1.00']);
    $app = kpFiling();
    $payment = kpOpen($app);

    expect((float) $payment->amount)->toBeGreaterThan(1.0)
        ->and((float) $payment->gateway_amount)->toBe(1.0);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/transfer') && $r['amount'] === '1.00');
});

it('completes the full bill on a signed callback for the override amount', function () {
    config(['payments.kwikpay.charge_override' => '1.00']);
    $app = kpFiling();
    $payment = kpOpen($app);
    $billed = (float) $payment->amount;

    kpPostCallback(kpCallback($payment, ['amount' => '1.000000']))->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed)
        ->and((float) $payment->amount)->toBe($billed);
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
});

it('refuses a callback for the full bill when KwikPay was only asked for the override', function () {
    config(['payments.kwikpay.charge_override' => '1.00']);
    $payment = kpOpen(kpFiling());

    kpPostCallback(kpCallback($payment, ['amount' => number_format((float) $payment->amount, 6, '.', '')]));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('keeps checking an order against what was asked even after the override is removed', function () {
    config(['payments.kwikpay.charge_override' => '1.00']);
    $payment = kpOpen(kpFiling());
    config(['payments.kwikpay.charge_override' => null]);

    kpPostCallback(kpCallback($payment, ['amount' => '1.000000']))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
});

it('asks for the full bill when no override is set', function () {
    $payment = kpOpen(kpFiling());

    expect((float) $payment->gateway_amount)->toBe((float) $payment->amount);
});

/* ── The charge switch: a test charge or the full bill ──────────────────── */

/*
 * The super admin's second switch (PaymentMode::charge). The defense takes
 * real payments through KwikPay at ₱1, and a panelist may ask to see the real
 * amount, so it flips on a running server from the Online Payments screen
 * [Ken, 2026-10-04]. The bill, the payment and the receipt keep the assessed
 * amount whichever way it points.
 */

it('falls back to the env when no charge is stored: a positive override is a test charge, anything else the full bill', function (mixed $override, string $charge, float $testAmount) {
    config(['payments.kwikpay.charge_override' => $override]);

    expect(PaymentMode::charge())->toBe($charge)
        ->and(PaymentMode::defaultCharge())->toBe($charge)
        ->and(PaymentMode::testAmount())->toBe($testAmount)
        ->and(KwikPayGateway::chargeFor(2500.0))->toBe($charge === 'test' ? $testAmount : 2500.0);
})->with([
    'no override' => [null, 'full', 1.0],
    'an empty override' => ['', 'full', 1.0],
    'one peso' => ['1.00', 'test', 1.0],
    'another amount, rounded to centavos' => ['2.499', 'test', 2.5],
    'zero is not an amount' => ['0', 'full', 1.0],
    'a negative is not an amount' => ['-5', 'full', 1.0],
    'words are not an amount' => ['one peso', 'full', 1.0],
]);

it('lets a stored charge beat the env in both directions, and ignores a stored value it does not know', function () {
    // Env says test; the switch says full, and wins.
    config(['payments.kwikpay.charge_override' => '1.00']);
    PaymentMode::switchCharge('full', 'api');
    expect(PaymentMode::charge())->toBe('full')
        ->and(KwikPayGateway::chargeFor(2500.0))->toBe(2500.0);

    // Env says nothing; the switch says test, and ₱1.00 is what test means.
    config(['payments.kwikpay.charge_override' => null]);
    PaymentMode::switchCharge('test', 'api');
    expect(PaymentMode::charge())->toBe('test')
        ->and(KwikPayGateway::chargeFor(2500.0))->toBe(1.0);

    // The override is still the test AMOUNT while the switch decides WHETHER.
    config(['payments.kwikpay.charge_override' => '5']);
    expect(KwikPayGateway::chargeFor(2500.0))->toBe(5.0);

    // A row nobody's code wrote is not a decision: the env's default stands.
    Setting::write('kwikpay_charge', 'half');
    expect(PaymentMode::charge())->toBe('test');
    config(['payments.kwikpay.charge_override' => null]);
    expect(PaymentMode::charge())->toBe('full');

    expect(fn () => PaymentMode::switchCharge('half', 'api'))->toThrow(InvalidArgumentException::class);
});

it('asks KwikPay for what the switch says when the payment is opened', function () {
    PaymentMode::switchCharge('test', 'api');
    $test = kpOpen(kpFiling());
    expect((float) $test->gateway_amount)->toBe(1.0)
        ->and((float) $test->amount)->toBeGreaterThan(1.0);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/transfer')
        && $r['order_id'] === $test->gateway_order_id && $r['amount'] === '1.00');

    PaymentMode::switchCharge('full', 'api');
    $full = kpOpen(kpFiling());
    expect((float) $full->gateway_amount)->toBe((float) $full->amount);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/transfer')
        && $r['order_id'] === $full->gateway_order_id
        && $r['amount'] === number_format((float) $full->amount, 2, '.', ''));
});

it('lets only the super admin switch the charge, and audits who, how, from and to', function () {
    foreach (['owner@biztrack.local', 'bplo@biztrack.local'] as $email) {
        $this->withHeaders(authAs($email))
            ->putJson('/api/v1/admin/payment-gateway', ['charge' => 'full'])
            ->assertForbidden();
    }
    expect(Setting::read('kwikpay_charge'))->toBeNull();

    $admin = authAs('admin@biztrack.local');
    $shown = $this->withHeaders($admin)->getJson('/api/v1/admin/payment-gateway')->assertOk();
    expect($shown->json('data.charge'))->toBe('full')
        ->and($shown->json('data.default_charge'))->toBe('full')
        ->and($shown->json('data.test_amount'))->toBe('1.00');

    $res = $this->withHeaders($admin)
        ->putJson('/api/v1/admin/payment-gateway', ['charge' => 'test'])
        ->assertOk();
    expect($res->json('data.charge'))->toBe('test')
        // The switch is about what is collected, not which way owners pay.
        ->and($res->json('data.mode'))->toBe('simulated');

    $this->withHeaders($admin)->putJson('/api/v1/admin/payment-gateway', ['charge' => 'full'])->assertOk();
    expect(PaymentMode::charge())->toBe('full');

    $flips = AuditLog::where('action', 'payment_gateway.charge_switched')->orderBy('id')->get();
    $adminId = User::where('email', 'admin@biztrack.local')->value('id');
    expect($flips)->toHaveCount(2)
        ->and($flips[0]->changes)->toMatchArray(['from' => 'full', 'to' => 'test', 'via' => 'api', 'test_amount' => '1.00'])
        ->and($flips[0]->user_id)->toBe($adminId)
        ->and($flips[1]->changes)->toMatchArray(['from' => 'test', 'to' => 'full', 'via' => 'api'])
        ->and($flips[1]->user_id)->toBe($adminId);
    // The mode was never touched, so it was never logged as switched.
    expect(AuditLog::where('action', 'payment_gateway.switched')->count())->toBe(0);
});

it('wants a mode or a charge, refuses anything else, and still takes a mode on its own', function () {
    $admin = authAs('admin@biztrack.local');

    $empty = $this->withHeaders($admin)->putJson('/api/v1/admin/payment-gateway', [])->assertStatus(422);
    expect($empty->json('errors.mode.0'))->toBe('Say which way owners pay, or what KwikPay collects.');

    $this->withHeaders($admin)->putJson('/api/v1/admin/payment-gateway', ['charge' => 'half'])
        ->assertStatus(422)->assertJsonValidationErrors('charge');
    $this->withHeaders($admin)->putJson('/api/v1/admin/payment-gateway', ['mode' => 'cash', 'charge' => 'test'])
        ->assertStatus(422)->assertJsonValidationErrors('mode');
    expect(Setting::read('kwikpay_charge'))->toBeNull();

    // A caller from before the charge switch existed, unchanged.
    $this->withHeaders($admin)->putJson('/api/v1/admin/payment-gateway', ['mode' => 'kwikpay'])->assertOk();
    expect(PaymentMode::current())->toBe('kwikpay')
        ->and(Setting::read('kwikpay_charge'))->toBeNull();

    // Both at once.
    $both = $this->withHeaders($admin)
        ->putJson('/api/v1/admin/payment-gateway', ['mode' => 'simulated', 'charge' => 'test'])
        ->assertOk();
    expect($both->json('data.mode'))->toBe('simulated')->and($both->json('data.charge'))->toBe('test');
});

it('changes neither switch when a request for both is refused on the mode', function () {
    config(['payments.kwikpay.key' => null]);

    $this->withHeaders(authAs('admin@biztrack.local'))
        ->putJson('/api/v1/admin/payment-gateway', ['mode' => 'kwikpay', 'charge' => 'test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mode');

    expect(PaymentMode::current())->toBe('simulated')
        ->and(Setting::read('kwikpay_charge'))->toBeNull()
        ->and(AuditLog::where('action', 'like', 'payment_gateway.%')->count())->toBe(0);
});

it('switches the charge from a terminal too, and says so in status', function () {
    $this->artisan('biztrack:payment-gateway status')
        ->expectsOutputToContain('KwikPay collects: the full bill')
        ->assertSuccessful();

    $this->artisan('biztrack:payment-gateway test-charge')
        ->expectsOutputToContain('KwikPay collects: the full bill → a ₱1.00 test charge.')
        ->assertSuccessful();
    expect(PaymentMode::charge())->toBe('test');

    $this->artisan('biztrack:payment-gateway status')
        ->expectsOutputToContain('KwikPay collects: a ₱1.00 test charge (the bill, receipt and records keep the real amount)')
        ->assertSuccessful();

    $this->artisan('biztrack:payment-gateway full-charge')->assertSuccessful();
    expect(PaymentMode::charge())->toBe('full');

    $flips = AuditLog::where('action', 'payment_gateway.charge_switched')->orderBy('id')->get();
    expect($flips)->toHaveCount(2)
        ->and($flips[0]->changes)->toMatchArray(['from' => 'full', 'to' => 'test', 'via' => 'artisan'])
        ->and($flips[0]->user_id)->toBeNull()
        ->and($flips[1]->changes)->toMatchArray(['from' => 'test', 'to' => 'full', 'via' => 'artisan']);

    $this->artisan('biztrack:payment-gateway half-charge')->assertFailed();
});

it('still confirms a test-charge payment for ₱1 after the switch moves to the full bill', function () {
    PaymentMode::switchCharge('test', 'api');
    $app = kpFiling();
    $payment = kpOpen($app);
    $billed = (float) $payment->amount;

    $this->withHeaders(authAs('admin@biztrack.local'))
        ->putJson('/api/v1/admin/payment-gateway', ['charge' => 'full'])
        ->assertOk();

    // A callback for the full bill is not what this order asked for…
    kpPostCallback(kpCallback($payment, ['amount' => number_format($billed, 6, '.', '')]))->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->flagged_at)->not->toBeNull();

    // …₱1 is, and it settles the whole bill.
    kpPostCallback(kpCallback($payment, ['amount' => '1.000000']))->assertOk();
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed)
        ->and((float) $payment->amount)->toBe($billed)
        ->and((float) $payment->gateway_amount)->toBe(1.0);
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
});

it('still confirms a full-bill payment for the full bill after the switch moves to the test charge', function () {
    // Written for the setting where KwikPay's answer to /api/query settles a payment.
    PaymentMode::setConfirm(PaymentMode::CONFIRM_QUERY);
    PaymentMode::switchCharge('full', 'api');
    $app = kpFiling();
    $payment = kpOpen($app);
    $billed = (float) $payment->amount;

    $this->withHeaders(authAs('admin@biztrack.local'))
        ->putJson('/api/v1/admin/payment-gateway', ['charge' => 'test'])
        ->assertOk();

    // Reconciliation hears ₱1 for a full-bill order: not credited.
    kpQueryAnswers('5', 1.0);
    $this->travel(3)->minutes();
    $this->artisan('biztrack:reconcile-payments')->assertSuccessful();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->flagged_at)->not->toBeNull();

    // The full bill, as the order was opened for, is.
    kpPostCallback(kpCallback($payment, ['amount' => number_format($billed, 6, '.', '')]))->assertOk();
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed)
        ->and((float) $payment->gateway_amount)->toBe($billed);
    expect($app->fresh()->status)->not->toBe(ApplicationStatus::PendingPayment);
});

it('tells the owner\'s pay screen the test charge only while it applies, and nothing else about the switch', function () {
    $app = kpFiling();
    $options = fn () => $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/payment-options")
        ->assertOk();

    // Simulated collects nothing, so a test charge there is not mentioned.
    PaymentMode::switchCharge('test', 'api');
    expect($options()->json('data.test_charge'))->toBeNull();

    PaymentMode::set(PaymentMode::KWIKPAY);
    $on = $options();
    expect($on->json('data.test_charge'))->toBe('1.00')
        ->and(array_keys($on->json('data')))->toBe(['mode', 'methods', 'in_progress', 'test_charge']);
    // The switch's own payload is the super admin's, not the owner's.
    expect($on->getContent())->not->toContain('default_charge')
        ->not->toContain('merchant')
        ->not->toContain(KP_KEY);

    config(['payments.kwikpay.charge_override' => '2.50']);
    expect($options()->json('data.test_charge'))->toBe('2.50');

    PaymentMode::switchCharge('full', 'api');
    expect($options()->json('data.test_charge'))->toBeNull();

    // Somebody else's application is still refused outright.
    authAs('juan@biztrack.local');
    $this->getJson("/api/v1/applications/{$app->id}/payment-options")->assertForbidden();
});

it('shows the owner what the gateway was asked to collect for their own payment', function () {
    PaymentMode::switchCharge('test', 'api');
    $app = kpFiling();
    $payment = kpOpen($app);

    // The switch moving later does not change what this payment says.
    PaymentMode::switchCharge('full', 'api');
    $shown = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/payments/{$payment->id}")
        ->assertOk();
    expect($shown->json('data.gateway_amount'))->toBe('1.00')
        ->and((float) $shown->json('data.amount'))->toBe((float) $payment->amount);

    // In flight, the pay screen resumes it with the same figure.
    $resumed = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/payment-options")
        ->assertOk();
    expect($resumed->json('data.in_progress.gateway_amount'))->toBe('1.00');

    // A simulated payment collects nothing through a gateway.
    PaymentMode::set(PaymentMode::SIMULATED);
    $other = kpFiling();
    $paid = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$other->id}/pay", ['method' => 'card'])
        ->assertCreated();
    expect($paid->json('data.gateway_amount'))->toBeNull();
});

it('counts the assessed amount in the collections report, never the ₱1 the gateway took', function () {
    PaymentMode::switchCharge('test', 'api');
    $payment = kpOpen(kpFiling());
    $billed = (float) $payment->amount;
    expect($billed)->toBeGreaterThan(1.0);

    $today = ManilaCalendar::today()->toDateString();
    $collected = fn () => (float) $this->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/analytics/reports/collections?from={$today}&to={$today}")
        ->assertOk()
        ->json('data.sections.2.total.amount');

    $before = $collected();
    kpPostCallback(kpCallback($payment, ['amount' => '1.000000']))->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);

    // The whole bill is what the City collected on paper; ₱1 is only what the test took.
    expect(round($collected() - $before, 2))->toBe(round($billed, 2));
});

<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\Payment;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\PaymentMode;
use App\Support\PermitFees;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;

/*
 * One payment per bill (Ken, 5 October 2026).
 *
 * `pay()` and `counterPayment()` read the balance, then charged, with nothing
 * held between the two. A second press landing in that window — the owner's
 * double tap, two clerks on two screens, or the owner paying online while BPLO
 * marks the bill paid — passed the same check and recorded a second payment
 * of the whole bill, or opened a second live KwikPay order (scenario run,
 * owner-pay 2; bplo-counter-payment 7, 8 and 12).
 *
 * The window is reproduced as the scenario run did: the second request is
 * fired from inside the first, at its in-flight query. One process cannot
 * wait for itself, so the second request's wait for the lock can only time
 * out here — `Sleep::fake` makes that instant. In a real double press it
 * waits for the first to finish, then re-checks and finds nothing owed or
 * the order already open.
 */

beforeEach(function () {
    config([
        'payments.kwikpay.base_url' => 'https://kwikpay.opb.test',
        'payments.kwikpay.merchant' => 'M-OPB',
        'payments.kwikpay.key' => 'opb-test-key-not-real',
        'payments.kwikpay.payment_type' => '1',
        'payments.kwikpay.callback_base_url' => 'https://api.biztrack.test',
        'payments.kwikpay.callback_ips' => [],
        'app.frontend_url' => 'https://biztrack.test',
    ]);
    Http::preventStrayRequests();
    Http::fake(fn (HttpRequest $r) => Http::response([
        'status' => '1', 'message' => 'ok', 'amount' => (float) $r['amount'], 'order_id' => $r['order_id'],
        'redirect_url' => 'https://pay.kwikpay.opb.test/'.$r['order_id'], 'qrcode_url' => '', 'gcash_qr_url' => '',
    ]));
    Sleep::fake(syncWithCarbon: true);
});

/** A new filing for the demo owner, BPLO-approved: Pending Payment. */
function opbFiling(): Application
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

/** Run `$second` once, from inside the first request, at its in-flight query. */
function opbInsideTheFirst(Closure $second): void
{
    $fired = false;
    DB::listen(function ($q) use (&$fired, $second) {
        if (! $fired && str_contains($q->sql, 'from "payments"') && str_contains($q->sql, '"pay_url" is not null')) {
            $fired = true;
            $second();
        }
    });
}

it('takes one payment when a second Pay lands while the first is charging', function () {
    $app = opbFiling();
    authAs('owner@biztrack.local');
    $inner = null;
    opbInsideTheFirst(function () use (&$inner, $app) {
        $inner = test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash']);
    });

    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'maya'])->assertCreated();

    $balance = PermitFees::balance($app->fresh());
    expect($inner?->status())->not->toBe(201)
        ->and(Payment::where('application_id', $app->id)->where('status', 'completed')->count())->toBe(1)
        ->and($balance['total_paid'])->toBe($balance['total_assessed']);
});

it('opens one KwikPay order when a second Pay lands while the first is opening it', function () {
    $app = opbFiling();
    PaymentMode::set(PaymentMode::KWIKPAY);
    authAs('owner@biztrack.local');
    $inner = null;
    opbInsideTheFirst(function () use (&$inner, $app) {
        $inner = test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash']);
    });

    $first = $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'maya'])->assertCreated();
    DB::flushQueryLog();

    expect($inner?->status())->not->toBe(201)
        ->and(Payment::where('application_id', $app->id)->whereNotNull('pay_url')->where('status', 'pending')->count())->toBe(1);

    // Pressed again once the first is done, it hands back the open order.
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));
});

it('records one counter payment when Mark as paid is pressed twice at once', function () {
    $app = opbFiling();
    authAs('bplo@biztrack.local');
    $inner = null;
    opbInsideTheFirst(function () use (&$inner, $app) {
        $inner = test()->postJson("/api/v1/applications/{$app->id}/counter-payment");
    });

    $this->postJson("/api/v1/applications/{$app->id}/counter-payment")->assertCreated();

    $balance = PermitFees::balance($app->fresh());
    expect($inner?->status())->not->toBe(201)
        ->and(Payment::where('application_id', $app->id)->where('method', 'counter')->count())->toBe(1)
        ->and($balance['total_paid'])->toBe($balance['total_assessed']);
});

it('takes no online payment when the owner pays while BPLO is marking the bill paid', function () {
    $app = opbFiling();
    authAs('bplo@biztrack.local');
    $inner = null;
    opbInsideTheFirst(function () use (&$inner, $app) {
        $bplo = auth()->user();
        authAs('owner@biztrack.local');
        $inner = test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash']);
        app('auth')->forgetGuards();
        Sanctum::actingAs($bplo);
    });

    $this->postJson("/api/v1/applications/{$app->id}/counter-payment")->assertCreated();

    expect($inner?->status())->not->toBe(201)
        ->and(Payment::where('application_id', $app->id)->where('status', 'completed')->pluck('method')->map->value->all())
        ->toBe(['counter']);

    // And once the counter payment is in, a Pay finds nothing owed.
    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'This application has nothing outstanding.');
});

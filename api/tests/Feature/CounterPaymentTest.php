<?php

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Payment;
use App\Models\PermitType;
use App\Models\User;
use App\Support\Numbering;
use App\Support\PermitFees;

/*
 * BPLO marks a filing paid "at the counter" [Ken, 4 Oct 2026].
 *
 * Some owners pay their bill in person at City Hall instead of online. Ken's
 * instruction: BPLO staff should "just find a user whose [filing is] pending
 * to be paid and they should be able to just mark it, no need to enter
 * anything" — so the endpoint takes nothing from the request, charges the
 * balance due, and runs every payment through `WorkflowService::onPaymentCompleted`,
 * the same as the owner's own `/pay`.
 */

/** A filing sitting on the bill, exactly as BPLO's approval leaves it. */
function filingAwaitingCounterPayment(): Application
{
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $businessPermit = PermitType::where('code', 'BUSINESS')->firstOrFail();

    $owner = authAs('owner@biztrack.local');

    $draft = test()->withHeaders($owner)
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPermit->id],
        ])
        ->assertCreated()
        ->json('data');

    attachRequiredDocuments($draft['id']);
    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$draft['id']}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', ApplicationStatus::ForApproval->value);

    return bploApprovesForm($draft['id']);
}

it('lets BPLO mark a filing paid at the counter, same as an online payment', function () {
    $filing = filingAwaitingCounterPayment();
    $balanceDue = PermitFees::balance($filing->fresh())['balance_due'];
    expect($balanceDue)->toBeGreaterThan(0);

    $bploId = User::where('email', 'bplo@biztrack.local')->value('id');
    $bplo = authAs('bplo@biztrack.local');

    $response = test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertCreated();

    $response->assertJsonPath('data.method', PaymentMethod::Counter->value)
        ->assertJsonPath('data.status', PaymentStatus::Completed->value);

    $payment = Payment::where('application_id', $filing->id)
        ->where('method', PaymentMethod::Counter->value)
        ->firstOrFail();

    expect(number_format((float) $payment->amount, 2))->toBe(number_format($balanceDue, 2))
        ->and($payment->status)->toBe(PaymentStatus::Completed)
        // No gateway: a counter payment never touched KwikPay.
        ->and($payment->gateway)->toBe(Payment::GATEWAY_SIMULATED);

    // The filing moved past Pending Payment — onPaymentCompleted ran.
    $filing->refresh();
    expect($filing->status)->not->toBe(ApplicationStatus::PendingPayment);

    // The Business Permit releases at payment, same as an online one.
    expect(
        $filing->permits()
            ->whereHas('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
            ->exists()
    )->toBeTrue();

    // Audited with the BPLO actor who pressed the button, not the system.
    $audit = AuditLog::where('action', 'payment.completed')
        ->where('auditable_id', $payment->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($bploId)
        ->and($audit->changes['via'] ?? null)->toBe('counter');
});

it('refuses a sanitary officer — application.review is shared, but counter payment is BPLO’s alone', function () {
    $filing = filingAwaitingCounterPayment();
    $sanitary = authAs('sanitary@biztrack.local');

    test()->withHeaders($sanitary)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertForbidden();

    expect(Payment::where('application_id', $filing->id)->where('method', PaymentMethod::Counter->value)->exists())
        ->toBeFalse();
});

it('refuses the owner', function () {
    $filing = filingAwaitingCounterPayment();
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertForbidden();
});

it('refuses the super admin — Ken named BPLO staff, not every office-wide reader', function () {
    $filing = filingAwaitingCounterPayment();
    $admin = authAs('admin@biztrack.local');

    test()->withHeaders($admin)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertForbidden();
});

it('refuses a filing BPLO has not approved yet, with a plain sentence', function () {
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $businessPermit = PermitType::where('code', 'BUSINESS')->firstOrFail();
    $owner = authAs('owner@biztrack.local');

    $draft = test()->withHeaders($owner)
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPermit->id],
        ])->assertCreated()->json('data');

    // Submitted, but BPLO has not approved the main form — ForApproval, not
    // PendingPayment, so there is nothing yet to pay.
    attachRequiredDocuments($draft['id']);
    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$draft['id']}/submit")
        ->assertOk();

    $bplo = authAs('bplo@biztrack.local');
    $res = test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$draft['id']}/counter-payment")
        ->assertStatus(422);

    expect($res->json('errors.status.0'))->toContain('BPLO has not approved');
});

it('refuses a filing with nothing outstanding, with a plain sentence', function () {
    $filing = filingAwaitingCounterPayment();
    $bplo = authAs('bplo@biztrack.local');

    // Settle it once, legitimately — then there is nothing left to collect.
    test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertCreated();

    $res = test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertStatus(422);

    expect($res->json('errors.status.0'))->toContain('nothing outstanding');
});

it('sets aside a waiting KwikPay payment instead of refusing, then records the counter payment', function () {
    $filing = filingAwaitingCounterPayment();
    $filing->loadMissing('feeAssessment');

    // An online payment the owner opened and never finished — still pending,
    // still with somewhere to pay it, exactly what `inFlight()` looks for.
    $waiting = Payment::create([
        'application_id' => $filing->id,
        'fee_assessment_id' => $filing->feeAssessment->id,
        'reference_number' => Numbering::paymentReference(),
        'gateway' => Payment::GATEWAY_KWIKPAY,
        'gateway_order_id' => 'PAY-TEST-'.random_int(100000, 999999),
        'amount' => $filing->feeAssessment->total_amount,
        'method' => PaymentMethod::Gcash,
        'status' => PaymentStatus::Pending,
        'pay_url' => 'https://kwikpay.example.test/pay/test',
    ]);

    $bplo = authAs('bplo@biztrack.local');

    test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertCreated();

    // Set aside, not failed and not refused — reconciliation can still find it.
    $waiting->refresh();
    expect($waiting->abandoned_at)->not->toBeNull()
        ->and($waiting->status)->toBe(PaymentStatus::Pending);

    // And the counter payment went through regardless.
    expect(
        Payment::where('application_id', $filing->id)
            ->where('method', PaymentMethod::Counter->value)
            ->where('status', PaymentStatus::Completed->value)
            ->exists()
    )->toBeTrue();

    expect(
        AuditLog::where('action', 'payment.abandoned')
            ->where('auditable_id', $waiting->id)
            ->exists()
    )->toBeTrue();
});

it('shows the owner a counter payment as "Paid at the counter"', function () {
    $filing = filingAwaitingCounterPayment();
    $bplo = authAs('bplo@biztrack.local');

    test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertCreated();

    $owner = authAs('owner@biztrack.local');
    $history = test()->withHeaders($owner)
        ->getJson('/api/v1/payments')
        ->assertOk()
        ->json('data');

    $row = collect($history)->firstWhere('application.id', $filing->id);

    expect($row)->not->toBeNull()
        ->and($row['method'])->toBe(PaymentMethod::Counter->value)
        // The value the API sends is the raw method; this is the label the
        // web client renders it as everywhere a payment's method is shown.
        ->and(PaymentMethod::from($row['method'])->label())->toBe('Paid at the counter');
});

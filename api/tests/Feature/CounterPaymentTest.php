<?php

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Department;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PermitType;
use App\Models\Role;
use App\Models\User;
use App\Support\Numbering;
use App\Support\PermitFees;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * BPLO marks a filing paid "at the counter".
 *
 * Some owners pay their bill in person at City Hall instead of online. Ken's
 * instruction of 4 October 2026 was that BPLO should "just mark it, no need to
 * enter anything". The thesis panel's revisions replaced that (matrix items 1,
 * 5 and 6), and these tests were rewritten for the new rule:
 *
 *  - only the BPLO officer HOLDING the filing's BPLO assignment may mark it
 *    paid; every other BPLO officer, the super admin, the other offices and
 *    the owner are refused with one sentence, and an unheld filing must be
 *    taken first;
 *  - the officer enters the OR number and the date paid, both required, and
 *    may attach a photo of the receipt;
 *  - the payment records them and who recorded it, links the owner's pending
 *    proof, and is audited with the officer, the filing and the OR.
 *
 * What did not change: the amount is the balance due, and every payment runs
 * through `WorkflowService::onPaymentCompleted`, the same as the owner's own
 * `/pay`.
 */

const OTC_HOLDER_ONLY = 'Only the officer handling this application can mark it as paid.';

beforeEach(function () {
    Storage::fake('local');
});

/** A filing sitting on the bill, exactly as BPLO's approval leaves it. */
function filingAwaitingCounterPayment(bool $taken = true): Application
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

    $filing = bploApprovesForm($draft['id']);

    // The demo BPLO officer takes the filing, as "Assign to Me" does.
    if ($taken) {
        authAs('bplo@biztrack.local');
        test()->postJson('/api/v1/assignments/'.counterBploAssignment($filing)->id.'/claim')->assertOk();
    }

    return $filing->fresh();
}

function counterBploAssignment(Application $filing): ApplicationAssignment
{
    return ApplicationAssignment::where('application_id', $filing->id)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();
}

/** What the officer types in the dialog: an OR number and the date it was paid. */
function counterOr(array $more = []): array
{
    return ['or_number' => '4200123', 'or_date' => now()->toDateString(), ...$more];
}

/** A second BPLO officer, so "another BPLO officer" is a real account. */
function counterSecondBploOfficer(): User
{
    $user = User::create([
        'name' => 'Second Counter',
        'first_name' => 'Second',
        'last_name' => 'Counter',
        'gender' => 'F',
        'email' => 'second.counter.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000003',
        'password' => 'biztrack1',
        'department_id' => Department::where('code', 'BPLO')->value('id'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', 'bplo_staff')->pluck('id'));

    return $user->fresh();
}

it('lets the BPLO officer holding the filing mark it paid at the counter, same as an online payment', function () {
    $filing = filingAwaitingCounterPayment();
    $balanceDue = PermitFees::balance($filing->fresh())['balance_due'];
    expect($balanceDue)->toBeGreaterThan(0);

    $bploId = User::where('email', 'bplo@biztrack.local')->value('id');
    $bplo = authAs('bplo@biztrack.local');

    $response = test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
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

it('records the OR number, the date paid and the officer on the payment', function () {
    $filing = filingAwaitingCounterPayment();
    $bploId = User::where('email', 'bplo@biztrack.local')->value('id');
    $paidOn = now()->subDays(2)->toDateString();

    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr([
        'or_number' => ' 7012345 ',
        'or_date' => $paidOn,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.or_number', '7012345')
        ->assertJsonPath('data.or_date', $paidOn);

    $payment = Payment::where('application_id', $filing->id)->where('method', 'counter')->firstOrFail();
    expect($payment->or_number)->toBe('7012345')
        ->and($payment->or_date->toDateString())->toBe($paidOn)
        ->and($payment->recorded_by_user_id)->toBe($bploId);

    // One audit row for the act: the officer, the filing and the OR.
    $audit = AuditLog::where('action', 'payment.completed')->where('auditable_id', $payment->id)->sole();
    expect($audit->user_id)->toBe($bploId)
        ->and($audit->created_at)->not->toBeNull()
        ->and($audit->changes['application_id'])->toBe($filing->id)
        ->and($audit->changes['tracking_id'])->toBe($filing->tracking_id)
        ->and($audit->changes['or_number'])->toBe('7012345')
        ->and($audit->changes['or_date'])->toBe($paidOn)
        ->and($audit->changes['proof_ids'])->toBe([]);
});

it('requires the OR number and the date paid, and refuses a date in the future', function () {
    $filing = filingAwaitingCounterPayment();
    authAs('bplo@biztrack.local');

    test()->postJson("/api/v1/applications/{$filing->id}/counter-payment")
        ->assertStatus(422)
        ->assertJsonPath('errors.or_number.0', 'Enter the OR number.')
        ->assertJsonPath('errors.or_date.0', 'Enter the date paid.');

    test()->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr(['or_date' => now()->addDay()->toDateString()]))
        ->assertStatus(422)
        ->assertJsonPath('errors.or_date.0', 'The date paid cannot be in the future.');

    expect(Payment::where('application_id', $filing->id)->count())->toBe(0)
        ->and($filing->fresh()->status)->toBe(ApplicationStatus::PendingPayment);
});

it('links the owner’s pending proof and the officer’s own photo to the payment', function () {
    $filing = filingAwaitingCounterPayment();

    authAs('owner@biztrack.local');
    $owners = test()->post("/api/v1/applications/{$filing->id}/payment-proofs", [
        'or_number' => '7012346',
        'or_date' => now()->subDay()->toDateString(),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    authAs('bplo@biztrack.local');
    test()->post("/api/v1/applications/{$filing->id}/counter-payment", counterOr([
        'or_number' => '7012346',
        'or_date' => now()->subDay()->toDateString(),
        'photo' => UploadedFile::fake()->createWithContent('or-7012346.pdf', "%PDF-1.4\n%%EOF\n"),
    ]), ['Accept' => 'application/json'])->assertCreated();

    $payment = Payment::where('application_id', $filing->id)->where('method', 'counter')->firstOrFail();
    $officers = PaymentProof::where('payment_id', $payment->id)->where('id', '!=', $owners)->sole();

    expect(PaymentProof::findOrFail($owners)->payment_id)->toBe($payment->id)
        ->and($officers->submitted_by_user_id)->toBe(User::where('email', 'bplo@biztrack.local')->value('id'))
        ->and($officers->original_filename)->toBe('or-7012346.pdf')
        ->and(Storage::disk('local')->get($officers->file_path))->toBe("%PDF-1.4\n%%EOF\n")
        ->and($payment->proofs()->count())->toBe(2);

    // Confirmed: nothing is pending any more.
    expect(PaymentProof::pendingFor($filing))->toBeNull();

    $audit = AuditLog::where('action', 'payment.completed')->where('auditable_id', $payment->id)->sole();
    expect($audit->changes['proof_ids'])->toBe([$owners, $officers->id]);
});

it('refuses another BPLO officer — the filing is with a colleague', function () {
    $filing = filingAwaitingCounterPayment();
    $other = counterSecondBploOfficer();

    app('auth')->forgetGuards();
    Sanctum::actingAs($other);
    test()->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
        ->assertForbidden()
        ->assertJsonPath('message', OTC_HOLDER_ONLY);

    expect(Payment::where('application_id', $filing->id)->count())->toBe(0);
});

it('refuses a filing nobody at BPLO has taken yet — it is taken first', function () {
    $filing = filingAwaitingCounterPayment(taken: false);
    expect(counterBploAssignment($filing)->officer_user_id)->toBeNull();

    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
        ->assertForbidden()
        ->assertJsonPath('message', OTC_HOLDER_ONLY);

    // Refused, and not claimed on the way past.
    expect(counterBploAssignment($filing)->fresh()->officer_user_id)->toBeNull()
        ->and(Payment::where('application_id', $filing->id)->count())->toBe(0);

    // Taken with Assign to Me, the same officer may.
    test()->postJson('/api/v1/assignments/'.counterBploAssignment($filing)->id.'/claim')->assertOk();
    test()->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())->assertCreated();
});

it('refuses a sanitary officer — application.review is shared, but the filing is BPLO’s', function () {
    $filing = filingAwaitingCounterPayment();
    $sanitary = authAs('sanitary@biztrack.local');

    test()->withHeaders($sanitary)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
        ->assertForbidden()
        ->assertJsonPath('message', OTC_HOLDER_ONLY);

    expect(Payment::where('application_id', $filing->id)->where('method', PaymentMethod::Counter->value)->exists())
        ->toBeFalse();
});

it('refuses the owner', function () {
    $filing = filingAwaitingCounterPayment();
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
        ->assertForbidden()
        ->assertJsonPath('message', OTC_HOLDER_ONLY);
});

it('refuses the super admin with the same sentence — it holds no filing at BPLO', function () {
    $filing = filingAwaitingCounterPayment();
    $admin = authAs('admin@biztrack.local');

    test()->withHeaders($admin)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
        ->assertForbidden()
        ->assertJsonPath('message', OTC_HOLDER_ONLY);
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
    // PendingPayment, so there is nothing yet to pay. Taken, so the officer
    // reaches the question at all.
    attachRequiredDocuments($draft['id']);
    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$draft['id']}/submit")
        ->assertOk();

    $bplo = authAs('bplo@biztrack.local');
    test()->postJson('/api/v1/assignments/'.counterBploAssignment(Application::findOrFail($draft['id']))->id.'/claim')->assertOk();

    $res = test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$draft['id']}/counter-payment", counterOr())
        ->assertStatus(422);

    expect($res->json('errors.status.0'))->toContain('BPLO has not approved');
});

it('refuses a filing with nothing outstanding, with a plain sentence', function () {
    $filing = filingAwaitingCounterPayment();
    $bplo = authAs('bplo@biztrack.local');

    // Settle it once, legitimately — then there is nothing left to collect.
    test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
        ->assertCreated();

    $res = test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
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
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr())
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

it('shows the owner a counter payment as "Paid at the counter", with its OR No.', function () {
    $filing = filingAwaitingCounterPayment();
    $bplo = authAs('bplo@biztrack.local');

    test()->withHeaders($bplo)
        ->postJson("/api/v1/applications/{$filing->id}/counter-payment", counterOr([
            'or_number' => '7012347',
            'or_date' => now()->subDay()->toDateString(),
        ]))
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
        ->and(PaymentMethod::from($row['method'])->label())->toBe('Paid at the counter')
        // Payment History prints the OR No. and the date paid from these.
        ->and($row['or_number'])->toBe('7012347')
        ->and($row['or_date'])->toBe(now()->subDay()->toDateString());
});

it('tells the Pending Payment tab who holds each filing at BPLO, and only BPLO and the super admin', function () {
    $filing = filingAwaitingCounterPayment();
    $bploId = User::where('email', 'bplo@biztrack.local')->value('id');

    authAs('bplo@biztrack.local');
    $row = collect(test()->getJson('/api/v1/applications?status=pending_payment&per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $filing->id);
    expect($row['bplo_assignment']['officer']['id'])->toBe($bploId)
        ->and($row['bplo_assignment']['can_act'])->toBeTrue()
        ->and($row['bplo_assignment']['can_claim'])->toBeFalse();

    // A colleague reads the same row as somebody else's.
    app('auth')->forgetGuards();
    Sanctum::actingAs(counterSecondBploOfficer());
    $row = collect(test()->getJson('/api/v1/applications?status=pending_payment&per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $filing->id);
    expect($row['bplo_assignment']['officer']['id'])->toBe($bploId)
        ->and($row['bplo_assignment']['can_act'])->toBeFalse();

    // The owner's own list carries no such thing.
    authAs('owner@biztrack.local');
    $row = collect(test()->getJson('/api/v1/applications?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $filing->id);
    expect($row)->not->toHaveKey('bplo_assignment');
});

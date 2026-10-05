<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\KwikPay\Signature;
use App\Services\WorkflowService;
use App\Support\DebugPanel;
use App\Support\PaymentMode;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/*
 * A suspended or blacklisted business's filings are on hold.
 *
 * The super admin's suspension used to reach the permits the business already
 * held and stop there: a filing in progress went on moving, and the next
 * payment or approval minted a fresh Active permit that /verify called valid
 * (scenario run, account-restriction 25, owner-pay 19, admin-owner-status 21).
 *
 * Ken's decisions for the fix: nothing moves a frozen business's filing toward
 * a permit; money that lands on one anyway is recorded and held, and the filing
 * continues — without the owner paying again — once the business is put back.
 *
 * No request leaves the process: KwikPay is answered by Http::fake.
 */

const HOLD_KP_BASE = 'https://kwikpay.hold.test';
const HOLD_KP_MERCHANT = 'M-HOLD';
const HOLD_KP_KEY = 'hold-test-key-not-real';

beforeEach(function () {
    // Visits are booked on a weekday in office hours (manage item 4).
    duringOfficeHours();
    config([
        'payments.kwikpay.base_url' => HOLD_KP_BASE,
        'payments.kwikpay.merchant' => HOLD_KP_MERCHANT,
        'payments.kwikpay.key' => HOLD_KP_KEY,
        'payments.kwikpay.payment_type' => '1',
        'payments.kwikpay.callback_base_url' => 'https://api.biztrack.test',
        'payments.kwikpay.callback_ips' => [],
        'app.frontend_url' => 'https://biztrack.test',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        HOLD_KP_BASE.'/api/transfer' => fn (HttpRequest $r) => Http::response([
            'status' => '1', 'message' => 'Transfer initiated successfully',
            'amount' => (float) $r['amount'], 'order_id' => $r['order_id'],
            'redirect_url' => 'https://pay.kwikpay.hold.test/abc', 'qrcode_url' => '', 'gcash_qr_url' => '',
        ]),
    ]);
});

/** A new filing BPLO has approved, on the demo owner's business (or the one given): ready to pay. */
function holdFiling(?Business $business = null): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business ??= Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);
    app(WorkflowService::class)->submit($app);

    return bploApprovesForm($app);
}

/** Another business for the demo owner, registered through the API. */
function holdSecondBusiness(): Business
{
    $id = test()->withHeaders(authAs('owner@biztrack.local'))->postJson('/api/v1/businesses', [
        'name' => 'Held Annex '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Hold Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    return Business::findOrFail($id);
}

/** The owner opens a KwikPay order for it, before anything is suspended. */
function holdOpenKwikPay(Application $app): Payment
{
    PaymentMode::set(PaymentMode::KWIKPAY);
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertCreated();

    return Payment::where('application_id', $app->id)->latest('id')->firstOrFail();
}

/** KwikPay's signed status-5 callback for that order: the owner paid. */
function holdPaid(Payment $payment): void
{
    $fields = [
        'status' => '5',
        'amount' => number_format((float) $payment->amount, 6, '.', ''),
        'message' => 'ok',
        'merchant' => HOLD_KP_MERCHANT,
        'order_id' => $payment->gateway_order_id,
    ];
    $fields['sign'] = Signature::make($fields, HOLD_KP_KEY);

    app('auth')->forgetGuards();
    test()->post('/api/v1/payments/kwikpay/callback', $fields)->assertOk();
}

function holdSetStatus(Business|int $business, string $status)
{
    $id = $business instanceof Business ? $business->id : $business;

    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$id}/status", ['status' => $status, 'reason' => 'Scenario reason.']);
}

const HOLD_SUSPENDED = 'This business is suspended, so its filings are on hold until the super admin lifts the suspension.';
const HOLD_BLACKLISTED = 'This business is blacklisted, so its filings are on hold until the super admin lifts the blacklisting.';

function holdBusinessPermit(Application $app): ?Permit
{
    return Permit::where('application_id', $app->id)
        ->whereHas('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
        ->first();
}

/* ── Money that lands while the business is suspended ───────────────────── */

it('records a KwikPay payment that lands while the business is suspended, and holds the filing', function () {
    $app = holdFiling();
    $payment = holdOpenKwikPay($app);
    holdSetStatus($app->business_id, 'suspended')->assertOk();

    holdPaid($payment);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment)
        ->and(holdBusinessPermit($app))->toBeNull();
});

it('moves the held filing on when the business is reinstated, without a second payment', function (string $backTo) {
    $app = holdFiling();
    $payment = holdOpenKwikPay($app);
    holdSetStatus($app->business_id, 'suspended')->assertOk();
    holdPaid($payment);

    holdSetStatus($app->business_id, $backTo)->assertOk();

    $permit = holdBusinessPermit($app);
    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($permit?->status)->toBe(PermitStatus::Active)
        ->and(Payment::where('application_id', $app->id)->count())->toBe(1);
})->with(['active', 'flagged']);

it('holds money that lands while the owner is blacklisted, and moves it on when the blacklisting is lifted', function () {
    $app = holdFiling();
    $payment = holdOpenKwikPay($app);
    holdSetStatus($app->business_id, 'blacklisted')->assertOk();
    holdPaid($payment);

    expect($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment)
        ->and(holdBusinessPermit($app))->toBeNull();

    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/owners/{$owner->id}/lift-blacklist", ['status' => 'active', 'reason' => 'Finding withdrawn.'])
        ->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and(holdBusinessPermit($app)?->status)->toBe(PermitStatus::Active);
});

it('moves on a held filing of the owner\'s other business when the owner is reinstated on Owner Status', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $first = Business::where('owner_user_id', $owner->id)->orderBy('id')->firstOrFail();
    $app = holdFiling(holdSecondBusiness());
    $payment = holdOpenKwikPay($app);
    holdSetStatus($first, 'blacklisted')->assertOk();
    holdPaid($payment);
    expect($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);

    /*
     * A business stays locked while its owner is blacklisted (Mike's Owner
     * Status, client 5 October 2026), so the way back is the owner's row:
     * reinstating the person returns the businesses the blacklisting
     * suspended, and the filing held on one of them moves on.
     */
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/owners/{$owner->id}/status", ['status' => 'active', 'reason' => 'Finding withdrawn.'])
        ->assertOk();

    expect($app->business->fresh()->status)->toBe('active')
        ->and($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

/* ── Nothing else moves a held filing toward a permit ───────────────────── */

it('refuses BPLO marking a held filing paid at the counter, with the sentence for each sanction', function (string $status, string $sentence) {
    $app = holdFiling();
    holdSetStatus($app->business_id, $status)->assertOk();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/counter-payment")
        ->assertStatus(422)
        ->assertJsonPath('message', $sentence);

    expect(Payment::where('application_id', $app->id)->count())->toBe(0)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment)
        ->and(holdBusinessPermit($app))->toBeNull();
})->with([
    'suspended' => ['suspended', HOLD_SUSPENDED],
    'blacklisted' => ['blacklisted', HOLD_BLACKLISTED],
]);

it('refuses the owner starting a payment on a held filing, online or simulated, and takes nothing', function (string $mode, string $method) {
    /*
     * Ken, 5 October 2026: a suspended business no longer bars its owner's
     * account, so the owner reaches Pay again — and a payment taken on a
     * filing that is on hold could not move it. Refused before anything is
     * recorded or sent to KwikPay, with the hold's own sentence.
     */
    PaymentMode::set($mode === 'kwikpay' ? PaymentMode::KWIKPAY : PaymentMode::SIMULATED);
    $app = holdFiling();
    holdSetStatus($app->business_id, 'suspended')->assertOk();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => $method])
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);

    expect(Payment::where('application_id', $app->id)->count())->toBe(0)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);
    Http::assertNothingSent();
})->with([
    'simulated' => ['simulated', 'gcash'],
    'kwikpay' => ['kwikpay', 'gcash'],
]);

it('refuses BPLO accepting the form of a held filing', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $app = Application::create([
        'business_id' => Business::where('owner_user_id', $owner->id)->firstOrFail()->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);
    app(WorkflowService::class)->submit($app);
    holdSetStatus($app->business_id, 'suspended')->assertOk();

    $bplo = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', app(WorkflowService::class)->bploDepartmentId())
        ->value('id');
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bplo}/approve")
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval);
});

/*
 * The cases from here that act as CHO start from a filing sanitary@ already
 * holds (scopedFilingHeldByCho): since the request of 6 October 2026 an
 * unheld CHO case refuses every act with 403 before the hold is even asked
 * about, and the hold is what these cases are testing.
 */
it('refuses an office approving a held filing\'s permit', function () {
    $appId = scopedFilingHeldByCho('Held Approval Shop');
    holdSetStatus(Application::findOrFail($appId)->business_id, 'suspended')->assertOk();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/assignments/'.choAssignmentId($appId).'/approve')
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);

    $row = ApplicationPermitType::where('application_id', $appId)
        ->where('permit_type_id', PermitType::where('code', 'SANITARY')->value('id'))->firstOrFail();
    expect($row->status)->toBe(ClearanceStatus::ForApproval);
});

it('refuses a passing visit on a held filing and issues nothing, but still records a failed one', function () {
    $appId = scopedFilingHeldByCho('Held Visit Shop');
    $app = Application::findOrFail($appId);
    authAs('sanitary@biztrack.local');
    test()->postJson('/api/v1/assignments/'.choAssignmentId($appId).'/approve')->assertOk();
    $visit = test()->postJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection", [
        'scheduled_at' => now()->toDateTimeString(),
    ])->assertCreated()->json('data.id');

    holdSetStatus($app->business_id, 'suspended')->assertOk();
    $permit = holdBusinessPermit($app);
    expect($permit->status)->toBe(PermitStatus::Suspended);

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/inspections/{$visit}/conduct", ['result' => 'passed'])
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);

    expect(Inspection::findOrFail($visit)->result)->toBeNull()
        ->and($permit->fresh()->status)->toBe(PermitStatus::Suspended)
        ->and(clearancePermitsIssued($appId))->toBe(0);

    test()->postJson("/api/v1/inspections/{$visit}/conduct", ['result' => 'failed', 'findings' => 'No handwashing sink.'])
        ->assertOk();
});

it('refuses booking, moving or re-booking a visit on a held filing', function () {
    $appId = scopedFilingHeldByCho('Held Booking Shop');
    $app = Application::findOrFail($appId);
    authAs('sanitary@biztrack.local');
    test()->postJson('/api/v1/assignments/'.choAssignmentId($appId).'/approve')->assertOk();
    $visit = test()->postJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection", [
        'scheduled_at' => now()->toDateTimeString(),
    ])->assertCreated()->json('data.id');

    holdSetStatus($app->business_id, 'suspended')->assertOk();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/inspections/{$visit}/reschedule", ['scheduled_at' => now()->addWeekdays(5)->toDateTimeString()])
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);
    test()->postJson("/api/v1/inspections/{$visit}/conduct", ['result' => 'failed', 'findings' => 'No handwashing sink.'])
        ->assertOk();
    test()->postJson("/api/v1/inspections/{$visit}/reinspect", ['scheduled_at' => now()->addWeekdays(5)->toDateTimeString()])
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);

    expect(Inspection::where('application_id', $appId)->count())->toBe(1);
});

it('refuses booking the first visit on a held filing', function () {
    $appId = scopedFilingHeldByCho('Held First Visit Shop');
    $app = Application::findOrFail($appId);
    authAs('sanitary@biztrack.local');
    test()->postJson('/api/v1/assignments/'.choAssignmentId($appId).'/approve')->assertOk();

    holdSetStatus($app->business_id, 'suspended')->assertOk();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection", [
        'scheduled_at' => now()->addWeekdays(2)->toDateTimeString(),
    ])->assertStatus(422)->assertJsonPath('message', HOLD_SUSPENDED);

    expect(Inspection::where('application_id', $appId)->count())->toBe(0);
});

it('refuses BPLO lifting a permit while its business is suspended', function () {
    $appId = scopedAssignmentFiling('Held Lift Shop');
    $app = Application::findOrFail($appId);
    holdSetStatus($app->business_id, 'suspended')->assertOk();
    $permit = holdBusinessPermit($app);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/lift-suspension", ['reason' => 'Asked at the counter.'])
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);

    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended);
    test()->getJson("/api/v1/verify/{$permit->permit_number}")->assertJsonPath('data.is_valid', false);
});

it('refuses BPLO\'s final approval of a held filing', function () {
    $app = Application::findOrFail(scopedAssignmentFiling('Held Final Shop'));
    holdSetStatus($app->business_id, 'blacklisted')->assertOk();

    expect(fn () => app(WorkflowService::class)->approveOverall($app->fresh()))
        ->toThrow(ValidationException::class, HOLD_BLACKLISTED);
    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

it('keeps a held filing waiting when its last clearance is in, and closes it once the business is reinstated', function () {
    $app = Application::findOrFail(scopedAssignmentFiling('Held Ready Shop'));
    ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', '!=', PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'))
        ->update(['status' => ClearanceStatus::Approved->value]);
    holdSetStatus($app->business_id, 'suspended')->assertOk();

    app(WorkflowService::class)->refreshReadiness($app->fresh());

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);

    holdSetStatus($app->business_id, 'active')->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

it('refuses the Debug panel paying for, or passing a visit on, a held filing', function () {
    $appId = scopedFilingHeldByCho('Held Debug Shop');
    $app = Application::findOrFail($appId);
    authAs('sanitary@biztrack.local');
    test()->postJson('/api/v1/assignments/'.choAssignmentId($appId).'/approve')->assertOk();
    test()->postJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection", [
        'scheduled_at' => now()->addWeekdays(2)->toDateTimeString(),
    ])->assertCreated();
    $unpaid = holdFiling();
    holdSetStatus($app->business_id, 'suspended')->assertOk();
    holdSetStatus($unpaid->business_id, 'suspended')->assertOk();

    DebugPanel::open(2);
    authAs('admin@biztrack.local');
    test()->postJson("/api/v1/debug/filings/{$unpaid->id}/steps", ['step' => 'payment'])
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);
    test()->postJson("/api/v1/debug/filings/{$appId}/steps", ['step' => 'inspection_pass', 'permit' => 'SANITARY'])
        ->assertStatus(422)
        ->assertJsonPath('message', HOLD_SUSPENDED);

    expect(Payment::where('application_id', $unpaid->id)->count())->toBe(0)
        ->and(clearancePermitsIssued($appId))->toBe(0);
});

it('still lets BPLO return or reject a held filing', function () {
    $app = holdFiling();
    $other = Application::findOrFail(scopedAssignmentFiling('Held Reject Shop'));
    holdSetStatus($app->business_id, 'suspended')->assertOk();
    holdSetStatus($other->business_id, 'suspended')->assertOk();

    app(WorkflowService::class)->rejectApplication($app->fresh(), 'False declarations.');
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$other->id}/reject", ['reason' => 'Wrong zone.'])
        ->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($other->fresh()->status)->toBe(ApplicationStatus::Rejected);
});

<?php

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Enums\OfficerRequestStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
use App\Models\ApplicationStatusHistory;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Inspection;
use App\Models\OfficerRequest;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Support\DebugPanel;
use App\Support\PaymentMode;
use Illuminate\Support\Collection;

/*
 * The Debug page's "Move a filing along" section
 * (App\Services\Debug\FilingMover, /api/v1/debug/filings/*).
 *
 * What these hold the section to: every step is the office's own service
 * call, a refusal is the service's own sentence with nothing left behind,
 * each step is audited as `debug.filing.*` with the super admin as the actor,
 * and the whole thing is a 404 to anyone the panel does not admit.
 */

/** A new filing the owner has submitted, waiting for BPLO to read the form. */
function moverFreshFiling(): Application
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Mover Trading '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Debug Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 200000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return Application::findOrFail($appId);
}

/** The applicant applies for one permit and hands its sheet in, checklist complete. */
function moverHandIn(int $appId, string $code): void
{
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")->assertOk();
    satisfyChecklist(Application::findOrFail($appId), $code);
    test()->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
        'form_data' => [],
        'submit' => true,
    ])->assertSuccessful();
}

/** A filing the owner has paid for, with the given permits handed in. */
function moverPaidFiling(array $handedIn = []): Application
{
    $app = moverFreshFiling();
    bploApprovesForm($app);
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    foreach ($handedIn as $code) {
        moverHandIn($app->id, $code);
    }

    return $app->fresh();
}

function moverAsAdmin(): User
{
    DebugPanel::open(2);
    authAs('admin@biztrack.local');

    return User::where('email', 'admin@biztrack.local')->firstOrFail();
}

function moverStep(Application $app, string $step, array $extra = [])
{
    return test()->postJson("/api/v1/debug/filings/{$app->id}/steps", ['step' => $step] + $extra);
}

function moverPivot(Application $app, string $code): ApplicationPermitType
{
    return ApplicationPermitType::where('application_id', $app->id)
        ->whereHas('permitType', fn ($q) => $q->where('code', $code))
        ->firstOrFail();
}

function moverAudits(): Collection
{
    return AuditLog::where('action', 'like', 'debug.filing.%')->orderBy('id')->get();
}

it('answers 404 on every filing route to a closed panel, to office and owner accounts, and to a guest', function () {
    $app = moverFreshFiling();
    $routes = [
        ['GET', '/api/v1/debug/filings', []],
        ['GET', "/api/v1/debug/filings/{$app->id}", []],
        ['POST', "/api/v1/debug/filings/{$app->id}/steps", ['step' => 'bplo_approve']],
        ['POST', "/api/v1/debug/filings/{$app->id}/advance", ['to' => 'paid']],
        // A wrong method must not answer 405: that would say the route exists.
        ['DELETE', "/api/v1/debug/filings/{$app->id}", []],
    ];

    authAs('admin@biztrack.local');
    foreach ($routes as [$method, $uri, $body]) {
        test()->json($method, $uri, $body)->assertNotFound();
    }

    DebugPanel::open(2);
    foreach (['bplo@biztrack.local', 'owner@biztrack.local'] as $email) {
        authAs($email);
        foreach ($routes as [$method, $uri, $body]) {
            test()->json($method, $uri, $body)->assertNotFound();
        }
    }
    app('auth')->forgetGuards();
    foreach ($routes as [$method, $uri, $body]) {
        test()->json($method, $uri, $body)->assertNotFound();
    }

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval)
        ->and(moverAudits())->toHaveCount(0);
});

it('finds a filing by its tracking ID and shows where it stands and what BPLO can press', function () {
    $app = moverFreshFiling();
    moverAsAdmin();

    $found = test()->getJson('/api/v1/debug/filings?q='.strtolower(substr($app->tracking_id, -5)))->assertOk();
    expect(collect($found->json('data'))->pluck('id'))->toContain($app->id);

    $filing = test()->getJson("/api/v1/debug/filings/{$app->id}")->assertOk();
    expect($filing->json('data.status'))->toBe('for_approval')
        ->and(collect($filing->json('data.steps'))->pluck('key')->all())->toBe(['bplo_approve', 'bplo_return'])
        ->and($filing->json('data.steps.0.label'))->toBe('BPLO accepts the form')
        ->and(collect($filing->json('data.targets'))->pluck('to')->all())->toBe(['pending_payment', 'paid', 'approved']);
});

it('has BPLO accept the form through approveAssignment, recorded as the super admin acting for BPLO', function () {
    $app = moverFreshFiling();
    $admin = moverAsAdmin();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $noticesBefore = AppNotification::where('user_id', $owner->id)->count();

    moverStep($app, 'bplo_approve')
        ->assertOk()
        ->assertJsonPath('data.result.ok', true)
        ->assertJsonPath('data.filing.status', 'pending_payment');

    $app->refresh();
    expect($app->status)->toBe(ApplicationStatus::PendingPayment)
        // approveMainForm's own side effect: the business permit's row opens.
        ->and(moverPivot($app, 'BUSINESS')->status)->toBe(ClearanceStatus::ForApproval);

    $history = ApplicationStatusHistory::where('application_id', $app->id)
        ->where('to_status', 'pending_payment')->firstOrFail();
    expect($history->changed_by_user_id)->toBe($admin->id);

    // Done, but not claimed: BPLO's officers can still pick the filing up.
    $bplo = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))->firstOrFail();
    expect($bplo->status)->toBe(AssignmentStatus::Completed)
        ->and($bplo->officer_user_id)->toBeNull();

    // The service's own trail and notice, as from BPLO's screen.
    expect(AuditLog::where('action', 'application.status_changed')
        ->where('auditable_id', $app->id)->where('user_id', $admin->id)->exists())->toBeTrue()
        ->and(AppNotification::where('user_id', $owner->id)->count())->toBeGreaterThan($noticesBefore);

    $audit = moverAudits()->sole();
    expect($audit->action)->toBe('debug.filing.bplo_approve')
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->auditable_id)->toBe($app->id)
        ->and($audit->changes['before']['status'])->toBe('for_approval')
        ->and($audit->changes['after']['status'])->toBe('pending_payment')
        ->and($audit->changes['acting_for'])->toBe('BPLO')
        ->and($audit->changes['tracking_id'])->toBe($app->tracking_id);
});

it('pays the assessed balance as a simulated payment even while owners pay through KwikPay, and releases the business permit', function () {
    config([
        'payments.kwikpay.base_url' => 'https://kwikpay.test',
        'payments.kwikpay.merchant' => 'M100001',
        'payments.kwikpay.key' => 'test-merchant-key-not-real',
        'payments.kwikpay.payment_type' => '1',
    ]);
    $app = moverFreshFiling();
    bploApprovesForm($app);
    PaymentMode::set(PaymentMode::KWIKPAY);
    moverAsAdmin();

    $assessed = (string) $app->fresh()->feeAssessment->total_amount;

    moverStep($app, 'payment')->assertOk()->assertJsonPath('data.result.ok', true);

    $payment = Payment::where('application_id', $app->id)->sole();
    expect($payment->gateway)->toBe(Payment::GATEWAY_SIMULATED)
        ->and((string) $payment->amount)->toBe($assessed)
        ->and($payment->method->value)->toBe('card')
        /*
         * Paid, and still OPEN. `awaiting_other_permits` said both in one
         * word until it was removed on 4 October 2026; `approved` says the
         * first and `decided_at` the second, so both are asserted or the
         * test would pass on a filing the city had finished with.
         */
        ->and($app->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($app->fresh()->isDecided())->toBeFalse()
        ->and(Permit::where('application_id', $app->id)
            ->whereHas('permitType', fn ($q) => $q->where('code', 'BUSINESS'))->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'payment.completed')->where('auditable_id', $payment->id)->exists())->toBeTrue();

    $audit = moverAudits()->sole();
    expect($audit->action)->toBe('debug.filing.payment')
        ->and($audit->changes['acting_for'])->toBe('applicant')
        ->and($audit->changes['after']['paid'])->toBe(number_format((float) $assessed, 2, '.', ''));
});

it('passes the service refusal through, leaves nothing behind, and still audits the attempt', function () {
    $app = moverFreshFiling();
    moverAsAdmin();

    // Paying before BPLO has read the form: the pay endpoint's own refusal.
    moverStep($app, 'payment')
        ->assertStatus(422)
        ->assertJsonPath('message', 'BPLO has not approved this application yet, so there is nothing to pay.')
        ->assertJsonPath('data.result.ok', false);
    expect(Payment::where('application_id', $app->id)->count())->toBe(0)
        ->and($app->fresh()->status)->toBe(ApplicationStatus::ForApproval);

    // BPLO pressing Approve while the bill is out: WorkflowService's sentence.
    moverStep($app, 'bplo_approve')->assertOk();
    $refused = moverStep($app, 'bplo_approve')->assertStatus(422);
    expect($refused->json('message'))->toStartWith('There is nothing for BPLO to approve while this application is Pending Payment.')
        ->and($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);

    $audits = moverAudits();
    expect($audits)->toHaveCount(3)
        ->and($audits[0]->changes['refused'])->toBe('BPLO has not approved this application yet, so there is nothing to pay.')
        ->and($audits[0]->changes['before'])->toEqual($audits[0]->changes['after'])
        ->and($audits[1]->changes)->not->toHaveKey('refused')
        ->and($audits[2]->changes['refused'])->toStartWith('There is nothing for BPLO to approve');
});

it('returns the form to the applicant only with a note, through returnMainForm', function () {
    $app = moverFreshFiling();
    moverAsAdmin();

    moverStep($app, 'bplo_return')->assertStatus(422)->assertJsonValidationErrors('note');
    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval);

    moverStep($app, 'bplo_return', ['note' => 'The trade name does not match the DTI certificate.'])->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Returned)
        ->and(ApplicationAssignment::where('application_id', $app->id)->sole()->remarks)
        ->toBe('The trade name does not match the DTI certificate.')
        ->and(moverAudits()->sole()->changes['note'])->toBe('The trade name does not match the DTI certificate.');

    // Nothing left for the page to press: it is the applicant's move.
    $filing = test()->getJson("/api/v1/debug/filings/{$app->id}")->assertOk();
    expect($filing->json('data.steps'))->toBe([])
        // No "Advance to" either: a button that could only stop at once is not offered.
        ->and($filing->json('data.targets'))->toBe([])
        ->and($filing->json('data.blockers.0'))->toContain('when the applicant resubmits');
});

it('walks one permit through its office: accept, book, fail, re-book, pass, issue', function () {
    $app = moverPaidFiling(['SANITARY']);
    $admin = moverAsAdmin();

    $filing = test()->getJson("/api/v1/debug/filings/{$app->id}")->assertOk();
    expect(collect($filing->json('data.steps'))->map(fn ($s) => $s['key'].':'.$s['permit'])->all())
        ->toBe(['permit_approve:SANITARY', 'permit_return:SANITARY'])
        ->and(collect($filing->json('data.blockers'))->join(' '))->toContain('the applicant has not applied for it yet');

    moverStep($app, 'permit_approve', ['permit' => 'SANITARY'])->assertOk();
    expect(moverPivot($app, 'SANITARY')->status)->toBe(ClearanceStatus::ForInspection);

    moverStep($app, 'inspection_book', ['permit' => 'SANITARY'])->assertOk();
    $first = Inspection::where('application_id', $app->id)->sole();

    moverStep($app, 'inspection_fail', ['permit' => 'SANITARY', 'note' => 'No hand-washing sink.'])->assertOk();
    expect($first->fresh()->result)->toBe(InspectionResult::Failed)
        ->and($first->fresh()->findings)->toBe('No hand-washing sink.')
        ->and(moverPivot($app, 'SANITARY')->status)->toBe(ClearanceStatus::ForInspection);

    $filing = test()->getJson("/api/v1/debug/filings/{$app->id}")->assertOk();
    expect(collect($filing->json('data.steps'))->pluck('key')->all())->toBe(['inspection_rebook']);

    moverStep($app, 'inspection_rebook', ['permit' => 'SANITARY'])->assertOk();
    moverStep($app, 'inspection_pass', ['permit' => 'SANITARY'])
        ->assertOk()
        ->assertJsonPath('data.result.changes', fn (array $changes) => collect($changes)->contains(fn ($c) => str_starts_with($c, 'Issued ')));

    // The failed visit is kept; the pass issued the permit, in the super admin's name.
    expect(Inspection::where('application_id', $app->id)->count())->toBe(2)
        ->and(moverPivot($app, 'SANITARY')->status)->toBe(ClearanceStatus::Approved);
    $permit = Permit::where('application_id', $app->id)
        ->whereHas('permitType', fn ($q) => $q->where('code', 'SANITARY'))->sole();
    expect($permit->issued_by_user_id)->toBe($admin->id);

    // Neither CHO's queue item nor its inspector was taken over by the super admin.
    $cho = ApplicationAssignment::findOrFail(choAssignmentId($app->id));
    expect($cho->officer_user_id)->toBeNull()
        ->and(Inspection::where('application_id', $app->id)->pluck('inspector_user_id')->contains($admin->id))->toBeFalse();

    expect(moverAudits()->pluck('action')->all())->toBe([
        'debug.filing.permit_approve',
        'debug.filing.inspection_book',
        'debug.filing.inspection_fail',
        'debug.filing.inspection_rebook',
        'debug.filing.inspection_pass',
    ])->and(moverAudits()->pluck('changes.acting_for')->unique()->all())->toBe(['CHO']);
});

it('returns one permit to the applicant through the office return', function () {
    $app = moverPaidFiling(['SANITARY']);
    moverAsAdmin();

    moverStep($app, 'permit_return', ['permit' => 'SANITARY', 'note' => 'The lease is unsigned.'])->assertOk();

    $row = moverPivot($app, 'SANITARY');
    expect($row->status)->toBe(ClearanceStatus::Returned)
        ->and($row->remarks)->toBe('The lease is unsigned.');
});

it('advances a fresh filing to Paid by BPLO accepting the form and then the payment, in that order', function () {
    $app = moverFreshFiling();
    moverAsAdmin();

    $res = test()->postJson("/api/v1/debug/filings/{$app->id}/advance", ['to' => 'paid'])->assertOk();

    expect(collect($res->json('data.results'))->pluck('step')->all())->toBe(['bplo_approve', 'payment'])
        ->and(collect($res->json('data.results'))->pluck('ok')->unique()->all())->toBe([true])
        ->and($res->json('data.reached'))->toBeTrue()
        ->and($res->json('data.stopped'))->toBeNull()
        /*
         * Paid, and still OPEN. `awaiting_other_permits` said both in one
         * word until it was removed on 4 October 2026; `approved` says the
         * first and `decided_at` the second, so both are asserted or the
         * test would pass on a filing the city had finished with.
         */
        ->and($app->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and($app->fresh()->isDecided())->toBeFalse()
        ->and(moverAudits()->pluck('changes.via')->unique()->all())->toBe(['advance']);
});

it('stops an advance at the first refusal and keeps the steps that ran before it', function () {
    $app = moverPaidFiling(['SANITARY', 'FSIC']);
    moverAsAdmin();

    // BFP has asked the applicant for something and not closed it.
    OfficerRequest::create([
        'application_id' => $app->id,
        'requested_by_user_id' => User::where('email', 'fire@biztrack.local')->value('id'),
        'department_id' => Department::where('code', 'BFP')->value('id'),
        'title' => 'Fire extinguisher receipt',
        'description' => 'Upload the receipt for the extinguisher.',
        'request_type' => 'document',
        'status' => OfficerRequestStatus::Pending,
    ]);

    $res = test()->postJson("/api/v1/debug/filings/{$app->id}/advance", ['to' => 'approved'])->assertOk();
    $results = collect($res->json('data.results'));

    expect($results->map(fn ($r) => $r['step'].':'.$r['permit'].':'.($r['ok'] ? 'ok' : 'refused'))->all())->toBe([
        'permit_approve:SANITARY:ok',
        'inspection_book:SANITARY:ok',
        'inspection_pass:SANITARY:ok',
        'permit_approve:FSIC:refused',
    ])
        ->and($res->json('data.stopped'))->toStartWith('Your office has asked this applicant for something')
        ->and($res->json('data.reached'))->toBeFalse()
        ->and(moverPivot($app, 'SANITARY')->status)->toBe(ClearanceStatus::Approved)
        ->and(moverPivot($app, 'FSIC')->status)->toBe(ClearanceStatus::ForApproval)
        ->and(moverAudits())->toHaveCount(4)
        ->and(moverAudits()->last()->changes['refused'])->toStartWith('Your office has asked this applicant');
});

it('stops an advance at what only the applicant can do, and invents nothing for them', function () {
    $app = moverPaidFiling();
    moverAsAdmin();

    $res = test()->postJson("/api/v1/debug/filings/{$app->id}/advance", ['to' => 'approved'])->assertOk();

    expect($res->json('data.results'))->toBe([])
        ->and($res->json('data.stopped'))->toContain('the applicant has not applied for it yet')
        ->and(ApplicationPermitType::where('application_id', $app->id)
            ->where('status', ClearanceStatus::NotStarted->value)->count())->toBe(5)
        ->and(moverAudits())->toHaveCount(0);
});

it('takes a filing to Completed, through BPLO final approval, once the applicant has handed every permit in', function () {
    $app = moverFreshFiling();
    moverAsAdmin();
    test()->postJson("/api/v1/debug/filings/{$app->id}/advance", ['to' => 'paid'])->assertOk();

    foreach (PermitType::REQUIRED_CLEARANCE_CODES as $code) {
        moverHandIn($app->id, $code);
    }
    authAs('admin@biztrack.local');

    $res = test()->postJson("/api/v1/debug/filings/{$app->id}/advance", ['to' => 'approved'])->assertOk();

    /*
     * Three office acts per permit, then BPLO's final approval. Nobody put an
     * officer's name to the RA 11032 category (the panel's BPLO step is the
     * Approve press alone, as on BPLO's screen), so the last pass parks the
     * filing at For Final Approval instead of closing it by itself
     * (WorkflowService::refreshReadiness) and the advance takes the step BPLO
     * would take there.
     */
    $results = collect($res->json('data.results'));
    expect($results)->toHaveCount(16)
        ->and($results->last()['step'])->toBe('bplo_approve')
        ->and($results->last()['label'])->toBe('BPLO gives the final approval')
        ->and($results->pluck('ok')->unique()->all())->toBe([true])
        ->and($res->json('data.reached'))->toBeTrue()
        ->and($app->fresh()->status)->toBe(ApplicationStatus::Approved)
        ->and(clearancePermitsIssued($app))->toBe(5);
});

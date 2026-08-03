<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationDocument;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\FeeAssessment;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\WorkflowService;
use App\Support\PermitFees;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * The LGU clearance stage (docs/clearances-after-payment.md).
 *
 * The six clearances left the apply wizard and became their own stage, opened
 * by the first payment clearing. Each one applied for re-assesses the filing so
 * that office's lines join a running balance, and no permit is released while
 * the balance is positive. Uploading a copy the business already holds is the
 * other route and adds nothing — which is the whole asymmetry these tests are
 * here to pin down.
 */

/**
 * A filing owned by `owner@biztrack.local`, applying for the business permit
 * only, carried all the way to paid — which is where the clearance stage opens.
 *
 * The fee profile is deliberately full. An empty one prices several clearances
 * at zero (no employees means no health certificates, no floor area means no
 * sanitary inspection fee), and a test asserting "the fee went up" against a
 * profile that cannot produce a fee proves nothing.
 */
function paidClearanceApplication(string $name = 'Clearance Stage Cafe'): Application
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-CLR-001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '12 Clearance Ave.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 500000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        'fee_profile' => [
            'gross_sales' => 2000000,
            'capitalization' => 500000,
            'employees' => 12,
            'employees_in_lgu' => 6,
            'floor_area_sqm' => 120,
            'storeys' => 2,
            'business_structure' => 'sole_proprietorship',
            'property_use' => 'non_residential',
        ],
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    test()->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    return Application::findOrFail($appId);
}

/** The same filing, stopped before payment: submitted and awaiting the TOP. */
function unpaidClearanceApplication(): Application
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Locked Stage Store',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-CLR-002',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '13 Locked St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return Application::findOrFail($appId);
}

beforeEach(function () {
    // Keep uploads out of the developer's real storage directory.
    Storage::fake('local');
});

// --- the shape ---------------------------------------------------------------

it('lists the six clearances and never the permit the application is for', function () {
    $app = paidClearanceApplication();

    $rows = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('data');

    $codes = collect($rows)->pluck('permit_type.code');

    expect($codes)->toHaveCount(6)
        ->and($codes->all())->toBe(['ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY', 'MARKET'])
        // The mayor's permit is the outcome of the application, not a clearance
        // to pick, so it is not on the chooser at all.
        ->and($codes)->not->toContain(PermitType::OUTCOME_CODE);
});

it('carries the full contract shape on every row', function () {
    $app = paidClearanceApplication();

    $row = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', 'SANITARY');

    expect($row)->toHaveKeys([
        'permit_type', 'state', 'has_office_form', 'office_form_complete',
        'held_document', 'assignment', 'fee_preview',
    ]);
    expect($row['permit_type'])->toHaveKeys(['id', 'code', 'name', 'department']);
    expect($row['permit_type']['department'])->toHaveKeys(['code', 'name']);
    expect($row['permit_type']['department']['code'])->toBe('CHO');
    expect($row['state'])->toBe('available');
    // SANITARY is one of the four with an applicant-facing sheet; nothing is
    // saved on it yet.
    expect($row['has_office_form'])->toBeTrue()
        ->and($row['office_form_complete'])->toBeFalse()
        ->and($row['held_document'])->toBeNull()
        ->and($row['assignment'])->toBeNull()
        ->and($row['fee_preview'])->toBeString();

    // Zoning and market have no form sheet in the prototype.
    $zoning = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'ZONING');
    expect($zoning['has_office_form'])->toBeFalse();
});

// --- the unlock rule ---------------------------------------------------------

it('keeps the stage locked until the first payment clears, with a reason that says what to do', function () {
    $app = unpaidClearanceApplication();

    $meta = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('meta');

    expect($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toBeString()
        // The next action, not the fact of being locked — "locked" is what the
        // applicant can already see.
        ->and($meta['locked_reason'])->toContain('Pay your Tax Order of Payment')
        ->and((float) $meta['total_paid'])->toBe(0.0)
        ->and($meta['balance_due'])->toBeGreaterThan(0);
});

it('refuses every write while the stage is locked', function () {
    $app = unpaidClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertStatus(422);
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    // Nothing leaked through: no permit type attached, no document stored.
    expect($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING')
        ->and(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0);
});

it('opens the stage once payment has cleared', function () {
    $app = paidClearanceApplication();

    $meta = $this->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()->json('meta');

    expect($meta['unlocked'])->toBeTrue()
        ->and($meta['locked_reason'])->toBeNull()
        ->and((float) $meta['balance_due'])->toBe(0.0)
        ->and($meta['total_paid'])->toEqual($meta['total_assessed']);
});

it('locks the stage again on a filing that was rejected', function () {
    $app = paidClearanceApplication();

    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])->assertOk();

    authAs('owner@biztrack.local');
    $meta = $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('meta');

    expect($meta['unlocked'])->toBeFalse()
        ->and($meta['locked_reason'])->toContain('was not approved');
});

// --- applying: exactly that office's fee lines -------------------------------

it('adds exactly that office’s fee lines and nothing else when a clearance is applied for', function () {
    $app = paidClearanceApplication();

    $before = FeeAssessment::where('application_id', $app->id)->firstOrFail();
    $labelsBefore = array_column($before->line_items, 'label');

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    $after = FeeAssessment::where('application_id', $app->id)->firstOrFail();
    $labelsAfter = array_column($after->line_items, 'label');

    $added = array_values(array_diff($labelsAfter, $labelsBefore));
    $removed = array_values(array_diff($labelsBefore, $labelsAfter));

    // Sec. 3.D.01: filing 45 + land use verification 345 + processing 345.
    // Every added line is the City Planning Office's and no other's.
    expect($added)->toHaveCount(3);
    foreach ($added as $label) {
        expect(strtolower($label))->toContain('locational clearance');
    }
    // The re-assessment is additive: nothing the business permit was already
    // charged is disturbed by asking a second office for something.
    expect($removed)->toBe([])
        ->and(round((float) $after->total_amount - (float) $before->total_amount, 2))->toBe(735.0);
});

it('adds only the applied office’s lines, not every office’s', function () {
    $app = paidClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    $labels = array_column(FeeAssessment::where('application_id', $app->id)->firstOrFail()->line_items, 'label');
    $joined = strtolower(implode(' | ', $labels));

    // The City Health Office's line is there...
    expect($joined)->toContain('sanitary inspection fee');
    // ...and no other office's clearance-gated line came with it.
    expect($joined)->not->toContain('locational clearance')
        ->and($joined)->not->toContain('fire safety inspection certificate fee');
});

it('routes the clearance to its own office as an assignment when applied for', function () {
    $app = paidClearanceApplication();
    $cho = Department::where('code', 'CHO')->firstOrFail();

    expect(ApplicationAssignment::where('application_id', $app->id)->where('department_id', $cho->id)->exists())
        ->toBeFalse();

    $row = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertOk()->json('data');

    $assignment = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', $cho->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status->value)->toBe('pending')
        ->and($row['assignment']['id'])->toBe($assignment->id)
        ->and($row['state'])->toBe('applied');
});

it('answers an apply with the new balance', function () {
    $app = paidClearanceApplication();

    $meta = $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertOk()->json('meta');

    expect((float) $meta['balance_due'])->toBe(735.0)
        ->and((float) $meta['total_assessed'] - (float) $meta['total_paid'])->toBe(735.0);
});

it('previews what applying would add before it is applied for', function () {
    $app = paidClearanceApplication();

    $preview = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'ZONING')['fee_preview'];

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    $balance = $this->getJson("/api/v1/applications/{$app->id}/clearances")->json('meta.balance_due');

    // The preview is the promise; the balance is what actually happened.
    expect($preview)->toBe('₱735.00')
        ->and((float) $balance)->toBe(735.0);
});

// --- un-applying -------------------------------------------------------------

it('takes the fee and the assignment back off when a clearance is un-applied', function () {
    $app = paidClearanceApplication();
    $cpdo = Department::where('code', 'CPDO')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $raised = (float) FeeAssessment::where('application_id', $app->id)->firstOrFail()->total_amount;

    $body = $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk()->json();

    $lowered = (float) FeeAssessment::where('application_id', $app->id)->firstOrFail()->total_amount;

    expect($raised - $lowered)->toBe(735.0)
        ->and((float) $body['meta']['balance_due'])->toBe(0.0)
        ->and($body['data']['state'])->toBe('available')
        ->and($body['data']['assignment'])->toBeNull()
        // The office's queue item goes with it: an office asked for nothing has
        // nothing to review.
        ->and(ApplicationAssignment::where('application_id', $app->id)->where('department_id', $cpdo->id)->exists())
        ->toBeFalse();
});

it('will not let the applicant withdraw a clearance the office has already acted on', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    $assignment = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'CHO')->firstOrFail()->id)
        ->firstOrFail();
    $assignment->update(['status' => 'in_progress']);

    authAs('owner@biztrack.local');
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")
        ->assertStatus(422);

    expect($app->fresh()->permitTypes->pluck('code'))->toContain('SANITARY');
});

it('leaves an office assignment alone when another clearance still routes to it', function () {
    $app = paidClearanceApplication();
    $bplo = Department::where('code', 'BPLO')->firstOrFail();

    // BPLO already holds the business permit's own assignment from routing at
    // payment. Nothing done to a clearance may take that away.
    expect(ApplicationAssignment::where('application_id', $app->id)->where('department_id', $bplo->id)->exists())
        ->toBeTrue();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    expect(ApplicationAssignment::where('application_id', $app->id)->where('department_id', $bplo->id)->exists())
        ->toBeTrue();
});

// --- the held copy: no fee ---------------------------------------------------

it('adds no fee and no permit type when a held copy is uploaded', function () {
    $app = paidClearanceApplication();

    $before = FeeAssessment::where('application_id', $app->id)->firstOrFail();

    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    $after = FeeAssessment::where('application_id', $app->id)->firstOrFail();

    expect((float) $after->total_amount)->toBe((float) $before->total_amount)
        ->and($after->line_items)->toBe($before->line_items)
        ->and((float) $body['meta']['balance_due'])->toBe(0.0)
        ->and($body['data']['state'])->toBe('submitted')
        ->and($body['data']['held_document']['name'])->toBe('sanitary.pdf')
        ->and($body['data']['held_document']['size'])->toBeGreaterThan(0)
        // No permit type means no form, no assignment and no fee — the whole
        // reason submitting a copy is not the same act as applying.
        ->and($app->fresh()->permitTypes->pluck('code'))->not->toContain('SANITARY')
        ->and($body['data']['assignment'])->toBeNull();
});

it('records the held copy through the same mechanism the wizard uses', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $doc = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)
        ->with('documentType')
        ->firstOrFail();

    // Same document-type convention as DocumentController's path, so an officer
    // reading the attachment list cannot tell which screen it arrived through.
    expect($doc->documentType->code)->toBe('HELD_SANITARY')
        ->and($doc->documentType->name)->toContain('already held');
});

it('replaces an earlier held copy rather than stacking them', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('old.pdf', 20, 'application/pdf'),
    ])->assertCreated();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('new.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $held = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)->get();

    expect($held)->toHaveCount(1)
        ->and($held->first()->original_filename)->toBe('new.pdf');
});

it('removes the held copy and its file', function () {
    $app = paidClearanceApplication();
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $path = ApplicationDocument::where('application_id', $app->id)
        ->where('permit_type_id', $sanitary->id)->firstOrFail()->stored_path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $body = $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")
        ->assertOk()->json();

    expect($body['data']['state'])->toBe('available')
        ->and($body['data']['held_document'])->toBeNull()
        ->and(ApplicationDocument::where('application_id', $app->id)->where('permit_type_id', $sanitary->id)->count())->toBe(0)
        // A "removed" document still sitting on disk is not removed: it stays
        // downloadable through /documents/{id}/download for as long as it is there.
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('keeps applying and submitting mutually exclusive', function () {
    $app = paidClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertStatus(422);
});

// --- the balance gates issuance ---------------------------------------------

it('refuses to issue a permit while a clearance balance is outstanding', function () {
    $app = paidClearanceApplication();

    // Apply for zoning after paying: ₱735 accrues and is not settled.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    $fresh = Application::findOrFail($app->id);

    expect(fn () => app(WorkflowService::class)->approveAndIssue($fresh))
        ->toThrow(ValidationException::class);

    expect($fresh->fresh()->permits()->count())->toBe(0)
        ->and($fresh->fresh()->status)->not->toBe(ApplicationStatus::Approved);
});

it('names the amount owed when it refuses to issue', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    try {
        app(WorkflowService::class)->approveAndIssue(Application::findOrFail($app->id));
        $this->fail('The permit was issued with a balance outstanding.');
    } catch (ValidationException $e) {
        $message = implode(' ', $e->errors()['balance_due']);
        expect($message)->toContain('₱735.00')->and($message)->toContain('can’t be released');
    }
});

it('refuses the officer’s approval at the counter while the balance is outstanding', function () {
    $app = paidClearanceApplication();
    // Zoning routes to CPDO and accrues ₱735 that is never settled.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    $assignments = ApplicationAssignment::where('application_id', $app->id)->get();

    // Every office signs off. The last approval is the one that would issue.
    foreach ($assignments as $i => $assignment) {
        $email = $assignment->department_id === Department::where('code', 'CPDO')->first()->id
            ? 'zoning@biztrack.local'
            : 'bplo@biztrack.local';
        authAs($email);

        $response = $this->postJson("/api/v1/assignments/{$assignment->id}/approve");

        if ($i === $assignments->count() - 1) {
            // The real officer-facing behaviour: a 422 naming the amount, not a
            // 500, and not a quietly issued permit.
            $response->assertStatus(422)->assertJsonValidationErrors('balance_due');
        } else {
            $response->assertOk();
        }
    }

    expect(Application::findOrFail($app->id)->permits()->count())->toBe(0);
});

it('releases the permit when the outstanding balance is finally paid', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    foreach (ApplicationAssignment::where('application_id', $app->id)->get() as $assignment) {
        authAs($assignment->department_id === Department::where('code', 'CPDO')->first()->id
            ? 'zoning@biztrack.local'
            : 'bplo@biztrack.local');
        $this->postJson("/api/v1/assignments/{$assignment->id}/approve");
    }

    // Blocked, and every event that could have retried issuance has now been
    // and gone: the offices have all signed off, so no further approval will
    // ever fire. Paying is the only remaining event.
    expect(Application::findOrFail($app->id)->permits()->count())->toBe(0);

    $fresh = Application::findOrFail($app->id);
    $payment = $fresh->payments()->create([
        'fee_assessment_id' => $fresh->feeAssessment->id,
        'reference_number' => 'TEST-CLEARANCE-SETTLED',
        'amount' => 735.00,
        'method' => 'gcash',
        'status' => 'completed',
        'paid_at' => now(),
    ]);
    app(WorkflowService::class)->onPaymentCompleted($payment->fresh());

    $settled = Application::findOrFail($app->id);
    expect($settled->permits()->count())->toBeGreaterThan(0)
        ->and($settled->status)->toBe(ApplicationStatus::Approved);
});

it('issues once the clearance balance is settled', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    // Settle the accrued ₱735 the way the gateway would.
    $fresh = Application::findOrFail($app->id);
    $fresh->payments()->create([
        'fee_assessment_id' => $fresh->feeAssessment->id,
        'reference_number' => 'TEST-CLEARANCE-BALANCE',
        'amount' => 735.00,
        'method' => 'gcash',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    app(WorkflowService::class)->approveAndIssue($fresh->fresh());

    expect(Application::findOrFail($app->id)->permits()->count())->toBeGreaterThan(0);
});

it('reports a clearance as issued once its permit exists', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    $fresh = Application::findOrFail($app->id);
    $fresh->payments()->create([
        'fee_assessment_id' => $fresh->feeAssessment->id,
        'reference_number' => 'TEST-CLEARANCE-ISSUED',
        'amount' => 735.00,
        'method' => 'gcash',
        'status' => 'completed',
        'paid_at' => now(),
    ]);
    app(WorkflowService::class)->approveAndIssue($fresh->fresh());

    authAs('owner@biztrack.local');
    $row = collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('data'))
        ->firstWhere('permit_type.code', 'ZONING');

    expect($row['state'])->toBe('issued');
});

// --- authorization -----------------------------------------------------------

it('refuses a stranger the clearance list', function () {
    $app = paidClearanceApplication();

    // A different business owner, holding a perfectly valid session.
    authAs('juan@biztrack.local');
    $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertForbidden();
});

it('refuses a stranger every clearance write', function () {
    $app = paidClearanceApplication();

    authAs('juan@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertForbidden();
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertForbidden();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 20, 'application/pdf'),
    ])->assertForbidden();
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/held")->assertForbidden();

    // The 403 is the whole story: nothing was attached, charged or stored.
    expect($app->fresh()->permitTypes->pluck('code'))->not->toContain('ZONING')
        ->and(ApplicationDocument::where('application_id', $app->id)->count())->toBe(0);
});

it('refuses an officer the clearance chooser even on a filing its office reviews', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    // The sanitary officer is routed this filing and may read the application
    // itself — but which clearances a business asks for is the applicant's
    // decision, and the chooser is not part of the review.
    authAs('sanitary@biztrack.local');
    $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertForbidden();
});

// --- guards ------------------------------------------------------------------

it('has no clearance endpoint for the permit the application is for', function () {
    $app = paidClearanceApplication();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/BUSINESS/apply")->assertNotFound();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/NOT_A_CODE/apply")->assertNotFound();
});

it('survives a filing whose business has been removed from the register', function () {
    $app = paidClearanceApplication();
    $app->business->delete();

    $body = $this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json();

    // The stage still renders; only the price it cannot compute is withheld.
    expect($body['data'])->toHaveCount(6)
        ->and($body['data'][0]['fee_preview'])->toBeNull()
        ->and($body['meta']['unlocked'])->toBeTrue();

    // And both re-assessing writes say so rather than returning a 500 from a
    // null dereference inside FeeCalculator.
    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertStatus(422);
    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")
        ->assertStatus(422);
});

it('still lets a held copy be filed when the business record has gone', function () {
    $app = paidClearanceApplication();
    $app->business->delete();

    // Nothing here re-assesses, so nothing here needs a price — the applicant
    // can still hand in the certificate they hold.
    $body = $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json();

    expect($body['data']['state'])->toBe('submitted');

    $this->deleteJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held")->assertOk();
});

it('reports an office form as complete only once the applicant has saved it', function () {
    $app = paidClearanceApplication();
    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();

    $row = fn () => collect($this->getJson("/api/v1/applications/{$app->id}/clearances")->json('data'))
        ->firstWhere('permit_type.code', 'SANITARY');

    expect($row()['office_form_complete'])->toBeFalse();

    $this->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
        'form_data' => ['owner_name' => 'Ana Dela Cruz'],
    ])->assertOk();

    expect($row()['office_form_complete'])->toBeTrue();
});

/*
 * The balance has to be payable through the endpoint an applicant actually
 * reaches, not only by writing a Payment row in a test.
 *
 * PaymentController::pay refused anything outside `pending_payment`, so once a
 * clearance raised a balance after the first payment there was no way to settle
 * it — and the permit is held until the balance clears. The applicant was left
 * in a room with no door: a bill they could see, could not pay, and which
 * blocked the thing they were waiting for.
 *
 * The tests above settled it by calling `payments()->create(...)` straight on
 * the model, which is why nothing caught it. This one goes through HTTP.
 */
it('lets the applicant settle a clearance balance through the payment endpoint', function () {
    $app = paidClearanceApplication('Balance Payable Cafe');

    $before = PermitFees::balance(Application::findOrFail($app->id));
    expect($before['balance_due'])->toBe(0.0);

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/apply")->assertOk();

    $raised = PermitFees::balance(Application::findOrFail($app->id));
    expect($raised['balance_due'])->toBeGreaterThan(0.0);

    authAs('owner@biztrack.local');
    $response = $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertCreated();

    /*
     * The charge is the outstanding balance, never the assessment total. The
     * total now covers the business permit as well, and billing that again
     * would take money this applicant has already handed over.
     */
    expect((float) $response->json('data.amount'))->toBe($raised['balance_due'])
        ->and((float) $response->json('data.amount'))->toBeLessThan($raised['total_assessed']);

    $settled = PermitFees::balance(Application::findOrFail($app->id));
    expect($settled['balance_due'])->toBe(0.0)
        ->and($settled['total_paid'])->toBe($raised['total_assessed']);
});

it('refuses a payment when the filing owes nothing', function () {
    $app = paidClearanceApplication('Nothing Owed Cafe');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'This application has nothing outstanding.');
});

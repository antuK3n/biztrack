<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\WorkflowService;

/*
 * A new business's other permits are BPLO's to pick.
 *
 * Client, 5 October 2026, asked how the system should decide which of the five
 * other permits a NEW filing carries: *"BPLO decides, no rules — but without
 * pre-ticked. Do not put reason too."*
 *
 * So a new filing is submitted carrying the Business Permit alone; at For
 * Approval BPLO ticks the clearances this business needs and approves; only
 * the ticked ones are attached, billed on the Tax Order of Payment and opened
 * after payment. Renewals and amendments are unchanged.
 *
 * Fixtures elsewhere that create a new filing with an explicit
 * `permit_type_ids` keep what they asked for (`attachRequiredPermitTypes`
 * never detaches), and BPLO's approval with no ticks keeps it too — ticks are
 * required only when the filing carries no clearance yet.
 */

/** A submitted NEW filing carrying what the wizard sends: the Business Permit. */
function newFilingForBplo(): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Picked Permits Store '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Picked Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return Application::findOrFail($appId);
}

function bploAssignmentOn(Application $app): ApplicationAssignment
{
    return ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();
}

/** @param  list<string>  $codes */
function idsOf(array $codes): array
{
    return PermitType::whereIn('code', $codes)->pluck('id')->all();
}

/** @return list<string> */
function carriedCodes(Application $app): array
{
    return $app->fresh()->permitTypes->pluck('code')->sort()->values()->all();
}

it('submits a new filing carrying the Business Permit alone', function () {
    $app = newFilingForBplo();

    expect(carriedCodes($app))->toBe(['BUSINESS'])
        ->and($app->status)->toBe(ApplicationStatus::ForApproval);
});

it('refuses BPLO’s approval of a new filing with no other permit ticked', function () {
    $app = newFilingForBplo();
    $bplo = bploAssignmentOn($app);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bplo->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('errors.permit_type_ids.0', 'Tick the other permits this business needs.');

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval)
        ->and(carriedCodes($app))->toBe(['BUSINESS']);
});

it('attaches exactly the ticked permits and bills them, not the others', function () {
    $app = newFilingForBplo();
    $before = collect($app->feeAssessment->line_items)->flatMap(fn ($i) => (array) ($i['permit_codes'] ?? []))->unique();
    $bplo = bploAssignmentOn($app);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bplo->id}/approve", ['permit_type_ids' => idsOf(['SANITARY', 'FSIC'])])
        ->assertOk();

    $app = $app->fresh();
    $codes = collect($app->feeAssessment->line_items)->flatMap(fn ($i) => (array) ($i['permit_codes'] ?? []))->unique();

    expect(carriedCodes($app))->toBe(['BUSINESS', 'FSIC', 'SANITARY'])
        ->and($app->status)->toBe(ApplicationStatus::PendingPayment)
        // The submission bill priced the Business Permit alone…
        ->and($before->contains('FSIC'))->toBeFalse()
        // …and the approval re-assessed it over the ticked set. (No sanitary
        // rule matches this fixture's trade, so FSIC is the line to look for.)
        ->and($codes->contains('FSIC'))->toBeTrue()
        ->and($codes->contains('CEC'))->toBeFalse()
        ->and($codes->contains('ZONING'))->toBeFalse()
        ->and(AuditLog::where('action', 'application.other_permits_set')
            ->where('auditable_id', $app->id)->exists())->toBeTrue()
        // The applicant's approval notice names them.
        ->and($app->statusHistory()->reorder()->latest('id')->value('note'))
        ->toContain('Other permits you will apply for after paying: Sanitary Permit');

    // And the absence means something: the same business ticked for all five
    // IS billed for zoning and the environmental certificate.
    $all = newFilingForBplo();
    bploApprovesForm($all, PermitType::REQUIRED_CLEARANCE_CODES);
    $allCodes = collect($all->fresh()->feeAssessment->line_items)->flatMap(fn ($i) => (array) ($i['permit_codes'] ?? []))->unique();
    expect($allCodes->contains('ZONING') || $allCodes->contains('CEC'))->toBeTrue();
});

it('replaces the clearances a filing already carries with BPLO’s ticks', function () {
    // A filing submitted before 5 October 2026 carries all five.
    $app = newFilingForBplo();
    $app->permitTypes()->syncWithoutDetaching(
        collect(idsOf(PermitType::REQUIRED_CLEARANCE_CODES))->mapWithKeys(fn ($id) => [$id => ['status' => 'not_started']])->all()
    );

    bploApprovesForm($app, ['ZONING']);

    expect(carriedCodes($app))->toBe(['BUSINESS', 'ZONING']);
});

it('refuses a tick that is not one of the five other permits', function () {
    $app = newFilingForBplo();
    $bplo = bploAssignmentOn($app);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bplo->id}/approve", ['permit_type_ids' => idsOf(['BUSINESS'])])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['permit_type_ids']);
});

it('opens only the ticked permits after payment, in no queue but their own office’s', function () {
    $app = newFilingForBplo();
    bploApprovesForm($app, ['SANITARY']);
    $owner = authAs('owner@biztrack.local');
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    $page = test()->withHeaders($owner)->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk();
    expect(collect($page->json('data'))->pluck('permit_type.code')->all())->toBe(['SANITARY']);

    // An unticked clearance cannot be added by the applicant either.
    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$app->id}/clearances/CEC/apply")
        ->assertStatus(422);

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertSuccessful();
    satisfyChecklist($app->fresh(), 'SANITARY');
    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
            'form_data' => ['sanitary_classification' => 'Food Establishment'],
            'submit' => true,
        ])->assertSuccessful();

    $offices = ApplicationAssignment::where('application_id', $app->id)
        ->with('department')->get()->pluck('department.code')->sort()->values()->all();
    expect($offices)->toBe(['BPLO', 'CHO']);

    foreach (['cenro@biztrack.local', 'fire@biztrack.local', 'zoning@biztrack.local', 'obo@biztrack.local'] as $officer) {
        $queue = test()->withHeaders(authAs($officer))->getJson('/api/v1/assignments')->assertOk();
        expect(collect($queue->json('data'))->pluck('application_id')->contains($app->id))->toBeFalse();
    }
});

it('closes the filing when the last ticked permit is issued', function () {
    duringOfficeHours();
    $app = newFilingForBplo();
    bploApprovesForm($app, ['SANITARY']);
    $owner = authAs('owner@biztrack.local');
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertSuccessful();
    satisfyChecklist($app->fresh(), 'SANITARY');
    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
            'form_data' => ['sanitary_classification' => 'Food Establishment'],
            'submit' => true,
        ])->assertSuccessful();

    expect($app->fresh()->decided_at)->toBeNull();

    $cho = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'CHO')->value('id'))->firstOrFail();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$cho->id}/approve")->assertOk();
    $visitId = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/permits/SANITARY/inspection", ['scheduled_at' => now()->toDateTimeString()])
        ->assertCreated()->json('data.id');
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed'])->assertOk();

    $app = $app->fresh();
    expect($app->decided_at)->not->toBeNull()
        ->and($app->status)->toBe(ApplicationStatus::Approved)
        ->and(Permit::where('application_id', $app->id)->count())->toBe(2);
});

it('refuses other-permit ticks on a renewal', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $type = PermitType::where('code', PermitType::OUTCOME_CODE)->firstOrFail();
    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);
    $prior = Permit::create([
        'application_id' => $priorApp->id,
        'business_id' => $business->id,
        'permit_type_id' => $type->id,
        'permit_number' => 'PICK-BUS-'.random_int(10000, 99999),
        'issued_at' => now()->subYear(),
        'valid_from' => now()->subYear(),
        'valid_until' => now()->addDays(20),
        'status' => 'active',
    ]);
    $renewal = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $prior->id,
    ]);
    $renewal->priorPermits()->sync([$prior->id]);
    $renewal->permitTypes()->sync([$type->id]);
    app(WorkflowService::class)->submit($renewal->fresh());

    $bplo = bploAssignmentOn($renewal);
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bplo->id}/approve", ['permit_type_ids' => idsOf(['SANITARY'])])
        ->assertStatus(422)
        ->assertJsonPath('errors.permit_type_ids.0', 'Only a new application takes other permits.');

    expect(carriedCodes($renewal))->toBe(['BUSINESS'])
        ->and($renewal->fresh()->status)->toBe(ApplicationStatus::ForApproval);
});

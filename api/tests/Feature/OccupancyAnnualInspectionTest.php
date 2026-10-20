<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\AssignmentStatus;
use App\Enums\ClearanceStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitExpiryNotice;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\AnnualInspection;
use App\Support\RenewablePermit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * The Occupancy Permit is inspected yearly, never renewed (client, 20 October
 * 2026: "It is stated to us that it is only annual inspection instead of
 * annual renewal (like the other permits)").
 *
 * It never expires, so the nightly scan neither reminds, expires nor nudges
 * it; on its due date the scan opens an "annual inspection" entry that OBO
 * works under For Inspection with the regular actions. The rulings are the
 * regular ones — the walk at the end runs each on an annual entry and on a
 * regular OCCUPANCY inspection and expects the same outcome. The allowed
 * differences: no fee, and a pass moves the existing permit's next inspection
 * a year on instead of issuing a new one.
 */

beforeEach(function () {
    // 10 AM on a Tuesday: visits are booked in office hours on a weekday.
    $this->travelTo(Carbon::parse('2027-01-05 10:00:00'));
});

/** An owner's business with a live Business Permit; returns [business id, business permit]. */
function oaiBusiness(): array
{
    authAs('owner@biztrack.local');
    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Annual Inspection Store '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Occupancy Road', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 300000]],
    ])->assertCreated()->json('data.id');

    return [$businessId, oaiPermit($businessId, 'BUSINESS', 'active', '2027-01-02', '2027-12-31')];
}

/** A permit of `$code` issued on a closed filing of its own. */
function oaiPermit(int $businessId, string $code, string $status, string $from, string $until): Permit
{
    $app = Application::create([
        'business_id' => $businessId,
        'applicant_user_id' => Business::find($businessId)->owner_user_id,
        'application_type' => 'new',
        'status' => 'approved',
        'decided_at' => Carbon::parse($from),
        'submitted_at' => Carbon::parse($from)->subMonth(),
    ]);
    $app->permitTypes()->attach(PermitType::where('code', $code)->value('id'), ['status' => 'approved']);

    return Permit::create([
        'application_id' => $app->id,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'permit_number' => 'OAI-'.$code.'-'.random_int(100000, 999999),
        'issued_at' => $from,
        'valid_from' => $from,
        'valid_until' => $until,
        'status' => $status,
    ]);
}

function oaiOboDepartment(): int
{
    return (int) PermitType::where('code', 'OCCUPANCY')->value('issuing_department_id');
}

/** The annual entry the scan opens for an Occupancy Permit due today. */
function oaiAnnualEntry(): array
{
    [$businessId, $businessPermit] = oaiBusiness();
    $occupancy = oaiPermit($businessId, 'OCCUPANCY', 'active', '2026-01-05', '2027-01-05');

    test()->artisan('biztrack:scan-permits')->assertSuccessful();

    $app = Application::where('application_type', ApplicationType::AnnualInspection->value)
        ->where('prior_permit_id', $occupancy->id)
        ->firstOrFail();

    return [$app, $businessPermit, $occupancy];
}

/**
 * A regular OCCUPANCY inspection to compare with: a new filing whose Business
 * Permit was released at payment and whose Occupancy clearance OBO accepted,
 * so it is at For Inspection — the state the annual entry is opened in.
 */
function oaiRegularEntry(): array
{
    [$businessId] = oaiBusiness();
    // The earlier permit from oaiBusiness() is retired; the filing's own is the live one.
    Permit::where('business_id', $businessId)->update(['status' => PermitStatus::Superseded->value]);

    $app = Application::create([
        'tracking_id' => 'BIZ-2027-'.random_int(10000, 99999),
        'business_id' => $businessId,
        'applicant_user_id' => Business::find($businessId)->owner_user_id,
        'application_type' => 'new',
        'status' => ApplicationStatus::Approved->value,
        'submitted_at' => now()->subWeek(),
    ]);
    $app->permitTypes()->attach(PermitType::where('code', 'BUSINESS')->value('id'), ['status' => 'approved']);
    $app->permitTypes()->attach(PermitType::where('code', 'OCCUPANCY')->value('id'), [
        'status' => ClearanceStatus::ForInspection->value, 'mode' => 'apply', 'submitted_at' => now()->subDays(3),
    ]);
    ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => oaiOboDepartment(),
        'status' => AssignmentStatus::Completed->value,
        'assigned_at' => now()->subDays(3),
        'completed_at' => now()->subDay(),
    ]);
    $businessPermit = Permit::create([
        'application_id' => $app->id,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', 'BUSINESS')->value('id'),
        'permit_number' => 'OAI-REG-'.random_int(100000, 999999),
        'issued_at' => now()->subWeek(),
        'valid_from' => now()->subWeek()->toDateString(),
        'valid_until' => '2027-12-31',
        'status' => PermitStatus::Active->value,
    ]);

    return [$app->fresh(), $businessPermit, null];
}

function oaiAssignment(Application $app): int
{
    return (int) $app->assignments()->where('department_id', oaiOboDepartment())->value('id');
}

/** OBO books the first visit for today and records `$result`. Returns the visit id. */
function oaiVisit(Application $app, string $result): int
{
    authAs('obo@biztrack.local');
    $visitId = test()->postJson("/api/v1/applications/{$app->id}/permits/OCCUPANCY/inspection", [
        'scheduled_at' => now()->toDateTimeString(),
    ])->assertCreated()->json('data.id');
    test()->postJson("/api/v1/inspections/{$visitId}/conduct", [
        'result' => $result,
        'findings' => $result === 'failed' ? 'Fire exit blocked.' : null,
    ])->assertOk();

    return (int) $visitId;
}

function oaiOccupancyStatus(Application $app): ClearanceStatus
{
    return $app->fresh()->permitTypes->firstWhere('code', 'OCCUPANCY')->pivot->status;
}

// ── It never expires ─────────────────────────────────────────────────────────

it('never reminds, expires or nudges an Occupancy Permit, while other permits keep their expiry', function () {
    [$businessId] = oaiBusiness();
    $lapsedOccupancy = oaiPermit($businessId, 'OCCUPANCY', 'active', '2025-01-02', '2026-12-01');
    $soonOccupancy = oaiPermit($businessId, 'OCCUPANCY', 'active', '2026-01-20', '2027-01-15');
    $lapsedSanitary = oaiPermit($businessId, 'SANITARY', 'active', '2026-01-02', '2026-12-31');
    $soonSanitary = oaiPermit($businessId, 'SANITARY', 'active', '2026-01-20', '2027-01-15');

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect($lapsedOccupancy->fresh()->status)->toBe(PermitStatus::Active)
        ->and($soonOccupancy->fresh()->status)->toBe(PermitStatus::Active)
        ->and(PermitExpiryNotice::whereIn('permit_id', [$lapsedOccupancy->id, $soonOccupancy->id])->count())->toBe(0)
        // The other permits behave exactly as before.
        ->and($lapsedSanitary->fresh()->status)->toBe(PermitStatus::Expired)
        ->and(PermitExpiryNotice::where('permit_id', $soonSanitary->id)->exists())->toBeTrue();
});

it('is not offered for renewal, and a renewal naming it is refused', function () {
    [$businessId] = oaiBusiness();
    $occupancy = oaiPermit($businessId, 'OCCUPANCY', 'active', '2026-01-05', '2027-03-01');
    $sanitary = oaiPermit($businessId, 'SANITARY', 'expired', '2026-01-02', '2026-12-31');

    authAs('owner@biztrack.local');
    $offered = $this->getJson("/api/v1/businesses/{$businessId}/prefill?type=renewal")
        ->assertOk()->json('data.renewable_permits.*.id');

    expect($offered)->not->toContain($occupancy->id)
        ->and($offered)->toContain($sanitary->id)
        ->and(RenewablePermit::refusal([$occupancy->id]))
        ->toBe('The Occupancy Permit isn’t renewed. It gets a yearly inspection instead.');
});

it('treats the Occupancy Permit, and only it, as never expiring', function () {
    // The one rule every reader asks (PermitType::NO_EXPIRY_CODES).
    expect(PermitType::where('code', 'OCCUPANCY')->firstOrFail()->expires())->toBeFalse()
        ->and(PermitType::where('code', 'SANITARY')->firstOrFail()->expires())->toBeTrue()
        ->and(PermitType::where('code', 'BUSINESS')->firstOrFail()->expires())->toBeTrue();
});

// ── The scan opens the yearly inspection ─────────────────────────────────────

it('opens one annual inspection for OBO on the due date, however often the scan runs', function () {
    [$app, , $occupancy] = oaiAnnualEntry();

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(Application::where('application_type', 'annual_inspection')->where('prior_permit_id', $occupancy->id)->count())->toBe(1)
        ->and($app->status)->toBe(ApplicationStatus::ForApproval)
        ->and($app->permitTypes->pluck('code')->all())->toBe(['OCCUPANCY'])
        ->and(oaiOccupancyStatus($app))->toBe(ClearanceStatus::ForInspection)
        ->and($app->assignments()->where('department_id', oaiOboDepartment())->value('status'))
        ->toBe(AssignmentStatus::Completed)
        // No fee: nothing is billed for it.
        ->and($app->feeAssessment)->toBeNull()
        ->and(AppNotification::where('user_id', $app->applicant_user_id)
            ->where('body', 'like', '%yearly inspection%')->exists())->toBeTrue();
});

it('does not open an inspection before the due date', function () {
    [$businessId] = oaiBusiness();
    $occupancy = oaiPermit($businessId, 'OCCUPANCY', 'active', '2026-01-06', '2027-01-06');

    $this->artisan('biztrack:scan-permits')->assertSuccessful();

    expect(AnnualInspection::openEntryFor($occupancy))->toBeNull();
});

it('lists the annual inspection under OBO For Inspection, labelled as one', function () {
    [$app] = oaiAnnualEntry();

    authAs('obo@biztrack.local');
    $rows = $this->getJson('/api/v1/assignments?application_status=approved,for_approval&clearance_status=for_inspection&per_page=100')
        ->assertOk()->json('data');

    $row = collect($rows)->firstWhere('application.id', $app->id);
    expect($row)->not->toBeNull()
        ->and($row['application']['application_type'])->toBe('annual_inspection');

    // Not BPLO's: it carries no BPLO assignment.
    authAs('bplo@biztrack.local');
    $bplo = $this->getJson('/api/v1/assignments?per_page=100')->assertOk()->json('data');
    expect(collect($bplo)->pluck('application.id'))->not->toContain($app->id);
});

it('cannot be cancelled by the owner', function () {
    [$app] = oaiAnnualEntry();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/cancel")->assertStatus(422);
    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval);
});

// ── The rulings, the same as a regular OCCUPANCY inspection ──────────────────

dataset('occupancy inspections', [
    'annual inspection' => [fn () => oaiAnnualEntry()],
    'regular inspection' => [fn () => oaiRegularEntry()],
]);

it('a passed visit approves the Occupancy clearance and closes the entry', function (array $entry) {
    [$app, $businessPermit, $occupancy] = $entry;
    $permitsBefore = Permit::where('business_id', $app->business_id)->count();

    oaiVisit($app, 'passed');

    expect(oaiOccupancyStatus($app))->toBe(ClearanceStatus::Approved)
        ->and($businessPermit->fresh()->status)->toBe(PermitStatus::Active)
        ->and($app->fresh()->isDecided())->toBeTrue();

    if ($occupancy !== null) {
        // Annual: no new certificate; the one held is due again a year from the pass.
        expect(Permit::where('business_id', $app->business_id)->count())->toBe($permitsBefore)
            ->and($occupancy->fresh()->valid_until->toDateString())->toBe('2028-01-05')
            ->and($occupancy->fresh()->status)->toBe(PermitStatus::Active);
    } else {
        // Regular: a new certificate, due its first inspection exactly a year
        // after issue (client, 20 October 2026), not on 31 December.
        $issued = $app->fresh()->permits()->whereHas('permitType', fn ($q) => $q->where('code', 'OCCUPANCY'))->firstOrFail();
        expect(Permit::where('business_id', $app->business_id)->count())->toBe($permitsBefore + 1)
            ->and($issued->valid_until->toDateString())->toBe('2028-01-05');
    }
})->with('occupancy inspections');

it('a failed visit suspends the Business Permit and keeps the clearance at its visit', function (array $entry) {
    [$app, $businessPermit, $occupancy] = $entry;

    oaiVisit($app, 'failed');

    expect(oaiOccupancyStatus($app))->toBe(ClearanceStatus::ForInspection)
        ->and($businessPermit->fresh()->status)->toBe(PermitStatus::Suspended)
        ->and($businessPermit->fresh()->suspended_for_permit_type_id)->toBe(PermitType::where('code', 'OCCUPANCY')->value('id'))
        ->and(AppNotification::where('user_id', $app->applicant_user_id)->where('body', 'like', '%did not pass%')->exists())->toBeTrue();

    if ($occupancy !== null) {
        expect($occupancy->fresh()->status)->toBe(PermitStatus::Active);
    }
})->with('occupancy inspections');

it('a passing re-inspection after a failure reinstates the Business Permit', function (array $entry) {
    [$app, $businessPermit] = $entry;
    $failed = oaiVisit($app, 'failed');

    authAs('obo@biztrack.local');
    $visitId = $this->postJson("/api/v1/inspections/{$failed}/reinspect", [
        'scheduled_at' => now()->toDateTimeString(),
    ])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed'])->assertOk();

    expect(oaiOccupancyStatus($app))->toBe(ClearanceStatus::Approved)
        ->and($businessPermit->fresh()->status)->toBe(PermitStatus::Active);
})->with('occupancy inspections');

it('refusing the permit after a failed visit suspends the Business Permit and records the refusal', function (array $entry) {
    [$app, $businessPermit] = $entry;
    oaiVisit($app, 'failed');

    authAs('obo@biztrack.local');
    $this->postJson('/api/v1/assignments/'.oaiAssignment($app).'/reject', [
        'reason' => 'Unsafe second storey.',
        'remedy' => 'Secure a building permit for the addition.',
    ])->assertOk();

    expect(oaiOccupancyStatus($app))->toBe(ClearanceStatus::Rejected)
        ->and($businessPermit->fresh()->status)->toBe(PermitStatus::Suspended)
        ->and(DB::table('clearance_refusals')->where('application_id', $app->id)->count())->toBe(1)
        ->and(AppNotification::where('user_id', $app->applicant_user_id)->where('body', 'like', '%Unsafe second storey%')->exists())->toBeTrue();
})->with('occupancy inspections');

it('after a refusal the owner applies again, and the passing visit reinstates the Business Permit and moves the next inspection', function () {
    [$app, $businessPermit, $occupancy] = oaiAnnualEntry();
    oaiVisit($app, 'failed');

    authAs('obo@biztrack.local');
    $this->postJson('/api/v1/assignments/'.oaiAssignment($app).'/reject', [
        'reason' => 'Unsafe second storey.',
        'remedy' => 'Secure a building permit for the addition.',
    ])->assertOk();
    expect($businessPermit->fresh()->status)->toBe(PermitStatus::Suspended);

    // The regular way back: the owner applies for the refused permit again.
    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/clearances/OCCUPANCY/apply")->assertOk();
    satisfyChecklist($app->fresh(), 'OCCUPANCY');
    $this->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
        'form_data' => ['application_type' => 'Full'],
        'submit' => true,
    ])->assertSuccessful();
    expect(oaiOccupancyStatus($app))->toBe(ClearanceStatus::ForApproval);

    authAs('obo@biztrack.local');
    $this->postJson('/api/v1/assignments/'.oaiAssignment($app).'/approve')->assertOk();
    oaiVisit($app, 'passed');

    expect(oaiOccupancyStatus($app))->toBe(ClearanceStatus::Approved)
        ->and($businessPermit->fresh()->status)->toBe(PermitStatus::Active)
        ->and($occupancy->fresh()->valid_until->toDateString())->toBe('2028-01-05')
        ->and($app->fresh()->feeAssessment)->toBeNull();
});

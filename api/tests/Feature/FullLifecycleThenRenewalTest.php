<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * The whole thing, end to end, through the endpoints a browser calls.
 *
 * Apply for every permit, walk it to BPLO's final approval, then renew. Not a
 * unit test of any one rule — the point is the JOINS: every gate that only
 * fires when a real filing arrives at it in a real state, and every assumption
 * one stage makes about what an earlier stage left behind.
 *
 * Driven through HTTP and not the service, deliberately. A workflow defect that
 * only a controller can produce — a permission gate, a 422 on a payload the
 * browser sends, an endpoint that needs a state nothing reaches — is invisible
 * to a test that calls WorkflowService directly, and those are the ones that
 * reach a tester.
 *
 * ── Every actor re-authenticates before it acts ───────────────────────────
 *
 * `authAs()` returns an EMPTY header array and switches the Sanctum guard, so
 * `withHeaders($owner)` restores nothing. An applicant action written after an
 * officer's therefore runs AS THAT OFFICER, and the first draft of this file
 * did exactly that: the second clearance in the loop came back 403 because the
 * fire officer was pressing Apply on the applicant's behalf. Every helper below
 * names the account it acts as, on the line it acts.
 */

/** Which seeded officer signs for which clearance. */
const OFFICER_FOR = [
    'ZONING' => 'zoning@biztrack.local',
    'SANITARY' => 'sanitary@biztrack.local',
    'CEC' => 'cenro@biztrack.local',
    'FSIC' => 'fire@biztrack.local',
    'OCCUPANCY' => 'obo@biztrack.local',
];

/** Enough answers to satisfy each office sheet's required fields. */
const SHEET_ANSWERS = [
    'ZONING' => [
        'zoning_home_address' => '27 Rizal Avenue Extension, Brgy. Concepcion, Malabon City',
        'zoning_project_description' => 'Two-storey hardware and construction supply store with a stockroom at the rear.',
    ],
    'SANITARY' => [],
    'CEC' => [
        'owner_address' => '27 Rizal Avenue Extension, Brgy. Concepcion, Malabon City',
        'owner_birthday' => '1979-03-14',
        'certified' => 'yes',
    ],
    'FSIC' => ['authorized_representative' => 'Ma. Teresa R. Bautista'],
    'OCCUPANCY' => [],
];

/** A registered business, as the counter would have it on file. */
function registerBusiness(string $name, string $registrationNumber): int
{
    authAs('owner@biztrack.local');

    return test()->postJson('/api/v1/businesses', [
        'name' => $name,
        'trade_name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '246-813-579-000',
        'president_officer_name' => 'Ma. Teresa R. Bautista',
        'owner' => [
            'surname' => 'Bautista',
            'given_name' => 'Ma. Teresa',
            'middle_name' => 'Reyes',
            'gender' => 'F',
        ],
        'address' => [
            'line1' => '127 F. Sevilla Boulevard',
            'barangay_id' => Barangay::where('name', 'Tañong')->value('id'),
            'telephone' => '(02) 8281-4712',
            'mobile_number' => '0917-842-6531',
            'email' => 'materesa.bautista@example.ph',
        ],
        'lines' => [[
            'psic_code_id' => PsicCode::where('code', '47521')->value('id'),
            'line_of_business' => 'Retail sale of hardware and building materials',
            'products_services' => 'Cement, steel bars, plywood, paint, plumbing fixtures',
            'capitalization' => 850000,
        ]],
    ])->assertCreated()->json('data.id');
}

/** The fee inputs a hardware store of this size would declare. */
function feeProfile(int $grossReceipts): array
{
    return [
        'floor_area_sqm' => 145,
        'storeys' => 2,
        'employees' => 7,
        'lines' => [['category' => 'retailer', 'gross_receipts' => $grossReceipts]],
    ];
}

/** BPLO reads the form, categorises it, and approves it for payment. */
function bploAccepts(int $appId, string $remarks): void
{
    authAs('bplo@biztrack.local');
    $assignment = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();

    test()->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'simple'])
        ->assertOk();
    test()->postJson("/api/v1/assignments/{$assignment->id}/approve", ['remarks' => $remarks])
        ->assertOk();
}

/**
 * One clearance, all the way: the applicant applies and fills the sheet, then
 * the office accepts the paperwork, books a visit and passes it.
 */
function clearanceEndToEnd(int $appId, string $code): void
{
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")->assertOk();
    test()->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
        'form_data' => SHEET_ANSWERS[$code] ?? [],
        'submit' => true,
    ])->assertOk();

    authAs(OFFICER_FOR[$code]);
    $assignment = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', PermitType::where('code', $code)->value('issuing_department_id'))
        ->firstOrFail();

    test()->postJson("/api/v1/assignments/{$assignment->id}/approve", [
        'remarks' => 'Requirements complete. Endorsed for inspection.',
    ])->assertOk();

    $inspectionId = test()->postJson("/api/v1/applications/{$appId}/permits/{$code}/inspection", [
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/v1/inspections/{$inspectionId}/conduct", [
        'result' => 'passed',
        'findings' => 'Premises inspected and found compliant.',
    ])->assertOk();
}

/** BPLO's last act: every office is done, so issue. */
function bploIssues(int $appId, string $remarks): void
{
    authAs('bplo@biztrack.local');
    $assignment = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();

    test()->postJson("/api/v1/assignments/{$assignment->id}/approve", ['remarks' => $remarks])
        ->assertOk();
}

function permitCodesOn(int $appId): array
{
    return Application::findOrFail($appId)
        ->permits()->with('permitType')->get()
        ->pluck('permitType.code')->sort()->values()->all();
}

it('walks a new application from filing to every permit issued', function () {
    $businessId = registerBusiness('Bautista Hardware & Construction Supply', 'DTI-2026-0451233');

    // ── 1. File for the business permit; submission attaches the rest ────
    authAs('owner@biztrack.local');
    $appId = $this->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'data_privacy_consent' => true,
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        'fee_profile' => feeProfile(1_850_000),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    expect(Application::find($appId)->permitTypes()->pluck('code')->sort()->values()->all())
        ->toBe(['BUSINESS', 'CEC', 'FSIC', 'OCCUPANCY', 'SANITARY', 'ZONING']);

    // ── 2. BPLO accepts the form; the Tax Order of Payment is raised ─────
    bploAccepts($appId, 'Form complete. Assessed as a simple transaction.');
    expect(Application::find($appId)->status->value)->toBe('pending_payment');

    // ── 3. Payment opens the clearance stage ─────────────────────────────
    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();
    expect(Application::find($appId)->status->value)->toBe('awaiting_other_permits');

    // ── 4. All five clearances, each with its own office ─────────────────
    foreach (array_keys(OFFICER_FOR) as $code) {
        clearanceEndToEnd($appId, $code);
    }
    expect(Application::find($appId)->status->value)->toBe('for_final_approval');

    // ── 5. BPLO's final approval ─────────────────────────────────────────
    bploIssues($appId, 'All clearances secured. Business permit approved.');
    expect(Application::find($appId)->status->value)->toBe('approved');

    // ── 6. Six certificates, one per permit ──────────────────────────────
    expect(permitCodesOn($appId))
        ->toBe(['BUSINESS', 'CEC', 'FSIC', 'OCCUPANCY', 'SANITARY', 'ZONING']);

    foreach (Application::find($appId)->permits as $permit) {
        expect($permit->permit_number)->not->toBeEmpty()
            ->and($permit->valid_until)->not->toBeNull();
    }

    // And they reach the applicant through the endpoint their profile reads.
    authAs('owner@biztrack.local');
    $mine = $this->getJson('/api/v1/permits')->assertOk()->json('data');
    expect(collect($mine)->pluck('permit_type.code')->all())
        ->toContain('BUSINESS', 'CEC', 'ZONING', 'SANITARY', 'FSIC', 'OCCUPANCY');
})->group('lifecycle');

it('renews two of the six and issues only those', function () {
    /*
     * The second half of the client's request, and the case the old code broke:
     * a renewal that leaves the Mayor's Permit alone. The prior permits are
     * staggered on purpose — differing expiry dates are the client's whole
     * reason for renewing a subset.
     */
    $businessId = registerBusiness('Bautista Hardware & Construction Supply', 'DTI-2026-0451233');
    $ownerId = User::where('email', 'owner@biztrack.local')->value('id');

    $priorApp = Application::create([
        'business_id' => $businessId,
        'applicant_user_id' => $ownerId,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    $prior = [];
    foreach (['BUSINESS', 'ZONING', 'SANITARY', 'CEC', 'FSIC', 'OCCUPANCY'] as $i => $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $prior[$code] = Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $businessId,
            'permit_type_id' => $type->id,
            'permit_number' => $type->permit_number_prefix.'-2025-'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays(15 + ($i * 45)),
            'status' => 'active',
        ]);
    }

    // ── 1. File a renewal for the two that are falling due ───────────────
    $renewCodes = ['SANITARY', 'ZONING'];
    authAs('owner@biztrack.local');
    $appId = $this->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'renewal',
        'data_privacy_consent' => true,
        'permit_type_ids' => PermitType::whereIn('code', $renewCodes)->pluck('id')->all(),
        'prior_permit_id' => $prior['SANITARY']->id,
        'prior_permit_ids' => [$prior['SANITARY']->id, $prior['ZONING']->id],
        'fee_profile' => feeProfile(2_100_000),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    // NOT expanded to six.
    expect(Application::find($appId)->permitTypes()->pluck('code')->sort()->values()->all())
        ->toBe($renewCodes);

    // ── 2. The same path a new filing takes ──────────────────────────────
    bploAccepts($appId, 'Renewal form complete.');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    // The clearance stage offers the two, not five.
    $rows = $this->getJson("/api/v1/applications/{$appId}/clearances")->assertOk()->json('data');
    expect(collect($rows)->pluck('permit_type.code')->sort()->values()->all())->toBe($renewCodes);

    foreach ($renewCodes as $code) {
        clearanceEndToEnd($appId, $code);
    }
    expect(Application::find($appId)->status->value)->toBe('for_final_approval');

    // ── 3. Approval issues the two renewed certificates and no others ────
    bploIssues($appId, 'Renewal approved.');
    expect(Application::find($appId)->status->value)->toBe('approved');
    expect(permitCodesOn($appId))->toBe($renewCodes);
})->group('lifecycle');

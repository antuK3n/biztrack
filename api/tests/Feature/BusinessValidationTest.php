<?php

use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\FeeAssessment;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;

/*
 * Business registration + line-of-business rules behind the wizard's step 2
 * and step 3 gating (tester items 3, 5, 12, 15).
 */

/** A valid POST /businesses body, overridable per test. */
function businessPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Tester Trading',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-55123',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Test St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::where('code', '47111')->value('id')]],
    ], $overrides);
}

it('requires the DTI / SEC / CDA registration number and type', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', businessPayload([
            'registration_number' => '',
            'registration_type' => '',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['registration_number', 'registration_type']);
});

it('requires a TIN', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', businessPayload(['tin' => '']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('tin');
});

it('rejects a malformed TIN', function (string $tin) {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', businessPayload(['tin' => $tin]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('tin');
})->with([
    '12345678',          // too short
    '1234567890',        // 10 digits: no branch code is that long
    '123-456-789-0',     // 1-digit branch code
    '123456789000000',   // 15 digits
    'TIN-123456789',     // letters
    'not-a-tin',
]);

it('accepts the usual TIN separators and stores one canonical form', function (string $typed) {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', businessPayload(['tin' => $typed]))
        ->assertCreated()
        ->assertJsonPath('data.tin', '123-456-789-000');
})->with([
    '123-456-789-000',
    '123456789000',
    '123 456 789 000',
    '123.456.789.000',
]);

it('accepts a 9-digit TIN without a branch code', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', businessPayload(['tin' => '123456789']))
        ->assertCreated()
        ->assertJsonPath('data.tin', '123-456-789');
});

it('persists a free-text line of business for the "Other (not listed)" code', function () {
    $otherId = PsicCode::where('code', ReferenceSeeder::OTHER_PSIC_CODE)->value('id');
    expect($otherId)->not->toBeNull();

    $businessId = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', businessPayload([
            'lines' => [[
                'psic_code_id' => $otherId,
                'capitalization' => 25000,
                'line_of_business' => '  Bamboo furniture weaving  ',
            ]],
        ]))
        ->assertCreated()
        ->json('data.id');

    $line = Business::find($businessId)->lines()->first();
    expect($line->psic_code_id)->toBe($otherId)
        ->and($line->line_of_business)->toBe('Bamboo furniture weaving');
});

it('keeps the free-text line when the business is updated', function () {
    $otherId = PsicCode::where('code', ReferenceSeeder::OTHER_PSIC_CODE)->value('id');
    $owner = authAs('owner@biztrack.local');

    $businessId = $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', businessPayload([
            'lines' => [['psic_code_id' => $otherId, 'line_of_business' => 'Bangka rental']],
        ]))
        ->assertCreated()
        ->json('data.id');

    $this->withHeaders($owner)
        ->putJson("/api/v1/businesses/{$businessId}", businessPayload([
            'lines' => [['psic_code_id' => $otherId, 'line_of_business' => 'Bangka rental and repair']],
        ]))
        ->assertOk();

    expect(Business::find($businessId)->lines()->first()->line_of_business)
        ->toBe('Bangka rental and repair');
});

it('still assesses fees for a free-text line via the revenue-code catch-all', function () {
    $otherId = PsicCode::where('code', ReferenceSeeder::OTHER_PSIC_CODE)->value('id');
    $owner = authAs('owner@biztrack.local');

    $businessId = $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', businessPayload([
            'lines' => [['psic_code_id' => $otherId, 'line_of_business' => 'Bangka rental']],
        ]))
        ->assertCreated()
        ->json('data.id');

    $appId = $this->withHeaders($owner)
        ->postJson('/api/v1/applications', [
            'business_id' => $businessId,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_payment');

    $fee = FeeAssessment::where('application_id', $appId)->first();
    expect((float) $fee->total_amount)->toBeGreaterThan(0.0);
});

it('routes the zoning clearance to the City Planning and Development Office', function () {
    $owner = authAs('owner@biztrack.local');
    $businessId = $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', businessPayload(['name' => 'Zoning Test Co']))
        ->assertCreated()
        ->json('data.id');

    $typeIds = PermitType::whereIn('code', ['BUSINESS', 'ZONING'])->pluck('id')->all();
    $appId = $this->withHeaders($owner)
        ->postJson('/api/v1/applications', [
            'business_id' => $businessId,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => $typeIds,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    $deptCodes = ApplicationAssignment::where('application_id', $appId)
        ->pluck('department_id')
        ->map(fn ($id) => Department::find($id)->code)
        ->all();

    expect($deptCodes)->toContain('CPDO')->toContain('BPLO');
});

/* ── Unified form fields (checklist item 2) ─────────────────────────────── */

it('requires the lessor block only when the premises are rented', function () {
    $payload = [
        'name' => 'Rented Shop',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-2026-5001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '1 Test St', 'barangay_id' => 1],
        'lines' => [['psic_code_id' => 1]],
        'is_rented' => true,
    ];

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lessor_name', 'lessor_address', 'monthly_rental']);

    // Owner-occupied: the same payload without the rented flag sails through,
    // because an owner has no lessor to name.
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', array_merge($payload, ['is_rented' => false, 'name' => 'Owned Shop']))
        ->assertCreated();
});

it('stores and returns the lessor and emergency contact block', function () {
    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', [
            'name' => 'Lessor Detail Shop',
            'registration_type' => 'sole_proprietorship',
            'registration_number' => 'DTI-2026-5002',
            'tin' => '123-456-789-000',
            'address' => ['line1' => '2 Test St', 'barangay_id' => 1],
            'lines' => [['psic_code_id' => 1]],
            'is_rented' => true,
            'lessor_name' => 'Aling Nena',
            'lessor_address' => '9 Rizal Ave, Malabon',
            'lessor_contact' => '09171234567',
            'monthly_rental' => 12000,
            'emergency_contact_name' => 'Mang Tonyo',
            'emergency_contact_number' => '09181234567',
        ])
        ->assertCreated();

    expect($res->json('data.is_rented'))->toBeTrue()
        ->and($res->json('data.lessor_name'))->toBe('Aling Nena')
        ->and((float) $res->json('data.monthly_rental'))->toBe(12000.0)
        ->and($res->json('data.emergency_contact_name'))->toBe('Mang Tonyo');
});

it('accepts annual and quarterly payment modes and nothing else', function () {
    // Ordinance Sec. 2N offers exactly these two; a semi-annual option would be
    // the system inventing a payment schedule the ordinance does not grant.
    $business = Business::where('owner_user_id', User::where('email', 'owner@biztrack.local')->value('id'))->firstOrFail();
    $base = [
        'business_id' => $business->id,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ];

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', $base + ['payment_mode' => 'quarterly'])
        ->assertCreated()
        ->assertJsonPath('data.payment_mode', 'quarterly');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', $base + ['payment_mode' => 'semi_annual'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['payment_mode']);
});

it('defaults the payment mode to annual', function () {
    $business = Business::where('owner_user_id', User::where('email', 'owner@biztrack.local')->value('id'))->firstOrFail();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
        ])
        ->assertCreated()
        ->assertJsonPath('data.payment_mode', 'annual');
});

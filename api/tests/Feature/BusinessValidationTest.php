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

    /*
     * Submitting still raises the bill; it no longer asks for the money.
     *
     * The subject here is that a free-text line of business is priced at all —
     * the revenue-code catch-all — and that has not changed. What changed is the
     * status the filing lands on: BPLO reads the form before the applicant is
     * asked to pay (docs/application-flow-2026-09.md), so submit ends at
     * `for_approval` and `pending_payment` is where BPLO's approval puts it. The
     * assessment below is written at submission either way, which is what lets
     * this case assert the fee without paying it.
     */
    $this->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'for_approval');

    $fee = FeeAssessment::where('application_id', $appId)->first();
    expect((float) $fee->total_amount)->toBeGreaterThan(0.0);
});

it('routes the zoning clearance to the City Planning and Development Office when the applicant hands its form in', function () {
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

    /*
     * Paying opens the clearance stage; HANDING IN the zoning sheet is what
     * reaches CPDO.
     *
     * Routing moved off payment and onto the applicant's own act
     * (`WorkflowService::startClearance`, docs/application-flow-2026-09.md), one
     * office at a time, so that `assigned_at` measures CPDO's service time and
     * not the days the owner spent on the other four forms. On 9 September 2026
     * it moved one step further along the same reasoning: applying only opens
     * the sheet, and `WorkflowService::submitClearanceForm` — reached here by
     * saving the sheet with `submit` — is what gives CPDO something to read.
     *
     * The rule this case exists for is unchanged and is the last line: ZONING
     * belongs to CPDO and to no other office.
     */
    $this->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/clearances/ZONING/apply")
        ->assertSuccessful();

    $this->withHeaders($owner)
        ->putJson("/api/v1/applications/{$appId}/office-forms/ZONING", [
            'form_data' => [],
            'submit' => true,
        ])->assertSuccessful();

    $deptCodes = ApplicationAssignment::where('application_id', $appId)
        ->pluck('department_id')
        ->map(fn ($id) => Department::find($id)->code)
        ->all();

    expect($deptCodes)->toContain('CPDO')->toContain('BPLO');
});

/* ── Unified form fields (checklist item 2) ─────────────────────────────── */

it('accepts a rented business without any lessor details', function () {
    /*
     * The lessor block used to be `required_if:is_rented,true` — name, address
     * and monthly rental all demanded of a lessee. It is not any more.
     *
     * MCG-BPLO-FO-001 item 9 asks one thing, "Do you pay rent for occupying a
     * place of business?", and nothing about the lessor. The four lessor boxes
     * came from the national BPLS unified form, and the client removed them
     * from the wizard on 16 September 2026 as absent from this paper.
     *
     * Requiring them on the API after removing them from the screen is the
     * exact failure this replaces: the applicant met "Enter the lessor's name,
     * or set the premises to owner-occupied" on a step with no such box and no
     * way to clear it. A gate and the control that satisfies it go together.
     *
     * The lessor's name and address are still asked — on MCG-CPDD-FO-003
     * items VIII.C and VIII.D, on the zoning sheet, which collects them into
     * its own form_data rather than into these columns.
     */
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
        ->assertCreated();

    // And owner-occupied still sails through, as it always did.
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

/*
 * ── BPLO item 5: the House/Bldg. No. and the Street are two boxes ─────────
 *
 * They were one question, "House No. & Street Name", written into `line1` —
 * while `business_addresses.house_bldg_no` and `.street` sat empty on every
 * row, having been added when the schema was aligned to the paper.
 *
 * The cost was not tidiness. The officer's review page had to guess the split
 * back out of `line1` with a regex, and filings exist whose entire street
 * address is "17" because the applicant read the label as asking for the
 * number — which that regex cannot parse, so BPLO was shown Street "17" and
 * House "—", the two answers reversed.
 */
it('stores the house number and the street separately, and composes line1', function () {
    $owner = authAs('owner@biztrack.local');

    $response = $this->withHeaders($owner)->postJson('/api/v1/businesses', businessPayload([
        'name' => 'Split Address Trading',
        'address' => [
            'house_bldg_no' => '17',
            'street' => 'Gen. Luna Street',
            'barangay_id' => Barangay::first()->id,
        ],
    ]))->assertCreated();

    $address = Business::find($response->json('data.id'))->address;

    expect($address->house_bldg_no)->toBe('17')
        ->and($address->street)->toBe('Gen. Luna Street')
        // Composed, not typed: everything that reads an address as one line —
        // the office sheets, the permit PDFs, the officer list — is untouched.
        ->and($address->line1)->toBe('17 Gen. Luna Street');

    // And served back in both shapes, so the wizard can put them into the two
    // boxes it will save over.
    $this->withHeaders($owner)
        ->getJson("/api/v1/businesses/{$response->json('data.id')}")
        ->assertOk()
        ->assertJsonPath('data.address.house_bldg_no', '17')
        ->assertJsonPath('data.address.street', 'Gen. Luna Street');
});

it('files premises that have no house number', function () {
    /*
     * A stall inside a public market, a unit known only by its building's
     * name. The paper prints a line for the number without marking it
     * required, so demanding one would be our rule and not the city's.
     */
    $owner = authAs('owner@biztrack.local');

    $response = $this->withHeaders($owner)->postJson('/api/v1/businesses', businessPayload([
        'name' => 'Stall Only Trading',
        'address' => [
            'street' => 'Malabon Public Market',
            'barangay_id' => Barangay::first()->id,
        ],
    ]))->assertCreated();

    $address = Business::find($response->json('data.id'))->address;

    expect($address->house_bldg_no)->toBeNull()
        // No leading space on the composed line.
        ->and($address->line1)->toBe('Malabon Public Market');
});

it('leaves a combined address alone when only line1 is sent', function () {
    /*
     * An importer, or a draft saved before the split. Two empty parts must not
     * blank an address somebody already gave — which is what a composed value
     * written unconditionally would do on the next autosave.
     */
    $owner = authAs('owner@biztrack.local');

    $response = $this->withHeaders($owner)->postJson('/api/v1/businesses', businessPayload([
        'name' => 'Legacy Payload Trading',
        'address' => ['line1' => '88 Rizal Avenue', 'barangay_id' => Barangay::first()->id],
    ]))->assertCreated();

    $address = Business::find($response->json('data.id'))->address;

    expect($address->line1)->toBe('88 Rizal Avenue')
        ->and($address->house_bldg_no)->toBeNull()
        ->and($address->street)->toBeNull();
});

it('stores block, lot and lot area on the address and reads them back', function () {
    $address = ['house_bldg_no' => '', 'street' => 'Gen. Luna Street', 'block' => '5', 'lot' => '12',
        'lot_area_sqm' => 120.5, 'barangay_id' => Barangay::first()->id];

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', businessPayload(['address' => $address]))
        ->assertCreated()
        ->assertJsonPath('data.address.block', '5')
        ->assertJsonPath('data.address.lot', '12')
        ->assertJsonPath('data.address.lot_area_sqm', 120.5);
});

it('keeps block, lot and lot area when a later save does not send them', function () {
    $headers = authAs('owner@biztrack.local');
    $barangayId = Barangay::first()->id;
    $id = $this->withHeaders($headers)->postJson('/api/v1/businesses', businessPayload([
        'address' => ['street' => 'Gen. Luna Street', 'block' => '5', 'lot' => '12',
            'lot_area_sqm' => 80, 'barangay_id' => $barangayId],
    ]))->assertCreated()->json('data.id');

    $this->withHeaders($headers)->putJson("/api/v1/businesses/{$id}", businessPayload([
        'address' => ['street' => 'Gen. Luna Street', 'barangay_id' => $barangayId],
    ]))->assertOk()
        ->assertJsonPath('data.address.block', '5')
        ->assertJsonPath('data.address.lot', '12')
        ->assertJsonPath('data.address.lot_area_sqm', 80);
});

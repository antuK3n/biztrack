<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\User;
use App\Support\OfficerEditRules;

/*
 * An office corrects the business permit form, under three groups.
 *
 * ── What this guards ────────────────────────────────────────────────────────
 *
 * Client, 19 October 2026: *"it is told to us to have restrictions on what
 * certain fields are allowed to be edited only by the admin."* The groups are
 * in App\Support\OfficerEditRules, and this file pins the server half of them
 * for the business permit form (PUT /applications/{id}/fields):
 *
 *  - CLERICAL fixes (business / trade name, house no. and street, contact
 *    numbers, e-mail, website) are BPLO's to make, with a reason that is
 *    audited beside the old and new value and the officer, and the owner is
 *    told.
 *  - LOCKED declarations (TIN, registration number, the owner's name, form
 *    of organization, line of business, capital, rent …) are refused to every
 *    office, reason or not. The remedy is Return.
 *  - The form is BPLO's: a clearance office reading it may not correct it.
 *
 * The applicant's own validator still runs first — a correction that the form
 * itself would refuse is refused here too.
 */

/** The filing scopedAssignmentFiling builds, as an Application. */
function filingForOfficerEdit(string $name): Application
{
    return Application::findOrFail(scopedAssignmentFiling($name));
}

/**
 * The business exactly as it stands, restated — what the review page sends —
 * with `$overrides` laid over it. Restating matters now: an answer the payload
 * left out would read as a change to a locked field.
 */
function officerFieldsPayload(Application $app, array $overrides = []): array
{
    $business = $app->fresh()->business;
    $address = $business->address;
    $owner = $business->owners()->where('is_primary', true)->first();

    $payload = [
        'name' => $business->name,
        'trade_name' => $business->trade_name,
        'registration_type' => $business->registration_type,
        'registration_number' => $business->registration_number,
        'tin' => $business->tin,
        'is_rented' => (bool) $business->is_rented,
        'has_tax_incentives' => (bool) $business->has_tax_incentives,
        'capital_investment' => $business->capital_investment,
        'economic_organization' => $business->economic_organization,
        'president_officer_name' => $business->president_officer_name,
        'citizenship' => $business->citizenship,
        'capital_participation_filipino' => $business->capital_participation_filipino,
        'emergency_contact_name' => $business->emergency_contact_name,
        'emergency_contact_number' => $business->emergency_contact_number,
        'lessor_name' => $business->lessor_name,
        'lessor_address' => $business->lessor_address,
        'lessor_contact' => $business->lessor_contact,
        'monthly_rental' => $business->monthly_rental,
        'address' => [
            'line1' => $address->line1,
            'line2' => $address->line2,
            'barangay_id' => $address->barangay_id,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'postal_code' => $address->postal_code,
            'telephone' => $address->telephone,
            'mobile_number' => $address->mobile_number,
            'email' => $address->email,
            'website' => $address->website,
        ],
        'lines' => $business->lines->map(fn ($l) => [
            'psic_code_id' => $l->psic_code_id,
            'capitalization' => $l->capitalization,
            'line_of_business' => $l->line_of_business,
            'products_services' => $l->products_services,
        ])->all(),
    ];
    if ($owner !== null) {
        $payload['owner'] = $owner->only(['surname', 'given_name', 'middle_name', 'suffix', 'gender']);
    }

    return array_replace_recursive($payload, $overrides);
}

it('serves the three groups from the one class the API enforces', function () {
    authAs('sanitary@biztrack.local');

    test()->getJson('/api/v1/reference/officer-edit-rules')
        ->assertOk()
        ->assertJsonPath('data.business.office', 'BPLO')
        ->assertJsonPath('data.business.clerical', OfficerEditRules::BUSINESS_CLERICAL)
        ->assertJsonPath('data.sheets.OCCUPANCY.clerical', OfficerEditRules::SHEET_CLERICAL['OCCUPANCY'])
        ->assertJsonPath('data.sheets.ZONING.office_use', OfficerEditRules::SHEET_OFFICE_USE);

    // Every 422 the groups produce is one short line (client: under ~50 characters).
    foreach ([OfficerEditRules::LOCKED_MESSAGE, OfficerEditRules::REASON_MESSAGE, OfficerEditRules::NOT_YOUR_FORM_MESSAGE] as $message) {
        expect(mb_strlen($message))->toBeLessThan(50);
    }
});

it('lets BPLO fix a clerical slip with a reason, audits it and tells the owner', function () {
    $app = filingForOfficerEdit('Officer Edit Co');
    $before = $app->business->name;

    authAs('bplo@biztrack.local');
    $changed = test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'name' => 'Corrected Trading Name',
        'address' => ['email' => 'shop@example.com'],
        'reason' => 'Misspelt on the form.',
    ]))
        ->assertOk()
        ->json('meta.changed');

    expect($changed)->toEqualCanonicalizing(['name', 'address.email'])
        ->and($app->fresh()->business->name)->toBe('Corrected Trading Name');

    $entry = AuditLog::where('action', 'application.fields_corrected_by_officer')->latest('id')->first();
    expect($entry->changes['reason'])->toBe('Misspelt on the form.')
        ->and($entry->changes['department'])->toBe('BPLO')
        ->and($entry->changes['officer_id'])->toBe(User::where('email', 'bplo@biztrack.local')->value('id'))
        ->and($entry->changes['changed']['name'])->toBe(['from' => $before, 'to' => 'Corrected Trading Name']);

    $notice = AppNotification::where('user_id', $app->applicant_user_id)->latest('id')->first();
    expect($notice->title)->toBe('BPLO corrected your filing')
        ->and($notice->body)->toContain('business name and e-mail address')
        ->and($notice->body)->toContain('Misspelt on the form.');
});

it('refuses a clerical fix that gives no reason, and keeps the old value', function () {
    $app = filingForOfficerEdit('Officer Edit Reason Co');

    authAs('bplo@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'trade_name' => 'Fixed Trade Name',
        'reason' => '   ',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('message', OfficerEditRules::REASON_MESSAGE);

    expect($app->fresh()->business->trade_name)->not->toBe('Fixed Trade Name')
        ->and(AuditLog::where('action', 'application.fields_corrected_by_officer')->count())->toBe(0);
});

it('refuses a locked declaration even with a reason, and rolls the whole save back', function () {
    $app = filingForOfficerEdit('Officer Edit Locked Co');
    $tin = $app->business->tin;
    $name = $app->business->name;

    authAs('bplo@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'tin' => '987-654-321-000',
        // A clerical change in the same request must not land on its own.
        'name' => 'Should Not Land',
        'reason' => 'Applicant asked.',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('message', OfficerEditRules::LOCKED_MESSAGE)
        ->assertJsonValidationErrors(['fields.tin']);

    $business = $app->fresh()->business;
    expect($business->tin)->toBe($tin)
        ->and($business->name)->toBe($name)
        ->and(AppNotification::where('title', 'BPLO corrected your filing')->count())->toBe(0);
});

it('locks the owner’s name, the form of organization and the line of business', function () {
    $app = filingForOfficerEdit('Officer Edit Owner Row Co');
    authAs('bplo@biztrack.local');

    foreach ([
        ['owner' => ['surname' => 'Reyes', 'given_name' => 'Corazon']],
        ['registration_type' => 'corporation'],
        ['lines' => [['products_services' => 'Something else entirely']]],
        ['is_rented' => true],
        ['capital_investment' => 999999],
    ] as $change) {
        test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, $change + ['reason' => 'Trying.']))
            ->assertStatus(422)
            ->assertJsonPath('message', OfficerEditRules::LOCKED_MESSAGE);
    }
});

it('refuses a clearance office, whose sheet only carries BPLO’s form', function () {
    $app = filingForOfficerEdit('Officer Edit Clearance Co');

    authAs('sanitary@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'name' => 'Not Theirs',
        'reason' => 'Typo.',
    ]))
        ->assertForbidden()
        ->assertJsonPath('message', OfficerEditRules::NOT_YOUR_FORM_MESSAGE);

    expect($app->fresh()->business->name)->not->toBe('Not Theirs');
});

it('applies the applicant’s own validation, not a second copy of it', function () {
    $app = filingForOfficerEdit('Officer Edit Rules Co');

    authAs('bplo@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'address' => ['email' => 'not-an-email'],
        'reason' => 'Typo.',
    ]))->assertStatus(422);

    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'name' => '',
        'reason' => 'Typo.',
    ]))->assertStatus(422);
});

it('reports nothing changed when nothing did, and asks no reason for it', function () {
    $app = filingForOfficerEdit('Officer Edit Noop Co');

    authAs('bplo@biztrack.local');
    $changed = test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app))
        ->assertOk()
        ->json('meta.changed');

    expect($changed)->toBe([]);
});

it('keeps the map pin when the street is corrected', function () {
    /*
     * `syncAddressAndLines` defaults latitude and longitude to null when the
     * payload omits them; the page restates them, and the lock would now
     * refuse a request that moved the pin rather than let it be erased.
     */
    $app = filingForOfficerEdit('Officer Edit Street Co');
    $app->business->address->update(['latitude' => 14.6600, 'longitude' => 120.9600]);

    authAs('bplo@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'address' => ['street' => 'Rizal Avenue', 'house_bldg_no' => '12'],
        'reason' => 'Street misspelt.',
    ]))->assertOk();

    $fresh = $app->fresh()->business->address;
    expect($fresh->street)->toBe('Rizal Avenue')
        ->and((float) $fresh->latitude)->toBe(14.66);

    // And moving the pin is a locked change, not a silent one.
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'address' => ['latitude' => 14.67],
        'reason' => 'Moving it.',
    ]))->assertStatus(422);
});

it('locks the barangay, which the zoning decision turns on', function () {
    $app = filingForOfficerEdit('Officer Edit Barangay Co');
    $other = Barangay::where('id', '!=', $app->business->address->barangay_id)->firstOrFail();

    authAs('bplo@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'address' => ['barangay_id' => $other->id],
        'reason' => 'Wrong barangay.',
    ]))->assertStatus(422);

    expect($app->fresh()->business->address->barangay_id)->not->toBe($other->id);
});

it('refuses the applicant, who has their own door', function () {
    $app = filingForOfficerEdit('Officer Edit Owner Co');

    authAs('owner@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
        'name' => 'Owner Route',
        'reason' => 'Mine.',
    ]))->assertForbidden();
});

it('refuses an edit once the filing is rejected or cancelled', function () {
    $app = filingForOfficerEdit('Officer Edit Decided Co');

    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Fake registration.'])->assertOk();
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, ['name' => 'After Rejection', 'reason' => 'Typo.']))
        ->assertStatus(422)
        ->assertJsonPath('message', "This filing has been decided and can't be edited.");

    $cancelled = filingForOfficerEdit('Officer Edit Cancelled Co');
    Application::whereKey($cancelled->id)->update(['status' => 'cancelled']);
    authAs('bplo@biztrack.local');
    test()->putJson("/api/v1/applications/{$cancelled->id}/fields", officerFieldsPayload($cancelled, ['name' => 'After Cancelling', 'reason' => 'Typo.']))
        ->assertStatus(422);

    expect($app->fresh()->business->name)->not->toBe('After Rejection')
        ->and($cancelled->fresh()->business->name)->not->toBe('After Cancelling');
});

it('refuses a BPLO officer who does not hold the case', function () {
    $app = filingForOfficerEdit('Officer Edit Holder Co');
    $bplo = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->update(['officer_user_id' => $bplo->id]);

    $colleague = User::create([
        'name' => 'Other Bplo', 'first_name' => 'Other', 'last_name' => 'Bplo', 'gender' => 'F',
        'email' => 'other.bplo@biztrack.local', 'password' => 'biztrack1', 'mobile_number' => '09170000089',
        'department_id' => $bplo->department_id, 'is_active' => true, 'email_verified_at' => now(),
    ]);
    $colleague->roles()->sync($bplo->roles()->pluck('roles.id'));

    authAs('other.bplo@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, ['name' => 'Not The Holder', 'reason' => 'Typo.']))
        ->assertForbidden()
        ->assertJsonPath('message', 'Only the officer handling this filing can edit it.');

    authAs('bplo@biztrack.local');
    test()->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, ['name' => 'By The Holder', 'reason' => 'Typo.']))
        ->assertOk();
    expect($app->fresh()->business->name)->toBe('By The Holder');
});

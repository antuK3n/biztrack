<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\PsicCode;

/*
 * An officer corrects the filing's own answers, under the applicant's rules.
 *
 * ── What this guards ────────────────────────────────────────────────────────
 *
 * The client instructed on 30 September 2026 that Edit mode let every admin
 * change any field. Edit mode had always locked the applicant's answers, and
 * for a reason: a citizen's declaration is corrected by RETURNING the filing,
 * which records the change against the person who declared it.
 *
 * Writing them directly loses that for free, so it is bought back — and these
 * cases pin the two halves of the purchase:
 *
 *  1. THE SAME RULES. The endpoint calls the applicant's own
 *     `validateBusiness()` rather than carrying a copy, which is the only
 *     reading of "same validation as their original counterparts" that stays
 *     true after the next paper change. A rubbish TIN is refused here exactly
 *     as it is refused on the form.
 *
 *  2. THE TRAIL. Every changed field is audited with the officer named. If
 *     that ever stops happening, a declared answer can be altered by somebody
 *     other than the declarant with nothing on the record saying so.
 *
 * And the boundary: an office may only correct a filing it can already read.
 */

/** The filing scopedAssignmentFiling builds, as an Application. */
function filingForOfficerEdit(string $name): Application
{
    return Application::findOrFail(scopedAssignmentFiling($name));
}

/** Everything `validateBusiness` requires, so a case can vary one field. */
function officerFieldsPayload(Application $app, array $overrides = []): array
{
    $business = $app->business;

    return array_merge([
        'name' => $business->name,
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-99887',
        'tin' => '123-456-789-000',
        'address' => [
            'line1' => '3 Scoped Street',
            'barangay_id' => Barangay::first()->id,
        ],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ], $overrides);
}

it('lets a reviewing officer correct a field on a filing they hold', function () {
    $app = filingForOfficerEdit('Officer Edit Co');

    $sanitary = authAs('sanitary@biztrack.local');
    test()->withHeaders($sanitary)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
            'name' => 'Corrected Trading Name',
        ]))
        ->assertOk();

    expect($app->fresh()->business->name)->toBe('Corrected Trading Name');
});

it('applies the applicant’s own validation, not a second copy of it', function () {
    /*
     * The client's instruction was that these fields carry the rules of their
     * counterparts on the form. The endpoint calls `validateBusiness()` to
     * mean it — so a TIN the form would refuse is refused here, and will stay
     * refused when that rule next changes.
     */
    $app = filingForOfficerEdit('Officer Edit Rules Co');

    $sanitary = authAs('sanitary@biztrack.local');
    test()->withHeaders($sanitary)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
            'tin' => 'not-a-tin',
        ]))
        ->assertStatus(422);

    /* And a required field cannot be emptied. */
    test()->withHeaders($sanitary)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
            'name' => '',
        ]))
        ->assertStatus(422);
});

it('records every changed field against the officer who changed it', function () {
    $app = filingForOfficerEdit('Officer Edit Audit Co');
    $before = $app->business->name;

    $sanitary = authAs('sanitary@biztrack.local');
    $changed = test()->withHeaders($sanitary)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
            'name' => 'Audited Name',
        ]))
        ->assertOk()
        ->json('meta.changed');

    /*
     * The response says what moved. A change nobody can trace to a person is
     * what makes this endpoint dangerous, and naming them is the answer.
     */
    expect($changed)->toContain('name')
        ->and($before)->not->toBe('Audited Name');
});

it('reports nothing changed when nothing did', function () {
    /*
     * Saving an untouched form is an ordinary press — the officer opened Edit,
     * looked, and saved. It must not read as a correction on the record.
     */
    $app = filingForOfficerEdit('Officer Edit Noop Co');

    $sanitary = authAs('sanitary@biztrack.local');
    /* Seed the watched columns first, so the second call changes nothing. */
    test()->withHeaders($sanitary)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app))
        ->assertOk();

    $again = test()->withHeaders($sanitary)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app))
        ->assertOk()
        ->json('meta.changed');

    expect($again)->toBe([]);
});

it('records a change to the named owner, who lives on another table', function () {
    /*
     * The audit watched the twelve columns on `businesses` and nothing else,
     * which was true to the screen when it was written — the sheet offered no
     * control for anything else. It offers one for all five of the owner's
     * name fields now, and an officer who can change WHO OWNS a business with
     * nothing on the record is the exact failure the entry exists to prevent.
     */
    $app = filingForOfficerEdit('Officer Edit Owner Row Co');

    $sanitary = authAs('sanitary@biztrack.local');
    $changed = test()->withHeaders($sanitary)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
            'owner' => [
                'surname' => 'Reyes',
                'given_name' => 'Corazon',
                'middle_name' => null,
                'suffix' => null,
                'gender' => 'F',
            ],
        ]))
        ->assertOk()
        ->json('meta.changed');

    expect($changed)->toContain('owner.surname')
        ->and($app->fresh()->business->owners()->where('is_primary', true)->first()->surname)
        ->toBe('Reyes');
});

it('records a change of barangay, and keeps the map pin', function () {
    /*
     * Two things at once, because they fail together.
     *
     * The barangay is the one answer on the sheet that is a choice from the
     * city's own table, and it decides which zoning rules the filing is read
     * against — so a change to it is the last one that should go unrecorded.
     *
     * And `syncAddressAndLines` defaults latitude and longitude to null when
     * the payload omits them, which is right for the wizard (it sends the pin
     * on every save) and lethal here: an officer fixing a typo in the street
     * would silently erase the pin CPDD rules the locational clearance from.
     * The client restates it; this case is what notices if that ever stops.
     */
    $app = filingForOfficerEdit('Officer Edit Barangay Co');
    $address = $app->business->address;
    $address->update(['latitude' => 14.6600, 'longitude' => 120.9600]);

    /*
     * The barangay the pin actually sits in, not just any other one. Since
     * 1 October 2026 the API refuses a pin outside the barangay the business
     * names (MalabonGeo::pinProblem), so moving the business to an arbitrary
     * barangay with the pin left in Tonsuya is refused before anything is
     * recorded. Moving it to the pin's own barangay is the correction an
     * officer would actually make, and it still changes the barangay.
     */
    $pinBarangay = App\Support\MalabonGeo::barangayContaining(14.6600, 120.9600);
    $elsewhere = Barangay::where('name', $pinBarangay)->firstOrFail();
    if ($elsewhere->id === $address->barangay_id) {
        $address->update(['barangay_id' => Barangay::where('id', '!=', $elsewhere->id)->value('id')]);
    }

    $sanitary = authAs('sanitary@biztrack.local');
    $changed = test()->withHeaders($sanitary)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
            'address' => [
                'line1' => '3 Scoped Street',
                'barangay_id' => $elsewhere->id,
                'latitude' => 14.6600,
                'longitude' => 120.9600,
            ],
        ]))
        ->assertOk()
        ->json('meta.changed');

    $fresh = $app->fresh()->business->address;

    expect($changed)->toContain('address.barangay_id')
        ->and($fresh->barangay_id)->toBe($elsewhere->id)
        ->and((float) $fresh->latitude)->toBe(14.66);
});
it('refuses an office the filing was never routed to', function () {
    /*
     * The boundary `ApplicationVisibility` exists to hold. A filing carrying
     * BUSINESS and SANITARY is CHO's and BPLO's; the fire officer can neither
     * read it nor, now, write it.
     */
    $app = filingForOfficerEdit('Officer Edit Boundary Co');

    $fire = authAs('fire@biztrack.local');
    test()->withHeaders($fire)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
            'name' => 'Not Theirs To Change',
        ]))
        ->assertForbidden();

    expect($app->fresh()->business->name)->not->toBe('Not Theirs To Change');
});

it('refuses the applicant, who has their own door', function () {
    /*
     * An owner edits through the wizard, which is Draft-or-Returned only. This
     * endpoint is the OFFICE's, and letting the owner through it would be a
     * way round that guard rather than a convenience.
     */
    $app = filingForOfficerEdit('Officer Edit Owner Co');

    $owner = authAs('owner@biztrack.local');
    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/fields", officerFieldsPayload($app, [
            'name' => 'Owner Route',
        ]))
        ->assertForbidden();
});

<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationOfficeForm;
use App\Models\Business;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\User;

/*
 * Each office sheet's answers are judged key by key (client, 10 November
 * 2026, "screen + server"): `form_data` used to be opaque JSON, so a count
 * took "two", the ZIP code "abcd" and a pick-one any word at all. The rules
 * are App\Support\OfficeFormRules.
 */

/*
 * The owner's home address, and OBO's ZIP Code, come from the account and
 * are locked since 10 November 2026 (OfficeFormAnswers::derive), so a value
 * posted into them is not judged at all — it is replaced. This file is about
 * the TYPED answer, which is what an account without a home address gets, so
 * owner@ has none here. OfficeFormTest covers the locked case.
 */
beforeEach(function () {
    User::where('email', 'owner@biztrack.local')->update(array_map(fn () => null, homeAddress()));
});

/** A draft (or the given status) for owner@ carrying these permit types. */
function ruledSheetApp(
    array $codes,
    ApplicationType $type = ApplicationType::New,
    ApplicationStatus $status = ApplicationStatus::Draft,
    $submittedAt = null,
): Application {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $app = Application::create([
        'business_id' => Business::where('owner_user_id', $owner->id)->firstOrFail()->id,
        'applicant_user_id' => $owner->id,
        'application_type' => $type,
        'status' => $status,
        'submitted_at' => $submittedAt,
    ]);
    $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id'));

    return $app;
}

function ruledSheet(Application $app, string $code): array
{
    return ApplicationOfficeForm::where('application_id', $app->id)
        ->where('permit_type_id', PermitType::where('code', $code)->value('id'))
        ->value('form_data') ?? [];
}

it('refuses a bad answer on each sheet key, naming the key', function (string $code, string $key, mixed $bad) {
    $app = ruledSheetApp([$code]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/{$code}", [
            'form_data' => [$key => $bad],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(["form_data.{$key}"]);

    expect(ruledSheet($app, $code))->not->toHaveKey($key);
})->with([
    'toilets in words' => ['SANITARY', 'toilets_count', 'two'],
    'toilets not whole' => ['SANITARY', 'toilets_count', '2.5'],
    'toilets over 1000' => ['SANITARY', 'toilets_count', '1001'],
    'seating in words' => ['SANITARY', 'seating_capacity', 'forty'],
    'classification off the list' => ['SANITARY', 'sanitary_classification', 'Spa'],
    'water source off the list' => ['SANITARY', 'water_source', 'River'],
    'toilet type off the list' => ['SANITARY', 'toilet_type', 'Bucket'],
    'separate toilets not yes/no' => ['SANITARY', 'toilets_separate_sexes', 'maybe'],
    'sewage off the list' => ['SANITARY', 'sewage_disposal', 'Canal'],
    'solid waste off the list' => ['SANITARY', 'solid_waste_disposal', 'Burning'],
    'segregation not yes/no' => ['SANITARY', 'waste_segregation', 'sometimes'],
    'pest control off the list' => ['SANITARY', 'pest_control', 'Cats'],
    'kitchen not yes/no' => ['SANITARY', 'has_kitchen', 'y'],
    'cold storage not yes/no' => ['SANITARY', 'has_cold_storage', 'n'],
    'operating hours too long' => ['SANITARY', 'operating_hours', str_repeat('a', 256)],
    'zoning storeys zero' => ['ZONING', 'building_storeys', '0'],
    'zoning storeys over 200' => ['ZONING', 'building_storeys', '201'],
    'industrial type off the list' => ['ZONING', 'zoning_industrial_project_type', 'Toxic'],
    'project description too long' => ['ZONING', 'zoning_project_description', str_repeat('a', 1001)],
    'lessor name too long' => ['ZONING', 'lessor_name', str_repeat('a', 256)],
    'representative with digits' => ['ZONING', 'authorized_representative', 'Ana Cruz 2'],
    'cec address too long' => ['CEC', 'owner_address', str_repeat('a', 256)],
    'bfp storeys in words' => ['FSIC', 'building_storeys', 'two'],
    'bfp representative with digits' => ['FSIC', 'authorized_representative', 'R2D2'],
    'obo full or partial off the list' => ['OCCUPANCY', 'application_type', 'Half'],
    'obo zip three digits' => ['OCCUPANCY', 'owner_zip', '147'],
    'obo zip letters' => ['OCCUPANCY', 'owner_zip', 'abcd'],
    'obo tel words' => ['OCCUPANCY', 'owner_tel', 'call the shop'],
    'obo ctc letters' => ['OCCUPANCY', 'owner_ctc_no', 'CTC-001'],
    'obo ctc date in the future' => ['OCCUPANCY', 'owner_ctc_date', now()->addDays(3)->toDateString()],
    'obo storeys zero' => ['OCCUPANCY', 'building_storeys', '0'],
    'obo units zero' => ['OCCUPANCY', 'building_units', '0'],
    'obo units in words' => ['OCCUPANCY', 'building_units', 'three'],
    'obo floor area zero' => ['OCCUPANCY', 'total_floor_area_sqm', '0'],
    'obo floor area words' => ['OCCUPANCY', 'total_floor_area_sqm', 'big'],
    'obo permit no too long' => ['OCCUPANCY', 'building_permit_no', str_repeat('1', 256)],
    'obo submitted by with digits' => ['OCCUPANCY', 'completion_submitted_by', 'Engr. Cruz 3'],
    'obo profession off the two' => ['OCCUPANCY', 'completion_professional', 'CE, PRC 0012345'],
    'an answer that is a list' => ['SANITARY', 'operating_hours', ['8am', '5pm']],
    'an unlisted key holding an object' => ['ZONING', 'anything_else', ['nested' => 'value']],
]);

it('names every bad answer on a sheet in one refusal', function () {
    $app = ruledSheetApp(['OCCUPANCY']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['owner_zip' => '12', 'building_units' => '0', 'owner_ctc_date' => now()->addDays(3)->toDateString()],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['form_data.owner_zip', 'form_data.building_units', 'form_data.owner_ctc_date'])
        ->assertJsonPath('errors', fn ($errors) => $errors['form_data.owner_zip'] === ['Enter a 4-digit ZIP code.']);
});

it('saves a sheet whose answers are all in shape', function () {
    $app = ruledSheetApp(['SANITARY', 'OCCUPANCY']);
    $owner = authAs('owner@biztrack.local');

    $this->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
            'form_data' => [
                'water_source' => 'Bottled / Refill',
                'toilets_count' => '0',
                'seating_capacity' => '40',
                'toilet_type' => 'Water-sealed flush',
                'toilets_separate_sexes' => 'Yes',
                'sewage_disposal' => 'Public sewer (Maynilad)',
                'solid_waste_disposal' => 'Composting / recycling',
                'pest_control' => 'None',
                'operating_hours' => '8:00 AM – 10:00 PM',
                'has_kitchen' => '',
            ],
        ])
        ->assertOk();

    $this->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => [
                'application_type' => 'Partial',
                'building_permit_no' => 'BP-2026-0001',
                'owner_zip' => '1470',
                'owner_tel' => '(02) 8281-4712',
                'owner_ctc_no' => '00123456',
                'owner_ctc_date' => now()->toDateString(),
                'building_storeys' => '2',
                'building_units' => '1',
                'total_floor_area_sqm' => '120.5',
                'completion_submitted_by' => 'Engr. Paolo R. Mendiola',
                'completion_professional' => 'Civil Engineer',
            ],
        ])
        ->assertOk();

    expect(ruledSheet($app, 'OCCUPANCY'))->toMatchArray(['owner_zip' => '1470', 'owner_ctc_no' => '00123456']);
});

it('does not judge an answer the office left as the applicant gave it', function () {
    /*
     * The review page saves the whole sheet back. A ZIP code stored before
     * these rules ("abcd") must not stop OBO recording an issuance date; the
     * office is held to the rules only on what it changes.
     */
    $app = ruledSheetApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::Approved, now()->subDay());
    ApplicationOfficeForm::create([
        'application_id' => $app->id,
        'permit_type_id' => PermitType::where('code', 'OCCUPANCY')->value('id'),
        'form_data' => ['building_permit_no' => 'BP-001', 'owner_zip' => 'abcd', 'owner_tel' => '8123-456'],
    ]);
    ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => Department::where('code', 'OBO')->value('id'),
        'status' => 'pending',
        'officer_user_id' => User::where('email', 'obo@biztrack.local')->value('id'),
        'assigned_at' => now(),
    ]);
    $obo = authAs('obo@biztrack.local');

    $this->withHeaders($obo)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'BP-001', 'owner_zip' => 'abcd', 'building_permit_date' => '2026-01-05'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.building_permit_date', '2026-01-05');

    // What the office does change is held to the rule.
    $this->withHeaders($obo)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'BP-001', 'owner_tel' => 'ask the guard'],
            'reason' => 'Owner phoned in a new number.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['form_data.owner_tel']);
});

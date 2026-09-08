<?php

use App\Models\Barangay;
use App\Models\BusinessLine;
use App\Models\PermitType;
use App\Models\PsicCode;

/**
 * Where the declared capital per line of business ends up.
 *
 * It used to be asked twice: against each line in Location & Zoning, which is
 * what wrote `business_lines.capitalization`, and again in Business & Tax
 * Profile, which is what the calculator actually assessed. The two answers had
 * no way of staying equal and only one of them was ever charged on. The wizard
 * asks once now, on the fee profile — so the business record has to be filled
 * from there, and it has to survive the autosave that rewrites the lines a
 * moment before the profile arrives.
 *
 * These are the two halves of that: the figure lands, and nothing on the way
 * past knocks it off again.
 */

/** The wizard's own sequence, minus the capital it no longer sends up front. */
function fileWithCapital(array $headers, float $capital): array
{
    $psic = PsicCode::where('code', '!=', '00000')->firstOrFail();

    $businessId = test()->withHeaders($headers)->postJson('/api/v1/businesses', [
        'name' => 'Aling Rosa Carinderia',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-2026-4410',
        'tin' => '123-456-789-000',
        'is_rented' => false,
        'address' => ['line1' => '9 Gov. Pascual Ave', 'barangay_id' => Barangay::first()->id],
        // No capitalization: the wizard stopped sending it with the business.
        'lines' => [['psic_code_id' => $psic->id]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($headers)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->firstOrFail()->id],
    ])->assertCreated()->json('data.id');

    test()->withHeaders($headers)->putJson("/api/v1/applications/{$appId}", [
        'fee_profile' => [
            'business_structure' => 'sole_proprietorship',
            'lines' => [[
                'psic_code_id' => $psic->id,
                'category' => 'carinderia',
                'capitalization' => $capital,
            ]],
            'floor_area_sqm' => 30,
            'employees' => 2,
        ],
    ])->assertOk();

    return ['business_id' => $businessId, 'application_id' => $appId, 'psic_code_id' => $psic->id];
}

it('lands the capital declared on the fee profile in business_lines', function () {
    $owner = authAs('owner@biztrack.local');
    $ids = fileWithCapital($owner, 425000);

    $this->withHeaders($owner)
        ->postJson("/api/v1/applications/{$ids['application_id']}/submit")
        ->assertOk();

    $line = BusinessLine::where('business_id', $ids['business_id'])->firstOrFail();
    expect((float) $line->capitalization)->toBe(425000.0);
});

it('keeps that capital when a later business update does not restate it', function () {
    /*
     * The failure this pins is a race the wizard runs on every keystroke:
     * autosave writes the business (which replaces every line) and then writes
     * the fee profile. If the first of those treated a missing capitalization as
     * a blank, the pair would take turns setting and clearing the figure, and
     * whichever one lost the last round would decide what BPLO saw.
     */
    $owner = authAs('owner@biztrack.local');
    $ids = fileWithCapital($owner, 425000);

    $this->withHeaders($owner)->putJson("/api/v1/businesses/{$ids['business_id']}", [
        'name' => 'Aling Rosa Carinderia & Catering',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-2026-4410',
        'tin' => '123-456-789-000',
        'is_rented' => false,
        'address' => ['line1' => '9 Gov. Pascual Ave', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => $ids['psic_code_id']]],
    ])->assertOk();

    $line = BusinessLine::where('business_id', $ids['business_id'])->firstOrFail();
    expect((float) $line->capitalization)->toBe(425000.0);
});

it('still lets a capital sent with the business win', function () {
    /*
     * Omission means "unchanged", not "always keep the old one". A caller that
     * states a figure — the seeders, the e2e fixtures, any client older than
     * this change — must still be able to correct it.
     */
    $owner = authAs('owner@biztrack.local');
    $ids = fileWithCapital($owner, 425000);

    $this->withHeaders($owner)->putJson("/api/v1/businesses/{$ids['business_id']}", [
        'name' => 'Aling Rosa Carinderia',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-2026-4410',
        'tin' => '123-456-789-000',
        'is_rented' => false,
        'address' => ['line1' => '9 Gov. Pascual Ave', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => $ids['psic_code_id'], 'capitalization' => 90000]],
    ])->assertOk();

    $line = BusinessLine::where('business_id', $ids['business_id'])->firstOrFail();
    expect((float) $line->capitalization)->toBe(90000.0);
});

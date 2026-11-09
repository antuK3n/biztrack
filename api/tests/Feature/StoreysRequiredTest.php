<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\StoreysRequired;

/*
 * No. of Storeys, refused at submit when blank (client, 10 November 2026:
 * "ask once, lock everywhere", then "screen + server"). Business Operation
 * requires it and the CPDD, BFP and OBO sheets show it locked, so a filing
 * cannot reach the offices without it. Never at autosave; never asked of an
 * amendment or a clearance-only renewal, which do not see Business Operation.
 */

/** A draft for owner@ on a new business, carrying `$codes`, documents attached. */
function storeysDraft(string $type = 'new', array $codes = ['BUSINESS']): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Storeys Gate '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Storey Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $id = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => $type,
        'permit_type_ids' => PermitType::whereIn('code', $codes)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    // The documents, then the storey count blanked again: the fixture
    // helper fills it in, and here it must be missing.
    $app = attachRequiredDocuments($id);
    $app->update(['fee_profile' => ['floor_area_sqm' => 40]]);

    return $app->fresh();
}

it('refuses to submit a Business Permit filing with no storey count', function () {
    $app = storeysDraft();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}", ['fee_profile' => ['floor_area_sqm' => 40]])
        // A draft still autosaves without it.
        ->assertOk();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/submit")
        ->assertStatus(422)
        ->assertJsonPath('errors', fn ($errors) => $errors['fee_profile.storeys'] === [StoreysRequired::MESSAGE]);

    expect($app->fresh()->status->value)->toBe('draft');
});

it('submits once the storey count is stated', function () {
    $app = storeysDraft();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}", ['fee_profile' => ['floor_area_sqm' => 40, 'storeys' => 2]])
        ->assertOk();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/submit")
        ->assertOk();
});

it('asks only new and renewal filings that carry the Business Permit', function () {
    $new = storeysDraft();
    expect(StoreysRequired::missing($new))->toBeTrue();

    $new->update(['application_type' => 'amendment']);
    expect(StoreysRequired::missing($new->fresh()))->toBeFalse();

    // A clearance-only renewal: the predicate, not a whole renewal chain.
    $renewal = storeysDraft();
    $renewal->update(['application_type' => 'renewal']);
    expect(StoreysRequired::missing($renewal->fresh()))->toBeTrue();
    $renewal->permitTypes()->sync(PermitType::where('code', 'SANITARY')->pluck('id'));
    expect(StoreysRequired::missing($renewal->fresh()))->toBeFalse();

    $zero = storeysDraft();
    $zero->update(['fee_profile' => ['storeys' => 0]]);
    expect(StoreysRequired::missing($zero->fresh()))->toBeTrue();
});

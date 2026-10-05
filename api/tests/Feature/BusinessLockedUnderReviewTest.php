<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\WorkflowService;

/*
 * The owner cannot rename the business, or change its line of business,
 * under a filing the offices are reading [Ken, 5 October 2026].
 *
 * PUT /businesses/{id} checked ownership only, so a business was renamed and
 * moved to another line while BPLO read the filing, and again after payment
 * while the offices held it. The filing BPLO approved and billed was not the
 * business the register then held. Once the filing is returned, the owner can
 * change both again: that is the correction path.
 */

/** The owner's own payload for this business, with any answers changed. */
function lockedPayload(Business $business, array $overrides = []): array
{
    $business->loadMissing(['address', 'lines']);

    return array_merge([
        'name' => $business->name,
        'registration_type' => $business->registration_type,
        'registration_number' => $business->registration_number,
        'tin' => $business->tin,
        'address' => ['line1' => $business->address->line1, 'barangay_id' => $business->address->barangay_id],
        'lines' => $business->lines->map(fn ($l) => [
            'psic_code_id' => $l->psic_code_id,
            'capitalization' => $l->capitalization,
        ])->all(),
    ], $overrides);
}

/** A filing of owner@ at For Approval. */
function lockedFiling(): Application
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Locked Store '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '7 Locked Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return Application::findOrFail($appId);
}

const LOCKED_SENTENCE = 'You can change this once the filing is returned to you.';

it('refuses a rename or a new line of business while BPLO reads the filing', function () {
    $app = lockedFiling();
    $business = $app->business;
    $otherLine = PsicCode::whereKeyNot($business->lines()->value('psic_code_id'))->firstOrFail();

    test()->putJson("/api/v1/businesses/{$business->id}", lockedPayload($business, ['name' => 'Renamed Under Review']))
        ->assertStatus(422)->assertJsonPath('message', LOCKED_SENTENCE);
    test()->putJson("/api/v1/businesses/{$business->id}", lockedPayload($business, [
        'lines' => [['psic_code_id' => $otherLine->id, 'capitalization' => 100000]],
    ]))->assertStatus(422)->assertJsonPath('message', LOCKED_SENTENCE);

    expect($business->fresh()->name)->not->toBe('Renamed Under Review');
});

it('refuses a rename after payment, while the offices hold the filing', function () {
    $app = lockedFiling();
    bploApprovesForm($app);
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])->assertCreated();

    test()->putJson("/api/v1/businesses/{$app->business_id}", lockedPayload($app->business, ['name' => 'Renamed After Payment']))
        ->assertStatus(422)->assertJsonPath('message', LOCKED_SENTENCE);
});

it('still saves the unchanged business under review, and a rename once it is returned', function () {
    $app = lockedFiling();
    $business = $app->business;

    // What every autosave sends: the same name and lines.
    test()->putJson("/api/v1/businesses/{$business->id}", lockedPayload($business))->assertOk();

    app(WorkflowService::class)->returnMainForm($app->fresh(), 'Use the registered name.', 'form:name');
    authAs('owner@biztrack.local');
    test()->putJson("/api/v1/businesses/{$business->id}", lockedPayload($business->fresh(), ['name' => 'Corrected Name']))
        ->assertOk();

    expect($business->fresh()->name)->toBe('Corrected Name');
});

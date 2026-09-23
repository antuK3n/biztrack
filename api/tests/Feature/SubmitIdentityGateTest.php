<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * Trade Name and TIN at submit (client, 23 September 2026).
 *
 * Trade Name / Franchise is required on every filing. The TIN is optional for a
 * NEW business, which may not have registered with BIR yet, and required on a
 * renewal or amendment. Both are checked at submit rather than on the business
 * write, because drafts save half-answered — see ApplicationController::submit.
 */

/** A consented draft for a fresh business, with the given identity fields. */
function identityDraft(array $business): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', array_replace([
        'name' => 'Identity Gate '.random_int(10000, 99999),
        'trade_name' => 'Identity Gate',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '3 Gate Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ], $business))->assertCreated()->json('data.id');

    return test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');
}

it('refuses to submit a filing whose business has no trade name', function () {
    $appId = identityDraft(['trade_name' => '']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors('trade_name');
});

it('submits a new filing with no TIN, because a new business may not have one yet', function () {
    $appId = identityDraft(['tin' => '']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertOk();
});

it('refuses to submit a renewal whose business has no TIN', function () {
    $appId = identityDraft(['tin' => '']);
    $app = Application::findOrFail($appId);

    /*
     * Made a renewal directly rather than through the identify flow: the
     * prior-permit gate runs first and wants a permit on this business, and
     * that is not what this test is about. A permit row from the seeded
     * register, re-pointed at this business, satisfies it.
     */
    $permit = Permit::query()->firstOrFail();
    $permit->forceFill(['business_id' => $app->business_id])->save();
    $app->forceFill(['application_type' => 'renewal', 'prior_permit_id' => $permit->id])->save();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors('tin');

    Business::whereKey($app->business_id)->update(['tin' => '123-456-789-000']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertJsonMissingValidationErrors('tin');
});

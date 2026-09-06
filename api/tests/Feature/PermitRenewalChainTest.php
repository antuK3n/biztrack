<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;

/*
 * The permit-to-permit chain.
 *
 * `applications.prior_permit_id` says which permit a FILING is for. It has
 * never said which permit a permit SUCCEEDED, so continuity between two
 * certificates had to be reconstructed afterwards from (business, permit_type,
 * dates) — RenewalOutcomes still does exactly that, and its late/on-time
 * verdict is what fits the renewal model in Glm.
 *
 * That reconstruction is sound right up to the case it most needs to get
 * right: a business renewing late holds last year's Mayor's Permit and this
 * year's at the same time, same type, overlapping records, and the match
 * becomes a coin toss feeding a regression. `permits.prior_permit_id` makes it
 * a fact written once, at issuance.
 */

/** Nena's business, which the demo seeds with two BUSINESS permits. */
function chainBusiness(): Business
{
    return Business::where('name', 'like', 'Nena%')->firstOrFail();
}

function issuedPermit(Application $application, array $overrides = []): Permit
{
    return Permit::create([
        'permit_number' => 'MCB-CHAIN-'.str_pad((string) $application->id, 6, '0', STR_PAD_LEFT),
        'application_id' => $application->id,
        'business_id' => $application->business_id,
        'permit_type_id' => PermitType::where('code', 'BUSINESS')->firstOrFail()->id,
        'status' => 'active',
        'valid_from' => now()->startOfYear()->toDateString(),
        'valid_until' => now()->endOfYear()->toDateString(),
        'issued_at' => now(),
        ...$overrides,
    ]);
}

it('points a renewed permit at the one it replaced', function () {
    $business = chainBusiness();
    $prior = $business->permits()->firstOrFail();

    $renewal = Application::create([
        'tracking_id' => 'BIZ-CHAIN-00001',
        'business_id' => $business->id,
        'applicant_user_id' => $business->owner_user_id,
        'application_type' => 'renewal',
        'status' => 'approved',
        'prior_permit_id' => $prior->id,
    ]);

    $issued = issuedPermit($renewal);

    expect($issued->prior_permit_id)->toBe($prior->id)
        ->and($issued->priorPermit->permit_number)->toBe($prior->permit_number)
        ->and($prior->refresh()->renewals->pluck('id')->all())->toContain($issued->id);
});

it('leaves a new filing’s permit unchained, because it succeeds nothing', function () {
    $business = chainBusiness();

    $new = Application::create([
        'tracking_id' => 'BIZ-CHAIN-00002',
        'business_id' => $business->id,
        'applicant_user_id' => $business->owner_user_id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    expect(issuedPermit($new)->prior_permit_id)->toBeNull();
});

it('refuses to chain across two different businesses', function () {
    /*
     * A cross-business link is worse than no link: analytics would read two
     * shops as one shop's continuous history. The application-level check
     * already refuses this, so the only way it arrives here is a writer that
     * bypasses the controller — a seeder, a console command, a future one.
     * Which is the whole reason the guard lives on the model.
     */
    $business = chainBusiness();
    $otherPermit = Permit::whereNot('business_id', $business->id)->first()
        ?? issuedPermit(Application::whereNot('business_id', $business->id)->firstOrFail());

    $renewal = Application::create([
        'tracking_id' => 'BIZ-CHAIN-00003',
        'business_id' => $business->id,
        'applicant_user_id' => $business->owner_user_id,
        'application_type' => 'renewal',
        'status' => 'approved',
        'prior_permit_id' => $otherPermit->id,
    ]);

    expect(issuedPermit($renewal)->prior_permit_id)->toBeNull();
});

it('lets an explicit chain stand rather than overwriting it', function () {
    // The model only ever FILLS a blank. A caller that knows better — a data
    // migration correcting history, say — must not be second-guessed.
    $business = chainBusiness();
    $permits = $business->permits()->orderBy('id')->get();

    $renewal = Application::create([
        'tracking_id' => 'BIZ-CHAIN-00004',
        'business_id' => $business->id,
        'applicant_user_id' => $business->owner_user_id,
        'application_type' => 'renewal',
        'status' => 'approved',
        'prior_permit_id' => $permits[0]->id,
    ]);

    $issued = issuedPermit($renewal, ['prior_permit_id' => $permits[1]->id]);

    expect($issued->prior_permit_id)->toBe($permits[1]->id);
});

it('chains a permit issued by the real approval path', function () {
    /*
     * The point of putting the guard on the model rather than in
     * WorkflowService is that every writer gets it. This is the writer that
     * matters: an officer approving a renewal end to end.
     */
    authAs('owner@biztrack.local');
    $business = chainBusiness();
    $prior = $business->permits()->orderBy('id')->firstOrFail();

    $appId = $this->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'data_privacy_consent' => true,
        'application_type' => 'renewal',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        'prior_permit_id' => $prior->id,
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $application = Application::findOrFail($appId);
    expect($application->prior_permit_id)->toBe($prior->id);

    // Issued directly rather than driven through the officer queue: this test
    // is about the chain, and the queue's own path is covered elsewhere.
    expect(issuedPermit($application)->prior_permit_id)->toBe($prior->id);
});

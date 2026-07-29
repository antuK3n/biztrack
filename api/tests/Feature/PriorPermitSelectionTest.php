<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;

/*
 * Item 50 — "there should be an option on what specific business permit to
 * renew, just like in the official government website."
 *
 * A business can hold a Mayor's Permit expiring in January and a sanitary
 * permit expiring in June. Renewal used to pick the business and carry
 * whatever it happened to hold, so the choice was never made and never
 * recorded. applications.prior_permit_id already existed; it had no way of
 * being read back or changed once the draft was created.
 */

/** A fresh renewal draft on Nena's business, which holds two seeded permits. */
function renewalDraft(?int $priorPermitId = null): array
{
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    $appId = test()->postJson('/api/v1/applications', array_filter([
        'business_id' => $business->id,
        'application_type' => 'renewal',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        'prior_permit_id' => $priorPermitId,
    ]))->assertCreated()->json('data.id');

    return ['application_id' => $appId, 'business' => $business];
}

it('reads back the permit a renewal was started for', function () {
    ['business' => $business] = renewalDraft();
    $permit = $business->permits()->orderBy('valid_until')->firstOrFail();
    ['application_id' => $appId] = renewalDraft($permit->id);

    $data = $this->getJson("/api/v1/applications/{$appId}/prior-permit")->assertOk()->json('data');

    expect($data['prior_permit_id'])->toBe($permit->id)
        ->and($data['prior_permit']['permit_number'])->toBe($permit->permit_number)
        ->and($data['prior_permit']['permit_type']['code'])->toBe('BUSINESS');
});

it('lets the applicant change which permit is being renewed', function () {
    ['application_id' => $appId, 'business' => $business] = renewalDraft();
    $permits = $business->permits()->orderBy('valid_until')->get();
    expect($permits)->toHaveCount(2);

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => $permits[0]->id,
    ])->assertOk()->assertJsonPath('data.prior_permit_id', $permits[0]->id);

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => $permits[1]->id,
    ])->assertOk()->assertJsonPath('data.prior_permit_id', $permits[1]->id);

    expect(Application::findOrFail($appId)->prior_permit_id)->toBe($permits[1]->id);
});

it('accepts "none of these" for a business whose permits predate the system', function () {
    ['business' => $business] = renewalDraft();
    ['application_id' => $appId] = renewalDraft($business->permits()->firstOrFail()->id);

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => null,
    ])->assertOk()->assertJsonPath('data.prior_permit_id', null);

    expect(Application::findOrFail($appId)->prior_permit_id)->toBeNull();
});

it('refuses a permit that belongs to a different business', function () {
    // A permit issued to somebody else's business entirely (RxCare Pharmacy).
    $foreignApp = Application::whereHas('business', fn ($b) => $b->where('name', 'RxCare Pharmacy'))
        ->firstOrFail();
    $foreign = Permit::create([
        'permit_number' => 'MCB-OTHER-0001',
        'application_id' => $foreignApp->id,
        'business_id' => $foreignApp->business_id,
        'permit_type_id' => PermitType::where('code', 'BUSINESS')->firstOrFail()->id,
        'status' => 'active',
        'valid_from' => now()->subYear()->toDateString(),
        'valid_until' => now()->addMonth()->toDateString(),
        'issued_at' => now()->subYear(),
    ]);

    ['application_id' => $appId] = renewalDraft();

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => $foreign->id,
    ])->assertStatus(422);

    expect(Application::findOrFail($appId)->prior_permit_id)->toBeNull();
});

it('will not let one applicant set the prior permit on another applicant’s draft', function () {
    ['application_id' => $appId, 'business' => $business] = renewalDraft();
    $permitId = $business->permits()->firstOrFail()->id;

    authAs('juan@biztrack.local');
    $this->getJson("/api/v1/applications/{$appId}/prior-permit")->assertForbidden();
    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => $permitId,
    ])->assertForbidden();

    expect(Application::findOrFail($appId)->prior_permit_id)->toBeNull();
});

it('will not change the prior permit once the application has been submitted', function () {
    ['application_id' => $appId, 'business' => $business] = renewalDraft();
    $permitId = $business->permits()->firstOrFail()->id;

    Application::findOrFail($appId)->update(['status' => 'submitted']);

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => $permitId,
    ])->assertStatus(422);
});

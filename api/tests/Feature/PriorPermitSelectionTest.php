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
        'declared_none' => true,
    ])->assertOk()
        ->assertJsonPath('data.prior_permit_id', null)
        ->assertJsonPath('data.declared_none', true);

    expect(Application::findOrFail($appId)->prior_permit_id)->toBeNull();
});

/*
 * ── The seven renewals of nothing ────────────────────────────────────────────
 *
 * 749 of 756 renewals in the register name the permit they renew. The seven
 * that do not are all the same failure wearing three costumes: five drafts on
 * businesses holding no permit at all, one on a business holding three where
 * the question was skipped, one written straight in by DemoSeeder. None of them
 * was ever REFUSED, because a null prior permit was accepted as an answer
 * without anybody having to give it.
 *
 * The escape stays — year one is mostly renewals of paper permits — but it has
 * to be taken rather than fallen into.
 */

it('refuses to submit a renewal that names no permit and does not say why', function () {
    ['application_id' => $appId] = renewalDraft();

    $this->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors('prior_permit_id');

    expect(Application::findOrFail($appId)->status->value)->toBe('draft');
});

it('refuses to submit an amendment that names no permit either', function () {
    // An amendment alters one permit's record. Which one is the same question.
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    $appId = $this->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'application_type' => 'amendment',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        'amendment_location' => true,
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors('prior_permit_id');
});

it('submits a renewal that names its permit', function () {
    ['business' => $business] = renewalDraft();
    ['application_id' => $appId] = renewalDraft($business->permits()->firstOrFail()->id);

    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
});

it('submits a renewal of a paper permit once the applicant says so out loud', function () {
    ['application_id' => $appId] = renewalDraft();

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => null,
        'declared_none' => true,
    ])->assertOk();

    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
});

it('drops the "no permit" declaration the moment a permit is named', function () {
    // Two contradictory statements, not two answers. The named permit wins, so
    // the submit gate can never pass on a row asserting both.
    ['application_id' => $appId, 'business' => $business] = renewalDraft();

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => null,
        'declared_none' => true,
    ])->assertOk();

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => $business->permits()->firstOrFail()->id,
        'declared_none' => true,
    ])->assertOk()->assertJsonPath('data.declared_none', false);

    expect(Application::findOrFail($appId)->prior_permit_declared_none)->toBeFalse();
});

it('reopens the question when a named permit is cleared without an explanation', function () {
    // Clearing the choice is not the same as declaring there is nothing to
    // choose. Left as an open question, this draft cannot submit.
    ['business' => $business] = renewalDraft();
    ['application_id' => $appId] = renewalDraft($business->permits()->firstOrFail()->id);

    $this->putJson("/api/v1/applications/{$appId}/prior-permit", [
        'prior_permit_id' => null,
    ])->assertOk()->assertJsonPath('data.declared_none', false);

    $this->postJson("/api/v1/applications/{$appId}/submit")->assertStatus(422);
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

/*
 * Item 85 — "renewal needs to ask ... to know which certain permit to renew".
 *
 * The picker above could only offer what the browser had already fetched from
 * GET /permits, the owner's whole paginated portfolio, filtered client-side.
 * That is the wrong source twice over: the permit being renewed can sit on page
 * two and never be offered at all, and whether a permit may be renewed is not
 * the browser's judgment to make. The prefill answers for one business now.
 */

it('lists the business’s renewable permits on the prefill, soonest to expire first', function () {
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    $data = $this->getJson("/api/v1/businesses/{$business->id}/prefill?type=renewal")
        ->assertOk()
        ->json('data.renewable_permits');

    expect($data)->toHaveCount($business->permits()->count());

    // Permit number, type and validity — enough for the choice to be unambiguous.
    expect($data[0])->toHaveKeys(['id', 'permit_number', 'permit_type', 'valid_from', 'valid_until']);

    $expiries = array_column($data, 'valid_until');
    $sorted = $expiries;
    sort($sorted);
    expect($expiries)->toBe($sorted);
});

it('offers an expired permit — a lapsed permit is exactly what gets renewed', function () {
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    $permit = $business->permits()->firstOrFail();
    $permit->update(['status' => 'expired', 'valid_until' => now()->subMonths(2)->toDateString()]);

    $ids = collect(
        $this->getJson("/api/v1/businesses/{$business->id}/prefill?type=renewal")
            ->assertOk()
            ->json('data.renewable_permits')
    )->pluck('id');

    expect($ids)->toContain($permit->id);
});

it('withholds revoked and suspended permits, which are appealed and not renewed', function () {
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    $permits = $business->permits()->orderBy('id')->get();
    expect($permits)->toHaveCount(2);
    $permits[0]->update(['status' => 'revoked']);
    $permits[1]->update(['status' => 'suspended']);

    $offered = $this->getJson("/api/v1/businesses/{$business->id}/prefill?type=renewal")
        ->assertOk()
        ->json('data.renewable_permits');

    expect($offered)->toBe([]);
});

it('does not offer another business’s permits', function () {
    // Two owners' permits used to reach the same client-side filter; the scope
    // is the query's now, so a mistake there is a 403, not a mis-filter.
    authAs('owner@biztrack.local');
    $business = Business::where('name', 'like', 'Nena%')->firstOrFail();

    $offered = collect(
        $this->getJson("/api/v1/businesses/{$business->id}/prefill?type=renewal")
            ->assertOk()
            ->json('data.renewable_permits')
    );

    expect($offered)->not->toBeEmpty()
        ->and($offered->pluck('business.id')->unique()->all())->toBe([$business->id]);
});

it('links the chosen permit to the filing, which renewal-compliance analytics count on', function () {
    // RenewalRiskAnalytics reads applications.prior_permit_id; a null there is
    // not a neutral omission, it quietly understates the renewal figures.
    ['business' => $business] = renewalDraft();
    $permit = $business->permits()->orderBy('valid_until')->firstOrFail();

    ['application_id' => $appId] = renewalDraft($permit->id);

    expect(Application::findOrFail($appId)->prior_permit_id)->toBe($permit->id);
});

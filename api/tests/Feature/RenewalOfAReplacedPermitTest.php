<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\RenewalWindow;

/*
 * A permit is renewed once, by the business's current certificate.
 *
 * Browser testing, 5 October 2026, found two holes in the same place:
 * `RenewalWindow::refusalFor` asked WHEN a permit could be renewed and never
 * WHETHER it was still the one to renew. So a certificate already replaced
 * by a renewal was offered and accepted again, and one permit could be
 * renewed by two filings in flight at the same time.
 *
 * A DRAFT naming the permit does not block: drafts are abandoned all the
 * time, and a forgotten one must not hold a permit hostage.
 */

/** A business holding one live permit of `$code`, which is returned. */
function replacedPermitFixture(string $code = 'SANITARY'): Permit
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Replaced Permit '.uniqid(),
        'registration_type' => 'DTI',
        'barangay_id' => Barangay::value('id'),
        'address_line' => '5 Chain Street',
        'status' => 'active',
    ]);

    $issuedBy = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    return Permit::create([
        'application_id' => $issuedBy->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'permit_number' => 'CHAIN-'.$code.'-'.uniqid(),
        'issued_at' => now()->subYear(),
        'valid_from' => now()->subYear(),
        'valid_until' => now()->addDays(20),
        'status' => 'active',
    ])->load('permitType', 'business');
}

/** A renewal of `$prior`, in `$status`. */
function renewalNaming(Permit $prior, string $status): Application
{
    $app = Application::create([
        'business_id' => $prior->business_id,
        'applicant_user_id' => $prior->business->owner_user_id,
        'application_type' => 'renewal',
        'status' => $status,
        'prior_permit_id' => $prior->id,
        // submit() mints this; a fixture standing in for a submitted filing has to.
        'tracking_id' => $status === 'draft' ? null : 'BIZ-TEST-'.uniqid(),
    ]);
    $app->priorPermits()->sync([$prior->id]);

    return $app->refresh();
}

it('refuses to renew a permit a renewal has already replaced', function () {
    $prior = replacedPermitFixture();

    // Renewed after it lapsed: the successor names it, its own status stays.
    Permit::create([
        'application_id' => $prior->application_id,
        'business_id' => $prior->business_id,
        'permit_type_id' => $prior->permit_type_id,
        'prior_permit_id' => $prior->id,
        'permit_number' => 'CHAIN-NEXT-'.uniqid(),
        'issued_at' => now(),
        'valid_from' => now(),
        'valid_until' => now()->addYear(),
        'status' => 'active',
    ]);

    expect(RenewalWindow::refusalFor($prior))->toBe('Already replaced by a newer permit.');
});

it('refuses a permit marked superseded', function () {
    $prior = replacedPermitFixture();
    $prior->update(['status' => 'superseded']);

    expect(RenewalWindow::refusalFor($prior->refresh()))->toBe('Already replaced by a newer permit.');
});

it('refuses a second renewal while one naming the permit is submitted, and names it', function () {
    $prior = replacedPermitFixture();
    $first = renewalNaming($prior, 'for_approval');

    expect(RenewalWindow::refusalFor($prior))
        ->toBe("A renewal of this permit is already filed ({$first->tracking_id}).")
        // Never counted against itself.
        ->and(RenewalWindow::refusalFor($prior, null, $first))->toBeNull();
});

it('lets a draft naming the permit go, since drafts are abandoned', function () {
    $prior = replacedPermitFixture();
    renewalNaming($prior, 'draft');

    expect(RenewalWindow::refusalFor($prior))->toBeNull();
});

it('stops counting a renewal once it is decided', function () {
    $prior = replacedPermitFixture();
    renewalNaming($prior, 'rejected');

    expect(RenewalWindow::refusalFor($prior))->toBeNull();
});

it('tells the picker why, through renewal_blocked_reason', function () {
    $prior = replacedPermitFixture();
    $first = renewalNaming($prior, 'for_approval');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/businesses/{$prior->business_id}/prefill")
        ->assertOk()
        ->assertJsonFragment([
            'id' => $prior->id,
            'renewal_blocked_reason' => "A renewal of this permit is already filed ({$first->tracking_id}).",
        ]);
});

it('refuses with 422 at create, when the prior permit is set, and at submit', function () {
    $prior = replacedPermitFixture();
    $headers = authAs('owner@biztrack.local');

    // A draft made while the permit was still free…
    $draft = renewalNaming($prior, 'draft');
    // …then another filing renewing it is submitted.
    renewalNaming($prior, 'for_approval');

    $this->withHeaders($headers)->postJson('/api/v1/applications', [
        'business_id' => $prior->business_id,
        'application_type' => 'renewal',
        'prior_permit_ids' => [$prior->id],
    ])->assertStatus(422)->assertJsonValidationErrors('prior_permit_id');

    $this->withHeaders($headers)->putJson("/api/v1/applications/{$draft->id}/prior-permit", [
        'prior_permit_id' => $prior->id,
        'prior_permit_ids' => [$prior->id],
    ])->assertStatus(422)->assertJsonValidationErrors('prior_permit_id');

    $this->withHeaders($headers)->postJson("/api/v1/applications/{$draft->id}/submit", [
        'data_privacy_consent' => true,
    ])->assertStatus(422);
});

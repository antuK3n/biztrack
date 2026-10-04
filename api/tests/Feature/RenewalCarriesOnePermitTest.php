<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\RenewalScope;

/*
 * A renewal carries the business permit, or one other permit.
 *
 * ── The decision ────────────────────────────────────────────────────────────
 *
 * Client, 3 October 2026: *"since all permits are independent of each other
 * (can be renewed in different applications), do you recommend the picking at
 * the start to allow only one renewal application?"*
 *
 * Narrowed to the case that was actually costing something. A standalone
 * renewal of two clearances at once was the one filing shape that grew a
 * wizard section per permit — CHO's Sanitary Permit and BFP's FSIC share no
 * step, no fee and no office, so one form meant one applicant filling in two
 * unrelated sheets before either office saw anything.
 *
 * The business permit renewal keeps its bundle, and the reason is money. A
 * clearance-only renewal defers its fee to the next January, so splitting
 * "renew my Mayor's Permit and my expiring Sanitary Permit" would move that
 * sanitary fee a year later than the city collects it today. Bundling costs no
 * sections there either: `officeSteps` returns none when the business permit
 * is on the filing.
 *
 * ── Why the rule is tested at the UNIT, and then at both doors ──────────────
 *
 * `RenewalScope` is one predicate and two endpoints ask it — the browser can
 * set a renewal's permits through `ApplicationController` on store, and
 * through `PriorPermitController` when the entry dialog is reopened from a
 * draft. A rule enforced in one of them is one a caller walks around by
 * picking the other, and the browser is not this API's only caller.
 */

/** A business holding a live permit of each code given, keyed by code. */
function scopeBusinessHolding(array $codes): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Renewal Scope '.uniqid(),
        'registration_type' => 'DTI',
        'barangay_id' => Barangay::value('id'),
        'address_line' => '3 Scope Street',
        'status' => 'active',
    ]);

    /* Every certificate was issued BY a filing — `permits.application_id` is
     * not nullable — so the fixture needs one even though nothing reads it. */
    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    $permits = [];
    foreach ($codes as $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $permits[$code] = Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $business->id,
            'permit_type_id' => $type->id,
            'permit_number' => 'SCOPE-'.$code.'-'.uniqid(),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays(20),
            'status' => 'active',
        ]);
    }

    return [$business, $permits];
}

it('allows one other permit on its own', function () {
    [, $permits] = scopeBusinessHolding(['SANITARY']);

    expect(RenewalScope::refusal([$permits['SANITARY']->id]))->toBeNull();
});

it('refuses two other permits on one filing', function () {
    /*
     * The rule. Two clearances with no business permit between them is the
     * shape that grew a wizard section each.
     */
    [, $permits] = scopeBusinessHolding(['SANITARY', 'FSIC']);

    expect(RenewalScope::refusal([$permits['SANITARY']->id, $permits['FSIC']->id]))
        ->toBeString();
});

it('refuses the business permit carrying another permit too', function () {
    /*
     * The exception the January filing used to have is gone (Ken, 5 October
     * 2026): the Mayor's / Business Permit is renewed on its own as well, and
     * each clearance on its own filing.
     */
    [, $permits] = scopeBusinessHolding([PermitType::OUTCOME_CODE, 'SANITARY']);

    expect(RenewalScope::refusal([
        $permits[PermitType::OUTCOME_CODE]->id,
        $permits['SANITARY']->id,
    ]))->toBe('Each permit is renewed on its own application. Choose one here and file the next separately.');
});

it('says nothing about an empty set', function () {
    /*
     * Zero is not a violation and must not cost a query. The paper-permit
     * filing names no prior permit at all and is a business permit renewal
     * with nothing to count.
     */
    expect(RenewalScope::refusal([]))->toBeNull();
});

it('names the way out, not just the refusal', function () {
    /*
     * An applicant who ticked two clearances wants both renewed. The answer is
     * two filings, and a message that refuses without saying so is a dead end
     * — which is the standard every other refusal in this flow is held to.
     */
    [, $permits] = scopeBusinessHolding(['SANITARY', 'FSIC']);

    expect(RenewalScope::refusal([$permits['SANITARY']->id, $permits['FSIC']->id]))
        ->toContain('its own application');
});

it('refuses the same set through the prior-permit endpoint', function () {
    /*
     * The second door. The entry dialog reopened from a draft's summary writes
     * here, not through `ApplicationController`, so the rule has to be asked
     * in both places — and this is the one that would have been missed.
     */
    $owner = authAs('owner@biztrack.local');
    [$business, $permits] = scopeBusinessHolding(['SANITARY', 'FSIC']);

    $draft = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => User::where('email', 'owner@biztrack.local')->value('id'),
        'application_type' => 'renewal',
        'status' => 'draft',
    ]);

    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$draft->id}/prior-permit", [
            'prior_permit_id' => $permits['SANITARY']->id,
            'prior_permit_ids' => [$permits['SANITARY']->id, $permits['FSIC']->id],
        ])
        ->assertStatus(422);

    /* And nothing was written on the way to being refused. */
    expect($draft->fresh()->priorPermits()->count())->toBe(0);
});

it('still takes one permit through the prior-permit endpoint', function () {
    /* The inverse, so the test above cannot pass against a broken endpoint. */
    $owner = authAs('owner@biztrack.local');
    [$business, $permits] = scopeBusinessHolding(['SANITARY']);

    $draft = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => User::where('email', 'owner@biztrack.local')->value('id'),
        'application_type' => 'renewal',
        'status' => 'draft',
    ]);

    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$draft->id}/prior-permit", [
            'prior_permit_id' => $permits['SANITARY']->id,
            'prior_permit_ids' => [$permits['SANITARY']->id],
        ])
        ->assertOk();

    expect($draft->fresh()->prior_permit_id)->toBe($permits['SANITARY']->id);
});

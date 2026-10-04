<?php

use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * What a SUSPENDED permit actually does, and what suspending a business does to
 * its permits.
 *
 * ── Two levers that did not speak to each other ──────────────────────────────
 *
 * Client's questions, 24 September 2026: *"If the business permit is tagged
 * 'Suspended', what would happen in our system?"* and *"a business permit can
 * still be suspended when violations or reports happen."*
 *
 * Both were fair, and the answers were thinner than they should have been:
 *
 *  - a suspended PERMIT failed verification and stamped its PDF, and blocked
 *    nothing — the business could start renewing a certificate it was not
 *    currently allowed to trade on;
 *  - a suspended BUSINESS barred filing and left its permits reading Active, so
 *    the printed certificate looked genuine and the QR check at the counter
 *    answered VALID.
 *
 * The second is the one that mattered: the sanction existed everywhere except
 * the one place an inspector looks. This file pins both directions, and the
 * scope the client asked about specifically — a block on one business must not
 * touch the owner's others.
 */

/**
 * A business owned by owner@biztrack.local, holding one issued active permit.
 *
 * Built here rather than borrowed from BusinessOwnerStatusTest: Pest helpers
 * share a global namespace but not a load order, so a helper defined in
 * another file is undefined when this one runs on its own — which it has to,
 * because a 15-minute suite is a bad way to check one rule.
 *
 * The permit is written directly rather than earned through a filing. What is
 * under test is what a SUSPENDED certificate does, and walking a filing to
 * issuance first would make every case here depend on the whole workflow —
 * which PermitReleasedAtPaymentTest already covers end to end.
 */
function businessHoldingPermit(string $name, string $code = PermitType::OUTCOME_CODE): Business
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name.' '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '3 Sanction Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', $code)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    Permit::create([
        'permit_number' => 'TEST-'.random_int(100000, 999999),
        'application_id' => $appId,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => PermitStatus::Active,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);

    return Business::findOrFail($businessId);
}

function setBusinessStatus(Business $business, string $status, string $reason): void
{
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => $status,
            'reason' => $reason,
        ])
        ->assertOk();
}

it('suspends a business’s live permits when the business is suspended', function () {
    $business = businessHoldingPermit('Violation Store');
    $permit = $business->permits()->firstOrFail();

    expect($permit->status)->toBe(PermitStatus::Active);

    setBusinessStatus($business, 'suspended', 'Operating outside permitted hours.');

    /*
     * The certificate, not just the column. This is the whole point of the
     * cascade: `/verify/{number}` reads the permit's status and would have gone
     * on answering VALID for a business the City had just sanctioned.
     */
    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended)
        ->and($permit->fresh()->status->isLive())->toBeFalse();
});

it('does the same on a blacklisting, and nothing at all on a flag', function () {
    /*
     * `flagged` is a watch marker rather than a sanction — `isBlockedFromApplying`
     * ignores it too — and taking a business's certificates away for being
     * watched would be heavier than the status means.
     */
    $flagged = businessHoldingPermit('Watched Store');
    setBusinessStatus($flagged, 'flagged', 'Two complaints this quarter; watching.');
    expect($flagged->permits()->firstOrFail()->status)->toBe(PermitStatus::Active);

    $closed = businessHoldingPermit('Closed Store');
    setBusinessStatus($closed, 'blacklisted', 'Operating without a sanitary permit, twice.');
    expect($closed->permits()->firstOrFail()->status)->toBe(PermitStatus::Suspended);
});

it('brings the permits back when the business is reinstated', function () {
    $business = businessHoldingPermit('Reinstated Store');
    $permit = $business->permits()->firstOrFail();

    setBusinessStatus($business, 'suspended', 'Pending an inspection.');
    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended);

    setBusinessStatus($business, 'active', 'Inspection passed; the finding is closed.');
    expect($permit->fresh()->status)->toBe(PermitStatus::Active);
});

it('does not bring back the permit of a filing BPLO rejected when the business is reinstated', function () {
    /*
     * BPLO rejecting a paid filing suspends its Business Permit, and no
     * clearance on the filing records why. Reinstating the business read
     * "nothing here is refused" as "nothing holds this permit" and revived
     * it (scenario run, permit-suspend-revoke 21).
     */
    $app = Application::findOrFail(scopedAssignmentFiling('Rejected Then Reinstated'));
    $permit = Permit::where('application_id', $app->id)
        ->whereHas('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
        ->firstOrFail();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'False declarations.'])
        ->assertOk();
    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended);

    setBusinessStatus($app->business, 'suspended', 'Investigation.');
    setBusinessStatus($app->business->fresh(), 'active', 'Investigation closed.');

    expect($permit->fresh()->status)->toBe(PermitStatus::Suspended);
});

it('holds only the suspended business, and the owner files for the others', function () {
    /*
     * ---- The scope the client asked about, three times, with two answers ----
     *
     * 27 September: *"Since only that business is suspended, why would other
     * applications be affected as well?"* They were not — `isBlockedFromApplying`
     * is a method on ONE business and nothing read the user.
     *
     * 30 September: *"di accessible dapat maayos muna yung pagka suspend o
     * blacklisted nya"*, and a suspension barred the whole account, 403 in
     * front of every route.
     *
     * 5 October, Ken: *"business suspension should never affect the entirety
     * of the account."* Back to the first answer. The suspended business is
     * refused a new filing by its own check (422 against `business_id`, with
     * `filingBlockReason`), and the owner's other business files as normal.
     * A blacklisting still bars the account; that is about the person.
     */
    $suspended = businessHoldingPermit('Barred Store');
    $healthy = businessHoldingPermit('Untouched Store');

    setBusinessStatus($suspended, 'suspended', 'Under investigation.');

    $owner = authAs('owner@biztrack.local');

    $filing = fn (int $businessId) => [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ];

    test()->withHeaders($owner)
        ->postJson('/api/v1/applications', $filing($suspended->id))
        ->assertStatus(422)
        ->assertJsonPath('errors.business_id.0', $suspended->fresh()->filingBlockReason());

    test()->withHeaders($owner)
        ->postJson('/api/v1/applications', $filing($healthy->id))
        ->assertCreated();

    // The account itself is not restricted.
    test()->withHeaders($owner)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.restriction', null);
});

it('bars a filing on a suspended PERMIT, and says what would settle it', function () {
    /*
     * The other cause, and the message is the subject. An owner whose permit is
     * suspended used to be told to contact the LGU about their account — the
     * wrong instruction, since this one is theirs to fix and the route is two
     * screens away.
     */
    $business = businessHoldingPermit('Permit Suspended Store');
    $business->permits()->update(['status' => PermitStatus::Suspended]);

    // The ACCOUNT is untouched; only the certificate is suspended.
    expect($business->fresh()->status)->not->toBe('suspended');

    $refused = test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        ])->assertStatus(422);

    $message = $refused->json('errors.business_id.0');

    expect($message)->toContain('suspended')
        ->and($message)->toContain('Settle the rejected permit')
        // NOT the account wording: that sends them to the LGU for something
        // they can fix themselves.
        ->and($message)->not->toContain('account status');
});

it('still lets the owner read and download a suspended permit', function () {
    /*
     * ── Deliberately NOT hidden ──────────────────────────────────────────────
     *
     * Client's question: should the owner see the digital permit while it is
     * suspended? Yes, and the reason is the opposite of the intuitive one.
     *
     * The PDF stamps SUSPENDED across its face for any status but Active
     * (`resources/views/pdf/permit.blade.php`), so the served copy is the most
     * honest artefact in the system. Withholding it does not un-issue anything
     * — it just leaves the CLEAN copy the owner downloaded last week as the
     * only one in circulation, which is the document you least want them
     * showing at a counter.
     *
     * So both stay open, and this is the test that stops a later "hide
     * suspended permits" tidy-up from quietly doing that damage.
     */
    $business = businessHoldingPermit('Readable Store');
    $permit = $business->permits()->firstOrFail();
    $permit->update(['status' => PermitStatus::Suspended]);

    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->getJson("/api/v1/permits/{$permit->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended')
        // The label rides along so the screen never has to name a status it has
        // not been taught.
        ->assertJsonPath('data.status_label', 'Suspended');

    test()->withHeaders($owner)->get("/api/v1/permits/{$permit->id}/pdf")->assertOk();
});

it('tells the public the permit does not verify', function () {
    /*
     * The one consequence that reaches somebody other than the owner, and the
     * reason the cascade above matters: this is what an inspector's phone asks.
     */
    $business = businessHoldingPermit('Unverifiable Store');
    $permit = $business->permits()->firstOrFail();

    test()->getJson("/api/v1/verify/{$permit->permit_number}")
        ->assertOk()
        ->assertJsonPath('data.is_valid', true);

    $permit->update(['status' => PermitStatus::Suspended]);

    test()->getJson("/api/v1/verify/{$permit->permit_number}")
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.status', 'suspended');
});

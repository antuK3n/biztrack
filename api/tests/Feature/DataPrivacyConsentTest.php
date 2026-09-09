<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * RA 10173 consent, kept with the filing it was given for.
 *
 * ── The defect ────────────────────────────────────────────────────────────
 *
 * `applications.data_privacy_consent` has been a column on this table since the
 * schema was aligned with the manuscript, and nothing ever wrote it. The wizard
 * held the tick in React state (`useState(false)`), sent it to no endpoint, and
 * lost it on every reload — so:
 *
 *   - reopening a draft asked again, every time, which is what teaches people
 *     to tick a consent box without reading it; and
 *   - the register held NO EVIDENCE of a consent the system refuses to proceed
 *     without. The tick gated a button and then vanished.
 *
 * The client, on seeing it: "Why is data privacy always asked whenever I reopen
 * the draft? Is it good that its answer is not saved as you exit the draft
 * again?"
 *
 * ── No timestamp, deliberately ────────────────────────────────────────────
 *
 * A `consented_at` column was proposed and dropped. The filing already carries
 * `created_at` and `submitted_at`, and consent is required to submit — so
 * "agreed before we processed it" is already in the record. A second date would
 * restate what two existing columns say.
 */

/** A draft owned by the demo applicant, consent as given. */
function draftWithConsent(bool $consent): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Consent Cafe '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '3 Consent St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');

    return test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        'data_privacy_consent' => $consent,
    ])->assertCreated()->json('data.id');
}

it('stores the consent given when the draft is created', function () {
    $id = draftWithConsent(true);

    expect(Application::findOrFail($id)->data_privacy_consent)->toBeTrue();
});

it('hands the consent back so a reopened draft can put the tick in', function () {
    $id = draftWithConsent(true);

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$id}");

    $res->assertOk();
    // The whole point of the fix: the wizard reads this on hydrate. Without it
    // there is nothing to restore and the applicant is asked again.
    expect($res->json('data.data_privacy_consent'))->toBeTrue();
});

it('lets the applicant take the consent back', function () {
    $id = draftWithConsent(true);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$id}", ['data_privacy_consent' => false])
        ->assertOk();

    /*
     * The reason the controller uses `array_key_exists` rather than `isset`.
     * `false` is a real answer, and `isset` would have skipped the write — the
     * stored `true` would stand, the box would be ticked again next time, and
     * the record would still say they had agreed.
     */
    expect(Application::findOrFail($id)->data_privacy_consent)->toBeFalse();
});

it('refuses to submit an application nobody consented to', function () {
    $id = draftWithConsent(false);

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$id}/submit");

    $res->assertStatus(422);
    expect($res->json('errors.data_privacy_consent.0'))->toContain('Data Privacy Consent');

    // Refused before anything moved: no tracking ID, still a draft.
    $app = Application::findOrFail($id);
    expect($app->status->value)->toBe('draft');
    expect($app->tracking_id)->toBeNull();
});

it('submits once the consent is there', function () {
    $id = draftWithConsent(true);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$id}/submit")
        ->assertOk();

    expect(Application::findOrFail($id)->status->value)->toBe('for_approval');
});

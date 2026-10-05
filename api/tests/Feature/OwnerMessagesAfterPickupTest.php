<?php

use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * "There should be no messaging when there is no officer assigned yet"
 * (checklist 2026-09-27, apply item 23).
 *
 * An owner may message an office about a filing once an officer of THAT
 * office has taken it. Until then the office is not offered, the filing has no
 * inbox row, and a message posted by hand is refused. A conversation the
 * office itself opened stays answerable, and the general enquiry, which has
 * no filing behind it, is untouched.
 */

/** A filed application of owner@, waiting unclaimed in BPLO's queue. */
function pickupFiling(): int
{
    static $n = 0;
    $n++;
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => "Pickup Store {$n}",
        'registration_type' => 'DTI',
        'registration_number' => "DTI-8230{$n}",
        'tin' => '123-456-789-000',
        'address' => ['line1' => "{$n} Pickup St.", 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return $appId;
}

it('refuses an owner’s message to an office nobody there has taken the filing for', function () {
    $appId = pickupFiling();
    authAs('owner@biztrack.local');

    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Is anyone there?'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You can message this office once an officer takes your filing.');

    // Nothing to offer, and no inbox row leading to an empty conversation.
    expect($this->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('meta.offices'))
        ->toBe([])
        ->and(collect($this->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'))
            ->firstWhere('application_id', $appId))->toBeNull();
});

it('lets the owner message an office once an officer there takes the filing', function () {
    $appId = pickupFiling();
    takeFiling($appId, 'BPLO');
    authAs('owner@biztrack.local');

    $offices = collect($this->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('meta.offices'));
    expect($offices->pluck('code')->all())->toBe(['BPLO'])
        ->and($offices->first()['can_message'])->toBeTrue();

    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Thank you for taking it.'])
        ->assertCreated();

    expect(collect($this->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'))
        ->firstWhere('application_id', $appId))->not->toBeNull();

    // Another office still waiting in its queue is not offered.
    $cho = assignOffice($appId, 'CHO');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Health?', 'department_id' => $cho])
        ->assertStatus(422);
});

it('lets the owner answer a conversation the office opened before anyone took the filing', function () {
    $appId = pickupFiling();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Please check your TIN.'])
        ->assertCreated();

    authAs('owner@biztrack.local');
    expect(collect($this->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('meta.offices'))
        ->pluck('code')->all())->toBe(['BPLO']);

    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Corrected, thank you.'])
        ->assertCreated();
});

it('leaves the general enquiry open with no filing behind it', function () {
    pickupFiling();
    authAs('owner@biztrack.local');

    $this->postJson('/api/v1/general-messages', ['body' => 'A question about permits in general.'])
        ->assertCreated();
});

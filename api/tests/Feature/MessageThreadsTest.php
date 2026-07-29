<?php

use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * Item 49: messaging needed its own page, which needs an inbox — one row per
 * conversation, named from the reader's side. Applicants only ever see their
 * own conversations; officers who may read every application see them all,
 * matching the per-thread participant check.
 */

/** A fresh application owned by owner@biztrack.local. */
function ownerApplicationId(): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Thread Test Bakery',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-49001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Thread St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    return test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');
}

it('lists the applicant’s own conversations, newest first', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Is my zoning page enough?'])
        ->assertCreated();

    $rows = $this->getJson('/api/v1/message-threads')->assertOk()->json('data');
    $row = collect($rows)->firstWhere('application_id', $appId);

    expect($row)->not->toBeNull()
        ->and($row['counterparty']['is_officer'])->toBeTrue()
        ->and($row['last_message']['body'])->toBe('Is my zoning page enough?')
        ->and($row['last_message']['mine'])->toBeTrue()
        ->and($row['messages_count'])->toBe(1);

    // Newest conversation first.
    $updated = array_column($rows, 'updated_at');
    $sorted = $updated;
    rsort($sorted);
    expect($updated)->toBe($sorted);
});

it('never shows one applicant the conversations of another', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Private note.'])
        ->assertCreated();

    authAs('juan@biztrack.local');
    $rows = $this->getJson('/api/v1/message-threads')->assertOk()->json('data');

    expect(collect($rows)->pluck('application_id'))->not->toContain($appId)
        ->and(collect($rows)->pluck('last_message.body'))->not->toContain('Private note.');
});

it('names the conversation after the applicant for a reviewing officer', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Good morning.'])
        ->assertCreated();

    authAs('bplo@biztrack.local');
    $rows = $this->getJson('/api/v1/message-threads')->assertOk()->json('data');
    $row = collect($rows)->firstWhere('application_id', $appId);

    expect($row['counterparty']['name'])->toBe('Nena Dela Cruz')
        ->and($row['counterparty']['is_officer'])->toBeFalse()
        ->and($row['counterparty']['subtitle'])->toBe('Thread Test Bakery')
        ->and($row['last_message']['mine'])->toBeFalse();
});

it('offers the applicant a way in before anyone has said anything', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $row = collect($this->getJson('/api/v1/message-threads')->assertOk()->json('data'))
        ->firstWhere('application_id', $appId);

    expect($row)->not->toBeNull()
        ->and($row['last_message'])->toBeNull()
        ->and($row['messages_count'])->toBe(0);
});

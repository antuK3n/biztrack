<?php

use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
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

/*
 * Item 73: "Messages should have something that will determine which admin is
 * responsible for handling your certain applications." `counterparty` answers
 * "who wrote to me last", which is a different question, drifts as different
 * officers reply, and says nothing at all before anybody has written — so the
 * office is now named on its own.
 */
it('names the responsible office on a conversation about a routed filing', function () {
    // Assignments are only created once the fee clears, so a routed filing has
    // to be borrowed from the register rather than built here.
    $assignment = ApplicationAssignment::with('application.applicant')
        ->whereHas('application.applicant')
        ->firstOrFail();
    $appId = $assignment->application_id;

    $this->withHeaders(authAs($assignment->application->applicant->email))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Which office has this?'])
        ->assertCreated();

    $row = collect($this->getJson('/api/v1/message-threads')->assertOk()->json('data'))
        ->firstWhere('application_id', $appId);

    expect($row['responsible_office'])->not->toBeNull()
        // ONE office, never the routing list: a filing with four clearances has
        // four assignments, and printing all of them answers nothing.
        ->and($row['responsible_office'])->toHaveKeys(['code', 'name', 'officer'])
        ->and($row['responsible_office']['name'])->not->toBe('');
});

it('leaves the responsible office null while nothing is routed yet', function () {
    // A filing nobody has been assigned genuinely has no responsible office;
    // naming one would be inventing it.
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Early question.'])
        ->assertCreated();

    $row = collect($this->getJson('/api/v1/message-threads')->assertOk()->json('data'))
        ->firstWhere('application_id', $appId);

    expect($row['responsible_office'])->toBeNull();
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

/*
 * "Messaging (make sure the business owner can only contact the correct
 * offices)". A thread belongs to `(application, department)` now, and these
 * cover the inbox's half of that: the row has to name the offices, or the
 * applicant is back to writing into the void and hoping.
 */

it('names the offices an applicant may talk to on each inbox row', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $row = collect($this->getJson('/api/v1/message-threads')->assertOk()->json('data'))
        ->firstWhere('application_id', $appId);

    /*
     * This test used to assert exactly ['BPLO'], because an office had to hold
     * an assignment before it could be written to and a submitted-but-unpaid
     * filing is routed to nobody. The rule it encoded is no longer true: the
     * client asked for the owner to choose from the offices the system has, so
     * every configured office is offered and BPLO is one of them rather than
     * the only one. See addressableOffices().
     *
     * The reasoning that made BPLO special still holds — an applicant whose
     * filing has not been routed is exactly the applicant with a question — it
     * simply no longer has to carry every other office's mail to get there.
     */
    $codes = collect($row['offices'])->pluck('code');

    expect($codes)->toContain('BPLO')->toContain('CHO')->toContain('BFP')
        ->and($codes)->toHaveCount(Department::count())
        ->and(collect($row['offices'])->every(fn ($o) => $o['can_message']))->toBeTrue()
        // Offered, but nothing said yet: no thread exists until somebody writes.
        ->and(collect($row['offices'])->every(fn ($o) => $o['thread_id'] === null))->toBeTrue()
        ->and(collect($row['offices'])->sum('messages_count'))->toBe(0);
});

it('counts each office’s conversation separately on the inbox row', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'First question.'])
        ->assertCreated();
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Second question.'])
        ->assertCreated();

    $row = collect($this->getJson('/api/v1/message-threads')->assertOk()->json('data'))
        ->firstWhere('application_id', $appId);

    // Unaddressed messages go to BPLO — the same assumption the 520 existing
    // threads were backfilled with, held by MessageThread::booted().
    $bplo = collect($row['offices'])->firstWhere('code', 'BPLO');

    expect($bplo['messages_count'])->toBe(2)
        ->and($bplo['thread_id'])->not->toBeNull()
        ->and($bplo['last_message_at'])->not->toBeNull()
        ->and($row['messages_count'])->toBe(2);
});

it('shows an officer which filing and which office a conversation belongs to', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    // Filed, so it has a tracking number — the officer identifies the filing by
    // that, not by the internal id.
    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Good morning.'])
        ->assertCreated();

    $row = collect(
        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data')
    )->firstWhere('application_id', $appId);

    expect($row['business_name'])->toBe('Thread Test Bakery')
        ->and($row['tracking_id'])->not->toBeNull()
        ->and(collect($row['offices'])->pluck('code'))->toContain('BPLO');
});

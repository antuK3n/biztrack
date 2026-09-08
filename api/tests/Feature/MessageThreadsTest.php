<?php

use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

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

/**
 * A SUBMITTED filing of the owner's, unique per call.
 *
 * ownerApplicationId() reuses one registration number, so two calls in one test
 * collide on the unique index. The inbox filter tests need two filings at once
 * — one spoken on and one silent — which is the whole point of "Not started".
 */
function requirementFilingForInbox(): int
{
    static $n = 0;
    $n++;

    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => "Inbox Filter Store {$n}",
        'registration_type' => 'DTI',
        'registration_number' => "DTI-4930{$n}",
        'tin' => '123-456-789-000',
        'address' => ['line1' => "{$n} Inbox St.", 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    // Filed, so it carries a tracking number and an office can see it at all.
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return $appId;
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

/*
 * The Messages page grew a real Filter — All / Unread / Awaiting their reply —
 * where before it drew an inert "Filter ▽" that no click did anything to. The
 * inbox held no data to answer "unread" with: it could say when a conversation
 * last moved, not whether the reader had seen it. These pin the number the
 * filter reads, because a filter over a field nobody asserts is a filter that
 * silently stops narrowing the day the field goes missing.
 */
it('never counts the reader’s own turns as unread to them', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Is my zoning page enough?'])
        ->assertCreated();

    $row = collect($this->getJson('/api/v1/message-threads')->assertOk()->json('data'))
        ->firstWhere('application_id', $appId);

    // One message on the row, none of it waiting for the person who wrote it.
    expect($row['messages_count'])->toBe(1)
        ->and($row['unread_count'])->toBe(0)
        ->and(collect($row['offices'])->sum('unread_count'))->toBe(0);
});

it('counts what the other side wrote as unread until the conversation is opened', function () {
    $appId = ownerApplicationId();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Good morning.'])
        ->assertCreated();

    // The office has one turn waiting for it, on its own office row.
    $row = collect(
        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data')
    )->firstWhere('application_id', $appId);

    expect($row['unread_count'])->toBe(1)
        ->and(collect($row['offices'])->firstWhere('code', 'BPLO')['unread_count'])->toBe(1);

    // Opening the transcript IS reading it — there is no separate gesture —
    // so the count has to fall, or the filter would keep offering a
    // conversation the officer has just finished reading.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk();

    $after = collect(
        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data')
    )->firstWhere('application_id', $appId);

    expect($after['unread_count'])->toBe(0)
        ->and($after['messages_count'])->toBe(1);
});

it('counts an enquiry’s unread turns the same way a filing’s are counted', function () {
    // A general enquiry has no application, so its row is built by a different
    // path (generalRow) and its count is not covered by the filing tests above.
    authAs('owner@biztrack.local');
    $this->postJson('/api/v1/general-messages', ['body' => 'Do I need a permit for a home bakery?'])
        ->assertCreated();

    $mine = collect($this->getJson('/api/v1/message-threads')->assertOk()->json('data'))
        ->firstWhere('kind', 'general');
    expect($mine['unread_count'])->toBe(0);

    $theirs = collect(
        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data')
    )->firstWhere('kind', 'general');

    expect($theirs['unread_count'])->toBe(1);
});

/*
 * The Filter is a QUERY, over the whole inbox.
 *
 * The inbox is paged at fifty and the Messages page renders one page, so a
 * narrowing done in the browser would search the fifty rows that happened to
 * be downloaded — the identical mistake the officer queue's search made, where
 * a filing one tab away was reported as not existing. These pin the narrowing
 * to SQL by asking for a page of ONE: a client-side filter cannot pass them.
 */
it('narrows the inbox to unread conversations across the whole register', function () {
    $appId = requirementFilingForInbox();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Good morning.'])
        ->assertCreated();

    // The office has one unread. Asked for a single row: only a server-side
    // narrowing can put the matching filing on a page of one.
    $rows = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/message-threads?narrow=unread&per_page=1')
        ->assertOk()->json('data');

    expect(collect($rows)->pluck('application_id'))->toContain($appId)
        ->and(collect($rows)->every(fn ($r) => $r['unread_count'] > 0))->toBeTrue();

    // Reading it takes it out of the answer, without touching any other filter.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk();

    $after = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/message-threads?narrow=unread&per_page=200')
        ->assertOk()->json('data');

    expect(collect($after)->pluck('application_id'))->not->toContain($appId);
});

it('never calls a conversation unread because ANOTHER office has mail on it', function () {
    /*
     * The filter must not leak the one fact readsThread() exists to hide.
     *
     * The filing is deliberately one BOTH offices are in — the fire office has
     * its own conversation on it and so appears in its inbox either way — so
     * the only thing that can put it under the fire office's "Unread" is the
     * health office's mail. An unscoped whereExists does exactly that, and the
     * office learns that City Health said something it may not read.
     */
    $appId = requirementFilingForInbox();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'A question for the health office.',
        'department_id' => Department::where('code', 'CHO')->value('id'),
    ])->assertCreated();
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'A question for the fire office.',
        'department_id' => Department::where('code', 'BFP')->value('id'),
    ])->assertCreated();

    // The fire office reads ITS conversation and has nothing left waiting.
    // City Health has not read theirs.
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk();

    $fire = collect(
        test()->withHeaders(authAs('fire@biztrack.local'))
            ->getJson('/api/v1/message-threads?narrow=unread&per_page=200')
            ->assertOk()->json('data')
    )->pluck('application_id');

    $health = collect(
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson('/api/v1/message-threads?narrow=unread&per_page=200')
            ->assertOk()->json('data')
    )->pluck('application_id');

    expect($health)->toContain($appId)
        ->and($fire)->not->toContain($appId);
});

it('narrows to the conversations where the reader spoke last', function () {
    $appId = requirementFilingForInbox();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Any update?'])
        ->assertCreated();

    // The owner wrote last, so they are the one waiting.
    $waiting = collect(
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson('/api/v1/message-threads?narrow=awaiting&per_page=200')
            ->assertOk()->json('data')
    )->pluck('application_id');
    expect($waiting)->toContain($appId);

    // The office answers. Now the office is waiting and the owner is not —
    // it is the LAST turn that decides, not whether you ever wrote.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Received, thank you.'])
        ->assertCreated();

    $ownerNow = collect(
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson('/api/v1/message-threads?narrow=awaiting&per_page=200')
            ->assertOk()->json('data')
    )->pluck('application_id');
    $officeNow = collect(
        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/message-threads?narrow=awaiting&per_page=200')
            ->assertOk()->json('data')
    )->pluck('application_id');

    expect($ownerNow)->not->toContain($appId)
        ->and($officeNow)->toContain($appId);
});

it('narrows to the filings nobody has written on yet', function () {
    $quiet = requirementFilingForInbox();
    $spoken = requirementFilingForInbox();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$spoken}/messages", ['body' => 'Hello.'])
        ->assertCreated();

    $rows = collect(
        $this->getJson('/api/v1/message-threads?narrow=quiet&per_page=200')
            ->assertOk()->json('data')
    );

    expect($rows->pluck('application_id'))->toContain($quiet)
        ->and($rows->pluck('application_id'))->not->toContain($spoken)
        ->and($rows->every(fn ($r) => $r['messages_count'] === 0))->toBeTrue();
});

it('refuses a narrowing it does not implement rather than ignoring it', function () {
    // Accepting the word and returning everything is how a filter ends up
    // silently doing nothing — which is the defect this whole change is about.
    authAs('owner@biztrack.local');
    $this->getJson('/api/v1/message-threads?narrow=starred')
        ->assertStatus(422)
        ->assertJsonValidationErrors('narrow');
});

/*
 * ── The inbox names a conversation after the OFFICE, never an officer ──────
 *
 * Client instruction: "sa Messages list, office name lang ang ipakita, while
 * the specific officer's name will only appear in the actual chat messages once
 * that officer responds."
 *
 * The old rule named the row after whoever replied last, which drifted with
 * every reply — the same conversation was "Elena Bautista" on Monday and "Liza
 * Reyes" on Thursday — and promised a correspondent the applicant does not
 * have. It is the office that is answerable, and the person is a fact about a
 * message rather than a claim about the conversation.
 */
it('names an applicant’s conversation after the office, whoever replied last', function () {
    $appId = requirementFilingForInbox();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Good morning.'])
        ->assertCreated();

    // An officer replies. Their name must not become the conversation's name.
    $officer = User::where('email', 'bplo@biztrack.local')->value('name');

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Received, thank you.'])
        ->assertCreated();

    $row = collect(
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data')
    )->firstWhere('application_id', $appId);

    $bplo = Department::where('code', 'BPLO')->value('name');

    expect($row['counterparty']['name'])->toBe($bplo)
        ->and($row['counterparty']['name'])->not->toBe($officer)
        // The second line says WHICH filing, not the office again.
        ->and($row['counterparty']['subtitle'])->not->toBe($bplo)
        ->and($row['counterparty']['is_officer'])->toBeTrue();

    /*
     * And the name is not lost — it moves to where it is a fact. Every turn
     * carries its sender and the office that turn was written for, which is
     * what the chat renders as "Juan Dela Cruz · BPLO Officer".
     */
    $turns = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data');

    $reply = collect($turns)->firstWhere('body', 'Received, thank you.');

    expect($reply['sender']['name'])->toBe($officer)
        ->and($reply['sender']['is_officer'])->toBeTrue()
        ->and($reply['department']['code'])->toBe('BPLO');
});

it('counts the enquiries it merges in, so the inbox total is never short', function () {
    /*
     * The Messages page states "Showing N of M", and BPLO's inbox read
     * "Showing 3 of 2": three rows on screen, a total that had never heard of
     * one of them. `pageMeta` counts what the paginator counted — FILINGS —
     * and an enquiry has no filing, so it is merged in afterwards and was
     * being left out of the count it appears in.
     */
    $appId = requirementFilingForInbox();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'About my filing.'])
        ->assertCreated();
    $this->postJson('/api/v1/general-messages', ['body' => 'And a general question.'])
        ->assertCreated();

    $body = $this->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json();

    $rows = collect($body['data']);
    expect($rows->where('kind', 'general'))->not->toBeEmpty()
        // The total has to cover every row it is printed beside. Greater-or-
        // equal, not identical: rows on later pages are counted too.
        ->and($body['meta']['total'])->toBeGreaterThanOrEqual($rows->count());
});

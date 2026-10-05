<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Message;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * The separation rule, stated as tests rather than trusted from comments.
 *
 * Every combination of FILING and OFFICE is its own conversation, and a
 * general enquiry — the one an owner can have before they file anything — is
 * separate from all of them. A message must reach the office it was addressed
 * to and no other, in either direction.
 *
 * These are written from the outside, through the API, because that is where
 * the boundary has failed before: checklist items 56 and 111 were both cases
 * of one door enforcing the rule and another not.
 */

/** A filed application owned by owner@biztrack.local, with a tracking id. */
function separationApplication(string $businessName, string $registrationNumber): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $businessName,
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '1 Separation St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        // RA 10173 consent is the first gate submit() runs; without it the filing
        // never leaves draft and every assertion below is about an empty inbox.
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return [$appId, Application::find($appId)->tracking_id];
}

/** Put an office on a filing so the owner is allowed to address it. */
it('keeps a message to the health office out of an unrelated office inbox', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70001');
    $choId = assignOffice($appId, 'CHO');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Question for the health office only.',
        'department_id' => $choId,
    ])->assertCreated();

    // The health office reads it.
    $cho = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data');
    expect(collect($cho)->pluck('body'))->toContain('Question for the health office only.');

    /*
     * An unrelated office does not — not the row, and not the message behind
     * it. Item 111 is exactly this: hiding the row while still serving the
     * message is not hiding it.
     */
    $fire = test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->json('data') ?? [];

    expect(collect($fire)->pluck('body'))->not->toContain('Question for the health office only.');
});

/*
 * BPLO is not an exception any more.
 *
 * It holds `application.view_any_office` and used to read every office's
 * conversation on every filing, on the reasoning that it coordinates the permit
 * and issues it off the other offices' clearances. The client has since ruled
 * the other way for CORRESPONDENCE specifically: an office sees a conversation
 * only if it is the office that was contacted. Reading a clearance is still
 * BPLO's business; reading the applicant's mail to the health office is not.
 *
 * This is the test that says so. If it ever goes red, the boundary has been
 * widened back.
 */
it('keeps a conversation with one office out of BPLO', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70008');
    $choId = assignOffice($appId, 'CHO');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Question for the health office only.',
        'department_id' => $choId,
    ])->assertCreated();

    $bplo = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->json('data') ?? [];

    expect(collect($bplo)->pluck('body'))->not->toContain('Question for the health office only.');
});

/*
 * The other half of the same rule: a seat that reviews for no office reads no
 * correspondence at all. The super admin has no department, so there is no
 * office whose conversations are theirs.
 *
 * This is a real loss of oversight and is meant: a conversation is between two
 * parties, and "can read everything" is what the client asked to remove.
 */
it('keeps every conversation out of a seat that holds no office', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70009');
    $choId = assignOffice($appId, 'CHO');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Question for the health office only.',
        'department_id' => $choId,
    ])->assertCreated();

    $admin = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->json('data') ?? [];

    expect(collect($admin)->pluck('body'))->not->toContain('Question for the health office only.');
});

/*
 * ---- Which offices a filing offers, and why it stopped being all of them --
 *
 * This asserted that every configured office was offered on every filing, and
 * it was right at the time: the client had asked for exactly that, against an
 * earlier rule that offered only the routed ones.
 *
 * Two instructions on 28 September 2026 settle it the other way, and they only
 * make sense together:
 *
 *   "sa admin offices ang maaccess lang nila once na naka assign na sa kanila
 *    yung application"
 *   "sa business owner side lahat na ng offices may general inquiry"
 *
 * An office's Messages page is its caseload, so an office that is not on this
 * filing will never be shown a message about it in its inbox. Offering that
 * office here would be offering a message that lands outside the one screen
 * that office works from.
 *
 * The owner has not lost the ability to reach it. That is what the general
 * enquiry is for, and every office now has one. What they cannot do is raise it
 * against a PERMIT the office was never handed.
 *
 * Section 5 still holds and is still asserted below: two offices opened at
 * different times stay two conversations.
 */
it('offers the owner the offices that are actually on the filing', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70010');

    authAs('owner@biztrack.local');

    // submit() hands the form to BPLO and to nobody else.
    $offices = $this->getJson("/api/v1/applications/{$appId}/messages")
        ->assertOk()->json('meta.offices');

    expect(collect($offices)->pluck('code'))->toContain('BPLO')
        ->and(collect($offices)->pluck('code'))->not->toContain('CPDO')
        ->and(collect($offices)->every(fn ($o) => $o['can_message']))->toBeTrue();

    // Route two more, and they appear - there is somebody to read them now.
    $cho = assignOffice($appId, 'CHO');
    $bfp = assignOffice($appId, 'BFP');

    $offices = $this->getJson("/api/v1/applications/{$appId}/messages")
        ->assertOk()->json('meta.offices');

    expect(collect($offices)->pluck('code'))
        ->toContain('BPLO')->toContain('CHO')->toContain('BFP')
        ->and(collect($offices)->pluck('code'))->not->toContain('CPDO');

    // Two offices, opened at different times, stay two conversations.
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'For health.', 'department_id' => $cho,
    ])->assertCreated();
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'For fire.', 'department_id' => $bfp,
    ])->assertCreated();

    $healthOnly = $this->getJson("/api/v1/applications/{$appId}/messages?department_id={$cho}")
        ->assertOk()->json('data');

    expect(collect($healthOnly)->pluck('body'))->toContain('For health.')->not->toContain('For fire.');
});

it('keeps an office reply out of the other offices on the same filing', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70002');
    $choId = assignOffice($appId, 'CHO');
    assignOffice($appId, 'BFP');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Health question.',
        'department_id' => $choId,
    ])->assertCreated();

    // The office answers. Section 5: an office initiating or replying is bound
    // by the same rule as the applicant.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Bring the sanitary permit.'])
        ->assertCreated();

    $fire = test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->json('data') ?? [];

    expect(collect($fire)->pluck('body'))
        ->not->toContain('Bring the sanitary permit.')
        ->not->toContain('Health question.');

    // The owner, who is a party to it, sees both turns.
    $owner = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages?department_id={$choId}")
        ->assertOk()->json('data');

    expect(collect($owner)->pluck('body'))
        ->toContain('Health question.')
        ->toContain('Bring the sanitary permit.');
});

/*
 * Opening the panel reads the conversation it lands on, not every one.
 *
 * The panel's first request names no office, and the server marked every
 * thread on the filing read. The panel then settles on BPLO, so the owner's
 * badge dropped for a CHO reply that never reached the screen.
 */
it('reads only the conversation the panel opens on when no office is named', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70011');
    $choId = assignOffice($appId, 'CHO');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'CHO: bring the water test', 'department_id' => $choId])
        ->assertCreated();
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'BPLO: noted'])
        ->assertCreated();

    authAs('owner@biztrack.local');
    $before = test()->getJson('/api/v1/unread-summary')->json('data.messages');
    test()->getJson("/api/v1/applications/{$appId}/messages")->assertOk();

    expect(Message::where('body', 'CHO: bring the water test')->value('read_at'))->toBeNull()
        ->and(Message::where('body', 'BPLO: noted')->value('read_at'))->not->toBeNull()
        ->and($before - test()->getJson('/api/v1/unread-summary')->json('data.messages'))->toBe(1);

    // Naming the office is reading it.
    test()->getJson("/api/v1/applications/{$appId}/messages?department_id={$choId}")->assertOk();
    expect(Message::where('body', 'CHO: bring the water test')->value('read_at'))->not->toBeNull();
});

it('never mixes the conversations of two different filings', function () {
    [$firstId, $firstTracking] = separationApplication('ABC Store', 'DTI-70003');
    [$secondId, $secondTracking] = separationApplication('XYZ Cafe', 'DTI-70004');

    expect($firstTracking)->not->toBe($secondTracking);

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$firstId}/messages", ['body' => 'About ABC Store.'])
        ->assertCreated();
    $this->postJson("/api/v1/applications/{$secondId}/messages", ['body' => 'About XYZ Cafe.'])
        ->assertCreated();

    $first = $this->getJson("/api/v1/applications/{$firstId}/messages")->assertOk()->json('data');
    $second = $this->getJson("/api/v1/applications/{$secondId}/messages")->assertOk()->json('data');

    expect(collect($first)->pluck('body'))->toContain('About ABC Store.')->not->toContain('About XYZ Cafe.');
    expect(collect($second)->pluck('body'))->toContain('About XYZ Cafe.')->not->toContain('About ABC Store.');
});

it('names the business and the filing on each inbox row so two are told apart', function () {
    [$firstId, $firstTracking] = separationApplication('ABC Store', 'DTI-70005');
    [$secondId, $secondTracking] = separationApplication('XYZ Cafe', 'DTI-70006');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$firstId}/messages", ['body' => 'One.'])->assertCreated();
    $this->postJson("/api/v1/applications/{$secondId}/messages", ['body' => 'Two.'])->assertCreated();

    $rows = collect($this->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'));

    $first = $rows->firstWhere('application_id', $firstId);
    $second = $rows->firstWhere('application_id', $secondId);

    // Business name AND tracking id on both, so the two rows are distinguishable
    // even when the business name is the same.
    expect($first['business_name'])->toBe('ABC Store')
        ->and($first['tracking_id'])->toBe($firstTracking)
        ->and($second['business_name'])->toBe('XYZ Cafe')
        ->and($second['tracking_id'])->toBe($secondTracking);
});

it('keeps the general enquiry apart from the filing conversations', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70007');

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'About my filing.'])
        ->assertCreated();
    $this->postJson('/api/v1/general-messages', ['body' => 'Can I change my email?'])
        ->assertCreated();

    // Neither conversation carries the other's turns.
    $filing = $this->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data');
    $general = $this->getJson('/api/v1/general-messages')->assertOk()->json('data');

    expect(collect($filing)->pluck('body'))
        ->toContain('About my filing.')
        ->not->toContain('Can I change my email?');
    expect(collect($general)->pluck('body'))
        ->toContain('Can I change my email?')
        ->not->toContain('About my filing.');

    // And they are two rows in the inbox, one of them carrying no filing.
    $rows = collect($this->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'));

    expect($rows->firstWhere('kind', 'general')['application_id'])->toBeNull()
        ->and($rows->firstWhere('application_id', $appId)['kind'])->toBe('application');
});

/*
 * The office that was written to can read it, even with no assignment.
 *
 * This is the hole that opening addressableOffices tore, found by driving the
 * running app rather than by a test: a message to the health office on a filing
 * routed only to BPLO was accepted with a 201, stored, and then refused to the
 * health office — "You are not a participant in this conversation." The
 * applicant watched it send and nobody could ever read it.
 *
 * Every other test in this file assigns the office first, which is exactly why
 * none of them caught it.
 */
it('lets an office read a filing it was written to, and lists it once routed', function () {
    [$appId] = separationApplication('ABC Store', 'DTI-70011');

    /*
     * Deliberately NO assignment for the office that gets written to.
     *
     * submit() routes BPLO and BPLO alone — it reads the form before the bill is
     * raised — and the other five offices are routed one at a time, when the
     * applicant opens that clearance after paying. So a freshly filed application
     * has exactly one assignment, and City Health is not on it.
     */
    $cho = Department::where('code', 'CHO')->value('id');

    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(1)
        ->and(ApplicationAssignment::where('application_id', $appId)
            ->where('department_id', $cho)->exists())->toBeFalse();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'Health question on an unrouted filing.',
        'department_id' => $cho,
    ])->assertCreated();

    // The health office can open it and finds its own conversation.
    $seen = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")
        ->assertOk()
        ->json('data');

    expect(collect($seen)->pluck('body'))->toContain('Health question on an unrouted filing.');

    /*
     * ---- The inbox row is gone, and that is now the point ----------------
     *
     * This asserted the filing WAS listed, on the reasoning that a message
     * which arrives nowhere the officer can navigate to has not arrived.
     *
     * "Sa admin offices ang maaccess lang nila once na naka assign na sa
     * kanila yung application" [client, 28 September 2026]. The page is the
     * caseload, so an unrouted filing is not on it.
     *
     * The reasoning that produced the old assertion was sound, and the answer
     * to it is upstream rather than here: the office picker on a filing now
     * offers only the offices that are on it (see visibleOffices), so an
     * applicant is not invited to start this conversation in the first place.
     * The message above could only be written by a client aiming straight at
     * the endpoint, which is why the test has to construct it that way.
     *
     * Reading is untouched - authorizeParticipant still lets the office open
     * the transcript, asserted just above - so nothing already sent becomes
     * unreadable. It returns to the inbox when the routing catches up.
     */
    $rows = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data');

    expect(collect($rows)->firstWhere('application_id', $appId))->toBeNull();

    assignOffice($appId, 'CHO');

    $rows = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data');

    expect(collect($rows)->firstWhere('application_id', $appId))->not->toBeNull();

    // The fire office, written to by nobody, still sees nothing.
    $fireRows = test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data');

    expect(collect($fireRows)->firstWhere('application_id', $appId))->toBeNull();
});

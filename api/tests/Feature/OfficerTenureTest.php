<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/*
 * ── One conversation for the applicant, a stretch each for the officers ──
 *
 * "Kung ano lang ang naka assign sa kanya, yun lang ang pwede nyang ma-chat.
 * Once na in-unassign na, magsstay pa rin ang convo pero di nya na ma-cha-chat,
 * like for viewing na lang. So sa end ng business owner, yung unang officer,
 * pag may chat na, andon pa rin ang chat — kung mapapalitan naman ang officer,
 * don pa rin sa convo nya, at mapapalitan lang ang name. Pero sa end naman ng
 * bagong officer in charge, wala yung dating convo — bagong convo na dapat
 * nila" [client, 30 September 2026].
 *
 * Three claims, and they only make sense together:
 *
 *   the applicant has ONE conversation per office, unbroken;
 *   the officer holding the case may write in it;
 *   an officer reads the part that was theirs, and keeps reading it after the
 *   case moves on.
 *
 * `messages.handled_by_user_id` is what carries all three. See the migration
 * 2026_09_30_000100 for why it had to be recorded per message.
 *
 * ── Since 6 October 2026 that is BPLO's rule, and only BPLO's ─────────────
 *
 * The request of that day, for every office but BPLO: an officer sees the
 * conversations of the applications assigned to them, and when a case is
 * unassigned and taken by somebody else "only the newly assigned officer can
 * access the conversation". So outside BPLO the third claim above is reversed:
 * the holder reads the WHOLE conversation, the predecessor keeps none of it,
 * and a case nobody holds is read and written by nobody in the office
 * (`App\Support\CaseHolder`). The applicant's side — one conversation,
 * unbroken, a name that changes — is the same in both.
 *
 * So the cases below come in two kinds. Those about the stretch-per-officer
 * rule run on BPLO, which kept it; they ran on City Health until that date.
 * Those about the new rule run on City Health, and say so in their names.
 */

/**
 * A filed application of the owner's, routed to the health office — or to the
 * office named, which for BPLO is the assignment `submit` already made.
 */
function tenureFiling(string $officeCode = 'CHO'): array
{
    static $n = 0;
    $n++;

    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => "Tenure Store {$n}",
        'registration_type' => 'DTI',
        'registration_number' => "DTI-8810{$n}",
        'tin' => '123-456-789-000',
        'address' => ['line1' => "{$n} Tenure St.", 'barangay_id' => Barangay::first()->id],
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

    $office = Department::where('code', $officeCode)->value('id');

    $assignment = ApplicationAssignment::firstOrCreate([
        'application_id' => $appId,
        'department_id' => $office,
    ]);

    return [$appId, $assignment, $office];
}

/** Hand the case to this officer. */
function handTo(ApplicationAssignment $assignment, string $email): User
{
    $officer = User::where('email', $email)->firstOrFail();
    $assignment->forceFill([
        'officer_user_id' => $officer->id,
        'assigned_at' => now(),
    ])->save();

    return $officer;
}

/** A second seat in the health office, because the seed gives each office one. */
function secondHealthSeat(): User
{
    return tenureSecondSeat('CHO', 'sanitary_officer');
}

/** A second seat in BPLO, for the cases on the rule BPLO kept (6 October 2026). */
function secondBploSeat(): User
{
    return tenureSecondSeat('BPLO', 'bplo_staff');
}

function tenureSecondSeat(string $officeCode, string $role): User
{
    $user = User::create([
        'name' => "Second {$officeCode} Seat",
        'first_name' => 'Second',
        'last_name' => 'Seat',
        'gender' => 'F',
        'email' => 'second.'.strtolower($officeCode).'.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000003',
        'password' => 'biztrack1',
        'department_id' => Department::where('code', $officeCode)->value('id'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', $role)->pluck('id'));

    return $user->fresh();
}

function transcriptFor(string $email, int $appId): Collection
{
    return collect(
        test()->withHeaders(authAs($email))
            ->getJson("/api/v1/applications/{$appId}/messages")
            ->assertOk()
            ->json('data')
    )->pluck('body');
}

function inboxFor(string $email): Collection
{
    return collect(
        test()->withHeaders(authAs($email))
            ->getJson('/api/v1/message-threads?per_page=200')
            ->assertOk()
            ->json('data')
    );
}

// ─────────────────────────────────────────────────────────────────────────
// Writing is for whoever holds the case.
// ─────────────────────────────────────────────────────────────────────────

it('lets the officer holding the case write in it', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Please bring the water potability result.',
            'department_id' => $cho,
        ])->assertCreated();
});

it('refuses an officer the case has been taken from', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'First officer speaking.', 'department_id' => $cho,
        ])->assertCreated();

    handTo($assignment, secondHealthSeat()->email);

    /*
     * Read-only, not gone. The refusal is on the server because a disabled box
     * in a browser stops nothing that keeps a tab open through a reassignment.
     */
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Still speaking?', 'department_id' => $cho,
        ])->assertForbidden();
});

it('lets anyone in BPLO answer while nobody holds the case', function () {
    [$appId, , $bplo] = tenureFiling('BPLO');

    /*
     * A filing routed to BPLO and claimed by nobody. Somebody has to be able
     * to answer the applicant's first question, or it waits for a claim that
     * may be waiting on the answer.
     *
     * Ran on City Health until 6 October 2026; BPLO is the office that kept
     * the rule (see the case below for the others).
     */
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'The office can still answer.', 'department_id' => $bplo,
        ])->assertCreated();
});

it('lets nobody in an office other than BPLO write on a case before somebody holds it', function () {
    [$appId, , $cho] = tenureFiling();

    /*
     * Request of 6 October 2026: outside BPLO the officer presses Assign to Me
     * before they can access the application, and its conversation is part of
     * it. Until then the office answered an unheld case; now the answer is to
     * take it first.
     */
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Nobody holds this yet.', 'department_id' => $cho,
        ])->assertForbidden();
    test()->getJson("/api/v1/applications/{$appId}/messages")->assertForbidden();
});

it('says on BPLO’s row whether the composer is open', function () {
    /*
     * On BPLO, because only there does a predecessor still OPEN the
     * conversation to be told the composer is shut: outside BPLO they are
     * refused it outright since 6 October 2026 (the City Health case after
     * this one). Ran on City Health until that date.
     */
    [$appId, $assignment, $bplo] = tenureFiling('BPLO');
    handTo($assignment, 'bplo@biztrack.local');

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Mine for now.', 'department_id' => $bplo,
        ])->assertCreated();

    $office = fn (string $email) => collect(
        test()->withHeaders(authAs($email))
            ->getJson("/api/v1/applications/{$appId}/messages")
            ->assertOk()->json('meta.offices')
    )->firstWhere('department_id', $bplo);

    expect($office('bplo@biztrack.local')['can_message'])->toBeTrue();

    handTo($assignment, secondBploSeat()->email);

    // The screen closes its composer from this, and the server refuses the
    // POST from the same rule — see 'refuses an officer the case has been
    // taken from' above.
    expect($office('bplo@biztrack.local')['can_message'])->toBeFalse();
});

it('shuts an office other than BPLO out of a case it handed on, composer and conversation both', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Mine for now.', 'department_id' => $cho,
        ])->assertCreated();

    $row = collect(test()->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('meta.offices'))
        ->firstWhere('department_id', $cho);
    expect($row['can_message'])->toBeTrue();

    handTo($assignment, secondHealthSeat()->email);

    // "Only the newly assigned officer can access the conversation" (request
    // of 6 October 2026): not read-only, refused.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")
        ->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────
// Reading: one conversation, a stretch each.
// ─────────────────────────────────────────────────────────────────────────

it('gives BPLO’s successor an empty conversation, and the applicant an unbroken one', function () {
    // BPLO's rule since 6 October 2026; it ran on City Health until then.
    [$appId, $assignment, $bplo] = tenureFiling('BPLO');

    $first = handTo($assignment, 'bplo@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Applicant asks the first officer.', 'department_id' => $bplo,
        ])->assertCreated();
    test()->withHeaders(authAs($first->email))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'First officer answers.', 'department_id' => $bplo,
        ])->assertCreated();

    $second = handTo($assignment, secondBploSeat()->email);

    /*
     * "Sa end naman ng bagong officer in charge, wala yung dating convo —
     * bagong convo na dapat nila." The successor opens on an empty screen.
     */
    expect(transcriptFor($second->email, $appId))->toBeEmpty();

    /*
     * "Sa end ng business owner ... don pa rin sa convo nya." One conversation,
     * unbroken, with both officers' turns in it.
     */
    expect(transcriptFor('owner@biztrack.local', $appId))
        ->toContain('Applicant asks the first officer.')
        ->toContain('First officer answers.');

    // And the first officer keeps what was theirs, after handing it on.
    expect(transcriptFor($first->email, $appId))
        ->toContain('Applicant asks the first officer.')
        ->toContain('First officer answers.');

    // What the successor writes is theirs, and is not the predecessor's.
    test()->withHeaders(authAs($second->email))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'The new officer starts again.', 'department_id' => $bplo,
        ])->assertCreated();

    expect(transcriptFor($second->email, $appId))->toContain('The new officer starts again.');
    expect(transcriptFor($first->email, $appId))->not->toContain('The new officer starts again.');

    // The applicant sees the whole of it, both stretches together.
    expect(transcriptFor('owner@biztrack.local', $appId))
        ->toContain('Applicant asks the first officer.')
        ->toContain('The new officer starts again.');
});

it('gives the successor in an office other than BPLO the whole conversation, and the predecessor none of it', function () {
    /*
     * The case above, turned round by the request of 6 October 2026: "only
     * the newly assigned officer can access the conversation", all of it,
     * chosen over a stretch each. The applicant's side does not change.
     */
    [$appId, $assignment, $cho] = tenureFiling();

    $first = handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Applicant asks the first officer.', 'department_id' => $cho,
        ])->assertCreated();
    test()->withHeaders(authAs($first->email))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'First officer answers.', 'department_id' => $cho,
        ])->assertCreated();

    $second = handTo($assignment, secondHealthSeat()->email);

    // The successor opens on everything said before them.
    expect(transcriptFor($second->email, $appId))
        ->toContain('Applicant asks the first officer.')
        ->toContain('First officer answers.');

    // The predecessor is refused it, not shown their part.
    test()->withHeaders(authAs($first->email))
        ->getJson("/api/v1/applications/{$appId}/messages")
        ->assertForbidden();

    test()->withHeaders(authAs($second->email))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'The new officer carries on.', 'department_id' => $cho,
        ])->assertCreated();

    // The applicant sees one conversation, unbroken, both officers in it.
    expect(transcriptFor('owner@biztrack.local', $appId))
        ->toContain('Applicant asks the first officer.')
        ->toContain('First officer answers.')
        ->toContain('The new officer carries on.');
});

it('keeps the released case in the old BPLO officer’s inbox', function () {
    // BPLO's rule since 6 October 2026; it ran on City Health until then.
    [$appId, $assignment, $bplo] = tenureFiling('BPLO');
    $first = handTo($assignment, 'bplo@biztrack.local');

    test()->withHeaders(authAs($first->email))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Something was said here.', 'department_id' => $bplo,
        ])->assertCreated();

    handTo($assignment, secondBploSeat()->email);

    /*
     * The row stays, keyed on their own stretch of the conversation rather
     * than on a record of the handover — an assignment's `officer_user_id` is
     * overwritten in place, so by then nothing says they ever held it.
     */
    $row = inboxFor($first->email)->firstWhere('application_id', $appId);

    expect($row)->not->toBeNull()
        ->and($row['messages_count'])->toBe(1);
});

it('drops a handed-on case from the old officer’s inbox in an office other than BPLO', function () {
    // The case above, outside BPLO since 6 October 2026: the inbox lists only
    // what the officer holds now, whatever they wrote on it before.
    [$appId, $assignment, $cho] = tenureFiling();
    $first = handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs($first->email))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Something was said here.', 'department_id' => $cho,
        ])->assertCreated();
    expect(inboxFor($first->email)->firstWhere('application_id', $appId))->not->toBeNull();

    $second = handTo($assignment, secondHealthSeat()->email);

    expect(inboxFor($first->email)->firstWhere('application_id', $appId))->toBeNull()
        ->and(inboxFor($second->email)->firstWhere('application_id', $appId))->not->toBeNull();
});

it('does not keep a case the old officer never wrote on', function () {
    [$appId, $assignment] = tenureFiling();
    $first = handTo($assignment, 'sanitary@biztrack.local');

    handTo($assignment, secondHealthSeat()->email);

    /*
     * Nothing was said, so there is no conversation to keep for viewing —
     * which is the whole of what the clause preserves. The row would otherwise
     * be a silhouette: a filing they cannot work, cannot write on, and have
     * nothing to read in.
     */
    expect(inboxFor($first->email)->firstWhere('application_id', $appId))->toBeNull();
});

it('leaves BPLO’s messages from before anybody held the case readable by whoever takes it', function () {
    /*
     * BPLO's alone since 6 October 2026, and it ran on City Health until then.
     * Outside BPLO nobody writes on an unheld case any more (see 'lets nobody
     * in an office other than BPLO write…'), and the holder reads the whole
     * conversation in any case, so there is no unowned stretch to lose.
     */
    [$appId, $assignment, $bplo] = tenureFiling('BPLO');

    /*
     * Written before anybody claimed it: nobody's stretch owns these. The
     * office opens it - an owner may answer an unclaimed office but not start
     * a conversation with one (checklist 2026-09-27, apply item 23).
     */
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Any questions before we start?'])
        ->assertCreated();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Asked before anybody picked it up.', 'department_id' => $bplo,
        ])->assertCreated();

    $officer = handTo($assignment, secondBploSeat()->email);

    /*
     * Hiding these would lose the applicant's first question to the office —
     * the one most likely to be the reason somebody claimed the case.
     */
    expect(transcriptFor($officer->email, $appId))->toContain('Asked before anybody picked it up.');
});

it('does not touch a general enquiry, which has no officer in charge', function () {
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)
        ->postJson('/api/v1/general-messages', ['body' => 'No filing behind this one.'])
        ->assertCreated();

    $thread = MessageThread::whereNull('application_id')
        ->where('user_id', User::where('email', 'owner@biztrack.local')->value('id'))
        ->latest('id')
        ->first();

    /*
     * An enquiry has no filing and so nobody holds it. Null here is what keeps
     * it readable by the office it was addressed to, whoever happens to be
     * sitting there.
     */
    expect(Message::where('thread_id', $thread->id)->value('handled_by_user_id'))->toBeNull();
});

it('renames the officer on the applicant’s row rather than starting a new one', function () {
    [$appId, $assignment, $cho] = tenureFiling();

    $first = handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'A question for whoever holds this.', 'department_id' => $cho,
        ])->assertCreated();

    $row = fn () => inboxFor('owner@biztrack.local')->firstWhere('application_id', $appId);

    expect($row()['responsible_office']['officer']['name'])->toBe($first->name);

    $second = handTo($assignment, secondHealthSeat()->email);

    /*
     * "Kung mapapalitan naman ang officer, don pa rin sa convo nya, at
     * mapapalitan lang ang name" [client, 30 September 2026].
     *
     * One row, one conversation, a different name on it — because the name is
     * read off the assignment as it stands rather than copied onto the thread
     * when it was opened. Nothing here had to be built; what this test does is
     * stop it being broken by somebody later deciding to denormalise it.
     */
    expect(inboxFor('owner@biztrack.local')->where('application_id', $appId))->toHaveCount(1)
        ->and($row()['responsible_office']['officer']['name'])->toBe($second->name)
        ->and($row()['messages_count'])->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────
// Where the person writing to the office currently stands.
// ─────────────────────────────────────────────────────────────────────────

/*
 * "Paki lagyan din ng note sa other admin offices sa messages page kung ang
 * kumokontak sa kanya ay currently suspended, flagged, blacklisted" [client,
 * 30 September 2026].
 *
 * An office reading its mail cannot otherwise tell: a blacklisted owner and one
 * in good standing write identical rows, and the reply differs — somebody
 * barred from filing should not be told to file.
 */

function standingSeenBy(string $email, int $appId): ?array
{
    $row = inboxFor($email)->firstWhere('application_id', $appId);

    return $row['counterparty']['standing'] ?? null;
}

it('says nothing about a sender in good standing', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'An ordinary question.', 'department_id' => $cho,
        ])->assertCreated();

    expect(standingSeenBy('sanitary@biztrack.local', $appId))->toBeNull();
});

it('notes a flagged business, which is a watch rather than a bar', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'A question.', 'department_id' => $cho,
        ])->assertCreated();

    Application::find($appId)->business->forceFill(['status' => 'flagged'])->save();

    expect(standingSeenBy('sanitary@biztrack.local', $appId))
        ->toBe(['kind' => 'flagged', 'label' => 'Business flagged', 'suspended_count' => 0]);
});

it('notes a suspended business', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'A question.', 'department_id' => $cho,
        ])->assertCreated();

    Application::find($appId)->business->forceFill(['status' => 'suspended'])->save();

    expect(standingSeenBy('sanitary@biztrack.local', $appId))
        ->toBe(['kind' => 'suspended', 'label' => 'Business suspended', 'suspended_count' => 1]);
});

it('reports a blacklisted account over the business it is writing about', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'A question.', 'department_id' => $cho,
        ])->assertCreated();

    $app = Application::find($appId);
    $app->business->forceFill(['status' => 'flagged'])->save();
    $app->applicant->forceFill(['blacklisted_at' => now()])->save();

    /*
     * The heavier finding, and the one that reaches furthest: a blacklisting is
     * against the PERSON and covers every business they hold or register
     * later. Reporting the shop's flag over it would understate what the
     * officer is looking at.
     */
    expect(standingSeenBy('sanitary@biztrack.local', $appId))
        /*
     * `suspended_count` is 0 on a blacklisting and means nothing: the cascade
     * sets every business the person holds to `blacklisted`, so none of them
     * is suspended. It is carried so the shape does not change between kinds.
     */
        ->toBe(['kind' => 'blacklisted', 'label' => 'Account blacklisted', 'suspended_count' => 0]);
});

it('does not tell an applicant their own standing on their own row', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'A question.', 'department_id' => $cho,
        ])->assertCreated();

    Application::find($appId)->business->forceFill(['status' => 'suspended'])->save();

    /*
     * The note is for the office reading its mail. An applicant learns their
     * own standing from the restriction notice, which explains it and offers
     * somewhere to take it — a chip on their own conversation would state the
     * finding and answer nothing.
     */
    $row = inboxFor('owner@biztrack.local')->firstWhere('application_id', $appId);

    expect($row['counterparty'])->not->toHaveKey('standing');
});

it('counts how many of the sender’s businesses are suspended', function () {
    /*
     * "Pwede rin i-note doon na may isa, dalawa, ... syang business na
     * suspended" [client, 1 October 2026].
     *
     * The note stays specific to the business this conversation is about —
     * that is the same instruction's first half — and this is the scale of it.
     * An officer answering about one suspended shopfront is better for knowing
     * whether it is the only one or the third.
     */
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'A question.', 'department_id' => $cho,
        ])->assertCreated();

    $app = Application::find($appId);
    $app->business->forceFill(['status' => 'suspended'])->save();

    expect(standingSeenBy('sanitary@biztrack.local', $appId)['suspended_count'])->toBe(1);

    /*
     * A second shopfront of the same owner's, suspended too.
     *
     * The id is read first and the update addressed to it. `->limit(1)->update()`
     * is not portable - SQLite only honours LIMIT on UPDATE when it is built
     * with SQLITE_ENABLE_UPDATE_DELETE_LIMIT, and this one is not, so the
     * clause was dropped and the row was never found.
     */
    $otherId = Business::where('owner_user_id', $app->applicant_user_id)
        ->where('id', '!=', $app->business_id)
        ->value('id');

    expect($otherId)->not->toBeNull();

    Business::whereKey($otherId)->update(['status' => 'suspended']);

    expect(standingSeenBy('sanitary@biztrack.local', $appId)['suspended_count'])->toBe(2);
});

it('says nothing on a healthy business however many others are suspended', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'A question.', 'department_id' => $cho,
        ])->assertCreated();

    $app = Application::find($appId);

    // Every OTHER business this owner holds, suspended.
    Business::where('owner_user_id', $app->applicant_user_id)
        ->where('id', '!=', $app->business_id)
        ->update(['status' => 'suspended']);

    /*
     * "Naka specific lang kung anong business ang suspended, at ayon lang din
     * ang may note" [client, 1 October 2026]. This conversation's business is
     * in good standing, so the row says nothing — raising the others here
     * would have an officer answering a matter the applicant did not come
     * about.
     */
    expect(standingSeenBy('sanitary@biztrack.local', $appId))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────
// The enquiry row carries it too.
// ─────────────────────────────────────────────────────────────────────────

/*
 * "Pag yung business is flagged, suspended, ipa-reflect din sa business owner
 * general enquiry, para alam ng other admin officer" [client, 1 October 2026].
 *
 * A filing's row names the business the conversation is about, so its note is
 * that business's standing. An enquiry is about no business at all, and used to
 * report nothing but a blacklisting — so an office answering a question from
 * somebody with two suspended shopfronts had no sign of it, which is exactly
 * the reader the note was added for.
 */

/**
 * The seeded business owner.
 *
 * Its own helper rather than OfficeInboxScopeTest's `owner()`: a Pest run
 * filtered to one file loads only that file, so a fixture two suites share has
 * to be in tests/Pest.php - and a second copy under the same name would
 * redeclare the function on a full run.
 */
function tenureOwner(): User
{
    return User::where('email', 'owner@biztrack.local')->firstOrFail();
}

function enquiryStandingAt(string $officerEmail, string $ownerName): ?array
{
    $row = inboxFor($officerEmail)
        ->where('kind', 'general')
        ->firstWhere('counterparty.name', $ownerName);

    return $row['counterparty']['standing'] ?? null;
}

it('says nothing on the enquiry of an owner in good standing', function () {
    expect(enquiryStandingAt('bplo@biztrack.local', tenureOwner()->name))->toBeNull();
});

it('reflects a suspended business on the owner’s enquiry', function () {
    Business::where('owner_user_id', tenureOwner()->id)->limit(1)->get()
        ->each(fn (Business $b) => $b->forceFill(['status' => 'suspended'])->save());

    expect(enquiryStandingAt('bplo@biztrack.local', tenureOwner()->name))
        ->toBe(['kind' => 'suspended', 'label' => 'Business suspended', 'suspended_count' => 1]);
});

it('reflects a flagged business on the owner’s enquiry', function () {
    Business::where('owner_user_id', tenureOwner()->id)->limit(1)->get()
        ->each(fn (Business $b) => $b->forceFill(['status' => 'flagged'])->save());

    expect(enquiryStandingAt('bplo@biztrack.local', tenureOwner()->name))
        ->toBe(['kind' => 'flagged', 'label' => 'Business flagged', 'suspended_count' => 0]);
});

it('reports the worst standing the owner holds, and how far it reaches', function () {
    /*
     * A second shopfront, made here rather than assumed: the demo seed gives
     * this owner one, and the claim under test is about holding several in
     * different standings at once.
     */
    tenureFiling();

    $held = Business::where('owner_user_id', tenureOwner()->id)->get();
    expect($held->count())->toBeGreaterThan(1);

    $held[0]->forceFill(['status' => 'flagged'])->save();
    $held[1]->forceFill(['status' => 'suspended'])->save();

    /*
     * With no one business in question the answer is the worst they hold — a
     * suspension outranks a watch — and the count says how far it reaches.
     */
    expect(enquiryStandingAt('bplo@biztrack.local', tenureOwner()->name))
        ->toBe(['kind' => 'suspended', 'label' => 'Business suspended', 'suspended_count' => 1]);

    $held[0]->forceFill(['status' => 'suspended'])->save();

    expect(enquiryStandingAt('bplo@biztrack.local', tenureOwner()->name)['suspended_count'])->toBe(2);
});

it('reports a blacklisted account over any business standing', function () {
    Business::where('owner_user_id', tenureOwner()->id)->limit(1)->get()
        ->each(fn (Business $b) => $b->forceFill(['status' => 'suspended'])->save());

    tenureOwner()->forceFill(['blacklisted_at' => now()])->save();

    /*
     * The heavier finding: it is against the PERSON and covers every business
     * they hold or register later, so reporting a shop's suspension over it
     * would understate what the officer is looking at.
     */
    expect(enquiryStandingAt('bplo@biztrack.local', tenureOwner()->name)['kind'])->toBe('blacklisted');
});

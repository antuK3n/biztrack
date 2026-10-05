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
 */

/** A filed application of the owner's, routed to the health office. */
function tenureFiling(): array
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

    $cho = Department::where('code', 'CHO')->value('id');

    $assignment = ApplicationAssignment::firstOrCreate([
        'application_id' => $appId,
        'department_id' => $cho,
    ]);

    return [$appId, $assignment, $cho];
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
    $user = User::create([
        'name' => 'Second Health Seat',
        'first_name' => 'Second',
        'last_name' => 'Seat',
        'gender' => 'F',
        'email' => 'second.health.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000003',
        'password' => 'biztrack1',
        'department_id' => Department::where('code', 'CHO')->value('id'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', 'sanitary_officer')->pluck('id'));

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

it('lets anyone in the office answer while nobody holds the case', function () {
    [$appId, , $cho] = tenureFiling();

    /*
     * A filing routed to the office and claimed by nobody. Somebody has to be
     * able to answer the applicant's first question, or it waits for a claim
     * that may be waiting on the answer.
     */
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'The office can still answer.', 'department_id' => $cho,
        ])->assertCreated();
});

it('says on the row whether the composer is open', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Mine for now.', 'department_id' => $cho,
        ])->assertCreated();

    $office = fn (string $email) => collect(
        test()->withHeaders(authAs($email))
            ->getJson("/api/v1/applications/{$appId}/messages")
            ->assertOk()->json('meta.offices')
    )->firstWhere('department_id', $cho);

    expect($office('sanitary@biztrack.local')['can_message'])->toBeTrue();

    handTo($assignment, secondHealthSeat()->email);

    // The screen closes its composer from this, and the server refuses the
    // POST from the same rule — see the test above.
    expect($office('sanitary@biztrack.local')['can_message'])->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────
// Reading: one conversation, a stretch each.
// ─────────────────────────────────────────────────────────────────────────

it('gives the successor an empty conversation, and the applicant an unbroken one', function () {
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
            'body' => 'The new officer starts again.', 'department_id' => $cho,
        ])->assertCreated();

    expect(transcriptFor($second->email, $appId))->toContain('The new officer starts again.');
    expect(transcriptFor($first->email, $appId))->not->toContain('The new officer starts again.');

    // The applicant sees the whole of it, both stretches together.
    expect(transcriptFor('owner@biztrack.local', $appId))
        ->toContain('Applicant asks the first officer.')
        ->toContain('The new officer starts again.');
});

it('keeps the released case in the old officer’s inbox', function () {
    [$appId, $assignment, $cho] = tenureFiling();
    $first = handTo($assignment, 'sanitary@biztrack.local');

    test()->withHeaders(authAs($first->email))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Something was said here.', 'department_id' => $cho,
        ])->assertCreated();

    handTo($assignment, secondHealthSeat()->email);

    /*
     * The row stays, keyed on their own stretch of the conversation rather
     * than on a record of the handover — an assignment's `officer_user_id` is
     * overwritten in place, so by then nothing says they ever held it.
     */
    $row = inboxFor($first->email)->firstWhere('application_id', $appId);

    expect($row)->not->toBeNull()
        ->and($row['messages_count'])->toBe(1);
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

it('leaves an unclaimed office’s messages readable by whoever takes the case', function () {
    [$appId, $assignment, $cho] = tenureFiling();

    /*
     * Written before anybody claimed it: nobody's stretch owns these. The
     * office opens it - an owner may answer an unclaimed office but not start
     * a conversation with one (checklist 2026-09-27, apply item 23).
     */
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", ['body' => 'Any questions before we start?'])
        ->assertCreated();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Asked before anybody picked it up.', 'department_id' => $cho,
        ])->assertCreated();

    $officer = handTo($assignment, 'sanitary@biztrack.local');

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

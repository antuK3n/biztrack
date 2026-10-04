<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/*
 * ── Whose permits an office sees, and whose questions reach it ────────────
 *
 * Two rules the client asked for together [28 September 2026], and they only
 * make sense together:
 *
 *   "ang andon lang sa messages page nila ay kung ano ang mga naka assign na
 *    business permit sa kanila"
 *
 *   "sa business owner side kung wala pang mismong officer in charge sa
 *    application nila, magkakaroon na rin ng general inquiry ang ibat ibang
 *    offices, tulad ng pinagawa ko sa bplo"
 *
 * The first narrows an office's Messages page to its own caseload. On its own
 * that would shut a door: an owner whose filing has not been routed anywhere
 * would have no one to ask. The second opens a different one — every office
 * gets the front door BPLO has always had, and a question with no filing
 * behind it goes there instead of into a caseload it does not belong to.
 */

/** The offices by code, so a test never hard-codes a seed id. */
function office(string $code): Department
{
    return Department::where('code', $code)->firstOrFail();
}

function owner(): User
{
    return User::where('email', 'owner@biztrack.local')->firstOrFail();
}

/** One filing of the owner's, routed nowhere. */
function unroutedFiling(): Application
{
    return Application::where('applicant_user_id', owner()->id)
        ->where('status', '!=', 'draft')
        ->firstOrFail();
}

/** The rows an account sees on its Messages page. */
function inbox(string $email): Collection
{
    $token = loginToken($email);
    app('auth')->forgetGuards();

    return collect(
        test()->withToken($token)->getJson('/api/v1/message-threads')->assertOk()->json('data')
    );
}

// ─────────────────────────────────────────────────────────────────────────
// The office's page is its caseload.
// ─────────────────────────────────────────────────────────────────────────

it('puts an assigned permit in the office inbox before anybody has written', function () {
    $filing = unroutedFiling();
    $fire = office('BFP');

    // Nothing said, nothing routed: the fire office has no business with it.
    expect(inbox('fire@biztrack.local')->pluck('application_id'))->not->toContain($filing->id);

    ApplicationAssignment::create([
        'application_id' => $filing->id,
        'department_id' => $fire->id,
        'officer_user_id' => User::where('email', 'fire@biztrack.local')->value('id'),
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    /*
     * The row appears on the strength of the ASSIGNMENT alone. This is the
     * half the old rule got wrong in the other direction: it listed a filing
     * only once a conversation on it had messages, so an officer handed a
     * permit could not write the first word about it from this screen.
     */
    expect(inbox('fire@biztrack.local')->pluck('application_id'))->toContain($filing->id);
});

/**
 * A second seat in an office, because the demo seed gives each office one.
 *
 * The register has several per office - that is the whole reason
 * `officer_user_id` matters - so the case has to be built here rather than
 * borrowed from the seed.
 */
function secondSeat(string $departmentCode, string $roleName): User
{
    $user = User::create([
        'name' => 'Second Seat',
        'first_name' => 'Second',
        'last_name' => 'Seat',
        'gender' => 'F',
        'email' => 'second.seat.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000002',
        'password' => 'biztrack1',
        'department_id' => office($departmentCode)->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', $roleName)->pluck('id'));

    return $user->fresh();
}

it('keeps another officer\'s caseload out of an office seat\'s inbox', function () {
    $filing = unroutedFiling();
    $colleague = secondSeat('BPLO', 'bplo_staff');

    /*
     * Routed to BPLO, but held by a NAMED person who is not the reader. 32 of
     * the 34 assignments in the register name somebody, so a rule that read
     * the department and stopped there would show every BPLO clerk every other
     * BPLO clerk's post.
     */
    ApplicationAssignment::create([
        'application_id' => $filing->id,
        'department_id' => office('BPLO')->id,
        'officer_user_id' => $colleague->id,
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    expect(inbox('bplo@biztrack.local')->pluck('application_id'))->not->toContain($filing->id);
    expect(inbox($colleague->email)->pluck('application_id'))->toContain($filing->id);
});

it('does not show an office a filing it was written to about but never assigned', function () {
    $filing = unroutedFiling();
    $cenro = office('CENRO');

    $thread = MessageThread::create([
        'application_id' => $filing->id,
        'department_id' => $cenro->id,
    ]);
    Message::create([
        'thread_id' => $thread->id,
        'sender_user_id' => owner()->id,
        'body' => 'Is my drainage plan enough for this?',
    ]);

    /*
     * ---- This asserted the opposite, and the client settled it -----------
     *
     * CENRO was written to and never routed. The row used to stay on the
     * strength of the message, on the reasoning that hiding mail somebody
     * actually sent is worse than a slightly wider list.
     *
     * "Sa admin offices ang maaccess lang nila once na naka assign na sa
     * kanila yung application" [client, 28 September 2026]. The page is the
     * caseload; a question that is not about a permit this office is handling
     * belongs in the general enquiry, which every office now has.
     *
     * Note what is NOT claimed: the conversation is not deleted. It is in the
     * register and returns to this screen the moment the filing is routed to
     * CENRO, which is the state in which somebody there could act on it.
     */
    expect(inbox('cenro@biztrack.local')->pluck('application_id'))->not->toContain($filing->id);

    // And it comes back when the routing catches up.
    ApplicationAssignment::create([
        'application_id' => $filing->id,
        'department_id' => $cenro->id,
        'officer_user_id' => User::where('email', 'cenro@biztrack.local')->value('id'),
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    expect(inbox('cenro@biztrack.local')->pluck('application_id'))->toContain($filing->id);
});

it('does not show an office a filing it was never assigned', function () {
    $filing = unroutedFiling();

    expect(inbox('obo@biztrack.local')->pluck('application_id'))->not->toContain($filing->id);
});

// ─────────────────────────────────────────────────────────────────────────
// The owner's front doors.
// ─────────────────────────────────────────────────────────────────────────

it('gives the owner one enquiry row carrying every office', function () {
    /*
     * ---- One row, then six, then one again -------------------------------
     *
     * The enquiry began as a single conversation with BPLO. When every office
     * got a front door it became a row each, and the inbox then showed six
     * conversations - six titles, six dates, six previews - for what an owner
     * thinks of as one thing: asking the City a question.
     *
     * "Nasa iisang convo na lang uli ang mga general inquiry sa ibat ibang
     * offices, tas may choices na lang ulit don kung anong office" [client, 30
     * September 2026]. So the row is one and the offices ride ON it, which is
     * the same shape a permit's row has.
     *
     * Underneath nothing joined up: `(user_id, department_id)` is still unique
     * and an office still reads only its own thread. What was merged is the
     * summary, not the correspondence - which the test below this one holds.
     */
    $rows = inbox('owner@biztrack.local')->where('kind', 'general');

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect(collect($row['offices'])->pluck('code')->sort()->values()->all())
        ->toBe(Department::orderBy('code')->pluck('code')->all())
        ->and($row['counterparty']['name'])->toBe('General enquiry');
});

it('leaves every office on the row however the filings are routed', function () {
    /*
     * Two narrower rules came before this one and both were wrong in the same
     * direction: the first withdrew an office once it was in charge of
     * anything of the owner's, the second once it was in charge of everything.
     * "Kung wala pang mismong officer in charge" was the situation being
     * described - an owner with nobody to write to - not a condition on the
     * offer.
     */
    $fire = office('BFP');
    $fireOfficer = User::where('email', 'fire@biztrack.local')->value('id');

    $filings = Application::where('applicant_user_id', owner()->id)
        ->where('status', '!=', 'draft')
        ->pluck('id');

    expect($filings->count())->toBeGreaterThan(1);

    $offices = fn () => collect(
        inbox('owner@biztrack.local')->where('kind', 'general')->first()['offices']
    )->pluck('code');

    expect($offices())->toContain('BFP');

    // Every filing they hold, handed to a named person in the fire office.
    foreach ($filings as $id) {
        ApplicationAssignment::create([
            'application_id' => $id,
            'department_id' => $fire->id,
            'officer_user_id' => $fireOfficer,
            'status' => 'pending',
            'assigned_at' => now(),
        ]);
    }

    expect($offices())->toContain('BFP')
        ->and($offices()->sort()->values()->all())
        ->toBe(Department::orderBy('code')->pluck('code')->all());
});

it('counts what has been said to each office separately on the one row', function () {
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => office('BFP')->id,
        'body' => 'A question for the fire office.',
    ])->assertCreated();

    $row = inbox('owner@biztrack.local')->where('kind', 'general')->first();
    $offices = collect($row['offices'])->keyBy('code');

    /*
     * The counts are what make one row honest about six conversations. Without
     * them the picker is six identical pills and the owner has no way to see
     * which office they have already written to.
     */
    expect($offices['BFP']['messages_count'])->toBe(1)
        ->and($offices['CHO']['messages_count'])->toBe(0)
        ->and($row['messages_count'])->toBe(1)
        // And the row is summarised from whoever spoke last.
        ->and($row['responsible_office']['code'])->toBe('BFP')
        ->and($row['last_message']['body'])->toBe('A question for the fire office.');
});

// ─────────────────────────────────────────────────────────────────────────
// Each door is a separate conversation, and only its own office reads it.
// ─────────────────────────────────────────────────────────────────────────

it('keeps one enquiry per office rather than merging them', function () {
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => office('BFP')->id,
        'body' => 'A question for the fire office.',
    ])->assertCreated();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => office('CHO')->id,
        'body' => 'A question for the health office.',
    ])->assertCreated();

    $fire = test()->withToken($token)
        ->getJson('/api/v1/general-messages?department_id='.office('BFP')->id)
        ->assertOk();

    expect($fire->json('meta.total'))->toBe(1)
        ->and($fire->json('data.0.body'))->toBe('A question for the fire office.');

    $health = test()->withToken($token)
        ->getJson('/api/v1/general-messages?department_id='.office('CHO')->id)
        ->assertOk();

    expect($health->json('meta.total'))->toBe(1)
        ->and($health->json('data.0.body'))->toBe('A question for the health office.');
});

it('sends an enquiry to BPLO when no office is named', function () {
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    /*
     * Every client written before offices had front doors sends exactly this,
     * and has to keep working. The absent office is not an error, it is the
     * owner who does not know which office to ask.
     */
    test()->withToken($token)
        ->postJson('/api/v1/general-messages', ['body' => 'Who do I even ask about this?'])
        ->assertCreated();

    $thread = MessageThread::whereNull('application_id')->where('user_id', owner()->id)->first();

    expect($thread->department->code)->toBe('BPLO');
});

it('shows an office only the enquiries addressed to it', function () {
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => office('BFP')->id,
        'body' => 'Fire office only.',
    ])->assertCreated();

    /*
     * The fire office holds the words. Found by the message rather than by
     * position: an office's list now carries a row for every owner it may
     * hear from, whether or not anybody has written [client, 1 October 2026],
     * so `first()` is whichever account sorts highest and not necessarily the
     * one that spoke.
     */
    $fire = inbox('fire@biztrack.local')->where('kind', 'general');
    $spoken = $fire->firstWhere('messages_count', 1);

    expect($spoken)->not->toBeNull()
        ->and($spoken['last_message']['body'])->toBe('Fire office only.');

    /*
     * The half that has to stay shut. This read BPLO's post hard-coded, so
     * before the change the fire office would have been shown BPLO's mail and
     * not its own - a leak and a blackout in the same line.
     *
     * The health office may hold rows of its own now; what it must not hold is
     * a word of this. Asserted on the CONTENT, because the row is no longer
     * evidence of anything - it is the door, and every office has one per
     * owner it may hear from.
     */
    $health = inbox('sanitary@biztrack.local')->where('kind', 'general');

    expect($health->sum('messages_count'))->toBe(0)
        ->and($health->pluck('last_message')->filter())->toBeEmpty();
});

it('refuses an enquiry addressed to an office that does not exist', function () {
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)
        ->postJson('/api/v1/general-messages', ['department_id' => 99999, 'body' => 'Hello?'])
        ->assertNotFound();
});

it('will not let one office open an enquiry addressed to another', function () {
    $ownerId = owner()->id;
    $fire = office('BFP');

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => $fire->id,
        'body' => 'For the fire office.',
    ])->assertCreated();

    /*
     * The two-segment form names WHOSE enquiry to open, and is an office
     * action. Naming the office in the query does not make the reader
     * answerable for it: the health office asking for the fire office's post
     * is refused, which is the half of per-office enquiries that has to stay
     * shut. Widening who may be WRITTEN to is safe only while who may READ
     * stays narrow.
     */
    $health = loginToken('sanitary@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($health)
        ->getJson("/api/v1/general-messages/{$ownerId}?department_id={$fire->id}")
        ->assertForbidden();

    // Its own office's enquiry, had there been one, is another matter.
    test()->withToken($health)
        ->getJson("/api/v1/general-messages/{$ownerId}?department_id=".office('CHO')->id)
        ->assertOk();
});

// ─────────────────────────────────────────────────────────────────────────
// Somebody is told.
// ─────────────────────────────────────────────────────────────────────────

it('tells the office when an enquiry arrives for it', function () {
    $fire = office('BFP');
    $officer = User::where('email', 'fire@biztrack.local')->firstOrFail();
    $before = $officer->notifications()->count();

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => $fire->id,
        'body' => 'Do I need a second extinguisher on the mezzanine?',
    ])->assertCreated();

    /*
     * A general enquiry was BPLO's alone, and BPLO lives in that inbox, so
     * nothing being sent was survivable. An enquiry to the fire office lands
     * in an inbox whose whole purpose is that office's CASELOAD, and without
     * this it waits for somebody to notice it.
     */
    expect($officer->notifications()->count())->toBeGreaterThan($before);

    $newest = $officer->notifications()->latest('id')->first();
    expect($newest->title)->toContain('sent your office a question')
        // Their own prefix: an office account signs in at /staff.
        ->and($newest->link)->toBe('/staff/messages');
});

it('does not tell another office about it', function () {
    $health = User::where('email', 'sanitary@biztrack.local')->firstOrFail();
    $before = $health->notifications()->count();

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => office('BFP')->id,
        'body' => 'For the fire office only.',
    ])->assertCreated();

    expect($health->notifications()->count())->toBe($before);
});

it('tells the owner when the office answers', function () {
    $fire = office('BFP');
    $owner = owner();

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();
    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => $fire->id,
        'body' => 'A question.',
    ])->assertCreated();

    $before = $owner->notifications()->count();

    $officer = loginToken('fire@biztrack.local');
    app('auth')->forgetGuards();
    test()->withToken($officer)->postJson("/api/v1/general-messages/{$owner->id}", [
        'department_id' => $fire->id,
        'body' => 'Yes, one per floor.',
    ])->assertCreated();

    /*
     * The owner has no inbox they are paid to watch, so the reply is the
     * direction that matters most.
     */
    expect($owner->notifications()->count())->toBeGreaterThan($before);
    expect($owner->notifications()->latest('id')->first()->title)
        ->toContain('Bureau of Fire Protection');
});

// ─────────────────────────────────────────────────────────────────────────
// An office can start the conversation, not only answer one.
// ─────────────────────────────────────────────────────────────────────────

/*
 * "Lahat dapat ng officer matatanggap ang general inquiry, so that ma-me-message
 * pa rin nila yung business owner sa messages page. Matic na pag gumagawa ng
 * account may magrereflect na sa general inquiry ng BPLO. Ganon naman din sa
 * other offices once na nag-apply na sila ng other permit sa office na yon"
 * [client, 1 October 2026].
 *
 * The office's enquiry list used to be built from THREADS, and only threads with
 * something in them. That is right for a filing, where the applicant has already
 * been given a way in, and wrong here: the row IS the way in.
 */

function enquiryRowsFor(string $email): Collection
{
    return inbox($email)->where('kind', 'general');
}

it('puts a newly registered owner in BPLO’s list before anybody has written', function () {
    $owner = User::create([
        'name' => 'Brand New Owner',
        'first_name' => 'Brand',
        'last_name' => 'Owner',
        'gender' => 'F',
        'email' => 'brand.new.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000009',
        'password' => 'biztrack1',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $owner->roles()->sync(Role::where('name', 'business_owner')->pluck('id'));

    /*
     * No business, no filing, nothing said. BPLO coordinates every filing and
     * is the office you write to when you do not know which office to ask, so
     * an account is in its list from the day it is registered.
     */
    $row = enquiryRowsFor('bplo@biztrack.local')
        ->firstWhere('counterparty.name', 'Brand New Owner');

    expect($row)->not->toBeNull()
        ->and($row['messages_count'])->toBe(0)
        ->and($row['thread_id'])->toBeNull()
        // And it names them, so the office can open the conversation.
        ->and($row['user_id'])->toBe($owner->id);
});

it('keeps that owner out of another office’s list until they are routed work', function () {
    $owner = User::create([
        'name' => 'Unrouted Owner',
        'first_name' => 'Unrouted',
        'last_name' => 'Owner',
        'gender' => 'F',
        'email' => 'unrouted.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000010',
        'password' => 'biztrack1',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $owner->roles()->sync(Role::where('name', 'business_owner')->pluck('id'));

    /*
     * An office with no filing of yours has no business opening a conversation
     * with you, and an inbox listing every citizen in the city would bury the
     * ones it does have work for.
     */
    expect(enquiryRowsFor('fire@biztrack.local')->firstWhere('counterparty.name', 'Unrouted Owner'))
        ->toBeNull();
});

it('adds an owner to an office’s list once that office is routed their filing', function () {
    $filing = unroutedFiling();
    $fire = office('BFP');

    expect(enquiryRowsFor('fire@biztrack.local')->firstWhere('counterparty.name', owner()->name))
        ->toBeNull();

    ApplicationAssignment::create([
        'application_id' => $filing->id,
        'department_id' => $fire->id,
        'officer_user_id' => User::where('email', 'fire@biztrack.local')->value('id'),
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    // "Ganon naman din sa other offices once na nag-apply na sila ng other
    // permit sa office na yon."
    expect(enquiryRowsFor('fire@biztrack.local')->firstWhere('counterparty.name', owner()->name))
        ->not->toBeNull();
});

it('lists an owner who simply wrote to an office it was never routed for', function () {
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => office('CENRO')->id,
        'body' => 'A question for the environment office.',
    ])->assertCreated();

    /*
     * An owner may address ANY office through its general enquiry, routed work
     * or not - that is what the front door per office is for. Without this the
     * office would be handed a question it could not see: the thread accepted,
     * stored, and absent from the one screen that lists enquiries.
     */
    $row = enquiryRowsFor('cenro@biztrack.local')->firstWhere('counterparty.name', owner()->name);

    expect($row)->not->toBeNull()
        ->and($row['last_message']['body'])->toBe('A question for the environment office.');
});

it('lets the office write first, to somebody who has said nothing', function () {
    $ownerId = owner()->id;

    $token = loginToken('bplo@biztrack.local');
    app('auth')->forgetGuards();

    /*
     * The point of the row. An office that can only answer what it has been
     * asked cannot start the conversation, and starting it is what this screen
     * is for.
     */
    test()->withToken($token)
        ->postJson("/api/v1/general-messages/{$ownerId}", [
            'department_id' => office('BPLO')->id,
            'body' => 'Your renewal window opens next month.',
        ])
        ->assertCreated();

    $row = enquiryRowsFor('bplo@biztrack.local')->firstWhere('counterparty.name', owner()->name);

    expect($row['last_message']['body'])->toBe('Your renewal window opens next month.');
});

it('shows the same enquiry to every officer in the office', function () {
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => office('BPLO')->id,
        'body' => 'Anybody at BPLO, please.',
    ])->assertCreated();

    /*
     * "Lahat dapat ng officer matatanggap ang general inquiry." An enquiry has
     * no filing and so no officer in charge - `handled_by_user_id` is null on
     * every message in one - so there is no tenure to scope by and none is
     * applied. Whoever holds the seat reads it.
     */
    $colleague = secondSeat('BPLO', 'bplo_staff');

    foreach (['bplo@biztrack.local', $colleague->email] as $email) {
        $row = enquiryRowsFor($email)->firstWhere('counterparty.name', owner()->name);

        expect($row)->not->toBeNull()
            ->and($row['last_message']['body'])->toBe('Anybody at BPLO, please.');
    }
});

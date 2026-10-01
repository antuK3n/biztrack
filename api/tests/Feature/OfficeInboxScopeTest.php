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

it('offers the owner a general enquiry with every office, not only BPLO', function () {
    $rows = inbox('owner@biztrack.local')->where('kind', 'general');

    $codes = $rows->pluck('responsible_office.code')->sort()->values();

    expect($codes->all())->toBe(
        Department::orderBy('code')->pluck('code')->all()
    );
});

it('leaves every door open however the filings are routed', function () {
    /*
     * ---- Two narrower rules came before this one, both wrong -------------
     *
     * The first closed an office's door as soon as that office was in charge
     * of anything of the owner's. Against the register that shut every door
     * but BPLO on the strength of one permit routed everywhere - including
     * for a permit filed days later with nothing routed to it at all.
     *
     * The second closed it only once the office was in charge of EVERY filing
     * they held. Better, and wrong in the same direction.
     *
     * "Sa business owner side lahat na ng offices may general inquiry"
     * [client, 28 September 2026]. "Kung wala pang mismong officer in charge"
     * was the situation being described - an owner with nobody to write to -
     * not a condition on the door. The answer to it is that the offices are
     * always there.
     */
    $fire = office('BFP');
    $fireOfficer = User::where('email', 'fire@biztrack.local')->value('id');

    $filings = Application::where('applicant_user_id', owner()->id)
        ->where('status', '!=', 'draft')
        ->pluck('id');

    expect($filings->count())->toBeGreaterThan(1);

    $doors = fn () => inbox('owner@biztrack.local')
        ->where('kind', 'general')
        ->pluck('responsible_office.code');

    expect($doors())->toContain('BFP');

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

    /*
     * Still there. A directory that grows and shrinks with the routing is one
     * an owner cannot learn: the fire office was on the page last week and is
     * gone today, and nothing on the screen explains why.
     */
    expect($doors())->toContain('BFP');

    // And the whole set, not only the one under test.
    expect($doors()->sort()->values()->all())
        ->toBe(Department::orderBy('code')->pluck('code')->all());
});

it('keeps a door open once it has been used', function () {
    $fire = office('BFP');

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)
        ->postJson('/api/v1/general-messages', [
            'department_id' => $fire->id,
            'body' => 'Do I need a second extinguisher on the mezzanine?',
        ])
        ->assertCreated();

    /*
     * Writing in a door does not remove it; it fills it. The row is the same
     * conversation either way, and the SCREEN decides where to draw it from
     * the message count - see MessagesPage, where an enquiry with something
     * in it joins the conversations and an empty one stays under "Ask an
     * office".
     */
    $row = inbox('owner@biztrack.local')
        ->where('kind', 'general')
        ->firstWhere('responsible_office.code', 'BFP');

    expect($row)->not->toBeNull()
        ->and($row['messages_count'])->toBe(1);
});

it('keeps BPLO\'s door open whatever the routing says', function () {
    $filing = unroutedFiling();

    ApplicationAssignment::create([
        'application_id' => $filing->id,
        'department_id' => office('BPLO')->id,
        'officer_user_id' => User::where('email', 'bplo@biztrack.local')->value('id'),
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    /*
     * BPLO coordinates every filing and is who you write to when you do not
     * know which office to ask. An owner with nothing registered at all has no
     * other way in, which is the case the enquiry was built for.
     */
    expect(inbox('owner@biztrack.local')->where('kind', 'general')->pluck('responsible_office.code'))
        ->toContain('BPLO');
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

    $fire = inbox('fire@biztrack.local')->where('kind', 'general');
    expect($fire)->toHaveCount(1)
        ->and($fire->first()['last_message']['body'])->toBe('Fire office only.');

    /*
     * The half that has to stay shut. This read BPLO's post hard-coded, so
     * before the change the fire office would have been shown BPLO's mail and
     * not its own — a leak and a blackout in the same line.
     */
    $health = inbox('sanitary@biztrack.local')->where('kind', 'general');
    expect($health)->toHaveCount(0);
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

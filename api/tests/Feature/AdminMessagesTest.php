<?php

use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;

/*
 * ── The office's line to the System Administrator ──────────────────────────
 *
 * Office accounts cannot edit their own details any more: no Settings, no
 * "Edit your details". Their name, mobile number, office and role are the
 * super admin's to set — the officer directory is the record, and a record
 * people can quietly edit about themselves is not one [client, 28 September
 * 2026: *"if they want to change theyre info they could message super admin"*].
 *
 * That only works if there is somewhere to ask, and if the super admin has
 * somewhere to read it. This is both ends of that.
 *
 * It needed no migration: `message_threads` already had the three columns that
 * tell the kinds apart, and this is the combination nothing used yet — a
 * `user_id` with no department, because the super admin belongs to no office.
 */

function officer(string $email = 'sanitary@biztrack.local'): User
{
    return User::where('email', $email)->firstOrFail();
}

it('opens a conversation the first time an officer writes', function () {
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'Please correct my surname to Reyes-Cruz.'])
        ->assertCreated();

    $thread = MessageThread::where('kind', MessageThread::KIND_ADMIN)
        ->where('user_id', officer()->id)
        ->first();

    expect($thread)->not->toBeNull()
        ->and(Message::where('thread_id', $thread->id)->count())->toBe(1);
});

it('lets the super admin read it and reply', function () {
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'Please correct my surname.'])
        ->assertCreated();

    $id = officer()->id;

    $read = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/admin-messages/{$id}")
        ->assertOk()
        ->json('data');

    expect($read)->toHaveCount(1)
        ->and($read[0]['body'])->toBe('Please correct my surname.');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin-messages/{$id}", ['body' => 'Done — check your profile.'])
        ->assertCreated();

    $back = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/admin-messages')
        ->assertOk()
        ->json('data');

    expect($back)->toHaveCount(2)
        ->and($back[1]['body'])->toBe('Done — check your profile.');
});

it('keeps one officer out of another officer’s conversation', function () {
    /*
     * The thing that makes this private. Without the ownership test, any
     * office account could read what a colleague asked the administrator —
     * which on a screen about somebody's own details is the whole of it.
     */
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'A private matter.'])
        ->assertCreated();

    $id = officer()->id;

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson("/api/v1/admin-messages/{$id}")
        ->assertForbidden();

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/admin-messages/{$id}", ['body' => 'Butting in.'])
        ->assertForbidden();
});

it('refuses a business owner, who has BPLO to write to instead', function () {
    /*
     * `department_id` is the whole test, and it is the honest one: an office
     * account has one, a business owner has none, and neither does the super
     * admin — who would otherwise be opening a conversation with themselves.
     */
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'Hello?'])
        ->assertForbidden();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin-messages')
        ->assertForbidden();
});

it('pins the administrator row to the top of an officer’s inbox', function () {
    /*
     * PINNED, not sorted by date. An officer who has never needed to ask would
     * otherwise find it below thirty filings — and the reason it exists is
     * that they cannot change their own details and have to be able to find
     * where to ask.
     */
    $rows = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/message-threads')
        ->assertOk()
        ->json('data');

    expect($rows[0]['kind'])->toBe('admin')
        ->and($rows[0]['counterparty']['name'])->toBe('Super Administrator');
});

it('shows the row before anything has been said in it', function () {
    // The row IS the way in. A list built from threads would hide every
    // officer who has not spoken yet, which is all of them on day one.
    MessageThread::where('kind', MessageThread::KIND_ADMIN)->delete();

    $rows = collect(
        test()->withHeaders(authAs('fire@biztrack.local'))
            ->getJson('/api/v1/message-threads')
            ->assertOk()
            ->json('data')
    );

    $row = $rows->firstWhere('kind', 'admin');

    expect($row)->not->toBeNull()
        ->and($row['thread_id'])->toBeNull()
        ->and($row['messages_count'])->toBe(0);
});

it('does not pin it for a business owner', function () {
    // They message BPLO, which is what a general enquiry is.
    $rows = collect(
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->getJson('/api/v1/message-threads')
            ->assertOk()
            ->json('data')
    );

    expect($rows->firstWhere('kind', 'admin'))->toBeNull();
});

it('counts the pinned row in the total, so the screen agrees with itself', function () {
    // "Showing 4 of 3" is what happens when a row is merged in and not counted.
    $body = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/message-threads')
        ->assertOk()
        ->json();

    expect(count($body['data']))->toBeLessThanOrEqual($body['meta']['total']);
});

it('lists every office account for the super admin, not just the talkative ones', function () {
    /*
     * The super admin has to be able to START one too — that is how "your
     * details have been updated" reaches the officer who asked.
     */
    $rows = collect(
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson('/api/v1/admin/staff-messages')
            ->assertOk()
            ->json('data')
    );

    $everyOfficeAccount = User::whereNotNull('department_id')->where('is_active', true)->count();

    expect($rows)->toHaveCount($everyOfficeAccount)
        ->and($rows->firstWhere('user.email', 'sanitary@biztrack.local'))->not->toBeNull();

    foreach ($rows as $row) {
        expect($row['office'])->not->toBeNull();
    }
});

it('sorts whoever has written above whoever has not', function () {
    // An alphabetical list would bury a question under thirty officers who
    // have never said anything.
    test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'My office code is wrong.'])
        ->assertCreated();

    $rows = collect(
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson('/api/v1/admin/staff-messages')
            ->assertOk()
            ->json('data')
    );

    expect($rows->first()['user']['email'])->toBe('cenro@biztrack.local')
        ->and($rows->first()['preview'])->toContain('office code is wrong');
});

it('counts what the super admin has not read, and stops counting once read', function () {
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'Unread until opened.'])
        ->assertCreated();

    $unread = fn () => collect(
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson('/api/v1/admin/staff-messages')->json('data')
    )->firstWhere('user.email', 'sanitary@biztrack.local')['unread_count'];

    expect($unread())->toBe(1);

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin-messages/'.officer()->id)
        ->assertOk();

    expect($unread())->toBe(0);
});

it('keeps the super admin’s inbox away from everyone else', function () {
    foreach (['sanitary@biztrack.local', 'owner@biztrack.local', 'bplo@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))
            ->getJson('/api/v1/admin/staff-messages')
            ->assertForbidden();
    }
});

it('tells the super admin when an officer writes', function () {
    // Without this the officer would be waiting on a screen nobody had been
    // told to open.
    $admin = User::where('email', 'admin@biztrack.local')->firstOrFail();
    $before = $admin->notifications()->count();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'Please update my mobile number.'])
        ->assertCreated();

    expect($admin->notifications()->count())->toBeGreaterThan($before);

    $newest = $admin->notifications()->latest('id')->first();
    /*
     * `/admin/office-messages`, not `/admin/messages`.
     *
     * The super admin does not hold `message.participate`, so the ordinary
     * Messages page under their prefix could only ever render "You do not have
     * permission" - which is what a notification link pointing there did. The
     * screen has its own route now, and the old path redirects for the
     * notifications written before the rename.
     */
    expect($newest->title)->toContain('sent you a message')
        ->and($newest->link)->toBe('/admin/office-messages');
});

it('tells the officer when the administrator replies', function () {
    $sanitary = officer();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'A question.'])
        ->assertCreated();

    $before = $sanitary->notifications()->count();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin-messages/{$sanitary->id}", ['body' => 'An answer.'])
        ->assertCreated();

    expect($sanitary->notifications()->count())->toBeGreaterThan($before);
    expect($sanitary->notifications()->latest('id')->first()->link)->toBe('/messages');
});

it('does not turn a filing conversation into an administrator one', function () {
    /*
     * The three kinds are told apart by which columns are set. This pins that
     * the new query cannot pick up a thread that belongs to a filing — which
     * would put another office's conversation on the super admin's screen.
     */
    $filingThreads = MessageThread::whereNotNull('application_id')->count();
    expect($filingThreads)->toBeGreaterThan(0);

    $adminThreads = MessageThread::where('kind', MessageThread::KIND_ADMIN)->count();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/admin-messages', ['body' => 'One more.'])
        ->assertCreated();

    expect(MessageThread::where('kind', MessageThread::KIND_ADMIN)->count())
        ->toBe($adminThreads + 1)
        ->and(MessageThread::whereNotNull('application_id')->count())->toBe($filingThreads);
});

it('does not exempt the pinned row from the inbox filters', function () {
    /*
     * A reader who asks for "Unread" has asked to see less, and a filter that
     * returns a read row is a filter that lies. The pin decides WHERE the row
     * sits, not whether the narrowing applies to it.
     */
    $rows = collect(
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson('/api/v1/message-threads?narrow=unread')
            ->assertOk()
            ->json('data')
    );

    foreach ($rows as $row) {
        expect($row['unread_count'])->toBeGreaterThan(0);
    }

    // And once there IS something unread in it, it comes back.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin-messages/'.officer()->id, ['body' => 'Your surname is fixed.'])
        ->assertCreated();

    $withUnread = collect(
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson('/api/v1/message-threads?narrow=unread')
            ->assertOk()
            ->json('data')
    );

    expect($withUnread->firstWhere('kind', 'admin'))->not->toBeNull();
});

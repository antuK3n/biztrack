<?php

use App\Models\Department;
use App\Models\User;

/*
 * The nav badge: "is there anything waiting for me?"
 *
 * `messages.read_at` existed and nothing in the app ever wrote to it or read it
 * back, and the notification bell had a working `meta.unread` behind it that no
 * screen drew. So a message or a notification arrived and the only way to find
 * out was to go and look.
 *
 * The counts are scoped exactly like the conversations themselves. A badge that
 * counted mail the reader may not open would leak the existence of it — the
 * silhouette that item 111 was about, reduced to a number.
 */

it('counts a message waiting for the applicant, and clears it when they read it', function () {
    $ownerToken = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    // Nothing waiting to begin with.
    $this->withToken($ownerToken)->getJson('/api/v1/unread-summary')
        ->assertOk()->assertJsonPath('data.messages', 0);

    // The applicant writes to BPLO. Their own turn is not unread to them.
    $this->withToken($ownerToken)
        ->postJson('/api/v1/general-messages', ['body' => 'A question.'])->assertCreated();

    $this->withToken($ownerToken)->getJson('/api/v1/unread-summary')
        ->assertOk()->assertJsonPath('data.messages', 0);

    // BPLO answers.
    $owner = User::where('email', 'owner@biztrack.local')->first();
    $bploToken = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($bploToken)
        ->postJson("/api/v1/general-messages/{$owner->id}", ['body' => 'Here is the answer.'])
        ->assertCreated();

    // Now it is waiting.
    $this->app['auth']->forgetGuards();
    $this->withToken($ownerToken)->getJson('/api/v1/unread-summary')
        ->assertOk()->assertJsonPath('data.messages', 1);

    // Opening the conversation is reading it.
    $this->withToken($ownerToken)->getJson('/api/v1/general-messages')->assertOk();

    $this->withToken($ownerToken)->getJson('/api/v1/unread-summary')
        ->assertOk()->assertJsonPath('data.messages', 0);
});

it('does not count mail addressed to another office', function () {
    $ownerToken = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($ownerToken)
        ->postJson('/api/v1/general-messages', ['body' => 'For BPLO only.'])->assertCreated();

    /*
     * Measured as a change, not an absolute. The demo seeder writes
     * correspondence of its own, so BPLO's baseline is not zero and asserting a
     * literal here would be asserting the seeder.
     */
    $bploToken = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();
    $bplo = $this->withToken($bploToken)->getJson('/api/v1/unread-summary')
        ->assertOk()->json('data.messages');

    $choToken = loginToken('sanitary@biztrack.local');
    $this->app['auth']->forgetGuards();
    $choBefore = $this->withToken($choToken)->getJson('/api/v1/unread-summary')
        ->assertOk()->json('data.messages');

    // One more arrives for BPLO. The health office's count must not move.
    $this->app['auth']->forgetGuards();
    $this->withToken($ownerToken)
        ->postJson('/api/v1/general-messages', ['body' => 'Another for BPLO.'])->assertCreated();

    $this->app['auth']->forgetGuards();
    expect($this->withToken($bploToken)->getJson('/api/v1/unread-summary')->json('data.messages'))
        ->toBe($bplo + 1);

    $this->app['auth']->forgetGuards();
    expect($this->withToken($choToken)->getJson('/api/v1/unread-summary')->json('data.messages'))
        ->toBe($choBefore);
});

it('does not count one applicant’s mail for another', function () {
    $ownerToken = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($ownerToken)
        ->postJson('/api/v1/general-messages', ['body' => 'Mine.'])->assertCreated();

    $owner = User::where('email', 'owner@biztrack.local')->first();
    $bploToken = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($bploToken)
        ->postJson("/api/v1/general-messages/{$owner->id}", ['body' => 'Reply to you.'])
        ->assertCreated();

    /*
     * Juan's count is unmoved by a reply into somebody else's enquiry. Taken as
     * a delta for the same reason as above — the seeder gives him filings of
     * his own, so his baseline is his, not zero.
     */
    $juanToken = loginToken('juan@biztrack.local');
    $this->app['auth']->forgetGuards();
    $before = $this->withToken($juanToken)->getJson('/api/v1/unread-summary')
        ->assertOk()->json('data.messages');

    $this->app['auth']->forgetGuards();
    $this->withToken($bploToken)
        ->postJson("/api/v1/general-messages/{$owner->id}", ['body' => 'And another.'])
        ->assertCreated();

    $this->app['auth']->forgetGuards();
    expect($this->withToken($juanToken)->getJson('/api/v1/unread-summary')->json('data.messages'))
        ->toBe($before);
});

it('reports unread notifications alongside messages', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $owner = User::where('email', 'owner@biztrack.local')->first();
    $owner->notifications()->create([
        'type' => 'test',
        'title' => 'Something happened',
        'body' => 'Body',
    ]);

    $this->withToken($token)->getJson('/api/v1/unread-summary')
        ->assertOk()
        ->assertJsonPath('data.notifications', 1)
        ->assertJsonPath('data.messages', 0);

    $this->withToken($token)->postJson('/api/v1/notifications/read-all')->assertOk();

    $this->withToken($token)->getJson('/api/v1/unread-summary')
        ->assertOk()->assertJsonPath('data.notifications', 0);
});

it('refuses an unread count with no token', function () {
    $this->getJson('/api/v1/unread-summary')->assertUnauthorized();
    expect(Department::count())->toBeGreaterThan(0);
});

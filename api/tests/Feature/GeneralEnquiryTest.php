<?php

use App\Models\Department;
use App\Models\MessageThread;
use App\Models\User;

/*
 * Writing to BPLO without a filing.
 *
 * `message_threads` was keyed on an application, so the only way to reach an
 * office was to have already filed — while the app told people the opposite:
 * "Your email is your sign-in ID and can't be changed here. Contact the City
 * BPLO to update it." There was no contact to make. See migration
 * 2026_09_03_000020.
 */

it('lets an applicant with no filing write to BPLO', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson('/api/v1/general-messages', ['body' => 'Can I change the email I sign in with?'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Can I change the email I sign in with?');

    $owner = User::where('email', 'owner@biztrack.local')->first();
    $thread = MessageThread::whereNull('application_id')->where('user_id', $owner->id)->first();

    expect($thread)->not->toBeNull()
        ->and($thread->department->code)->toBe('BPLO');
});

it('keeps one enquiry thread however many times the applicant writes', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    foreach (['first question', 'second question', 'third question'] as $body) {
        $this->withToken($token)
            ->postJson('/api/v1/general-messages', ['body' => $body])
            ->assertCreated();
    }

    $owner = User::where('email', 'owner@biztrack.local')->first();

    // `(user_id, department_id)` is unique, so this is the schema's guarantee
    // and not merely the controller's good behaviour.
    expect(MessageThread::whereNull('application_id')->where('user_id', $owner->id)->count())->toBe(1);

    $this->withToken($token)
        ->getJson('/api/v1/general-messages')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

it('gives an applicant a way in before they have written anything', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $rows = $this->withToken($token)->getJson('/api/v1/message-threads')->assertOk()->json('data');

    $general = collect($rows)->firstWhere('kind', 'general');

    // Present, but not yet a row in the table: a GET must not write.
    expect($general)->not->toBeNull()
        ->and($general['thread_id'])->toBeNull()
        ->and($general['application_id'])->toBeNull()
        ->and($general['messages_count'])->toBe(0);

    expect(MessageThread::whereNull('application_id')->count())->toBe(0);
});

it('shows BPLO an enquiry it has been sent', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->postJson('/api/v1/general-messages', ['body' => 'How do I change my email?'])
        ->assertCreated();

    $bploToken = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();

    $rows = $this->withToken($bploToken)->getJson('/api/v1/message-threads')->assertOk()->json('data');
    $general = collect($rows)->firstWhere('kind', 'general');

    expect($general)->not->toBeNull()
        ->and($general['counterparty']['name'])->toBe('Nena Dela Cruz')
        ->and($general['messages_count'])->toBe(1);
});

it('does not show what was said to one office to another', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->postJson('/api/v1/general-messages', ['body' => 'A question for BPLO only.'])
        ->assertCreated();

    $sanitaryToken = loginToken('sanitary@biztrack.local');
    $this->app['auth']->forgetGuards();

    $rows = collect(
        $this->withToken($sanitaryToken)->getJson('/api/v1/message-threads')->assertOk()->json('data')
    )->where('kind', 'general');

    /*
     * ---- What this test stopped claiming, and what it claims now --------
     *
     * It asserted the sanitary office saw NO general row at all, which was
     * right while an office's list was built from threads: no thread with
     * them, no row.
     *
     * An office's list is built from the OWNERS it may hear from now, whether
     * or not anybody has written [client, 1 October 2026] - the row is how the
     * office opens the conversation, so it has to exist before there is
     * anything in it. An empty row is therefore expected and is not a leak.
     *
     * The leak is the CONTENT, and that is what is asserted: every row the
     * sanitary office sees is addressed to the sanitary office, holds no
     * thread of BPLO's and none of its words.
     */
    foreach ($rows as $row) {
        expect($row['responsible_office']['code'])->toBe('CHO')
            ->and($row['messages_count'])->toBe(0)
            ->and($row['last_message'])->toBeNull();
    }

    // And BPLO's own row is unaffected: the words are still there for them.
    $bploToken = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();

    $bplo = collect(
        $this->withToken($bploToken)->getJson('/api/v1/message-threads')->assertOk()->json('data')
    )->where('kind', 'general')->firstWhere('messages_count', 1);

    expect($bplo)->not->toBeNull()
        ->and($bplo['last_message']['body'])->toBe('A question for BPLO only.');
});

it('refuses one applicant the enquiry of another', function () {
    $ownerToken = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($ownerToken)
        ->postJson('/api/v1/general-messages', ['body' => 'Something private.'])
        ->assertCreated();

    $owner = User::where('email', 'owner@biztrack.local')->first();

    /*
     * The leak this test exists for: readsThreadOf() answers true for ANY
     * reader without application.view_all, because on a filing the applicant
     * authors every sheet and ownership is established elsewhere. There is no
     * filing here to establish it, so the controller checks user_id itself. If
     * that check is ever removed, this goes red.
     */
    $juanToken = loginToken('juan@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($juanToken)
        ->getJson("/api/v1/general-messages/{$owner->id}")
        ->assertForbidden();

    $this->withToken($juanToken)
        ->postJson("/api/v1/general-messages/{$owner->id}", ['body' => 'Reading your mail.'])
        ->assertForbidden();

    // And their own inbox shows only their own enquiry.
    $rows = $this->withToken($juanToken)->getJson('/api/v1/message-threads')->assertOk()->json('data');
    $general = collect($rows)->firstWhere('kind', 'general');
    expect($general['messages_count'])->toBe(0);
});

it('lets BPLO reply into an applicant enquiry', function () {
    $ownerToken = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($ownerToken)
        ->postJson('/api/v1/general-messages', ['body' => 'Can I change my email?'])
        ->assertCreated();

    $owner = User::where('email', 'owner@biztrack.local')->first();

    $bploToken = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($bploToken)
        ->postJson("/api/v1/general-messages/{$owner->id}", ['body' => 'Come to the BPLO counter with a valid ID.'])
        ->assertCreated();

    // The applicant sees both turns in one conversation.
    $this->app['auth']->forgetGuards();
    $body = $this->withToken($ownerToken)->getJson('/api/v1/general-messages')->assertOk();

    $body->assertJsonPath('meta.total', 2);
    expect(collect($body->json('data'))->pluck('body')->all())
        ->toBe(['Can I change my email?', 'Come to the BPLO counter with a valid ID.']);
});

it('refuses an enquiry with no token', function () {
    $this->getJson('/api/v1/general-messages')->assertUnauthorized();
    $this->postJson('/api/v1/general-messages', ['body' => 'hello'])->assertUnauthorized();
});

it('refuses an empty enquiry', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson('/api/v1/general-messages', ['body' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('body');
});

it('opens an owner’s enquiry on BPLO, whoever spoke last', function () {
    /*
     * "Make it on messages page naka default lagi sa BPLO" [client, 1 October
     * 2026].
     *
     * This answered with whichever office had spoken last, on the reasoning
     * that an applicant is coming back to the conversation they were having.
     * The screen opens on BPLO regardless now, and a server that disagreed
     * would be fetched twice for every visit — once for the office that spoke
     * last, once more when the picker settled — showing one conversation and
     * replacing it with another as the reader looked at it.
     */
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $fire = Department::where('code', 'BFP')->value('id');

    $this->withToken($token)->postJson('/api/v1/general-messages', [
        'department_id' => $fire,
        'body' => 'The most recent thing said, and not to BPLO.',
    ])->assertCreated();

    $meta = $this->withToken($token)->getJson('/api/v1/general-messages')->assertOk()->json('meta');

    expect($meta['department_id'])->toBe(Department::where('code', 'BPLO')->value('id'));

    // And the fire office is still one press away, with its count on it.
    $offices = collect($meta['offices'])->keyBy('code');
    expect($offices['BFP']['messages_count'])->toBe(1);
});

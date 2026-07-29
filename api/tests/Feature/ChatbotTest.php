<?php

use App\Models\Application;
use App\Models\User;

function ownedTrackingId(string $email): string
{
    $user = User::where('email', $email)->firstOrFail();

    return Application::where('applicant_user_id', $user->id)->firstOrFail()->tracking_id;
}

it('rejects unauthenticated chatbot access', function () {
    $this->getJson('/api/v1/chatbot/messages')->assertUnauthorized();
    $this->postJson('/api/v1/chatbot/messages', ['message' => 'hi'])->assertUnauthorized();
});

it('answers the requirements intent from the seeded checklists', function () {
    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'Ano kailangan na documents?'])
        ->assertCreated()
        ->assertJsonPath('data.sender', 'bot')
        ->json('data.body');

    expect($body)->toContain("Mayor's / Business Permit")
        ->toContain('Barangay Business Clearance')
        ->toContain('Fire Safety Inspection Certificate');
});

it('answers the fees intent with surcharge and interest', function () {
    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'Magkano ang bayad?'])
        ->assertCreated()
        ->json('data.body');

    expect($body)->toContain('Tax Order of Payment')
        ->toContain('25% surcharge')
        ->toContain('2% interest');
});

it('answers the renewal intent with the January window', function () {
    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'When is the renewal deadline?'])
        ->assertCreated()
        ->json('data.body');

    expect($body)->toContain('first 20 days of January');
});

it('answers the offices intent with the issuing departments', function () {
    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'Which office reviews my application?'])
        ->assertCreated()
        ->json('data.body');

    expect($body)->toContain('Business Permits and Licensing Office')
        ->toContain('Bureau of Fire Protection')
        ->toContain('City Environment and Natural Resources Office');
});

it('answers the hours intent with RA 11032', function () {
    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'How long until release?'])
        ->assertCreated()
        ->json('data.body');

    expect($body)->toContain('RA 11032')->toContain('10 working days');
});

it('greets back and falls back gracefully', function () {
    $greet = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'Kumusta!'])
        ->assertCreated()
        ->json('data.body');
    expect($greet)->toContain('Kumusta');

    $fallback = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'zzz qwerty'])
        ->assertCreated()
        ->json('data.body');
    expect($fallback)->toContain('message your assigned office');
});

it('looks up the status of the user\'s own tracking id', function () {
    $tracking = ownedTrackingId('owner@biztrack.local');

    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => "Asan na ang {$tracking}?"])
        ->assertCreated()
        ->json('data.body');

    expect($body)->toContain($tracking)->toContain('currently:');
});

it('refuses to reveal another user\'s tracking id status', function () {
    // juan@ owns the RxCare application; owner@ must not see its status.
    $foreign = ownedTrackingId('juan@biztrack.local');

    $body = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => "Track {$foreign} please"])
        ->assertCreated()
        ->json('data.body');

    expect($body)->toContain('could not find')
        ->not->toContain('currently:');
});

it('persists the exchange and returns it on GET', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'hello'])
        ->assertCreated();

    $data = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/chatbot/messages')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(2);
    expect($data[0]['sender'])->toBe('user');
    expect($data[0]['body'])->toBe('hello');
    expect($data[1]['sender'])->toBe('bot');

    // Another user's history is empty (self-scoped conversations).
    $other = $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson('/api/v1/chatbot/messages')
        ->assertOk()
        ->json('data');
    expect($other)->toHaveCount(0);
});

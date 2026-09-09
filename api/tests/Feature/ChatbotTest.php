<?php

use App\Models\Application;
use App\Models\ChatbotConversation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

function ownedTrackingId(string $email): string
{
    $user = User::where('email', $email)->firstOrFail();

    return Application::where('applicant_user_id', $user->id)->firstOrFail()->tracking_id;
}

/** Ask the bot as the demo owner and return the reply body. */
function ask(string $message, string $email = 'owner@biztrack.local'): string
{
    return test()->withHeaders(authAs($email))
        ->postJson('/api/v1/chatbot/messages', ['message' => $message])
        ->assertCreated()
        ->json('data.body');
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

    /*
      * The tiers, not a flat figure. This assertion used to pin "10 working
      * days" — a number in neither RA 11032 nor Ra11032::TIERS — which is how
      * a wrong statutory deadline stayed in an answer given to applicants.
      */
    expect($body)->toContain('RA 11032')
        ->toContain('3 working days for simple')
        ->toContain('7 working days for complex')
        ->toContain('20 working days for highly technical')
        ->not->toContain('10 working days');
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

// --- entity scoping: answer the permit that was named, not all of them -------

it('scopes the requirements answer to the permit type that was named', function () {
    $body = ask('what documents do I need for a sanitary permit');

    expect($body)->toContain('Sanitary Permit / Health Certificate')
        ->toContain('Sanitary Requirements')
        // The whole point of the bug: no rundown of the other permits.
        ->not->toContain('Occupancy Permit')
        ->not->toContain('Barangay Business Clearance');
});

it('scopes requirements for Taglish and abbreviated permit names', function () {
    expect(ask('ano ang requirements para sa bumbero permit?'))
        ->toContain('Fire Safety Inspection Certificate')
        ->not->toContain('Sanitary Requirements');

    expect(ask('health cert requirements'))
        ->toContain('Sanitary Permit / Health Certificate')
        ->not->toContain('Fire Safety Requirements');

    expect(ask('CENRO requirements'))
        ->toContain('City Environmental Certificate')
        ->not->toContain('Fire Safety Requirements');
});

it('answers zoning questions with the planning office, not a permit rundown', function () {
    expect(ask('kailangan ba ng zoning clearance?'))
        ->toContain('Zoning')
        ->toContain('City Planning')
        ->not->toContain('Sanitary Requirements');
});

it('still lists every checklist when the question really is that broad', function () {
    $body = ask('give me all the requirements for all permits');

    expect($body)->toContain("Mayor's / Business Permit")
        ->toContain('Sanitary Permit / Health Certificate')
        ->toContain('Fire Safety Inspection Certificate')
        ->toContain('Occupancy Permit')
        ->toContain('Zoning / Locational Clearance');
});

it('scopes fees to the named permit and separates late-payment penalties', function () {
    $sanitary = ask('magkano ang sanitary permit');
    expect($sanitary)->toContain('Sanitary Permit / Health Certificate')
        ->toContain('Tax Order of Payment');

    $penalty = ask('how much is the penalty if I pay late?');
    expect($penalty)->toContain('25% surcharge')
        ->toContain('2% interest')
        ->not->toContain('Malabon Revenue Code');
});

it('never quotes a peso figure for a permit fee', function () {
    // permit_types.base_fee is a legacy fallback, not what the applicant is
    // billed: the Tax Order of Payment comes out of the revenue-code rules.
    $questions = [
        'magkano ang sanitary permit',
        'how much is the fire safety fee',
        "how much is the mayor's permit",
        'how much is the zoning clearance',
        'Magkano ang bayad?',
    ];

    foreach ($questions as $question) {
        expect(ask($question))->not->toContain('₱');
    }
});

it('explains the FSIC as a percentage of the other fees, from the fire code rule', function () {
    $body = ask('how much is the fire safety fee');

    expect($body)->toContain('Fire Safety Inspection Certificate')
        ->toContain('10%')
        ->toContain('RA 9514');
});

it('names what actually drives a permit fee', function () {
    expect(ask("how much is the mayor's permit"))
        ->toContain('gross sales')
        ->toContain('Tax Order of Payment');

    /*
     * Was "how much is the market clearance" → "stalls". The Market Clearance
     * was removed on 6 September 2026, but the stall-count driver did not go
     * with it: five revenue-code rules still price a BUSINESS permit per stall
     * for the operator who RUNS a market. The occupancy fee is the equivalent
     * check on a permit that still exists — a named permit whose driver is a
     * measurement rather than gross sales.
     */
    expect(ask('how much is the occupancy permit'))->toContain('floor area');
});

it('reads "how much to pay" as a fee question, not a how-to-pay question', function () {
    expect(ask('how much to pay?'))
        ->toContain('Malabon Revenue Code')
        ->not->toContain('Pay online button');
});

it('answers payment method questions with the accepted methods', function () {
    expect(ask('can I pay with gcash?'))
        ->toContain('GCash')
        ->toContain('Maya');
});

it('scopes the offices answer to the named permit', function () {
    // Was the Market Clearance / City Market Administrator pair, removed on
    // 6 September 2026. Any permit with its own office proves the same rule.
    $body = ask('who handles the zoning clearance?');

    expect($body)->toContain('City Planning and Development Office')
        ->not->toContain('Bureau of Fire Protection');
});

it('scopes processing time to the named permit and keeps the RA 11032 rule', function () {
    $body = ask('how long does the sanitary permit take?');

    expect($body)->toContain('City Health Office')
        ->toContain('inspection')
        ->toContain('3 working days for simple')
        ->not->toContain('10 working days')
        ->not->toContain('Office of the Building Official');
});

it('scopes renewal answers to the named permit validity', function () {
    $body = ask('kailan mag-expire ang fsic ko?');

    expect($body)->toContain('Fire Safety Inspection Certificate')
        ->toContain('365 days')
        ->toContain('first 20 days of January');
});

it('answers a bare permit name with what it can tell you about it', function () {
    $body = ask('sanitary permit');

    expect($body)->toContain('Sanitary Permit / Health Certificate')
        ->toContain('City Health Office')
        ->not->toContain('Fire Safety Requirements');
});

// --- status: real data, still self-scoped ------------------------------------

it('lists only the asker\'s own applications for a status question', function () {
    $own = ownedTrackingId('owner@biztrack.local');
    $foreign = ownedTrackingId('juan@biztrack.local');

    $body = ask('what is the status of my applications?');

    expect($body)->toContain($own)->not->toContain($foreign);
});

// --- input it cannot classify ------------------------------------------------

it('asks for a question instead of guessing on empty or junk input', function () {
    // Blank and whitespace-only never reach the responder.
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => ''])
        ->assertStatus(422);
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => '   '])
        ->assertStatus(422);

    expect(ask('???'))
        ->toContain('did not catch a question')
        ->not->toContain('Barangay Business Clearance');
});

it('does not dump a canned answer on input it cannot classify', function () {
    $body = ask('asdfgh lorem ipsum');

    expect($body)->toContain('did not quite get that')
        ->toContain('BIZ-2026-00123')
        ->not->toContain('Barangay Business Clearance')
        ->not->toContain('Malabon Revenue Code');
});

it('explains the tracking number format instead of guessing on a partial one', function () {
    $body = ask('BIZ-2026-1');

    expect($body)->toContain('BIZ-2026-00123')
        ->not->toContain('currently:');
});

// --- form fields: what a box on the form is for -------------------------------

it('explains a form field instead of offering the permit menu', function () {
    // The reported bug, verbatim: this used to come back as the permit menu.
    $body = ask('In the sanitary permit application, what is the water source for?');

    expect($body)->toContain('Water Source')
        ->toContain('Sanitary Permit sheet')
        ->toContain('Level III (Waterworks)')
        ->toContain('Deep Well')
        ->not->toContain('Which one would you like?');
});

it('explains the other sanitary form fields', function () {
    expect(ask('what is the sanitary classification field?'))
        ->toContain('Sanitary Classification')
        ->toContain('Food Establishment')
        ->toContain('Industrial');

    expect(ask('what do I put in no. of workers requiring health certificates?'))
        ->toContain('Workers Requiring Health Certificates')
        ->toContain('health certificate')
        ->toContain('0');
});

it('explains fields on the fire and occupancy sheets', function () {
    expect(ask('what is certificate applied for?'))
        ->toContain('Certificate Applied For')
        ->toContain('Permit Selection');

    expect(ask('what does application type mean on the occupancy permit form?'))
        ->toContain('occupancy scope')
        ->toContain('Full')
        ->toContain('Partial');

    expect(ask('what is the building permit no field for?'))
        ->toContain('Building Permit No.')
        ->toContain('Office of the Building Official');
});

it('explains the main wizard fields', function () {
    /*
     * The step named here has to be a step that exists. Line of Business used
     * to be a section of its own and is now asked on Location & Zoning, so an
     * answer still directing people to the old one would send an applicant
     * looking for a tab that is not in the map — the chatbot is read by people
     * who are already lost.
     */
    expect(ask('what is capital?'))->toContain('Capital')->toContain('Location & Zoning step');
    expect(ask('what is gross sales?'))->toContain('Gross Sales')->toContain('before you take any expenses off');
    expect(ask('what is line of business?'))->toContain('Line of Business')->toContain('PSIC');
    expect(ask('what should I enter for TIN?'))->toContain('Tax Identification Number')->toContain('123-456-789-000');
    expect(ask('what is floor area for?'))->toContain('Floor Area')->toContain('square metres');
});

it('answers a bare field name without a question word', function () {
    expect(ask('water source'))->toContain('Water Source')->toContain('Deep Well');
});

it('says it does not know a field rather than inventing one', function () {
    $body = ask('what is the hazard grading field on the sanitary form for?');

    expect($body)->toContain('do not have a note on that field')
        ->toContain('City Health Office')
        ->not->toContain('Which one would you like?');
});

it('keeps permit questions out of the field layer', function () {
    // "how much" is still a fee question even though gross sales is a field.
    expect(ask('how much is the sanitary permit'))
        ->toContain('Sanitary Permit / Health Certificate')
        ->toContain('Tax Order of Payment')
        ->not->toContain('Business & Tax Profile step');

    expect(ask('what documents do I need for a sanitary permit'))
        ->toContain('Sanitary Requirements')
        ->not->toContain('do not have a note on that field');
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

it('returns the stored user turn alongside the reply', function () {
    // The panel draws the user's bubble before the round trip; it needs the row
    // id back so a later history load does not show the same turn twice.
    $meta = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'what is floor area for?'])
        ->assertCreated()
        ->json('meta.user_message');

    expect($meta['sender'])->toBe('user');
    expect($meta['body'])->toBe('what is floor area for?');
    expect($meta['id'])->toBeInt();
});

it('keeps the whole thread across separate requests and a new token', function () {
    // Three turns, each its own request, exactly as a reload or a re-login does.
    foreach (['what is the water source for?', 'requirements for a sanitary permit', 'hello'] as $message) {
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->postJson('/api/v1/chatbot/messages', ['message' => $message])
            ->assertCreated();
    }

    // authAs() mints a fresh token, so this reads back the way a re-login does.
    $data = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/chatbot/messages')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(6);
    expect(array_column($data, 'body'))->toContain('what is the water source for?')
        ->toContain('requirements for a sanitary permit')
        ->toContain('hello');
    expect(array_column($data, 'sender'))->toBe(['user', 'bot', 'user', 'bot', 'user', 'bot']);

    // One thread per user, and it is the one the reads come back from.
    expect(ChatbotConversation::where(
        'user_id',
        User::where('email', 'owner@biztrack.local')->value('id'),
    )->count())->toBe(1);
});

it('never splits a user thread across two conversations', function () {
    $userId = User::where('email', 'owner@biztrack.local')->value('id');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'hello'])
        ->assertCreated();

    // A second conversation row is what used to hide half a thread from GET.
    expect(fn () => ChatbotConversation::create([
        'user_id' => $userId,
        'started_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

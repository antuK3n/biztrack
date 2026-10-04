<?php

use App\Models\Application;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Models\User;
use App\Support\PaymentMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

    /*
     * Asserted against the paper's list, which is what the seeded checklist
     * holds since 16 September 2026 — MCG-BPLO-FO-001 prints six documentary
     * requirements and does NOT include the Barangay Business Clearance this
     * test used to look for (questions-for-malabon E9, answered).
     *
     * The conditional markers are asserted too, because the bot reads the whole
     * checklist flat: without them it would recite "Contract of Lease" and
     * "Tax Declaration/Transfer Certificate of Title" one after the other,
     * and no applicant needs both.
     *
     * The names here are the paper's own wording. BusinessPermitRequirementList
     * is where that is pinned; this asserts the bot passes it through rather
     * than paraphrasing.
     */
    expect($body)->toContain("Mayor's / Business Permit")
        ->toContain('Proof of Business Registration (DTI / SEC / CDA)')
        ->toContain('Sketch and photos of location of business')
        ->toContain('if you pay rent for the premises')
        ->toContain('if you own the premises')
        ->toContain('Fire Safety Inspection Certificate')
        ->not->toContain('Barangay Business Clearance');

    /*
     * Certain requirements before conditional ones, and the optional one last.
     *
     * The bot recites the whole checklist as one run of bullets and cannot show
     * or hide a row the way the wizard does — it has no answers to go on — so
     * every conditional row is read out to everybody. Which makes the ordering
     * matter MORE here than on the screen: the two lines that are true for any
     * listener have to come first, ahead of the five qualified with "if" and
     * the one nobody is obliged to bring at all.
     */
    expect(strpos($body, 'Proof of Business Registration'))
        ->toBeLessThan(strpos($body, 'Sketch and photos'))
        ->and(strpos($body, 'Sketch and photos'))
        ->toBeLessThan(strpos($body, 'Contract of Lease'))
        ->and(strpos($body, 'Contract of Lease'))
        ->toBeLessThan(strpos($body, 'Tax Incentive Certificate'))
        // The optional one last, here as on the screen.
        ->and(strpos($body, 'Tax Incentive Certificate'))
        ->toBeLessThan(strpos($body, 'SPA / Authorization'));
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
        ->toContain('City Environmental and Natural Resources Office');
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

    expect($body)->toContain('Sanitary Permit')
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
        ->toContain('Sanitary Permit')
        ->not->toContain('Fire Safety Requirements');

    expect(ask('CENRO requirements'))
        ->toContain('City Environmental Certificate')
        ->not->toContain('Fire Safety Requirements');
});

it('answers zoning questions with the planning office, not a permit rundown', function () {
    expect(ask('kailangan ba ng zoning clearance?'))
        ->toContain('Zoning')
        ->toContain('Planning/Zoning Office')
        ->not->toContain('Sanitary Requirements');
});

it('still lists every checklist when the question really is that broad', function () {
    $body = ask('give me all the requirements for all permits');

    expect($body)->toContain("Mayor's / Business Permit")
        ->toContain('Sanitary Permit')
        ->toContain('Fire Safety Inspection Certificate')
        ->toContain('Occupancy Permit')
        ->toContain('Zoning Clearance');
});

it('scopes fees to the named permit and separates late-payment penalties', function () {
    $sanitary = ask('magkano ang sanitary permit');
    expect($sanitary)->toContain('Sanitary Permit')
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

it('tells owners how payment really works in the current payment mode', function () {
    // Simulated: nothing moves, and the BPLO counter is still a way to pay.
    expect(ask('can I pay cash over the counter?'))
        ->toContain('no real money moves')
        ->toContain('BPLO counter')
        ->not->toContain('There is no over-the-counter option');

    // Online payment switched on: no "simulated" claim, and the methods it offers.
    config([
        'payments.kwikpay.base_url' => 'https://kwikpay.invalid',
        'payments.kwikpay.merchant' => 'TEST',
        'payments.kwikpay.key' => 'test-key',
        'payments.kwikpay.payment_type' => 'TEST',
    ]);
    PaymentMode::set(PaymentMode::KWIKPAY);

    $method = ask('can I pay with gcash?');
    expect($method)->toContain('GCash')->toContain('QR Ph')->toContain('GoTyme')
        ->toContain('BPLO counter')
        ->not->toContain('no real money moves')
        ->not->toContain('Credit / Debit Card')
        ->not->toContain('There is no over-the-counter option');
    expect(ask('how do I pay?'))->not->toContain('simulated')->not->toContain('no real money moves');
});

it('scopes the offices answer to the named permit', function () {
    // Was the Market Clearance / City Market Administrator pair, removed on
    // 6 September 2026. Any permit with its own office proves the same rule.
    $body = ask('who handles the zoning clearance?');

    expect($body)->toContain('Planning/Zoning Office')
        ->not->toContain('Bureau of Fire Protection');
});

it('scopes processing time to the named permit and keeps the RA 11032 rule', function () {
    $body = ask('how long does the sanitary permit take?');

    expect($body)->toContain('City Health Office')
        ->toContain('inspection')
        ->toContain('3 working days for simple')
        ->not->toContain('10 working days')
        ->not->toContain('Office of the Local Building Official');
});

it('dates a permit the way it is issued: 20 January for the business permit, 31 December or a year for the rest', function () {
    /*
     * WorkflowService::issuePermitFor: the business permit ends on 20 January
     * of the year after it starts (RenewalSeason::endOfTermFor), a clearance's
     * first issue ends on 31 December of its year, and a renewed clearance
     * runs one year from the day it is renewed. This test used to pin "365
     * days" and "renew it with your business permit in January", read from
     * `validity_days`, which nothing dates a permit with any more.
     */
    $fsic = ask('kailan mag-expire ang fsic ko?');
    expect($fsic)->toContain('Fire Safety Inspection Certificate')
        ->toContain('expires on 31 December of the year it is first issued')
        ->toContain('a renewal runs one year from the day it is renewed')
        ->toContain('You can renew it from 30 days before it expires, on its own application.')
        ->not->toContain('365 days')
        ->not->toContain('first 20 days of January');

    $mayors = ask("when does the mayor's permit expire?");
    expect($mayors)->toContain("Mayor's / Business Permit expires on 20 January of the year after it is issued or renewed")
        ->toContain('first 20 days of January')
        ->not->toContain('365 days');
});

it('answers a bare permit name with what it can tell you about it', function () {
    $body = ask('sanitary permit');

    expect($body)->toContain('Sanitary Permit')
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

it('calls a paid filing still gathering its other permits Approved, not Completed', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $application = Application::where('applicant_user_id', $owner->id)
        ->whereNotNull('tracking_id')->orderByDesc('id')->firstOrFail();

    // Paid, and waiting on its clearances: `approved` with no decision yet.
    $application->forceFill(['status' => 'approved', 'decided_at' => null])->save();
    $label = "{$application->tracking_id} (".$application->business?->name.')';

    expect(ask($application->tracking_id))->toContain('is currently: Approved.');
    expect(ask('what is the status of my application?'))
        ->toContain("{$label}: Approved")
        ->not->toContain("{$label}: Completed");

    // Once the last permit is granted it has ended, and says so.
    $application->forceFill(['decided_at' => now()])->save();
    expect(ask($application->tracking_id))->toContain('is currently: Completed.');
});

it('reads its own starter "Where is my application?" as a status question', function () {
    // One of the four buttons the bubble shows every owner on first open; it
    // used to come back as "Sorry, I did not quite get that".
    $own = ownedTrackingId('owner@biztrack.local');

    expect(ask('Where is my application?'))
        ->toContain('Your most recent applications:')
        ->toContain($own)
        ->not->toContain('did not quite get that');

    // "where is" alone is not a status question: the office stays an office.
    expect(ask('where is the cpdo'))->not->toContain('Your most recent applications');
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

it('refuses an over-long message with one plain sentence about the length', function () {
    /*
     * The bubble shows a refusal's own sentence, so it has to be one an owner
     * can act on. Laravel's "The message field must not be greater than 2000
     * characters." names a field nobody sees.
     */
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => str_repeat('a', 2001)])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Please keep your message to 2,000 characters or fewer.');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => str_repeat('a', 2000)])
        ->assertCreated();
});

it('does not dump a canned answer on input it cannot classify', function () {
    $body = ask('asdfgh lorem ipsum');

    expect($body)->toContain('did not quite get that')
        ->toContain('BIZ-2026-00123')
        ->not->toContain('Barangay Business Clearance')
        ->not->toContain('Malabon Revenue Code');
});

it('looks up a six-digit tracking id as itself, not the five-digit one inside it', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    [$five, $six] = Application::where('applicant_user_id', $owner->id)
        ->whereNotNull('tracking_id')->orderBy('id')->take(2)->get()->all();
    $five->forceFill(['tracking_id' => 'BIZ-2026-12345', 'status' => 'rejected'])->save();
    $six->forceFill(['tracking_id' => 'BIZ-2026-123456', 'status' => 'for_approval'])->save();

    // Numbering pads to five digits and grows past them after 99,999 filings.
    expect(ask('status of BIZ-2026-123456 please'))
        ->toContain('Application BIZ-2026-123456 is currently: '.$six->fresh()->statusLabel().'.')
        ->not->toContain('BIZ-2026-12345 ');
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
        ->toContain('Office of the Local Building Official');
});

it('explains the main wizard fields', function () {
    /*
     * The step named here has to be a step that exists. Line of Business used
     * to be a section of its own and is now asked on Location & Zoning, so an
     * answer still directing people to the old one would send an applicant
     * looking for a tab that is not in the map — the chatbot is read by people
     * who are already lost.
     *
     * The same for the "Business & Tax Profile step", which the wizard no
     * longer has: business area, the employee counts, gross sales and the one
     * Capital Investment figure are all asked on Business Operation now
     * (ApplyWizard's `operation` section). Capital was asserted against
     * "Location & Zoning step", where a per-line capital used to be.
     */
    expect(ask('what is capital?'))->toContain('Capital Investment is item 6 in the Business Operation step');
    expect(ask('what is gross sales?'))->toContain('Gross Sales')->toContain('Business Operation step')->toContain('before you take any expenses off');
    expect(ask('what is line of business?'))->toContain('Line of Business')->toContain('PSIC');
    expect(ask('what should I enter for TIN?'))->toContain('Tax Identification Number')->toContain('123-456-789-000');
    expect(ask('what is floor area for?'))->toContain('Business Area (sq. m.)')->toContain('Business Operation step')->toContain('square metres');
    expect(ask('what is total number of employees?'))->toContain('item 2 in the Business Operation step');

    foreach (['capital', 'gross sales', 'floor area', 'total number of employees'] as $field) {
        expect(ask("what is {$field}?"))->not->toContain('Business & Tax Profile');
    }
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
        ->toContain('Sanitary Permit')
        ->toContain('Tax Order of Payment')
        ->not->toContain('Business Operation step');

    expect(ask('what documents do I need for a sanitary permit'))
        ->toContain('Sanitary Requirements')
        ->not->toContain('do not have a note on that field');
});

it('logs the intent, a confidence and the source beside every bot answer', function () {
    // UCR-07 step 3.1: each exchange is recorded with the detected intent and
    // a confidence score. The owner's own turn is not classified.
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $last = fn (string $sender) => ChatbotMessage::whereHas('conversation', fn ($q) => $q->where('user_id', $owner->id))
        ->where('sender', $sender)->latest('id')->firstOrFail();
    $logged = function (string $question) use ($last) {
        ask($question);
        $bot = $last('bot');

        return [$bot->intent, $bot->confidence, $bot->source];
    };

    expect($logged('requirements for a sanitary permit'))->toBe(['requirements', 0.9, 'rules']);
    expect($logged(ownedTrackingId('owner@biztrack.local')))->toBe(['status', 1.0, 'rules']);
    expect($logged('what is the water source for?'))->toBe(['field', 0.9, 'rules']);
    expect($logged('sanitary permit'))->toBe(['permit', 0.6, 'rules']);
    expect($logged('asdfgh lorem ipsum'))->toBe(['fallback', 0.0, 'rules']);

    // A single short keyword is a weaker match than a phrase naming a permit.
    [, $weak] = $logged('magkano?');
    expect($weak)->toBeLessThan(0.9)->toBeGreaterThan(0.0);

    $asked = $last('user');
    expect([$asked->intent, $asked->confidence, $asked->source])->toBe([null, null, null]);
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

/*
 * Two first messages racing: both find no conversation, the other request's
 * insert lands first, and this one's hits the unique index. The loser is meant
 * to re-read and carry on. On PostgreSQL a failed statement poisons the
 * transaction around it ("current transaction is aborted"), and the insert
 * runs inside the one that writes the two turns — so the re-read failed too
 * and the message was lost with a 500, unless the insert is fenced by a
 * savepoint. SQLite has no such rule, which is why this passed there.
 */
it('recovers when another request opens the conversation first', function () {
    $userId = User::where('email', 'owner@biztrack.local')->value('id');
    ChatbotConversation::where('user_id', $userId)->delete();

    // The other request, committing between this one's lookup (which found
    // nothing) and its insert. Written right after the lookup inside store()'s
    // transaction — the test's own wraps everything, hence level 2 — so it
    // sits outside anything the insert itself may roll back, as a real
    // competitor's committed row would.
    $raced = false;
    DB::listen(function ($query) use (&$raced, $userId) {
        if ($raced || DB::transactionLevel() < 2
            || ! str_starts_with($query->sql, 'select * from "chatbot_conversations"')) {
            return;
        }
        $raced = true;
        DB::table('chatbot_conversations')->insert([
            'user_id' => $userId, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => 'hello'])
        ->assertCreated();

    $conversations = ChatbotConversation::where('user_id', $userId)->get();
    expect($raced)->toBeTrue()
        ->and($conversations)->toHaveCount(1)
        ->and($conversations->first()->messages()->count())->toBe(2);
});

<?php

use App\Models\ChatbotMessage;
use App\Models\User;
use App\Services\ChatbotFacts;
use App\Services\GeminiChatbot;
use App\Support\PaymentMode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The chatbot answers with Gemini and falls back to the rule-based bot on any
 * failure [Ken, 5 October 2026].
 *
 * Every request here is faked. preventStrayRequests() makes a request nobody
 * faked fail the test rather than reach Google, and phpunit.xml blanks
 * GEMINI_KEY for the whole suite; these tests set a key in config only.
 */

const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent';

/** A question the keyword rules cannot place, so Gemini is asked. */
const OPEN_QUESTION = 'Paano mag-apply ng permit para sa bagong negosyo?';

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.gemini.key' => 'test-gemini-key',
        'services.gemini.model' => 'gemini-3.5-flash-lite',
        'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ]);
});

/** Gemini's generateContent envelope around one JSON reply. */
function geminiEnvelope(array|string $reply, string $finish = 'STOP'): array
{
    return [
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => is_string($reply) ? $reply : json_encode($reply)]]],
            'finishReason' => $finish,
        ]],
        'modelVersion' => 'gemini-3.5-flash-lite',
    ];
}

function geminiSays(array|string $reply, int $status = 200, string $finish = 'STOP'): void
{
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiEnvelope($reply, $finish), $status)]);
}

/** Ask as the demo owner; the stored bot turn. */
function geminiAsk(string $message): ChatbotMessage
{
    $id = test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/chatbot/messages', ['message' => $message])
        ->assertCreated()
        ->json('data.id');

    return ChatbotMessage::findOrFail($id);
}

/** The rule-based bot's answer to the same question, with Gemini switched off. */
function rulesAnswer(string $message): string
{
    $key = config('services.gemini.key');
    config(['services.gemini.key' => '']);
    $body = geminiAsk($message)->body;
    config(['services.gemini.key' => $key]);

    return $body;
}

it('answers through Gemini and logs its intent, confidence and source', function () {
    $answer = 'Pumunta sa Home at piliin ang New Business Permit. Sagutan ang bawat hakbang, i-upload ang mga dokumento, at i-submit.';
    geminiSays(['answer' => $answer, 'intent' => 'requirements', 'confidence' => 0.82]);

    $bot = geminiAsk(OPEN_QUESTION);

    expect([$bot->body, $bot->intent, $bot->confidence, $bot->source])
        ->toBe([$answer, 'requirements', 0.82, 'gemini']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === GEMINI_URL
            // The key in the header and nowhere in the URL.
            && $request->header('x-goog-api-key') === ['test-gemini-key']
            && ! str_contains($request->url(), 'test-gemini-key')
            && $body['contents'][0]['parts'][0]['text'] === OPEN_QUESTION
            && $body['generationConfig']['responseMimeType'] === 'application/json'
            && in_array('fallback', $body['generationConfig']['responseSchema']['properties']['intent']['enum'], true)
            // Grounded in the system's own facts.
            && str_contains($body['systemInstruction']['parts'][0]['text'], 'Sanitary Permit, issued by the City Health Office');
    });
});

it('takes the markdown off an answer, since the bubble prints text as text', function () {
    geminiSays(['answer' => "**Steps:**\n* Open Home\n* Choose New Business Permit", 'intent' => 'permit', 'confidence' => 0.7]);

    expect(geminiAsk(OPEN_QUESTION)->body)->toBe("Steps:\n• Open Home\n• Choose New Business Permit");
});

// --- every failure is the rule-based answer, silently ------------------------

it('answers from the rules, and sends nothing, when no key is configured', function () {
    config(['services.gemini.key' => '']);
    Http::fake();

    $bot = geminiAsk(OPEN_QUESTION);

    expect($bot->source)->toBe('rules')->and($bot->body)->toContain('did not quite get that');
    Http::assertNothingSent();
});

it('falls back to the rules on a timeout', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection('Operation timed out')]);

    $bot = geminiAsk(OPEN_QUESTION);

    expect($bot->source)->toBe('rules')->and($bot->body)->toBe(rulesAnswer(OPEN_QUESTION));
});

it('falls back to the rules on a 429, and stops asking for a while', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);

    expect(geminiAsk(OPEN_QUESTION)->source)->toBe('rules');
    // The quota is spent: the next question is not sent at all.
    expect(geminiAsk('ano ang zoning sa may pin ko sa mapa?')->source)->toBe('rules');

    Http::assertSentCount(1);
});

it('falls back to the rules on a server error', function () {
    geminiSays(['answer' => 'x', 'intent' => 'permit', 'confidence' => 1], 500);

    expect(geminiAsk(OPEN_QUESTION)->source)->toBe('rules');
});

it('falls back to the rules when the reply is not the JSON asked for', function (string|array $reply) {
    geminiSays($reply);

    $bot = geminiAsk(OPEN_QUESTION);

    expect($bot->source)->toBe('rules')->and($bot->body)->toBe(rulesAnswer(OPEN_QUESTION));
})->with([
    'not JSON' => ['Here is how to apply: go to Home.'],
    'cut off' => ['{"answer": "Go to Home and'],
    'an intent nobody uses' => [['answer' => 'Go to Home.', 'intent' => 'weather', 'confidence' => 0.9]],
    'no confidence' => [['answer' => 'Go to Home.', 'intent' => 'permit']],
    'an empty answer' => [['answer' => '  ', 'intent' => 'permit', 'confidence' => 0.9]],
]);

it('falls back to the rules when Gemini blocks the question or the answer', function () {
    // The question blocked outright: no candidates at all.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);
    expect(geminiAsk(OPEN_QUESTION)->source)->toBe('rules');

    // The answer stopped for safety part-way.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiEnvelope(['answer' => 'Go', 'intent' => 'permit', 'confidence' => 0.9], 'SAFETY'))]);
    expect(geminiAsk('ano ang dapat gawin para magbukas ng tindahan?')->source)->toBe('rules');
});

it('never serves an answer that quotes a peso amount or a day count RA 11032 does not have', function (string $answer) {
    geminiSays(['answer' => $answer, 'intent' => 'fees', 'confidence' => 0.9]);

    $bot = geminiAsk(OPEN_QUESTION);

    expect($bot->source)->toBe('rules')->and($bot->body)->not->toBe($answer);
})->with([
    'a peso sign' => ['The sanitary permit costs ₱500.'],
    'pesos in words' => ['Mga 1,000 pesos ang bayad.'],
    'PHP' => ['It is PHP 250 for the permit.'],
    'ten working days' => ['It takes 10 working days to release.'],
    'in Filipino' => ['Aabot ng 15 araw ng trabaho.'],
]);

// --- what never leaves -------------------------------------------------------

it('never asks Gemini about the owner\'s own filings, payments or permits', function (string $question) {
    Http::fake();

    expect(geminiAsk($question)->source)->toBe('rules');

    Http::assertNothingSent();
})->with([
    'the starter' => ['Where is my application?'],
    'a tracking id' => ['Ano na ang lagay ng BIZ-2026-00001?'],
    'half a tracking id' => ['biz 2026 status?'],
    'status' => ['what is the status'],
    'my permit' => ['is my sanitary permit approved already?'],
    'my payment' => ['did my payment go through?'],
    'Taglish' => ['nasaan na ang application ko?'],
    'Filipino' => ['natanggap na ba ang bayad ko?'],
]);

it('does not spend the quota on greetings or on questions the rules answer surely', function (string $question) {
    Http::fake();

    expect(geminiAsk($question)->source)->toBe('rules');

    Http::assertNothingSent();
})->with([
    'hello' => ['Hello!'],
    'thanks' => ['salamat po'],
    // Three of the bubble's starters; the fourth is "Where is my application?".
    'starter: requirements' => ['Requirements for a sanitary permit'],
    'starter: fee' => ['How much is the fire safety fee?'],
    'starter: field' => ['What is the water source field for?'],
]);

it('sends no name, e-mail, phone or reference number, even when the question had them', function () {
    geminiSays(['answer' => 'Pwede po.', 'intent' => 'permit', 'confidence' => 0.6]);

    geminiAsk("Ako si Nena Dela Cruz ng Nena's Sari-Sari Store sa 12 Gen. Luna St.; can I add a carinderia? "
        .'Email nena.delacruz@example.com, call 0917 123 4567 or (02) 8281-1234, permit MCB-2026-000001, account BP-2026-0001, TIN 123-456-789-000.');

    Http::assertSent(function (Request $request) {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

        foreach (['Nena', 'Dela Cruz', 'Sari-Sari Store', 'Gen. Luna', 'nena.delacruz', 'example.com',
            '0917', '123 4567', '8281', 'MCB-2026', 'BP-2026-0001', '123-456-789'] as $personal) {
            if (str_contains($request['contents'][0]['parts'][0]['text'], $personal)) {
                return false;
            }
        }

        // The question still makes sense without them.
        return str_contains($sent, 'can I add a carinderia?');
    });
});

it('scrubs tracking ids too, should one ever reach the scrubber', function () {
    expect(GeminiChatbot::scrub('compare BIZ-2026-00042 with biz 2026 00043 on 2026-10-05'))
        ->toBe('compare [number] with [number] on 2026-10-05');
});

// --- the quota -------------------------------------------------------------

it('asks Gemini once for the same public question, however it is typed', function () {
    geminiSays(['answer' => 'Pumunta sa Home.', 'intent' => 'permit', 'confidence' => 0.7]);

    $first = geminiAsk(OPEN_QUESTION);
    $again = geminiAsk('  paano MAG-APPLY ng permit para sa bagong negosyo  ');

    expect([$again->body, $again->source])->toBe([$first->body, 'gemini']);
    Http::assertSentCount(1);
});

it('asks again once a fact it was grounded in changes', function () {
    geminiSays(['answer' => 'Pumunta sa Home.', 'intent' => 'permit', 'confidence' => 0.7]);
    geminiAsk(OPEN_QUESTION);

    config([
        'payments.kwikpay.base_url' => 'https://kwikpay.invalid',
        'payments.kwikpay.merchant' => 'TEST',
        'payments.kwikpay.key' => 'test-key',
        'payments.kwikpay.payment_type' => 'TEST',
    ]);
    PaymentMode::set(PaymentMode::KWIKPAY);
    geminiAsk(OPEN_QUESTION);

    Http::assertSentCount(2);
});

// --- what Gemini's own intents do -----------------------------------------

it('answers a question Gemini places as the owner\'s own from the owner\'s records, never from the model', function () {
    geminiSays(['answer' => 'Your application is approved.', 'intent' => 'status', 'confidence' => 0.8]);

    $bot = geminiAsk('ano na po ang nangyari sa inapply namin?');

    expect([$bot->intent, $bot->source])->toBe(['status', 'gemini'])
        ->and($bot->body)->toContain('Your most recent applications:')
        ->not->toContain('Your application is approved.');
});

it('says the rule-based "did not quite get that" when Gemini says the facts do not cover it', function () {
    geminiSays(['answer' => 'I do not know who the mayor is.', 'intent' => 'fallback', 'confidence' => 0.9]);

    $bot = geminiAsk('Sino ang mayor ng Malabon ngayon?');

    expect([$bot->intent, $bot->source])->toBe(['fallback', 'gemini'])
        ->and($bot->body)->toStartWith('Sorry, I did not quite get that.')
        ->not->toContain('I do not know who the mayor is.');
});

// --- the grounding -------------------------------------------------------

it('grounds Gemini in BizTrack\'s own permits, rules and switches, and in nothing personal', function () {
    $facts = app(ChatbotFacts::class)->instruction();

    expect($facts)
        // The six permits and their offices, from the tables.
        ->toContain("Mayor's / Business Permit, issued by the Business Permits and Licensing Office (BPLO)")
        ->toContain('Zoning Clearance, issued by the Planning/Zoning Office (CPDO)')
        // A checklist row, as the rule-based bot qualifies it.
        ->toContain('Contract of Lease (if you pay rent for the premises)')
        ->toContain('10% of your mayor\'s permit and regulatory fees')
        ->toContain('25% surcharge')
        ->toContain('3 working days for simple, 7 working days for complex, 20 working days for highly technical')
        ->toContain('Monday to Friday, 08:00 to 17:00')
        ->toContain('Online payment is simulated')
        ->toContain('BPLO counter at Malabon City Hall')
        ->toContain('expires on 20 January of the year after it is issued or renewed')
        ->toContain('a renewal runs one year from the day it is renewed')
        ->toContain('Each permit is renewed on its own application')
        ->toContain('sari-sari store')
        ->not->toContain('₱')
        ->not->toContain('Nena')
        ->not->toContain('owner@biztrack.local');

    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    expect($facts)->not->toContain($owner->businesses()->value('name'));
});

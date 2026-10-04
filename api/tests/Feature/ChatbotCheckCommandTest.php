<?php

use Illuminate\Support\Facades\Http;

/*
 * biztrack:chatbot-check — one fixed public question to Gemini, after a
 * deploy. It prints the model, the HTTP status, the latency and whether the
 * answer parsed, and never the key.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.gemini.model' => 'gemini-3.5-flash-lite',
        'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ]);
});

it('reports the model, status, latency and a parsed answer, and never the key', function () {
    config(['services.gemini.key' => 'secret-gemini-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => json_encode([
                'answer' => 'Valid Government ID and the Sanitary Requirements.', 'intent' => 'requirements', 'confidence' => 0.9,
            ])]]],
            'finishReason' => 'STOP',
        ]],
    ])]);

    $this->artisan('biztrack:chatbot-check')
        ->expectsOutputToContain('model:   gemini-3.5-flash-lite')
        ->expectsOutputToContain('status:  200')
        ->expectsOutputToContain('latency: ')
        ->expectsOutputToContain('parsed:  yes')
        ->doesntExpectOutputToContain('secret-gemini-key')
        ->doesntExpectOutputToContain('Valid Government ID')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request['contents'][0]['parts'][0]['text'] === 'What documents do I need for a sanitary permit?');
});

it('fails, and says so, when the answer does not parse', function () {
    config(['services.gemini.key' => 'secret-gemini-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 403]], 403)]);

    $this->artisan('biztrack:chatbot-check')
        ->expectsOutputToContain('status:  403')
        ->expectsOutputToContain('parsed:  no')
        ->assertFailed();
});

it('sends nothing without a key', function () {
    config(['services.gemini.key' => '']);
    Http::fake();

    $this->artisan('biztrack:chatbot-check')
        ->expectsOutputToContain('GEMINI_KEY is not set')
        ->assertFailed();

    Http::assertNothingSent();
});

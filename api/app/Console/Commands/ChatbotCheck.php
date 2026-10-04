<?php

namespace App\Console\Commands;

use App\Services\GeminiChatbot;
use Illuminate\Console\Command;

/**
 * Ask Gemini one fixed public question, now, and say whether the answer came
 * back usable.
 *
 * For the server after a deploy: it answers "is GEMINI_KEY right, does the
 * model name exist, how slow is it from here" without signing in as an owner
 * to provoke a reply. It prints the model, the HTTP status, the latency and
 * whether the reply passed the checks the chatbot applies, and nothing else:
 * never the key, never the reply itself. It goes straight to the API, past the
 * answer cache, so a cached answer cannot pass for a working key.
 */
class ChatbotCheck extends Command
{
    protected $signature = 'biztrack:chatbot-check';

    protected $description = 'Ask Gemini one fixed public question and report the model, HTTP status, latency and whether the answer parsed';

    public function handle(GeminiChatbot $gemini): int
    {
        $this->line('model:   '.GeminiChatbot::model());

        if (! GeminiChatbot::configured()) {
            $this->warn('GEMINI_KEY is not set, so the chatbot answers from its rules alone. Nothing was sent.');

            return self::FAILURE;
        }

        $result = $gemini->check();

        $this->line('status:  '.($result['status'] ?? 'no response (timeout or connection refused)'));
        $this->line('latency: '.$result['latency_ms'].' ms');
        $this->line('parsed:  '.($result['parsed'] ? 'yes' : 'no'));

        return $result['parsed'] ? self::SUCCESS : self::FAILURE;
    }
}

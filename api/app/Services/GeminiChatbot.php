<?php

namespace App\Services;

use App\Models\User;
use App\Support\ChatbotReply;
use App\Support\Ra11032;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gemini's answer to an owner's PUBLIC question, or null — never an error.
 *
 * Ken, 5 October 2026: the chatbot answers with Gemini Flash-Lite and falls
 * back to the rule-based bot on ANY failure, silently. So everything here ends
 * in an answer that passed every check below, or in null, and null means
 * ChatbotAssistant serves the rules answer it already holds. The owner never
 * reads an error because of Gemini.
 *
 * ── What is sent ────────────────────────────────────────────────────────────
 *
 * The system instruction (ChatbotFacts: how to answer, and BizTrack's public
 * facts) and the owner's question, scrubbed (scrub()). Nothing else: no name,
 * no e-mail, no filing. Questions about the owner's own records never get here
 * (ChatbotReply::$keepLocal). The key goes in the `x-goog-api-key` header, so
 * the URL that a proxy or access log records carries none.
 *
 * ── What is accepted ───────────────────────────────────────────────────────
 *
 * JSON mode with a schema, so the reply is {answer, intent, confidence}, and
 * then only if: the request answered 200; nothing was blocked (a prompt block,
 * or a finish other than STOP, which is how a safety stop, a recitation or a
 * truncated reply shows); the JSON parses; the intent is one of ours; the
 * answer is non-empty and short; and it quotes no peso amount and no working-
 * day count RA 11032 does not have (invents()). The last two are the two
 * mistakes this chatbot has made before in code, and a model is likelier to.
 *
 * ── Saving the quota ───────────────────────────────────────────────────────
 *
 * The key is free tier: roughly 15–30 requests a minute and a few hundred to
 * ~1,500 a day. An accepted answer is cached for CACHE_HOURS against the
 * normalised scrubbed question, the model and a hash of the instruction, so
 * the same public question costs one request however many owners ask it, and
 * a changed fact retires the answers built on the old one. A 429 pauses every
 * request for the Retry-After it names (COOLDOWN_SECONDS when it names none),
 * because asking again inside the minute only spends the next one.
 */
class GeminiChatbot
{
    public const CACHE_HOURS = 6;

    public const COOLDOWN_SECONDS = 60;

    /** The fixed public question `biztrack:chatbot-check` asks. */
    public const CHECK_QUESTION = 'What documents do I need for a sanitary permit?';

    private const COOLDOWN_KEY = 'chatbot:gemini:cooldown';

    /** About 120 words with room to spare; longer is a reply that ignored the brief. */
    private const MAX_ANSWER_CHARS = 1200;

    public function __construct(private ChatbotFacts $facts) {}

    public static function configured(): bool
    {
        return trim((string) config('services.gemini.key')) !== '';
    }

    public static function model(): string
    {
        return (string) (config('services.gemini.model') ?: 'gemini-3.5-flash-lite');
    }

    /**
     * Gemini's reply to `$question`, asked by `$asker`, or null on any failure.
     */
    public function answer(string $question, User $asker): ?ChatbotReply
    {
        if (! self::configured() || Cache::has(self::COOLDOWN_KEY)) {
            return null;
        }

        $clean = self::scrub($question, $asker);
        $instruction = $this->facts->instruction();
        $key = 'chatbot:gemini:'.sha1(self::model()."\n".sha1($instruction)."\n".self::normalise($clean));

        $reply = Cache::get($key);
        if (! is_array($reply)) {
            $result = $this->ask($instruction, $clean);
            $reply = $result['reply'];
            if ($reply === null) {
                Log::warning('Chatbot: Gemini gave no usable answer, the rule-based answer was used.', [
                    'reason' => $result['reason'],
                    'status' => $result['status'],
                ]);

                return null;
            }
            Cache::put($key, $reply, now()->addHours(self::CACHE_HOURS));
        }

        return new ChatbotReply($reply['answer'], $reply['intent'], $reply['confidence'], ChatbotReply::GEMINI);
    }

    /**
     * One request, timed, for `biztrack:chatbot-check`: the model, the HTTP
     * status, the latency and whether the reply passed every check. Nothing
     * else, and never the key.
     *
     * @return array{model: string, status: int|null, latency_ms: int, parsed: bool}
     */
    public function check(): array
    {
        $started = hrtime(true);
        $result = $this->ask($this->facts->instruction(), self::CHECK_QUESTION);

        return [
            'model' => self::model(),
            'status' => $result['status'],
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'parsed' => $result['reply'] !== null,
        ];
    }

    /**
     * The question with anything personal taken out, before it leaves.
     *
     * The asker's own name, their businesses' names and their home street go
     * first, by value, because those are known exactly. Then by shape: e-mail
     * addresses, BizTrack's own numbers (tracking, permit, business account
     * and payment references), and any run of seven or more digits, which is
     * a phone number, a TIN, an ID or an amount. An ISO date is kept: it is
     * the one long run of digits a permit question needs.
     *
     * Free text is free text: a stranger's name typed into a question cannot
     * be recognised, and is the owner's own words about somebody else.
     */
    public static function scrub(string $question, ?User $asker = null): string
    {
        $text = $question;

        if ($asker) {
            $known = collect([$asker->first_name, $asker->middle_name, $asker->last_name, $asker->home_street])
                ->merge($asker->businesses()->get(['name', 'trade_name'])->flatMap(fn ($b) => [$b->name, $b->trade_name]))
                ->filter(fn ($v) => is_string($v) && mb_strlen(trim($v)) >= 3)
                ->map(fn (string $v) => trim($v))
                ->sortByDesc(fn (string $v) => mb_strlen($v))
                ->unique();
            foreach ($known as $value) {
                $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu', '[name]', $text) ?? $text;
            }
        }

        $text = preg_replace('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+/u', '[email]', $text) ?? $text;
        $text = preg_replace('/\b(?:BIZ|MCB|BP|PAY)[\s\-_]*\d{4}[\s\-_]*\d+/iu', '[number]', $text) ?? $text;
        // A comma joins digits only inside a number (1,500,000), not in a list ("11032, 3, 7").
        $text = preg_replace('/(?<![\p{L}\p{N}])(?!\d{4}-\d{2}-\d{2}(?!\d))\+?\(?\d(?:(?:[\s().\-]{0,2}|,(?=\d))\d){6,}/u', '[number]', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** One cache entry for "How much is it?", "how much is it" and "how much  is it ?". */
    public static function normalise(string $question): string
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $question) ?? $question);

        return trim($text, " \t\n\r\0\x0B?!.,;:");
    }

    /**
     * @return array{status: int|null, reply: array{answer: string, intent: string, confidence: float}|null, reason: string|null}
     */
    private function ask(string $instruction, string $question): array
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(max(1, (int) config('services.gemini.timeout', 8)))
                ->post(self::endpoint(), self::body($instruction, $question));
        } catch (Throwable) {
            // Timeout, refused connection, DNS: the message can carry the URL,
            // which is harmless, but nothing more is needed than that it failed.
            return ['status' => null, 'reply' => null, 'reason' => 'unreachable'];
        }

        if ($response->status() === 429) {
            $wait = (int) $response->header('Retry-After');
            Cache::put(self::COOLDOWN_KEY, true, now()->addSeconds($wait > 0 ? min($wait, 300) : self::COOLDOWN_SECONDS));

            return ['status' => 429, 'reply' => null, 'reason' => 'quota'];
        }

        if (! $response->successful()) {
            return ['status' => $response->status(), 'reply' => null, 'reason' => 'http'];
        }

        $reply = self::parse($response->json());

        return ['status' => $response->status(), 'reply' => $reply, 'reason' => $reply === null ? 'unusable' : null];
    }

    private static function endpoint(): string
    {
        $base = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        return "{$base}/models/".rawurlencode(self::model()).':generateContent';
    }

    /** @return array<string, mixed> */
    private static function body(string $instruction, string $question): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $instruction]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $question]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'answer' => ['type' => 'STRING'],
                        'intent' => ['type' => 'STRING', 'format' => 'enum', 'enum' => ChatbotReply::INTENTS],
                        'confidence' => ['type' => 'NUMBER'],
                    ],
                    'required' => ['answer', 'intent', 'confidence'],
                ],
                // Low: the facts are the answer, and variety is not wanted.
                'temperature' => 0.2,
                'maxOutputTokens' => 512,
            ],
        ];
    }

    /**
     * The reply, if every check passes; see the class note.
     *
     * @return array{answer: string, intent: string, confidence: float}|null
     */
    private static function parse(mixed $payload): ?array
    {
        if (! is_array($payload) || isset($payload['promptFeedback']['blockReason'])) {
            return null;
        }

        $candidate = $payload['candidates'][0] ?? null;
        if (! is_array($candidate) || ($candidate['finishReason'] ?? 'STOP') !== 'STOP') {
            return null;
        }

        // A thinking model may send its reasoning as parts of its own; only the reply counts.
        $text = collect($candidate['content']['parts'] ?? [])
            ->filter(fn ($part) => is_array($part) && ! ($part['thought'] ?? false) && is_string($part['text'] ?? null))
            ->pluck('text')
            ->implode('');
        $text = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/', '', $text) ?? $text;

        $data = json_decode(trim($text), true);
        if (! is_array($data)) {
            return null;
        }

        $intent = $data['intent'] ?? null;
        $answer = $data['answer'] ?? null;
        $confidence = $data['confidence'] ?? null;
        if (! is_string($intent) || ! in_array($intent, ChatbotReply::INTENTS, true)
            || ! is_string($answer) || ! is_numeric($confidence)) {
            return null;
        }

        $answer = self::plain($answer);
        // `status` and `fallback` carry no answer of theirs: the rules supply it.
        if (! in_array($intent, ['status', 'fallback'], true)
            && ($answer === '' || mb_strlen($answer) > self::MAX_ANSWER_CHARS || self::invents($answer))) {
            return null;
        }

        return [
            'answer' => $answer,
            'intent' => $intent,
            'confidence' => round(max(0.0, min(1.0, (float) $confidence)), 3),
        ];
    }

    /** Markdown the brief asked it not to send, taken off: the bubble prints text as text. */
    private static function plain(string $answer): string
    {
        $text = str_replace(['**', '__', '`'], '', $answer);
        $text = preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $text) ?? $text;
        $text = preg_replace('/^[ \t]*[*\-][ \t]+/mu', '• ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * A peso figure, or a working-day count that is not one of RA 11032's.
     *
     * BizTrack never quotes an amount before the Tax Order of Payment
     * (ChatbotResponder::fees), and the rule-based bot once told applicants
     * "10 working days", a tier that does not exist (AGENTS.md §11). Both are
     * claims the facts cannot back, so a reply making one is not served.
     */
    private static function invents(string $answer): bool
    {
        if (preg_match('/₱\s?\d|\bphp\s?\d|\bp\s?\d[\d,]*\.\d{2}\b|\d[\d,.]*\s*(?:pesos?|piso)\b/iu', $answer)) {
            return true;
        }

        $tiers = array_column(Ra11032::TIERS, 'statutory_working_days');
        preg_match_all('/(\d+)\s*(?:working|business)\s*days?|(\d+)\s*(?:na\s+)?araw\s+ng\s+trabaho/iu', $answer, $m);
        foreach (array_merge($m[1], $m[2]) as $days) {
            if ($days !== '' && ! in_array((int) $days, $tiers, true)) {
                return true;
            }
        }

        return false;
    }
}

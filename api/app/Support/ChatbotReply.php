<?php

namespace App\Support;

/**
 * One assistant answer, with what the paper promises is logged beside it.
 *
 * UCR-07 step 3.1 says each exchange is recorded with the detected intent and
 * a confidence score, so the answer travels with both, and with `source`: who
 * classified the question. The chatbot_messages row stores all three for every
 * bot turn.
 *
 * `intent` is one of INTENTS, the names ChatbotResponder already scores
 * keywords under, plus `field` (a box on a form), `permit` (a permit named and
 * nothing asked about it) and `fallback` (nothing understood).
 */
final class ChatbotReply
{
    public const INTENTS = [
        'requirements', 'renewal', 'payment', 'fees', 'status', 'offices', 'hours',
        'greeting', 'field', 'permit', 'fallback',
    ];

    public const RULES = 'rules';

    public function __construct(
        public readonly string $body,
        public readonly string $intent,
        public readonly float $confidence,
        public readonly string $source = self::RULES,
    ) {}
}

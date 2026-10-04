<?php

namespace App\Services;

use App\Models\User;
use App\Support\ChatbotReply;

/**
 * Who answers an owner's question: the rule-based bot or Gemini.
 *
 * ── The order, and why the rules go first ──────────────────────────────────
 *
 * ChatbotResponder answers EVERY question first. Its answer is then either
 * served, or held while Gemini is asked, and served after all if Gemini fails
 * in any way (GeminiChatbot returns null) [Ken, 5 October 2026]. So with no key
 * configured, or the quota spent, or the API down, the assistant is exactly the
 * rule-based bot it was, and the owner never sees an error because of Gemini.
 *
 * Gemini is NOT asked when the rules answer stands on its own:
 *
 *  - the question is about the asker's own filings, payments or permits, or has
 *    no question in it (`keepLocal`): nothing personal leaves the building, and
 *    the scoped lookups are the only thing that can answer it anyway;
 *  - it is a greeting: the reply is a menu, the same whoever asks;
 *  - the keyword match is strong (confidence RULES_CONFIDENT or more), which in
 *    practice means a named permit or form field plus an unmistakable phrase:
 *    "requirements for a sanitary permit", "how much is the fire safety fee",
 *    "what is the water source field for". Those answers are read straight off
 *    the tables Gemini would be paraphrasing, and are pinned by tests; asking a
 *    model to restate them spends free-tier quota for no better answer. Three
 *    of the bubble's four starter questions are in this set, and the fourth is
 *    about the owner's own application, so a first visit costs no quota.
 *
 * Everything else goes to Gemini: a question the keywords could not place, one
 * they placed weakly ("magkano?", "paano mag-apply"), and anything worded in a
 * way the keyword lists never anticipated. That is where the model is better:
 * it understands the question, and it answers in the owner's own language.
 *
 * ── What Gemini's answer can and cannot do ─────────────────────────────────
 *
 * Its `status` means "this is about my own records": the model only names the
 * intent, and the owner's recent filings come from the rules' scoped lookup,
 * never from the model. Its `fallback` means "the facts do not cover it", and
 * the owner reads the rule-based bot's own "Sorry, I did not quite get that"
 * rather than a sentence the model wrote — there is no hand-off, forward or
 * escalation anywhere [Ken, 5 October 2026].
 */
class ChatbotAssistant
{
    /** At or above this, the rules answer stands and Gemini is not asked. */
    public const RULES_CONFIDENT = 0.9;

    public function __construct(
        private ChatbotResponder $rules,
        private GeminiChatbot $gemini,
    ) {}

    public function reply(User $user, string $message): ChatbotReply
    {
        $rules = $this->rules->reply($user, $message);

        if ($rules->keepLocal || $rules->intent === 'greeting' || $rules->confidence >= self::RULES_CONFIDENT) {
            return $rules;
        }

        $model = $this->gemini->answer($message, $user);
        if ($model === null) {
            return $rules;
        }

        return match ($model->intent) {
            'status' => new ChatbotReply($this->rules->ownApplications($user), 'status', $model->confidence, ChatbotReply::GEMINI),
            'fallback' => new ChatbotReply($this->rules->fallbackReply(), 'fallback', $model->confidence, ChatbotReply::GEMINI),
            default => $model,
        };
    }
}

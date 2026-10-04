<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single chatbot turn. sender is 'user' or 'bot'.
 *
 * A bot turn also records what the question was taken to be: `intent`,
 * `confidence` (0–1) and `source` ('rules' or 'gemini'), see ChatbotReply.
 * All three are null on the user's own turns.
 */
class ChatbotMessage extends Model
{
    protected $fillable = ['conversation_id', 'sender', 'body', 'intent', 'confidence', 'source'];

    protected $casts = ['confidence' => 'float'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }
}

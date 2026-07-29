<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A single chatbot turn. sender is 'user' or 'bot'. */
class ChatbotMessage extends Model
{
    protected $fillable = ['conversation_id', 'sender', 'body'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }
}

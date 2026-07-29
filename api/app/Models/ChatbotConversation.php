<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One rule-based assistant conversation per user (created lazily). */
class ChatbotConversation extends Model
{
    protected $fillable = ['user_id', 'started_at'];

    protected $casts = ['started_at' => 'datetime'];

    /**
     * The user's one thread, or null if they have never written. Ordered so the
     * answer never depends on how the storage engine happens to scan the index.
     */
    public static function forUser(int $userId): ?self
    {
        return static::where('user_id', $userId)->orderBy('id')->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatbotMessage::class, 'conversation_id');
    }
}

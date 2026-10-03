<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    /**
     * `handled_by_user_id` is the officer who HELD the filing when this was
     * written, which is not the same as who wrote it: an applicant's message
     * carries the officer it was addressed to.
     *
     * It is what gives each officer their own stretch of one shared
     * conversation - the applicant sees the whole of it, and an officer sees
     * the part that was theirs [client, 30 September 2026]. See the migration
     * 2026_09_30_000100 for why this had to be recorded per message rather
     * than read off the assignment.
     */
    protected $fillable = ['thread_id', 'sender_user_id', 'handled_by_user_id', 'body'];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MessageThread::class, 'thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}

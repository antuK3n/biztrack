<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $fillable = ['message_id', 'original_filename', 'stored_path', 'mime', 'size'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}

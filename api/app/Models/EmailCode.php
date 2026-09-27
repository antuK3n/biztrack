<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A six-digit code sent by e-mail. See the migration for the two purposes and
 * App\Support\EmailCodes for the rules; this class only holds the row.
 */
class EmailCode extends Model
{
    public const VERIFY = 'verify';

    public const LOGIN = 'login';

    protected $fillable = [
        'user_id', 'purpose', 'challenge_hash', 'portal', 'code_hash',
        'attempts', 'send_count', 'sent_at', 'expires_at', 'consumed_at',
    ];

    protected $hidden = ['code_hash', 'challenge_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'send_count' => 'integer',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Still able to accept a guess: not used, not expired, not guessed out. */
    public function isLive(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('auth.email_codes.max_attempts', 5);
    }
}

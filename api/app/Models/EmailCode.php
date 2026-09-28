<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A six-digit code sent by e-mail. See the migration for the first two purposes
 * (the third, PASSWORD, is below; it needed no new column) and
 * App\Support\EmailCodes for the rules; this class only holds the row.
 */
class EmailCode extends Model
{
    public const VERIFY = 'verify';

    public const LOGIN = 'login';

    /*
     * The second step of a password change from Settings [checklist
     * 2026-09-27, Edit Settings]. Tied to the account only, like VERIFY: the
     * request carrying it is already signed in, so there is no challenge.
     * `purpose` is the whole boundary between the three — every lookup
     * filters on it, so a sign-in code cannot change a password and a
     * password code cannot finish a sign-in.
     */
    public const PASSWORD = 'password';

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

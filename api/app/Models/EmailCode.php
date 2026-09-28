<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * A one-time code emailed to confirm an act on an account.
 *
 * The rules live here rather than in the controller because every one of them
 * is a way the mechanism fails quietly if forgotten: an expired code that still
 * works, a spent code that works twice, a code issued for one purpose accepted
 * for another, or no limit on guesses at six digits.
 */
class EmailCode extends Model
{
    /** Changing a password. The only purpose so far; the column allows more. */
    public const PURPOSE_PASSWORD = 'password_change';

    /**
     * How long a code is good for.
     *
     * Ten minutes is long enough to find the mail on a slow connection and
     * short enough that a code read over somebody's shoulder is worthless by
     * the time they are alone with the phone.
     */
    public const TTL_MINUTES = 10;

    /** Guesses allowed before the code is burnt. Six digits, so this matters. */
    public const MAX_ATTEMPTS = 5;

    /**
     * How long before another code can be asked for.
     *
     * Without it the "Send code" button is an open relay: anybody holding a
     * session can post it in a loop and fill the owner's inbox.
     */
    public const RESEND_SECONDS = 60;

    protected $fillable = ['user_id', 'purpose', 'code_hash', 'expires_at', 'attempts', 'used_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mint a code for this user and purpose, and answer with the plain digits
     * for the mail to carry.
     *
     * Any earlier code for the same purpose is spent first. Two live codes
     * means the one the reader is looking at might not be the one that works,
     * which is indistinguishable from the feature being broken.
     */
    public static function issue(User $user, string $purpose): string
    {
        self::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Six digits, zero-padded, from a cryptographic source — `rand()` is
        // seeded and predictable, which is exactly the wrong property here.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        self::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $code;
    }

    /** The live code for this user and purpose, if there is one. */
    public static function liveFor(User $user, string $purpose): ?self
    {
        return self::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    /**
     * Check a code and spend it.
     *
     * Returns the reason it failed, or null when it is good. A REASON rather
     * than a boolean, because "that code has expired" and "that code is wrong"
     * send the reader to two different places — one to the Send button, one
     * back to the mail — and a single "invalid" sends half of them to the
     * wrong one.
     */
    public function spend(string $attempt): ?string
    {
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            return 'Too many wrong codes were entered. Ask for a new one.';
        }

        if (! Hash::check($attempt, $this->code_hash)) {
            $this->increment('attempts');

            $left = self::MAX_ATTEMPTS - $this->attempts;

            return $left > 0
                ? "That code is not right. {$left} ".($left === 1 ? 'try' : 'tries').' left.'
                : 'That code is not right, and there are no tries left. Ask for a new one.';
        }

        $this->forceFill(['used_at' => now()])->save();

        return null;
    }
}

<?php

namespace App\Support;

use App\Mail\OneTimeCode;
use App\Models\EmailCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The rules for six-digit e-mail codes, in one place [checklist 2026-09-27,
 * Register 1 and Login 5].
 *
 * Issuing, checking, resending and sending. AuthController decides WHEN a code
 * is needed (and only while EmailSwitch::on()); this class decides what a code
 * is and when it stops working, so the sign-in code and the address code
 * cannot drift apart on expiry, attempts or hashing.
 *
 * ── Why a code and not the signed link ──────────────────────────────────────
 *
 * The address could already be confirmed with a signed link
 * (VerifyEmailAddress, item #61), and that link is still what goes out while
 * mail is off, so nothing about today's demo changes. With mail on, a code is
 * sent instead because most owners register and read mail on the same phone:
 * a link opens in the mail app's own browser, which holds no BizTrack session,
 * and lands them on a sign-in page instead of back where they were. A code is
 * typed into the tab they already have open. The sign-in step needed a code
 * anyway, so both now work the same way.
 *
 * ── Why hashed ──────────────────────────────────────────────────────────────
 *
 * A code is a password with a short life. The table stores `Hash::make` of it,
 * and a sha256 of the sign-in challenge, so a copied database file holds
 * nothing that can finish a sign-in or confirm an address.
 */
class EmailCodes
{
    /**
     * Start the second step of a sign-in: a fresh code, and the random handle
     * the sign-in page will send back with it.
     *
     * @return array{0: EmailCode, 1: string, 2: string} [row, code, challenge]
     */
    public static function issueLogin(User $user, string $portal): array
    {
        $challenge = Str::random(64);
        $code = self::newCode();

        $row = EmailCode::create([
            'user_id' => $user->id,
            'purpose' => EmailCode::LOGIN,
            'challenge_hash' => self::hashChallenge($challenge),
            'portal' => $portal,
            'code_hash' => Hash::make($code),
            'sent_at' => now(),
            'expires_at' => now()->addMinutes(self::minutes(EmailCode::LOGIN)),
        ]);

        return [$row, $code, $challenge];
    }

    /**
     * A fresh address-confirmation code. Any earlier one still open is closed
     * first: only the newest e-mail should work, or a reader holding two
     * messages cannot tell which code to type.
     *
     * @return array{0: EmailCode, 1: string} [row, code]
     */
    public static function issueVerify(User $user): array
    {
        EmailCode::where('user_id', $user->id)
            ->where('purpose', EmailCode::VERIFY)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = self::newCode();

        $row = EmailCode::create([
            'user_id' => $user->id,
            'purpose' => EmailCode::VERIFY,
            'code_hash' => Hash::make($code),
            'sent_at' => now(),
            'expires_at' => now()->addMinutes(self::minutes(EmailCode::VERIFY)),
        ]);

        return [$row, $code];
    }

    /**
     * The address code sent at sign-up [Ken, 6 October 2026: "the confirm
     * email address should already be asked … when signing up"].
     *
     * A VERIFY row, so the e-mail, its thirty minutes and "only the newest
     * works" are the confirmation code's. It also carries a challenge, as a
     * sign-in code does, because the reader holds no session yet: sign-up hands
     * one out only once the address is proved, through the sign-in code's own
     * endpoints (findChallenge accepts this row). The plain issueVerify rows,
     * sent from Profile to a signed-in owner, have no challenge and so can
     * never finish a sign-in.
     *
     * @return array{0: EmailCode, 1: string, 2: string} [row, code, challenge]
     */
    public static function issueSignUp(User $user): array
    {
        [$row, $code] = self::issueVerify($user);
        $challenge = Str::random(64);

        $row->forceFill([
            'challenge_hash' => self::hashChallenge($challenge),
            'portal' => 'public',
        ])->save();

        return [$row, $code, $challenge];
    }

    /**
     * A fresh code for changing the password from Settings [checklist
     * 2026-09-27, Edit Settings]. Earlier open ones are closed first, for the
     * reason issueVerify gives: only the newest e-mail should work.
     *
     * @return array{0: EmailCode, 1: string} [row, code]
     */
    public static function issuePassword(User $user): array
    {
        EmailCode::where('user_id', $user->id)
            ->where('purpose', EmailCode::PASSWORD)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = self::newCode();

        $row = EmailCode::create([
            'user_id' => $user->id,
            'purpose' => EmailCode::PASSWORD,
            'code_hash' => Hash::make($code),
            'sent_at' => now(),
            'expires_at' => now()->addMinutes(self::minutes(EmailCode::PASSWORD)),
        ]);

        return [$row, $code];
    }

    public static function latestPassword(User $user): ?EmailCode
    {
        return EmailCode::where('user_id', $user->id)
            ->where('purpose', EmailCode::PASSWORD)
            ->latest('id')
            ->first();
    }

    /** A sign-in code, or the sign-up code (issueSignUp), by its challenge. */
    public static function findChallenge(string $challenge): ?EmailCode
    {
        return EmailCode::whereIn('purpose', [EmailCode::LOGIN, EmailCode::VERIFY])
            ->where('challenge_hash', self::hashChallenge($challenge))
            ->first();
    }

    public static function latestVerify(User $user): ?EmailCode
    {
        return EmailCode::where('user_id', $user->id)
            ->where('purpose', EmailCode::VERIFY)
            ->latest('id')
            ->first();
    }

    /**
     * Try a guess against a code.
     *
     * `ok` consumes it. `wrong` counts the guess and says how many are left.
     * `dead` means it could not have been right whatever was typed: used,
     * expired, or guessed out — and a guess that uses up the last try answers
     * `dead` too, so the page moves the reader on rather than inviting a sixth.
     *
     * @return array{status: 'ok'|'wrong'|'dead', remaining: int}
     */
    public static function check(EmailCode $row, string $code): array
    {
        if (! $row->isLive()) {
            return ['status' => 'dead', 'remaining' => 0];
        }

        if (Hash::check($code, $row->code_hash)) {
            $row->forceFill(['consumed_at' => now()])->save();

            return ['status' => 'ok', 'remaining' => 0];
        }

        $row->forceFill(['attempts' => $row->attempts + 1])->save();
        $remaining = max(0, self::maxAttempts() - $row->attempts);

        if ($remaining === 0) {
            $row->forceFill(['consumed_at' => now()])->save();

            return ['status' => 'dead', 'remaining' => 0];
        }

        return ['status' => 'wrong', 'remaining' => $remaining];
    }

    /**
     * Seconds until the resend button may be used again: 0 means now, null
     * means never (this code has been sent as often as it may be, or it is
     * dead — start again).
     */
    public static function resendWait(EmailCode $row): ?int
    {
        if (! $row->isLive() || $row->send_count >= (int) config('auth.email_codes.max_sends', 5)) {
            return null;
        }

        $ready = $row->sent_at->copy()->addSeconds((int) config('auth.email_codes.resend_after', 60));

        return $ready->isFuture() ? (int) ceil(now()->diffInSeconds($ready)) : 0;
    }

    /**
     * Replace the code on a row with a new one and restart its clock. The wrong
     * guesses already made stay counted — see the migration.
     */
    public static function refresh(EmailCode $row): string
    {
        $code = self::newCode();

        $row->forceFill([
            'code_hash' => Hash::make($code),
            'send_count' => $row->send_count + 1,
            'sent_at' => now(),
            'expires_at' => now()->addMinutes(self::minutes($row->purpose)),
        ])->save();

        return $code;
    }

    /**
     * Send it, now, through the configured mailer.
     *
     * Synchronously and deliberately not through the queue the owner updates
     * use (SendOwnerUpdateEmail). Somebody is standing at the sign-in page
     * waiting for this; if no worker is running, a queued code waits in `jobs`
     * and the sign-in never finishes, with nothing on screen to say why. Sent
     * inline, a relay failure throws here and the caller can tell the reader
     * the code did not go.
     */
    public static function send(User $user, string $purpose, string $code): void
    {
        Mail::to($user->email, $user->fullName() ?: $user->name)->send(new OneTimeCode(
            recipientName: $user->first_name ?: null,
            code: $code,
            purpose: $purpose,
            minutes: self::minutes($purpose),
        ));
    }

    /**
     * `m••••@gmail.com` — enough for the reader to recognise which inbox to
     * open, not enough to hand an onlooker the address.
     */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'••••'.($domain !== '' ? '@'.$domain : '');
    }

    /*
     * A password code lives as long as a sign-in code, not as long as an
     * address code. Both guard the account itself and are typed by someone
     * waiting at the screen that asked for them; the address code is longer
     * because the owner may register now and open the mail later.
     */
    public static function minutes(string $purpose): int
    {
        return (int) match ($purpose) {
            EmailCode::LOGIN => config('auth.email_codes.login_expire', 10),
            EmailCode::PASSWORD => config('auth.email_codes.password_expire', 10),
            default => config('auth.email_codes.verify_expire', 30),
        };
    }

    private static function maxAttempts(): int
    {
        return (int) config('auth.email_codes.max_attempts', 5);
    }

    private static function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function hashChallenge(string $challenge): string
    {
        return hash('sha256', $challenge);
    }
}

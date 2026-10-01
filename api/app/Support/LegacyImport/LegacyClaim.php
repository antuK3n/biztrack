<?php

namespace App\Support\LegacyImport;

use App\Models\Business;
use App\Models\LegacyOwner;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * An owner claims the businesses the old register holds under their name.
 *
 * Ken's checklist, "Migration 1": owners without a BizTrack account are
 * imported as UNCLAIMED records they can claim at sign-up by matching a
 * business or permit number.
 *
 * ── Why the number is not enough on its own ────────────────────────────────
 *
 * A permit number is printed on a certificate that hangs on the shop wall, and
 * anyone can read it — the public QR check at /verify exists precisely so they
 * can. A claim that took the number alone would hand a business to whoever
 * photographed its permit. So the claimant's SURNAME must also match the owner
 * the old register names (accents, case and spacing ignored), and failed
 * attempts are rate-limited per IP address so the surname cannot be guessed
 * down a list of common ones.
 *
 * That is still a weaker proof than BPLO checking an ID at the counter, and
 * the question of whether a counter step is wanted is written down in
 * docs/questions-for-malabon.md. Every claim is audit-logged with the number
 * used, so a wrong one can be found and undone by transferring the business.
 *
 * ── What a claim takes ─────────────────────────────────────────────────────
 *
 * Every still-unclaimed business of that legacy owner, not just the one whose
 * number was quoted. The old register grouped them under one owner id, and
 * making an owner of three shops claim three times proves nothing more.
 */
class LegacyClaim
{
    /** Failed claim attempts allowed per IP address per hour. */
    public const MAX_FAILURES = 10;

    /**
     * The unclaimed legacy owner behind this number, if the surname matches.
     * Throws a validation error on `$field` otherwise.
     */
    public static function match(string $number, string $lastName, ?string $ip, string $field = 'claim_number'): LegacyOwner
    {
        $key = 'legacy-claim:'.($ip ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            throw ValidationException::withMessages([
                $field => ['Too many attempts to claim a business. Try again in an hour, or ask BPLO to link it for you.'],
            ]);
        }

        $owner = self::find($number, $lastName);
        if ($owner === null) {
            RateLimiter::hit($key, 3600);

            throw ValidationException::withMessages([
                $field => ['No unclaimed business matches that number under your surname. Check the business account or permit number on your paper permit — or leave this empty and ask BPLO to link your business.'],
            ]);
        }

        return $owner;
    }

    public static function find(string $number, string $lastName): ?LegacyOwner
    {
        $number = Lookups::number($number);
        if ($number === '') {
            return null;
        }

        $business = Business::query()
            ->whereNull('owner_user_id')
            ->whereNotNull('legacy_owner_id')
            ->where(function ($q) use ($number) {
                $q->whereRaw('UPPER(ban) = ?', [$number])
                    ->orWhereHas('permits', fn ($p) => $p->whereRaw('UPPER(permit_number) = ?', [$number]));
            })
            ->with('legacyOwner')
            ->first();

        $owner = $business?->legacyOwner;
        if ($owner === null || $owner->claimed_by_user_id !== null) {
            return null;
        }

        return Lookups::key($owner->last_name) === Lookups::key($lastName) ? $owner : null;
    }

    /** Give the owner's unclaimed businesses to this account. Returns how many. */
    public static function claim(LegacyOwner $owner, User $user, string $number): int
    {
        return DB::transaction(function () use ($owner, $user, $number) {
            $businesses = $owner->businesses()->whereNull('owner_user_id')->get();

            foreach ($businesses as $business) {
                $business->update(['owner_user_id' => $user->id]);
                Audit::log('business.claimed', $business, [
                    'legacy_id' => $business->legacy_id,
                    'claimed_with' => $number,
                    'user_id' => $user->id,
                ]);
            }

            $owner->update(['claimed_by_user_id' => $user->id, 'claimed_at' => now()]);

            return $businesses->count();
        });
    }
}

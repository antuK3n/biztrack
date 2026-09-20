<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Permit;

/**
 * The business details printed on a certificate — built once, in one place.
 *
 * ── Two doors, one builder ─────────────────────────────────────────────────
 *
 * These five facts are needed at two different moments: when a permit is
 * ISSUED, to be frozen onto the row, and when its PDF is RENDERED, for the
 * permits issued before freezing existed. `PermitController::certificateData`
 * built them inline, which was fine while there was one caller and is exactly
 * how this codebase has repeatedly ended up with a rule in one of two doors.
 *
 * So the builder lives here and both callers ask it. A field added to the face
 * appears in the snapshot and on the page together, or not at all.
 *
 * ── Why a permit is a snapshot and not a view ─────────────────────────────
 *
 * The PDF used to be assembled from the live business record every time it was
 * downloaded, which meant editing a business rewrote every certificate it had
 * ever held. The full argument is in the migration that added
 * `permits.issued_details`; the short version is that a Sanitary Permit is
 * CHO's statement about premises CHO inspected, and its face must not quietly
 * start describing somewhere else.
 */
class PermitFace
{
    /**
     * The keys a snapshot carries. Order is the certificate's own.
     *
     * Kept as a constant so `changedSince()` can compare exactly these and not
     * whatever a future `capture()` happens to return — a comparison that
     * silently widens is how a stale-details flag starts firing on a corrected
     * typo in an emergency contact.
     */
    public const KEYS = [
        'business_name',
        'trade_name',
        'owner_name',
        'address',
        'barangay',
        'city',
        'line_of_business',
    ];

    /**
     * What is true of this business right now.
     *
     * @return array<string, string|null>
     */
    public static function capture(?Business $business): array
    {
        $address = $business?->address;

        return [
            // Null, not '', so a reader can tell "removed from the register"
            // from "never named".
            'business_name' => $business?->name,
            'trade_name' => $business?->trade_name,
            'owner_name' => $business?->owner?->fullName(),
            'address' => $address?->line1,
            'barangay' => $address?->barangay?->name,
            'city' => $address?->city,
            // Every declared line, joined: a permit face lists the activities
            // it covers, and a business may carry more than one.
            'line_of_business' => $business?->lines
                ->map(fn ($l) => $l->psicCode?->title)
                ->filter()
                ->implode(', ') ?: null,
        ];
    }

    /**
     * The face to print: the frozen one, or the live one for older permits.
     *
     * The 8 permits issued before `issued_details` existed cannot be given a
     * truthful snapshot after the fact — what their business looked like on the
     * day is not recoverable — so they keep rendering live and behave exactly
     * as they did. New permits print what was signed.
     *
     * @return array<string, string|null>
     */
    public static function forPrinting(Permit $permit): array
    {
        $frozen = $permit->issued_details;

        if (is_array($frozen) && $frozen !== []) {
            // Filled through KEYS rather than returned as stored, so a snapshot
            // written before a key was added renders that key blank instead of
            // the view reaching for an index that is not there.
            return collect(self::KEYS)
                ->mapWithKeys(fn (string $key) => [$key => $frozen[$key] ?? null])
                ->all();
        }

        return self::capture($permit->business);
    }

    /**
     * Which printed details no longer match the register.
     *
     * This is the durable half of telling an office that a business has been
     * amended. A notification is read once and then gone; this is computable
     * for as long as the certificate exists, so "your Sanitary Permit was
     * issued for a different address" still answers itself in two years.
     *
     * Empty for a permit with no snapshot: an older permit renders live, so its
     * face cannot disagree with the register by construction. Saying "nothing
     * has changed" about it would be a guess dressed as a fact.
     *
     * @return array<string, array{was: string|null, now: string|null}>
     */
    public static function changedSince(Permit $permit): array
    {
        $frozen = $permit->issued_details;
        if (! is_array($frozen) || $frozen === []) {
            return [];
        }

        $now = self::capture($permit->business);
        $drift = [];

        foreach (self::KEYS as $key) {
            $was = $frozen[$key] ?? null;
            if (($now[$key] ?? null) !== $was) {
                $drift[$key] = ['was' => $was, 'now' => $now[$key] ?? null];
            }
        }

        return $drift;
    }
}

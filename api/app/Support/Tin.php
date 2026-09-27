<?php

namespace App\Support;

/**
 * A Taxpayer Identification Number, as the form stores it.
 *
 * ── Why this is its own class now ────────────────────────────────────────────
 *
 * The shaping lived as a private static on `BusinessController`, which was the
 * right home while exactly one route accepted a TIN. Since 27 September 2026 a
 * second one does: the automatic requirement raised when Section A's item 3 is
 * left blank is answered by TYPING the number into a requirement reply, and
 * `OfficerRequestController` writes that answer into `businesses.tin` when the
 * office accepts it.
 *
 * Two entry points, one format. Copying nine lines into the second controller
 * would have been the shorter change and the wrong one — a TIN that normalises
 * one way through the form and another way through a requirement reply is two
 * different columns wearing one name, and the divergence would only show up on
 * the register months later.
 *
 * The pattern itself is unchanged and deliberately so: 9 digits, optionally
 * plus a 3-to-5 digit branch code, rendered dash-joined.
 */
final class Tin
{
    /** The stored shape: 123-456-789, or 123-456-789-000 with a branch code. */
    public const PATTERN = '/^\d{3}-\d{3}-\d{3}(-\d{3,5})?$/';

    /**
     * Dash-join a number somebody typed, or hand it back untouched.
     *
     * Untouched is the important half. Anything this does not recognise is
     * returned exactly as given so that VALIDATION rejects it and says why —
     * a normaliser that silently repairs bad input decides on the applicant's
     * behalf what they meant, and a mistyped TIN is precisely the thing nobody
     * should guess at.
     */
    public static function normalize(string $raw): string
    {
        $trimmed = trim($raw);
        if (! preg_match('/^[\d\s.\-]+$/', $trimmed)) {
            return $trimmed;
        }
        $digits = preg_replace('/\D/', '', $trimmed);
        $length = strlen($digits);
        if ($length !== 9 && ($length < 12 || $length > 14)) {
            return $trimmed;
        }
        $tin = substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3);

        return $length > 9 ? $tin.'-'.substr($digits, 9) : $tin;
    }

    /** Is this the stored shape? Blank is NOT valid here — callers decide that. */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}

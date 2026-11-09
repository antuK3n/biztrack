<?php

namespace App\Support;

use App\Models\Application;
use App\Models\User;

/**
 * Answers an office sheet takes from the applicant's ACCOUNT.
 *
 * ── Why ──────────────────────────────────────────────────────────────────────
 *
 * The CPDD, Occupancy and CENRO sheets ask the owner's home address. The
 * business permit application never collected it — `business_addresses` has
 * an `address_type` column and nothing has ever written a residential row —
 * so the sheets asked it blank. Client, 5 October 2026: *"If none, derive this
 * from the user's address, but still editable."* It comes from here: the home
 * address the owner gave at registration (`users.home_*`, 28 September 2026).
 *
 * ── Applied and locked since 10 November 2026 ───────────────────────────────
 *
 * It was OFFERED on the `prefill` channel and stayed editable. Client, 10
 * November 2026 ("ask once, lock everywhere"): the three sheets show it
 * read-only, with "Change it on your profile.", and the CPDD sheet — never
 * offered it before — takes it too. So these are derived answers now
 * (`OfficeFormAnswers::derive`), and a posted value is ignored whenever the
 * account holds one. An account without a home address derives nothing, and
 * the box stays the applicant's to fill, as it was.
 *
 * OBO prints the ZIP Code in its own box, so its address line leaves the
 * postal code off and `owner_zip` carries it; CPDD and CENRO print one box.
 */
final class AccountPrefill
{
    /** The sheets that print a box for the owner's own address, and its key. */
    public const OWNER_ADDRESS_KEYS = [
        'ZONING' => 'zoning_home_address',
        'CEC' => 'owner_address',
        'OCCUPANCY' => 'owner_address',
    ];

    /**
     * The account's answers for one sheet, keyed as the sheet keys them.
     *
     * @return array<string, string>
     */
    public static function answers(Application $application, string $permitTypeCode): array
    {
        $key = self::OWNER_ADDRESS_KEYS[$permitTypeCode] ?? null;
        if ($key === null) {
            return [];
        }

        $owner = $application->applicant;
        if (! $owner instanceof User || ! $owner->hasHomeAddress()) {
            return [];
        }

        if ($permitTypeCode !== 'OCCUPANCY') {
            return [$key => self::oneLine($owner)];
        }

        $postal = trim((string) $owner->home_postal_code);

        return array_filter([
            $key => self::oneLine($owner, withPostal: false),
            'owner_zip' => $postal,
        ], fn (string $value) => $value !== '');
    }

    /**
     * The paper's single box: "No. of Street, Barangay, Municipality/City,
     * Province" and the postal code after, where there is one.
     */
    public static function oneLine(User $owner, bool $withPostal = true): string
    {
        $line = collect([$owner->home_street, $owner->home_barangay, $owner->home_city, $owner->home_province])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(', ');

        $postal = $withPostal ? trim((string) $owner->home_postal_code) : '';

        return $postal === '' ? $line : "{$line} {$postal}";
    }
}

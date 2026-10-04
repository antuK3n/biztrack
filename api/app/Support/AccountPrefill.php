<?php

namespace App\Support;

use App\Models\Application;
use App\Models\User;

/**
 * Answers an office sheet can take from the applicant's ACCOUNT.
 *
 * ── Why ──────────────────────────────────────────────────────────────────────
 *
 * The Occupancy and CENRO sheets ask the owner's address. The business permit
 * application never collected it — `business_addresses` has an `address_type`
 * column and nothing has ever written a residential row — so the sheet asked
 * it blank. Client, 5 October 2026: *"Check the business permit application if
 * there is no owner's address to derive from … If none, derive this from the
 * user's address, but still editable."* There is none, so it comes from here:
 * the home address the owner gave at registration (`users.home_*`, 28
 * September 2026).
 *
 * ── Offered, not applied ─────────────────────────────────────────────────────
 *
 * Returned on the same `prefill` channel `RenewalPrefill` uses, for the same
 * reason: the applicant must be able to see that the answer was not theirs
 * and confirm it. The browser seeds the empty field, flags it "from your
 * account", and writes it only when the applicant saves. A key already in the
 * stored sheet is never offered — an answer the applicant gave is theirs.
 */
final class AccountPrefill
{
    /** The sheets that print a box for the owner's own address. */
    public const SHEETS_ASKING_OWNER_ADDRESS = ['OCCUPANCY', 'CEC'];

    /**
     * @param  array<string, mixed>  $stored  the sheet's saved `form_data`, if any
     * @return array<string, string>
     */
    public static function forSheet(Application $application, string $permitTypeCode, array $stored): array
    {
        if (! in_array($permitTypeCode, self::SHEETS_ASKING_OWNER_ADDRESS, true)) {
            return [];
        }
        if (trim((string) ($stored['owner_address'] ?? '')) !== '') {
            return [];
        }

        $owner = $application->applicant;
        if (! $owner instanceof User || ! $owner->hasHomeAddress()) {
            return [];
        }

        return ['owner_address' => self::oneLine($owner)];
    }

    /**
     * The paper's single box: "No. of Street, Barangay, Municipality/City,
     * Province" and the postal code after, where there is one.
     */
    public static function oneLine(User $owner): string
    {
        $line = collect([$owner->home_street, $owner->home_barangay, $owner->home_city, $owner->home_province])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(', ');

        $postal = trim((string) $owner->home_postal_code);

        return $postal === '' ? $line : "{$line} {$postal}";
    }
}

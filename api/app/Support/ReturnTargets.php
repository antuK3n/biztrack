<?php

namespace App\Support;

/**
 * The fields BPLO can send a filing back about, as the API understands them.
 *
 * ── Why this exists twice ────────────────────────────────────────────────────
 *
 * `web/src/lib/returnTargets.ts` is the original and stays the one an officer's
 * picker is built from — it owns the labels, the grouping and the order the
 * applicant is asked. This side needs two of the same facts and nothing else:
 * whether a code is a SCALAR (one box, corrected inline) and which column it
 * writes to. Without them the corrections endpoint would take a field name from
 * the browser and write it, which is a request body choosing a column.
 *
 * Two copies of one list is a real cost and it is paid deliberately, the same
 * way `ApplicationStatus` and `web/src/lib/status.ts` pay it: a parity test
 * reads the TypeScript and fails when the two drift. See
 * `ReturnTargetParityTest`. The alternative — publishing the list from PHP and
 * having the browser fetch it — would put a network round trip in front of a
 * dropdown that never changes between deploys.
 *
 * ── Section targets are absent on purpose ────────────────────────────────────
 *
 * Only the scalars are here, because only the scalars can be corrected by this
 * API. A section target ("Line of business", "Uploaded documents") names a
 * whole wizard step with its own repeating rows and its own uploads; the
 * applicant fixes those in the wizard, through the endpoints that already own
 * them. A code that is missing from this map is therefore not an error — it is
 * a target this route does not serve, and the caller is told so.
 */
final class ReturnTargets
{
    /**
     * Scalar target code => [relation, column].
     *
     * `relation` is where the value lives: `business` for a column on the
     * businesses row itself, `address` for its one BusinessAddress, `owner` for
     * its primary BusinessOwner. It is recorded rather than assumed because
     * assuming it was the bug — four of these read as business columns, are
     * named like business columns, and are on the address.
     *
     * A code is SCALAR only if the applicant can retype it into a plain box
     * with nothing else having to change. Anything with a dependent field, a
     * derivation or an enum behind it is a `section` in returnTargets.ts and is
     * corrected in the wizard step that owns those rules — see the note at the
     * top of the patch that split them.
     *
     * Checked against the real schema by ReturnTargetSchemaTest, which is the
     * test that would have caught the original mapping.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SCALAR_FIELDS = [
        'form:registration_number' => ['business', 'registration_number'],
        'form:tin' => ['business', 'tin'],
        'form:name' => ['business', 'name'],
        'form:trade_name' => ['business', 'trade_name'],
        'form:telephone' => ['address', 'telephone'],
        'form:mobile_number' => ['address', 'mobile_number'],
        'form:email' => ['address', 'email'],
        'form:website' => ['address', 'website'],
        'form:president_officer_name' => ['business', 'president_officer_name'],
        'form:citizenship' => ['business', 'citizenship'],
        'form:capital_participation' => ['business', 'capital_participation_filipino'],
        'form:floor_area_sqm' => ['business', 'business_area_sqm'],
        'form:employees_in_lgu' => ['business', 'employees_within_lgu'],
        'form:delivery_units' => ['business', 'delivery_units'],
        'form:capital_investment' => ['business', 'capital_investment'],

        /*
         * Items 10 to 13 — four boxes on the paper and four here since
         * 28 September 2026. They were one lumped `form:owner_name` section,
         * so an officer who could see that only the middle name was wrong had
         * to send back all four. Columns on `business_owners`, which is why
         * the `owner` relation exists.
         */
        'form:owner_surname' => ['owner', 'surname'],
        'form:owner_given_name' => ['owner', 'given_name'],
        'form:owner_middle_name' => ['owner', 'middle_name'],
        'form:owner_suffix' => ['owner', 'suffix'],

        /* Two boxes on the form, so two targets rather than one group. */
        'form:emergency_contact_name' => ['business', 'emergency_contact_name'],
        'form:emergency_contact_number' => ['business', 'emergency_contact_number'],
    ];

    /** Which record holds a scalar target's value: business, address or owner. */
    public static function relation(string $code): ?string
    {
        return self::SCALAR_FIELDS[$code][0] ?? null;
    }

    /**
     * Split the stored pointer into the codes it names.
     *
     * The column held one code until 27 September 2026 and now holds a
     * comma-separated list, so a row written before that date parses as a list
     * of one and nothing had to be migrated.
     *
     * Blanks are dropped rather than preserved as empty codes: a trailing comma
     * is a formatting accident, not a field the officer ticked.
     *
     * @return list<string>
     */
    public static function parse(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $stored)),
            fn (string $code) => $code !== '',
        ));
    }

    /** Just the scalar codes from a stored pointer — the ones this API can write. */
    public static function scalars(?string $stored): array
    {
        return array_values(array_filter(
            self::parse($stored),
            fn (string $code) => isset(self::SCALAR_FIELDS[$code]),
        ));
    }

    /** The column a scalar code writes, or null when it is not one. */
    public static function column(string $code): ?string
    {
        return self::SCALAR_FIELDS[$code][1] ?? null;
    }
}

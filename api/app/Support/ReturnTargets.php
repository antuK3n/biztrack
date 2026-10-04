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
        /*
         * Section B items 1, 3 and 4 were here and are NOT columns on this
         * record — `business_area_sqm`, `employees_within_lgu` and
         * `delivery_units` exist, and are written only at approval by
         * `WorkflowService::syncDeclaredFigures`, from
         * `applications.fee_profile`. The wizard writes the fee profile;
         * during review these are NULL on every filing.
         *
         * So `answeredBy` called them unanswered and the picker hid them,
         * and a correction that did get through would have written a column
         * nothing reads before approval copied the old figure back over it.
         * They are `section` targets now and are corrected on the wizard
         * step that owns them, which also re-prices the permit — which is
         * the real reason they cannot be one-box corrections.
         *
         * `capital_investment` below stays: it IS a column here, written at
         * submission, and carries a value on filings in review.
         */
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

    /**
     * The scalar codes this business has actually answered.
     *
     * What an officer may send a filing back about: a field the applicant
     * left blank was never their answer to correct. A missing TIN has its
     * own route — BPLO's approval raises a requirement for it — and
     * returning the whole filing instead would be both disproportionate and
     * a second way to ask one question.
     *
     * Computed here because only this side knows where each field lives:
     * four are on the address row and one on the primary owner's.
     *
     * @return list<string>
     */
    public static function answeredBy(\App\Models\Business $business): array
    {
        $records = [
            'business' => $business,
            'address' => $business->address,
            'owner' => $business->owners->firstWhere('is_primary', true),
        ];

        $answered = [];
        foreach (self::SCALAR_FIELDS as $code => [$relation, $column]) {
            $record = $records[$relation] ?? null;
            if ($record === null) {
                continue;
            }

            /*
             * A declared FALSE or a zero is an answer. Only null and the
             * empty string are "they did not say" — `empty()` would drop a
             * capital participation of 0, which is the correct figure for a
             * wholly foreign-owned sole proprietorship.
             */
            $value = $record->{$column};
            if ($value !== null && $value !== '') {
                $answered[] = $code;
            }
        }

        return $answered;
    }

    /**
     * A human-readable value for one target on this filing, or null.
     *
     * Null means "this target has no single value worth showing" — a document
     * code, an office-form key, or `form:fee_profile`, which is a dozen
     * questions under one pointer. The caller skips those.
     *
     * Display text, deliberately. Nothing reads it back: it becomes
     * `old_value` / `new_value` on an `ApplicationCorrection`, which the
     * officer's sheet prints. A section target cannot round-trip through one
     * column — that is why it sends the applicant to the wizard — so composing
     * a sentence here is the honest rendering rather than a lossy one.
     */
    public static function displayValue(\App\Models\Application $app, string $code): ?string
    {
        $business = $app->business;
        if ($business === null) {
            return null;
        }

        /* The scalar half: one column, already mapped. */
        if (isset(self::SCALAR_FIELDS[$code])) {
            [$relation, $column] = self::SCALAR_FIELDS[$code];
            $record = match ($relation) {
                'business' => $business,
                'address' => $business->address,
                'owner' => $business->owners->firstWhere('is_primary', true),
                default => null,
            };

            $value = $record?->{$column};

            return $value === null || $value === '' ? null : (string) $value;
        }

        $address = $business->address;
        $profile = is_array($app->fee_profile) ? $app->fee_profile : [];

        /* A fee-profile figure, which is where Section B items 1-4 live. */
        $figure = function (string $key) use ($profile): ?string {
            $value = $profile[$key] ?? null;

            return $value === null || $value === '' ? null : (string) $value;
        };

        $yesNo = fn (?bool $value) => $value === null ? null : ($value ? 'Yes' : 'No');

        return match ($code) {
            'form:registration_type' => self::words($business->registration_type),
            'form:owner_gender' => self::words(
                $business->owners->firstWhere('is_primary', true)?->gender,
            ),
            'form:economic_organization' => $business->economic_organization === 'others'
                ? 'Others — '.($business->economic_organization_others ?: 'unspecified')
                : self::words($business->economic_organization),
            'form:has_tax_incentives' => $yesNo(
                $business->has_tax_incentives === null ? null : (bool) $business->has_tax_incentives,
            ),
            'form:is_rented' => $yesNo(
                $business->is_rented === null ? null : (bool) $business->is_rented,
            ),

            /* Location & Zoning. Composed, because each is several boxes. */
            'form:barangay' => $address?->barangay?->name,
            'form:address' => self::joinParts([
                $address?->house_bldg_no,
                $address?->street,
                $address?->block === null || $address->block === '' ? null : 'Block '.$address->block,
                $address?->lot === null || $address->lot === '' ? null : 'Lot '.$address->lot,
            ]),
            'form:map_pin' => $address?->latitude === null || $address?->longitude === null
                ? null
                : number_format((float) $address->latitude, 6).', '
                    .number_format((float) $address->longitude, 6),
            /*
             * The trades, in the order they were declared. A table rendered as
             * a list: the officer is being told WHICH line changed, and the
             * wizard is where the detail behind each one lives.
             */
            'form:lines' => self::joinParts(
                $business->lines->map(fn ($line) => trim((string) $line->line_of_business))->all(),
                '; ',
            ),

            /* Section B items 1-4, which are `fee_profile` keys, not columns. */
            'form:floor_area_sqm' => $figure('floor_area_sqm'),
            'form:employees' => self::joinParts([
                $figure('employees') === null ? null : $figure('employees').' total',
                $figure('male_employees') === null ? null : $figure('male_employees').' male',
                $figure('female_employees') === null ? null : $figure('female_employees').' female',
            ]),
            'form:employees_in_lgu' => $figure('employees_in_lgu'),
            'form:delivery_units' => self::joinParts([
                $figure('delivery_vehicles_motorized') === null
                    ? null
                    : $figure('delivery_vehicles_motorized').' motorized',
                $figure('delivery_vehicles_other') === null
                    ? null
                    : $figure('delivery_vehicles_other').' other',
            ]),

            /*
             * `form:fee_profile` and every code from another namespace — a
             * document type, an office-form key, a permit code. Not one value,
             * so not one before-and-after.
             */
            default => null,
        };
    }

    /** `sole_proprietorship` as "Sole Proprietorship", and null stays null. */
    private static function words(?string $key): ?string
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return ucwords(str_replace('_', ' ', trim($key)));
    }

    /**
     * Join the parts that have something in them.
     *
     * Blank parts drop out rather than leaving "17,  , Block " — the same rule
     * the wizard and the officer's sheet use when they assemble a name or an
     * address out of optional boxes.
     *
     * @param  list<string|null>  $parts
     */
    private static function joinParts(array $parts, string $glue = ' '): ?string
    {
        $kept = array_values(array_filter(
            array_map(fn ($part) => $part === null ? '' : trim((string) $part), $parts),
            fn (string $part) => $part !== '',
        ));

        return $kept === [] ? null : implode($glue, $kept);
    }

    /**
     * What every named target on this filing says right now.
     *
     * Targets with no single value are left out entirely rather than stored as
     * null, so the map is a record of what CAN be compared. A code that
     * appears here at return time and not at resubmission has genuinely been
     * emptied; one that never appears was never comparable.
     *
     * @param  list<string>  $codes
     * @return array<string, string|null>
     */
    public static function snapshot(\App\Models\Application $app, array $codes): array
    {
        $values = [];
        foreach ($codes as $code) {
            if (isset(self::SCALAR_FIELDS[$code]) || self::isComposable($code)) {
                $values[$code] = self::displayValue($app, $code);
            }
        }

        return $values;
    }

    /** Does this code have a value `displayValue` knows how to compose? */
    private static function isComposable(string $code): bool
    {
        return in_array($code, [
            'form:registration_type',
            'form:owner_gender',
            'form:economic_organization',
            'form:has_tax_incentives',
            'form:is_rented',
            'form:barangay',
            'form:address',
            'form:map_pin',
            'form:lines',
            'form:floor_area_sqm',
            'form:employees',
            'form:employees_in_lgu',
            'form:delivery_units',
        ], true);
    }

    /** The column a scalar code writes, or null when it is not one. */
    public static function column(string $code): ?string
    {
        return self::SCALAR_FIELDS[$code][1] ?? null;
    }
}

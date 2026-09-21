<?php

namespace App\Support;

use App\Models\Barangay;
use App\Models\Business;
use App\Models\PsicCode;

/**
 * Which business details an amendment may change, and how.
 *
 * ── Shaped by MCG-BPLO-FO-003, box for box ────────────────────────────────
 *
 * The paper has FOUR checkboxes, and this file has four groups. Client,
 * 21 September 2026, on the version before this one: *"the fields in paper and
 * in system DOES NOT REALLY MATCH. Make sure everything matches up."* It did
 * not: three of the paper's four boxes were refused outright, and the one that
 * was built wrote the wrong column.
 *
 *  | Box on FO-003                          | Group        | Built |
 *  | -------------------------------------- | ------------ | ----- |
 *  | (unnumbered) Change of line of business| `other`      | ✅    |
 *  | (unnumbered) Additional line of business| `other`     | ⛔    |
 *  | (unnumbered) Amendment of area         | `other`      | ✅    |
 *  | (unnumbered) Others, kindly specify    | `other`      | ✅    |
 *  | I. Change of address                   | `address`    | ✅    |
 *  | II. Change of ownership                | `ownership`  | part  |
 *  | III. Change of trade name              | `trade_name` | ✅    |
 *
 * Each group also carries its OWN requirements list on the paper, which is why
 * the group key is on every field here: the document rules read it. See the
 * `amend_*` contexts in `permit_type_requirements`.
 *
 * ── Ownership is recorded, not applied ────────────────────────────────────
 *
 * Client's decision, 21 September 2026: *"Write the owner's name, BPLO moves
 * the account."* The permit prints `business->owner->fullName()` — the
 * ACCOUNT's name — and the new owner may not hold a BizTrack account at all,
 * so nothing here can honestly write it. `owner_name` is therefore recorded
 * against the filing, reaches BPLO complete with the Deed of Transfer attached,
 * and BPLO moves the account by hand. The alternative was a certificate
 * printing a name no account backs, which is a lie on a document the LGU signs.
 *
 * Worth saying plainly: nothing in this codebase moved a business between owner
 * accounts when this was written. The admin "Reassign" screen moves FILINGS
 * BETWEEN OFFICERS, which is a different thing that shares a word. Building the
 * transfer is the other half of this decision, and until it exists
 * `WorkflowService::tellBploToMoveTheAccount` is what stops an approved
 * ownership amendment from landing nowhere in silence.
 *
 * ── Why `address_line1` is gone ───────────────────────────────────────────
 *
 * It was the one address field, and it wrote `business_addresses.line1` — a
 * COMPOSED column. `BusinessController::syncAddressAndLines` rebuilds `line1`
 * from `house_bldg_no` and `street` on every business write, so an approved
 * address amendment was reverted by the next edit that touched the business.
 * The parts are amendable now and `line1` is recomposed from them, exactly as
 * the new-application path does it.
 */
class AmendableFields
{
    /**
     * The paper's four checkboxes. `paper` is the numeral FO-003 gives each
     * one, or null for the unnumbered box — shown to the applicant so the
     * screen and the form they may be holding say the same thing.
     *
     * ── Display order, which is NOT the paper's print order ──────────────
     *
     * FO-003 prints the unnumbered box at the top, above I, II and III.
     * Client, 21 September 2026: *"for other amendments, just place them
     * below. Now at the very top of the other three."*
     *
     * Right, and for a reason the paper does not have to care about: on
     * paper the boxes are all visible at once, so a catch-all at the top
     * costs nothing. On a screen they are collapsed sections read top to
     * bottom, and leading with "Other amendments" puts the vaguest heading
     * where the eye lands first — an applicant changing their address meets
     * a box that is defined by not being theirs.
     *
     * `kinds()` sorts by this, so the order is decided once and every reader
     * — the applicant's step, the officer's summary, `groupsFor` — inherits
     * it. The numeral each box carries is still the paper's, so the two
     * documents remain matchable even where their order differs.
     */
    public const GROUPS = [
        'address' => ['label' => 'Change of address', 'paper' => 'I'],
        'ownership' => ['label' => 'Change of ownership', 'paper' => 'II'],
        'trade_name' => ['label' => 'Change of trade name', 'paper' => 'III'],
        'other' => ['label' => 'Other amendments', 'paper' => null],
    ];

    /**
     * Fields whose change invalidates what another office verified.
     *
     * Client's decision, 19 September 2026: notify the offices, and only for
     * these. A Sanitary Permit is CHO's statement about premises CHO visited,
     * so moving the premises makes its face describe somewhere it has not been.
     * A line of business is what CENRO and CHO assessed. A corrected employee
     * count tells neither of them anything they acted on, and notifying for
     * that is how five accounts learn to ignore the notices that matter.
     *
     * Trade name and owner name are deliberately NOT here. They change what a
     * certificate PRINTS, not what the office found, and the reissue already
     * carries the new face. Adding them would double the traffic on this
     * channel to tell four offices something none of them has to act on.
     */
    public const OFFICE_VISIBLE = [
        'address_house_bldg_no',
        'address_street',
        'address_barangay_id',
        'address_pin',
        'line_of_business',
    ];

    /** Staged relation writes, keyed by business id. */
    private static array $pending = [];

    /**
     * Field key => which box it came off, how to read and write it, and what
     * to call it.
     *
     * `type` is what the applicant's control has to be, because half of these
     * are no longer free text: a PSIC line needs the picker the new-application
     * form uses, a barangay needs the list zoning is assessed against, and a
     * pin needs a map. The applicant's step reads this rather than carrying a
     * second copy of the same knowledge.
     *
     * `writes` says what approval does:
     *   `business`      — a column on `businesses`
     *   `address`       — a column on `business_addresses` (staged, see apply)
     *   `line_primary`  — replaces the PSIC code on the business's one line
     *   `pin`           — latitude and longitude together
     *   `null`          — recorded for BPLO and applied by hand (ownership),
     *                     or a note that was never a field (the paper's
     *                     "AMENDMENT OF … DETAILS" blanks)
     *
     * `cast` runs on the way in and is what makes a text column safe to hold a
     * decimal and an integer side by side — see the migration's note on why
     * both values are stored as text.
     *
     * @return array<string, array{
     *     group: string, label: string, help: ?string, type: string,
     *     writes: ?string, column: ?string, cast: callable,
     *     validation: array<int, string>
     * }>
     */
    public static function kinds(): array
    {
        $text = fn (?string $v) => $v === null || $v === '' ? null : $v;
        $int = fn (?string $v) => $v === null || $v === '' ? null : (int) $v;

        $fields = [
            /* ── The unnumbered box, printed first on FO-003 ──────────────── */

            /*
             * "CHANGE OF LINE OF BUSINESS" — the trade itself, replaced.
             *
             * A PSIC code and not free text, because the line is what the fee
             * is computed from: `permit_types.per_line_surcharge` counts lines
             * and the assessment reads the codes. The paper's blank is a blank
             * because a clerk resolves it to a code by hand at the window.
             *
             * Client, 21 September 2026, chose "build both, fee reassessed":
             * approval rewrites the first line's code and the difference is
             * carried to the January renewal like every other amendment fee.
             */
            'line_of_business' => [
                'group' => 'other',
                'label' => 'Change of line of business',
                'help' => 'Replaces the trade on record. The fee is reassessed and any '
                    .'difference is carried to your January renewal.',
                'type' => 'psic',
                'writes' => 'line_primary',
                'column' => null,
                'cast' => $int,
                'validation' => ['required', 'integer', 'exists:psic_codes,id'],
            ],
            /*
             * ── "ADDITIONAL LINE OF BUSINESS" is deliberately absent ────────
             *
             * It is on the paper, and it was built here on 21 September 2026
             * for exactly that reason — which was following the form without
             * checking the system it was being fitted to. Client, the same
             * day: *"Is it possible for a business to have two lines of
             * business? Currently, in our system, it only has one, right, and
             * I think that is way better and simple?"*
             *
             * Right on both counts, and the register agrees: all five
             * businesses hold exactly one line, and the apply wizard was
             * narrowed to one on purpose — picking a trade REPLACES whatever
             * is there, and a filing carried over with two rows is shown a
             * note saying the extras will go.
             *
             * So an amendment that added a second line could create a state no
             * other path can produce and the next filing would silently undo.
             * A shop taking on a second trade is a counter visit until the
             * whole system declares more than one, and the applicant's step
             * says so where they would look for this box.
             *
             * `line_of_business` below REPLACES the one line, which is the
             * paper's other box and is coherent with one line.
             */
            // "AMENDMENT OF AREA".
            'business_area_sqm' => [
                'group' => 'other',
                'label' => 'Floor area (sqm)',
                'help' => null,
                'type' => 'number',
                'writes' => 'business',
                'column' => 'business_area_sqm',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : (float) $v,
                'validation' => ['required', 'numeric', 'min:0', 'max:1000000'],
            ],
            /*
             * "OTHERS, KINDLY SPECIFY (EMPLOYEES, VEHICLE, ETC.)".
             *
             * The paper names employees and vehicles as its examples, and
             * BizTrack holds both as figures rather than prose — so they are
             * asked as figures and applied on approval, which a free-text line
             * could never be. `other_amendment` below is the escape for the
             * "etc.", and it is the only one of the five that is not applied.
             */
            'total_employees' => [
                'group' => 'other',
                'label' => 'Total employees',
                'help' => null,
                'type' => 'integer',
                'writes' => 'business',
                'column' => 'total_employees',
                'cast' => $int,
                'validation' => ['required', 'integer', 'min:0', 'max:100000'],
            ],
            'male_employees' => [
                'group' => 'other',
                'label' => 'Male employees',
                'help' => null,
                'type' => 'integer',
                'writes' => 'business',
                'column' => 'male_employees',
                'cast' => $int,
                'validation' => ['required', 'integer', 'min:0', 'max:100000'],
            ],
            'female_employees' => [
                'group' => 'other',
                'label' => 'Female employees',
                'help' => null,
                'type' => 'integer',
                'writes' => 'business',
                'column' => 'female_employees',
                'cast' => $int,
                'validation' => ['required', 'integer', 'min:0', 'max:100000'],
            ],
            'employees_within_lgu' => [
                'group' => 'other',
                'label' => 'Employees residing in Malabon',
                'help' => null,
                'type' => 'integer',
                'writes' => 'business',
                'column' => 'employees_within_lgu',
                'cast' => $int,
                'validation' => ['required', 'integer', 'min:0', 'max:100000'],
            ],
            'delivery_units' => [
                'group' => 'other',
                'label' => 'Delivery vehicles',
                'help' => null,
                'type' => 'integer',
                'writes' => 'business',
                'column' => 'delivery_units',
                'cast' => $int,
                'validation' => ['required', 'integer', 'min:0', 'max:100000'],
            ],
            'other_amendment' => [
                'group' => 'other',
                'label' => 'Others, kindly specify',
                'help' => 'Anything this form has no box for. BPLO reads it and applies it '
                    .'by hand.',
                'type' => 'note',
                'writes' => null,
                'column' => null,
                'cast' => $text,
                'validation' => ['required', 'string', 'max:1000'],
            ],

            /* ── I. CHANGE OF ADDRESS ─────────────────────────────────────── */

            /*
             * The parts, not `line1`.
             *
             * `line1` is composed from these two by `syncAddressAndLines`, so
             * amending it directly was undone by the next business write. The
             * applicant sees the same two boxes the new-application form asks
             * (BPLO item 5), which is also why they are named for it.
             */
            'address_house_bldg_no' => [
                'group' => 'address',
                'label' => 'House / building no.',
                'help' => null,
                'type' => 'text',
                'writes' => 'address',
                'column' => 'house_bldg_no',
                'cast' => $text,
                'validation' => ['required', 'string', 'max:120'],
            ],
            'address_street' => [
                'group' => 'address',
                'label' => 'Street',
                'help' => null,
                'type' => 'text',
                'writes' => 'address',
                'column' => 'street',
                'cast' => $text,
                'validation' => ['required', 'string', 'max:255'],
            ],
            /*
             * The barangay, which is the expensive half of a move.
             *
             * A pin has to sit inside the barangay named beside it, so
             * changing this always means dropping the pin again.
             *
             * It is NOT what decides the zoning clearance. That was the rule
             * until 21 September 2026 and tested the wrong thing: zoning
             * belongs to a location, and two streets in one barangay can be
             * zoned differently. `WorkflowService::amendmentMovesPremises`
             * reads the PIN instead — see the long note there.
             */
            'address_barangay_id' => [
                'group' => 'address',
                'label' => 'Barangay',
                'help' => 'Your pin has to sit inside the barangay you choose, so changing '
                    .'this means dropping the pin again.',
                'type' => 'barangay',
                'writes' => 'address',
                'column' => 'barangay_id',
                'cast' => $int,
                'validation' => ['required', 'integer', 'exists:barangays,id'],
            ],
            /*
             * The pin, as one field rather than two.
             *
             * A latitude without its longitude is not half an answer, it is
             * not an answer, and two rows that can be withdrawn separately
             * could leave exactly that. Stored "lat,lng"; `apply` splits it.
             */
            'address_pin' => [
                'group' => 'address',
                'label' => 'Map location',
                'help' => 'Move the pin only if the business has actually moved — that is what '
                    .'re-applies for your Zoning Clearance. Fixing how the address is spelled '
                    .'does not need a new pin.',
                'type' => 'pin',
                'writes' => 'pin',
                'column' => null,
                'cast' => $text,
                'validation' => ['required', 'string', 'regex:/^-?\d{1,3}(\.\d+)?,-?\d{1,3}(\.\d+)?$/'],
            ],
            // "AMENDMENT OF ADDRESS DETAILS" — the paper's second blank.
            'address_details' => [
                'group' => 'address',
                'label' => 'Amendment of address details',
                'help' => 'Anything about this move BPLO should know.',
                'type' => 'note',
                'writes' => null,
                'column' => null,
                'cast' => $text,
                'validation' => ['required', 'string', 'max:1000'],
            ],

            /* ── II. CHANGE OF OWNERSHIP ──────────────────────────────────── */

            /*
             * Recorded, never applied — see the class note. The Deed of
             * Transfer rides along as an upload on the Documentary
             * Requirements step, and BPLO moves the account at the window.
             */
            'owner_name' => [
                'group' => 'ownership',
                'label' => 'Owner',
                'help' => 'BPLO transfers the business to this person when they approve. '
                    .'Upload the Deed of Transfer on the next step.',
                'type' => 'text',
                'writes' => null,
                'column' => null,
                'cast' => $text,
                'validation' => ['required', 'string', 'max:255'],
            ],
            // "AMENDMENT OF OWNERSHIP DETAILS".
            'ownership_details' => [
                'group' => 'ownership',
                'label' => 'Amendment of ownership details',
                'help' => 'Anything about this transfer BPLO should know.',
                'type' => 'note',
                'writes' => null,
                'column' => null,
                'cast' => $text,
                'validation' => ['required', 'string', 'max:1000'],
            ],

            /* ── III. CHANGE OF TRADE NAME ────────────────────────────────── */

            'trade_name' => [
                'group' => 'trade_name',
                'label' => 'Trade name',
                'help' => null,
                'type' => 'text',
                'writes' => 'business',
                'column' => 'trade_name',
                'cast' => $text,
                'validation' => ['required', 'string', 'max:255'],
            ],
            // "AMENDMENT OF TRADENAME DETAILS".
            'trade_name_details' => [
                'group' => 'trade_name',
                'label' => 'Amendment of trade name details',
                'help' => 'Anything about this change BPLO should know.',
                'type' => 'note',
                'writes' => null,
                'column' => null,
                'cast' => $text,
                'validation' => ['required', 'string', 'max:1000'],
            ],
        ];

        /*
         * Written above in the paper's print order, returned in the screen's.
         *
         * Two orders exist and both are real: FO-003 prints the unnumbered
         * box first, and the step shows it last (see GROUPS). Keeping the
         * literal in the paper's order is what lets somebody check this file
         * against the form line by line; sorting on the way out is what gives
         * every reader the screen's order without a second list to maintain.
         *
         * PHP's sorts have been stable since 8.0, so fields keep their order
         * within a group and only the groups move.
         */
        $rank = array_flip(array_keys(self::GROUPS));
        uasort(
            $fields,
            fn (array $a, array $b) => $rank[$a['group']] <=> $rank[$b['group']],
        );

        return $fields;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::kinds());
    }

    /**
     * A stored value as a person should read it, or null if it already reads
     * as itself.
     *
     * Here rather than in the controller because there are TWO readers of
     * these values and they must not differ: the applicant's step and the
     * officer's review sheet. The officer's is the one where the decision is
     * made, and it was about to print "Change of line of business: 1 → 47" —
     * an id is not a thing to show anybody, least of all the person approving
     * it.
     *
     * Null for a floor area, a street or a note, so a caller can fall back to
     * the raw value and this stays the exception rather than a second copy of
     * every field.
     */
    public static function describe(string $field, ?string $value): ?string
    {
        if ($value === null || $value === '' || ! self::allows($field)) {
            return null;
        }

        return match (self::kinds()[$field]['type']) {
            'psic' => PsicCode::find($value)?->title,
            'barangay' => Barangay::find($value)?->name,
            default => null,
        };
    }

    public static function allows(string $field): bool
    {
        return array_key_exists($field, self::kinds());
    }

    public static function label(string $field): string
    {
        return self::kinds()[$field]['label'] ?? $field;
    }

    public static function group(string $field): ?string
    {
        return self::kinds()[$field]['group'] ?? null;
    }

    /**
     * Which of the paper's boxes these requested changes tick.
     *
     * The document rules are per group, so this is what turns a set of
     * requested fields into the `amend_*` contexts a filing asks documents
     * for.
     *
     * @param  iterable<string>  $fields
     * @return list<string>
     */
    public static function groupsFor(iterable $fields): array
    {
        $groups = [];
        foreach ($fields as $field) {
            $group = self::group($field);
            if ($group !== null && ! in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        // Kept in the paper's printed order, so every list reads the same way.
        return array_values(array_filter(
            array_keys(self::GROUPS),
            fn (string $g) => in_array($g, $groups, true),
        ));
    }

    /**
     * Every field that is only ever recorded — nothing to apply on approval.
     *
     * @return list<string>
     */
    public static function recordedOnly(): array
    {
        return array_keys(array_filter(
            self::kinds(),
            fn (array $spec) => $spec['writes'] === null,
        ));
    }

    /**
     * The business's current value for this field, as the register holds it.
     *
     * Null for anything that has no "current": the paper's details blanks and
     * the new owner. A screen showing "Currently: —" against those is telling
     * the truth.
     */
    public static function current(Business $business, string $field): ?string
    {
        if (! self::allows($field)) {
            return null;
        }

        $spec = self::kinds()[$field];

        $value = match ($spec['writes']) {
            'business' => $business->getAttribute($spec['column']),
            'address' => $business->address?->getAttribute($spec['column']),
            'pin' => self::pinOf($business),
            'line_primary' => $business->lines()->orderBy('id')->first()?->psic_code_id,
            // The recorded-only fields, which have no current value.
            default => null,
        };

        return $value === null ? null : (string) $value;
    }

    /** "lat,lng" for the business's pin, or null if it has never been set. */
    private static function pinOf(Business $business): ?string
    {
        $address = $business->address;
        if ($address?->latitude === null || $address->longitude === null) {
            return null;
        }

        return $address->latitude.','.$address->longitude;
    }

    /**
     * Write the requested value onto the business. Returns what it replaced.
     *
     * Does NOT save — the caller owns the transaction, so that applying five
     * requested changes is one write and cannot half-succeed. Relation writes
     * are STAGED here and written by `flush()`, because the owning row is a
     * different record and `$business->save()` would not carry it.
     */
    public static function apply(Business $business, string $field, ?string $newValue): ?string
    {
        if (! self::allows($field)) {
            return null;
        }

        $spec = self::kinds()[$field];
        $old = self::current($business, $field);
        $cast = $spec['cast'];

        switch ($spec['writes']) {
            case 'business':
                $business->setAttribute($spec['column'], $cast($newValue));
                break;

            case 'address':
                self::$pending[$business->id]['address'][$spec['column']] = $cast($newValue);
                break;

            case 'pin':
                [$lat, $lng] = array_pad(explode(',', (string) $newValue, 2), 2, null);
                self::$pending[$business->id]['address']['latitude'] = $lat === null
                    ? null
                    : (float) $lat;
                self::$pending[$business->id]['address']['longitude'] = $lng === null
                    ? null
                    : (float) $lng;
                break;

            case 'line_primary':
                self::$pending[$business->id]['line_primary'] = $cast($newValue);
                break;


            default:
                /*
                 * Recorded only — the ownership request and the paper's notes.
                 * There is nothing to write and nothing to reverse, and the
                 * row's own `applied_at` is what says BPLO has dealt with it.
                 */
                break;
        }

        return $old;
    }

    /**
     * Write the staged relation changes, inside the caller's transaction and
     * right after the business itself is saved.
     *
     * A no-op for an amendment that touched only columns. The staging is
     * cleared either way, so a second call in the same request cannot replay
     * the first one's values onto another business.
     */
    public static function flush(Business $business): void
    {
        $staged = self::$pending[$business->id] ?? [];
        unset(self::$pending[$business->id]);

        if ($staged === []) {
            return;
        }

        if (isset($staged['address'])) {
            $address = $business->address;
            /*
             * A business with no address row on file is a register gap, and
             * inventing a row here would hide it rather than fix it.
             */
            if ($address !== null) {
                $columns = $staged['address'];

                /*
                 * `line1` is COMPOSED, never typed — the same rule
                 * `BusinessController::syncAddressAndLines` follows. Recomposed
                 * from whichever of the two parts this amendment changed plus
                 * whichever it left alone, or the amendment would land and the
                 * next business edit would quietly undo it.
                 */
                $house = trim((string) ($columns['house_bldg_no'] ?? $address->house_bldg_no ?? ''));
                $street = trim((string) ($columns['street'] ?? $address->street ?? ''));
                $composed = trim($house.' '.$street);
                if ($composed !== '') {
                    $columns['line1'] = $composed;
                }

                $address->update($columns);
            }
        }

        if (isset($staged['line_primary'])) {
            /*
             * The FIRST line by id, which is the one the new-application form
             * wrote first and the one every screen shows as the business's
             * trade. A business with no line at all is another register gap,
             * and an amendment is not the place to invent its first one.
             */
            $line = $business->lines()->orderBy('id')->first();
            $line?->update(['psic_code_id' => $staged['line_primary']]);
        }


    }
}

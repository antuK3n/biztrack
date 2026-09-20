<?php

namespace App\Support;

use App\Models\Business;

/**
 * Which business details an amendment may change, and how.
 *
 * ── The client's rule, 19 September 2026 ───────────────────────────────────
 *
 * *"We have clarified with the LGU that only business permit details can be
 * amended."* So this is a whitelist and not a convenience: a field absent from
 * here cannot be amended through the system at all, and the amendment endpoint
 * refuses it by name rather than ignoring it. An amendment that silently drops
 * a requested change is worse than one that refuses it, because the applicant
 * believes it was asked for.
 *
 * ── Mapped from the paper, not invented ───────────────────────────────────
 *
 * The LGU's Amendment Form has seven boxes. Each maps to something real:
 *
 *  | Box on the form                  | Where it lives                  |      |
 *  | -------------------------------- | ------------------------------- | ---- |
 *  | AMENDMENT OF AREA                | `businesses.business_area_sqm`  | ✅   |
 *  | OTHERS (employees, vehicle, etc) | the employee/vehicle counts     | ✅   |
 *  | III. CHANGE OF TRADE NAME        | `businesses.trade_name`         | ✅   |
 *  | I. CHANGE OF ADDRESS             | `business_addresses.line1`      | part |
 *  | II. CHANGE OF OWNERSHIP          | `owner_user_id` + owners        | ⛔   |
 *  | CHANGE OF LINE OF BUSINESS       | the `lines` relation            | ⛔   |
 *  | ADDITIONAL LINE OF BUSINESS      | a new `lines` row               | ⛔   |
 *
 * The ticked ones are scalar columns on `businesses`: one value in, one out,
 * and reversible by reading `old_value` back.
 *
 * **Address is half built.** The street line is amendable; the barangay and the
 * map pin are not. A renumbered street inside the same barangay is a detail. A
 * move to another barangay changes which zoning rules apply and which CPDO
 * officer covers it, so it stays at the counter until the LGU says whether a
 * move needs re-inspection — still open in docs/amendment-2026-09-19.md.
 *
 * The rest are refused rather than half-applied, for two different reasons that
 * decide what building them would mean:
 *
 *  - **Lines are a relation the money depends on.** A line of business carries
 *    a PSIC code and a capitalisation that the fee assessment is computed FROM,
 *    so applying one correctly means deciding what happens to the fee already
 *    paid. That is a product question, not a mapping.
 *  - **Ownership is legal.** The paper asks for a Deed of Transfer, an
 *    affidavit of self-adjudication or an extra-judicial settlement of a
 *    deceased owner's estate. Transferring a business between people on the
 *    strength of an automated approval is not a thing this class should be able
 *    to do on its own.
 */
class AmendableFields
{
    /**
     * Fields whose change invalidates what another office verified.
     *
     * Client's decision, 19 September 2026: notify the offices, and only for
     * these. A Sanitary Permit is CHO's statement about premises CHO visited,
     * so moving the premises makes its face describe somewhere it has not been.
     * A corrected employee count tells CHO nothing it acted on, and notifying
     * for that is how five accounts learn to ignore the notices that matter.
     *
     * `line_of_business` is listed ahead of being amendable, deliberately: it
     * changes what CENRO and CHO assessed, so the day that kind is built the
     * notice must already fire. A list that has to be remembered alongside a
     * new feature is a list that gets remembered once.
     */
    public const OFFICE_VISIBLE = ['address_line1', 'line_of_business'];

    /**
     * Fields that live on a relation rather than on `businesses`.
     *
     * `apply()` stages these and `flush()` writes them, because the owning row
     * is a different record and saving the business would not carry them.
     */
    private const ON_ADDRESS = ['address_line1' => 'line1'];

    /** Staged relation writes, keyed by business id then column. */
    private static array $pending = [];

    /**
     * Field key => how to read it, how to write it, and what to call it.
     *
     * `cast` runs on the way in and is what makes a text column safe to hold a
     * decimal and an integer side by side — see the migration's note on why
     * both values are stored as text.
     *
     * @return array<string, array{label: string, cast: callable, validation: array<int, string>}>
     */
    public static function kinds(): array
    {
        return [
            /*
             * The street address only — NOT the barangay, and not the pin.
             *
             * Client, 19 September 2026, chose to build the address kind rather
             * than leave it at the counter. This is the half that is safe to
             * apply: a corrected or renumbered street address inside the same
             * barangay. Moving barangay changes which zoning rules apply and
             * which CPDO officer covers it, so it stays at the BPLO window
             * until the LGU says whether a move needs re-inspection — the
             * question still open in docs/amendment-2026-09-19.md.
             *
             * Approving one notifies every office that has certified this
             * business, because their certificates now print the new street.
             * See OFFICE_VISIBLE.
             */
            'address_line1' => [
                'label' => 'Street address',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : $v,
                'validation' => ['required', 'string', 'max:255'],
            ],
            'trade_name' => [
                'label' => 'Trade name',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : $v,
                'validation' => ['nullable', 'string', 'max:255'],
            ],
            'business_area_sqm' => [
                'label' => 'Floor area (sqm)',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : (float) $v,
                'validation' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            ],
            'total_employees' => [
                'label' => 'Total employees',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : (int) $v,
                'validation' => ['nullable', 'integer', 'min:0', 'max:100000'],
            ],
            'male_employees' => [
                'label' => 'Male employees',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : (int) $v,
                'validation' => ['nullable', 'integer', 'min:0', 'max:100000'],
            ],
            'female_employees' => [
                'label' => 'Female employees',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : (int) $v,
                'validation' => ['nullable', 'integer', 'min:0', 'max:100000'],
            ],
            'employees_within_lgu' => [
                'label' => 'Employees residing in Malabon',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : (int) $v,
                'validation' => ['nullable', 'integer', 'min:0', 'max:100000'],
            ],
            'delivery_units' => [
                'label' => 'Delivery vehicles',
                'cast' => fn (?string $v) => $v === null || $v === '' ? null : (int) $v,
                'validation' => ['nullable', 'integer', 'min:0', 'max:100000'],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::kinds());
    }

    public static function allows(string $field): bool
    {
        return array_key_exists($field, self::kinds());
    }

    public static function label(string $field): string
    {
        return self::kinds()[$field]['label'] ?? $field;
    }

    /**
     * The business's current value for this field, as the register holds it.
     *
     * Read straight off the model rather than through a per-field getter,
     * because every field here is a column on `businesses` by construction —
     * that IS the current definition of amendable. The moment a relation is
     * added to `kinds()` this needs a reader per field, and the array is shaped
     * to take one.
     */
    public static function current(Business $business, string $field): ?string
    {
        if (! self::allows($field)) {
            return null;
        }

        if (isset(self::ON_ADDRESS[$field])) {
            $value = $business->address?->getAttribute(self::ON_ADDRESS[$field]);

            return $value === null ? null : (string) $value;
        }

        $value = $business->getAttribute($field);

        return $value === null ? null : (string) $value;
    }

    /**
     * Write the requested value onto the business. Returns what it replaced.
     *
     * Does NOT save — the caller owns the transaction, so that applying five
     * requested changes is one write and cannot half-succeed.
     */
    public static function apply(Business $business, string $field, ?string $newValue): ?string
    {
        if (! self::allows($field)) {
            return null;
        }

        $old = self::current($business, $field);
        $cast = self::kinds()[$field]['cast'];

        /*
         * A relation field is STAGED, not set. `$business->save()` would not
         * carry it, and writing the related row here would escape the caller's
         * transaction boundary — five requested changes have to land together
         * or not at all.
         */
        if (isset(self::ON_ADDRESS[$field])) {
            self::$pending[$business->id][self::ON_ADDRESS[$field]] = $cast($newValue);

            return $old;
        }

        $business->setAttribute($field, $cast($newValue));

        return $old;
    }

    /**
     * Write the staged relation changes. Called inside the caller's transaction,
     * right after the business itself is saved.
     *
     * A no-op when nothing was staged, which is every amendment that touched
     * only columns. The staging is cleared either way, so a second call in the
     * same request cannot replay the first one's values onto another business.
     */
    public static function flush(Business $business): void
    {
        $staged = self::$pending[$business->id] ?? [];
        unset(self::$pending[$business->id]);

        if ($staged === []) {
            return;
        }

        $address = $business->address;
        if ($address === null) {
            // Nothing to amend: a business with no address row on file is a
            // register gap, and inventing a row here would hide it.
            return;
        }

        $address->update($staged);
    }
}

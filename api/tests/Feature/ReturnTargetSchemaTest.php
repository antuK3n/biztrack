<?php

use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\BusinessOwner;
use App\Support\ReturnTargets;
use Illuminate\Support\Facades\Schema;

/*
 * ── Every scalar return target writes a column that exists ───────────────────
 *
 * The test that was missing, and the one that mattered.
 *
 * `ReturnTargets::SCALAR_FIELDS` mapped 21 codes to `businesses` columns when
 * it shipped on 27 September 2026. Eight of those columns are not on that
 * table: `telephone`, `mobile_number`, `email` and `website` are on
 * `business_addresses`; `floor_area_sqm`, `employees` and `employees_in_lgu`
 * are `business_area_sqm`, `total_employees` and `employees_within_lgu`; and
 * `owner_gender` is `gender` on `business_owners`. A filing returned about the
 * applicant's e-mail would have failed on save.
 *
 * Two tests were green over it. `ReturnTargetParityTest` compared this map
 * against the TypeScript copy — written by the same hand from the same
 * assumption, so it proved they AGREED, which was never the same as either
 * being right. And the corrections tests exercised `tin` and `trade_name`,
 * which happen to be real columns.
 *
 * The lesson is the shape of the check, not the eight names: a parity test can
 * only catch drift between two things a person wrote. This one asks the
 * database, which is the only participant that could not be talked into the
 * same mistake.
 */

/** The table each `relation` value resolves to. */
function tableForRelation(string $relation): string
{
    return match ($relation) {
        'business' => (new Business)->getTable(),
        'address' => (new BusinessAddress)->getTable(),
        'owner' => (new BusinessOwner)->getTable(),
    };
}

it('names a real column for every scalar target', function () {
    expect(ReturnTargets::SCALAR_FIELDS)->not->toBeEmpty();

    foreach (ReturnTargets::SCALAR_FIELDS as $code => [$relation, $column]) {
        $table = tableForRelation($relation);

        expect(Schema::hasColumn($table, $column))->toBeTrue(
            "Return target {$code} writes {$table}.{$column}, which does not exist."
        );
    }
});

it('only uses relations this code knows how to resolve', function () {
    /*
     * `ApplicationController::corrections` builds a map of exactly these three
     * records. A fourth relation added here without a matching entry there
     * would refuse every correction naming it — loudly, which is the right
     * failure, but this says so at the map instead of at the applicant.
     */
    foreach (ReturnTargets::SCALAR_FIELDS as $code => [$relation, $column]) {
        expect($relation)->toBeIn(['business', 'address', 'owner'], "Target {$code}");
    }
});

it('keeps the fields with dependencies out of the scalar map', function () {
    /*
     * A scalar target is one the applicant can retype into a plain box with
     * NOTHING ELSE having to change. These six cannot:
     *
     *   registration_type      decides which agency's number is valid in item 2
     *   economic_organization  carries an "Others, specify" text field
     *   has_tax_incentives     a boolean revealing the certificate upload
     *   is_rented              a boolean revealing the lessor block
     *   employees              derived from male + female, and uneditable by
     *                          the client's own ruling of 24 September 2026
     *   owner_gender           an enum on another table, asked with the name
     *
     * All six were once scalar and were validated as `nullable|string|max:255`
     * and written raw — so a boolean column could have taken the word "yes".
     * They are sections now and are corrected in the wizard step that owns
     * their rules. Pinned here because the fix is a judgement, not a typo, and
     * the next person to add a field will reach for the shorter answer.
     */
    foreach ([
        'form:registration_type',
        'form:economic_organization',
        'form:has_tax_incentives',
        'form:is_rented',
        'form:employees',
        'form:owner_gender',
    ] as $code) {
        expect(ReturnTargets::column($code))->toBeNull(
            "{$code} has a dependent field, a derivation or an enum behind it, so it "
            .'cannot be corrected by writing one column.'
        );
    }
});

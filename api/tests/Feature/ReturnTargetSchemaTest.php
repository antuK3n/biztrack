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
        /*
         * Section B items 1, 3 and 4, added 29 September 2026. They are
         * not stored on any of the three records a correction can write:
         * the wizard puts them in `applications.fee_profile`, and the
         * `businesses` columns of the same name are filled only at
         * approval. See the test below, which checks that rather than
         * taking this list's word for it.
         */
        'form:floor_area_sqm',
        'form:employees_in_lgu',
        'form:delivery_units',
    ] as $code) {
        expect(ReturnTargets::column($code))->toBeNull(
            "{$code} has a dependent field, a derivation or an enum behind it, so it "
            .'cannot be corrected by writing one column.'
        );
    }
});

/*
 * ── Only what the applicant answered ─────────────────────────────────────────
 *
 * Client, 29 September 2026: *"only put there what is submitted by the
 * applicant ... if the applicant did not submit their TIN, how come can the
 * admin return the TIN?"* A blank field was never their answer to correct, and
 * a missing TIN already has its own route — BPLO's approval raises a
 * requirement for it.
 */
it('offers only the scalar fields a business has answered', function () {
    $business = App\Models\Business::whereNotNull('tin')->firstOrFail();
    $business->load(['address', 'owners']);

    expect(ReturnTargets::answeredBy($business))->toContain('form:tin');

    $business->update(['tin' => null]);
    expect(ReturnTargets::answeredBy($business->fresh()->load(['address', 'owners'])))
        ->not->toContain('form:tin');
});

it('counts a declared zero as an answer', function () {
    /*
     * 0% Filipino participation is the correct figure for a wholly
     * foreign-owned sole proprietorship, so it is an answer an officer may
     * query. `empty()` would have dropped it.
     */
    $business = App\Models\Business::firstOrFail();
    $business->update(['capital_participation_filipino' => 0]);

    expect(ReturnTargets::answeredBy($business->fresh()->load(['address', 'owners'])))
        ->toContain('form:capital_participation');
});

/*
 * ── A column that exists is not a column anybody fills ───────────────────────
 *
 * The first test in this file asks whether each scalar target's column EXISTS.
 * It was green on 29 September 2026 while three targets pointed at
 * `businesses.business_area_sqm`, `businesses.employees_within_lgu` and
 * `businesses.delivery_units`. All three are real columns. All three are NULL
 * on every filing in review, because the wizard asks those figures on
 * `FeeProfileStep` and stores them in `applications.fee_profile`; the
 * `businesses` copies are written later, by
 * `WorkflowService::syncDeclaredFigures`, at approval and at amendment.
 *
 * The client found it as three missing rows in the Return picker — `answeredBy`
 * read the empty columns and reported the questions unanswered. The quieter
 * half was worse: a correction that did get through would have written a column
 * no review screen reads, and then approval would have copied the UNCORRECTED
 * `fee_profile` straight over it. The applicant's fix would have disappeared
 * with nothing logged.
 *
 * ── Why this reads the source ────────────────────────────────────────────────
 *
 * The invariant is structural, not statistical: a column whose only writer runs
 * at APPROVAL cannot back a target that is corrected during REVIEW. That set is
 * not a judgement — it is written down, once, as the `$map` inside
 * `syncDeclaredFigures` plus the `delivery_units` line below it. So the test
 * reads that method rather than a list retyped here, which would agree today
 * and drift the first time somebody added a figure to it.
 *
 * The same trick ReturnTargetParityTest uses on the TypeScript, for the same
 * reason: the check has to be against the thing itself.
 */
function columnsWrittenAtApproval(): array
{
    $source = (string) file_get_contents(app_path('Services/WorkflowService.php'));

    $start = strpos($source, 'private function syncDeclaredFigures');
    expect($start)->not->toBeFalse(
        'syncDeclaredFigures has been renamed or removed. It is the list of columns '
        .'filled only at approval — find its replacement and point this test at it.'
    );

    /* To the next method, which is where its body ends. */
    $end = strpos($source, "\n    /**", $start);
    $body = substr($source, $start, $end === false ? null : $end - $start);

    /* `'fee_profile_key' => 'column',` — the column is what matters here. */
    preg_match_all("/=>\s*'([a-z_]+)',/", $body, $mapped);
    /* And the one that is summed rather than mapped: $changes['delivery_units']. */
    preg_match_all("/\\\$changes\['([a-z_]+)'\]/", $body, $summed);

    $columns = array_values(array_unique(array_merge($mapped[1], $summed[1])));

    expect($columns)->not->toBeEmpty('Parsed no columns out of syncDeclaredFigures.');

    return $columns;
}

it('keeps columns written only at approval out of the scalar map', function () {
    $lateWritten = columnsWrittenAtApproval();

    foreach (App\Support\ReturnTargets::SCALAR_FIELDS as $code => [$relation, $column]) {
        expect($column)->not->toBeIn(
            $relation === 'business' ? $lateWritten : [],
            "Return target {$code} writes businesses.{$column}, which is filled only at "
            .'approval by WorkflowService::syncDeclaredFigures. During review it is null, so '
            .'answeredBy will never offer this field — and a correction to it would be '
            .'overwritten by the fee profile at approval. Make it a section target on the '
            .'wizard step that asks the question.'
        );
    }
});

it('names the four Section B figures as sections, not scalars', function () {
    /*
     * The specific fix, pinned. Items 1 to 4 of Business Operation are
     * assessment inputs: a new floor area or headcount re-prices the permit,
     * and only the wizard step knows how. An inline box on the status page
     * would change the declaration and leave the Tax Order of Payment computed
     * from the figure it replaced — which is the second reason these are
     * sections, independent of where they are stored.
     */
    foreach ([
        'form:floor_area_sqm',
        'form:employees',
        'form:employees_in_lgu',
        'form:delivery_units',
    ] as $code) {
        expect(App\Support\ReturnTargets::column($code))->toBeNull(
            "{$code} is asked on FeeProfileStep, stored in applications.fee_profile, and "
            .'re-prices the permit when it changes. It cannot be a one-box correction.'
        );
    }
});
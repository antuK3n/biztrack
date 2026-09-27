<?php

use App\Models\Application;
use App\Models\ApplicationCorrection;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\WorkflowService;

/*
 * ── A return names fields, and the applicant answers only those ──────────────
 *
 * Client, 27 September 2026: *"once Returned, the admin can choose which field
 * is wrong and return it to the business applicant. The business applicant can
 * then comply to those SELECTED FIELDS only ... where can the admin see the
 * newly complied fields?"*
 *
 * Before this: `remarks_target` held ONE code in a column validated at 120
 * characters, the applicant reopened the whole fifty-question wizard with
 * nothing locked, and the officer re-read all of it because nothing recorded
 * what had moved.
 */

/* `filingReturnedAbout` lives in tests/Pest.php — see BlankTinIsChasedTest. */


it('stores every field the officer ticked, not just the first', function () {
    $app = filingReturnedAbout('form:tin,form:trade_name,form:email');

    $stored = $app->assignments()
        ->where('department_id', app(WorkflowService::class)->bploDepartmentId())
        ->value('remarks_target');

    /*
     * The column was a varchar(120) and the request capped at the same. Three
     * codes are 43 characters, five are about 150 — so this passing at three
     * proves little on its own, which is why the parity test pins the parse and
     * the migration widened the column to text.
     */
    expect($stored)->toBe('form:tin,form:trade_name,form:email');
});

it('writes the corrected fields and sends the filing back for approval', function () {
    $app = filingReturnedAbout('form:tin,form:trade_name');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => [
                'form:tin' => '123456789000',
                'form:trade_name' => 'New Trade Name',
            ],
        ])
        ->assertOk();

    $business = $app->fresh()->business;

    // Shaped by the same helper the wizard uses, so both doors agree.
    expect($business->tin)->toBe('123-456-789-000')
        ->and($business->trade_name)->toBe('New Trade Name')
        // Correcting IS resubmitting: one act, one round trip.
        ->and($app->fresh()->status->value)->toBe('for_approval');
});

it('records what each field was and what it became', function () {
    $app = filingReturnedAbout('form:trade_name');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:trade_name' => 'New Trade Name'],
        ])
        ->assertOk();

    $row = ApplicationCorrection::where('application_id', $app->id)->sole();

    /*
     * The officer's answer to "what changed". Both halves captured at the write
     * itself — see the migration for why "before" cannot be derived afterwards.
     */
    expect($row->target)->toBe('form:trade_name')
        ->and($row->old_value)->toBe('Old Trade Name')
        ->and($row->new_value)->toBe('New Trade Name');
});

it('records a field the applicant looked at and left alone', function () {
    $app = filingReturnedAbout('form:trade_name');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:trade_name' => 'Old Trade Name'],
        ])
        ->assertOk();

    /*
     * "They considered it and stand by it" is an answer. An empty list would
     * read to the officer as "they ignored me", which is a different thing and
     * would send the filing round again for nothing.
     */
    $row = ApplicationCorrection::where('application_id', $app->id)->sole();
    expect($row->old_value)->toBe('Old Trade Name')
        ->and($row->new_value)->toBe('Old Trade Name');
});

it('refuses a field BPLO did not ask about, by name', function () {
    $app = filingReturnedAbout('form:trade_name');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => [
                'form:trade_name' => 'New Trade Name',
                // Not ticked. Accepting it would make the narrow door a wide one.
                'form:name' => 'A Completely Different Business',
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fields']);

    /*
     * And nothing at all was written — not even the legitimate half. A partial
     * save here would leave the applicant unable to tell what had gone through.
     */
    expect($app->fresh()->business->trade_name)->toBe('Old Trade Name')
        ->and($app->fresh()->status->value)->toBe('returned')
        ->and(ApplicationCorrection::where('application_id', $app->id)->count())->toBe(0);
});

it('refuses to correct a filing that was not returned', function () {
    $app = filingReturnedAbout('form:trade_name');
    app(WorkflowService::class)->resubmit($app);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:trade_name' => 'Sneaked In'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($app->fresh()->business->trade_name)->toBe('Old Trade Name');
});

it('says so when the return named only sections this route cannot write', function () {
    // "Line of business" is a repeating table; the applicant fixes it in the
    // wizard step that owns it, not through a one-column write.
    $app = filingReturnedAbout('form:lines');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:lines' => 'anything'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fields']);

    expect($app->fresh()->status->value)->toBe('returned');
});

it('lets another office read what was corrected without the applicant resubmitting twice', function () {
    $app = filingReturnedAbout('form:tin');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:tin' => '123-456-789-000'],
        ])
        ->assertOk();

    // One round, one row: correcting resubmits, so there is no second act that
    // could double the record.
    expect(ApplicationCorrection::where('application_id', $app->id)->count())->toBe(1)
        ->and($app->fresh()->status->value)->toBe('for_approval');
});

/*
 * ── The case the original mapping got wrong ──────────────────────────────────
 *
 * Four of the fifteen scalar fields live on `business_addresses`, not on
 * `businesses`, and the first version of `ReturnTargets::SCALAR_FIELDS` wrote
 * all of them onto the business — setting an attribute with no column behind it
 * and failing on save. No test touched one: the corrections tests exercised
 * `tin` and `trade_name`, which happen to be real business columns.
 *
 * So one of the four is exercised end to end here, and the schema is checked
 * for all of them in ReturnTargetSchemaTest.
 */
it('corrects a field that lives on the address, not the business', function () {
    $app = filingReturnedAbout('form:email');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:email' => 'newaddress@example.com'],
        ])
        ->assertOk();

    expect($app->fresh()->business->address->email)->toBe('newaddress@example.com')
        ->and($app->fresh()->status->value)->toBe('for_approval');
});

it('records an address correction with both of its halves', function () {
    $app = filingReturnedAbout('form:email');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:email' => 'newaddress@example.com'],
        ])
        ->assertOk();

    // The officer's "was → now" has to work whichever record the field is on.
    $row = ApplicationCorrection::where('application_id', $app->id)->sole();
    expect($row->target)->toBe('form:email')
        ->and($row->new_value)->toBe('newaddress@example.com');
});

it('corrects fields on two different records in one submission', function () {
    $app = filingReturnedAbout('form:email,form:trade_name');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => [
                'form:email' => 'both@example.com',
                'form:trade_name' => 'Both Records',
            ],
        ])
        ->assertOk();

    /*
     * One transaction across two tables. The address row and the business row
     * are saved separately, so a filing that wrote one and not the other would
     * leave the applicant told they had corrected both.
     */
    $fresh = $app->fresh();
    expect($fresh->business->address->email)->toBe('both@example.com')
        ->and($fresh->business->trade_name)->toBe('Both Records')
        ->and(ApplicationCorrection::where('application_id', $app->id)->count())->toBe(2);
});

<?php

use App\Models\ApplicationPermitType;
use App\Models\ApplicationReturnNote;
use App\Models\PermitType;
use App\Services\WorkflowService;

/*
 * ── An office return keeps the note it was made to write ────────────────────
 *
 * The officer's screen will not send a return until every ticked row carries
 * a note — `missingNote` disables the button. `AssignmentController` validates
 * `remarks_notes`, `returnAssignment` carries them, and until 30 September
 * 2026 both clearance branches called a `returnClearance` that had nowhere to
 * put them. Three notes typed, one paragraph kept, three blank rows shown.
 *
 * The client's report was that the Return feature works "mostly" — this is
 * one of the halves that did not, and it was invisible because the composed
 * `remarks` string still carried the words, just not the pairing.
 *
 * ── And the two returns must not delete each other's notes ──────────────────
 *
 * `application_return_notes` is keyed by application, and each return clears
 * the set before writing its own. The second test is the one that matters for
 * the long run: BPLO returning the main form used to wipe the table, so an
 * office's instructions would have vanished the next time BPLO sent anything
 * back. `permit_type_id` is what keeps them apart.
 */

/**
 * A clearance row an office is actually reviewing.
 *
 * The states are SET rather than hunted for. Seeded filings sit wherever
 * the seeder left them — the first match was a permit nobody had started,
 * and "A Not Yet Submitted permit cannot become Returned" is the workflow
 * correctly refusing. What these tests are about is where the NOTES go, so
 * the surrounding state is arranged rather than discovered, and the
 * transition rules are left to the suites that exist to check them.
 */
function officeReturnRow(string $code = 'SANITARY'): ApplicationPermitType
{
    $type = PermitType::where('code', $code)->firstOrFail();
    $row = ApplicationPermitType::where('permit_type_id', $type->id)->firstOrFail();

    $row->forceFill(['status' => 'for_approval'])->saveQuietly();
    $row->application->forceFill(['status' => 'for_approval'])->saveQuietly();

    return $row->fresh();
}

it('keeps one note per returned row', function () {
    $row = officeReturnRow();

    app(WorkflowService::class)->returnClearance(
        $row,
        'Two things to fix.',
        'LEASE_TITLE,LOT_OWNER_CONSENT',
        [
            'LEASE_TITLE' => 'The lessor page is unsigned.',
            'LOT_OWNER_CONSENT' => 'Not attached at all.',
        ],
    );

    $notes = ApplicationReturnNote::where('application_id', $row->application_id)
        ->where('permit_type_id', $row->permit_type_id)
        ->pluck('note', 'target')
        ->all();

    expect($notes)->toBe([
        'LEASE_TITLE' => 'The lessor page is unsigned.',
        'LOT_OWNER_CONSENT' => 'Not attached at all.',
    ]);
});

it('replaces its own notes on a second return and leaves the main form alone', function () {
    $row = officeReturnRow();
    $workflow = app(WorkflowService::class);

    /* BPLO's own return, which writes with a null permit type. */
    $workflow->returnMainForm(
        $row->application,
        'Fix the trade name.',
        'form:trade_name',
        ['form:trade_name' => 'This does not match the DTI certificate.'],
    );

    $workflow->returnClearance($row, 'One thing.', 'LEASE_TITLE', [
        'LEASE_TITLE' => 'First instruction.',
    ]);

    /* A second office return replaces only what the office said before. */
    $workflow->returnClearance($row, 'Something else now.', 'LOT_OWNER_CONSENT', [
        'LOT_OWNER_CONSENT' => 'Second instruction.',
    ]);

    $office = ApplicationReturnNote::where('application_id', $row->application_id)
        ->where('permit_type_id', $row->permit_type_id)
        ->pluck('note', 'target')
        ->all();

    /*
     * The first instruction is gone — it named a row this return is not about,
     * and leaving it would tell the applicant to fix something nobody asked
     * about. That is the same rule the pointer follows.
     */
    expect($office)->toBe(['LOT_OWNER_CONSENT' => 'Second instruction.']);

    /* And BPLO's survived all of it. */
    $mainForm = ApplicationReturnNote::where('application_id', $row->application_id)
        ->whereNull('permit_type_id')
        ->pluck('note', 'target')
        ->all();

    expect($mainForm)->toBe([
        'form:trade_name' => 'This does not match the DTI certificate.',
    ]);
});

it('lets the main form clear its own notes without touching an office\'s', function () {
    $row = officeReturnRow();
    $workflow = app(WorkflowService::class);

    $workflow->returnClearance($row, 'Office asked.', 'LEASE_TITLE', [
        'LEASE_TITLE' => 'Office instruction.',
    ]);

    /*
     * BPLO returns twice. The second clears the first's notes — and used to
     * clear every row in the table, which is what would have deleted the
     * office's instruction above.
     */
    $workflow->returnMainForm($row->application, 'One.', 'form:tin', ['form:tin' => 'A.']);
    $workflow->returnMainForm($row->application, 'Two.', 'form:name', ['form:name' => 'B.']);

    expect(
        ApplicationReturnNote::where('application_id', $row->application_id)
            ->whereNull('permit_type_id')
            ->pluck('note', 'target')
            ->all()
    )->toBe(['form:name' => 'B.']);

    expect(
        ApplicationReturnNote::where('application_id', $row->application_id)
            ->where('permit_type_id', $row->permit_type_id)
            ->pluck('note', 'target')
            ->all()
    )->toBe(['LEASE_TITLE' => 'Office instruction.']);
});

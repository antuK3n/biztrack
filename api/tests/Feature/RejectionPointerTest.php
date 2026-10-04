<?php

use App\Enums\ClearanceStatus;
use App\Models\ApplicationReturnNote;
use App\Models\PermitType;
use App\Services\WorkflowService;
use Illuminate\Validation\ValidationException;

/*
 * A refusal says WHICH rows it is about, and will not take the same form back.
 *
 * ── The lopsidedness this closes ─────────────────────────────────────────────
 *
 * A RETURN carries `remarks_target`: the officer ticks the wrong fields, the
 * applicant's correction dialog is drawn from them, and `refuseEmptyReturnedRows`
 * stops an empty row going back. A REFUSAL — the more serious decision, which
 * suspends a business permit while it stands — carried two sentences and
 * nothing else. The applicant read the prose and worked out for themselves
 * which of fifteen answers to change, and could send back a form they had not
 * touched.
 *
 * The client chose both halves on 30 September 2026, having first asked the
 * fair question: isn't resubmission already the answer? It is — a refused
 * permit can be applied for again and always could. What was missing was the
 * guidance to get it right and the guard against getting it wrong.
 *
 * ── Why the unchanged-check is "anything at all" ─────────────────────────────
 *
 * A refusal may name nothing. "The premises are not zoned for this" is about
 * no single field and might be answered by a lease at a different address —
 * a change this code cannot predict. So the test is that SOMETHING moved
 * since the office looked, which is the weakest claim that still rules out
 * the case worth ruling out.
 */

/** A filing whose FSIC has reached inspection, which is where refusal lives. */
function filingAtInspection(): array
{
    $appId = scopedAssignmentFiling('Refusal Pointer Co');
    $app = App\Models\Application::findOrFail($appId);
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', 'FSIC')->firstOrFail();

    $workflow->startClearance($app->fresh(), $type, 'apply');
    satisfyChecklist($app->fresh(), $type);
    $workflow->submitClearanceForm($app->fresh(), $type);

    $row = $workflow->pivotFor($app->fresh(), 'FSIC');
    $workflow->approveClearance($row, 'Paperwork fine.');

    $row = $workflow->pivotFor($app->fresh(), 'FSIC');
    expect($row->status)->toBe(ClearanceStatus::ForInspection);

    return [$app->fresh(), $row];
}

it('records which rows a refusal is about, and what was said about each', function () {
    [$app, $row] = filingAtInspection();

    app(WorkflowService::class)->rejectClearance(
        $row,
        'The fire safety plan is not signed.',
        'Have it signed by a licensed fire safety practitioner, then apply again.',
        'FSIC_REQ_OBO_ENDORSEMENT',
        ['FSIC_REQ_OBO_ENDORSEMENT' => 'No signature on the endorsement.'],
    );

    $fresh = app(WorkflowService::class)->pivotFor($app->fresh(), 'FSIC');

    expect($fresh->status)->toBe(ClearanceStatus::Rejected)
        ->and($fresh->remarks_target)->toBe('FSIC_REQ_OBO_ENDORSEMENT')
        /* The two sentences survive as they always did. */
        ->and($fresh->rejection_note)->toBe('The fire safety plan is not signed.')
        ->and($fresh->rejection_remedy)->not->toBeNull();

    $notes = ApplicationReturnNote::where('application_id', $app->id)
        ->where('permit_type_id', $row->permit_type_id)
        ->pluck('note', 'target')
        ->all();

    expect($notes['FSIC_REQ_OBO_ENDORSEMENT'])->toBe('No signature on the endorsement.');
});

it('refuses a re-submission that changed nothing since the refusal', function () {
    [$app, $row] = filingAtInspection();
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', 'FSIC')->firstOrFail();

    $workflow->rejectClearance(
        $row,
        'The fire safety plan is not signed.',
        'Have it signed, then apply again.',
        'FSIC_REQ_OBO_ENDORSEMENT',
        [],
    );

    /*
     * Straight back, untouched. This is the path of least effort — the sheet
     * reopens with every answer still on it — and it used to cost the office
     * a whole second review to discover nothing had changed.
     */
    expect(fn () => $workflow->submitClearanceForm($app->fresh(), $type))
        ->toThrow(ValidationException::class);

    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)
        ->toBe(ClearanceStatus::Rejected);
});

it('takes the re-submission once the named row has changed', function () {
    [$app, $row] = filingAtInspection();
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', 'FSIC')->firstOrFail();

    $workflow->rejectClearance(
        $row,
        'The fire safety plan is not signed.',
        'Have it signed, then apply again.',
        'FSIC_REQ_OBO_ENDORSEMENT',
        [],
    );

    /*
     * A second copy against the named slot. `storeRequirement` appends, so
     * the snapshot moves — which is the whole signal the guard reads.
     */
    App\Models\ApplicationDocument::create([
        'application_id' => $app->id,
        'document_type_id' => App\Support\SheetRequirements::documentType('FSIC', 'FSIC_REQ_OBO_ENDORSEMENT')->id,
        'original_filename' => 'endorsement-signed.pdf',
        'stored_path' => 'private/documents/test/endorsement-signed.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
    ]);

    $workflow->submitClearanceForm($app->fresh(), $type);

    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)
        ->toBe(ClearanceStatus::ForApproval);
});

it('lets a refusal that named nothing through, rather than stranding anybody', function () {
    /*
     * "The premises are not zoned for this" names no row, so there is no
     * snapshot to compare and nothing this code can say about whether the
     * applicant has answered it. Refusing on absent evidence would trap them
     * over a record they never had.
     */
    [$app, $row] = filingAtInspection();
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', 'FSIC')->firstOrFail();

    $workflow->rejectClearance($row, 'The premises are not zoned for this.', 'Move, then apply.');

    $workflow->submitClearanceForm($app->fresh(), $type);

    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)
        ->toBe(ClearanceStatus::ForApproval);
});

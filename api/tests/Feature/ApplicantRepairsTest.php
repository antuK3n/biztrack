<?php

use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\PermitType;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The applicant's side of a return and a refusal, over the wire.
 *
 * ── Why this file exists ────────────────────────────────────────────────────
 *
 * Everything else written for these features calls the service directly or
 * posts to the OFFICER's endpoints. Nothing exercised the route the applicant
 * actually takes — open what was asked for, upload against the named row,
 * send it back — and that route crosses three authorisation gates the office
 * tests never touch: `ownerMayEdit`, `authorizeRequirementWrite`, and the
 * submit's own status rule.
 *
 * The refusal path in particular had never been run end to end at all. There
 * was no refused clearance in the register to try it on, so every claim about
 * it was read off the code.
 *
 * ── What it pins ────────────────────────────────────────────────────────────
 *
 * That the pointer written by the office is the pointer the applicant is
 * served; that the slot it names accepts a file from them; and that the
 * permit moves when they send it. Three links in one chain, any of which
 * would leave the feature looking built and not working — which is the
 * failure mode this codebase produced twice in one day.
 */

/** A paid filing with FSIC handed in and sitting with BFP. */
function filingWithFsicAtOffice(string $name): array
{
    $appId = scopedAssignmentFiling($name);
    $app = Application::findOrFail($appId);
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', 'FSIC')->firstOrFail();

    $workflow->startClearance($app->fresh(), $type, 'apply');
    satisfyChecklist($app->fresh(), $type);
    $workflow->submitClearanceForm($app->fresh(), $type);

    return [$app->fresh(), $type];
}

it('serves the applicant the rows the office named, and takes their new file', function () {
    Storage::fake('local');
    [$app, $type] = filingWithFsicAtOffice('Applicant Repair Co');
    $workflow = app(WorkflowService::class);

    $workflow->returnClearance(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'The occupancy certificate copy is not certified.',
        'FSIC_REQ_VALID_COO',
        ['FSIC_REQ_VALID_COO' => 'Not a certified true copy.'],
    );

    $owner = authAs('owner@biztrack.local');

    /*
     * What the applicant's dialog is drawn from. The pointer and the note
     * have to survive the trip to their screen, keyed the same way, or the
     * dialog lists nothing and the officer's instruction is invisible.
     */
    $rows = test()->withHeaders($owner)
        ->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()
        ->json('data');
    $row = collect($rows)->firstWhere('permit_type.code', 'FSIC');

    expect($row['state'])->toBe('returned')
        ->and($row['return_target'])->toBe('FSIC_REQ_VALID_COO')
        ->and($row['return_notes']['FSIC_REQ_VALID_COO'])->toBe('Not a certified true copy.');

    /*
     * And the slot it names takes a file from them. `ownerMayEdit` reopens
     * the sheet on Returned, and `authorizeRequirementWrite` has to agree —
     * two gates, neither exercised by any officer-side test.
     */
    test()->withHeaders($owner)
        ->post(
            "/api/v1/applications/{$app->id}/office-forms/FSIC/requirements/FSIC_REQ_VALID_COO",
            ['file' => UploadedFile::fake()->create('endorsement-signed.pdf', 40, 'application/pdf')],
        )
        ->assertSuccessful();

    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => [],
            'submit' => true,
        ])
        ->assertSuccessful();

    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)
        ->toBe(ClearanceStatus::ForApproval);
});

it('lets the applicant answer a REFUSAL the same way', function () {
    /*
     * Never run end to end before this. A refused permit is re-applied for
     * rather than returned, `submitClearanceForm` accepts Rejected as one of
     * its three starting states, and the refusal now carries a pointer — but
     * no test had walked the applicant through it, and there was no refused
     * clearance in the register to walk.
     */
    Storage::fake('local');
    [$app, $type] = filingWithFsicAtOffice('Applicant Refusal Co');
    $workflow = app(WorkflowService::class);

    $workflow->approveClearance($workflow->pivotFor($app->fresh(), 'FSIC'), 'Paperwork fine.');
    $workflow->rejectClearance(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'The occupancy certificate copy is not certified.',
        'Have it signed, then apply again.',
        'FSIC_REQ_VALID_COO',
        ['FSIC_REQ_VALID_COO' => 'Not a certified true copy.'],
    );

    $owner = authAs('owner@biztrack.local');
    $rows = test()->withHeaders($owner)
        ->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()
        ->json('data');
    $row = collect($rows)->firstWhere('permit_type.code', 'FSIC');

    expect($row['state'])->toBe('rejected')
        ->and($row['return_target'])->toBe('FSIC_REQ_VALID_COO')
        ->and($row['return_notes']['FSIC_REQ_VALID_COO'])->toBe('Not a certified true copy.');

    /* The sheet is theirs again — `ownerMayEdit` allows Rejected. */
    test()->withHeaders($owner)
        ->post(
            "/api/v1/applications/{$app->id}/office-forms/FSIC/requirements/FSIC_REQ_VALID_COO",
            ['file' => UploadedFile::fake()->create('endorsement-signed.pdf', 40, 'application/pdf')],
        )
        ->assertSuccessful();

    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => [],
            'submit' => true,
        ])
        ->assertSuccessful();

    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)
        ->toBe(ClearanceStatus::ForApproval);
});

it('tells the applicant when the office changes what it asked for', function () {
    /*
     * A return notifies and a refusal notifies; changing what either asked
     * for was silent until 30 September 2026. An office could add a second
     * field and the one person who has to act on it would find out only by
     * reopening a dialog they believe they have already answered.
     */
    [$app] = filingWithFsicAtOffice('Amend Notifies Co');
    $workflow = app(WorkflowService::class);

    $workflow->returnClearance(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'The occupancy certificate copy is not certified.',
        'FSIC_REQ_VALID_COO',
        [],
    );

    $before = $app->applicant->notifications()->count();

    $workflow->amendClearanceReturn(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'The completion certificate too.',
        'FSIC_REQ_VALID_COO,FSIC_REQ_COMPLETION',
        [],
    );

    expect($app->applicant->notifications()->count())->toBeGreaterThan($before);
});

it('lets an office correct a refusal it got wrong', function () {
    /*
     * Amend accepted Returned only, so an officer who refused and then found
     * they had ticked the wrong row had no way back — and a refusal suspends
     * the Business Permit while it stands, so a wrong one costs the applicant
     * more than a wrong return does, not less.
     */
    [$app] = filingWithFsicAtOffice('Refusal Amend Co');
    $workflow = app(WorkflowService::class);

    $workflow->approveClearance($workflow->pivotFor($app->fresh(), 'FSIC'), 'Paperwork fine.');
    $workflow->rejectClearance(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'The occupancy certificate copy is not certified.',
        'Have it signed.',
        'FSIC_REQ_VALID_COO',
        [],
    );

    $workflow->amendClearanceReturn(
        $workflow->pivotFor($app->fresh(), 'FSIC'),
        'Actually it is the completion certificate that is missing.',
        'FSIC_REQ_COMPLETION',
        ['FSIC_REQ_COMPLETION' => 'Not attached.'],
    );

    $row = $workflow->pivotFor($app->fresh(), 'FSIC');

    expect($row->status)->toBe(ClearanceStatus::Rejected)
        ->and($row->remarks_target)->toBe('FSIC_REQ_COMPLETION')
        /*
         * The refusal itself stands. Rewriting its record from an amend would
         * lose the fact that this permit was refused, which is what the office
         * re-reading it needs most.
         */
        ->and($row->rejected_at)->not->toBeNull()
        ->and($row->rejection_note)->toBe('The occupancy certificate copy is not certified.');
});

<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
use App\Models\Business;
use App\Models\Department;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Validation\ValidationException;

/*
 * Returning a permit: the note, the pointer, and who it reaches.
 *
 * ── The feature, and the two defects it was built on top of ───────────────
 *
 * The client settled the shape on 17 September 2026: Return is enough on its
 * own (clearance-level rejection was removed), the reason stays FREE TEXT
 * because there are thousands of possible reasons, and the system learns WHICH
 * thing the text is about from an optional pointer stored beside it — never by
 * reading the prose. *"How can a free text match what is specifically asked.
 * There could be database matching issues for this."*
 *
 * Two things were wrong before any of it was built, and both are asserted here
 * because neither is visible from the code that reads correctly:
 *
 *  1. The reason never reached the applicant. The card read
 *     `assignment.remarks`; `returnClearance` writes the PIVOT's `remarks`, and
 *     the assignment's are written on an APPROVAL — so the panel could show an
 *     approval note under a heading about changes being requested.
 *  2. BPLO could not return at Final Approval at all. `ForFinalApproval` had no
 *     `Returned` edge, so the button threw.
 */

/**
 * One clearance on a paid filing, filed and waiting on its office.
 *
 * A permit cannot be RETURNED from Not Yet Submitted — `ClearanceStatus`
 * allows `NotStarted → ForApproval` and nothing else — and the seeded
 * awaiting_other_permits filing has its clearances at `not_started`, because
 * nobody has applied for them. So the fixture has to apply and hand in the
 * sheet before there is anything an office could send back. Returning from
 * not_started was the first draft of these tests and the enum was right to
 * refuse it: an office cannot ask for changes to a form it has never been given.
 */
function clearanceAwaitingItsOffice(string $code): array
{
    /*
     * BUILT, not found. Two earlier drafts hunted the register for a filing in
     * the right state and failed twice for different reasons: the first
     * awaiting_other_permits filing belongs to another owner, so reading it
     * back as the applicant answered 403 (the boundary working, not a fixture
     * to widen), and the seed has no such filing for THIS owner at all.
     *
     * A fixture that depends on seeded state is a test that fails when somebody
     * else changes the seed, for reasons that have nothing to do with returns.
     */
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', $code)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
        'complexity' => 'complex',
        'complexity_set_by_user_id' => $owner->id,
    ]);
    $app->permitTypes()->sync(PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all());

    $workflow->submit($app->fresh());
    $workflow->approveMainForm($app->fresh());

    $assessment = $app->fresh()->feeAssessment()->firstOrFail();
    $workflow->onPaymentCompleted(Payment::create([
        'application_id' => $app->id,
        'fee_assessment_id' => $assessment->id,
        'amount' => $assessment->total_amount,
        'method' => 'gcash',
        'status' => 'completed',
        'reference' => 'RET-'.$code.'-'.$app->id,
        'paid_at' => now(),
    ]));

    // Applied for and handed in, so an office is holding something to send back.
    $workflow->startClearance($app->fresh(), $type, ApplicationPermitType::MODE_APPLY);
    $workflow->submitClearanceForm($app->fresh(), $type);

    return [$app->fresh(), $workflow, $type];
}

/** A business holding a live permit of each code, from one prior filing. */
function returnBusinessHolding(array $codes): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    $permits = [];
    foreach ($codes as $i => $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $permits[$code] = Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $business->id,
            'permit_type_id' => $type->id,
            'permit_number' => 'RET-'.$code.'-'.str_pad((string) (Permit::max('id') + $i + 1), 6, '0', STR_PAD_LEFT),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays(30 + ($i * 30)),
            'status' => 'active',
        ]);
    }

    return [$business, $permits];
}

it('sends the reason and the pointer to the applicant, off the pivot', function () {
    /*
     * An office returns its own clearance on a NEW filing — the ordinary case.
     * Driven through the service, and read back through the applicant's own
     * clearance payload, because the defect was entirely in which column the
     * screen read.
     */
    $owner = authAs('owner@biztrack.local');
    [$app, $workflow] = clearanceAwaitingItsOffice('SANITARY');

    $row = $workflow->pivotFor($app, 'SANITARY');
    $workflow->returnClearance(
        $row,
        'The scan is cut off at the bottom — the notary’s seal is not visible.',
        'ZONING_REQ_DECLARATION',
    );

    $rows = test()->withHeaders($owner)
        ->getJson("/api/v1/applications/{$app->id}/clearances")
        ->assertOk()
        ->json('data');

    $sanitary = collect($rows)->firstWhere('permit_type.code', 'SANITARY');

    expect($sanitary['state'])->toBe('returned');
    expect($sanitary['return_note'])->toContain('notary');
    expect($sanitary['return_target'])->toBe('ZONING_REQ_DECLARATION');
    // And WHEN, so both sides can say how long it has been waiting.
    expect($sanitary['returned_at'])->not->toBeNull();
});

it('replaces the pointer on every return, including with nothing', function () {
    /*
     * A stale target is worse than none: it would mark a row this return is not
     * about, the applicant would fix the wrong thing, and they would be returned
     * a second time for the same reason.
     */
    [$app, $workflow] = clearanceAwaitingItsOffice('SANITARY');
    $row = $workflow->pivotFor($app, 'SANITARY');

    $workflow->returnClearance($row, 'Wrong page.', 'ZONING_REQ_DECLARATION');
    expect($workflow->pivotFor($app->fresh(), 'SANITARY')->remarks_target)
        ->toBe('ZONING_REQ_DECLARATION');

    $workflow->returnClearance($workflow->pivotFor($app->fresh(), 'SANITARY'), 'Ring us instead.');
    expect($workflow->pivotFor($app->fresh(), 'SANITARY')->remarks_target)->toBeNull();
});

it('clears the note when the applicant answers it, and keeps the date', function () {
    /*
     * The instruction is discharged once they resubmit — leaving it up would
     * highlight a row they have just fixed. `returned_at` survives, because it
     * stops being "how long have they sat on this" and becomes the record that
     * this permit was sent back once.
     */
    [$app, $workflow, $type] = clearanceAwaitingItsOffice('SANITARY');

    $workflow->returnClearance($workflow->pivotFor($app, 'SANITARY'), 'Fix the scan.', 'X_DOC');
    $workflow->submitClearanceForm($app->fresh(), $type);

    $row = $workflow->pivotFor($app->fresh(), 'SANITARY');
    expect($row->status)->toBe(ClearanceStatus::ForApproval);
    expect($row->remarks)->toBeNull();
    expect($row->remarks_target)->toBeNull();
    expect($row->returned_at)->not->toBeNull();
});

it('lets BPLO send back one uploaded clearance and keeps the filing on its desk', function () {
    /*
     * The client's decision for Final Approval on a renewal: return that one
     * clearance, not the whole filing. Before the `Returned` edge existed BPLO's
     * Return threw; before `outstandingClearances` read the status over the
     * mode, the return was cosmetic and BPLO could approve on the very copy it
     * had just rejected.
     */
    $codes = ['BUSINESS', 'SANITARY', 'FSIC', 'OCCUPANCY', 'CEC', 'ZONING'];
    [$business, $permits] = returnBusinessHolding($codes);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $workflow = app(WorkflowService::class);

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $permits['BUSINESS']->id,
        'complexity' => 'complex',
        'complexity_set_by_user_id' => $owner->id,
    ]);
    $app->priorPermits()->sync(collect($permits)->pluck('id')->all());
    $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id')->all());
    $workflow->submit($app->fresh());

    /*
     * Paid FIRST, then the copies go in. A renewal carrying the business permit
     * IS billed, so `defersPayment()` is false and its clearance stage stays
     * gated on payment like any other filing's — uploading before this is
     * refused with "The other permits open once this application is paid", which
     * is how the first draft of this test found out.
     */
    $workflow->approveMainForm($app->fresh());
    $assessment = $app->feeAssessment()->firstOrFail();
    $workflow->onPaymentCompleted(Payment::create([
        'application_id' => $app->id,
        'fee_assessment_id' => $assessment->id,
        'amount' => $assessment->total_amount,
        'method' => 'gcash',
        'status' => 'completed',
        'reference' => 'RET-JAN-1',
        'paid_at' => now(),
    ]));
    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval);

    // The five clearances handed in as copies the applicant already holds.
    foreach (['SANITARY', 'FSIC', 'OCCUPANCY', 'CEC', 'ZONING'] as $code) {
        $workflow->startClearance(
            $app->fresh(),
            PermitType::where('code', $code)->firstOrFail(),
            ApplicationPermitType::MODE_UPLOAD,
        );
    }

    /*
     * No office was asked to read any of them — the other half of the same
     * decision, and the half that was missing until `startClearance` learned
     * to skip the routing on a renewal upload.
     */
    $offices = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', '!=', 'BPLO'))
        ->count();
    expect($offices)->toBe(0);

    // BPLO reads the copies and sends ONE back, naming it with the pointer.
    $bplo = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->firstOrFail();

    $workflow->returnAssignment($bplo, 'Your FSIC expired in March. Upload the current one.', 'FSIC');

    // That one permit, not the filing.
    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)->toBe(ClearanceStatus::Returned);
    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval);
    expect($workflow->pivotFor($app->fresh(), 'SANITARY')->status)->not->toBe(ClearanceStatus::Returned);

    // And Approve is shut until it is replaced.
    expect(fn () => $workflow->approveOverall($app->fresh(), 'Trying anyway.'))
        ->toThrow(ValidationException::class);

    // The applicant re-uploads; the filing can be approved again.
    $workflow->startClearance(
        $app->fresh(),
        PermitType::where('code', 'FSIC')->firstOrFail(),
        ApplicationPermitType::MODE_UPLOAD,
    );
    $workflow->approveOverall($app->fresh(), 'Current copy received.');
    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

it('falls back to returning the whole form when BPLO points at nothing', function () {
    /*
     * The safe direction. A pointer naming a permit the filing does not carry —
     * a stale tab, a renamed code — gives BPLO the behaviour it had before
     * rather than silently doing nothing.
     */
    /*
     * Built rather than found. The register has no filing sitting at
     * For Initial Approval — the seeded ones are all further along — and a test
     * that hunts for a status nothing is in fails with "No query results",
     * which says nothing about returns.
     */
    [$business, $permits] = returnBusinessHolding(['BUSINESS']);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $workflow = app(WorkflowService::class);

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $permits['BUSINESS']->id,
        'complexity' => 'complex',
        'complexity_set_by_user_id' => $owner->id,
    ]);
    $app->priorPermits()->sync([$permits['BUSINESS']->id]);
    $app->permitTypes()->sync(PermitType::where('code', 'BUSINESS')->pluck('id')->all());
    $workflow->submit($app->fresh());

    $bplo = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();

    $workflow->returnAssignment($bplo, 'The address does not match the plan.', 'NOT_A_PERMIT');

    expect($app->fresh()->status)->toBe(ApplicationStatus::Returned);
});

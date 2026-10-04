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
 * approved filing has its clearances at `not_started`, because
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
     * approved filing belongs to another owner, so reading it
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
    // The checklist is complete before the sheet goes in — the submit
    // refuses one that is not. See satisfyChecklist() in Pest.php.
    satisfyChecklist($app->fresh(), $type);
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

it('lets BPLO send back one clearance by name, and not the whole filing', function () {
    /*
     * Return that ONE clearance, not the whole filing. Before the `Returned`
     * edge existed BPLO's Return threw.
     *
     * ── Written for a renewal, moved to an amendment on 3 October 2026 ──
     *
     * The scenario was BPLO reading uploaded copies at Final Approval on a
     * renewal. Both halves of that are gone: the client removed the upload
     * on a renewal (*"an admin verifying an uploaded other permit will be
     * useless if the system already tells them whether they are still valid
     * or not"*) and a renewal no longer reaches Final Approval at all.
     *
     * The amendment is the one filing type where the whole scenario is
     * still real — it may hand in copies, and it goes back to BPLO after
     * payment because the LGU's own form says to. So the capability under
     * test is unchanged and only its vehicle has moved.
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
    /*
     * Retyped only NOW, between BPLO's first approval and the payment.
     *
     * An amendment built properly must name the detail it changes and
     * carries the business permit ALONE — neither of which gives the
     * five-clearance filing this scenario is about. Submitting as a renewal
     * and retyping leaves the filing in the shape BPLO actually meets.
     *
     * AFTER `approveMainForm`, because that method sends an amendment to
     * `approveAmendment` and completes it in one act — there would be no
     * Final Approval left to return anything at. BEFORE the payment,
     * because `onPaymentCompleted` is what routes an amendment to BPLO.
     */
    $app->forceFill(['application_type' => 'amendment'])->saveQuietly();

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

    /*
     * The five clearances applied for, each sheet handed in.
     *
     * They were uploaded copies until 4 October 2026, when the client had
     * that route removed — *"IT IS NOT POSSIBLE FOR THE USER TO SUBMIT A COPY
     * OF AN OTHER PERMIT."* Applying is the only way in now, and it takes two
     * calls rather than one: `startClearance` returns early on a permit whose
     * office has a form, so the office is routed when the FORM is handed in
     * and not before. What this test needs is five routed offices, which is
     * what the second call produces.
     */
    foreach (['SANITARY', 'FSIC', 'OCCUPANCY', 'CEC', 'ZONING'] as $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $workflow->startClearance($app->fresh(), $type, ApplicationPermitType::MODE_APPLY);
        satisfyChecklist($app->fresh(), $type);
        $workflow->submitClearanceForm($app->fresh(), $type);
    }

    /*
     * Each office IS asked to read its own copy, and that is the amendment
     * behaving as the 6 September 2026 decision says — *"the LGU inspects
     * the premises, not the paperwork"* — so a handed-in certificate still
     * sends the office out.
     *
     * This asserted ZERO while the scenario was a renewal: `startClearance`
     * skipped the routing there, because the visit behind last year's
     * certificate had already happened. That skip was tied to an uploaded
     * copy on a renewal, and with uploads removed on 4 October 2026 it went
     * with them — every started permit routes to its office now.
     */
    $offices = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', '!=', 'BPLO'))
        ->count();
    expect($offices)->toBe(5);

    // BPLO reads the filing and sends ONE permit back, naming it with the pointer.
    $bplo = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->firstOrFail();

    $workflow->returnAssignment($bplo, 'Your FSIC expired in March. Upload the current one.', 'FSIC');

    /*
     * ── That one permit, not the filing — which is the whole claim ────
     *
     * The pointer names FSIC, so FSIC is returned and SANITARY beside it is
     * untouched. A Return without a pointer sends back the whole form, and
     * telling an applicant to redo six answers because one certificate was
     * stale is the thing this mechanism exists to avoid.
     */
    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)->toBe(ClearanceStatus::Returned);
    expect($workflow->pivotFor($app->fresh(), 'SANITARY')->status)->not->toBe(ClearanceStatus::Returned);

    /*
     * The FILING is at AwaitingOtherPermits, and the test name's 'keeps it
     * on BPLO's desk' is no longer the right description.
     *
     * It sat at Final Approval because an UPLOADED copy counted as
     * satisfied, so five uploads made the filing ready and BPLO held it.
     * That rule went on 3 October 2026 at the client's request, so five
     * uploads now leave five clearances outstanding and `refreshReadiness`
     * walks the filing back to the stage that is honestly true of it.
     *
     * Asserted rather than dropped: walking BACK out of Final Approval when
     * a permit stops qualifying is its own mechanism, and this is the only
     * test that exercises it.
     */
    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);

    // And Approve is shut until it is replaced.
    expect(fn () => $workflow->approveOverall($app->fresh(), 'Trying anyway.'))
        ->toThrow(ValidationException::class);

    /*
     * ── Answering BPLO does NOT reopen approval ──────────────────────
     *
     * This used to end by handing in a fresh copy and approving on it,
     * because an upload satisfied the requirement by its MODE. That rule
     * went on 3 October 2026 — it was a quieter way for a filing to be
     * called satisfied with no office having approved anything — and the
     * upload route itself went on 4 October, at the client's instruction.
     *
     * So the applicant applies again, which is the only answer available
     * to them now, and the filing is still not approvable: the office that
     * issues an FSIC has not issued one.
     */
    $fsic = PermitType::where('code', 'FSIC')->firstOrFail();
    $workflow->startClearance($app->fresh(), $fsic, ApplicationPermitType::MODE_APPLY);
    satisfyChecklist($app->fresh(), $fsic);
    $workflow->submitClearanceForm($app->fresh(), $fsic);

    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)
        ->not->toBe(ClearanceStatus::Returned);

    expect(fn () => $workflow->approveOverall($app->fresh(), 'Current copy received.'))
        ->toThrow(ValidationException::class);
});

/**
 * A filing sitting at For Approval with BPLO's own assignment on it.
 *
 * Built rather than found. The register has no filing at For Approval — the
 * seeded ones are all further along — and a test that hunts for a status
 * nothing is in fails with "No query results", which says nothing about
 * returns.
 *
 * @return array{0: Application, 1: ApplicationAssignment}
 */
function bploFilingAtForApproval(): array
{
    [$business, $permits] = returnBusinessHolding(['BUSINESS']);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

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
    app(WorkflowService::class)->submit($app->fresh());

    $bplo = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();

    return [$app, $bplo];
}

it('falls back to returning the whole form when BPLO points at nothing', function () {
    /*
     * The safe direction. A pointer naming a permit the filing does not carry —
     * a stale tab, a renamed code — gives BPLO the behaviour it had before
     * rather than silently doing nothing.
     */
    [$app, $bplo] = bploFilingAtForApproval();

    app(WorkflowService::class)
        ->returnAssignment($bplo, 'The address does not match the plan.', 'NOT_A_PERMIT');

    expect($app->fresh()->status)->toBe(ApplicationStatus::Returned);
});

it('records which field of the main form BPLO wants fixed', function () {
    /*
     * Client, 24 September 2026: *"allow me to choose a field that the business
     * owner will have to comply to. Then, I should also put a reason why."*
     *
     * Both halves are asserted, because the pointer without the prose names a
     * field and gives no reason, and the prose without the pointer is the
     * behaviour this replaced.
     */
    [$app, $bplo] = bploFilingAtForApproval();

    app(WorkflowService::class)->returnAssignment(
        $bplo,
        'The trade name does not match your DTI certificate.',
        'form:trade_name',
    );

    expect($app->fresh()->status)->toBe(ApplicationStatus::Returned);
    expect($bplo->fresh()->remarks_target)->toBe('form:trade_name');
    expect($bplo->fresh()->remarks)->toBe('The trade name does not match your DTI certificate.');
});

it('replaces the main form pointer on every return, including with nothing', function () {
    /*
     * The rule `returnClearance` already follows, applied to the main form: a
     * stale target from a previous round flags a field this return is not
     * about, so the applicant fixes the wrong thing and is returned twice.
     *
     * The filing has to be resubmitted between the two returns — Returned is
     * not a state a second return can be made from — which is also the real
     * sequence this guards: fix, resubmit, get sent back for something else.
     */
    [$app, $bplo] = bploFilingAtForApproval();
    $workflow = app(WorkflowService::class);

    $workflow->returnAssignment($bplo, 'Trade name is wrong.', 'form:trade_name');
    expect($bplo->fresh()->remarks_target)->toBe('form:trade_name');

    $workflow->resubmit($app->fresh());
    $workflow->returnAssignment($bplo->fresh(), 'Now the barangay is wrong.');

    expect($bplo->fresh()->remarks_target)->toBeNull();
    expect($bplo->fresh()->remarks)->toBe('Now the barangay is wrong.');
});

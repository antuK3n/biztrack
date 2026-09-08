<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Exceptions\IllegalTransitionException;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationStatusHistory;
use App\Models\Barangay;
use App\Models\Inspection;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\WorkflowService;

/*
 * A terminal filing cannot be brought back to life (INS-5).
 *
 * This was OBSERVED before it was fixed, not reasoned about. Against a
 * throwaway copy of the e2e database, approving one office's still-open review
 * on a filing already REJECTED produced:
 *
 *     BEFORE: rejected
 *     AFTER : for_inspection
 *     HISTORY ROW: rejected -> for_inspection
 *     INSPECTIONS BOOKED: 1
 *
 * and the same call on a `returned` filing moved it on too, silently cancelling
 * the applicant's revision request. A SELECT over the real register found 101
 * rejected filings and 2 returned ones carrying a still-approvable assignment,
 * because rejectApplication() deliberately does not touch assignments — every
 * office that had not finished reading keeps a `pending` row for good, and that
 * row was a live Approve button.
 *
 * Why nothing caught it: WorkflowService::transition() had no legality table at
 * all, its only guard being `if ($from === $to) return;`. Every existing
 * workflow suite drives filings forward through legal states, so a machine that
 * permits every edge passes all of them. The cases below are the ones that only
 * fail when the table is missing — they approach a terminal or applicant-held
 * filing from the side.
 *
 * ── What the September 2026 flow changed here ─────────────────────────────
 *
 * The hole and the guard are unchanged; the states around them are not. Three
 * cases below used to be written against `under_review` and `for_inspection` as
 * APPLICATION statuses, and those are retired — they described work belonging to
 * one permit, which now carries its own `ClearanceStatus` on the pivot row. So
 * the "another office approves a dead filing" case is approached through a
 * clearance office that was routed when the applicant opened its permit, and the
 * "returned" case splits in two: an OP returning ITS permit no longer returns
 * the application at all, which is a stronger version of the same guarantee.
 *
 * One refusal changed shape rather than strength. Approving on a terminal filing
 * is refused by `approveAssignment()`'s own guard, which throws a
 * ValidationException (422), before `transition()` and its
 * IllegalTransitionException (409) are ever reached. Both refuse and both write
 * nothing; the 409 is still asserted directly at the service below, which is
 * where that exception is actually reachable.
 */

/** Which office issues each permit, and which account speaks for it. */
const LEGALITY_OFFICE = [
    'SANITARY' => ['CHO', 'sanitary@biztrack.local'],
    'FSIC' => ['BFP', 'fire@biztrack.local'],
    'ZONING' => ['CPDO', 'zoning@biztrack.local'],
    'OCCUPANCY' => ['OBO', 'obo@biztrack.local'],
    'CEC' => ['CENRO', 'cenro@biztrack.local'],
];

/** A paid filing with these permits opened, so their offices are routed. */
function legalityFiling(array $openCodes, string $name): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name.' '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Legality Lane', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that, and
    // `bploApprovesForm` also settles the RA 11032 category the offices need.
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    foreach ($openCodes as $code) {
        authAs('owner@biztrack.local');
        test()->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")->assertOk();
    }

    return Application::findOrFail($appId);
}

/** A filing still on BPLO's desk, before any money is asked for. */
function legalityFilingForApproval(string $name): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name.' '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Legality Lane', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    return Application::findOrFail($appId);
}

/** This office's assignment id on this filing. */
function legalityAssignmentId(Application $app, string $departmentCode): int
{
    $id = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', $departmentCode))
        ->value('id');

    expect($id)->not->toBeNull("{$departmentCode} has no assignment on this filing");

    return $id;
}

it('refuses to revive a rejected filing when another office approves its permit', function () {
    $app = legalityFiling(['SANITARY', 'FSIC'], 'Legality Rejected Cafe');
    $choAssignment = legalityAssignmentId($app, 'CHO');

    // BPLO rejects. This is terminal, and it deliberately leaves CHO's and BFP's
    // reviews `pending` — a record of what was outstanding when the decision
    // came, not a task list.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])
        ->assertOk();

    expect(ApplicationAssignment::find($choAssignment)->status->value)->toBe('pending');

    /*
     * The exact call that used to answer 200 and resurrect the filing. 422 here
     * rather than the table's 409 because approveAssignment() carries its own
     * terminal guard and reaches it first — "This application has been decided
     * and can no longer be approved." The refusal is what matters and it is
     * loud; the 409 path is asserted at the service below.
     */
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$choAssignment}/approve", ['remarks' => 'Cleared.'])
        ->assertStatus(422);

    expect($app->fresh()->status)->toBe(ApplicationStatus::Rejected);

    // Nothing was written on the way to the refusal. The assignment must still
    // be pending — marking it completed and only THEN failing the status change
    // would leave a rejected filing carrying an approval nobody made.
    expect(ApplicationAssignment::find($choAssignment)->status->value)->toBe('pending')
        ->and(ApplicationAssignment::find($choAssignment)->completed_at)->toBeNull();

    /*
     * No visit booked against a filing the LGU refused, no permit minted, and no
     * history row claiming movement off `rejected`. The old assertion named
     * `for_inspection` as the status it must not reach; that status no longer
     * exists on this machine, so the honest form of the same claim is that
     * NOTHING follows the rejection.
     */
    expect(Inspection::where('application_id', $app->id)->count())->toBe(0)
        ->and(Permit::where('application_id', $app->id)->count())->toBe(0)
        ->and(ApplicationStatusHistory::where('application_id', $app->id)
            ->where('from_status', 'rejected')->count())->toBe(0);
});

it('returns one permit without returning the application or disturbing the other offices', function () {
    /*
     * The `returned` half of INS-5, and the flow has made the guarantee
     * stronger rather than weaker.
     *
     * It used to be that an office returning its assignment sent the whole
     * APPLICATION to `returned`, so a second office approving afterwards could
     * cancel the applicant's revision request by dragging the filing forward.
     * An OP now returns only its own permit: `returnAssignment()` routes to
     * `returnClearance()` for anyone who is not BPLO, and the application's
     * status is never touched. There is no longer a shared status for the
     * second office to move.
     */
    $app = legalityFiling(['SANITARY', 'FSIC'], 'Legality Returned Cafe');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'CHO').'/return', [
            'remarks' => 'Water potability certificate is expired.',
        ])->assertOk();

    $workflow = app(WorkflowService::class);

    // CHO's permit went back to the applicant; the filing did not.
    expect($workflow->pivotFor($app->fresh(), 'SANITARY')->status)->toBe(ClearanceStatus::Returned);
    expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);

    /*
     * BFP approves its own permit while CHO's sits returned. This is ALLOWED —
     * the fire office genuinely has finished reading, and refusing to record
     * that would lose real work — and it moves only FSIC.
     */
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'BFP').'/approve', ['remarks' => 'Cleared.'])
        ->assertOk();

    expect($workflow->pivotFor($app->fresh(), 'FSIC')->status)->toBe(ClearanceStatus::ForInspection);
    expect($workflow->pivotFor($app->fresh(), 'SANITARY')->status)->toBe(ClearanceStatus::Returned);
    expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);

    // And no inspector is sent to premises whose paperwork is mid-revision:
    // BFP has not picked a date, and CHO cannot until its permit is read again.
    expect(Inspection::where('application_id', $app->id)->count())->toBe(0);
});

it('sends a form BPLO returned back to for_approval when the applicant resubmits', function () {
    /*
     * The application-level `returned` state has exactly one source now — BPLO
     * returning the main form before payment — and exactly one way out.
     * `resubmit()` is the only thing that restores a returned assignment, and
     * the applicant's Resubmit button only renders on a returned filing, so an
     * edge that skipped `for_approval` would strand them.
     */
    $app = legalityFilingForApproval('Legality Resubmit Cafe');
    expect($app->status)->toBe(ApplicationStatus::ForApproval);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'BPLO').'/return', [
            'remarks' => 'The line of business does not match the PSIC code given.',
        ])->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Returned);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/resubmit")->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval);

    /*
     * Returned must not reach PendingPayment directly. The whole point of
     * returning a form is that BPLO has not accepted it, and billing for it
     * would say they had.
     */
    expect(ApplicationStatus::Returned->canTransitionTo(ApplicationStatus::PendingPayment))->toBeFalse();
});

it('refuses an illegal transition at the service, whatever the caller', function () {
    // transition() is the only write path for applications.status, so the table
    // has to hold there and not merely on the one route that exposed it. A
    // future endpoint gets this for free; that is why the check is not in
    // AssignmentController.
    $app = legalityFiling(['SANITARY'], 'Legality Service Cafe');

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])
        ->assertOk();

    // Any target at all, since a terminal status lists none. PendingPayment
    // stands in for the retired `under_review` the old case used.
    expect(fn () => app(WorkflowService::class)
        ->transition($app->fresh(), ApplicationStatus::PendingPayment, 'by hand'))
        ->toThrow(IllegalTransitionException::class);

    expect($app->fresh()->status)->toBe(ApplicationStatus::Rejected);
});

it('will not issue a second set of permits for a filing already approved', function () {
    /*
     * The duplicate-permit path the legality table closes as a side effect.
     *
     * A replayed inspection result does not fail loudly — transition() no-ops on
     * Approved → Approved — so without a guard it would succeed quietly and the
     * extra permits would be real, with real numbers. Two things stop it now:
     * `grantClearance()` is only reached from a pivot row still sitting at
     * `for_inspection`, and that row is `approved` the moment its permit was
     * minted.
     */
    $codes = array_keys(LEGALITY_OFFICE);
    $app = legalityFiling($codes, 'Legality Duplicate Cafe');

    $lastVisit = null;
    foreach ($codes as $code) {
        [$deptCode, $email] = LEGALITY_OFFICE[$code];

        test()->withHeaders(authAs($email))
            ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, $deptCode).'/approve', ['remarks' => 'Cleared.'])
            ->assertOk();

        $lastVisit = test()->withHeaders(authAs($email))
            ->postJson("/api/v1/applications/{$app->id}/permits/{$code}/inspection", [
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ])->assertCreated()->json('data.id');

        test()->withHeaders(authAs($email))
            ->postJson("/api/v1/inspections/{$lastVisit}/conduct", ['result' => 'passed'])
            ->assertOk();
    }

    // BPLO's second act mints the Mayor's Permit on top of the five.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'BPLO').'/approve', ['remarks' => 'All in.'])
        ->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
    $issued = Permit::where('application_id', $app->id)->count();
    expect($issued)->toBe(6);

    // Replay the result on the already-issued filing, at the service, the way a
    // late re-inspection would arrive.
    app(WorkflowService::class)->recordInspection(
        Inspection::findOrFail($lastVisit),
        InspectionResult::Passed,
        'Re-checked after issuance.',
    );

    expect(Permit::where('application_id', $app->id)->count())->toBe($issued);
});

it('lets no status follow a terminal one', function (string $status) {
    // The table itself, stated once so the three terminal cases cannot drift
    // apart. `cancelled` has no HTTP case above because cancel() is only offered
    // before payment, so a cancelled filing never has an open clearance
    // assignment to approve — the hole is the same shape and is closed here.
    expect(ApplicationStatus::from($status)->allowedNext())->toBe([])
        ->and(ApplicationStatus::from($status)->isTerminal())->toBeTrue();
})->with(['approved', 'rejected', 'cancelled']);

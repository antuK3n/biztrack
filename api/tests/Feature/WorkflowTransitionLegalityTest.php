<?php

use App\Enums\ApplicationStatus;
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
 * and the same call on a `returned` filing moved it to `for_inspection`,
 * silently cancelling the applicant's revision request. A SELECT over the real
 * register found 101 rejected filings and 2 returned ones carrying a
 * still-approvable assignment, because rejectApplication() deliberately does not
 * touch assignments — every office that had not finished reading keeps a
 * `pending` row for good, and that row was a live Approve button.
 *
 * Why nothing caught it: WorkflowService::transition() had no legality table at
 * all, its only guard being `if ($from === $to) return;`. Every existing
 * workflow suite drives filings forward through legal states, so a machine that
 * permits every edge passes all of them. The cases below are the ones that only
 * fail when the table is missing — they approach a terminal or applicant-held
 * filing from the side.
 *
 * The regression to watch for: this hole was UNREACHABLE before 5da4daa,
 * because afterReviewProgress() opened with "every assignment is completed, or
 * return" and a rejected filing's assignments are not completed. Deleting that
 * line for an unrelated and correct reason uncovered it. If a future change
 * deletes a guard and these go red, the answer is not to relax them.
 */

/** A paid, routed filing carrying exactly these permit types. Reviews untouched. */
function legalityFiling(array $permitCodes, string $name): Application
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
        'permit_type_ids' => PermitType::whereIn('code', $permitCodes)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    // The office confirms the processing category on receipt, which is what
    // unlocks Approve. What these cases are about is the transition table, so
    // the category has to be a settled part of the fixture rather than a step.
    classifyAsOfficer(Application::findOrFail($appId));

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

it('refuses to revive a rejected filing when another office approves its review', function () {
    $app = legalityFiling(['BUSINESS', 'SANITARY', 'FSIC'], 'Legality Rejected Cafe');
    $choAssignment = legalityAssignmentId($app, 'CHO');

    // BPLO rejects. This is terminal, and it deliberately leaves CHO's and BFP's
    // reviews `pending` — a record of what was outstanding when the decision
    // came, not a task list.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])
        ->assertOk();

    expect(ApplicationAssignment::find($choAssignment)->status->value)->toBe('pending');

    // The exact call that used to answer 200 and resurrect the filing.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$choAssignment}/approve", ['remarks' => 'Cleared.'])
        ->assertStatus(409);

    // 409 rather than a silent no-op: the officer is told the filing moved under
    // them, which is the whole difference between a refusal and a bug.
    expect($app->fresh()->status)->toBe(ApplicationStatus::Rejected);

    // Nothing was written on the way to the refusal. The assignment must still
    // be pending — marking it completed and only THEN failing the status change
    // would leave a rejected filing carrying an approval nobody made.
    expect(ApplicationAssignment::find($choAssignment)->status->value)->toBe('pending')
        ->and(ApplicationAssignment::find($choAssignment)->completed_at)->toBeNull();

    // No visit booked against a filing the LGU refused, and no history row
    // claiming movement that did not happen.
    expect(Inspection::where('application_id', $app->id)->count())->toBe(0)
        ->and(
            ApplicationStatusHistory::where('application_id', $app->id)
                ->where('to_status', 'for_inspection')->count()
        )->toBe(0);
});

it('leaves a returned filing with the applicant when another office approves its review', function () {
    $app = legalityFiling(['BUSINESS', 'SANITARY', 'FSIC'], 'Legality Returned Cafe');

    // CHO returns the filing for revision. The applicant now owns it.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'CHO').'/return', [
            'remarks' => 'Water potability certificate is expired.',
        ])->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Returned);

    /*
     * BFP approves its own review while the filing sits returned. This is
     * ALLOWED — the fire office genuinely has finished reading, and refusing to
     * record that would lose real work — but it must not move the filing.
     * Before the fix it went `returned → for_inspection`, which cancelled the
     * applicant's revision request with no message and no way back: resubmit()
     * is the only thing that restores a returned assignment, and the applicant's
     * Resubmit button only renders on a returned filing.
     */
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'BFP').'/approve', ['remarks' => 'Cleared.'])
        ->assertOk();

    expect(ApplicationAssignment::find(legalityAssignmentId($app, 'BFP'))->status->value)->toBe('completed')
        ->and($app->fresh()->status)->toBe(ApplicationStatus::Returned);

    // And no inspector is sent to premises whose paperwork is mid-revision.
    expect(Inspection::where('application_id', $app->id)->count())->toBe(0);

    // The applicant can still do the thing the return asked them to do.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/resubmit")->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::UnderReview);
});

it('still moves a filing under review to for inspection when an office approves', function () {
    // The guard must refuse the illegal edge without touching the legal one —
    // this is the parallel-booking behaviour 5da4daa shipped, unchanged.
    $app = legalityFiling(['BUSINESS', 'SANITARY'], 'Legality Happy Cafe');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'CHO').'/approve', ['remarks' => 'Cleared.'])
        ->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForInspection)
        ->and(Inspection::where('application_id', $app->id)->count())->toBe(1);
});

it('refuses an illegal transition at the service, whatever the caller', function () {
    // transition() is the only write path for applications.status, so the table
    // has to hold there and not merely on the one route that exposed it. A
    // future endpoint gets this for free; that is why the check is not in
    // AssignmentController.
    $app = legalityFiling(['BUSINESS', 'SANITARY'], 'Legality Service Cafe');

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])
        ->assertOk();

    expect(fn () => app(WorkflowService::class)
        ->transition($app->fresh(), ApplicationStatus::UnderReview, 'by hand'))
        ->toThrow(IllegalTransitionException::class);

    expect($app->fresh()->status)->toBe(ApplicationStatus::Rejected);
});

it('will not issue a second set of permits for a filing already approved', function () {
    /*
     * The duplicate-permit path the legality table closes as a side effect.
     *
     * approveAndIssue() mints a Permit row per permit type and only THEN calls
     * transition(), which no-ops on Approved → Approved. So a second run does
     * not fail loudly — it succeeds quietly and the extra permits are real, with
     * real numbers. The stop has to be isFullyCleared(), which now asks the
     * table whether this filing may still reach Approved at all.
     */
    $app = legalityFiling(['BUSINESS', 'SANITARY'], 'Legality Duplicate Cafe');

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'BPLO').'/approve', ['remarks' => 'Cleared.'])
        ->assertOk();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/assignments/'.legalityAssignmentId($app, 'CHO').'/approve', ['remarks' => 'Cleared.'])
        ->assertOk();

    $visit = Inspection::where('application_id', $app->id)->firstOrFail();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/inspections/{$visit->id}/conduct", ['result' => 'passed'])
        ->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
    $issued = Permit::where('application_id', $app->id)->count();
    expect($issued)->toBe(2);

    // Replay the result on the already-issued filing, at the service, the way a
    // late re-inspection would arrive.
    app(WorkflowService::class)->recordInspection(
        $visit->fresh(),
        InspectionResult::Passed,
        'Re-checked after issuance.',
    );

    expect(Permit::where('application_id', $app->id)->count())->toBe($issued);
});

it('lets no status follow a terminal one', function (string $status) {
    // The table itself, stated once so the three terminal cases cannot drift
    // apart. `cancelled` has no HTTP case above because cancel() is only offered
    // before review starts, so a cancelled filing never has an assignment to
    // approve — the hole is the same shape and is closed here.
    expect(ApplicationStatus::from($status)->allowedNext())->toBe([])
        ->and(ApplicationStatus::from($status)->isTerminal())->toBeTrue();
})->with(['approved', 'rejected', 'cancelled']);

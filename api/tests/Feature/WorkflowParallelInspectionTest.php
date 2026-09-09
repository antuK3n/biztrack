<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Inspection;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\WorkflowService;
use Illuminate\Validation\ValidationException;

/*
 * The five other permits run independently, and nothing waits for the slowest.
 *
 * The client's question was "when I approved a sanitary permit, why did it not
 * automatically go to inspection?", and the original answer was a single line in
 * WorkflowService::afterReviewProgress: it returned early unless EVERY
 * assignment on the filing was `completed`, so nothing happened until the last
 * office had finished reading. City Health could not visit a premises it had
 * already cleared on paper because another office had not opened its form.
 *
 * The September 2026 flow answers that question properly, by splitting status
 * into two machines (docs/application-flow-2026-09.md). This file used to pin
 * "each office's visit is booked when that office approves, and the FILING reads
 * For Inspection from the first booking". Both halves of that are now wrong:
 *
 *  - approving the paperwork books NOTHING. It moves that one permit to
 *    `for_inspection` and stops; the office picks the date as a separate act.
 *  - `for_inspection` is not an application status any more. It belongs to one
 *    permit, on the `application_permit_types` row. An application cannot be
 *    "For Inspection" as a whole when CHO is inspecting, BFP is still reading
 *    and CPDO has already issued.
 *
 * So what is pinned below is the independence itself, stated against the machine
 * that now carries it: one office's act moves one permit, each permit is
 * released by its own office the moment that office passes it, and the
 * application reaches BPLO's desk only when the last of the five is in.
 */

/** Which office issues each permit, and which account speaks for it. */
const PARALLEL_OFFICE = [
    'SANITARY' => ['CHO', 'sanitary@biztrack.local'],
    'FSIC' => ['BFP', 'fire@biztrack.local'],
    'ZONING' => ['CPDO', 'zoning@biztrack.local'],
    'OCCUPANCY' => ['OBO', 'obo@biztrack.local'],
    'CEC' => ['CENRO', 'cenro@biztrack.local'],
];

/**
 * A paid filing on which the applicant has opened exactly these permits, so
 * exactly those offices are routed. Reviews untouched.
 *
 * Driven through the real endpoints rather than built from factories, because
 * what is under test is the order in which WorkflowService does things — a
 * hand-assembled filing would prove nothing about the path that assembles it.
 *
 * Note that the permit SET is not what `$openCodes` controls. Submission
 * attaches all five required clearances whatever the applicant asked for; what
 * varies here is which of them have been filed, and therefore which offices
 * have a queue item at all.
 */
function parallelFiling(array $openCodes, string $name): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name.' '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '4 Parallel Way', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO reads the form before the applicant is asked for money, and
    // `bploApprovesForm` also puts an officer's name to the RA 11032 category —
    // without which no office may approve at all.
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    /*
     * Two acts per permit, because that is now what filing one takes. Apply
     * OPENS the office's sheet and records that the applicant chose to fill it
     * in; handing the sheet back is what submits the permit, moves it to
     * ForApproval and routes the office (WorkflowService::submitClearanceForm).
     * Apply alone used to do all of it, and told two different people something
     * untrue — the applicant read "For Approval" on a form they had not touched,
     * the office opened a queue row with no answers on it.
     *
     * Every code here bears a form (PermitType::OFFICE_FORM_CODES), so every one
     * of them needs both calls to reach an office at all. The sheets are posted
     * empty on purpose: this file is about which office moves which permit, and
     * the answers on the form are OfficeFormTest's subject.
     */
    foreach ($openCodes as $code) {
        authAs('owner@biztrack.local');
        test()->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")->assertOk();
        test()->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
            'form_data' => [],
            'submit' => true,
        ])->assertSuccessful();
    }

    return Application::findOrFail($appId);
}

/** The office behind $code signs off that permit's paperwork. */
function approveOfficePaperwork(Application $app, string $code): void
{
    [$deptCode, $email] = PARALLEL_OFFICE[$code];

    $assignmentId = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', $deptCode))
        ->value('id');

    expect($assignmentId)->not->toBeNull("{$deptCode} has no assignment on this filing");

    test()->withHeaders(authAs($email))
        ->postJson("/api/v1/assignments/{$assignmentId}/approve", ['remarks' => 'Cleared.'])
        ->assertOk();
}

/** That office picks a date for its permit's visit. */
function bookOfficeVisit(Application $app, string $code): int
{
    [, $email] = PARALLEL_OFFICE[$code];

    return test()->withHeaders(authAs($email))
        ->postJson("/api/v1/applications/{$app->id}/permits/{$code}/inspection", [
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertCreated()->json('data.id');
}

/** That office records a result against its own visit. */
function conductOfficeVisit(Application $app, string $code, int $visitId, string $result = 'passed'): void
{
    [, $email] = PARALLEL_OFFICE[$code];

    test()->withHeaders(authAs($email))
        ->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => $result, 'findings' => 'Seen.'])
        ->assertOk();
}

/** One permit's own status on this filing. */
function permitStatus(Application $app, string $code): ?ClearanceStatus
{
    return app(WorkflowService::class)->pivotFor($app->fresh(), $code)?->status;
}

/** The offices whose visits are booked on a filing, sorted. */
function bookedOffices(Application $app): array
{
    return $app->inspections()->with('department')->get()
        ->pluck('department.code')->sort()->values()->all();
}

it('moves only the approving office’s permit, and books no visit at all', function () {
    $app = parallelFiling(['SANITARY', 'FSIC'], 'Sanitary First Diner');

    approveOfficePaperwork($app, 'SANITARY');

    /*
     * CHO's permit moved and nobody else's did. Under the old machine this
     * approval would have dragged the whole FILING to For Inspection, which is
     * the summary that answers a question nobody asked: BFP has not read a page.
     */
    expect(permitStatus($app, 'SANITARY'))->toBe(ClearanceStatus::ForInspection);
    expect(permitStatus($app, 'FSIC'))->toBe(ClearanceStatus::ForApproval);
    expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);

    /*
     * And no visit exists. Accepting the paperwork is not the same act as
     * choosing when to go out — the old service booked automatically, two
     * working days ahead, which is a promise made to the applicant by a
     * scheduler that does not know whether anyone is free.
     */
    expect(bookedOffices($app))->toBe([]);
    expect(Permit::where('application_id', $app->id)->count())->toBe(0);
});

it('books a visit only for the office that picked a date', function () {
    $app = parallelFiling(['SANITARY', 'FSIC'], 'Two Office Grill');

    approveOfficePaperwork($app, 'SANITARY');
    approveOfficePaperwork($app, 'FSIC');

    // Both permits are ready for a date; neither has one.
    expect(bookedOffices($app))->toBe([]);

    bookOfficeVisit($app, 'SANITARY');
    expect(bookedOffices($app))->toBe(['CHO']);

    bookOfficeVisit($app, 'FSIC');
    expect(bookedOffices($app))->toBe(['BFP', 'CHO']);

    // Still nothing issued, and the filing has not moved: booking a visit is
    // not progress on the application, it is progress on one permit.
    expect(Permit::where('application_id', $app->id)->count())->toBe(0);
    expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);
});

it('releases each permit as its own office passes it, without waiting for the others', function () {
    /*
     * Rule 7, and the client was explicit: "the other 6 permits are
     * automatically released once they are approved by their respective admins;
     * no need to wait for each other to be approved."
     *
     * This is the exact case the old `isFullyCleared` gate got wrong in the
     * other direction — it held every permit until the whole filing was done.
     */
    $app = parallelFiling(['SANITARY', 'FSIC'], 'Fast Office Cafe');

    approveOfficePaperwork($app, 'SANITARY');
    conductOfficeVisit($app, 'SANITARY', bookOfficeVisit($app, 'SANITARY'));

    // CHO's permit is out, on CHO's say-so alone.
    expect(permitStatus($app, 'SANITARY'))->toBe(ClearanceStatus::Approved);
    expect(Permit::where('application_id', $app->id)->count())->toBe(1);

    // BFP is untouched by that, and so is the application.
    expect(permitStatus($app, 'FSIC'))->toBe(ClearanceStatus::ForApproval);
    expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);
});

it('keeps the application off BPLO’s desk while any required permit is outstanding', function () {
    /*
     * Every required permit but one is driven all the way to approved. The
     * filing must NOT reach `for_final_approval`, because BPLO's second act
     * issues the Mayor's Permit and the remaining office has not been out to the
     * premises. `ClearanceStatus::isOutstanding()` is the predicate; an
     * off-by-one there hands out a permit over an uninspected business.
     */
    $codes = array_keys(PARALLEL_OFFICE);
    $app = parallelFiling($codes, 'Nearly There Bakery');

    foreach (array_slice($codes, 0, 4) as $code) {
        approveOfficePaperwork($app, $code);
        conductOfficeVisit($app, $code, bookOfficeVisit($app, $code));
    }

    expect(Permit::where('application_id', $app->id)->count())->toBe(4);
    expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);

    // The fifth is what tips it, and only the fifth.
    $last = end($codes);
    approveOfficePaperwork($app, $last);
    conductOfficeVisit($app, $last, bookOfficeVisit($app, $last));

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval);
    expect(Permit::where('application_id', $app->id)->count())->toBe(5);
});

it('refuses to reverse a permit that has already been issued, and leaves the filing with BPLO', function () {
    /*
     * An issued permit is final. `ClearanceStatus::Approved` lists nothing in
     * `allowedNext()` and `isTerminal()` returns true for it alone — "the permit
     * is minted and numbered by then", so walking it back would leave a numbered
     * legal instrument in the register behind a status saying it was refused.
     *
     * ── A note on the walk-back edge, because this case is where you find it ──
     *
     * `ApplicationStatus::ForFinalApproval` allows a move BACK to
     * `AwaitingOtherPermits`, and the comment defending that edge justifies it
     * with "a re-inspection is opened, an office reverses itself". Neither is
     * reachable today, and this test is the proof of the second: the filing gets
     * to `for_final_approval` only when all five permits are Approved, Approved
     * is terminal, so `refreshReadiness()` can never find one outstanding again.
     * `scheduleReinspection()` does not move the pivot status either, and
     * `attachRequiredPermitTypes()` runs once at submission, so the required set
     * cannot grow underneath a filing in flight.
     *
     * The edge is therefore defensive rather than live. That is a reasonable
     * thing for a legality table to be — it costs nothing and it is the safe
     * direction to be wrong in — but the reasoning attached to it describes a
     * transition the other machine forbids. Left as found and reported rather
     * than "fixed" in either direction: making the permit reversible and making
     * the edge unreachable-by-construction are both product decisions.
     *
     * Driven at the service because no route exposes a per-clearance rejection:
     * each office returns its own assignment, and ending a permit outright is
     * not a button the officer screens offer today.
     */
    $codes = array_keys(PARALLEL_OFFICE);
    $app = parallelFiling($codes, 'Reversal Store');

    foreach ($codes as $code) {
        approveOfficePaperwork($app, $code);
        conductOfficeVisit($app, $code, bookOfficeVisit($app, $code));
    }

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval);
    expect(ClearanceStatus::Approved->isTerminal())->toBeTrue();
    expect(ClearanceStatus::Approved->allowedNext())->toBe([]);

    $workflow = app(WorkflowService::class);
    expect(fn () => $workflow->rejectClearance(
        $workflow->pivotFor($app->fresh(), 'SANITARY'),
        'The certificate was issued against the wrong premises.',
    ))->toThrow(ValidationException::class);

    // Nothing moved: the permit is still approved, the five issued permits are
    // still issued, and BPLO still has the filing on its desk.
    expect(permitStatus($app, 'SANITARY'))->toBe(ClearanceStatus::Approved);
    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval);
    expect(Permit::where('application_id', $app->id)->count())->toBe(5);
});

it('refuses a second visit to an office that already holds one', function () {
    /*
     * A second row would leave the office with two visits, neither aware of the
     * other, and — because a scheduled visit counts as not-yet-passed — a permit
     * that can never clear. `currentPerDepartment()` is what distinguishes an
     * open booking from a failed visit deliberately kept on the record.
     */
    $app = parallelFiling(['SANITARY'], 'Double Booking Store');

    approveOfficePaperwork($app, 'SANITARY');
    $visitId = bookOfficeVisit($app, 'SANITARY');

    test()->withHeaders(authAs(PARALLEL_OFFICE['SANITARY'][1]))
        ->postJson("/api/v1/applications/{$app->id}/permits/SANITARY/inspection", [
            'scheduled_at' => now()->addDays(4)->toDateTimeString(),
        ])->assertStatus(422);

    expect(Inspection::where('application_id', $app->id)->count())->toBe(1);
    expect(Inspection::where('application_id', $app->id)->value('id'))->toBe($visitId);
});

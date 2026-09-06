<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationStatusHistory;
use App\Models\Barangay;
use App\Models\Inspection;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * Inspections are booked per office, as that office approves.
 *
 * The client's question was "when I approved a sanitary permit, why did it not
 * automatically go to inspection?", and the answer was a single line in
 * WorkflowService::afterReviewProgress: it returned early unless EVERY
 * assignment on the filing was `completed`, so nothing at all happened until the
 * last office had finished reading. That was defensible when SANITARY and FSIC
 * were the only inspected clearances. All six supporting clearances are
 * inspected now, so it meant City Health could not visit a premises it had
 * already cleared on paper because the Market Office had not opened its form —
 * six offices moving at the pace of the slowest.
 *
 * These cases pin the replacement: each office's visit is booked when that
 * office approves, the filing reads For Inspection from the first booking, and
 * — the part that carries all the risk — nothing issues a permit until every
 * review AND every current visit is done.
 */

const PARALLEL_OFFICE_EMAIL = [
    'BPLO' => 'bplo@biztrack.local',
    'CHO' => 'sanitary@biztrack.local',
    'BFP' => 'fire@biztrack.local',
];

/**
 * A paid, routed filing carrying exactly these permit types, reviews untouched.
 *
 * Driven through the real endpoints rather than built from factories, because
 * what is under test is the order in which WorkflowService does things — a
 * hand-assembled filing would prove nothing about the path that assembles it.
 */
function parallelFiling(array $permitCodes, string $name): Application
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
        'permit_type_ids' => PermitType::whereIn('code', $permitCodes)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    // The office confirms the processing category as the filing lands on its
    // desk, which is what unlocks Approve. Nothing below is about that gate;
    // without this every sign-off here would be refused for the wrong reason.
    classifyAsOfficer(Application::findOrFail($appId));

    return Application::findOrFail($appId);
}

/** One office signs off its own review, as that office. */
function approveOfficeReview(Application $app, string $departmentCode): void
{
    $assignmentId = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', $departmentCode))
        ->value('id');

    expect($assignmentId)->not->toBeNull("{$departmentCode} has no assignment on this filing");

    test()->withHeaders(authAs(PARALLEL_OFFICE_EMAIL[$departmentCode]))
        ->postJson("/api/v1/assignments/{$assignmentId}/approve", ['remarks' => 'Cleared.'])
        ->assertOk();
}

/** That office conducts and closes its own visit. */
function conductOfficeVisit(Application $app, string $departmentCode, string $result = 'passed'): void
{
    $visit = $app->inspections()->currentPerDepartment()
        ->whereHas('department', fn ($d) => $d->where('code', $departmentCode))
        ->firstOrFail();

    test()->withHeaders(authAs(PARALLEL_OFFICE_EMAIL[$departmentCode]))
        ->postJson("/api/v1/inspections/{$visit->id}/conduct", ['result' => $result, 'findings' => 'Seen.'])
        ->assertOk();
}

/** The offices whose visits are booked on a filing, sorted. */
function bookedOffices(Application $app): array
{
    return $app->inspections()->with('department')->get()
        ->pluck('department.code')->sort()->values()->all();
}

it('books only the approving office’s visit, and moves the filing to for_inspection at once', function () {
    $app = parallelFiling(['BUSINESS', 'SANITARY', 'FSIC'], 'Sanitary First Diner');

    approveOfficeReview($app, 'CHO');

    /*
     * ONE visit, City Health's. BFP has not signed off, so booking its visit
     * here would be the mirror of the old bug — an office sent out to a premises
     * on the strength of somebody else's reading.
     */
    expect(bookedOffices($app))->toBe(['CHO']);

    // And the client's actual complaint: the filing says so immediately, rather
    // than sitting in For Approval until the last office finishes.
    expect($app->fresh()->status->value)->toBe('for_inspection');
    expect(Permit::where('application_id', $app->id)->count())->toBe(0);
});

it('books the second office’s visit on its own approval, without a second for_inspection row', function () {
    $app = parallelFiling(['BUSINESS', 'SANITARY', 'FSIC'], 'Two Office Grill');

    approveOfficeReview($app, 'CHO');
    approveOfficeReview($app, 'BFP');

    expect(bookedOffices($app))->toBe(['BFP', 'CHO']);
    expect($app->fresh()->status->value)->toBe('for_inspection');

    /*
     * Exactly one arrival at For Inspection, and this is the assertion that
     * keeps it honest. The filing was already there when BFP approved, so the
     * booking must not announce a move: a for_inspection → for_inspection row
     * reads on the applicant's timeline as movement that did not happen, which
     * is the same reason scheduleReinspection() deliberately does not call
     * transition() either.
     */
    $arrivals = ApplicationStatusHistory::where('application_id', $app->id)
        ->where('to_status', 'for_inspection')
        ->count();
    expect($arrivals)->toBe(1);
});

it('lets an office that does not inspect simply complete, blocking and triggering nothing', function () {
    $app = parallelFiling(['BUSINESS', 'SANITARY'], 'Mayor Permit Mart');

    /*
     * BPLO issues the Mayor's Permit on the strength of the clearances rather
     * than a visit of its own — `requires_inspection` is false on BUSINESS and
     * on nothing else. So its approval books no visit, and it must not drag the
     * filing to For Inspection on the back of an office that has not approved.
     */
    approveOfficeReview($app, 'BPLO');

    expect(bookedOffices($app))->toBe([]);
    expect($app->fresh()->status->value)->toBe('under_review');
    expect(Permit::where('application_id', $app->id)->count())->toBe(0);
});

it('refuses to issue while a review is still open, even with every booked visit passed', function () {
    /*
     * THE case this change exists to not break, and the one that fails against
     * an unguarded WorkflowService.
     *
     * CHO approves and its visit passes. At that instant every CURRENT
     * inspection on the filing has passed — there is only one — and BPLO has not
     * read a page. recordInspection's old test was the visits alone, which was
     * sound only because reaching `for_inspection` used to imply every review
     * was done. Booking per office removes that implication, so without the
     * guard in isFullyCleared() this hands the applicant a Mayor's Permit over
     * an unread clearance application.
     */
    $app = parallelFiling(['BUSINESS', 'SANITARY'], 'Half Read Bakery');

    approveOfficeReview($app, 'CHO');
    conductOfficeVisit($app, 'CHO');

    expect(Permit::where('application_id', $app->id)->count())->toBe(0);
    expect($app->fresh()->status->value)->toBe('for_inspection');
    expect(ApplicationAssignment::where('application_id', $app->id)
        ->where('status', 'pending')->count())->toBe(1);

    // The last review is what completes it — reviews and visits now land in any
    // order, so the final review has to be able to issue as well.
    approveOfficeReview($app, 'BPLO');

    expect($app->fresh()->status->value)->toBe('approved');
    expect(Permit::where('application_id', $app->id)->count())->toBe(2);
});

it('holds a filing whose one inspecting office has already passed while another office still reviews', function () {
    /*
     * The same trap one office wider, and the way round it is likelier to be
     * hit: CHO is fast, BFP is slow. CHO's visit passes while BFP has neither
     * approved nor been booked, so "every current inspection has passed" is true
     * again, on a filing two reviews away from being decided.
     */
    $app = parallelFiling(['BUSINESS', 'SANITARY', 'FSIC'], 'Fast Office Cafe');

    approveOfficeReview($app, 'CHO');
    conductOfficeVisit($app, 'CHO');

    expect($app->fresh()->status->value)->toBe('for_inspection');
    expect(Permit::where('application_id', $app->id)->count())->toBe(0);

    approveOfficeReview($app, 'BPLO');

    // Still nothing: BFP's review is open, so its visit does not exist yet and
    // the filing cannot be complete however the passed ones read.
    expect($app->fresh()->status->value)->toBe('for_inspection');
    expect(Permit::where('application_id', $app->id)->count())->toBe(0);

    approveOfficeReview($app, 'BFP');
    expect(bookedOffices($app))->toBe(['BFP', 'CHO']);
    expect(Permit::where('application_id', $app->id)->count())->toBe(0);

    conductOfficeVisit($app, 'BFP');

    expect($app->fresh()->status->value)->toBe('approved');
    expect(Permit::where('application_id', $app->id)->count())->toBe(3);
});

it('does not rebook an office that already holds a visit, and puts the filing back where it was', function () {
    /*
     * A filing goes round the returned/resubmitted loop and the office that
     * already has an open booking approves a second time.
     *
     * Two things have to survive that. A second row would leave the office with
     * two visits, neither aware of the other, and — because a scheduled visit
     * counts as not-yet-passed — a filing that can never clear. And the return
     * dropped the filing to `returned` and the resubmission to `under_review`
     * while CHO's visit was still outstanding, so the approval has to put it
     * back to For Inspection even though it booked nothing: `canBeReinspected()`
     * requires that status, so a filing left in `under_review` with an open
     * visit is one whose office cannot rebook if that visit fails.
     */
    $app = parallelFiling(['BUSINESS', 'SANITARY'], 'Returned Loop Store');

    approveOfficeReview($app, 'CHO');
    $firstVisitId = Inspection::where('application_id', $app->id)->value('id');

    $choAssignmentId = ApplicationAssignment::where('application_id', $app->id)
        ->whereHas('department', fn ($d) => $d->where('code', 'CHO'))
        ->value('id');

    test()->withHeaders(authAs(PARALLEL_OFFICE_EMAIL['CHO']))
        ->postJson("/api/v1/assignments/{$choAssignmentId}/return", ['remarks' => 'Lease copy is unreadable.'])
        ->assertOk();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/resubmit")
        ->assertOk();

    approveOfficeReview($app, 'CHO');

    expect(Inspection::where('application_id', $app->id)->count())->toBe(1);
    expect(Inspection::where('application_id', $app->id)->value('id'))->toBe($firstVisitId);
    expect($app->fresh()->status->value)->toBe('for_inspection');
});

<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Inspection;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Support\Collection;

/**
 * Re-inspection after a failed visit.
 *
 * The failure this covers was not that a re-inspection went wrong — it was that
 * there was no such thing. `recordInspection()` returned early on a failure
 * under a comment saying the department may schedule a re-inspection, and
 * nothing in the system could: the permit stayed `for_inspection` with every
 * visit conducted, which is also the state in which the officer's screen hides
 * its controls. Six live filings were stranded there.
 *
 * So these tests are mostly about the two things that make the fix real rather
 * than plausible: the failed visit is still on the record afterwards, and the
 * passing re-inspection is actually able to release the permit over it.
 *
 * ── What the 6 September 2026 flow changed here ─────────────────────────────
 *
 * `for_inspection` is no longer a status of the APPLICATION. It is a state of
 * ONE permit, on `application_permit_types`, and a filing whose Fire Safety
 * certificate is being re-inspected reads `approved` as a whole —
 * because CHO may already have issued and CPDO may still be reading. Every
 * assertion below that used to read the filing's status now reads the permit's.
 *
 * Two more steps had to be written into the fixture rather than assumed. The
 * offices are no longer routed by payment, so the APPLICANT opens each permit
 * before its office has anything in a queue; and approving the paperwork no
 * longer books a visit, so the office picks the date in a separate act.
 */
$deptEmail = [
    'BPLO' => 'bplo@biztrack.local',
    'CHO' => 'sanitary@biztrack.local',
    'BFP' => 'fire@biztrack.local',
    'CPDO' => 'zoning@biztrack.local',
    'OBO' => 'obo@biztrack.local',
    'CENRO' => 'cenro@biztrack.local',
];

/** The five required clearances and the office that inspects for each. */
const REINSPECTION_OFFICE = [
    'SANITARY' => 'CHO',
    'FSIC' => 'BFP',
    'ZONING' => 'CPDO',
    'OCCUPANCY' => 'OBO',
    'CEC' => 'CENRO',
];

/**
 * Drive a filing to the point where every clearance is awaiting its visit.
 *
 * The long way round — business, application, submit, BPLO's approval, payment,
 * five permits opened, five paperwork approvals, five bookings — rather than
 * inserting rows, because the state under test is the one the workflow builds,
 * and a hand-built filing would not prove the scheduler had been through it.
 *
 * All FIVE clearances are driven, not the two this filing used to name. Which
 * permits a filing must obtain stopped being the applicant's to choose:
 * `attachRequiredPermitTypes()` attaches all five at submission, so asking for
 * a short list would leave three permits outstanding for ever and no filing
 * here could ever be approved.
 *
 * @return array{0: int, 1: Collection<int, Inspection>}
 */
function filingAwaitingInspection(array $deptEmail, string $name): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Re-inspect Ave.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    /*
     * BPLO accepts the main form first; the bill does not exist before that.
     * The helper also puts an officer's name to the RA 11032 processing
     * category, which the approval gate requires and which re-inspection is
     * not what that rule is about.
     */
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    /*
     * The applicant opens each permit and then hands its sheet in. Payment no
     * longer fans the filing out to everyone at once, and neither does Apply:
     * Apply OPENS the office's form, and it is submitting that form which moves
     * the permit to ForApproval and routes the office
     * (WorkflowService::submitClearanceForm). All five codes here bear a form,
     * so all five need both acts before any office has a queue item to approve
     * — which is what the loop below goes on to do.
     *
     * The sheets go in empty. What is on them is OfficeFormTest's subject; this
     * file needs only that an office received something.
     */
    foreach (array_keys(REINSPECTION_OFFICE) as $code) {
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")
            ->assertOk();
        // The checklist is complete before the sheet goes in — the submit
        // refuses one that is not. See satisfyChecklist() in Pest.php.
        satisfyChecklist(
            Application::findOrFail($appId),
            PermitType::where('code', $code)->firstOrFail(),
        );
        test()->withHeaders(authAs('owner@biztrack.local'))
            ->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
                'form_data' => [],
                'submit' => true,
            ])->assertSuccessful();
    }

    foreach (REINSPECTION_OFFICE as $code => $deptCode) {
        $officer = authAs($deptEmail[$deptCode]);

        $assignmentId = ApplicationAssignment::where('application_id', $appId)
            ->whereHas('department', fn ($d) => $d->where('code', $deptCode))
            ->value('id');

        test()->withHeaders($officer)
            ->postJson("/api/v1/assignments/{$assignmentId}/approve", ['remarks' => 'ok'])
            ->assertOk();

        // Approving the paperwork books nothing. The office says when.
        test()->withHeaders($officer)
            ->postJson("/api/v1/applications/{$appId}/permits/{$code}/inspection", [
                'scheduled_at' => now()->toDateTimeString(), // today: no result before the booked day
            ])->assertCreated();
    }

    expect(clearanceStatusOf($appId, 'FSIC'))->toBe('for_inspection');

    return [$appId, Inspection::where('application_id', $appId)->with('department')->get()];
}

/** What one permit on this filing is currently doing. */
function clearanceStatusOf(int $appId, string $code): ?string
{
    return ApplicationPermitType::where('application_id', $appId)
        ->where('permit_type_id', PermitType::where('code', $code)->value('id'))
        ->first()?->status?->value;
}

it('schedules a re-inspection from a failed visit and keeps the failure on the record', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Extinguisher Diner');

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", [
            'result' => 'failed',
            'findings' => 'no extinguisher',
        ])->assertOk();

    // The whole point of the bug: the PERMIT is still waiting, and before this
    // change nothing could move it.
    expect(clearanceStatusOf($appId, 'FSIC'))->toBe('for_inspection');

    $when = now()->addWeekdays(5)->startOfHour();
    $created = test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", ['scheduled_at' => $when->toIso8601String()])
        ->assertCreated()
        ->json('data');

    // A NEW row, for the same office, waiting to be conducted.
    expect($created['id'])->not->toBe($fire->id);
    expect($created['status'])->toBe('scheduled');
    expect($created['result'])->toBeNull();
    expect($created['department']['code'])->toBe('BFP');

    // And the failure is still there, untouched — not rescheduled, not reused.
    $failed = Inspection::find($fire->id);
    expect($failed->result->value)->toBe('failed');
    expect($failed->findings)->toBe('no extinguisher');
    expect($failed->conducted_at)->not->toBeNull();
    expect(Inspection::where('application_id', $appId)->where('department_id', $fire->department_id)->count())->toBe(2);

    /*
     * And the applicant is told the same thing they were told before. The
     * filing reads `approved`, not `for_inspection`: a single
     * column over five permits being worked at once cannot say which of them
     * is at the premises, so it says the true thing instead.
     */
    $ownerHeaders = authAs('owner@biztrack.local');
    expect(
        test()->withHeaders($ownerHeaders)->getJson("/api/v1/applications/{$appId}")->json('data.status')
    )->toBe('approved');
});

it('tells the owner when the re-inspection is booked', function () use ($deptEmail) {
    /*
     * The notice went through `applicationStatus`, which is silent on Approved,
     * the status a paid filing wears while its visits happen — so the owner
     * was never told the date.
     */
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Booked Again Bakery');
    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    $when = now()->addWeekdays(5)->startOfHour();
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", ['scheduled_at' => $when->toIso8601String()])
        ->assertCreated();

    $app = Application::findOrFail($appId);
    expect(AppNotification::where('user_id', $app->applicant_user_id)
        ->where('body', "{$app->tracking_id}: A re-inspection has been scheduled for {$when->format('F j, Y')}.")
        ->exists())->toBeTrue();
});

it('issues the permit when the re-inspection passes, over the kept failure', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Second Chance Grill');

    /*
     * The other four offices pass first, so the only thing standing between
     * this filing and its Mayor's Permit is the failed visit and its
     * replacement. Each of those four permits is ISSUED as its own office
     * finishes (rule 7) — nothing waits for the fire inspection.
     */
    foreach (['CHO', 'CPDO', 'OBO', 'CENRO'] as $deptCode) {
        $visit = $visits->firstWhere('department.code', $deptCode);
        test()->withHeaders(authAs($deptEmail[$deptCode]))
            ->postJson("/api/v1/inspections/{$visit->id}/conduct", ['result' => 'passed'])
            ->assertOk();
    }
    expect(clearancePermitsIssued($appId))->toBe(4);

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    $reinspectionId = test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(5)->toIso8601String(),
        ])->assertCreated()->json('data.id');

    // The fire permit is still for inspection with the booking open — nothing
    // auto-approves, and an office with an open visit has not passed.
    expect(clearanceStatusOf($appId, 'FSIC'))->toBe('for_inspection');
    expect(clearancePermitsIssued($appId))->toBe(4);
    expect(Application::find($appId)->status->value)->toBe('approved');

    // On the day it was booked for: a result cannot predate its visit.
    test()->travelTo(now()->addWeekdays(5)->setTime(9, 0));
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$reinspectionId}/conduct", ['result' => 'passed', 'findings' => 'extinguisher installed'])
        ->assertOk();

    /*
     * This is the assertion the old `allPassed` over EVERY row could not pass:
     * the failed visit is still in the table and the permit is issued anyway.
     *
     * And with the fifth clearance in, the filing closes itself — six permits,
     * not five. A passing RE-inspection is worth pinning here specifically: it
     * reaches readiness by a different route from a first-time pass, through
     * `recordInspection` on a re-booked visit, and since 18 September 2026 that
     * route mints the Mayor's Permit too. A filing rescued from a failed
     * inspection must end up exactly where a clean one does.
     */
    expect(clearanceStatusOf($appId, 'FSIC'))->toBe('approved');
    expect(Permit::where('application_id', $appId)->count())->toBe(6);
    expect(Application::find($appId)->status->value)->toBe('approved');

    /*
     * There is nothing left for BPLO to sign. The filing approved itself above,
     * so the press that used to end this test is now REFUSED as already
     * decided — and that refusal is worth keeping rather than deleting the
     * lines, because a re-inspected filing reaching Approved by a second route
     * would be a double issuance.
     */
    $bploAssignmentId = ApplicationAssignment::where('application_id', $appId)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->value('id');
    test()->withHeaders(authAs($deptEmail['BPLO']))
        ->postJson("/api/v1/assignments/{$bploAssignmentId}/approve", ['remarks' => 'All requirements met.'])
        ->assertStatus(422);

    // Still six, not seven: the refused press minted nothing.
    expect(Application::find($appId)->status->value)->toBe('approved');
    expect(Permit::where('application_id', $appId)->count())->toBe(6);

    expect(Inspection::find($fire->id)->result->value)->toBe('failed');
    expect(Inspection::where('application_id', $appId)->count())->toBe(6);
});

it('refuses a re-inspection on a visit that passed', function () use ($deptEmail) {
    [, $visits] = filingAwaitingInspection($deptEmail, 'Spotless Bakery');

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'passed'])
        ->assertOk();

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(5)->toIso8601String(),
        ])->assertStatus(422);

    // Nothing was booked behind the refusal.
    expect(Inspection::where('application_id', $fire->application_id)
        ->where('department_id', $fire->department_id)->count())->toBe(1);
});

it('refuses a second re-inspection booked from a superseded failure', function () use ($deptEmail) {
    [, $visits] = filingAwaitingInspection($deptEmail, 'Twice Shy Cafe');

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'blocked exit'])
        ->assertOk();

    $second = test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(5)->toIso8601String(),
        ])->assertCreated()->json('data.id');

    // The first failure is now history. Booking from it again would leave the
    // office with two open visits, neither aware of the other.
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(9)->toIso8601String(),
        ])->assertStatus(422);

    // The current visit may fail again and be re-inspected again, though —
    // failing twice is not a reason to strand the filing a second time.
    // On the day it was booked for: a result cannot predate its visit.
    test()->travelTo(now()->addWeekdays(5)->setTime(9, 0));
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$second}/conduct", ['result' => 'failed', 'findings' => 'still blocked'])
        ->assertOk();
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$second}/reinspect", [
            'scheduled_at' => now()->addWeekdays(12)->toIso8601String(),
        ])->assertCreated();

    expect(Inspection::where('application_id', $fire->application_id)
        ->where('department_id', $fire->department_id)->count())->toBe(3);
});

/*
 * GREEN since 18 September 2026. Kept exactly as written — the assertion was
 * never weakened to match the bug, and this note records how it was closed.
 *
 * `WorkflowService::approveClearance()` refuses outright when the application is
 * terminal — "This application has been decided. Its permits can no longer be
 * acted on." — so the rule below was always the flow's own. But
 * `Inspection::canBeReinspected()` had stopped checking it. It used to ask
 * `application->status === ForInspection`, which happened to carry the terminal
 * guard for free; that had to go when `for_inspection` became a per-permit
 * state, and `permitIsAwaitingInspection()` replaced it without carrying the
 * guard across. A rejected filing keeps its permit rows at `for_inspection` and
 * nothing walks them back, so the office could still book a visit against a
 * filing the LGU had refused.
 *
 * Chasing it found TWO MORE doors asking the same half-question, and the worst
 * was not this one: `recordInspection()` would take a PASSING visit on a
 * rejected filing and issue the certificate. The test after this one is that
 * case, and it was measured before it was fixed — 200, permit approved,
 * `permits` 0 → 1.
 *
 * The fix is one predicate — `ApplicationPermitType::awaitingInspection()` —
 * asked by all three doors. Walking the pivot rows back in `rejectApplication()`
 * was the alternative and was rejected: `ClearanceStatus::Rejected` was removed
 * on 17 September at the client's decision, so there is no state to walk them
 * to, and where each permit stood when the filing was refused is worth keeping.
 */
it('refuses a re-inspection once the filing has been decided', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Closed Book Store');

    $fire = $visits->firstWhere('department.code', 'BFP');
    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    // BPLO ends the filing (the one office that may).
    test()->withHeaders(authAs($deptEmail['BPLO']))
        ->postJson("/api/v1/applications/{$appId}/reject", ['reason' => 'Premises unsafe.'])
        ->assertOk();

    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(5)->toIso8601String(),
        ])->assertStatus(422);
});

/*
 * The one that mattered: a passing visit on a decided filing must not ISSUE.
 *
 * The test above refuses a BOOKING, which on its own only saves a useless row.
 * This refuses the act that produced a real document. Measured on
 * 18 September 2026, before the fix, on a filing BPLO had rejected: the conduct
 * endpoint answered 200, the FSIC pivot moved `for_inspection → approved`, and
 * `permits` went 0 → 1. A Fire Safety certificate issued against a filing the
 * LGU had refused — and the business holding it would have been right to think
 * it meant something.
 *
 * Both halves are asserted, because either alone can pass while the defect
 * stands: the refusal (422), and the absence of a certificate. A version of
 * this that only checked the status code would still go green if the endpoint
 * refused AFTER `grantClearance()` had run.
 *
 * The visit record is asserted absent too. The guard sits before the row is
 * written, so a dead filing does not collect a conducted visit that achieved
 * nothing — the officer is told why instead.
 */
it('refuses to conduct, and never issues, once the filing has been decided', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Refused Bakery');

    test()->withHeaders(authAs($deptEmail['BPLO']))
        ->postJson("/api/v1/applications/{$appId}/reject", ['reason' => 'Premises unsafe.'])
        ->assertOk();

    $fire = $visits->firstWhere('department.code', 'BFP');

    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", [
            'result' => 'passed',
            'findings' => 'all clear',
        ])->assertStatus(422);

    expect(clearancePermitsIssued($appId))->toBe(0);
    expect($fire->fresh()->conducted_at)->toBeNull();

    // And the permit is left where it stood when the filing was refused, which
    // is the record the walk-back alternative would have destroyed.
    expect(clearanceStatusOf($appId, 'FSIC'))->toBe('for_inspection');
});

/*
 * The re-inspection belongs to the office that failed the visit — the super
 * admin does not book it.
 *
 * This test used to assert the opposite, and said so: "the stranded filings are
 * opened by an admin, not by the office that failed them, so this is the path
 * that actually unsticks them". That was true of the six stranded filings
 * because at the time the admin was the only account that could reach an
 * inspection at all — OBO, CENRO, CPDO and the Market Office had no
 * `inspection.manage`, so a rescue by the responsible office was not on offer
 * and the admin was standing in for it.
 *
 * Both halves of that have since been fixed, in opposite directions. Every
 * clearance office now holds `inspection.manage` (RbacSeeder), so each one can
 * rebook its own failed visit — which is what the test above this one proves,
 * through the same endpoint, for the same failure. And the super admin lost it,
 * at the client's request: "In the super admin's account (admin@), remove
 * Messages, Track, Inspections, and Other Requirements. It is not his role to
 * do those things."
 *
 * So the rescue path did not disappear, it moved to the office that owns the
 * premises it is about. Asserting the 403 is what keeps the two facts from
 * drifting apart: if the admin ever answers 201 here again, either the client's
 * separation was undone or an office lost the permission and the admin is
 * covering for it once more.
 */
it('refuses the super admin a re-inspection: it belongs to the office that failed the visit', function () use ($deptEmail) {
    [, $visits] = filingAwaitingInspection($deptEmail, 'Admin Rescue Mart');

    $fire = $visits->firstWhere('department.code', 'BFP');
    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    $payload = ['scheduled_at' => now()->addWeekdays(5)->toIso8601String()];

    // 403 from the route's `permission:inspection.manage` gate, before the
    // controller is reached — the admin cannot see the visit either.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", $payload)
        ->assertStatus(403);
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/inspections/{$fire->id}")
        ->assertStatus(403);

    // And the filing is not stranded by that: BFP books its own replacement.
    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", $payload)
        ->assertCreated();
});

/*
 * ── The visit's date is a real date ─────────────────────────────────────────
 *
 * Browser testing, 5 October 2026: a visit could be booked in the past, a
 * result recorded two days before the day it was booked for (booked 6 October,
 * "conducted" the 4th), and moving a visit told the applicant nothing.
 */

it('refuses to book or move a visit to a day already gone', function () use ($deptEmail) {
    [, $visits] = filingAwaitingInspection($deptEmail, 'Yesterday Eatery');
    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reschedule", ['scheduled_at' => now()->subDay()->toDateString()])
        ->assertStatus(422)
        ->assertJsonPath('errors.scheduled_at.0', 'Pick today or a later date.');

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", ['scheduled_at' => now()->subDays(3)->toIso8601String()])
        ->assertStatus(422)
        ->assertJsonPath('errors.scheduled_at.0', 'Pick today or a later date.');

    // Today itself is fine, at any hour.
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", ['scheduled_at' => now()->startOfDay()->toIso8601String()])
        ->assertCreated();
});

it('refuses a first booking in the past too', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'First Booking Bistro');
    $fire = $visits->firstWhere('department.code', 'BFP');
    // Clear the fixture's booking so the office has a first one to make.
    Inspection::whereKey($fire->id)->delete();

    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/applications/{$appId}/permits/FSIC/inspection", ['scheduled_at' => now()->subDay()->toDateTimeString()])
        ->assertStatus(422)
        ->assertJsonPath('errors.scheduled_at.0', 'Pick today or a later date.');
});

it('refuses a result recorded before the day the visit is booked for', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Early Bird Grill');
    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    $booked = now()->addDays(2)->setTime(14, 0);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reschedule", ['scheduled_at' => $booked->toDateTimeString()])
        ->assertOk();

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'passed'])
        ->assertStatus(422)
        ->assertJsonPath('errors.scheduled_at.0', 'The visit is booked for '.$booked->format('F j, Y').'.');
    expect(clearanceStatusOf($appId, 'FSIC'))->toBe('for_inspection');

    // On the morning of the booked day it can be recorded, before the hour.
    test()->travelTo($booked->copy()->setTime(8, 0));
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'passed'])
        ->assertOk();
});

it('tells the applicant when a visit is moved', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Moved Visit Cafe');
    $fire = $visits->firstWhere('department.code', 'BFP');
    $to = now()->addDays(3);

    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/reschedule", ['scheduled_at' => $to->toDateTimeString()])
        ->assertOk();

    $owner = Application::findOrFail($appId)->applicant_user_id;
    expect(AppNotification::where('user_id', $owner)
        ->where('body', 'like', '%inspection has been moved to '.$to->format('F j, Y').'.')
        ->exists())->toBeTrue();
});

/*
 * ── A failed visit suspends the business permit ─────────────────────────────
 *
 * The client, 5 October 2026: *"I thought when a permit was Rejected (which
 * was done through For Inspection), the business permit is automatically
 * Suspended? We already built that right, so where is that? Kindly FIX."*
 * Only the office's formal refusal (`rejectClearance`) suspended; the
 * inspection's Reject — a failed result — left the Mayor's Permit active.
 */

/** The Mayor's Permit this filing released at payment. */
function mayorsPermitOf(int $appId): Permit
{
    return Permit::where('application_id', $appId)
        ->whereHas('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
        ->latest('id')
        ->firstOrFail();
}

it('suspends the Mayor’s Permit when a visit fails, and tells the applicant', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Suspended By Visit');
    expect(mayorsPermitOf($appId)->status->value)->toBe('active');

    $fire = $visits->firstWhere('department.code', 'BFP');
    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    $permit = mayorsPermitOf($appId);
    expect($permit->status->value)->toBe('suspended');

    $audit = AuditLog::where('action', 'permit.suspended')
        ->where('auditable_id', $permit->id)->sole();
    expect($audit->changes['because_permit_type'])->toBe(PermitType::where('code', 'FSIC')->value('name'))
        ->and($audit->changes['reason'])->toBe('no extinguisher');

    $owner = Application::findOrFail($appId)->applicant_user_id;
    expect(AppNotification::where('user_id', $owner)
        ->where('body', 'like', '%Your Business Permit is suspended until this is settled.')
        ->exists())->toBeTrue();
});

it('reinstates the Mayor’s Permit once the re-inspection passes', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Reinstated By Visit');

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();
    $again = test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", ['scheduled_at' => now()->toDateTimeString()])
        ->assertCreated()->json('data.id');
    expect(mayorsPermitOf($appId)->status->value)->toBe('suspended');

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$again}/conduct", ['result' => 'passed'])
        ->assertOk();

    $permit = mayorsPermitOf($appId);
    expect(clearanceStatusOf($appId, 'FSIC'))->toBe('approved')
        ->and($permit->status->value)->toBe('active')
        ->and(AuditLog::where('action', 'permit.reinstated')->where('auditable_id', $permit->id)->exists())
        ->toBeTrue();
});

it('keeps the Mayor’s Permit suspended while another office passes and the failure is unanswered', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Still Suspended');

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();
    // A re-inspection booked but not yet done answers nothing.
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", ['scheduled_at' => now()->toDateTimeString()])
        ->assertCreated();

    $cho = $visits->firstWhere('department.code', 'CHO');
    test()->withHeaders(authAs($deptEmail['CHO']))
        ->postJson("/api/v1/inspections/{$cho->id}/conduct", ['result' => 'passed'])
        ->assertOk();

    expect(clearanceStatusOf($appId, 'SANITARY'))->toBe('approved')
        ->and(mayorsPermitOf($appId)->status->value)->toBe('suspended');
});

/*
 * ── A visit with a result is final ──────────────────────────────────────────
 *
 * Ken, 5 October 2026, from the scenario run (inspection rows 1, 2 and 4): a
 * conducted visit took a second result over the first, a failed visit could
 * be recorded again as passed — erasing the failure and issuing the permit —
 * and a failed visit could be "moved", which hid the failure behind
 * Rescheduled and let another office's pass lift the suspension it caused.
 * The way on from a failure is the re-inspection, a new visit.
 */
it('refuses a second result on a visit that already has one', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Written Twice');
    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'passed', 'findings' => 'First write-up.'])
        ->assertOk();
    $first = Inspection::findOrFail($fire->id);

    test()->travel(2)->hours();
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'passed', 'findings' => 'Second write-up.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This visit already has a result.');

    $after = Inspection::findOrFail($fire->id);
    expect($after->findings)->toBe('First write-up.')
        ->and($after->conducted_at->equalTo($first->conducted_at))->toBeTrue()
        ->and(Permit::where('application_id', $appId)
            ->where('permit_type_id', PermitType::where('code', 'FSIC')->value('id'))->count())->toBe(1);
});

it('keeps a failed visit failed: it cannot be recorded again as passed', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Failed Stays Failed');
    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'passed', 'findings' => 'Fine now.'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This visit already has a result.');

    $visit = Inspection::findOrFail($fire->id);
    expect($visit->result->value)->toBe('failed')
        ->and($visit->findings)->toBe('no extinguisher')
        ->and(clearanceStatusOf($appId, 'FSIC'))->toBe('for_inspection')
        ->and(mayorsPermitOf($appId)->status->value)->toBe('suspended');
});

it('refuses to move a visit that already has a result; the failure is answered by a re-inspection', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Moved After Failing');
    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reschedule", ['scheduled_at' => now()->addDays(4)->toDateTimeString()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This visit already has a result.');

    $visit = Inspection::findOrFail($fire->id);
    expect($visit->status->value)->toBe('completed')
        ->and($visit->result->value)->toBe('failed');

    // The way on still works: a re-inspection from the failed visit.
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", ['scheduled_at' => now()->addDays(6)->toDateTimeString()])
        ->assertCreated();

    // And the standing failure still holds the Mayor's Permit when another office passes.
    $cho = $visits->firstWhere('department.code', 'CHO');
    test()->withHeaders(authAs($deptEmail['CHO']))
        ->postJson("/api/v1/inspections/{$cho->id}/conduct", ['result' => 'passed'])
        ->assertOk();
    expect(mayorsPermitOf($appId)->status->value)->toBe('suspended');
});

it('refuses a second booking while the office’s moved visit is still open', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Booked Twice Bakery');
    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reschedule", ['scheduled_at' => now()->addDays(6)->toDateTimeString()])
        ->assertOk();

    test()->withHeaders($officer)
        ->postJson("/api/v1/applications/{$appId}/permits/FSIC/inspection", ['scheduled_at' => now()->addDays(8)->toDateTimeString()])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This office already has a visit booked for this filing.');

    expect(Inspection::where('application_id', $appId)->where('department_id', $fire->department_id)->count())->toBe(1);
});

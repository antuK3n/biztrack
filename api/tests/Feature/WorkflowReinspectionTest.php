<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationPermitType;
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
 * certificate is being re-inspected reads `awaiting_other_permits` as a whole —
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
                'scheduled_at' => now()->addWeekdays(2)->startOfHour()->toDateTimeString(),
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
     * filing reads `awaiting_other_permits`, not `for_inspection`: a single
     * column over five permits being worked at once cannot say which of them
     * is at the premises, so it says the true thing instead.
     */
    $ownerHeaders = authAs('owner@biztrack.local');
    expect(
        test()->withHeaders($ownerHeaders)->getJson("/api/v1/applications/{$appId}")->json('data.status')
    )->toBe('awaiting_other_permits');
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
    expect(Permit::where('application_id', $appId)->count())->toBe(4);

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
    expect(Permit::where('application_id', $appId)->count())->toBe(4);
    expect(Application::find($appId)->status->value)->toBe('awaiting_other_permits');

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$reinspectionId}/conduct", ['result' => 'passed', 'findings' => 'extinguisher installed'])
        ->assertOk();

    /*
     * This is the assertion the old `allPassed` over EVERY row could not pass:
     * the failed visit is still in the table and the permit is issued anyway.
     * With the fifth clearance in, the filing goes to BPLO for its second act.
     */
    expect(clearanceStatusOf($appId, 'FSIC'))->toBe('approved');
    expect(Permit::where('application_id', $appId)->count())->toBe(5);
    expect(Application::find($appId)->status->value)->toBe('for_final_approval');

    // BPLO signs the whole thing off on the strength of the five.
    $bploAssignmentId = ApplicationAssignment::where('application_id', $appId)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->value('id');
    test()->withHeaders(authAs($deptEmail['BPLO']))
        ->postJson("/api/v1/assignments/{$bploAssignmentId}/approve", ['remarks' => 'All requirements met.'])
        ->assertOk();

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
 * ⚠ CURRENTLY RED, and left red deliberately. This is a gap in the code, not a
 * stale test.
 *
 * `WorkflowService::approveClearance()` refuses outright when the application is
 * terminal — "This application has been decided. Its permits can no longer be
 * acted on." — so the rule below is the flow's own, stated by the flow's own
 * code. But `Inspection::canBeReinspected()` no longer checks it. It used to ask
 * `application->status === ForInspection`, which happened to carry the terminal
 * guard for free; that had to go when `for_inspection` became a per-permit
 * state, and `permitIsAwaitingInspection()` replaced it without carrying the
 * guard across. A rejected filing keeps its permit rows at `for_inspection`,
 * nothing walks them back, so the office can still book a visit against a filing
 * the LGU has refused — the exact failure `ApplicationStatus::allowedNext()`
 * describes ("booked a site visit against a filing the LGU had refused").
 *
 * The assertion is not weakened to match. The fix is one clause in
 * `canBeReinspected()`, or a walk-back of the permit rows in
 * `rejectApplication()`, and which of those it should be is the lead's call.
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

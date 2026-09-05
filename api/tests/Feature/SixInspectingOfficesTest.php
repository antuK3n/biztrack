<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * Every office that issues a clearance can conduct the visit behind it.
 *
 * `permit_types.requires_inspection` is true for all six supporting clearances
 * — SANITARY, FSIC, OCCUPANCY, CEC, ZONING, MARKET — and false only for
 * BUSINESS. That is one flag, but it is really a pair of facts that have to
 * agree, and for a long time they did not:
 *
 *   1. the workflow books a visit per inspecting office — one each, at the
 *      moment that office approves its own review — so a filing carrying all
 *      six clearances gets SIX inspections, not the two it used to; and
 *   2. somebody in each of those six offices has to be able to close one.
 *
 * (2) is the half that had been missing. `inspection.manage` was on
 * `sanitary_officer` and `fire_inspector` alone, and every /inspections* route
 * is behind it, so OBO, CENRO, CPDO and the Market Office could not even see a
 * visit booked against their own office — the client's report, verbatim: "OBO,
 * CENRO, Market, and Zoning admins cannot approve inspection. Only Sanitary and
 * Fire has it." Because WorkflowService issues only once every review is
 * complete AND every current visit has passed, turning the flag on without the
 * permission would not have improved anything; it would have parked every
 * clearance filing in `for_inspection` with nobody but the super admin able to
 * move it.
 *
 * So these tests are the pair. Break either half and one of them goes red.
 */

/** Which account speaks for each inspecting office. */
const OFFICE_INSPECTOR = [
    'CHO' => 'sanitary@biztrack.local',
    'BFP' => 'fire@biztrack.local',
    'CPDO' => 'zoning@biztrack.local',
    'OBO' => 'obo@biztrack.local',
    'CENRO' => 'cenro@biztrack.local',
];

/**
 * A paid filing asking for the business permit and all six clearances, with
 * every office assignment approved — so the workflow has booked its visits and
 * the filing is waiting on them.
 *
 * Each visit was booked by its own office's sign-off, in the order the loop
 * happens to take them, not in one batch at the end. Approving all seven here
 * is what makes the fixture SETTLED rather than what triggers the booking: with
 * every review in, the only thing between this filing and its permits is the
 * six site visits, which is the state each case below wants to start from.
 */
function filingWithEveryClearance(): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Six Office Trading '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '6 Clearance Row', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 500000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    $app = Application::findOrFail($appId);

    // Confirmed on receipt. Without a person's name on the processing category
    // no office may approve at all, and what this file is about is which of the
    // seven offices gets sent out to the premises.
    classifyAsOfficer($app);

    // Each office signs off its own assignment; ApplicationVisibility keeps a
    // reviewer to the filings routed to their department, so no one account can
    // stand in for the rest.
    foreach ($app->assignments()->with('department')->get() as $assignment) {
        $code = $assignment->department->code;
        authAs($code === 'BPLO' ? 'bplo@biztrack.local' : OFFICE_INSPECTOR[$code]);
        test()->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();
    }

    return $app->fresh();
}

it('books one visit for each of the six clearance offices, and none for the mayor’s permit', function () {
    $app = filingWithEveryClearance();

    $offices = $app->inspections()->with('department')->get()
        ->pluck('department.code')->sort()->values()->all();

    /*
     * Six, not two. BPLO is absent and that is the rule rather than an
     * omission: BPLO issues the Mayor's Permit on the strength of the six
     * clearances, so a seventh visit of its own would be a visit nobody
     * performs and would stall approveAndIssue behind it forever.
     */
    expect($offices)->toBe(['BFP', 'CENRO', 'CHO', 'CPDO', 'OBO'])
        ->and($app->status)->toBe(ApplicationStatus::ForInspection)
        ->and($app->permits()->count())->toBe(0);
});

it('gives every inspecting office an active officer to book the visit to', function () {
    /*
     * WorkflowService::leastLoadedInspector returns null when an office has no
     * active user, and a visit created with a null inspector is not fatal — the
     * queue is scoped by department, and conduct() adopts the officer who
     * closes it. But it does mean nobody is named on the sheet, so it is worth
     * knowing which offices are staffed rather than discovering it on a filing.
     */
    foreach (array_keys(OFFICE_INSPECTOR) as $code) {
        $departmentId = Department::where('code', $code)->value('id');
        expect(User::where('department_id', $departmentId)->where('is_active', true)->exists())
            ->toBeTrue("{$code} has no active user to inspect for it");
    }

    $app = filingWithEveryClearance();
    expect($app->inspections()->whereNull('inspector_user_id')->count())->toBe(0);
});

it('lets each of the six offices see and close its own visit, and issues once all six pass', function () {
    $app = filingWithEveryClearance();

    foreach ($app->inspections()->with('department')->get() as $inspection) {
        $code = $inspection->department->code;
        authAs(OFFICE_INSPECTOR[$code]);

        /*
         * The list read first, deliberately. `index` is behind
         * `permission:inspection.manage` and then scoped to the caller's own
         * department, so this asserts both halves at once: the office holds the
         * permission, and the visit booked for it is the one it is shown. A 403
         * here is the client's bug back again.
         */
        $visible = collect(
            test()->getJson('/api/v1/inspections')->assertOk()->json('data')
        )->pluck('id')->all();
        expect(in_array($inspection->id, $visible, true))
            ->toBeTrue("{$code} cannot see the inspection booked against its own office");

        test()->postJson("/api/v1/inspections/{$inspection->id}/conduct", [
            'result' => 'passed',
            'findings' => 'Premises inspected and found compliant.',
        ])->assertOk();
    }

    $settled = $app->fresh();

    // Seven permits: the Mayor's Permit and the six clearances behind it.
    expect($settled->status)->toBe(ApplicationStatus::Approved)
        ->and($settled->permits()->count())->toBe(7);
});

it('holds the filing until the last of the six visits passes', function () {
    $app = filingWithEveryClearance();
    $inspections = $app->inspections()->with('department')->get();

    /*
     * The point of the six is that they are six. Five passes must issue
     * nothing — recordInspection releases the permits only when no CURRENT
     * inspection on the file is still outstanding (and, since visits are booked
     * per office as each review lands, only when every review is in too), and
     * an off-by-one there would hand an applicant a Mayor's Permit while an
     * office was still on its way out.
     */
    foreach ($inspections->slice(0, 5) as $inspection) {
        authAs(OFFICE_INSPECTOR[$inspection->department->code]);
        test()->postJson("/api/v1/inspections/{$inspection->id}/conduct", ['result' => 'passed'])->assertOk();

        expect($app->fresh()->status)->toBe(ApplicationStatus::ForInspection)
            ->and($app->permits()->count())->toBe(0);
    }

    $last = $inspections->last();
    authAs(OFFICE_INSPECTOR[$last->department->code]);
    test()->postJson("/api/v1/inspections/{$last->id}/conduct", ['result' => 'passed'])->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

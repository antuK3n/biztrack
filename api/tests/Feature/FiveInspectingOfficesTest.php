<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\WorkflowService;

/*
 * Every office that issues a clearance can book and close the visit behind it.
 *
 * FIVE offices, not six. This file was `SixInspectingOfficesTest` and the count
 * in its name stopped being true on 6 September 2026, when Market Clearance and
 * the City Market Office were removed from the system entirely — the client
 * confirmed with the LGU that neither is needed. A test whose name states a
 * number the code contradicts is worse than one with a vague name, because the
 * number reads as the specification.
 *
 * `permit_types.requires_inspection` is true for all five supporting clearances
 * — SANITARY, FSIC, OCCUPANCY, CEC, ZONING — and false only for BUSINESS. That
 * is one flag, but it is really a pair of facts that have to agree, and for a
 * long time they did not:
 *
 *   1. every one of those five permits gets a site visit — the LGU inspects the
 *      premises, not the paperwork — so a filing gets FIVE inspections, not the
 *      two it used to; and
 *   2. somebody in each of those five offices has to be able to book one and
 *      close it.
 *
 * (2) is the half that had been missing. `inspection.manage` was on
 * `sanitary_officer` and `fire_inspector` alone, and every /inspections* route
 * is behind it, so OBO, CENRO and CPDO could not even see a visit booked against
 * their own office — the client's report, verbatim: "OBO, CENRO, Market, and
 * Zoning admins cannot approve inspection. Only Sanitary and Fire has it."
 *
 * So these tests are the pair. Break either half and one of them goes red.
 *
 * What changed with the September flow, and shows up throughout below: a visit
 * is no longer BOOKED by the office approving its paperwork. Approval moves that
 * permit to `for_inspection` and stops; the office then CHOOSES a date, because
 * an automatic date is a promise made by a scheduler that does not know whether
 * anyone is free. And each permit is released the moment its own office passes
 * it, rather than the whole filing clearing at once.
 */

/** Which account speaks for each inspecting office, and which permit it issues. */
const OFFICE_INSPECTOR = [
    'CHO' => ['SANITARY', 'sanitary@biztrack.local'],
    'BFP' => ['FSIC', 'fire@biztrack.local'],
    'CPDO' => ['ZONING', 'zoning@biztrack.local'],
    'OBO' => ['OCCUPANCY', 'obo@biztrack.local'],
    'CENRO' => ['CEC', 'cenro@biztrack.local'],
];

/**
 * A paid filing on which every other permit has been applied for and had its
 * PAPERWORK approved — so all five are `for_inspection` and not one visit has
 * been booked yet.
 *
 * That last clause is the fixture's whole value. Under the old flow approving
 * the reviews was what created the inspections, so "reviews all in" and "visits
 * all booked" were the same state and could not be told apart. They are two
 * states now, and every case below starts from the first one.
 */
function filingWithEveryClearance(): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Five Office Trading '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Clearance Row', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 500000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    $app = Application::findOrFail($appId);

    /*
     * The applicant starts each other permit, and THAT is what routes its
     * office. Without this the filing has exactly one assignment — BPLO's,
     * completed at the form approval — and the loop below has nothing to
     * iterate.
     */
    foreach (OFFICE_INSPECTOR as [$permitCode, $email]) {
        authAs('owner@biztrack.local');
        test()->postJson("/api/v1/applications/{$appId}/clearances/{$permitCode}/apply")->assertOk();
    }

    /*
     * Each office signs off its own paperwork; ApplicationVisibility keeps a
     * reviewer to the filings routed to their department, so no one account can
     * stand in for the rest.
     *
     * BPLO is skipped, and that is the flow rather than a convenience. Its
     * assignment is already `completed` — approving the main form is what closed
     * it — and its second act is the FINAL approval, which the workflow refuses
     * until every other permit is approved ("There is nothing for BPLO to
     * approve while this application is Awaiting Other Permits"). Pressing it
     * here 422s and took this whole file down with it.
     */
    foreach ($app->fresh()->assignments()->with('department')->get() as $assignment) {
        $code = $assignment->department->code;
        if ($code === 'BPLO') {
            continue;
        }
        authAs(OFFICE_INSPECTOR[$code][1]);
        test()->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();
    }

    return $app->fresh();
}

/** That office books its visit on its own permit, and returns the visit id. */
function bookVisitFor(Application $app, string $officeCode): int
{
    [$permitCode, $email] = OFFICE_INSPECTOR[$officeCode];

    return test()->withHeaders(authAs($email))
        ->postJson("/api/v1/applications/{$app->id}/permits/{$permitCode}/inspection", [
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertCreated()->json('data.id');
}

it('lets each of the five clearance offices book a visit, and books none for the mayor’s permit', function () {
    $app = filingWithEveryClearance();

    // Approving the paperwork booked nothing. The date is a separate choice.
    expect($app->inspections()->count())->toBe(0);

    foreach (array_keys(OFFICE_INSPECTOR) as $officeCode) {
        bookVisitFor($app, $officeCode);
    }

    $offices = $app->inspections()->with('department')->get()
        ->pluck('department.code')->sort()->values()->all();

    /*
     * Five, not two, and BPLO is absent. That absence is the rule rather than an
     * omission: BPLO issues the Mayor's Permit on the strength of the five
     * clearances, so a sixth visit of its own would be a visit nobody performs
     * and would stall the final approval behind it forever.
     */
    expect($offices)->toBe(['BFP', 'CENRO', 'CHO', 'CPDO', 'OBO'])
        ->and($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits)
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
    foreach (array_keys(OFFICE_INSPECTOR) as $officeCode) {
        bookVisitFor($app, $officeCode);
    }

    expect($app->inspections()->whereNull('inspector_user_id')->count())->toBe(0);
});

it('lets each of the five offices see and close its own visit, releasing that permit at once', function () {
    $app = filingWithEveryClearance();

    $released = 0;
    foreach (array_keys(OFFICE_INSPECTOR) as $officeCode) {
        [$permitCode, $email] = OFFICE_INSPECTOR[$officeCode];
        $visitId = bookVisitFor($app, $officeCode);

        authAs($email);

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
        expect(in_array($visitId, $visible, true))
            ->toBeTrue("{$officeCode} cannot see the inspection booked against its own office");

        test()->postJson("/api/v1/inspections/{$visitId}/conduct", [
            'result' => 'passed',
            'findings' => 'Premises inspected and found compliant.',
        ])->assertOk();

        /*
         * Rule 7: the permit is released the moment its OWN office passes it,
         * with nothing waiting on the other four. This count climbing inside
         * the loop is that rule — the old flow issued nothing until every
         * office had finished, which is what the client asked to be changed.
         */
        $released++;
        expect($app->permits()->count())->toBe($released);
        expect(app(WorkflowService::class)->pivotFor($app->fresh(), $permitCode)->status)
            ->toBe(ClearanceStatus::Approved);
    }

    // Five permits issued by five offices, and the filing is now in BPLO's
    // queue for the final approval rather than approved outright — the Mayor's
    // Permit is BPLO's to mint and is not one of these five.
    $settled = $app->fresh();
    expect($settled->status)->toBe(ApplicationStatus::ForFinalApproval)
        ->and($settled->permits()->count())->toBe(5);
});

it('holds the final approval until the last of the five visits passes', function () {
    $app = filingWithEveryClearance();

    /*
     * The point of the five is that they are five. Four passes must not put the
     * filing in front of BPLO — `refreshReadiness()` moves it to
     * `for_final_approval` only when NO required permit is still outstanding,
     * and an off-by-one there would offer BPLO an Approve button over a filing
     * an office was still on its way out to.
     */
    $offices = array_keys(OFFICE_INSPECTOR);
    foreach (array_slice($offices, 0, 4) as $officeCode) {
        $visitId = bookVisitFor($app, $officeCode);
        authAs(OFFICE_INSPECTOR[$officeCode][1]);
        test()->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed'])->assertOk();

        expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);
    }

    // Four permits are out — each released by its own office — while the
    // application itself has not moved.
    expect($app->permits()->count())->toBe(4);

    $last = end($offices);
    $visitId = bookVisitFor($app, $last);
    authAs(OFFICE_INSPECTOR[$last][1]);
    test()->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed'])->assertOk();

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval)
        ->and($app->permits()->count())->toBe(5);
});

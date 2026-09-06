<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;

/*
 * `?clearance_status=` on the officer queue — the filter the For Inspection tab
 * is built on, and the office boundary inside it.
 *
 * ── Why the tab needed a new filter at all ────────────────────────────────
 *
 * It used to select `application_status=for_inspection`. That status was deleted
 * on 6 September 2026 because it could not be true of an application: five
 * permits are inspected independently, so one filing has a fire visit booked
 * while its zoning clearance has not been applied for. The tab matched nothing
 * and the queue was empty however much work was waiting.
 *
 * Neither could the assignment's own status take over. `approveClearance()`
 * marks it `completed` at the moment it moves the permit to `for_inspection` —
 * accepting the paperwork closes the assignment, conducting the visit does not —
 * so `completed` covers both "inspecting" and "finished" and cannot separate
 * them.
 *
 * ── The half that is a boundary, not a feature ────────────────────────────
 *
 * The filter matches the permit whose `issuing_department_id` IS the
 * assignment's `department_id`. Drop that join condition and the query still
 * looks right and still returns rows — it would just return them to the wrong
 * office, surfacing a filing in the City Health Office's queue because the
 * BUREAU OF FIRE's permit is out for inspection. That is the client's separation
 * rule ("the City Health Office admin must NOT see any application fields
 * regarding Fire Safety Inspection Certificate application") leaking through a
 * list query, so the second test below is the one that matters most.
 */

/** A paid filing, ready for the applicant to start the other permits on. */
function paidFilingForQueue(): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);

    $workflow = app(WorkflowService::class);
    $workflow->submit($app);
    $app->refresh();

    classifyAsOfficer($app);
    $workflow->approveMainForm($app->fresh());
    $app->refresh();

    // The payment itself is PaymentTimingTest's subject; this file needs the
    // state after it, which is where the other permits open.
    $workflow->transition($app, ApplicationStatus::AwaitingOtherPermits, 'Paid.');

    return $app->fresh();
}

/** Apply for one permit, then push it as far as $to. */
function permitReaching(Application $app, string $code, string $to): ApplicationPermitType
{
    $workflow = app(WorkflowService::class);
    $type = PermitType::where('code', $code)->firstOrFail();
    $workflow->startClearance($app, $type, ApplicationPermitType::MODE_APPLY);

    $row = ApplicationPermitType::where('application_id', $app->id)
        ->where('permit_type_id', $type->id)
        ->firstOrFail();

    if ($to === 'for_inspection') {
        $workflow->approveClearance($row->fresh());
    }

    return $row->fresh();
}

it('gives an office the filings whose own permit is out for inspection', function () {
    $app = paidFilingForQueue();
    permitReaching($app, 'SANITARY', 'for_inspection');

    $res = $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/assignments?clearance_status=for_inspection');

    $res->assertOk();

    $row = collect($res->json('data'))->firstWhere('application.tracking_id', $app->tracking_id);
    expect($row)->not->toBeNull();
    // The row says which permit it is and where it stands, so five offices'
    // rows on one filing are not five identical lines.
    expect($row['clearance']['code'])->toBe('SANITARY');
    expect($row['clearance']['status'])->toBe('for_inspection');

    // Nothing arrives in this tab that is not what the tab claims to hold.
    foreach ($res->json('data') as $each) {
        expect($each['clearance']['status'])->toBe('for_inspection');
    }
});

it('does not give an office a filing because another office’s permit is inspecting', function () {
    $app = paidFilingForQueue();

    // Fire's permit reaches inspection. Sanitary's is applied for and no
    // further, so the City Health Office is genuinely still reading.
    permitReaching($app, 'FSIC', 'for_inspection');
    permitReaching($app, 'SANITARY', 'for_approval');

    $res = $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/assignments?clearance_status=for_inspection');

    $res->assertOk();
    /*
     * Zero, not "the row without fire's detail on it". The boundary here is
     * about which filings appear in a queue at all — a row that should not be in
     * this tab cannot be made acceptable by trimming its fields, because its
     * presence is itself the disclosure that BFP is at inspection.
     */
    expect($res->json('meta.total'))->toBe(0);

    /*
     * And the same office DOES still see it where its own work actually is.
     *
     * Found by tracking ID rather than asserted as the only row: DemoSeeder
     * leaves a second filing on the City Health Office's desk, so a bare total
     * here counts the fixture and the seed together and would fail for a reason
     * that has nothing to do with the boundary being tested.
     */
    $open = $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/assignments?status=pending,in_progress,returned&application_status=awaiting_other_permits');
    $open->assertOk();

    $row = collect($open->json('data'))->firstWhere('application.tracking_id', $app->tracking_id);
    expect($row)->not->toBeNull();
    expect($row['clearance']['code'])->toBe('SANITARY');
});

it('reports each office its own permit and never a neighbour’s', function () {
    $app = paidFilingForQueue();
    permitReaching($app, 'FSIC', 'for_inspection');
    permitReaching($app, 'SANITARY', 'for_approval');

    foreach ([
        ['fire@biztrack.local', 'FSIC', 'for_inspection'],
        ['sanitary@biztrack.local', 'SANITARY', 'for_approval'],
    ] as [$email, $code, $status]) {
        $res = $this->withHeaders(authAs($email))->getJson('/api/v1/assignments');
        $res->assertOk();

        $row = collect($res->json('data'))
            ->firstWhere('application.tracking_id', $app->tracking_id);

        expect($row)->not->toBeNull();
        expect($row['clearance']['code'])->toBe($code);
        expect($row['clearance']['status'])->toBe($status);
    }
});

it('leaves BPLO out of the inspection tab without a special case', function () {
    /*
     * BPLO issues the Mayor's / Business Permit, so it matches the join like any
     * other office. It is excluded by the STATUS rather than by an exception:
     * that permit goes not_started → for_approval → approved and is never
     * `for_inspection`, because the outcome of a filing is not site-inspected.
     */
    $app = paidFilingForQueue();
    permitReaching($app, 'SANITARY', 'for_inspection');

    $res = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/assignments?clearance_status=for_inspection');

    $res->assertOk();
    expect(collect($res->json('data'))->firstWhere('application.tracking_id', $app->tracking_id))
        ->toBeNull();
});

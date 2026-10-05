<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Business;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;

/*
 * An office queue holds nothing the applicant has not submitted.
 *
 * ── The bug ──────────────────────────────────────────────────────────────────
 *
 * The client, signed in as CPDO on 30 September 2026: BIZ-2026-00004, "Juan's
 * Milk Tea", Zoning Clearance, chip reading **Not Yet Submitted**, sitting in
 * the queue with an Assign to Me button beside it. They checked the applicant's
 * side before reporting it, and the Zoning sheet genuinely had never been sent.
 * *"There should be no things listed here that are NOT YET SUBMITTED... Fix
 * this bug for ALL OFFICES CONCERNING THIS MATTER."*
 *
 * Four such rows were in the register, across four different offices — CHO,
 * BFP, CENRO and CPDO — so it was never one office's problem.
 *
 * ── Where they came from ─────────────────────────────────────────────────────
 *
 * Filings from the model where PAYMENT routed all six offices at once. The
 * assignment opened and the paperwork never followed. Nothing made since
 * produces one, because `startClearance()` routes the office and moves the
 * permit in a single transaction — which is why this file FABRICATES the state
 * rather than reaching it through the API. There is no longer a route to it,
 * and that is the point: the register holds the rows regardless.
 *
 * ── The line the fix must not cross ──────────────────────────────────────────
 *
 * The second test. BPLO's own permit sits at `not_started` for exactly as long
 * as BPLO's first review is open, so a flat "hide not_started" would empty the
 * one queue that is never empty. That is the whole reason this was left unfixed
 * before, and it is the case most likely to be broken by a later tidy-up.
 */

/** A filing at BPLO's first review, with no clearance started. */
function filingAwaitingBplo(): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);

    app(WorkflowService::class)->submit($app);
    $app->refresh();
    classifyAsOfficer($app);

    return $app->fresh();
}

/**
 * The state the old model left behind: an office routed, its permit untouched.
 *
 * Written directly because no code path reaches it any more.
 */
function legacyAssignment(Application $app, string $permitCode): ApplicationAssignment
{
    $type = PermitType::where('code', $permitCode)->firstOrFail();

    return ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => $type->issuing_department_id,
        'status' => 'pending',
    ]);
}

it('keeps a clearance the applicant never submitted out of its office queue', function () {
    $app = filingAwaitingBplo();
    app(WorkflowService::class)->approveMainForm($app->fresh(), null, allOtherPermitIds());
    $app->refresh();
    app(WorkflowService::class)->transition($app, ApplicationStatus::Approved, 'Paid.');

    // Routed to CPDO, ZONING never started — the client's exact row.
    legacyAssignment($app->fresh(), 'ZONING');
    expect(
        app(WorkflowService::class)->pivotFor($app->fresh(), 'ZONING')->status->value
    )->toBe('not_started');

    $zoning = authAs('zoning@biztrack.local');
    $rows = test()->withHeaders($zoning)->getJson('/api/v1/assignments')
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('application.tracking_id'))
        ->not->toContain($app->tracking_id);
});

it('still shows BPLO its own review, whose permit is not started by design', function () {
    /*
     * The case a flat "hide not_started" would break, and the reason this was
     * left alone until now. BPLO's Business Permit pivot reads `not_started`
     * for the whole of its first review — `approveMainForm()` is what moves it
     * — so its queue depends on that row being visible.
     */
    $app = filingAwaitingBplo();

    $bplo = authAs('bplo@biztrack.local');
    $rows = test()->withHeaders($bplo)->getJson('/api/v1/assignments')
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('application.tracking_id'))
        ->toContain($app->tracking_id);
});

it('shows the clearance the moment its sheet is handed in', function () {
    /*
     * The other half of the rule: hiding is about the paperwork's absence, not
     * about the filing. The same office, the same filing, one submit later.
     */
    $app = filingAwaitingBplo();
    app(WorkflowService::class)->approveMainForm($app->fresh(), null, allOtherPermitIds());
    $app->refresh();
    app(WorkflowService::class)->transition($app, ApplicationStatus::Approved, 'Paid.');

    $type = PermitType::where('code', 'ZONING')->firstOrFail();
    $workflow = app(WorkflowService::class);
    $workflow->startClearance($app->fresh(), $type, 'apply');

    $zoning = authAs('zoning@biztrack.local');
    expect(
        collect(test()->withHeaders($zoning)->getJson('/api/v1/assignments')->json('data'))
            ->pluck('application.tracking_id')
    )->not->toContain($app->tracking_id);

    satisfyChecklist($app->fresh(), $type);
    $workflow->submitClearanceForm($app->fresh(), $type);

    expect(
        collect(test()->withHeaders($zoning)->getJson('/api/v1/assignments')->json('data'))
            ->pluck('application.tracking_id')
    )->toContain($app->tracking_id);
});

it('counts the tab badge the same way it fills the list', function () {
    /*
     * A badge counting rows the list refuses to show is its own bug: an office
     * reads "For Approval 1" over an empty queue and reasonably concludes the
     * screen is broken. The counts come from a second query, so the exclusion
     * has to be on both.
     */
    $app = filingAwaitingBplo();
    app(WorkflowService::class)->approveMainForm($app->fresh(), null, allOtherPermitIds());
    $app->refresh();
    app(WorkflowService::class)->transition($app, ApplicationStatus::Approved, 'Paid.');
    legacyAssignment($app->fresh(), 'ZONING');

    $zoning = authAs('zoning@biztrack.local');
    $body = test()->withHeaders($zoning)->getJson('/api/v1/assignments')->assertOk()->json();

    $listed = count($body['data']);
    $counted = array_sum($body['meta']['status_counts'] ?? []);

    expect($counted)->toBe($listed);
});

it('judges an office on its own permit and not on the one beside it', function () {
    /*
     * The office boundary, restated here because this filter makes the same
     * join the `clearance_status` filter does. Drop the `whereColumn` and the
     * query still returns rows — it just hides CHO's work because CPDO's
     * permit is unstarted, which is the separation rule leaking through a
     * list query.
     */
    $app = filingAwaitingBplo();
    app(WorkflowService::class)->approveMainForm($app->fresh(), null, allOtherPermitIds());
    $app->refresh();
    app(WorkflowService::class)->transition($app, ApplicationStatus::Approved, 'Paid.');

    // CHO's sheet is in; CPDO's was never started.
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();
    $workflow = app(WorkflowService::class);
    $workflow->startClearance($app->fresh(), $sanitary, 'apply');
    satisfyChecklist($app->fresh(), $sanitary);
    $workflow->submitClearanceForm($app->fresh(), $sanitary);

    $cho = Department::where('code', 'CHO')->firstOrFail();
    expect(
        ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $cho->id)
            ->exists()
    )->toBeTrue();

    $healthOfficer = authAs('sanitary@biztrack.local');
    expect(
        collect(test()->withHeaders($healthOfficer)->getJson('/api/v1/assignments')->json('data'))
            ->pluck('application.tracking_id')
    )->toContain($app->tracking_id);
});

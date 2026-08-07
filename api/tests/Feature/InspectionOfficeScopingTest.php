<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Inspection;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * INS-8 — an office reads the write-up of its OWN visits, and nobody else's.
 *
 * `GET /inspections/{id}` refused a cross-office read from the day it was
 * written. The officer's review sheet does not call it: it reads visits out of
 * `GET /assignments/{id}`, and `GET /applications/{id}` carries the same block,
 * both through ApplicationResource → InspectionResource, which filtered
 * nothing. Same user, same row, two endpoints, two answers — and the door the
 * product actually opens was the one handing the data over. An e2e run found 21
 * leaked visits across all six inspecting offices, carrying the named inspector
 * and findings prose such as "Food handlers without current health
 * certificates…".
 *
 * The whole existing suite passed throughout that leak, because every test
 * about inspection scoping asked `/inspections*` — the endpoint that was
 * already correct. So the cases below deliberately read the OTHER two doors,
 * and the first one asserts both answers in a single test so the contradiction
 * cannot be half-fixed again.
 *
 * The other half of the rule is that this must not over-tighten. A filing does
 * not advance until every current visit passes, so an office genuinely needs to
 * see that another office's visit happened and how it went; `visibleVisit`
 * below asserts status, result and department survive.
 */

/** The prose that must never cross an office boundary. */
const OTHER_OFFICE_FINDINGS = 'Food handlers without current health certificates on the premises.';

/**
 * A paid filing routed to BPLO, CHO and BFP, with every review approved so both
 * clearance offices have a visit booked — and BFP's visit conducted and written
 * up, so there is something on the record for CHO to be refused.
 *
 * BFP passes rather than fails on purpose: CHO's visit is left open, so the
 * filing stays `for_inspection` and both assignments stay readable. A leak on a
 * settled filing would be the easier case; this is the state the review sheet
 * is actually open in.
 *
 * @return array{app: Application, visit: Inspection}
 */
function filingWithOneOfficesVisitWrittenUp(): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Cross Office Findings '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '8 Boundary Road', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 300000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'SANITARY', 'FSIC'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    $app = Application::findOrFail($appId);

    // Each office signs off its own assignment — a reviewer is kept to the
    // filings routed to their department, so no one account stands in for the
    // rest. The last sign-off is what books the visits.
    foreach ($app->assignments()->with('department')->get() as $assignment) {
        authAs(match ($assignment->department->code) {
            'BPLO' => 'bplo@biztrack.local',
            'CHO' => 'sanitary@biztrack.local',
            'BFP' => 'fire@biztrack.local',
        });
        test()->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();
    }

    $visit = $app->inspections()
        ->whereRelation('department', 'code', 'BFP')
        ->firstOrFail();

    authAs('fire@biztrack.local');
    test()->postJson("/api/v1/inspections/{$visit->id}/conduct", [
        'result' => 'failed',
        'findings' => OTHER_OFFICE_FINDINGS,
    ])->assertOk();

    return ['app' => $app->fresh(), 'visit' => $visit->fresh()];
}

/** One inspection out of a filing payload, by row id. */
function visitIn(array $inspections, int $id): array
{
    $match = collect($inspections)->firstWhere('id', $id);
    expect($match)->not->toBeNull("inspection {$id} is missing from the payload");

    return $match;
}

/** The application id an office's own assignment points at. */
function assignmentFor(Application $app, string $departmentCode): int
{
    return $app->assignments()
        ->whereRelation('department', 'code', $departmentCode)
        ->value('id');
}

it('withholds another office’s findings and inspector from the assignment review sheet', function () {
    ['app' => $app, 'visit' => $bfpVisit] = filingWithOneOfficesVisitWrittenUp();
    $choAssignment = assignmentFor($app, 'CHO');

    authAs('sanitary@biztrack.local');

    /*
     * The endpoint that was always right, asserted here rather than in its own
     * case so the two answers stand side by side. If this 403 ever loosens, the
     * expectations below stop meaning what they say.
     */
    test()->getJson("/api/v1/inspections/{$bfpVisit->id}")->assertForbidden();

    $payload = test()->getJson("/api/v1/assignments/{$choAssignment}")
        ->assertOk()->json('data.application.inspections');

    $leaked = visitIn($payload, $bfpVisit->id);

    expect($leaked['findings'])->toBeNull()
        ->and($leaked['inspector'])->toBeNull()
        // Bare progress is shared on purpose: CHO's own clearance cannot issue
        // until BFP's visit passes, so it has to be able to see that it did not.
        ->and($leaked['status'])->toBe('completed')
        ->and($leaked['result'])->toBe('failed')
        ->and($leaked['department']['code'])->toBe('BFP');
});

it('withholds another office’s findings and inspector from the filing detail endpoint', function () {
    ['app' => $app, 'visit' => $bfpVisit] = filingWithOneOfficesVisitWrittenUp();

    // The second door onto the same block. ApplicationController::show eager
    // loads `inspections.inspector` for its own reasons; the filter has to hold
    // regardless of what the caller chose to load.
    authAs('sanitary@biztrack.local');
    $leaked = visitIn(
        test()->getJson("/api/v1/applications/{$app->id}")->assertOk()->json('data.inspections'),
        $bfpVisit->id,
    );

    expect($leaked['findings'])->toBeNull()
        ->and($leaked['inspector'])->toBeNull();
});

it('keeps an office’s own findings and inspector on its own visit', function () {
    ['app' => $app, 'visit' => $bfpVisit] = filingWithOneOfficesVisitWrittenUp();
    $bfpAssignment = assignmentFor($app, 'BFP');

    // The same row, the same endpoint, the office that conducted it. Without
    // this the filter could be "blank every visit" and still pass above.
    authAs('fire@biztrack.local');
    $own = visitIn(
        test()->getJson("/api/v1/assignments/{$bfpAssignment}")->assertOk()->json('data.application.inspections'),
        $bfpVisit->id,
    );

    expect($own['findings'])->toBe(OTHER_OFFICE_FINDINGS)
        ->and($own['inspector']['name'])->not->toBeNull();
});

it('keeps the write-up for the applicant whose premises were inspected', function () {
    ['app' => $app, 'visit' => $bfpVisit] = filingWithOneOfficesVisitWrittenUp();

    // The findings are what the owner has to put right before a re-inspection
    // can pass. Hiding them would break the filing.
    authAs('owner@biztrack.local');
    $own = visitIn(
        test()->getJson("/api/v1/applications/{$app->id}")->assertOk()->json('data.inspections'),
        $bfpVisit->id,
    );

    expect($own['findings'])->toBe(OTHER_OFFICE_FINDINGS)
        ->and($own['inspector']['name'])->not->toBeNull();
});

it('keeps the write-up for BPLO and the super admin, who read across offices by design', function (string $email, callable $read) {
    ['app' => $app, 'visit' => $bfpVisit] = filingWithOneOfficesVisitWrittenUp();

    authAs($email);
    $seen = visitIn($read($app), $bfpVisit->id);

    expect($seen['findings'])->toBe(OTHER_OFFICE_FINDINGS)
        ->and($seen['inspector']['name'])->not->toBeNull();
})->with([
    // BPLO coordinates every clearance, and reads the filing through its own
    // assignment; the super admin has no department at all, so /assignments is
    // closed to them and the register is read through /applications.
    'BPLO' => ['bplo@biztrack.local', fn (Application $app) => test()
        ->getJson('/api/v1/assignments/'.assignmentFor($app, 'BPLO'))
        ->assertOk()->json('data.application.inspections')],
    'super admin' => ['admin@biztrack.local', fn (Application $app) => test()
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()->json('data.inspections')],
]);

it('keeps the write-up on the conducting office’s own /inspections feed', function () {
    ['visit' => $bfpVisit] = filingWithOneOfficesVisitWrittenUp();

    // The endpoint the filter must not have caught in the crossfire: the same
    // resource serves the office's own queue, and blanking a visit there would
    // hide an officer's findings from the officer who wrote them.
    authAs('fire@biztrack.local');
    $shown = test()->getJson("/api/v1/inspections/{$bfpVisit->id}")->assertOk()->json('data');

    expect($shown['findings'])->toBe(OTHER_OFFICE_FINDINGS)
        ->and($shown['inspector']['name'])->not->toBeNull();

    $listed = visitIn(test()->getJson('/api/v1/inspections')->assertOk()->json('data'), $bfpVisit->id);
    expect($listed['findings'])->toBe(OTHER_OFFICE_FINDINGS);
});

it('names the department that owns a visit from the row, not from the permit type', function () {
    ['visit' => $bfpVisit] = filingWithOneOfficesVisitWrittenUp();

    /*
     * The boundary this filter uses is `inspections.department_id`. It is NOT
     * NULL and is set by WorkflowService::openInspection from the office whose
     * approval booked the visit, so the conducting office is a fact on the row
     * rather than something inferred through the permit type — which is what
     * readsOfficeSheet and readsPermitOf have to do. If a future change ever
     * makes that column nullable, this goes red before the filter starts
     * failing open.
     */
    expect($bfpVisit->department_id)->toBe(Department::where('code', 'BFP')->value('id'))
        ->and(Inspection::whereNull('department_id')->exists())->toBeFalse();
});

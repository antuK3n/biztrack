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
 * The other half of the rule is that this must not over-tighten. BPLO's final
 * approval is gated on every required permit being approved, and no permit is
 * approved until its own visit passes, so an office genuinely needs to see that
 * another office's visit happened and how it went; the first case below asserts
 * status, result and department survive.
 */

/** The prose that must never cross an office boundary. */
const OTHER_OFFICE_FINDINGS = 'Food handlers without current health certificates on the premises.';

/**
 * A paid filing routed to BPLO, CHO and BFP, with both clearance offices having
 * approved their permit and booked a visit — and BFP's visit conducted and
 * written up, so there is something on the record for CHO to be refused.
 *
 * CHO's visit is booked and left unconducted on purpose. Its permit is still
 * outstanding, so both assignments stay readable and the review sheet is open
 * in the state an officer actually meets it; a leak on a settled filing would
 * be the easier case.
 *
 * Three steps here are new, and each is a step the 6 September procedure makes
 * the fixture perform rather than get for free
 * (docs/application-flow-2026-09.md):
 *
 *  - BPLO approves the main form BEFORE the bill exists, so `pay` at
 *    `for_approval` is refused with "BPLO has not approved this application
 *    yet";
 *  - the applicant OPENS each other permit after paying, and that is what
 *    routes CHO and BFP — payment alone leaves BPLO the only assignment;
 *  - the office PICKS the inspection date. Approving a permit's paperwork no
 *    longer books anything: the auto-scheduler promised a date two working days
 *    out on behalf of an office nobody had asked.
 *
 * The BPLO assignment is deliberately not in the approval loop any more. BPLO
 * acts twice and its first act already happened above; pressing Approve again
 * at `awaiting_other_permits` is refused, and rightly — there is nothing for it
 * to approve until every other permit is in.
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
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'SANITARY', 'FSIC'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    // BPLO reads the form, which is what raises the bill. Classification comes
    // with it: an office may not approve until somebody has put their name to
    // the processing category, and that gate is not what this file is about.
    bploApprovesForm($appId);

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    /*
     * The applicant opens both other permits and fills both sheets in, which is
     * what routes CHO and BFP.
     *
     * Apply on its own no longer reaches an office. SANITARY and FSIC both
     * carry a form, and on a form-bearing permit `startClearance` records the
     * choice, opens the sheet and stops — Apply's whole job is to OPEN the
     * form, and it used to announce "For Approval" on a form the applicant had
     * not touched (client, 9 September 2026). Saving the sheet with `submit` is
     * what hands it to the office and creates the assignment
     * (WorkflowService::submitClearanceForm). Every case in this file reads a
     * visit off an office's own review sheet, so without the second call there
     * is no assignment to open and the fixture dies looking for one.
     *
     * The answers and the submission go in ONE write on purpose. `ownerMayEdit`
     * hands the sheet back to the office the moment it is submitted, so a save
     * after a submit would be a 422; apply → fill → submit is the only order
     * the product now allows, and a single PUT is that order.
     */
    foreach ([
        'SANITARY' => ['sanitary_classification' => 'Food Establishment'],
        'FSIC' => ['storey_count' => '2', 'floor_area' => '180'],
    ] as $code => $formData) {
        test()->withHeaders($owner)
            ->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")
            ->assertSuccessful();

        test()->withHeaders($owner)
            ->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
                'form_data' => $formData,
                'submit' => true,
            ])->assertSuccessful();
    }

    $app = Application::findOrFail($appId);

    /*
     * Each office signs off its own permit and books its own visit — a reviewer
     * is kept to the filings routed to their department, so no one account
     * stands in for the rest.
     */
    foreach ([['CHO', 'sanitary@biztrack.local', 'SANITARY'], ['BFP', 'fire@biztrack.local', 'FSIC']] as [$code, $email, $permit]) {
        $assignment = $app->assignments()->whereRelation('department', 'code', $code)->firstOrFail();

        authAs($email);
        test()->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();
        test()->postJson("/api/v1/applications/{$appId}/permits/{$permit}/inspection", [
            'scheduled_at' => now()->addWeekdays(2)->toDateString(),
        ])->assertCreated();
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

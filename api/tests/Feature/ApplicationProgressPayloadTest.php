<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;

/*
 * What the review sheet needs in order to draw where a filing is, and to be
 * right about it.
 *
 * The progression rail is not decoration — it is the answer to the one question
 * an admin opens the sheet asking. Two facts make it possible and neither was on
 * the wire before:
 *
 *  - `status_history`, the transitions WorkflowService has been recording all
 *    along (8,628 rows) and no officer screen could read.
 *  - `permit_types[].requires_inspection`, which decides whether a permit's
 *    office picks an inspection date at all.
 *
 * These are pinned because they are cheap to drop by accident. `status_history`
 * is conditional on an eager load, so forgetting the load in a controller
 * degrades it to an empty array rather than an error, and the rail would quietly
 * render an empty history against a filing with a long one.
 *
 * ── What the second fact is FOR changed on 6 September 2026 ─────────────────
 *
 * This header used to say `requires_inspection` decides whether For Inspection
 * is a stage the APPLICATION will ever enter, and named
 * `WorkflowService::afterReviewProgress` as the code that reads it. Neither
 * holds: `for_inspection` was retired from `ApplicationStatus` and now lives on
 * `ClearanceStatus`, one permit at a time, and `afterReviewProgress` was
 * deleted along with the single-review model it belonged to. The rail
 * (`web/src/components/ApplicationProgress.tsx`) draws the application's own
 * statuses and does not consult the flag at all.
 *
 * The flag is still worth pinning, for a different and narrower reason:
 * `WorkflowService::approveClearance` branches on it. A permit type whose
 * office does not inspect goes straight from the office's approval to approved
 * and is issued there; every other one waits for a date and a visit. Nothing
 * seeded is in the first position today — see the last two tests, which pin
 * exactly that.
 */

/**
 * A paid, routed filing carrying exactly these permit types.
 *
 * Driven through the real endpoints rather than factories so the history rows
 * are the ones WorkflowService actually writes, in the order it writes them.
 *
 * @param  list<string>  $permitCodes
 */
function progressFiling(array $permitCodes): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Progress Rail Test '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Progress Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
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

    return $appId;
}

it('puts the recorded transitions on the application record, oldest first', function () {
    $appId = progressFiling(['BUSINESS']);

    $history = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")
        ->assertOk()
        ->json('data.status_history');

    /*
     * Three moves, and their order is the process — but not the three it used
     * to be. This read `['submitted', 'pending_payment', 'under_review']`, and
     * all three of those names are wrong now
     * (docs/application-flow-2026-09.md):
     *
     *  - `submitted` is RETIRED. The client's flow moves a submitted form
     *    straight to For Approval, so the state had a name and no duration.
     *  - the middle move is unchanged in name and reversed in meaning. It used
     *    to follow submission directly; it now follows BPLO ACCEPTING the form,
     *    because BPLO reads a filing before the applicant is asked for money.
     *  - `under_review` is RETIRED. Review is per-permit now, so an application
     *    "Under Review" while one office is inspecting and another has already
     *    issued was a wrong answer to a question the screen did not ask.
     */
    expect(array_column($history, 'to_status'))
        ->toBe(['for_approval', 'pending_payment', 'awaiting_other_permits']);

    expect($history[0])->toHaveKeys(['from_status', 'to_status', 'note', 'changed_by', 'created_at']);
    expect($history[0]['from_status'])->toBe('draft');

    // The note is what the rail shows under a step; it is not decoration either.
    // It no longer says "Routed for review" because payment routes nobody — the
    // offices are routed one at a time as the applicant starts each permit.
    expect($history[2]['note'])->toBe('Payment received. You can now apply for the other permits.');
});

it('names the person behind a transition, and says nothing when there is none', function () {
    $appId = progressFiling(['BUSINESS']);

    $history = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")
        ->assertOk()
        ->json('data.status_history');

    // The applicant submitted, so their name is on the first move.
    expect($history[0]['changed_by']['name'])->not->toBeNull();

    /*
     * The move into review is made by the payment callback under the applicant's
     * own session, so it too carries a name here. What matters for the reader is
     * that the key is always present and always either {name} or null — the
     * review sheet prints "System" for the null case, and it would print
     * "undefined" for a missing key.
     */
    foreach ($history as $row) {
        expect($row)->toHaveKey('changed_by');
        expect($row['changed_by'] === null || isset($row['changed_by']['name']))->toBeTrue();
    }
});

it('says of each permit type whether it will ever be inspected', function () {
    // FSIC inspects, the mayor's permit does not: the two branches in one filing.
    $appId = progressFiling(['BUSINESS', 'FSIC']);

    $types = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")
        ->assertOk()
        ->json('data.permit_types');

    $flags = collect($types)->pluck('requires_inspection', 'code')->all();

    expect($flags)->toHaveKey('BUSINESS')
        ->and($flags['BUSINESS'])->toBeFalse()
        ->and($flags['FSIC'])->toBeTrue();
});

/*
 * RENAMED from "lets a filing with no inspecting permit type be recognised as
 * one that skips inspection", which asserted the exact opposite of what is now
 * true and could not be made to pass by any fixture.
 *
 * The old test filed BUSINESS alone and expected no inspecting permit type on
 * the filing. `WorkflowService::attachRequiredPermitTypes` ended that: all five
 * other permits are attached at SUBMISSION, because all five are required and
 * the one Tax Order of Payment has to price them. The applicant does not choose
 * them and cannot withdraw them, so there is no submitted filing anywhere in
 * the system that carries no inspecting permit type.
 *
 * The old comment anticipated this shape of failure and said how to read it:
 * "this test has nothing left to assert and the skip-inspection branch is dead
 * code". Half of that is right. The branch in
 * `WorkflowService::approveClearance` is genuinely unreachable through the
 * product — nothing seeded is desk-only — but it is deliberate rather than
 * dead: it exists so an LGU marking a future permit type desk-only does not get
 * a permit stuck waiting for a visit nobody performs. So the test is inverted
 * to state the rule that replaced it, and the branch is left alone.
 */
it('puts an inspecting permit type on every submitted filing, however the applicant filled the form', function () {
    // BUSINESS alone is what the wizard sends; the other five arrive at submit.
    $appId = progressFiling(['BUSINESS']);

    $types = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")
        ->assertOk()
        ->json('data.permit_types');

    $codes = collect($types)->pluck('code')->sort()->values()->all();

    expect($codes)->toBe(
        collect(PermitType::CLEARANCE_ORDER)->push(PermitType::OUTCOME_CODE)->sort()->values()->all()
    );

    // Every one of the five is inspected; only the mayor's permit is not.
    expect(collect($types)->where('requires_inspection', true)->pluck('code')->sort()->values()->all())
        ->toBe(collect(PermitType::CLEARANCE_ORDER)->sort()->values()->all());
});

it('has exactly one permit type that skips inspection, and it is the mayor’s permit', function () {
    /*
     * The companion to the test above: it pins WHY that fixture is the only one
     * available, so a future reader does not read `['BUSINESS']` as an
     * arbitrary pick. ReferenceSeeder is the source of the rule; this reads it
     * back off the reference endpoint the wizard itself uses.
     */
    $types = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/reference/permit-types')
        ->assertOk()
        ->json('data');

    $skipsInspection = collect($types)->reject->requires_inspection->pluck('code')->values()->all();

    expect($skipsInspection)->toBe(['BUSINESS']);
});

it('carries the history into the officer review sheet without a second request', function () {
    $appId = progressFiling(['BUSINESS']);

    $bplo = authAs('bplo@biztrack.local');
    $assignmentId = collect(
        test()->withHeaders($bplo)->getJson('/api/v1/assignments')->assertOk()->json('data')
    )->firstWhere('application.id', $appId)['id'];

    $sheet = test()->withHeaders($bplo)
        ->getJson("/api/v1/assignments/{$assignmentId}")
        ->assertOk()
        ->json('data.application');

    // `under_review` was the third move and is a retired status; the filing now
    // lands in `awaiting_other_permits`, waiting on the five offices rather than
    // sitting in one review queue.
    expect($sheet['status_history'])->toHaveCount(3)
        ->and($sheet['status_history'][2]['to_status'])->toBe('awaiting_other_permits');

    // The rail's other input has to survive the same trip.
    expect($sheet['permit_types'][0])->toHaveKey('requires_inspection');
});

it('serves the same transition shape from the record and from the timeline endpoint', function () {
    /*
     * Both readers are typed against one object (`TimelineEntry`). They stopped
     * being one implementation the moment the review sheet needed history too,
     * so this asserts they did not become two shapes: a by-line that renders on
     * the applicant's page and vanishes on the officer's is the sort of thing
     * that is only ever noticed by the person it was built for.
     */
    $appId = progressFiling(['BUSINESS']);
    $owner = authAs('owner@biztrack.local');

    $embedded = test()->withHeaders($owner)
        ->getJson("/api/v1/applications/{$appId}")->assertOk()->json('data.status_history');
    $endpoint = test()->withHeaders($owner)
        ->getJson("/api/v1/applications/{$appId}/timeline")->assertOk()->json('data');

    expect($endpoint)->toBe($embedded);
});

it('does not make the filing list pay for histories it never shows', function () {
    progressFiling(['BUSINESS']);

    $row = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/applications')->assertOk()->json('data.0');

    /*
     * The list uses ApplicationListResource, which has no history and should
     * grow none: a register page rendering 50 rows has no use for 50 transition
     * logs, and the cost of carrying them is paid on every page load.
     */
    expect($row)->not->toHaveKey('status_history');
});

it('reports an empty history rather than failing when nothing has moved yet', function () {
    $owner = authAs('owner@biztrack.local');
    $businessId = Application::whereNotNull('business_id')->value('business_id');

    $draftId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ])->assertCreated()->json('data.id');

    expect(
        test()->withHeaders($owner)->getJson("/api/v1/applications/{$draftId}")
            ->assertOk()->json('data.status_history')
    )->toBe([]);
})->skip(fn () => Application::whereNotNull('business_id')->doesntExist(), 'no seeded business to hang a draft on');

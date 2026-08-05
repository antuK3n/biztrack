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
 *  - `permit_types[].requires_inspection`, which decides whether For Inspection
 *    is a stage this filing will ever enter. WorkflowService::afterReviewProgress
 *    checks exactly this flag and, when nothing needs an inspection, goes from
 *    the last office approval straight to approveAndIssue(). A rail drawn
 *    without it must either invent an inspection step for a filing that will
 *    skip it or hide one from a filing that will not — both are the screen
 *    lying about the process.
 *
 * These are pinned because they are cheap to drop by accident. `status_history`
 * is conditional on an eager load, so forgetting the load in a controller
 * degrades it to an empty array rather than an error, and the rail would quietly
 * render an empty history against a filing with a long one.
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
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', $permitCodes)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    return $appId;
}

it('puts the recorded transitions on the application record, oldest first', function () {
    $appId = progressFiling(['BUSINESS']);

    $history = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")
        ->assertOk()
        ->json('data.status_history');

    // Submission and payment are three moves, and their order is the process.
    expect(array_column($history, 'to_status'))
        ->toBe(['submitted', 'pending_payment', 'under_review']);

    expect($history[0])->toHaveKeys(['from_status', 'to_status', 'note', 'changed_by', 'created_at']);
    expect($history[0]['from_status'])->toBe('draft');

    // The note is what the rail shows under a step; it is not decoration either.
    expect($history[2]['note'])->toBe('Payment received. Routed for review.');
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

it('lets a filing with no inspecting permit type be recognised as one that skips inspection', function () {
    /*
     * BUSINESS alone, and BUSINESS is the only fixture that can stand here.
     *
     * That is a fact about the domain rather than a convenience. All six
     * supporting clearances — SANITARY, FSIC, OCCUPANCY, CEC, ZONING, MARKET —
     * are inspected, because each of them certifies something about the
     * premises or the site and none can honestly be granted from a desk. The
     * Mayor's Permit is the exception: BPLO issues it on the strength of those
     * six clearances rather than a visit of its own, so it is the one permit
     * type that leaves `requires_inspection` false and therefore the one route
     * on which For Inspection is never drawn.
     *
     * This test used to file BUSINESS + ZONING, from when zoning was granted on
     * paper. If a seventh non-inspecting type is ever added it may join this
     * fixture; if BUSINESS is ever flipped to true, this test has nothing left
     * to assert and the skip-inspection branch of the rail is dead code — which
     * is exactly what a failure here should be read as.
     */
    $appId = progressFiling(['BUSINESS']);

    $types = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")
        ->assertOk()
        ->json('data.permit_types');

    // This is the exact predicate the rail runs before drawing the step.
    expect(collect($types)->contains('requires_inspection', true))->toBeFalse();
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

    expect($sheet['status_history'])->toHaveCount(3)
        ->and($sheet['status_history'][2]['to_status'])->toBe('under_review');

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
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ])->assertCreated()->json('data.id');

    expect(
        test()->withHeaders($owner)->getJson("/api/v1/applications/{$draftId}")
            ->assertOk()->json('data.status_history')
    )->toBe([]);
})->skip(fn () => Application::whereNotNull('business_id')->doesntExist(), 'no seeded business to hang a draft on');

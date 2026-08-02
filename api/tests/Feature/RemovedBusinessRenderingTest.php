<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\Inspection;
use App\Models\User;

/*
 * A business can be removed from the register while its filings stay on it.
 *
 * `Business` soft-deletes, so the default scope drops it from every eager load
 * and the officer-facing payloads answer `business: null` — 375 of the 4,620
 * assignments on the demo register and 63 soft-deleted businesses. The web types
 * declare `business: { name: string }`, non-nullable, so nothing in the type
 * checker ever saw this, and three officer screens read `.name` straight off it:
 * the queue, the inspection list, and the review sheet. Each one threw
 * "Cannot read properties of null" mid-render, and with no error boundary
 * anywhere in the app that empties the entire page, not just the row.
 *
 * The screens now render the filing's tracking id and say the business is gone.
 * These tests pin the shape they were taught to expect: the endpoints must keep
 * answering — not 500, not omit the row — and must keep saying `null` rather
 * than inventing a name, so that "removed" stays distinguishable from "named".
 */

/** Soft-delete the business behind one routed filing and hand back both ids. */
function removeBusinessBehindAnAssignment(): array
{
    $application = Application::whereHas('assignments')
        ->whereNotNull('business_id')
        ->firstOrFail();

    $business = Business::findOrFail($application->business_id);
    $business->delete();

    return [$application->id, $business->id];
}

it('keeps a filing in the officer queue after its business is removed', function () {
    [$applicationId] = removeBusinessBehindAnAssignment();
    $admin = authAs('admin@biztrack.local');

    $rows = test()->withHeaders($admin)
        ->getJson('/api/v1/assignments?per_page=200')
        ->assertOk()
        ->json('data');

    $orphaned = collect($rows)->where('application.id', $applicationId);

    // The work still happened, so the officer must still be able to find it.
    expect($orphaned)->not->toBeEmpty();

    foreach ($orphaned as $row) {
        // Null, not an empty object and not a stand-in name: the screen has to
        // be able to tell "removed" apart from "named" to label the row.
        expect($row['application']['business'])->toBeNull()
            // The tracking id is what the screens fall back to as the row's
            // heading, so it has to be there whenever the business is not.
            ->and($row['application']['tracking_id'])->toBeString()->not->toBeEmpty();
    }
});

it('opens the review sheet for a filing whose business was removed', function () {
    [$applicationId] = removeBusinessBehindAnAssignment();
    $admin = authAs('admin@biztrack.local');

    $assignmentId = Application::findOrFail($applicationId)->assignments()->firstOrFail()->id;

    $body = test()->withHeaders($admin)
        ->getJson("/api/v1/assignments/{$assignmentId}")
        ->assertOk()
        ->json('data');

    expect($body['application']['business'])->toBeNull()
        ->and($body['application']['tracking_id'])->toBeString()->not->toBeEmpty();
});

it('keeps an inspection listed after its business is removed', function () {
    $officer = User::where('email', 'sanitary@biztrack.local')->firstOrFail();

    // Schedule a visit for this officer's own office on a live filing, so the
    // row is one they are allowed to see, then remove the business under it.
    $application = Application::whereNotNull('business_id')->firstOrFail();
    $inspection = Inspection::create([
        'application_id' => $application->id,
        'department_id' => $officer->department_id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay(),
    ]);
    Business::findOrFail($application->business_id)->delete();

    $headers = authAs('sanitary@biztrack.local');

    $rows = test()->withHeaders($headers)
        ->getJson('/api/v1/inspections?per_page=200')
        ->assertOk()
        ->json('data');

    $row = collect($rows)->firstWhere('id', $inspection->id);

    expect($row)->not->toBeNull()
        ->and($row['application']['business'])->toBeNull()
        ->and($row['application']['tracking_id'])->toBeString()->not->toBeEmpty();
});

/*
 * The converse, and checklist item 87 — "errors in passing data for the
 * inspections in both business owner and admin".
 *
 * Everything above pins `business: null` as the signal for "removed from the
 * register". That signal is only worth anything if nothing else emits it, and
 * two payloads did: the applicant's filing detail and the officer's review
 * sheet both nested InspectionResource while eager-loading the stub as
 * `inspections.application:id,tracking_id`. The business relation was therefore
 * never loaded, the resource answered null, and every reader of that null
 * concluded the business was gone — on filings whose business was alive.
 *
 * "Not loaded" and "removed" are different facts. These two tests are what stop
 * a future narrowing of those selects from conflating them again.
 */
/** Put a scheduled visit on a filing whose business is still on the register. */
function scheduleVisitOnALiveFiling(): Application
{
    $officer = User::where('email', 'sanitary@biztrack.local')->firstOrFail();

    $application = Application::whereHas('business')
        ->whereHas('assignments')
        ->firstOrFail();

    Inspection::create([
        'application_id' => $application->id,
        'department_id' => $officer->department_id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay(),
    ]);

    return $application;
}

it('names a live business on the inspections nested in an applicant filing', function () {
    $application = scheduleVisitOnALiveFiling();
    $owner = User::findOrFail($application->applicant_user_id);

    $body = test()->withHeaders(authAs($owner->email))
        ->getJson("/api/v1/applications/{$application->id}")
        ->assertOk()
        ->json('data');

    expect($body['inspections'])->not->toBeEmpty();

    foreach ($body['inspections'] as $inspection) {
        expect($inspection['application'])->not->toBeNull()
            // The name the parent resource carries, not null: the applicant is
            // looking at their own live business.
            ->and($inspection['application']['business']['name'])
            ->toBe($body['business']['name']);
    }
});

it('names a live business on the inspections nested in the review sheet', function () {
    $application = scheduleVisitOnALiveFiling();
    $assignmentId = $application->assignments()->firstOrFail()->id;

    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/assignments/{$assignmentId}")
        ->assertOk()
        ->json('data');

    expect($body['application']['inspections'])->not->toBeEmpty();

    foreach ($body['application']['inspections'] as $inspection) {
        expect($inspection['application']['business']['name'])
            ->toBe($body['application']['business']['name']);
    }
});

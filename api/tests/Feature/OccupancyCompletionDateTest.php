<?php

use App\Models\Application;
use App\Models\PermitType;
use App\Support\OfficeFormAnswers;

/*
 * The Occupancy sheet's Date of Completion is set by the system, never typed
 * (request of 6 October 2026: "what do you mean I can set it to anything").
 * It is the day the applicant submits the sheet, and today until then.
 */

/** A filing carrying the Occupancy clearance, submitted on $submittedAt (or not yet). */
function occupancyFiling(?string $submittedAt): Application
{
    $app = Application::firstOrFail();
    $type = PermitType::where('code', 'OCCUPANCY')->firstOrFail();

    $app->permitTypes()->syncWithoutDetaching([$type->id => ['submitted_at' => $submittedAt]]);
    $app->permitTypes()->updateExistingPivot($type->id, ['submitted_at' => $submittedAt]);

    return $app->fresh();
}

it('dates completion the day the occupancy sheet was submitted, whatever was typed', function () {
    $app = occupancyFiling('2026-08-03 10:15:00');

    $answers = OfficeFormAnswers::derive($app, 'OCCUPANCY', ['completion_date' => '1999-01-01']);

    expect($answers['completion_date'])->toBe('2026-08-03');
});

it('dates completion today while the occupancy sheet is still being filled in', function () {
    $app = occupancyFiling(null);

    $answers = OfficeFormAnswers::derive($app, 'OCCUPANCY', ['completion_date' => '2031-12-31']);

    expect($answers['completion_date'])->toBe(now()->toDateString());
});

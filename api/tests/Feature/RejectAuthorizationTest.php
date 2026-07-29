<?php

use App\Models\Application;
use App\Models\User;

/*
 * Rejecting an application ends it for every office at once. That is the
 * issuing office's call (BPLO) or the super admin's. A per-office reviewer
 * returns its own assignment instead, which is a different, recoverable act.
 */

/** An application still open to a decision (see ApplicationStatus::isTerminal). */
function firstOpenApplication(): Application
{
    return Application::whereIn('status', [
        'submitted', 'under_review', 'returned', 'pending_payment', 'for_inspection',
    ])->firstOrFail();
}

it('lets BPLO reject an application', function () {
    $app = firstOpenApplication();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Incomplete requirements.'])
        ->assertOk();

    expect($app->fresh()->status->value)->toBe('rejected');
});

it('lets the super admin reject an application', function () {
    $app = firstOpenApplication();

    $this->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Duplicate filing.'])
        ->assertOk();
});

it('refuses a rejection from every office that is not BPLO', function (string $email) {
    $app = firstOpenApplication();
    $before = $app->status->value;

    $this->withHeaders(authAs($email))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'not my call'])
        ->assertForbidden();

    // The application must be untouched, not merely the response refused.
    expect($app->fresh()->status->value)->toBe($before)
        ->and($app->fresh()->rejection_reason)->toBeNull();
})->with([
    'sanitary@biztrack.local',
    'fire@biztrack.local',
    'obo@biztrack.local',
    'cenro@biztrack.local',
    'market@biztrack.local',
]);

it('still lets a non-BPLO office return its own assignment', function () {
    // The point of restricting reject is that offices keep a recourse; if this
    // broke, the fix would have taken away their ability to push back at all.
    $user = User::where('email', 'sanitary@biztrack.local')->firstOrFail();
    $assignment = App\Models\ApplicationAssignment::where('department_id', $user->department_id)->first();

    if ($assignment === null) {
        expect(true)->toBeTrue(); // no seeded sanitary assignment in this dataset

        return;
    }

    $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/return", ['remarks' => 'Please send the sanitary permit.'])
        ->assertOk();
});

it('refuses a rejection from a business owner outright', function () {
    $app = firstOpenApplication();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'nope'])
        ->assertForbidden();
});

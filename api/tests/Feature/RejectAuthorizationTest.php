<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
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
    $assignment = ApplicationAssignment::where('department_id', $user->department_id)->first();

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

/*
 * Item 80's second half: "After rejection, allow the admin to put some remarks
 * ... and this will be reflected in the Track page of the business owners."
 *
 * A refusal with no reason leaves the applicant a dead filing and nothing to do
 * about it, and a reason the applicant's own endpoint does not return is the
 * same thing with extra steps. These four guard both ends.
 */
it('refuses a rejection with no reason', function () {
    $app = firstOpenApplication();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    expect($app->fresh()->status->value)->not->toBe('rejected');
});

it('refuses a return with no remarks', function () {
    $assignment = ApplicationAssignment::whereHas(
        'application',
        fn ($a) => $a->whereIn('status', ['submitted', 'under_review', 'pending_payment'])
    )->firstOrFail();

    $this->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/return", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['remarks']);
});

it('returns the rejection reason to the applicant who reads the filing', function () {
    $app = firstOpenApplication();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Floor area does not match the plan.'])
        ->assertOk();

    $applicant = $app->fresh()->applicant;
    $this->withHeaders(authAs($applicant->email))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Floor area does not match the plan.');
});

it('returns a returning office’s remarks to the applicant, with the office named', function () {
    /*
     * The six offices that cannot reject say no by returning, and that refusal
     * is just as unactionable without a reason. The applicant also needs to know
     * WHICH office wants what: a bare remark on an unnamed assignment does not
     * tell them where to go.
     */
    $user = User::where('email', 'sanitary@biztrack.local')->firstOrFail();
    $assignment = ApplicationAssignment::where('department_id', $user->department_id)
        ->whereHas('application', fn ($a) => $a->whereIn('status', [
            'submitted', 'under_review', 'returned', 'pending_payment', 'for_inspection',
        ]))
        ->first();

    if ($assignment === null) {
        expect(true)->toBeTrue(); // no open sanitary assignment in this dataset

        return;
    }

    $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/return", ['remarks' => 'Sanitary permit is expired.'])
        ->assertOk();

    $app = $assignment->fresh()->application;
    $rows = $this->withHeaders(authAs($app->applicant->email))
        ->getJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->json('data.assignments');

    $row = collect($rows)->firstWhere('id', $assignment->id);
    expect($row['remarks'])->toBe('Sanitary permit is expired.')
        ->and($row['department']['name'])->not->toBeNull();
});

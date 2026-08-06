<?php

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Permit;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Validation\ValidationException;

/*
 * A filing may not be approved until somebody has said which tier it is.
 *
 * The client: "On the admin side, choosing the Application category must be
 * required. The admin must not approve the application unless an Application
 * category is chosen."
 *
 * The rule is enforced in WorkflowService, not on the screen, and that is the
 * whole point of testing it here: the review sheet disables its Approve button
 * when the category is missing, but a disabled button is a courtesy to the
 * officer, not a control. Anyone holding a token can POST the approval
 * directly. These tests hold the server's half.
 *
 * Two approval paths reach Approved and they do not share a call: the last
 * office's review goes through approveAssignment(), and a filing already at
 * for_inspection is released by the last passing inspection, which never
 * touches approveAssignment at all. Both are covered below, because gating
 * only the first would leave permits mintable through the second — and the
 * register already holds pre-gate rows sitting in exactly that state.
 *
 * `complexity` is nulled deliberately in these fixtures. submit() seeds a tier
 * from Ra11032::tierFor(), so a filing made through the product arrives
 * categorised and never sees this refusal; null is what rows predating
 * submission-time classification look like, and what filings will look like if
 * open question A10 is ever answered by dropping our unapproved guess.
 */

/** An under-review filing with its category cleared, as pre-gate rows are. */
function uncategorised(): Application
{
    $app = Application::query()
        ->where('status', ApplicationStatus::UnderReview->value)
        ->whereNotNull('submitted_at')
        ->whereHas('assignments', fn ($q) => $q->where('status', AssignmentStatus::Pending->value))
        ->firstOrFail();

    $app->forceFill(['complexity' => null])->save();

    return $app->fresh();
}

function pendingAssignmentOn(Application $app): ApplicationAssignment
{
    return $app->assignments()
        ->where('status', AssignmentStatus::Pending->value)
        ->firstOrFail();
}

it('refuses an office approval while the filing has no category', function () {
    $app = uncategorised();
    $assignment = pendingAssignmentOn($app);

    expect(fn () => app(WorkflowService::class)->approveAssignment($assignment))
        ->toThrow(ValidationException::class);

    /*
     * The refusal has to come BEFORE the write, not after it. An approval that
     * marks the assignment completed and then throws leaves the filing
     * carrying a review no officer intended and no rollback removes — the same
     * ordering bug the rejection guard above it exists to prevent.
     */
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Pending);
});

it('lets that same approval through once a category is chosen', function () {
    $app = uncategorised();
    $assignment = pendingAssignmentOn($app);
    $officer = User::where('department_id', $assignment->department_id)->firstOrFail();

    app(WorkflowService::class)->classify($app->fresh(), 'complex', $officer);

    app(WorkflowService::class)->approveAssignment($assignment->fresh());

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Completed);
});

it('does not gate rejection on the category', function () {
    /*
     * Deliberately ungated. A rejected filing never enters a processing clock,
     * so demanding a tier before an officer can say no would block the refusal
     * for the sake of a deadline that will never be measured — and would leave
     * an officer unable to reject a filing they can see is wrong.
     */
    $app = uncategorised();

    app(WorkflowService::class)->rejectApplication($app, 'Papers do not match the business on file.');

    expect($app->fresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($app->fresh()->complexity)->toBeNull();
});

it('mints no permits on an uncategorised filing', function () {
    $app = uncategorised();
    $before = Permit::where('application_id', $app->id)->count();

    expect(fn () => app(WorkflowService::class)->approveAndIssue($app))
        ->toThrow(ValidationException::class);

    expect(Permit::where('application_id', $app->id)->count())->toBe($before)
        ->and($app->fresh()->status)->not->toBe(ApplicationStatus::Approved);
});

it('names the category as the field at fault so the screen can point at it', function () {
    /*
     * The key matters as much as the message: the review sheet reads the 422's
     * field name to decide what to highlight. A generic error would leave the
     * officer with a red banner and no indication that the control they need is
     * the one under For Office Use Only.
     */
    $app = uncategorised();

    try {
        app(WorkflowService::class)->approveAndIssue($app);
        $this->fail('approveAndIssue accepted a filing with no processing category.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('complexity');
    }
});

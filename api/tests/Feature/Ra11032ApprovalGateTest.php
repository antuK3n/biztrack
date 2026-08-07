<?php

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AuditLog;
use App\Models\Permit;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\Ra11032;
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
 * ── The two fixtures, and which of them is the case that matters ─────────────
 *
 * A NULL `complexity` is the weaker shape and no longer the one this gate is
 * for. It is what rows predating submission-time classification look like, and
 * what filings would look like if open question A10 is ever answered by
 * dropping our unapproved guess — worth holding, but a state the product does
 * not currently produce.
 *
 * An AUTOMATIC category is the shape every live filing actually has, and it is
 * the shape the first version of this gate let straight through. That check
 * asked only whether `complexity` held a tier; submit() seeds one from
 * Ra11032::tierFor() for every filing, so it was never once refused and the
 * suite went green on a control that was doing nothing. The client found it in
 * minutes — "how was this auto fill to complex / also i shouldnt be able to
 * approve if i havent selected an application category". Every case built on
 * automaticallyCategorised() below fails against that old gate; none of the
 * ones built on uncategorised() does. That asymmetry is the point of the file.
 */

/** An under-review filing with its category cleared, as pre-gate rows are. */
function uncategorised(): Application
{
    $app = Application::query()
        ->where('status', ApplicationStatus::UnderReview->value)
        ->whereNotNull('submitted_at')
        ->whereHas('assignments', fn ($q) => $q->where('status', AssignmentStatus::Pending->value))
        ->firstOrFail();

    $app->forceFill([
        'complexity' => null,
        'complexity_set_by_user_id' => null,
        'complexity_set_at' => null,
    ])->save();

    return $app->fresh();
}

/**
 * The same filing carrying a tier the SYSTEM guessed and nobody has claimed —
 * the state every filing is in the moment it reaches an office.
 *
 * Written to the columns rather than produced by re-submitting the filing,
 * because submit() only runs on a draft and this has to keep the pending
 * assignment the approval needs. The values are exactly the three submit()
 * leaves behind: our rule's tier, and no name against it.
 */
function automaticallyCategorised(): Application
{
    $app = uncategorised();

    $app->forceFill([
        'complexity' => Ra11032::tierFor($app),
        'complexity_set_by_user_id' => null,
        'complexity_set_at' => null,
    ])->save();

    $app = $app->fresh();

    // If this ever stops being a real tier the cases below are asserting
    // nothing, because they would be the null fixture wearing another name.
    expect(Ra11032::isTier($app->complexity))->toBeTrue();

    return $app;
}

function pendingAssignmentOn(Application $app): ApplicationAssignment
{
    return $app->assignments()
        ->where('status', AssignmentStatus::Pending->value)
        ->firstOrFail();
}

/** Any officer of the office whose assignment this is. */
function officerBehind(ApplicationAssignment $assignment): User
{
    return User::where('department_id', $assignment->department_id)->firstOrFail();
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
    $officer = officerBehind($assignment);

    app(WorkflowService::class)->classify($app->fresh(), 'complex', $officer);

    app(WorkflowService::class)->approveAssignment($assignment->fresh());

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Completed);
});

it('refuses an office approval on a category the system guessed', function () {
    /*
     * THE CASE THE ORIGINAL BUG WOULD HAVE FAILED.
     *
     * The filing carries a real tier — this is not the null fixture — and it is
     * still refused, because nobody has read it. A guess made from the filing
     * type and the declared capital is a convenience, not the LGU's published
     * classification (open question A10), and this gate exists to make an
     * officer look. The first version asked `complexity !== null` and would
     * have let this through, which is precisely what the client hit.
     */
    $app = automaticallyCategorised();
    $assignment = pendingAssignmentOn($app);

    expect(fn () => app(WorkflowService::class)->approveAssignment($assignment))
        ->toThrow(ValidationException::class);

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Pending);
});

it('mints no permits on a filing carrying only the system’s guess', function () {
    // The other approval path, on the same shape. Gating only approveAssignment
    // would leave permits mintable by the last passing inspection instead.
    $app = automaticallyCategorised();
    $before = Permit::where('application_id', $app->id)->count();

    expect(fn () => app(WorkflowService::class)->approveAndIssue($app))
        ->toThrow(ValidationException::class);

    expect(Permit::where('application_id', $app->id)->count())->toBe($before)
        ->and($app->fresh()->status)->not->toBe(ApplicationStatus::Approved);
});

it('lets the approval through once an officer confirms the very tier the system guessed', function () {
    /*
     * Agreeing with the guess has to be a way out, and it is the commonest one:
     * most guesses are right, and an officer who reads the filing and endorses
     * what is on the screen has made exactly the decision the gate asks for.
     * Without this, confirming would change nothing, record nothing, and leave
     * the officer blocked unless they picked a tier they believed was wrong —
     * a rule that would have taught the office to mis-classify to get past it.
     */
    $app = automaticallyCategorised();
    $guess = $app->complexity;
    $assignment = pendingAssignmentOn($app);

    app(WorkflowService::class)->classify($app->fresh(), $guess, officerBehind($assignment));

    // Same tier as before, and now owned — endorsing is not overriding.
    expect($app->fresh()->complexity)->toBe($guess);

    app(WorkflowService::class)->approveAssignment($assignment->fresh());

    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Completed);
});

it('stamps the officer on an unchanged tier while nobody owns it', function () {
    /*
     * The half of classify() that makes the case above possible, stated on its
     * own so it cannot be deleted as a redundant write. An unchanged value used
     * to return early; it now falls through to be claimed whenever the
     * provenance is still null, and only then.
     */
    $app = automaticallyCategorised();
    $assignment = pendingAssignmentOn($app);
    $officer = officerBehind($assignment);
    $guess = $app->complexity;

    app(WorkflowService::class)->classify($app->fresh(), $guess, $officer);

    $fresh = $app->fresh();
    expect($fresh->complexity)->toBe($guess)
        ->and($fresh->complexity_set_by_user_id)->toBe($officer->id)
        ->and($fresh->complexity_set_at)->not->toBeNull();
});

it('treats a second save of the same tier as the no-op it is', function () {
    /*
     * The stamping above must not become "write on every Save". Once a person's
     * name is on the row there is nothing left to record, and re-writing
     * `complexity_set_at` would move the record of WHEN the decision was made
     * every time someone opened the sheet — which is the one thing an auditor
     * reading a statutory-clock change needs to be able to trust.
     */
    $app = automaticallyCategorised();
    $assignment = pendingAssignmentOn($app);
    $officer = officerBehind($assignment);
    $guess = $app->complexity;

    app(WorkflowService::class)->classify($app->fresh(), $guess, $officer);
    $claimed = $app->fresh();
    $audited = AuditLog::where('action', 'application.reclassified')
        ->where('auditable_id', $app->id)->count();

    // Far enough that a re-stamp would be visible rather than lost in a second.
    $this->travel(2)->minutes();
    app(WorkflowService::class)->classify($app->fresh(), $guess, $officer);

    $again = $app->fresh();
    expect($again->complexity_set_at->toDateTimeString())
        ->toBe($claimed->complexity_set_at->toDateTimeString())
        ->and($again->complexity_set_by_user_id)->toBe($officer->id)
        ->and(AuditLog::where('action', 'application.reclassified')
            ->where('auditable_id', $app->id)->count())->toBe($audited);
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

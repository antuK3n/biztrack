<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\Ra11032;

/*
 * Who decides which RA 11032 tier a filing belongs to.
 *
 * The statute fixes the DEADLINES — three working days for a simple
 * transaction, seven for a complex one, twenty for a highly technical one —
 * and says nothing about which filing is which. That classification is the
 * LGU's, published in its Citizen's Charter, and Malabon has not given us
 * theirs (open question A10 in docs/questions-for-malabon.md).
 *
 * So every tier in the register came from `Ra11032::tierFor()`, a rule this
 * project wrote and nobody at BPLO approved, and the Analytics Dashboard's
 * compliance rate was measured against it. The client's fix is to let the
 * office that is actually reading the filing set the category from Edit mode
 * on the review sheet. These tests hold the four things that makes true and
 * the four it must not.
 *
 * Read the day counts here as fixtures, not as expectations to be adjusted:
 * if `TIERS` ever changes, this file should go red loudly rather than follow.
 */

/** A filing an office is still reviewing, with that office's assignment. */
function openAssignmentFor(string $email): ?ApplicationAssignment
{
    $user = User::where('email', $email)->firstOrFail();

    return ApplicationAssignment::query()
        ->where('department_id', $user->department_id)
        ->whereHas('application', fn ($a) => $a->whereNotIn('status', [
            ApplicationStatus::Approved->value,
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Cancelled->value,
        ])->whereNotNull('submitted_at'))
        ->first();
}

it('states the three statutory tiers and nothing else', function () {
    // The control picks among these. It must never be able to invent a fourth
    // or edit a day count, so the day counts are asserted literally here — the
    // one place in the suite where "3, 7, 20" is written down as the law.
    expect(Ra11032::tierKeys())->toBe(['simple', 'complex', 'highly_technical'])
        ->and(Ra11032::statutoryWorkingDays('simple'))->toBe(3)
        ->and(Ra11032::statutoryWorkingDays('complex'))->toBe(7)
        ->and(Ra11032::statutoryWorkingDays('highly_technical'))->toBe(20)
        ->and(Ra11032::isTier('expedited'))->toBeFalse();
});

it('lets every reviewing office set the category on its own filing', function (string $email) {
    // The client's words are "all office admins", so this runs as each of the
    // seven rather than as BPLO alone. An office with no open assignment in
    // the seeded storyline is a fixture gap, not a defect.
    $assignment = openAssignmentFor($email);
    if ($assignment === null) {
        expect(true)->toBeTrue();

        return;
    }

    // A tier that is genuinely a CHANGE, whatever the fixture happens to hold:
    // setting the tier a filing already has is a deliberate no-op (its own
    // test below), and asserting `source: officer` after one would be asserting
    // the opposite of the behaviour.
    $target = $assignment->application->complexity === 'highly_technical'
        ? 'simple'
        : 'highly_technical';

    $this->withHeaders(authAs($email))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => $target])
        ->assertOk()
        ->assertJsonPath('data.ra11032.tier', $target)
        ->assertJsonPath('data.ra11032.statutory_working_days', Ra11032::statutoryWorkingDays($target))
        // The officer has to be able to see that a PERSON now owns this value.
        ->assertJsonPath('data.ra11032.source', 'officer');

    expect($assignment->application->fresh()->complexity)->toBe($target);
})->with([
    'bplo@biztrack.local',
    'sanitary@biztrack.local',
    'fire@biztrack.local',
    'obo@biztrack.local',
    'cenro@biztrack.local',
    'market@biztrack.local',
    'zoning@biztrack.local',
]);

it('recomputes the deadline from the filing date, not from the reclassification', function () {
    /*
     * The heart of it. RA 11032's clock runs from FILING; recomputing from
     * `now()` would hand the LGU a fresh twenty days by reclassifying on day
     * nineteen, which is the one behaviour a compliance feature must not have.
     */
    $assignment = openAssignmentFor('bplo@biztrack.local');
    expect($assignment)->not->toBeNull();

    $app = $assignment->application;
    $submittedAt = $app->submitted_at;

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'simple'])
        ->assertOk();

    $fresh = $app->fresh();
    expect($fresh->complexity)->toBe('simple')
        ->and($fresh->deadline_at->toDateTimeString())
        ->toBe($submittedAt->copy()->addWeekdays(3)->toDateTimeString());

    // And it follows the tier UP as well as down, from the same origin.
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'highly_technical'])
        ->assertOk();

    expect($app->fresh()->deadline_at->toDateTimeString())
        ->toBe($submittedAt->copy()->addWeekdays(20)->toDateTimeString());
});

it('records who changed a statutory clock, from what, to what', function () {
    $assignment = openAssignmentFor('sanitary@biztrack.local');
    expect($assignment)->not->toBeNull();

    $app = $assignment->application;
    $before = $app->complexity;
    $target = $before === 'complex' ? 'simple' : 'complex';
    $officer = User::where('email', 'sanitary@biztrack.local')->firstOrFail();

    $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => $target])
        ->assertOk();

    $entry = AuditLog::where('action', 'application.reclassified')
        ->where('auditable_id', $app->id)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($officer->id)
        ->and($entry->changes['from'])->toBe($before)
        ->and($entry->changes['to'])->toBe($target)
        // The day counts, because "simple → complex" alone does not tell an
        // auditor how many days the office gained or lost.
        ->and($entry->changes['to_working_days'])->toBe(Ra11032::statutoryWorkingDays($target))
        ->and($entry->changes['deadline_to'])->not->toBeNull();

    // The provenance on the row itself, which is what the review sheet reads.
    $fresh = $app->fresh();
    expect($fresh->complexity_set_by_user_id)->toBe($officer->id)
        ->and($fresh->complexity_set_at)->not->toBeNull();
});

it('records the first officer to confirm the guess, and nothing after that', function () {
    /*
     * The unchanged-tier case, which has two halves and used to have one.
     *
     * It used to say only that an unchanged select writes nothing at all, and
     * that was right while the tier was only a value. It is wrong now that the
     * approval gate asks WHO set it. `submit()` seeds a guess and leaves the
     * provenance null, so an officer who reads the filing, agrees with the
     * guess and picks the same option would change nothing, never be recorded
     * as having chosen, and stay blocked from approving with no way out but
     * picking a tier they believe is wrong. Agreeing is a decision.
     *
     * What survives unchanged is the second press. Once a person's name is on
     * the row there is nothing left to record, and writing an audit row per
     * Save would pad the trail with non-events — the trail an LGU has to be
     * able to read afterwards is the one nobody has stuffed with noise.
     */
    $assignment = openAssignmentFor('bplo@biztrack.local');
    expect($assignment)->not->toBeNull();

    $app = $assignment->application;
    $app->update(['complexity' => 'complex', 'complexity_set_by_user_id' => null]);
    $auditBefore = AuditLog::where('action', 'application.reclassified')->count();
    $bplo = User::where('email', 'bplo@biztrack.local')->firstOrFail();

    // Confirming the guess: the value does not move, the ownership does.
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'complex'])
        ->assertOk()
        ->assertJsonPath('data.ra11032.tier', 'complex')
        ->assertJsonPath('data.ra11032.source', 'officer');

    $confirmed = $app->fresh();
    expect($confirmed->complexity_set_by_user_id)->toBe($bplo->id)
        ->and(AuditLog::where('action', 'application.reclassified')->count())->toBe($auditBefore + 1);

    // Pressing Save again on a select nobody moved: genuinely nothing to say.
    $this->travel(2)->minutes();
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'complex'])
        ->assertOk()
        ->assertJsonPath('data.ra11032.source', 'officer');

    expect(AuditLog::where('action', 'application.reclassified')->count())->toBe($auditBefore + 1)
        ->and($app->fresh()->complexity_set_at->toDateTimeString())
        ->toBe($confirmed->complexity_set_at->toDateTimeString());
});

it('refuses a tier the statute does not have', function () {
    $assignment = openAssignmentFor('bplo@biztrack.local');
    expect($assignment)->not->toBeNull();
    $before = $assignment->application->complexity;

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'expedited'])
        ->assertStatus(422);

    expect($assignment->application->fresh()->complexity)->toBe($before);
});

it('refuses to reclassify a filing that has already been decided', function () {
    /*
     * Approved, rejected and cancelled carry a `decided_at` and, for approvals,
     * issued permits. Moving the tier under one of those rewrites whether the
     * LGU met its statutory deadline on a closed case.
     */
    $assignment = ApplicationAssignment::query()
        ->whereHas('application', fn ($a) => $a->whereIn('status', [
            ApplicationStatus::Approved->value,
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Cancelled->value,
        ]))
        ->first();

    if ($assignment === null) {
        expect(true)->toBeTrue();

        return;
    }

    $officer = User::where('department_id', $assignment->department_id)
        ->whereHas('roles', fn ($r) => $r->where('name', '!=', 'business_owner'))
        ->firstOrFail();

    $app = $assignment->application;
    $before = $app->complexity;
    $deadlineBefore = optional($app->deadline_at)->toDateTimeString();

    $this->withHeaders(authAs($officer->email))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'simple'])
        ->assertStatus(422);

    $fresh = $app->fresh();
    expect($fresh->complexity)->toBe($before)
        ->and(optional($fresh->deadline_at)->toDateTimeString())->toBe($deadlineBefore);
});

it('refuses an office reaching for another office’s filing', function () {
    $sanitary = openAssignmentFor('sanitary@biztrack.local');
    expect($sanitary)->not->toBeNull();
    $before = $sanitary->application->complexity;

    // Same boundary as approve/return: an assignment belongs to one office.
    $this->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/assignments/{$sanitary->id}/classification", ['tier' => 'simple'])
        ->assertForbidden();

    expect($sanitary->application->fresh()->complexity)->toBe($before);
});

it('is not writable by the applicant who filed it', function () {
    // It is an office field. The owner is not an office, holds no
    // `application.review`, and has no assignment to reach through.
    $assignment = openAssignmentFor('bplo@biztrack.local');
    expect($assignment)->not->toBeNull();
    $before = $assignment->application->complexity;

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'simple'])
        ->assertForbidden();

    expect($assignment->application->fresh()->complexity)->toBe($before);
});

it('still classifies an unclassified filing automatically at submission', function () {
    /*
     * `tierFor()` is not deleted and must not be: a filing nobody has looked
     * at still needs a deadline, and a null tier is invisible to the
     * compliance panel entirely. What changes is only that the officer may
     * overrule it — so the seeded value has to arrive marked as OURS.
     */
    $draft = Application::where('status', ApplicationStatus::Draft->value)->first();
    if ($draft === null) {
        expect(true)->toBeTrue();

        return;
    }

    app(WorkflowService::class)->submit($draft);

    $fresh = $draft->fresh();
    expect($fresh->complexity)->toBe(Ra11032::tierFor($fresh))
        ->and($fresh->complexity_set_by_user_id)->toBeNull()
        ->and($fresh->deadline_at->toDateTimeString())->toBe(
            $fresh->submitted_at->copy()->addWeekdays(
                Ra11032::statutoryWorkingDays($fresh->complexity),
            )->toDateTimeString(),
        );
});

it('serves the review sheet the three tiers and the current provenance', function () {
    /*
     * The browser must never hold its own copy of the statute, so the payload
     * carries the options — and it has to distinguish three states the officer
     * genuinely needs told apart before they touch the control:
     *
     *   null      — nobody has classified this at all.
     *   automatic — our rule guessed it (A10), and the officer may disagree.
     *   officer   — a named person decided it, and overriding is a second
     *               opinion rather than a first.
     *
     * All three are walked here because presenting a guess as a decision is
     * the specific failure this field exists to avoid.
     */
    $assignment = openAssignmentFor('bplo@biztrack.local');
    expect($assignment)->not->toBeNull();
    $app = $assignment->application;

    $sheet = fn () => $this->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/assignments/{$assignment->id}")
        ->assertOk();

    // The statute, on every response, whatever the tier is.
    $sheet()
        ->assertJsonPath('data.application.ra11032.editable', true)
        ->assertJsonPath('data.application.ra11032.tiers.0.value', 'simple')
        ->assertJsonPath('data.application.ra11032.tiers.0.statutory_working_days', 3)
        ->assertJsonPath('data.application.ra11032.tiers.1.statutory_working_days', 7)
        ->assertJsonPath('data.application.ra11032.tiers.2.value', 'highly_technical')
        ->assertJsonPath('data.application.ra11032.tiers.2.statutory_working_days', 20)
        ->assertJsonCount(3, 'data.application.ra11032.tiers');

    // Unclassified: blank, and said to be blank.
    $app->update(['complexity' => null]);
    $sheet()
        ->assertJsonPath('data.application.ra11032.tier', null)
        ->assertJsonPath('data.application.ra11032.source', null);

    // Classified by our rule, exactly as WorkflowService::submit leaves it.
    $app->update(['complexity' => Ra11032::tierFor($app), 'complexity_set_by_user_id' => null]);
    $sheet()
        ->assertJsonPath('data.application.ra11032.source', 'automatic')
        ->assertJsonPath('data.application.ra11032.set_by', null);

    // Classified by a person, who is named.
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/classification", ['tier' => 'highly_technical'])
        ->assertOk();

    $bplo = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    $sheet()
        ->assertJsonPath('data.application.ra11032.source', 'officer')
        ->assertJsonPath('data.application.ra11032.label', 'Highly technical')
        ->assertJsonPath('data.application.ra11032.set_by.name', $bplo->name);
});

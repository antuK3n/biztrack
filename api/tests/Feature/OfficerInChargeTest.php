<?php

use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * Officer in Charge: who holds a filing, and who may take it.
 *
 * The register already carried `application_assignments.officer_user_id` and an
 * admin-only `assign` endpoint, and that was the whole of it. There was no way
 * for an officer to take a case — the column was stamped as a SIDE EFFECT of
 * approving, so the only officer ever recorded was whoever happened to finish
 * the review, and until that moment every case in the office was nobody's.
 *
 * Worse, the column recorded a name without conferring anything. Every action
 * on AssignmentController is guarded by `authorizeDepartment`, which asks only
 * which OFFICE you belong to — so a second officer of the same office could
 * approve, return, re-check and re-classify a review another officer was
 * already holding, and the audit row would name whoever pressed last.
 *
 * The client's rule: the first authorised officer to claim an unassigned
 * filing becomes its OIC; once held, only that officer may act on it, and only
 * the super admin may move it to somebody else.
 *
 * Each office is answered separately, because each office is a separate
 * assignment row: one filing carrying BUSINESS and SANITARY has two, and BPLO
 * claiming its own must leave CHO's untouched.
 */

/** A second officer in an office, so "another officer" is a real account. */
function colleagueIn(string $departmentCode, string $roleName, string $label): User
{
    $user = User::create([
        'name' => $label,
        'first_name' => $label,
        'last_name' => 'Officer',
        'gender' => 'F',
        'email' => strtolower(str_replace(' ', '.', $label)).'.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000002',
        'password' => 'biztrack1',
        'department_id' => Department::where('code', $departmentCode)->value('id'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', $roleName)->pluck('id'));

    return $user->fresh();
}

function actAs(User $user): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);
}

/** BPLO's assignment on a filing. */
function bploAssignmentId(int $applicationId): int
{
    return ApplicationAssignment::where('application_id', $applicationId)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->value('id');
}

/* ── §2 the first officer to claim becomes the OIC ───────────────────────── */

it('hands an unclaimed filing to the first officer who claims it', function () {
    $appId = scopedAssignmentFiling('OIC First Claim Cafe');
    $id = choAssignmentId($appId);

    // Nobody holds it yet, and the payload says so rather than staying silent.
    $before = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/assignments/{$id}")->assertOk()->json('data');
    expect($before['officer'])->toBeNull();

    $claimed = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")
        ->assertOk()->json('data');

    $carlos = User::where('email', 'sanitary@biztrack.local')->first();
    expect($claimed['officer']['id'])->toBe($carlos->id)
        // The date the admin's OIC page has to print. It was never written:
        // `assignOfficer` set the officer and left `assigned_at` null.
        ->and($claimed['assigned_at'])->not->toBeNull();
});

it('refuses a second officer the filing the first one took', function () {
    $appId = scopedAssignmentFiling('OIC Race Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $second = colleagueIn('CHO', 'sanitary_officer', 'Second Health');
    actAs($second);
    test()->postJson("/api/v1/assignments/{$id}/claim")->assertStatus(409);

    // And the refusal changed nothing — the first officer still holds it.
    $carlos = User::where('email', 'sanitary@biztrack.local')->first();
    expect(ApplicationAssignment::find($id)->officer_user_id)->toBe($carlos->id);
});

it('lets the officer who holds it claim again without complaint', function () {
    // Idempotent on purpose: a double-click, or a stale tab, must not read as
    // somebody stealing the case from themselves.
    $appId = scopedAssignmentFiling('OIC Double Click Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();
});

it('refuses a claim from an officer of another office', function () {
    $appId = scopedAssignmentFiling('OIC Wrong Office Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertForbidden();

    expect(ApplicationAssignment::find($id)->officer_user_id)->toBeNull();
});

/* ── §3 / §11 holding it is what confers the right to act ────────────────── */

it('refuses every review action to an officer who is not the one holding it', function (string $action, array $body) {
    $appId = scopedAssignmentFiling('OIC Exclusive Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $second = colleagueIn('CHO', 'sanitary_officer', 'Other Health');
    actAs($second);

    test()->postJson("/api/v1/assignments/{$id}/{$action}", $body)->assertForbidden();
})->with([
    'approve' => ['approve', []],
    'return' => ['return', ['remarks' => 'Send the potability result.']],
    'checks' => ['checks', ['label' => 'Water potability', 'is_checked' => true]],
]);

it('lets the officer holding it do the work', function () {
    $appId = scopedAssignmentFiling('OIC Holder Works Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/return", ['remarks' => 'Send the potability result.'])
        ->assertOk();
});

it('still lets an unclaimed filing be worked, and records who worked it', function () {
    /*
     * Not every office wants a claim step, and an office of one should not have
     * to press a button to be allowed to do its job. Acting on an UNHELD
     * assignment therefore claims it — the same rule approve() has always had,
     * kept deliberately rather than replaced by a hard "claim first".
     */
    $appId = scopedAssignmentFiling('OIC Implicit Claim Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/return", ['remarks' => 'Send the potability result.'])
        ->assertOk();

    $carlos = User::where('email', 'sanitary@biztrack.local')->first();
    $row = ApplicationAssignment::find($id);
    expect($row->officer_user_id)->toBe($carlos->id)
        ->and($row->assigned_at)->not->toBeNull();
});

/* ── §9 the offices are independent ──────────────────────────────────────── */

it('leaves every other office’s holder untouched when one office claims', function () {
    $appId = scopedAssignmentFiling('OIC Independent Cafe');
    $cho = choAssignmentId($appId);
    $bplo = bploAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$cho}/claim")->assertOk();

    expect(ApplicationAssignment::find($bplo)->officer_user_id)
        ->not->toBe(User::where('email', 'sanitary@biztrack.local')->value('id'));

    // And BPLO can hold the same filing under its own office at the same time.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$bplo}/claim")->assertOk();

    $liza = User::where('email', 'bplo@biztrack.local')->value('id');
    $carlos = User::where('email', 'sanitary@biztrack.local')->value('id');
    expect(ApplicationAssignment::find($bplo)->officer_user_id)->toBe($liza)
        ->and(ApplicationAssignment::find($cho)->officer_user_id)->toBe($carlos);
});

/* ── §10 the Track page's three questions ────────────────────────────────── */

it('narrows the queue to unassigned, mine, and somebody else’s', function () {
    $mine = scopedAssignmentFiling('OIC Mine Cafe');
    $theirs = scopedAssignmentFiling('OIC Theirs Cafe');
    $free = scopedAssignmentFiling('OIC Free Cafe');

    $mineId = choAssignmentId($mine);
    $theirsId = choAssignmentId($theirs);
    $freeId = choAssignmentId($free);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$mineId}/claim")->assertOk();

    $second = colleagueIn('CHO', 'sanitary_officer', 'Colleague Health');
    actAs($second);
    test()->postJson("/api/v1/assignments/{$theirsId}/claim")->assertOk();

    $ids = function (string $narrow) {
        return collect(test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson("/api/v1/assignments?oic={$narrow}&per_page=200")
            ->assertOk()->json('data'))->pluck('id');
    };

    expect($ids('unassigned'))->toContain($freeId)->not->toContain($mineId, $theirsId);
    expect($ids('mine'))->toContain($mineId)->not->toContain($freeId, $theirsId);
    expect($ids('others'))->toContain($theirsId)->not->toContain($mineId, $freeId);

    // An unknown narrowing is refused rather than quietly ignored, which would
    // hand the reader the whole queue under a heading that says otherwise.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/assignments?oic=everything')->assertStatus(422);
});

it('tells an officer which rows are theirs to act on', function () {
    // The screen needs this: a row it must render read-only looks exactly like
    // one it may open, and deciding that in the browser by comparing ids is a
    // rule in two places that will drift.
    $appId = scopedAssignmentFiling('OIC Can Act Cafe');
    $id = choAssignmentId($appId);

    $free = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/assignments/{$id}")->assertOk()->json('data');
    expect($free['can_claim'])->toBeTrue()->and($free['can_act'])->toBeTrue();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $second = colleagueIn('CHO', 'sanitary_officer', 'Readonly Health');
    actAs($second);
    $held = test()->getJson("/api/v1/assignments/{$id}")->assertOk()->json('data');

    expect($held['can_claim'])->toBeFalse()
        ->and($held['can_act'])->toBeFalse()
        // Still readable, and it names who holds it — §5: the other officers
        // must be able to see "Assigned to: Officer A".
        ->and($held['officer']['name'])->not->toBeNull();
});

/* ── §4 / §8 the applicant is told who is handling their filing ──────────── */

it('shows the business owner the officer in charge, office by office', function () {
    $appId = scopedAssignmentFiling('OIC Owner Sees Cafe');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/assignments/'.choAssignmentId($appId).'/claim')->assertOk();
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson('/api/v1/assignments/'.bploAssignmentId($appId).'/claim')->assertOk();

    /*
     * Read from `assignments`, which the applicant's payload already carries,
     * rather than from a second field built for this screen. The client's §8 is
     * that there is ONE source of truth for who holds a filing; answering the
     * owner's page from a parallel list would be two, and they would disagree
     * the first time one of them was updated alone.
     */
    $offices = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")->assertOk()->json('data.assignments'));

    expect($offices)->toHaveCount(2);

    $cho = $offices->firstWhere('department.code', 'CHO');
    $bplo = $offices->firstWhere('department.code', 'BPLO');

    expect($cho['officer']['name'])->toBe('Carlos Dizon')
        ->and($bplo['officer']['name'])->toBe('Liza Reyes')
        ->and($cho['assigned_at'])->not->toBeNull();
});

it('says nobody is handling it rather than leaving the applicant guessing', function () {
    $appId = scopedAssignmentFiling('OIC Owner Unassigned Cafe');

    $offices = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")->assertOk()->json('data.assignments'));

    expect($offices)->not->toBeEmpty();
    expect($offices->firstWhere('department.code', 'CHO')['officer'])->toBeNull();
});

it('keeps one applicant out of another’s handling list', function () {
    $appId = scopedAssignmentFiling('OIC Not Yours Cafe');

    test()->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")->assertForbidden();
});

/* ── §6 / §7 the super admin's OIC register ──────────────────────────────── */

it('gives the super admin every assignment with its holder', function () {
    $appId = scopedAssignmentFiling('OIC Admin Register Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $rows = collect(test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/oic-assignments?per_page=200')->assertOk()->json('data'));

    $row = $rows->firstWhere('id', $id);

    // Every column the client's OIC Management page lists.
    expect($row)->not->toBeNull()
        ->and($row['business']['name'])->not->toBeNull()
        ->and($row['tracking_id'])->not->toBeNull()
        ->and($row['office']['code'])->toBe('CHO')
        ->and($row['officer']['name'])->toBe('Carlos Dizon')
        ->and($row['assigned_at'])->not->toBeNull()
        ->and($row['status_label'])->not->toBeNull();
});

it('closes the OIC register to everyone but the super admin', function () {
    foreach (['bplo@biztrack.local', 'sanitary@biztrack.local', 'owner@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))
            ->getJson('/api/v1/admin/oic-assignments')->assertForbidden();
    }
});

it('offers the super admin only the officers of that assignment’s own office', function () {
    $appId = scopedAssignmentFiling('OIC Candidates Cafe');
    $id = choAssignmentId($appId);
    colleagueIn('CHO', 'sanitary_officer', 'Candidate Health');

    $candidates = collect(test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/admin/oic-assignments/{$id}/candidates")->assertOk()->json('data'));

    expect($candidates)->not->toBeEmpty();
    // Nobody from another office is offered — the officer must belong to the
    // office whose review this is, which `assign` already enforces at 422.
    $choId = Department::where('code', 'CHO')->value('id');
    foreach ($candidates as $candidate) {
        expect($candidate['department_id'])->toBe($choId);
    }
});

it('moves a filing from one officer to another when the super admin says so', function () {
    $appId = scopedAssignmentFiling('OIC Reassign Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $successor = colleagueIn('CHO', 'sanitary_officer', 'Successor Health');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/assign", [
            'officer_user_id' => $successor->id,
            'reason' => 'Carlos is on leave.',
        ])->assertOk();

    // §8: one source of truth. The same fact, read from all four doors.
    expect(ApplicationAssignment::find($id)->officer_user_id)->toBe($successor->id);

    $adminRow = collect(test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/oic-assignments?per_page=200')->json('data'))->firstWhere('id', $id);
    expect($adminRow['officer']['id'])->toBe($successor->id);

    $ownerOffices = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}")->json('data.assignments'));
    expect($ownerOffices->firstWhere('department.code', 'CHO')['officer']['id'])->toBe($successor->id);

    // The officer who lost it can no longer act, and the one who gained it can.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/return", ['remarks' => 'Still mine?'])
        ->assertForbidden();

    actAs($successor);
    test()->postJson("/api/v1/assignments/{$id}/return", ['remarks' => 'Send the potability result.'])
        ->assertOk();
});

it('refuses an officer the power to hand their own case to a colleague', function () {
    $appId = scopedAssignmentFiling('OIC No Self Reassign Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $colleague = colleagueIn('CHO', 'sanitary_officer', 'Wants It Health');

    // Not theirs to give away — `oic.assign` is the super admin's alone.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/assign", ['officer_user_id' => $colleague->id])
        ->assertForbidden();
});

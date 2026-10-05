<?php

use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\CaseHolder;

/*
 * Assign to Me comes first — outside BPLO.
 *
 * Request of 6 October 2026, for every office except BPLO (CHO, BFP, OBO,
 * CENRO, CPDO): "The officer must click 'Assign to Me' before they can access
 * and process the application. Once assigned, other officers can only view
 * the application." And of the conversation: when a case is unassigned and
 * taken by somebody else, "only the newly assigned officer can access the
 * conversation".
 *
 * Until that day an unheld case was open to the whole office and acting on it
 * claimed it — the rule BPLO keeps, because the request named the others.
 * `App\Support\CaseHolder` is the one place the rule lives; these cases ask
 * it through the doors an officer actually uses, City Health standing for the
 * five offices, and pin BPLO's exception beside it so a later widening is a
 * decision rather than an accident.
 *
 * Many other suites now claim before they act (`claimAs` in Pest.php); this
 * file is where the claim itself is the subject.
 */

/** A second account in an office, holding the same role as its seeded officer. */
function claimColleague(string $officeCode, string $role): User
{
    $user = User::create([
        'name' => "Colleague {$officeCode}",
        'first_name' => 'Colleague',
        'last_name' => $officeCode,
        'gender' => 'F',
        'email' => 'colleague.'.strtolower($officeCode).'.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000077',
        'password' => 'biztrack1',
        'department_id' => Department::where('code', $officeCode)->value('id'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', $role)->pluck('id'));

    return $user->fresh();
}

/** This officer's queue row for an assignment — readable whether or not it is held. */
function claimQueueRow(string $email, int $assignmentId): ?array
{
    return collect(test()->withHeaders(authAs($email))
        ->getJson('/api/v1/assignments?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $assignmentId);
}

/* ── The case itself ─────────────────────────────────────────────────────── */

it('refuses to open or work a case outside BPLO until an officer there claims it', function () {
    $appId = scopedAssignmentFiling('Claim First Cafe');
    $id = choAssignmentId($appId);

    // The queue offers it, with Assign to Me and nothing else.
    $row = claimQueueRow('sanitary@biztrack.local', $id);
    expect($row)->not->toBeNull()
        ->and($row['officer'])->toBeNull()
        ->and($row['can_claim'])->toBeTrue()
        ->and($row['can_act'])->toBeFalse();

    // The case does not open…
    test()->getJson("/api/v1/assignments/{$id}")
        ->assertForbidden()
        ->assertJsonPath('message', CaseHolder::UNCLAIMED);

    // …and is not worked, nor claimed by the attempt — which is what acting
    // on it did until 6 October 2026.
    test()->postJson("/api/v1/assignments/{$id}/approve", ['remarks' => 'Cleared.'])
        ->assertForbidden()
        ->assertJsonPath('message', CaseHolder::UNCLAIMED);
    expect(ApplicationAssignment::find($id)->officer_user_id)->toBeNull();
});

it('lets the officer who claimed a case outside BPLO open it and approve it', function () {
    $appId = scopedAssignmentFiling('Claimed Then Worked Cafe');
    $id = choAssignmentId($appId);

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $case = test()->getJson("/api/v1/assignments/{$id}")->assertOk()->json('data');
    expect($case['can_act'])->toBeTrue()
        ->and($case['officer']['id'])->toBe(User::where('email', 'sanitary@biztrack.local')->value('id'));

    test()->postJson("/api/v1/assignments/{$id}/approve", ['remarks' => 'Cleared.'])->assertOk();
});

it('lets a colleague read a case another officer holds, and work none of it', function () {
    $appId = scopedAssignmentFiling('Colleague Reads Cafe');
    $id = choAssignmentId($appId);
    $colleague = claimColleague('CHO', 'sanitary_officer');

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    // "Once assigned, other officers can only view the application."
    $seen = test()->withHeaders(authAs($colleague->email))
        ->getJson("/api/v1/assignments/{$id}")->assertOk()->json('data');
    expect($seen['can_act'])->toBeFalse()
        ->and($seen['can_claim'])->toBeFalse()
        ->and($seen['officer']['name'])->not->toBeNull();

    test()->postJson("/api/v1/assignments/{$id}/approve", ['remarks' => 'Cleared.'])
        ->assertForbidden()
        ->assertJsonPath('message', CaseHolder::HELD_ELSEWHERE);
});

it('still lets a BPLO officer open and work an unheld BPLO case, claiming it by acting', function () {
    // BPLO is the office the request did not name. A filing BPLO is still
    // reading (For Approval), so its own review is the one in front of it.
    $app = filingWithoutTin('123-456-789-000');
    $id = ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->value('id');
    expect(ApplicationAssignment::find($id)->officer_user_id)->toBeNull();

    $case = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/assignments/{$id}")->assertOk()->json('data');
    expect($case['can_act'])->toBeTrue();

    test()->postJson("/api/v1/assignments/{$id}/return", ['remarks' => 'Send the lease contract.'])
        ->assertOk();

    expect(ApplicationAssignment::find($id)->officer_user_id)
        ->toBe(User::where('email', 'bplo@biztrack.local')->value('id'));
});

/* ── The conversation ────────────────────────────────────────────────────── */

it('hands the whole conversation to the officer who takes a case over outside BPLO, and none to the one who handed it on', function () {
    $appId = scopedAssignmentFiling('Conversation Handover Cafe');
    $id = choAssignmentId($appId);
    $cho = Department::where('code', 'CHO')->value('id');
    $b = claimColleague('CHO', 'sanitary_officer');

    // Officer A holds it and writes; the applicant answers.
    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$id}/claim")->assertOk();
    test()->postJson("/api/v1/applications/{$appId}/messages", [
        'body' => 'A: please send the water potability result.', 'department_id' => $cho,
    ])->assertCreated();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/messages", [
            'body' => 'Owner: attached.', 'department_id' => $cho,
        ])->assertCreated();

    // While A holds it, B in the same office reads none of it.
    test()->withHeaders(authAs($b->email))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertForbidden();

    // A releases; B takes it.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/release")->assertOk();
    test()->withHeaders(authAs($b->email))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    // B sees what was said before them — all of it, not a fresh stretch.
    $bodies = collect(test()->getJson("/api/v1/applications/{$appId}/messages")->assertOk()->json('data'))
        ->pluck('body');
    expect($bodies)
        ->toContain('A: please send the water potability result.')
        ->toContain('Owner: attached.');
    $bRows = collect(test()->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'));
    expect($bRows->firstWhere('application_id', $appId))->not->toBeNull();

    // A keeps nothing: the conversation is refused and the row is gone.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/applications/{$appId}/messages")->assertForbidden();
    $aRows = collect(test()->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'));
    expect($aRows->firstWhere('application_id', $appId))->toBeNull();
});

it('shows an office’s general enquiry to every officer in it, holding a case or not', function () {
    // An enquiry has no filing, so nobody holds it; the request of 6 October
    // 2026 is about cases and leaves these with the whole office.
    $b = claimColleague('CHO', 'sanitary_officer');
    $cho = Department::where('code', 'CHO')->value('id');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/general-messages', ['department_id' => $cho, 'body' => 'A question for City Health, no filing.'])
        ->assertCreated();

    foreach (['sanitary@biztrack.local', $b->email] as $email) {
        $row = collect(test()->withHeaders(authAs($email))
            ->getJson('/api/v1/message-threads?per_page=200')->assertOk()->json('data'))
            ->where('kind', 'general')
            ->firstWhere('last_message.body', 'A question for City Health, no filing.');

        expect($row)->not->toBeNull();
    }
});

<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\CaseHolder;
use Illuminate\Support\Facades\DB;
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
 * And since the request of 6 October 2026, for every office but BPLO, the
 * claim comes FIRST: "The officer must click 'Assign to Me' before they can
 * access and process the application." An unheld CHO case is neither opened
 * nor worked (`App\Support\CaseHolder::UNCLAIMED`); BPLO keeps the older
 * rule that acting on an unheld case claims it. ClaimBeforeWorkTest pins the
 * rule door by door; the cases here that changed say so where they stand.
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

    /*
     * Nobody holds it yet, and the queue row says so rather than staying
     * silent. The queue, because since the request of 6 October 2026 the case
     * itself cannot be opened until it is claimed — that refusal is the other
     * half of what is asserted here; the row read the review sheet until then.
     */
    $before = collect(test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/assignments?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $id);
    expect($before)->not->toBeNull()
        ->and($before['officer'])->toBeNull();
    test()->getJson("/api/v1/assignments/{$id}")
        ->assertForbidden()
        ->assertJsonPath('message', CaseHolder::UNCLAIMED);

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

it('still lets an unclaimed BPLO filing be worked, and records who worked it', function () {
    /*
     * An office of one should not have to press a button to be allowed to do
     * its job, so acting on an UNHELD assignment claims it — the rule approve()
     * has always had. Since the request of 6 October 2026 that is BPLO's
     * alone, the office the request did not name; this case ran on City Health
     * until then (the case below is City Health now).
     *
     * On a filing BPLO is still reading (For Approval), because BPLO's return
     * is of the main form and the paid fixture the City Health cases use is
     * past it.
     */
    $appId = filingWithoutTin('123-456-789-000')->id;
    $id = bploAssignmentId($appId);
    expect(ApplicationAssignment::find($id)->officer_user_id)->toBeNull();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/return", ['remarks' => 'Send the lease contract.'])
        ->assertOk();

    $liza = User::where('email', 'bplo@biztrack.local')->first();
    $row = ApplicationAssignment::find($id);
    expect($row->officer_user_id)->toBe($liza->id)
        ->and($row->assigned_at)->not->toBeNull();
});

it('refuses work on an unclaimed filing outside BPLO until an officer claims it', function () {
    /*
     * Request of 6 October 2026: "The officer must click 'Assign to Me'
     * before they can access and process the application." Until then this
     * return claimed the case on the way past (the case above, on City Health).
     */
    $appId = scopedAssignmentFiling('OIC Claim First Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/return", ['remarks' => 'Send the potability result.'])
        ->assertForbidden()
        ->assertJsonPath('message', CaseHolder::UNCLAIMED);

    // Refused, and nothing was claimed by the attempt.
    expect(ApplicationAssignment::find($id)->officer_user_id)->toBeNull();
});

/* ── Giving it back ──────────────────────────────────────────────────────── */

/*
 * An officer who has taken a case must be able to put it down.
 *
 * Claiming is one click and, until now, irreversible without the super admin:
 * a mis-click, a case that turns out to belong to a colleague's area, or an
 * officer going on leave all needed an admin to unpick. That makes the cheap
 * action expensive to undo, which is the shape that stops people using it —
 * and an office whose officers will not claim is an office back where it
 * started, with every case belonging to nobody.
 *
 * Released to the office pool rather than handed to a named person: choosing
 * somebody else's workload for them is the super admin's call (`oic.assign`),
 * and an unheld case is the ordinary state a filing starts in.
 */
it('lets the officer holding a filing give it back to the office', function () {
    $appId = scopedAssignmentFiling('OIC Release Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $released = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/release")
        ->assertOk()->json('data');

    expect($released['officer'])->toBeNull()
        ->and($released['assigned_at'])->toBeNull()
        ->and($released['can_claim'])->toBeTrue();

    // And it is back in the office's unassigned list, for anyone to take.
    $free = collect(test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/assignments?oic=unassigned&per_page=200')->assertOk()->json('data'))
        ->pluck('id');
    expect($free)->toContain($id);
});

it('refuses to let one officer put down another officer’s filing', function () {
    $appId = scopedAssignmentFiling('OIC Release Theirs Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    $second = colleagueIn('CHO', 'sanitary_officer', 'Releaser Health');
    actAs($second);
    test()->postJson("/api/v1/assignments/{$id}/release")->assertForbidden();

    $carlos = User::where('email', 'sanitary@biztrack.local')->value('id');
    expect(ApplicationAssignment::find($id)->officer_user_id)->toBe($carlos);
});

it('says plainly that an unheld filing was already unheld', function () {
    // Not an error. Two tabs, or a release the admin got to first, and the
    // officer's intent — "this should not be mine" — is already true.
    $appId = scopedAssignmentFiling('OIC Release Twice Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/release")->assertOk();

    expect(ApplicationAssignment::find($id)->officer_user_id)->toBeNull();
});

it('refuses a release from an officer of another office', function () {
    $appId = scopedAssignmentFiling('OIC Release Outsider Cafe');
    $id = choAssignmentId($appId);

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    test()->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/release")->assertForbidden();
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

    /*
     * Unheld, the row offers Assign to Me and nothing else: `can_act` is false
     * outside BPLO since the request of 6 October 2026 (it was true until
     * then, when acting claimed the case). Read off the queue, because the
     * case itself does not open until it is claimed.
     */
    $free = collect(test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/assignments?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $id);
    expect($free['can_claim'])->toBeTrue()->and($free['can_act'])->toBeFalse();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/assignments/{$id}/claim")->assertOk();

    // Held, it is the holder's to work.
    $mine = test()->getJson("/api/v1/assignments/{$id}")->assertOk()->json('data');
    expect($mine['can_claim'])->toBeFalse()->and($mine['can_act'])->toBeTrue();

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

/*
 * ── Releasing from the Officer in Charge screen ───────────────────────────
 *
 * `POST /assignments/{id}/assign` could only NAME a successor, so the one act
 * the caseload screen could perform and this one could not was putting a
 * filing back in its office's pool. An office losing its only officer had
 * nowhere to release the work from — and `release` refuses another officer's
 * case with the words "Only the system administrator can move it", a promise
 * that endpoint did not keep.
 *
 * `officer_user_id: null` is that act, and it is the same meaning
 * `reassign-caseload` has always given `to_user_id: null`.
 */

it('releases a filing to the office queue when no officer is named', function () {
    $assignment = ApplicationAssignment::query()
        ->whereNotNull('officer_user_id')
        ->firstOr(function () {
            $a = ApplicationAssignment::firstOrFail();
            $officer = User::where('department_id', $a->department_id)->firstOrFail();
            $a->forceFill(['officer_user_id' => $officer->id, 'assigned_at' => now()])->save();

            return $a->fresh();
        });

    expect($assignment->officer_user_id)->not->toBeNull();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", [
            'officer_user_id' => null,
            'reason' => 'Officer left the office.',
        ])
        ->assertOk()
        // The same shape the naming branch answers with, so a caller cannot
        // tell which branch ran except by reading `officer`.
        ->assertJsonPath('data.officer', null);

    $assignment->refresh();
    expect($assignment->officer_user_id)->toBeNull();
    /*
     * `assigned_at` goes with the holder. It records when THIS officer took
     * it, so leaving it behind would date a claim nobody has made — and the
     * office queue orders by longest-waiting.
     */
    expect($assignment->assigned_at)->toBeNull();
});

it('records a release as a release, not as a reassignment', function () {
    /*
     * Its own audit action. An auditor reading the trail should not have to
     * inspect the payload to tell a handover from a release.
     */
    $assignment = ApplicationAssignment::firstOrFail();
    $officer = User::where('department_id', $assignment->department_id)->firstOrFail();
    $assignment->forceFill(['officer_user_id' => $officer->id, 'assigned_at' => now()])->save();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", ['officer_user_id' => null])
        ->assertOk();

    expect(
        DB::table('audit_logs')
            ->where('action', 'assignment.released')
            ->where('auditable_id', $assignment->id)
            ->exists()
    )->toBeTrue();
});

it('treats releasing an already-free filing as nothing to do', function () {
    // The reader asked for a state the filing is already in. Saying "no" to
    // that would be pedantry, not a guard.
    $assignment = ApplicationAssignment::firstOrFail();
    $assignment->forceFill(['officer_user_id' => null, 'assigned_at' => null])->save();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", ['officer_user_id' => null])
        ->assertOk();

    expect($assignment->fresh()->officer_user_id)->toBeNull();
});

it('still refuses a request that forgets to say who holds it', function () {
    /*
     * `present`, not `sometimes`. A caller that simply omitted the field would
     * otherwise release a filing silently, which is the one mistake this field
     * can make.
     */
    $assignment = ApplicationAssignment::firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", ['reason' => 'no officer named'])
        ->assertStatus(422);
});

it('will not let an office release another office’s filing', function () {
    /*
     * The boundary is unchanged by the new branch. `authorizeOicReassignment`
     * exempts only a reader with no department — the super admin — and an
     * office reviewer is still held to its own.
     */
    $cenro = User::where('email', 'cenro@biztrack.local')->firstOrFail();

    $foreign = ApplicationAssignment::query()
        ->where('department_id', '!=', $cenro->department_id)
        ->firstOrFail();

    test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson("/api/v1/assignments/{$foreign->id}/assign", ['officer_user_id' => null])
        ->assertForbidden();
});

/*
 * ── The register and the officer directory must reconcile ─────────────────
 *
 * They count different things and both are right: the register counts every
 * assignment a name is on, finished ones included, because it is the record of
 * who did what; the directory's Holding column counts only work that can still
 * be moved. On the live register that read 34 against 2 with nothing on either
 * screen explaining the gap — the kind of disagreement nobody reports as a
 * bug, they just stop trusting both numbers.
 *
 * `meta.open` and `meta.open_assigned` are the chain between them, and the
 * second must equal what the Holding column adds up to. These tests are that
 * equality.
 */

it('states how many assignments are still open, and how many of those are held', function () {
    $meta = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/oic-assignments?per_page=5')
        ->assertOk()
        ->json('meta');

    expect($meta)->toHaveKey('open');
    expect($meta)->toHaveKey('open_assigned');

    // The chain has to narrow, or it explains nothing.
    expect($meta['open'])->toBeLessThanOrEqual($meta['total']);
    expect($meta['open_assigned'])->toBeLessThanOrEqual($meta['open']);
});

it('reconciles exactly with the Holding column on the officer directory', function () {
    $headers = authAs('admin@biztrack.local');

    $meta = test()->withHeaders($headers)
        ->getJson('/api/v1/admin/oic-assignments?per_page=5')
        ->json('meta');

    $holding = collect(
        test()->withHeaders($headers)
            ->getJson('/api/v1/admin/users?per_page=100&staff=1')
            ->json('data')
    )->sum(fn ($u) => ($u['open_reviews'] ?? 0) + ($u['open_inspections'] ?? 0));

    /*
     * Reviews only. `open_assigned` counts held ASSIGNMENTS; the directory's
     * total adds inspections to them, which live in another table and are not
     * on this register at all. Compared against the review half so the two
     * figures describe the same rows.
     */
    $inspections = collect(
        test()->withHeaders($headers)
            ->getJson('/api/v1/admin/users?per_page=100&staff=1')
            ->json('data')
    )->sum(fn ($u) => $u['open_inspections'] ?? 0);

    expect($meta['open_assigned'])->toBe($holding - $inspections);
});

it('narrows the register to still-open work', function () {
    $headers = authAs('admin@biztrack.local');

    $all = test()->withHeaders($headers)->getJson('/api/v1/admin/oic-assignments?per_page=200')->json();
    $open = test()->withHeaders($headers)->getJson('/api/v1/admin/oic-assignments?per_page=200&state=open')->json();

    expect($open['meta']['total'])->toBe($all['meta']['open']);

    /*
     * Every row it returned is on a filing that has not been decided — asked
     * of the ROW, because `approved` stopped meaning finished on 4 October
     * 2026 and a gathering filing wears it while five offices are still at
     * work. See `Application::isDecided()`.
     */
    foreach ($open['data'] as $row) {
        $application = Application::findOrFail($row['application_id']);
        expect($application->isDecided())->toBeFalse("{$row['tracking_id']} is decided and should not be here");
    }
});

it('narrows the register to finished work, and the two halves make the whole', function () {
    $headers = authAs('admin@biztrack.local');

    $all = test()->withHeaders($headers)->getJson('/api/v1/admin/oic-assignments?per_page=200')->json('meta.total');
    $open = test()->withHeaders($headers)->getJson('/api/v1/admin/oic-assignments?per_page=200&state=open')->json('meta.total');
    $done = test()->withHeaders($headers)->getJson('/api/v1/admin/oic-assignments?per_page=200&state=finished')->json('meta.total');

    // Nothing is in both and nothing is in neither — or the filter is lying
    // about one of the two.
    expect($open + $done)->toBe($all);
});

it('refuses a state it does not know', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/oic-assignments?state=halfway')
        ->assertStatus(422);
});

it('keeps the reconciling figures steady while a filter moves', function () {
    /*
     * They are counted over the WHOLE register, ignoring the page and every
     * filter. A reconciliation that moved when a filter did would reconcile
     * nothing — the reader would be comparing a filtered figure against the
     * directory's register-wide one.
     */
    $headers = authAs('admin@biztrack.local');

    $meta = fn (string $qs = '') => test()->withHeaders($headers)
        ->getJson('/api/v1/admin/oic-assignments?per_page=5'.$qs)
        ->json('meta');

    $plain = $meta();
    $taken = $meta('&holder=assigned');
    $free = $meta('&holder=unassigned');

    /*
     * Steady across BOTH halves of the filter, rather than across one of them.
     *
     * The first version narrowed by `holder=unassigned` and asserted the total
     * had dropped — which is true of the live register and false of the test
     * one, where every assignment is unheld. Asserting on the pair instead
     * makes the claim without depending on which half happens to be empty.
     */
    foreach ([$taken, $free] as $narrowed) {
        expect($narrowed['open'])->toBe($plain['open']);
        expect($narrowed['open_assigned'])->toBe($plain['open_assigned']);
    }

    // And `total` — the figure that SHOULD follow the filter — does.
    expect($taken['total'] + $free['total'])->toBe($plain['total']);
});

/*
 * ── A decided filing has no officer in charge to change ───────────────────
 *
 * Client, 27 September 2026: *"yung finished bawal na mareassign kasi tapos na
 * na."* The screen was the only thing saying so — the endpoint would rewrite
 * the name on a filing approved months ago, which is not a reassignment but an
 * edit to the record of who did the work.
 */

it('refuses to reassign an assignment whose filing has been decided', function () {
    $assignment = ApplicationAssignment::firstOrFail();
    $officer = User::where('department_id', $assignment->department_id)->firstOrFail();

    $assignment->application->forceFill(['status' => ApplicationStatus::Approved->value, 'decided_at' => now()])->save();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", [
            'officer_user_id' => $officer->id,
            'reason' => 'Should not be possible.',
        ])
        ->assertStatus(422);

    // And the name on the record is untouched.
    expect($assignment->fresh()->officer_user_id)->toBe($assignment->officer_user_id);
});

it('refuses to release a decided filing back to the queue', function () {
    // The other direction of the same act. Releasing a finished filing would
    // erase who handled it just as surely as renaming them.
    $assignment = ApplicationAssignment::firstOrFail();
    $officer = User::where('department_id', $assignment->department_id)->firstOrFail();
    $assignment->forceFill(['officer_user_id' => $officer->id, 'assigned_at' => now()])->save();
    $assignment->application->forceFill(['status' => ApplicationStatus::Approved->value, 'decided_at' => now()])->save();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", ['officer_user_id' => null])
        ->assertStatus(422);

    expect($assignment->fresh()->officer_user_id)->toBe($officer->id);
});

it('still allows a reassignment while the filing is live', function () {
    // The guard must not close the door it was put there to keep open.
    $assignment = ApplicationAssignment::firstOrFail();
    $officer = User::where('department_id', $assignment->department_id)->firstOrFail();

    $assignment->application->forceFill(['status' => ApplicationStatus::ForApproval->value])->save();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", [
            'officer_user_id' => $officer->id,
        ])
        ->assertOk();

    expect($assignment->fresh()->officer_user_id)->toBe($officer->id);
});

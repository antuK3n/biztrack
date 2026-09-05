<?php

use App\Enums\AssignmentStatus;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Inspection;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\Role;
use App\Models\User;

/*
 * Manage Officer-in-Charge, on the super admin's Officer Assignment screen.
 *
 * Three things on that screen did not do what the screen said they did:
 *
 *  - "Add Officer" could not create anybody. The form posts `role` (the shape
 *    the published TS contract declares); the endpoint validated `roles`. The
 *    422 that came back was keyed `roles`, and the modal renders field errors
 *    under `role` — so the one message explaining the failure was addressed to
 *    a field name nothing on screen was reading. The admin filled the form,
 *    pressed Create account, and watched the button re-enable in silence.
 *
 *  - "Reassign" moved nothing. It collected a scope, a target and a reason and
 *    showed a green "✓ Reassignment recorded"; the caseload stayed exactly
 *    where it was.
 *
 *  - Deactivating an officer left every open review and scheduled inspection
 *    bearing the name of somebody who can no longer sign in, in no queue and
 *    flagged nowhere.
 */

/** An officer in a named office, with the role that office actually uses. */
function officerIn(string $code, string $role, string $email): User
{
    $user = User::create([
        'name' => "Test {$code}",
        'first_name' => 'Test',
        'last_name' => strtoupper($code),
        'gender' => 'M',
        'email' => $email,
        'mobile_number' => '09170000000',
        'password' => 'biztrack1',
        'department_id' => Department::where('code', $code)->value('id'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', $role)->pluck('id'));

    return $user;
}

/** A submitted filing routed to one office, with that office's officer named on it. */
function assignmentHeldBy(User $officer, string $registrationNumber): ApplicationAssignment
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Caseload Cafe',
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Caseload St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $assignment = ApplicationAssignment::firstOrCreate(
        ['application_id' => $appId, 'department_id' => $officer->department_id],
        ['officer_user_id' => $officer->id],
    );
    // firstOrCreate only sets the officer on the CREATE branch, and submit()
    // may have routed this office already — name them either way.
    $assignment->update(['officer_user_id' => $officer->id]);

    return $assignment;
}

/* ── Add Officer ─────────────────────────────────────────────────────────── */

it('creates an officer from the payload the form actually sends', function () {
    // `role`, singular — exactly what web/src/lib/types.ts AdminUserPayload
    // declares and what CreateOfficerModal posts. This used to 422.
    $created = test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', [
            'first_name' => 'Herminia',
            'last_name' => 'Alcantara',
            'gender' => 'F',
            'email' => 'new.zoning@biztrack.local',
            'mobile_number' => '09171234567',
            'password' => 'biztrack1',
            'role' => 'zoning_officer',
            'department_id' => Department::where('code', 'CPDO')->value('id'),
        ])->assertCreated()->json('data');

    expect($created['roles'])->toBe(['zoning_officer'])
        ->and($created['department']['code'])->toBe('CPDO')
        ->and($created['is_active'])->toBeTrue();

    // And the account can actually sign in to the staff portal it was made for.
    test()->postJson('/api/v1/auth/login', [
        'email' => 'new.zoning@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertOk();
});

it('still accepts the plural roles array', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', [
            'first_name' => 'Teodoro',
            'last_name' => 'Mangahas',
            'gender' => 'M',
            'email' => 'new.obo@biztrack.local',
            'mobile_number' => '09171234567',
            'password' => 'biztrack1',
            'roles' => ['obo_staff'],
            'department_id' => Department::where('code', 'OBO')->value('id'),
        ])->assertCreated()
        ->assertJsonPath('data.roles', ['obo_staff']);
});

it('offers every office a role, not just the four that were hard-coded', function () {
    $roles = collect(test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/roles')->assertOk()->json('data'));

    // The four the form used to list, plus the four offices it could not staff.
    expect($roles->pluck('name'))
        ->toContain('bplo_staff', 'sanitary_officer', 'fire_inspector', 'admin')
        ->toContain('zoning_officer', 'obo_staff', 'cenro_officer', 'market_admin')
        // Owners register themselves; minting one here makes an account with no
        // consent record and no business.
        ->not->toContain('business_owner');

    // Labels come from roles.display_name, which has held them since the first
    // migration and which nothing read — the screen kept its own shorter map.
    expect($roles->firstWhere('name', 'obo_staff')['label'])->toBe('Building Official Staff')
        ->and($roles->firstWhere('name', 'admin')['wants_department'])->toBeFalse()
        ->and($roles->firstWhere('name', 'cenro_officer')['wants_department'])->toBeTrue();
});

it('refuses an officer with no office, and a super admin with one', function () {
    $admin = authAs('admin@biztrack.local');

    // An officer with no department sees an empty queue: AssignmentController
    // sends a departmentless non-admin down whereRaw('1=0'). This used to be
    // accepted and produced an account that signed in to nothing.
    test()->withHeaders($admin)->postJson('/api/v1/admin/users', [
        'first_name' => 'No', 'last_name' => 'Office', 'gender' => 'M',
        'email' => 'no.office@biztrack.local', 'mobile_number' => '09171234567',
        'password' => 'biztrack1', 'role' => 'sanitary_officer',
    ])->assertStatus(422)->assertJsonValidationErrors('department_id');

    // The mirror image, and the more dangerous one: OIC reassignment is granted
    // by having NO department, so giving the super admin an office would
    // silently revoke their power to move any office's caseload.
    test()->withHeaders($admin)->postJson('/api/v1/admin/users', [
        'first_name' => 'Super', 'last_name' => 'Admin', 'gender' => 'F',
        'email' => 'second.admin@biztrack.local', 'mobile_number' => '09171234567',
        'password' => 'biztrack1', 'role' => 'admin',
        'department_id' => Department::where('code', 'BPLO')->value('id'),
    ])->assertStatus(422)->assertJsonValidationErrors('department_id');
});

it('will not mint a business owner from the staff screen', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', [
            'first_name' => 'Back', 'last_name' => 'Door', 'gender' => 'M',
            'email' => 'back.door@biztrack.local', 'mobile_number' => '09171234567',
            'password' => 'biztrack1', 'role' => 'business_owner',
        ])->assertStatus(422)->assertJsonValidationErrors('roles.0');
});

/* ── Reassign ────────────────────────────────────────────────────────────── */

it('reports what an officer is holding, and who in their office could take it', function () {
    $held = officerIn('CHO', 'sanitary_officer', 'cho.held@biztrack.local');
    $colleague = officerIn('CHO', 'sanitary_officer', 'cho.spare@biztrack.local');
    officerIn('BFP', 'fire_inspector', 'bfp.other@biztrack.local');

    assignmentHeldBy($held, 'DTI-91001');

    $caseload = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/admin/users/{$held->id}/caseload")->assertOk()->json('data');

    expect($caseload['open_reviews'])->toBe(1)
        ->and($caseload['total'])->toBe(1)
        ->and($caseload['department']['code'])->toBe('CHO');

    // Only same-office, active colleagues: assign() refuses anyone else, so
    // offering them would be offering a choice the confirm would reject.
    $candidateIds = collect($caseload['candidates'])->pluck('id');
    expect($candidateIds)->toContain($colleague->id)
        ->and($candidateIds)->not->toContain($held->id)
        ->and($candidateIds)->not->toContain(User::where('email', 'bfp.other@biztrack.local')->value('id'));
});

it('actually moves the caseload to the chosen officer', function () {
    $held = officerIn('CHO', 'sanitary_officer', 'cho.held@biztrack.local');
    $colleague = officerIn('CHO', 'sanitary_officer', 'cho.spare@biztrack.local');
    $assignment = assignmentHeldBy($held, 'DTI-91002');

    $result = test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/users/{$held->id}/reassign-caseload", [
            'to_user_id' => $colleague->id,
            'scope' => 'all',
            'reason' => 'Officer on extended leave.',
        ])->assertOk()->json('data');

    expect($result['moved_reviews'])->toBe(1)
        ->and($result['to']['id'])->toBe($colleague->id);

    // The part the mock never did.
    expect($assignment->fresh()->officer_user_id)->toBe($colleague->id);

    // The officer who inherited it is told, because a caseload that appears
    // overnight with no explanation is indistinguishable from a queue bug.
    test()->withHeaders(authAs('cho.spare@biztrack.local'));
    expect($colleague->notifications()->where('title', 'Cases reassigned to you')->exists())->toBeTrue();
});

it('releases a caseload to the office when there is nobody to name', function () {
    // Every office in the register is one officer deep, so this is the ordinary
    // case rather than the edge one.
    $held = officerIn('CENRO', 'cenro_officer', 'cenro.held@biztrack.local');
    $assignment = assignmentHeldBy($held, 'DTI-91003');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/users/{$held->id}/reassign-caseload", [
            'to_user_id' => null,
            'scope' => 'all',
            'reason' => 'Post vacant pending appointment.',
        ])->assertOk()->assertJsonPath('data.total', 1);

    // Unassigned is the state a case starts in, not a broken one — the office
    // still sees it as work waiting.
    expect($assignment->fresh()->officer_user_id)->toBeNull();
});

it('honours the scope, so an inspections-only move leaves reviews alone', function () {
    $held = officerIn('BFP', 'fire_inspector', 'bfp.held@biztrack.local');
    $colleague = officerIn('BFP', 'fire_inspector', 'bfp.spare@biztrack.local');
    $assignment = assignmentHeldBy($held, 'DTI-91004');

    $inspection = Inspection::create([
        'application_id' => $assignment->application_id,
        'department_id' => $held->department_id,
        'inspector_user_id' => $held->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addWeek(),
    ]);

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/users/{$held->id}/reassign-caseload", [
            'to_user_id' => $colleague->id,
            'scope' => 'inspections',
            'reason' => 'Site visits only.',
        ])->assertOk()
        ->assertJsonPath('data.moved_inspections', 1)
        ->assertJsonPath('data.moved_reviews', 0);

    expect($inspection->fresh()->inspector_user_id)->toBe($colleague->id)
        ->and($assignment->fresh()->officer_user_id)->toBe($held->id);
});

it('leaves finished work alone', function () {
    $held = officerIn('CHO', 'sanitary_officer', 'cho.held@biztrack.local');
    $colleague = officerIn('CHO', 'sanitary_officer', 'cho.spare@biztrack.local');

    $open = assignmentHeldBy($held, 'DTI-91008');
    $done = assignmentHeldBy($held, 'DTI-91009');
    $done->update(['status' => AssignmentStatus::Completed]);

    $closedInspection = Inspection::create([
        'application_id' => $open->application_id,
        'department_id' => $held->department_id,
        'inspector_user_id' => $held->id,
        'status' => 'completed',
        'scheduled_at' => now()->subWeek(),
        'conducted_at' => now()->subWeek(),
    ]);

    // Only the live case is counted…
    expect(test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/admin/users/{$held->id}/caseload")->assertOk()->json('data.total'))->toBe(1);

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/users/{$held->id}/reassign-caseload", [
            'to_user_id' => $colleague->id, 'scope' => 'all', 'reason' => 'Handover.',
        ])->assertOk()->assertJsonPath('data.total', 1);

    /*
     * …and only the live case moves. A completed review and a conducted
     * inspection are the record of who actually did that work; rewriting the
     * name on them would quietly falsify the register, and the audit trail
     * would show the successor signing off a review they never saw.
     */
    expect($open->fresh()->officer_user_id)->toBe($colleague->id)
        ->and($done->fresh()->officer_user_id)->toBe($held->id)
        ->and($closedInspection->fresh()->inspector_user_id)->toBe($held->id);
});

it('refuses to hand a caseload across offices or to a deactivated account', function () {
    $held = officerIn('CHO', 'sanitary_officer', 'cho.held@biztrack.local');
    $otherOffice = officerIn('BFP', 'fire_inspector', 'bfp.other@biztrack.local');
    $deactivated = officerIn('CHO', 'sanitary_officer', 'cho.gone@biztrack.local');
    $deactivated->update(['is_active' => false]);
    assignmentHeldBy($held, 'DTI-91005');

    $admin = authAs('admin@biztrack.local');

    // Same rule AssignmentController::assign enforces, checked BEFORE the loop
    // writes — a refusal partway through would leave half a caseload moved.
    test()->withHeaders($admin)
        ->postJson("/api/v1/admin/users/{$held->id}/reassign-caseload", [
            'to_user_id' => $otherOffice->id, 'scope' => 'all', 'reason' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('to_user_id');

    test()->withHeaders($admin)
        ->postJson("/api/v1/admin/users/{$held->id}/reassign-caseload", [
            'to_user_id' => $deactivated->id, 'scope' => 'all', 'reason' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('to_user_id');

    // And a move has to say why: it is recorded against both officers.
    test()->withHeaders($admin)
        ->postJson("/api/v1/admin/users/{$held->id}/reassign-caseload", [
            'to_user_id' => null, 'scope' => 'all',
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
});

/* ── Deactivate and office moves ─────────────────────────────────────────── */

it('releases the caseload when an officer is deactivated', function () {
    $held = officerIn('CHO', 'sanitary_officer', 'cho.held@biztrack.local');
    $assignment = assignmentHeldBy($held, 'DTI-91006');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/users/{$held->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('meta.released.reviews', 1);

    // It used to stay named to an account that can no longer sign in, visible
    // in no queue and flagged nowhere.
    expect($assignment->fresh()->officer_user_id)->toBeNull();
});

it('does not strand a caseload in the office an officer has just left', function () {
    $held = officerIn('CHO', 'sanitary_officer', 'cho.held@biztrack.local');
    $assignment = assignmentHeldBy($held, 'DTI-91007');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$held->id}", [
            'department_id' => Department::where('code', 'BFP')->value('id'),
            'roles' => ['fire_inspector'],
        ])->assertOk()->assertJsonPath('meta.released.reviews', 1);

    /*
     * The row is City Health's and stays City Health's; only the name comes
     * off it. Editing the Office field used to be a bare column write, so the
     * officer landed in Fire while still named on a City Health review that
     * AssignmentController then refused to show them — live work, with a name
     * against it, in nobody's queue.
     */
    expect($assignment->fresh()->officer_user_id)->toBeNull()
        ->and($assignment->fresh()->department_id)->toBe(Department::where('code', 'CHO')->value('id'));
});

it('keeps the caseload move behind oic.assign, not merely user.manage', function () {
    $held = officerIn('CHO', 'sanitary_officer', 'cho.held@biztrack.local');

    // BPLO holds neither oic.assign nor user.manage; the point of the assertion
    // is that an office account cannot reach the city-wide reshuffle at all.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/admin/users/{$held->id}/caseload")
        ->assertForbidden();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/admin/users/{$held->id}/reassign-caseload", [
            'to_user_id' => null, 'scope' => 'all', 'reason' => 'x',
        ])->assertForbidden();
});

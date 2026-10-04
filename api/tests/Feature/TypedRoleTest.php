<?php

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
 * ── A role typed in because it is not on the list ──────────────────────────
 *
 * An office needs to be able to write down the job title it actually uses
 * [client, 27 September 2026: *"pede rin nila i type yung role kung wala sa
 * choices"*]. The obvious implementation — create the row, attach nothing —
 * mints an account that signs in to a blank app and can do nothing, with no
 * error anywhere to say why.
 *
 * What makes typing safe is that an office role is almost entirely a TITLE.
 * Measured on the register: `sanitary_officer`, `fire_inspector`, `obo_staff`
 * and `cenro_officer` carry byte-for-byte the same seven permissions, and
 * `zoning_officer` those seven plus `zoning.evaluate`. What decides which
 * filings an officer actually sees is their department. So a typed role copies
 * that standard set, and the office still does the scoping.
 */

/** The payload the Add Officer form sends, with a typed role on it. */
function typedRolePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Typed',
        'last_name' => 'Role',
        'gender' => 'F',
        'email' => 'typed.role@biztrack.local',
        'mobile_number' => '09170000111',
        'password' => 'Malabon-City-2026!',
        'new_role' => 'Sanitary Inspector II',
        'department_id' => Department::where('code', 'CHO')->value('id'),
    ], $overrides);
}

function addOfficer(array $overrides = []): TestResponse
{
    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', typedRolePayload($overrides));
}

it('creates the role a title was typed for, with an officer’s permissions', function () {
    $created = addOfficer()->assertCreated()->json('data');

    $role = Role::where('display_name', 'Sanitary Inspector II')->firstOrFail();

    expect($role->name)->toBe('sanitary_inspector_ii')
        ->and($created['roles'])->toContain('sanitary_inspector_ii');

    /*
     * The permissions are the point. A role with none is an account that signs
     * in and finds every screen empty — which is not a refusal anybody can
     * debug from the outside.
     */
    $standard = Role::where('name', 'sanitary_officer')->firstOrFail()
        ->permissions()->pluck('permissions.name')->sort()->values()->all();

    expect($role->permissions()->pluck('permissions.name')->sort()->values()->all())
        ->toBe($standard)
        ->and($standard)->not->toBeEmpty();

    // And the officer holding it can actually do the job.
    $officer = User::where('email', 'typed.role@biztrack.local')->firstOrFail();
    expect($officer->roleNames())->toContain('sanitary_inspector_ii');
});

/*
 * The door is part of being an officer (owner-sign-in row 14).
 *
 * The staff sign-in admitted a fixed list of six role names, so an officer
 * whose title was typed in had the permissions and the office and still got
 * "Invalid credentials" at /staff/login — counted towards the lockout — while
 * the owners' sign-in let them in and issued a token. The door now follows
 * the account: anything that is not the super admin's or an owner's is staff.
 */
it('admits an officer with a typed role at the staff door and nowhere else', function () {
    addOfficer()->assertCreated();
    app('auth')->forgetGuards();

    $login = fn (string $portal) => $this->postJson('/api/v1/auth/login', [
        'email' => 'typed.role@biztrack.local',
        'password' => 'Malabon-City-2026!',
        'portal' => $portal,
    ]);

    $login('staff')->assertOk()->assertJsonPath('data.user.email', 'typed.role@biztrack.local');

    // The citizen door answers as it does a wrong password, and issues nothing.
    $login('public')->assertStatus(422)->assertJsonMissingPath('data.token');
    $login('admin')->assertStatus(409);
});

it('lands two admins typing the same title on one role', function () {
    /*
     * A week apart, and a space out. Two roles differing by whitespace is how
     * a register ends up with entries nobody can tell apart on screen.
     */
    addOfficer()->assertCreated();
    addOfficer([
        'new_role' => '  sanitary inspector ii  ',
        'email' => 'second.typist@biztrack.local',
    ])->assertCreated();

    expect(Role::where('name', 'sanitary_inspector_ii')->count())->toBe(1);
});

it('uses the role already on the list when its exact name is typed', function () {
    // Typing what you could have picked is not a request for a second role
    // beside it.
    $before = Role::count();

    addOfficer([
        'new_role' => 'Fire Inspector',
        'email' => 'typed.existing@biztrack.local',
        'department_id' => Department::where('code', 'BFP')->value('id'),
    ])->assertCreated();

    expect(Role::count())->toBe($before)
        ->and(User::where('email', 'typed.existing@biztrack.local')->firstOrFail()->roleNames())
        ->toContain('fire_inspector');
});

it('refuses to mint a role with no office behind it', function () {
    /*
     * The one door typing must not open. `admin` holds fourteen permissions
     * including `user.manage` — the power to create accounts — so a typed name
     * that reached a departmentless role would be privilege escalation by
     * spelling.
     */
    addOfficer([
        'new_role' => 'Deputy Super Admin',
        'email' => 'no.office@biztrack.local',
        'department_id' => null,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_role');

    expect(Role::where('name', 'deputy_super_admin')->exists())->toBeFalse()
        ->and(User::where('email', 'no.office@biztrack.local')->exists())->toBeFalse();
});

it('leaves no role behind when the account is refused for another reason', function () {
    /*
     * The role is resolved BEFORE validation runs, so a request that then
     * fails on something else would strand a role nobody holds. This is the
     * case that says whether that happens.
     */
    $before = Role::count();

    addOfficer([
        'new_role' => 'Records Clerk III',
        'email' => 'weak.typed@biztrack.local',
        'password' => 'short',
    ])->assertStatus(422);

    expect(User::where('email', 'weak.typed@biztrack.local')->exists())->toBeFalse()
        ->and(Role::where('name', 'records_clerk_iii')->exists())->toBeFalse()
        ->and(Role::count())->toBe($before);
});

it('refuses a title too short to be one', function () {
    addOfficer(['new_role' => 'II', 'email' => 'too.short@biztrack.local'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_role');
});

it('refuses to let a typed title reach the business-owner role', function () {
    // The other excluded name. Owners register their own accounts; an officer
    // account wearing that role would see an applicant's app, not an office's.
    addOfficer(['new_role' => 'Business Owner', 'email' => 'owner.typed@biztrack.local'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_role');
});

it('offers the new role to the next account without anybody typing it again', function () {
    // The point of making it a real role rather than a label on one account:
    // the office's second inspector picks it off the list.
    addOfficer()->assertCreated();

    $row = collect(
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->json('data')
    )->firstWhere('name', 'sanitary_inspector_ii');

    expect($row)->not->toBeNull()
        ->and($row['label'])->toBe('Sanitary Inspector II')
        // It belongs to an office, like every role a title can be typed for.
        ->and($row['wants_department'])->toBeTrue()
        ->and($row['available'])->toBeTrue();
});

it('records who invented a role, and what it was copied from', function () {
    // A role that appeared in the register with nobody's name against it is
    // the kind of row an auditor cannot follow up.
    addOfficer(['new_role' => 'Records Clerk III'])->assertCreated();

    $entry = AuditLog::where('action', 'role.created')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->changes['display_name'])->toBe('Records Clerk III')
        ->and($entry->changes['copied_from'])->toBe('sanitary_officer');
});

it('lets an existing officer be moved onto a typed role', function () {
    // The edit form offers the same box, so the same path has to work there.
    $officer = User::where('email', 'sanitary@biztrack.local')->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$officer->id}", [
            'new_role' => 'Sanitary Inspector II',
            'department_id' => $officer->department_id,
        ])
        ->assertOk();

    expect($officer->refresh()->roleNames())->toContain('sanitary_inspector_ii');
});

it('still takes a role chosen from the list, untouched', function () {
    // Typing is an addition, not a replacement. The ordinary path must not
    // have become the exceptional one.
    $before = Role::count();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', [
            'first_name' => 'Picked',
            'last_name' => 'Normally',
            'gender' => 'M',
            'email' => 'picked.normally@biztrack.local',
            'mobile_number' => '09170000119',
            'password' => 'Malabon-City-2026!',
            'roles' => ['cenro_officer'],
            'department_id' => Department::where('code', 'CENRO')->value('id'),
        ])
        ->assertCreated();

    expect(Role::count())->toBe($before)
        ->and(User::where('email', 'picked.normally@biztrack.local')->firstOrFail()->roleNames())
        ->toContain('cenro_officer');
});

<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/*
 * Three write endpoints skipped the office boundary that every read enforces.
 *
 * None of them was exploitable as the RBAC matrix stands: `application.reject`
 * and `fee.adjust` sit only on BPLO and the super admin, who read every office
 * anyway, and `oic.assign` is super-admin only. That is an argument for adding
 * the checks, not against — each of these is a stronger act than reading the
 * filing, and each was one permission grant away from being a hole nobody would
 * think to look for.
 *
 * So the tests grant the permission to an office role explicitly and assert the
 * boundary still holds. Testing the current matrix would only prove the matrix.
 */

/** Give one seeded office role a permission it does not normally hold. */
function grantPermission(string $roleName, string $permission): void
{
    $role = Role::where('name', $roleName)->firstOrFail();
    $id = Permission::firstOrCreate(['name' => $permission])->id;
    $role->permissions()->syncWithoutDetaching([$id]);
}

/** A filing this officer's office was never routed to. */
function filingOutsideOffice(string $officerEmail): Application
{
    $officer = User::where('email', $officerEmail)->firstOrFail();

    return Application::whereDoesntHave('assignments', fn ($a) => $a->where('department_id', $officer->department_id))
        ->where('applicant_user_id', '!=', $officer->id)
        ->firstOrFail();
}

it('will not let an office adjust the fee on another office’s filing', function () {
    grantPermission('cenro_officer', 'fee.adjust');
    $application = filingOutsideOffice('cenro@biztrack.local');

    test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson("/api/v1/applications/{$application->id}/fee/adjust", [
            'line_items' => [['label' => 'Revised assessment', 'amount' => 1]],
            'total_amount' => 1,
        ])
        ->assertForbidden();
});

it('will not let an office end another office’s filing', function () {
    grantPermission('cenro_officer', 'application.reject');
    $application = filingOutsideOffice('cenro@biztrack.local');

    test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson("/api/v1/applications/{$application->id}/reject", ['reason' => 'Not my filing.'])
        ->assertForbidden();

    expect($application->fresh()->status->value)->not->toBe('rejected');
});

it('will not let an OIC reshuffle another office’s queue', function () {
    grantPermission('cenro_officer', 'oic.assign');

    $cenro = User::where('email', 'cenro@biztrack.local')->firstOrFail();
    $otherAssignment = ApplicationAssignment::where('department_id', '!=', $cenro->department_id)->firstOrFail();
    $someOfficer = User::where('department_id', $otherAssignment->department_id)->firstOrFail();

    test()->withHeaders(authAs('cenro@biztrack.local'))
        ->postJson("/api/v1/assignments/{$otherAssignment->id}/assign", [
            'officer_user_id' => $someOfficer->id,
        ])
        ->assertForbidden();

    expect($otherAssignment->fresh()->officer_user_id)->not->toBe($someOfficer->id);
});

it('still lets the offices that should do these things do them', function () {
    // BPLO reads every office, so the added checks must not get in its way.
    $application = Application::whereHas('assignments')
        ->whereNotIn('status', ['approved', 'rejected', 'cancelled'])
        ->firstOrFail();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$application->id}/fee/adjust", [
            'line_items' => [['label' => 'Revised assessment', 'amount' => 1500]],
            'total_amount' => 1500,
        ])
        ->assertOk();

    $assignment = ApplicationAssignment::firstOrFail();
    $officer = User::where('department_id', $assignment->department_id)->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", ['officer_user_id' => $officer->id])
        ->assertOk();
});

it('rejects a fee adjustment whose amounts overflow the money columns', function () {
    $application = Application::whereHas('assignments')->firstOrFail();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$application->id}/fee/adjust", [
            'line_items' => [['label' => 'Absurd', 'amount' => '999999999999999999']],
            'total_amount' => '999999999999999999',
        ])
        ->assertStatus(422);
});

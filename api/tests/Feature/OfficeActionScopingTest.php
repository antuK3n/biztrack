<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/*
 * Two write endpoints skipped the office boundary that every read enforces.
 *
 * Neither was exploitable as the RBAC matrix stands: `application.reject` sits
 * only on BPLO and the super admin, who read every office anyway, and
 * `oic.assign` is super-admin only. That is an argument for adding the checks,
 * not against — each of these is a stronger act than reading the filing, and
 * each was one permission grant away from being a hole nobody would think to
 * look for.
 *
 * So the tests grant the permission to an office role explicitly and assert the
 * boundary still holds. Testing the current matrix would only prove the matrix.
 *
 * ── There were three ──────────────────────────────────────────────────────
 *
 * The third was fee adjustment, and it is gone [client, 2026-09-06]: the fee is
 * computed from the revenue code at submission and is the figure the applicant
 * has been looking at ever since, so there is no moment at which moving it
 * would not move a number somebody had already decided to pay. The route was
 * deleted, not gated — see the note beside the payment routes in
 * `routes/workflow.php`. Two cases went with it: one asserting an outside
 * office was refused, and one asserting an absurd amount was rejected with 422.
 * The first is now the stronger statement below (nobody has this power, not
 * even BPLO); the second guarded an overflow on input that can no longer be
 * supplied. If BPLO turns out to need the adjustment after all, the route comes
 * back and both belong back with it — `fee.adjust` is deliberately still in
 * RbacSeeder for that.
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

it('will not let any office adjust the fee, on its own filings or anyone else’s', function () {
    // Holding `fee.adjust` buys nothing, because there is nothing to reach: the
    // route is gone rather than gated, which is why this asserts 404 and not
    // 403. BPLO and the super admin are named alongside the outside office
    // deliberately — the rule is not "the wrong office is refused", it is that
    // the figure is the revenue code's and no account can move it.
    grantPermission('cenro_officer', 'fee.adjust');
    $outside = filingOutsideOffice('cenro@biztrack.local');
    $any = Application::whereHas('assignments')->firstOrFail();

    foreach ([
        ['cenro@biztrack.local', $outside],
        ['bplo@biztrack.local', $any],
        ['admin@biztrack.local', $any],
    ] as [$email, $application]) {
        test()->withHeaders(authAs($email))
            ->postJson("/api/v1/applications/{$application->id}/fee/adjust", [
                'line_items' => [['label' => 'Revised assessment', 'amount' => 1]],
                'total_amount' => 1,
            ])
            ->assertNotFound();
    }
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

it('still lets the office that should do this do it', function () {
    // The other half of the pair: a boundary that refuses the right people too
    // is not a boundary, it is an outage. The super admin reassigns across
    // offices by design, so the added check must not get in its way.
    $assignment = ApplicationAssignment::firstOrFail();
    $officer = User::where('department_id', $assignment->department_id)->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/assign", ['officer_user_id' => $officer->id])
        ->assertOk();
});

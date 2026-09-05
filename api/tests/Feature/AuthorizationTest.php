<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\Role;

/** Cross-owner: an owner may not view another owner's application. */
it('forbids cross-owner application access with 403', function () {
    // app2 (RxCare) belongs to juan@biztrack.local; owner@ must not see it.
    $app2 = Application::whereHas('business', fn ($q) => $q->where('name', 'RxCare Pharmacy'))->first();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$app2->id}")
        ->assertStatus(403);
});

/** Cross-department: an officer may not approve another department's assignment. */
it('forbids cross-department assignment approval with 403', function () {
    $app2 = Application::whereHas('business', fn ($q) => $q->where('name', 'RxCare Pharmacy'))->first();
    // The CHO (sanitary) assignment — a fire inspector must not approve it.
    $choDeptId = Department::where('code', 'CHO')->value('id');
    $assignment = ApplicationAssignment::where('application_id', $app2->id)
        ->where('department_id', $choDeptId)->first();

    $this->withHeaders(authAs('fire@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignment->id}/approve", ['remarks' => 'x'])
        ->assertStatus(403);
});

/** Permission gate: a business owner has no analytics permission. */
it('forbids an owner from analytics with 403', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/analytics/summary')
        ->assertStatus(403);
});

/*
 * The super admin oversees the process and does not work inside it.
 *
 * The client, in full: "In the super admin's account (admin@), remove Messages,
 * Track, Inspections, and Other Requirements. It is not his role to do those
 * things." Those are four rail entries, and each one is a permission — the nav
 * filters off the profile payload, so the permission IS the rail entry, and the
 * API routes are gated on the same four names.
 *
 * Asserted against the seeded matrix rather than through the four endpoints,
 * for the same reason the analytics split below is: the grant is the thing that
 * was asked for, and every screen and route hangs off it. An endpoint test would
 * also go green if the route moved to a different permission the admin happens
 * to hold.
 *
 * The positive half matters as much as the negative one. "Remove these four" is
 * not "strip the role": reading the register is oversight, and the admin still
 * holds `application.view_all` + `application.view_any_office` (every filing),
 * `permit.view_all`, and its own account / reference / audit powers. Pinning
 * both lists is what stops this being re-read later as either a demotion or a
 * restoration.
 */
it('keeps the four working permissions off the super admin, and the oversight ones on', function () {
    $admin = Role::where('name', 'admin')->firstOrFail();
    $held = $admin->permissions->pluck('name');

    $removed = [
        'message.participate' => 'Messages',
        'application.review' => 'Track (the officer queue)',
        'inspection.manage' => 'Inspections',
        'request.create' => 'Other Requirements',
    ];

    /*
     * toBeFalse() rather than not->toContain(), so a failure names the
     * permission and the rail entry it puts back. Pest's toContain() is
     * variadic and would silently swallow the message as a second needle.
     */
    foreach ($removed as $permission => $railEntry) {
        expect($held->contains($permission))->toBeFalse(
            "The super admin holds [{$permission}], which puts [{$railEntry}] back on its rail."
        );
    }

    foreach (['application.view_all', 'application.view_any_office', 'permit.view_all',
        'permit.issue', 'user.manage', 'reference.manage', 'audit.view',
        'analytics.processing_time'] as $permission) {
        expect($held->contains($permission))->toBeTrue(
            "The super admin lost [{$permission}], which is oversight rather than office work."
        );
    }
});

/*
 * The other side of the same rule: the six offices that issue a clearance are
 * the ones that inspect for it.
 *
 * `inspection.manage` did not simply leave the super admin — it moved outwards.
 * It used to be `sanitary_officer` and `fire_inspector` alone, which left OBO,
 * CENRO, CPDO and the Market Office unable to see a visit booked against their
 * own office. The client reported exactly that ("OBO, CENRO, Market, and Zoning
 * admins cannot approve inspection. Only Sanitary and Fire has it"), and
 * ReferenceSeeder now marks all six supporting clearances as inspected — so
 * every one of those offices must be able to close its own visit or the filing
 * strands in `for_inspection` with nobody to move it.
 */
it('grants inspection.manage to the five other-permit offices and no one else', function () {
    $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'inspection.manage'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    // BPLO is absent and should be: it issues the Mayor's Permit on the strength
    // of the five other permits rather than a visit of its own.
    //
    // `market_admin` was a sixth holder until 6 September 2026, when the Market
    // Clearance and the City Market Office were removed from the system.
    expect($holders)->toBe([
        'cenro_officer', 'fire_inspector',
        'obo_staff', 'sanitary_officer', 'zoning_officer',
    ]);
});

/*
 * Checklist item 78 — "the dashboard should be transferred to BPLO admin, not
 * super admin."
 *
 * This asserts the seeded matrix rather than one endpoint, because the grant is
 * the thing that was asked for and every analytics route hangs off it. The
 * negative half is the point: "transferred to BPLO" is not "opened to the
 * offices", and the reason the boundary can move for BPLO alone is that BPLO is
 * the only office role already holding application.view_any_office — the
 * aggregates summarise nothing it could not open one filing at a time.
 *
 * "Transferred" turned out to mean transferred. The super admin is not on this
 * list, and its absence is asserted here rather than left to whichever endpoint
 * test happens to notice: `analytics.view` carries the three operational screens
 * (spec §1 Analytics Dashboard, §2 Renewal Risk, §4 Business Growth, each headed
 * "(Admin - BPLO)"), and they are now BPLO's alone.
 */
it('grants analytics.view to BPLO alone, the super admin included', function () {
    $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'analytics.view'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($holders)->toBe(['bplo_staff']);

    $bplo = Role::where('name', 'bplo_staff')->firstOrFail();
    expect($bplo->permissions->pluck('name'))->toContain('application.view_any_office');
});

/*
 * The companion half of the split, and the reason it is a split rather than a
 * demotion.
 *
 * Spec §6 "Permit Processing Time Monitoring (Super Admin)" got its own
 * permission because it measures the DEPARTMENTS — BPLO among them — for
 * genuine slowdowns. Handing the office being measured the same screen as the
 * office measuring it is what this avoids, so the two grants must not overlap.
 * Asserting each list exactly, and then that they are disjoint, is what stops a
 * future "give the admin analytics.view back, it is the admin after all" from
 * landing quietly.
 */
it('grants analytics.processing_time to the super admin alone, BPLO included', function () {
    $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'analytics.processing_time'))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($holders)->toBe(['admin']);

    /*
     * Neither role holds both. Separation of duties is the whole content of the
     * rule, and an overlap would satisfy both `toBe()` assertions above while
     * breaking it — so it is asserted from the other side as well.
     *
     * Written as toBeFalse() rather than not->toContain() so the failure names
     * the role and the permission. Pest's toContain() is variadic, so a message
     * passed to it is silently taken as a second needle and the assertion starts
     * checking for a sentence.
     */
    foreach (['admin' => 'analytics.view', 'bplo_staff' => 'analytics.processing_time'] as $role => $forbidden) {
        $held = Role::where('name', $role)->firstOrFail()->permissions->pluck('name')->contains($forbidden);

        expect($held)->toBeFalse("Role [{$role}] holds [{$forbidden}], which collapses the analytics split.");
    }
});

it('lets BPLO open the analytics dashboard', function () {
    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk();

    // The sign-in payload is what the rail and the route guards filter on, so a
    // grant the login does not carry is a grant the screens never see.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertOk()->assertJsonPath('data.user.permissions', fn ($perms) => in_array('analytics.view', $perms, true));
});

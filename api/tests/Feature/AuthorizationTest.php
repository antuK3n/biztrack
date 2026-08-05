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

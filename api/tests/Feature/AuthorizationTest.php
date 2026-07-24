<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;

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

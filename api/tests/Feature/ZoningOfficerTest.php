<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * CPDO had a department row and a ZONING permit type but no officer, so zoning
 * assignments piled up in a queue nobody could open (tester item 53).
 */

it('seeds a CPDO zoning officer who can sign in at the staff door', function () {
    $officer = User::where('email', 'zoning@biztrack.local')->first();

    expect($officer)->not->toBeNull()
        ->and($officer->is_active)->toBeTrue()
        ->and($officer->department?->code)->toBe('CPDO')
        ->and($officer->roles->pluck('name')->all())->toBe(['zoning_officer']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'zoning@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertOk()->assertJsonPath('data.user.department.code', 'CPDO');
});

it('shows the zoning officer the CPDO queue and nothing else', function () {
    $owner = authAs('owner@biztrack.local');

    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Zoning Queue Hardware',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-88101',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '4 Zoning Ave.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'ZONING'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    $cpdoId = Department::where('code', 'CPDO')->value('id');
    $zoningAssignment = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', $cpdoId)
        ->firstOrFail();

    $zoning = authAs('zoning@biztrack.local');
    $queue = $this->withHeaders($zoning)->getJson('/api/v1/assignments')->assertOk()->json('data');

    expect(collect($queue)->pluck('id'))->toContain($zoningAssignment->id)
        ->and(collect($queue)->pluck('department.code')->unique()->all())->toBe(['CPDO']);

    $this->withHeaders($zoning)
        ->getJson("/api/v1/assignments/{$zoningAssignment->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $zoningAssignment->id);
});

it('lets the zoning officer clear its own assignment but not end the application', function () {
    $owner = authAs('owner@biztrack.local');

    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Zoning Review Bakery',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-88102',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Zoning Ave.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'ZONING'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    // Confirmed on receipt, so the only thing left standing between the zoning
    // officer and their own assignment is the department scoping under test.
    classifyAsOfficer(Application::findOrFail($appId));

    $cpdoId = Department::where('code', 'CPDO')->value('id');
    $assignment = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', $cpdoId)
        ->firstOrFail();

    $zoning = authAs('zoning@biztrack.local');
    $this->withHeaders($zoning)
        ->postJson("/api/v1/assignments/{$assignment->id}/approve", ['remarks' => 'Conforms to the zoning ordinance.'])
        ->assertOk();

    expect($assignment->fresh()->status->value)->toBe('completed');

    // Ending the whole application stays with BPLO and the super admin.
    $this->withHeaders($zoning)
        ->postJson("/api/v1/applications/{$appId}/reject", ['reason' => 'Not conforming.'])
        ->assertForbidden();
});

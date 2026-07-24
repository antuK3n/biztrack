<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Inspection;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Support\Carbon;

/**
 * End-to-end happy path: create -> submit -> pay -> 3 dept approvals ->
 * inspections pass -> permits issued with validity_days-based expiry.
 */
it('runs the full permit lifecycle and issues permits with validity_days', function () {
    $owner = authAs('owner@biztrack.local');

    // 1. Create a business (owner).
    $barangayId = Barangay::first()->id;
    $psicId = PsicCode::first()->id;
    $bizRes = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Test Diner',
        'address' => ['line1' => '1 Test St.', 'barangay_id' => $barangayId],
        'lines' => [['psic_code_id' => $psicId, 'capitalization' => 100000]],
    ])->assertCreated();
    $businessId = $bizRes->json('data.id');

    // 2. Create a DRAFT application with the 3 core permit types.
    $typeIds = PermitType::whereIn('code', ['BUSINESS', 'SANITARY', 'FSIC'])->pluck('id')->all();
    $appRes = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => $typeIds,
    ])->assertCreated();
    $appId = $appRes->json('data.id');

    // 3. Submit -> pending_payment.
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    expect(Application::find($appId)->status->value)->toBe('pending_payment');

    // 4. Pay -> under_review + assignments fan out to 3 departments.
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();
    expect(Application::find($appId)->status->value)->toBe('under_review');
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(3);

    // 5. Each department approves its assignment.
    $deptEmail = [
        'BPLO' => 'bplo@biztrack.local',
        'CHO' => 'sanitary@biztrack.local',
        'BFP' => 'fire@biztrack.local',
    ];
    foreach (ApplicationAssignment::where('application_id', $appId)->with('department')->get() as $assignment) {
        $officer = authAs($deptEmail[$assignment->department->code]);
        $this->withHeaders($officer)
            ->postJson("/api/v1/assignments/{$assignment->id}/approve", ['remarks' => 'ok'])
            ->assertOk();
    }

    // 6. All reviews done -> for_inspection; conduct each inspection (passed).
    expect(Application::find($appId)->status->value)->toBe('for_inspection');
    foreach (Inspection::where('application_id', $appId)->with('department')->get() as $inspection) {
        $officer = authAs($deptEmail[$inspection->department->code]);
        $this->withHeaders($officer)
            ->postJson("/api/v1/inspections/{$inspection->id}/conduct", ['result' => 'passed', 'findings' => 'clean'])
            ->assertOk();
    }

    // 7. Approved + one permit per type, valid ~365 days out.
    $app = Application::find($appId);
    expect($app->status->value)->toBe('approved');

    $permits = Permit::where('application_id', $appId)->get();
    expect($permits)->toHaveCount(3);

    $permit = $permits->first();
    $days = (int) Carbon::parse($permit->valid_from)
        ->diffInDays(Carbon::parse($permit->valid_until));
    expect($days)->toBe(365);
});

it('issues a 6-office application to all six department queues', function () {
    $owner = authAs('owner@biztrack.local');

    $barangayId = Barangay::first()->id;
    $psicId = PsicCode::first()->id;
    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Six Office Mart',
        'address' => ['line1' => '6 Office Rd.', 'barangay_id' => $barangayId],
        'lines' => [['psic_code_id' => $psicId]],
    ])->assertCreated()->json('data.id');

    $allTypeIds = PermitType::pluck('id')->all();
    expect($allTypeIds)->toHaveCount(6);

    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => $allTypeIds,
    ])->assertCreated()->json('data.id');

    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    // One assignment per issuing department => 6 queues hit.
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(6);
});

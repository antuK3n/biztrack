<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Inspection;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/**
 * The two end states must reach the applicant (tester item 51). Approval used
 * to arrive only as a generic "Application update" ping, indistinguishable from
 * every intermediate status change.
 */
$deptEmail = [
    'BPLO' => 'bplo@biztrack.local',
    'CHO' => 'sanitary@biztrack.local',
    'BFP' => 'fire@biztrack.local',
];

/** Create + submit + pay an application owned by owner@biztrack.local. */
function payingApplication(string $businessName, string $registrationNumber): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $businessName,
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Notify St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'SANITARY', 'FSIC'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    return $appId;
}

it('notifies the applicant when the application is approved', function () use ($deptEmail) {
    $appId = payingApplication('Notify Test Bakery', 'DTI-88001');

    // Confirmed on receipt. Approval is refused until a person has set the
    // processing category, and what this case is about is the notification the
    // applicant gets afterwards. The rejection case below deliberately does not
    // need it: rejection is ungated, a refused filing having no clock to run.
    classifyAsOfficer(Application::findOrFail($appId));

    foreach (ApplicationAssignment::where('application_id', $appId)->with('department')->get() as $assignment) {
        $this->withHeaders(authAs($deptEmail[$assignment->department->code]))
            ->postJson("/api/v1/assignments/{$assignment->id}/approve", ['remarks' => 'ok'])
            ->assertOk();
    }
    foreach (Inspection::where('application_id', $appId)->with('department')->get() as $inspection) {
        $this->withHeaders(authAs($deptEmail[$inspection->department->code]))
            ->postJson("/api/v1/inspections/{$inspection->id}/conduct", ['result' => 'passed', 'findings' => 'clean'])
            ->assertOk();
    }

    $app = Application::find($appId);
    expect($app->status->value)->toBe('approved');

    $owner = User::where('email', 'owner@biztrack.local')->first();
    $approval = AppNotification::where('user_id', $owner->id)
        ->where('type', 'decision')
        ->where('title', 'Application approved')
        ->first();

    expect($approval)->not->toBeNull()
        ->and($approval->body)->toContain($app->tracking_id)
        ->and($approval->body)->toContain('Permits')
        ->and($approval->link)->toBe('/permits');

    // The issuance pointer still lands, and nobody else is told about it.
    expect(AppNotification::where('user_id', $owner->id)->where('type', 'issuance')->count())->toBe(1);
    expect(AppNotification::where('user_id', '!=', $owner->id)->where('type', 'decision')->count())->toBe(0);

    // No duplicate generic "Application update" for the same end state.
    expect(
        AppNotification::where('user_id', $owner->id)
            ->where('type', 'status_change')
            ->where('body', 'like', '%Approved%')
            ->count()
    )->toBe(0);
});

it('notifies the applicant with the reason when the application is rejected', function () {
    $appId = payingApplication('Notify Test Cantina', 'DTI-88002');

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/reject", ['reason' => 'Lease contract is expired.'])
        ->assertOk();

    $app = Application::find($appId);
    expect($app->status->value)->toBe('rejected');

    $owner = User::where('email', 'owner@biztrack.local')->first();
    $rejection = AppNotification::where('user_id', $owner->id)
        ->where('type', 'decision')
        ->where('title', 'Application rejected')
        ->first();

    expect($rejection)->not->toBeNull()
        ->and($rejection->body)->toContain($app->tracking_id)
        ->and($rejection->body)->toContain('Lease contract is expired.');
});

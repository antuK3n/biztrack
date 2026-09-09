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
    'CPDO' => 'zoning@biztrack.local',
    'OBO' => 'obo@biztrack.local',
    'CENRO' => 'cenro@biztrack.local',
];

/** The five required clearances and the office that issues each. */
const END_STATE_OFFICE = [
    'SANITARY' => 'CHO',
    'FSIC' => 'BFP',
    'ZONING' => 'CPDO',
    'OCCUPANCY' => 'OBO',
    'CEC' => 'CENRO',
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
        'data_privacy_consent' => true,
        'application_type' => 'new',
        // The business permit alone. Which clearances a filing must obtain is
        // not the applicant's to choose: attachRequiredPermitTypes() adds all
        // five at submission, so naming a short list here would only mislead.
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    return $appId;
}

it('notifies the applicant when the application is approved', function () use ($deptEmail) {
    $appId = payingApplication('Notify Test Bakery', 'DTI-88001');

    /*
     * The applicant opens each of the five permits and hands each sheet in, and
     * it is the second act that routes the office. Payment no longer does it,
     * and nor does Apply on its own: Apply opens the office's form, and
     * `WorkflowService::submitClearanceForm` is what submits the permit and puts
     * it in a queue. A paid filing nobody has FILED a clearance on sits in
     * BPLO's queue alone, and the loop below would find no assignment to
     * approve.
     *
     * The sheets go in empty — this file is about who is told what at the end,
     * not about the answers that got the filing there.
     */
    foreach (array_keys(END_STATE_OFFICE) as $code) {
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->postJson("/api/v1/applications/{$appId}/clearances/{$code}/apply")
            ->assertOk();
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->putJson("/api/v1/applications/{$appId}/office-forms/{$code}", [
                'form_data' => [],
                'submit' => true,
            ])->assertSuccessful();
    }

    /*
     * Each office reads its permit, picks a date, and records the visit — three
     * acts, not one. Approving the paperwork books nothing now; the office says
     * when. The processing category needed no confirming here either, because
     * `bploApprovesForm()` above could not have approved the form without it.
     */
    foreach (END_STATE_OFFICE as $code => $deptCode) {
        $officer = authAs($deptEmail[$deptCode]);

        $assignmentId = ApplicationAssignment::where('application_id', $appId)
            ->whereHas('department', fn ($d) => $d->where('code', $deptCode))
            ->value('id');

        $this->withHeaders($officer)
            ->postJson("/api/v1/assignments/{$assignmentId}/approve", ['remarks' => 'ok'])
            ->assertOk();

        $visitId = $this->withHeaders($officer)
            ->postJson("/api/v1/applications/{$appId}/permits/{$code}/inspection", [
                'scheduled_at' => now()->addWeekdays(2)->startOfHour()->toDateTimeString(),
            ])->assertCreated()->json('data.id');

        $this->withHeaders($officer)
            ->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed', 'findings' => 'clean'])
            ->assertOk();
    }

    // The five are in, so BPLO's SECOND act is what makes the filing approved.
    // That is the only place an application becomes Approved now.
    $bploAssignmentId = ApplicationAssignment::where('application_id', $appId)
        ->whereHas('department', fn ($d) => $d->where('code', 'BPLO'))
        ->value('id');
    $this->withHeaders(authAs($deptEmail['BPLO']))
        ->postJson("/api/v1/assignments/{$bploAssignmentId}/approve", ['remarks' => 'All requirements met.'])
        ->assertOk();

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

    /*
     * No duplicate generic "Application update" for the same end state.
     *
     * Matched on the sentence `applicationStatus()` would actually write —
     * `… is now “Approved”.` — rather than on the word "Approved" anywhere in
     * the body, which is what this used to do. Each of the five offices now
     * announces its own permit as approved and issued the moment it passes its
     * inspection, so a bare `%Approved%` matches a dozen notices that are not
     * duplicates of anything and are the point of rule 7.
     */
    expect(
        AppNotification::where('user_id', $owner->id)
            ->where('type', 'status_change')
            ->where('body', 'like', '%is now “Approved”%')
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

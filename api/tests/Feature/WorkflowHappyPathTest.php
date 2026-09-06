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
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-77001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '1 Test St.', 'barangay_id' => $barangayId],
        'lines' => [['psic_code_id' => $psicId, 'capitalization' => 100000]],
    ])->assertCreated();
    $businessId = $bizRes->json('data.id');

    // 2. Create a DRAFT application with the 3 core permit types.
    $typeIds = PermitType::whereIn('code', ['BUSINESS', 'SANITARY', 'FSIC'])->pluck('id')->all();
    $appRes = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => $typeIds,
    ])->assertCreated();
    $appId = $appRes->json('data.id');

    // 3. Submit -> pending_payment.
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    expect(Application::find($appId)->status->value)->toBe('pending_payment');

    // 4. Pay -> under_review + assignments fan out to 3 departments.
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();
    expect(Application::find($appId)->status->value)->toBe('under_review');
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(3);

    /*
     * 5. The office confirms the processing category, then each department
     * approves its assignment.
     *
     * The confirmation is a real step of the lifecycle, not test scaffolding:
     * WorkflowService refuses to approve a filing still carrying the tier the
     * system guessed at submission, so on the happy path somebody reads the
     * filing and puts their name to that category before anyone signs off.
     */
    classifyAsOfficer(Application::findOrFail($appId));

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

    /*
     * 6. For inspection, and it got there on the FIRST inspecting office's
     * approval rather than the last review.
     *
     * The loop above no longer describes a phase. afterReviewProgress books
     * each office's visit as that office signs off, so whichever of CHO and BFP
     * approved first moved the filing here and the other joined it in place —
     * six offices used to wait on the slowest, which is what the client hit
     * ("when I approved a sanitary permit, why did it not automatically go to
     * inspection?"). What the end of the loop still guarantees, and why this
     * line is still exactly this line, is that every review is in AND every
     * booked visit is outstanding: no permit may exist yet.
     */
    expect(Application::find($appId)->status->value)->toBe('for_inspection');
    expect(Permit::where('application_id', $appId)->count())->toBe(0);
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

it('issues an all-office application to every department queue', function () {
    $owner = authAs('owner@biztrack.local');

    $barangayId = Barangay::first()->id;
    $psicId = PsicCode::first()->id;
    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Six Office Mart',
        // Item 94: `registration_type` is the organisation structure, not the
        // agency. "SEC" is refused on purpose — it registers both partnerships
        // and corporations, so it does not say which this shop is.
        'registration_type' => 'corporation',
        'registration_number' => 'SEC-77002',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '6 Office Rd.', 'barangay_id' => $barangayId],
        'lines' => [['psic_code_id' => $psicId]],
    ])->assertCreated()->json('data.id');

    // BPLO, CHO, BFP, OBO, CENRO, CPDO (zoning).
    $allTypeIds = PermitType::pluck('id')->all();
    expect($allTypeIds)->toHaveCount(7);

    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => $allTypeIds,
    ])->assertCreated()->json('data.id');

    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    // One assignment per issuing department => 7 queues hit.
    expect(ApplicationAssignment::where('application_id', $appId)->count())->toBe(7);
});

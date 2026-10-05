<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Services\WorkflowService;
use Carbon\Carbon;

/*
 * A bill follows the owner's corrections (Ken, 5 October 2026).
 *
 * The fee was assessed once, at submission, and nothing assessed it again. So
 * when BPLO returned a filing because its figures looked wrong and the owner
 * corrected them, the Tax Order of Payment kept the first figures: a renewal
 * corrected from ₱200,000 to ₱5,000,000 of sales stayed billed at ₱12,200
 * where a fresh filing declaring the same was ₱42,987.50 (scenario run,
 * owner-renew 27), and a new filing's corrected floor area and receipts never
 * reached the bill the fee preview was already quoting (owner-pay 13).
 */

it('re-bills a returned new filing whose fee profile was corrected', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $app = Application::create([
        'business_id' => $business->id, 'applicant_user_id' => $owner->id,
        'application_type' => 'new', 'status' => 'draft',
        'fee_profile' => ['floor_area_sqm' => 20, 'lines' => [['category' => 'retailer', 'gross_receipts' => 100_000]]],
    ]);
    app(WorkflowService::class)->submit($app);
    $before = (float) $app->fresh()->feeAssessment->total_amount;

    app(WorkflowService::class)->returnMainForm($app->fresh(), 'Gross receipts and floor area look wrong.', 'form:lines');

    $corrected = ['floor_area_sqm' => 400, 'lines' => [['category' => 'retailer', 'gross_receipts' => 5_000_000]]];
    authAs('owner@biztrack.local');
    $this->putJson("/api/v1/applications/{$app->id}", ['fee_profile' => $corrected])->assertOk();
    $preview = (float) $this->postJson("/api/v1/applications/{$app->id}/fee-preview", ['fee_profile' => $corrected])
        ->assertOk()->json('data.total_amount');
    $this->postJson("/api/v1/applications/{$app->id}/resubmit")->assertOk();
    $reassessed = (float) $app->fresh()->feeAssessment->total_amount;

    // Since 5 October 2026 a new filing is priced over the Business Permit
    // alone until BPLO ticks its other permits at approval, and their fees
    // join the bill then. So the preview is the Business Permit's share of
    // the bill, not all of it.
    bploApprovesForm($app->id);
    $businessPermitShare = collect($app->fresh()->feeAssessment->line_items)
        ->filter(fn (array $line) => $line['permit_codes'] === [PermitType::OUTCOME_CODE])
        ->sum('amount');

    expect($preview)->not->toBe($before)
        ->and($reassessed)->toBe($preview)
        ->and((float) $businessPermitShare)->toBe($preview);
});

it('re-bills a returned renewal whose gross sales were corrected', function () {
    $this->travelTo(Carbon::parse('2027-01-05 02:00:00'));
    $profile = fn (int $sales) => ['fee_profile' => [
        'gross_sales' => $sales,
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'gross_sales' => $sales]],
    ]];

    $renewal = function (array $extra) {
        authAs('owner@biztrack.local');
        $businessId = test()->postJson('/api/v1/businesses', [
            'name' => 'Corrected Sales '.random_int(10000, 99999),
            'registration_type' => 'DTI',
            'registration_number' => 'DTI-'.random_int(10000, 99999),
            'tin' => '123-456-789-000',
            'address' => ['line1' => '9 Renewal Road', 'barangay_id' => Barangay::first()->id],
            'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 300000]],
        ])->assertCreated()->json('data.id');
        $permit = Permit::create([
            'business_id' => $businessId,
            'permit_type_id' => PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
            'permit_number' => 'RSB-'.random_int(100000, 999999),
            'issued_at' => '2026-01-21', 'valid_from' => '2026-01-21', 'valid_until' => '2026-12-31',
            'status' => 'active',
        ]);

        return test()->postJson('/api/v1/applications', [
            'business_id' => $businessId,
            'data_privacy_consent' => true,
            'application_type' => 'renewal',
            'prior_permit_ids' => [$permit->id],
            ...$extra,
        ])->assertCreated()->json('data.id');
    };

    // What a renewal declaring ₱5,000,000 is billed when filed that way.
    $control = $renewal($profile(5_000_000));
    attachRequiredDocuments($control);
    $this->postJson("/api/v1/applications/{$control}/submit")->assertOk();
    $expected = (float) Application::find($control)->feeAssessment->total_amount;

    $appId = $renewal($profile(200_000));
    attachRequiredDocuments($appId);
    $this->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    $first = (float) Application::find($appId)->feeAssessment->total_amount;
    expect($first)->not->toBe($expected);

    app(WorkflowService::class)->returnMainForm(Application::find($appId), 'Gross sales look too low.');
    authAs('owner@biztrack.local');
    $this->putJson("/api/v1/applications/{$appId}", $profile(5_000_000))->assertOk();
    $this->postJson("/api/v1/applications/{$appId}/resubmit")->assertOk();
    bploApprovesForm($appId);

    authAs('owner@biztrack.local');
    expect((float) Application::find($appId)->feeAssessment->total_amount)->toBe($expected)
        ->and((float) $this->getJson("/api/v1/applications/{$appId}/fee")->json('data.total_amount'))->toBe($expected);
});

it('leaves the bill as it was when a returned filing comes back unchanged', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $app = Application::create([
        'business_id' => $business->id, 'applicant_user_id' => $owner->id,
        'application_type' => 'new', 'status' => 'draft',
        'fee_profile' => ['floor_area_sqm' => 20, 'lines' => [['category' => 'retailer', 'gross_receipts' => 100_000]]],
    ]);
    app(WorkflowService::class)->submit($app);
    $before = $app->fresh()->feeAssessment;

    app(WorkflowService::class)->returnMainForm($app->fresh(), 'Please re-upload the DTI certificate.');
    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/resubmit")->assertOk();

    $after = $app->fresh()->feeAssessment;
    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval)
        ->and((float) $after->total_amount)->toBe((float) $before->total_amount)
        ->and($after->line_items)->toBe($before->line_items);
});

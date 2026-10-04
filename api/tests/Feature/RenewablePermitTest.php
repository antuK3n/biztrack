<?php

use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use Carbon\Carbon;

/*
 * Which permits a renewal may name (App\Support\RenewablePermit).
 *
 * Found by the scenario run of owner-renew: the picker hid revoked and
 * superseded permits, and the API took them anyway. Each renewal was billed,
 * paid and issued, so the business ended up holding a fresh Active permit for
 * a certificate the city had taken away, or two Active permits for one term.
 */

beforeEach(function () {
    // January: the only month a business permit renewal is accepted.
    $this->travelTo(Carbon::parse('2027-01-05 02:00:00'));
});

/** A business of the seeded owner, built through the API. */
function rnpBusiness(): int
{
    authAs('owner@biztrack.local');

    return test()->postJson('/api/v1/businesses', [
        'name' => 'Renewable Store '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'trade_name' => 'Renewable Sign',
        'address' => ['line1' => '9 Renewal Road', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 300000]],
    ])->assertCreated()->json('data.id');
}

/** The business permit it held for 2026, in whatever state the test needs. */
function rnpPermit(int $businessId, PermitStatus $status = PermitStatus::Active): Permit
{
    return Permit::create([
        'application_id' => Application::create([
            'business_id' => $businessId,
            'applicant_user_id' => Business::find($businessId)->owner_user_id,
            'application_type' => 'new',
            'status' => 'approved',
            'submitted_at' => '2025-12-21',
        ])->id,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
        'permit_number' => 'RNP-'.random_int(100000, 999999),
        'issued_at' => '2026-01-21',
        'valid_from' => '2026-01-21',
        'valid_until' => '2027-01-20',
        'status' => $status,
    ]);
}

function rnpRenew(int $businessId, Permit $permit)
{
    authAs('owner@biztrack.local');

    return test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'renewal',
        'prior_permit_ids' => [$permit->id],
    ]);
}

function rnpSubmit(int $appId)
{
    authAs('owner@biztrack.local');

    return test()->postJson("/api/v1/applications/{$appId}/submit");
}

function rnpPay(int $appId)
{
    authAs('owner@biztrack.local');

    return test()->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash']);
}

it('refuses to start a renewal of a revoked permit', function () {
    $businessId = rnpBusiness();
    $revoked = rnpPermit($businessId, PermitStatus::Revoked);

    rnpRenew($businessId, $revoked)
        ->assertStatus(422)
        ->assertJsonPath('errors.prior_permit_id.0', 'This permit was revoked, so it can’t be renewed.');

    expect(Application::where('prior_permit_id', $revoked->id)->exists())->toBeFalse();
});

it('refuses to submit a renewal draft whose permit was revoked after it was started', function () {
    $businessId = rnpBusiness();
    $permit = rnpPermit($businessId);
    $appId = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');

    $permit->update(['status' => PermitStatus::Revoked, 'revoked_at' => now(), 'revoked_reason' => 'Closure order.']);

    rnpSubmit($appId)
        ->assertStatus(422)
        ->assertJsonPath('errors.prior_permit_id.0', 'This permit was revoked, so it can’t be renewed.');
});

it('refuses to renew a permit a paid renewal already superseded', function () {
    $businessId = rnpBusiness();
    $permit = rnpPermit($businessId);

    $first = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');
    rnpSubmit($first)->assertOk();
    bploApprovesForm($first);
    rnpPay($first)->assertCreated();
    expect($permit->fresh()->status)->toBe(PermitStatus::Superseded);

    // A stale tab still names the old permit.
    rnpRenew($businessId, $permit)
        ->assertStatus(422)
        ->assertJsonPath('errors.prior_permit_id.0', 'This permit has already been renewed, so it can’t be renewed again.');

    expect(Permit::where('business_id', $businessId)->where('status', PermitStatus::Active)->count())->toBe(1);
});

it('refuses to switch a renewal draft onto a revoked permit through the prior-permit door', function () {
    $businessId = rnpBusiness();
    $permit = rnpPermit($businessId);
    $revoked = rnpPermit($businessId, PermitStatus::Revoked);
    $appId = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');

    authAs('owner@biztrack.local');
    $this->putJson("/api/v1/applications/{$appId}/prior-permit", ['prior_permit_id' => $revoked->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.prior_permit_id.0', 'This permit was revoked, so it can’t be renewed.');

    expect(Application::find($appId)->prior_permit_id)->toBe($permit->id);
});

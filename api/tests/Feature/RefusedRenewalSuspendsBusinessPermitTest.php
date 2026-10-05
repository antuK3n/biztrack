<?php

use App\Enums\ClearanceStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use Carbon\Carbon;

/*
 * A refused one-permit renewal suspends the Business Permit (owner-renew 35).
 *
 * Since 5 October 2026 a renewal carries one permit, so a Sanitary renewal is
 * its own filing with no Business Permit on it, and an office refusing it
 * suspended nothing: the refusal looked for a certificate on its own filing.
 * Ken: it suspends the business's live Business Permit, the way a refusal on
 * one filing always did, and the certificate comes back when that permit is
 * applied for again and passes.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2027-01-05 02:00:00'));
});

/** An owner's business, with a live Business Permit and a Sanitary Permit due. */
function rrsBusiness(): array
{
    authAs('owner@biztrack.local');
    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Refused Renewal Store '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Renewal Road', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 300000]],
    ])->assertCreated()->json('data.id');

    $permit = function (string $code, string $status, string $from, string $until) use ($businessId): Permit {
        $appId = Application::create([
            'business_id' => $businessId,
            'applicant_user_id' => Business::find($businessId)->owner_user_id,
            'application_type' => 'new',
            'status' => 'approved',
            'decided_at' => Carbon::parse($from),
            'submitted_at' => Carbon::parse($from)->subMonth(),
        ])->id;
        Application::find($appId)->permitTypes()->attach(PermitType::where('code', $code)->value('id'), ['status' => 'approved']);

        return Permit::create([
            'application_id' => $appId,
            'business_id' => $businessId,
            'permit_type_id' => PermitType::where('code', $code)->value('id'),
            'permit_number' => 'RRS-'.$code.'-'.random_int(100000, 999999),
            'issued_at' => $from,
            'valid_from' => $from,
            'valid_until' => $until,
            'status' => $status,
        ]);
    };

    return [
        $businessId,
        $permit('BUSINESS', 'active', '2026-01-21', '2027-01-20'),
        $permit('SANITARY', 'expired', '2026-01-02', '2026-12-31'),
    ];
}

/** File the Sanitary renewal on its own, and have City Health refuse it after the visit. */
function rrsRefusedSanitaryRenewal(int $businessId, Permit $sanitary): Application
{
    authAs('owner@biztrack.local');
    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'renewal',
        'prior_permit_ids' => [$sanitary->id],
        'permit_type_ids' => PermitType::where('code', 'SANITARY')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');
    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();

    $app = Application::findOrFail($appId);
    $cho = $app->assignments()->where('department_id', PermitType::where('code', 'SANITARY')->value('issuing_department_id'))->value('id');
    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$cho}/approve")->assertOk();
    test()->postJson("/api/v1/assignments/{$cho}/reject", [
        'reason' => 'No potable water.',
        'remedy' => 'Connect to the mains, then apply again.',
    ])->assertOk();

    return $app->fresh();
}

/** Apply for the refused Sanitary Permit again, and pass it. */
function rrsReapplyAndPass(Application $app): void
{
    authAs('owner@biztrack.local');
    test()->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/apply")->assertOk();
    satisfyChecklist($app->fresh(), PermitType::where('code', 'SANITARY')->firstOrFail());
    test()->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", ['form_data' => [], 'submit' => true])
        ->assertSuccessful();

    $cho = $app->assignments()->where('department_id', PermitType::where('code', 'SANITARY')->value('issuing_department_id'))->value('id');
    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$cho}/approve")->assertOk();
    $visitId = test()->postJson("/api/v1/applications/{$app->id}/permits/SANITARY/inspection", [
        'scheduled_at' => now()->toDateTimeString(),
    ])->assertCreated()->json('data.id');
    test()->postJson("/api/v1/inspections/{$visitId}/conduct", ['result' => 'passed'])->assertOk();
}

it('suspends the live Business Permit when an office refuses a one-permit renewal', function () {
    [$businessId, $businessPermit, $sanitary] = rrsBusiness();

    $app = rrsRefusedSanitaryRenewal($businessId, $sanitary);

    expect($app->permitTypes->firstWhere('code', 'SANITARY')->pivot->status)->toBe(ClearanceStatus::Rejected)
        ->and($businessPermit->fresh()->status)->toBe(PermitStatus::Suspended);
});

it('restores the Business Permit when that permit is applied for again and passes', function () {
    [$businessId, $businessPermit, $sanitary] = rrsBusiness();
    $app = rrsRefusedSanitaryRenewal($businessId, $sanitary);
    expect($businessPermit->fresh()->status)->toBe(PermitStatus::Suspended);

    rrsReapplyAndPass($app);

    expect($app->fresh()->permitTypes->firstWhere('code', 'SANITARY')->pivot->status)->toBe(ClearanceStatus::Approved)
        ->and($businessPermit->fresh()->status)->toBe(PermitStatus::Active);
});

it('keeps the Business Permit suspended while the business itself is suspended', function () {
    /*
     * The super admin's suspension holds every permit the business has
     * (`suspendPermitsForBusiness`), and an office passing a visit does not
     * lift it. Reinstating the business then finds nothing refused, and the
     * certificate comes back.
     */
    [$businessId, $businessPermit, $sanitary] = rrsBusiness();
    $app = rrsRefusedSanitaryRenewal($businessId, $sanitary);

    authAs('admin@biztrack.local');
    test()->postJson("/api/v1/admin/businesses/{$businessId}/status", ['status' => 'suspended', 'reason' => 'Inspection.'])->assertOk();
    expect($businessPermit->fresh()->status)->toBe(PermitStatus::Suspended);

    // Reinstated while the refusal still stands: still suspended for it.
    authAs('admin@biztrack.local');
    test()->postJson("/api/v1/admin/businesses/{$businessId}/status", ['status' => 'active', 'reason' => 'Cleared.'])->assertOk();
    expect($businessPermit->fresh()->status)->toBe(PermitStatus::Suspended);

    rrsReapplyAndPass($app);
    expect($businessPermit->fresh()->status)->toBe(PermitStatus::Active);
});

it('keeps the Business Permit suspended while a second renewal’s refusal stands', function () {
    [$businessId, $businessPermit, $sanitary] = rrsBusiness();
    $first = rrsRefusedSanitaryRenewal($businessId, $sanitary);

    // Another open one-permit renewal of this business, refused too.
    $second = Application::create([
        'business_id' => $businessId,
        'applicant_user_id' => Business::find($businessId)->owner_user_id,
        'application_type' => 'renewal',
        'status' => 'for_approval',
        'submitted_at' => now(),
    ]);
    $second->permitTypes()->attach(PermitType::where('code', 'FSIC')->value('id'), ['status' => ClearanceStatus::Rejected->value]);

    rrsReapplyAndPass($first);

    expect($businessPermit->fresh()->status)->toBe(PermitStatus::Suspended);
});

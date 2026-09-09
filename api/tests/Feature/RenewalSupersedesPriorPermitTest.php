<?php

use App\Enums\ApplicationStatus;
use App\Enums\InspectionResult;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;

/*
 * What a renewal does to the certificate it replaces, and when the new one
 * starts.
 *
 * Three defects found by walking the lifecycle end to end on 9 September 2026,
 * all of them invisible from any single screen because each needs a SECOND
 * filing against the same business before it shows:
 *
 *   1. The old permit stayed `active`. Renewing a sanitary permit sixty days
 *      early left MCS-2025-000099 (to 7 November) and MCS-2026-000001 (to next
 *      September) both live — both on the applicant's profile, both offered by
 *      the renewal picker, so the following year they could renew the one that
 *      had already been replaced.
 *
 *   2. The new permit started today. Those sixty days of cover the applicant
 *      had already paid for were simply lost, which penalised renewing early —
 *      the opposite of what a renewal season is for.
 *
 *   3. `Permit::booted` linked every permit a filing issued to the renewal's
 *      PRIMARY prior permit, checking same-business and not same-type. A
 *      two-permit renewal therefore recorded a zoning certificate as the
 *      successor of a sanitary one. Worse than a missing link: RenewalOutcomes
 *      reads the chain to decide whether a renewal was late, so the model was
 *      being fitted on two different permits' dates.
 */

/** A business holding one live permit of each given code, staggered. */
function heldPermits(array $codes, int $daysToFirstExpiry = 60): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    $permits = [];
    foreach ($codes as $i => $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $permits[$code] = Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $business->id,
            'permit_type_id' => $type->id,
            'permit_number' => $type->permit_number_prefix.'-2025-'
                .str_pad((string) (Permit::max('id') + $i + 1), 6, '0', STR_PAD_LEFT),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays($daysToFirstExpiry + ($i * 30)),
            'status' => 'active',
        ]);
    }

    return [$business, $permits];
}

/** Drive a renewal of `$codes` all the way to issuance. */
function issuedRenewal(array $codes, array $prior, int $businessId): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $workflow = app(WorkflowService::class);

    $app = Application::create([
        'business_id' => $businessId,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $prior[$codes[0]]->id,
    ]);
    $app->priorPermits()->sync(collect($prior)->only($codes)->pluck('id')->all());
    $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id')->all());

    $workflow->submit($app);
    $app->refresh();
    classifyAsOfficer($app);
    $workflow->approveMainForm($app->fresh());
    $app->refresh();
    $workflow->transition($app, ApplicationStatus::AwaitingOtherPermits, 'Paid.');

    foreach ($codes as $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $workflow->startClearance($app->fresh(), $type, ApplicationPermitType::MODE_APPLY);
        $workflow->submitClearanceForm($app->fresh(), $type);
        $row = $workflow->pivotFor($app->fresh(), $code);
        $workflow->approveClearance($row, 'Accepted.');
        $inspection = $workflow->scheduleClearanceInspection($row->fresh(), now()->addDay());
        $workflow->recordInspection($inspection, InspectionResult::Passed, 'Compliant.');
    }

    $workflow->approveOverall($app->fresh(), 'Renewal approved.');

    return $app->fresh();
}

it('retires the permit a renewal replaces', function () {
    [$business, $prior] = heldPermits(['SANITARY'], daysToFirstExpiry: 60);

    issuedRenewal(['SANITARY'], $prior, $business->id);

    expect($prior['SANITARY']->fresh()->status)->toBe(PermitStatus::Superseded);

    // One live sanitary permit, not two.
    $live = Permit::where('business_id', $business->id)
        ->where('permit_type_id', $prior['SANITARY']->permit_type_id)
        ->where('status', PermitStatus::Active->value)
        ->get();
    expect($live)->toHaveCount(1);
});

it('keeps the retired permit’s real expiry date', function () {
    /*
     * `Superseded`, never `Expired` with `valid_until` rewritten to today. The
     * expiry analytics and the renewal-risk model both read these dates, and a
     * permit recorded as lapsing two months before it did is a permit the model
     * learns the wrong thing from.
     */
    [$business, $prior] = heldPermits(['SANITARY'], daysToFirstExpiry: 60);
    $realExpiry = $prior['SANITARY']->valid_until->toDateString();

    issuedRenewal(['SANITARY'], $prior, $business->id);

    expect($prior['SANITARY']->fresh()->valid_until->toDateString())->toBe($realExpiry);
});

it('starts the renewed permit the day the old one ends', function () {
    [$business, $prior] = heldPermits(['SANITARY'], daysToFirstExpiry: 60);
    $old = $prior['SANITARY'];

    $app = issuedRenewal(['SANITARY'], $prior, $business->id);
    $new = $app->permits()->firstOrFail();

    expect($new->valid_from->toDateString())
        ->toBe($old->valid_until->copy()->addDay()->toDateString());

    // And the full term is granted from there — nothing is lost to renewing early.
    expect($new->valid_until->toDateString())
        ->toBe($old->valid_until->copy()->addDay()->addDays(365)->toDateString());
});

it('starts today when the permit being renewed has already lapsed', function () {
    /*
     * No unexpired term to continue, and back-dating one would invent cover for
     * a period the business traded without a permit.
     */
    [$business, $prior] = heldPermits(['SANITARY']);
    $prior['SANITARY']->update([
        'valid_until' => now()->subDays(30),
        'status' => PermitStatus::Expired,
    ]);

    $app = issuedRenewal(['SANITARY'], $prior, $business->id);

    expect($app->permits()->firstOrFail()->valid_from->toDateString())
        ->toBe(now()->toDateString());

    // An expired permit is left expired: that status says something the
    // renewal does not undo.
    expect($prior['SANITARY']->fresh()->status)->toBe(PermitStatus::Expired);
});

it('links each renewed permit to its OWN predecessor, not the primary', function () {
    [$business, $prior] = heldPermits(['SANITARY', 'ZONING']);

    // SANITARY is the primary — it is what `prior_permit_id` on the filing holds.
    $app = issuedRenewal(['SANITARY', 'ZONING'], $prior, $business->id);

    $issued = $app->permits()->with('permitType')->get()->keyBy('permitType.code');

    expect($issued['SANITARY']->prior_permit_id)->toBe($prior['SANITARY']->id)
        ->and($issued['ZONING']->prior_permit_id)->toBe($prior['ZONING']->id);

    // Both predecessors retired, not just the primary.
    expect($prior['SANITARY']->fresh()->status)->toBe(PermitStatus::Superseded)
        ->and($prior['ZONING']->fresh()->status)->toBe(PermitStatus::Superseded);
});

it('leaves a superseded permit out of the renewal picker', function () {
    [$business, $prior] = heldPermits(['SANITARY']);
    issuedRenewal(['SANITARY'], $prior, $business->id);

    $owner = authAs('owner@biztrack.local');
    $offered = $this->withHeaders($owner)
        ->getJson("/api/v1/businesses/{$business->id}/prefill?type=renewal")
        ->assertOk()
        ->json('data.renewable_permits');

    $numbers = collect($offered)->pluck('permit_number');
    expect($numbers)->not->toContain($prior['SANITARY']->permit_number);
});

it('does not verify a superseded permit as valid', function () {
    [$business, $prior] = heldPermits(['SANITARY']);
    issuedRenewal(['SANITARY'], $prior, $business->id);

    $this->getJson('/api/v1/verify/'.$prior['SANITARY']->permit_number)
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.status_label', 'Superseded');
});

it('leaves a new application’s permits dated from today', function () {
    // Nothing above may leak into a first filing: there is no predecessor, so
    // the term runs from issuance exactly as it always did.
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);

    $workflow = app(WorkflowService::class);
    $workflow->submit($app);
    $app->refresh();
    classifyAsOfficer($app);
    $workflow->approveMainForm($app->fresh());
    $app->refresh();
    $workflow->transition($app, ApplicationStatus::AwaitingOtherPermits, 'Paid.');

    foreach (PermitType::REQUIRED_CLEARANCE_CODES as $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $workflow->startClearance($app->fresh(), $type, ApplicationPermitType::MODE_APPLY);
        $workflow->submitClearanceForm($app->fresh(), $type);
        $row = $workflow->pivotFor($app->fresh(), $code);
        $workflow->approveClearance($row, 'Accepted.');
        $inspection = $workflow->scheduleClearanceInspection($row->fresh(), now()->addDay());
        $workflow->recordInspection($inspection, InspectionResult::Passed, 'Compliant.');
    }
    $workflow->approveOverall($app->fresh(), 'Approved.');

    foreach ($app->fresh()->permits as $permit) {
        expect($permit->valid_from->toDateString())->toBe(now()->toDateString());
    }
});

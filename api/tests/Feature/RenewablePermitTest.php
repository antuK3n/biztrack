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

/*
 * ── One renewal in progress per permit (Ken, 5 October 2026) ────────────────
 *
 * While a renewal sat open the picker still offered its permit, and a second
 * renewal of it submitted, billed and paid: ₱12,850 collected for one term and
 * two Active business permits for 2027 (scenario run, owner-renew 20).
 */

it('refuses a second renewal of a permit while one is in progress', function () {
    $businessId = rnpBusiness();
    $permit = rnpPermit($businessId);
    $first = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');
    rnpSubmit($first)->assertOk();

    rnpRenew($businessId, $permit)
        ->assertStatus(422)
        ->assertJsonPath('errors.prior_permit_id.0', 'This permit already has a renewal in progress.');
});

it('lets two drafts of one permit coexist, and submits only the first', function () {
    $businessId = rnpBusiness();
    $permit = rnpPermit($businessId);
    $first = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');
    $second = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');

    rnpSubmit($first)->assertOk();

    rnpSubmit($second)
        ->assertStatus(422)
        ->assertJsonPath('errors.prior_permit_id.0', 'This permit already has a renewal in progress.');
});

it('counts a permit carried in a renewal’s set, not only its primary', function () {
    $businessId = rnpBusiness();
    $permit = rnpPermit($businessId);
    $sanitary = Permit::create([
        'application_id' => $permit->application_id,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', 'SANITARY')->value('id'),
        'permit_number' => 'RNP-SAN-'.random_int(100000, 999999),
        'issued_at' => '2026-01-02',
        'valid_from' => '2026-01-02',
        'valid_until' => '2026-12-31',
        'status' => PermitStatus::Expired,
    ]);

    authAs('owner@biztrack.local');
    $first = $this->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'renewal',
        'prior_permit_ids' => [$permit->id, $sanitary->id],
    ])->assertCreated()->json('data.id');
    rnpSubmit($first)->assertOk();

    rnpRenew($businessId, $sanitary)
        ->assertStatus(422)
        ->assertJsonPath('errors.prior_permit_id.0', 'This permit already has a renewal in progress.');
});

it('frees the permit again once its renewal is cancelled', function () {
    $businessId = rnpBusiness();
    $permit = rnpPermit($businessId);
    $first = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');
    rnpSubmit($first)->assertOk();

    authAs('owner@biztrack.local');
    $this->postJson("/api/v1/applications/{$first}/cancel")->assertOk();

    $second = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');
    rnpSubmit($second)->assertOk();
});

it('tells the picker which permits already have a renewal in progress', function () {
    $businessId = rnpBusiness();
    $permit = rnpPermit($businessId);

    authAs('owner@biztrack.local');
    expect($this->getJson("/api/v1/businesses/{$businessId}/prefill?type=renewal")->assertOk()
        ->json('data.renewal_in_progress_permit_ids'))->toBe([]);

    // A draft is not a renewal in progress; a submitted one is.
    $first = rnpRenew($businessId, $permit)->assertCreated()->json('data.id');
    authAs('owner@biztrack.local');
    expect($this->getJson("/api/v1/businesses/{$businessId}/prefill?type=renewal")
        ->json('data.renewal_in_progress_permit_ids'))->toBe([]);

    rnpSubmit($first)->assertOk();
    authAs('owner@biztrack.local');
    $prefill = $this->getJson("/api/v1/businesses/{$businessId}/prefill?type=renewal")->assertOk();

    // Still listed, so the picker can show it greyed out rather than lose it.
    expect(collect($prefill->json('data.renewable_permits'))->pluck('id')->all())->toContain($permit->id)
        ->and($prefill->json('data.renewal_in_progress_permit_ids'))->toBe([$permit->id]);
});

/*
 * ── An expired permit that was already renewed (Ken, 5 October 2026) ───────
 *
 * Renewing a permit that has already lapsed leaves it Expired, not Superseded
 * — its term ran out on its own (see PermitStatus::Superseded) — so the
 * superseded check above never saw it. The picker kept offering it and the
 * API took it, and the business could renew the same lapsed certificate
 * again. The renewal chain (`permits.prior_permit_id`) is what says it was
 * renewed, and it is now read like the superseded status.
 */

/** A lapsed sanitary permit, renewed: the new one names it as its prior. */
function rnpLapsedAndRenewed(int $businessId): Permit
{
    $type = PermitType::where('code', 'SANITARY')->value('id');
    $lapsed = Permit::create([
        'business_id' => $businessId,
        'permit_type_id' => $type,
        'permit_number' => 'RNP-OLD-'.random_int(100000, 999999),
        'issued_at' => '2025-01-02', 'valid_from' => '2025-01-02', 'valid_until' => '2025-12-31',
        'status' => PermitStatus::Expired,
    ]);
    Permit::create([
        'business_id' => $businessId,
        'permit_type_id' => $type,
        'prior_permit_id' => $lapsed->id,
        'permit_number' => 'RNP-NEW-'.random_int(100000, 999999),
        'issued_at' => '2026-12-01', 'valid_from' => '2026-12-01', 'valid_until' => '2027-12-31',
        'status' => PermitStatus::Active,
    ]);

    return $lapsed;
}

it('refuses to renew an expired permit a renewal already replaced', function () {
    $businessId = rnpBusiness();
    $lapsed = rnpLapsedAndRenewed($businessId);

    rnpRenew($businessId, $lapsed)
        ->assertStatus(422)
        ->assertJsonPath('errors.prior_permit_id.0', 'This permit has already been renewed, so it can’t be renewed again.');
});

it('does not offer an expired permit a renewal already replaced, and still offers one that was not', function () {
    $businessId = rnpBusiness();
    $lapsed = rnpLapsedAndRenewed($businessId);
    $neverRenewed = Permit::create([
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', 'FSIC')->value('id'),
        'permit_number' => 'RNP-FSIC-'.random_int(100000, 999999),
        'issued_at' => '2025-01-02', 'valid_from' => '2025-01-02', 'valid_until' => '2026-12-31',
        'status' => PermitStatus::Expired,
    ]);

    authAs('owner@biztrack.local');
    $offered = collect($this->getJson("/api/v1/businesses/{$businessId}/prefill?type=renewal")->assertOk()
        ->json('data.renewable_permits'))->pluck('id');

    expect($offered)->not->toContain($lapsed->id)
        ->and($offered)->toContain($neverRenewed->id);
});

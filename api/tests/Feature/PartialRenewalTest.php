<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Services\ClearanceService;
use App\Services\WorkflowService;

/*
 * A renewal renews what the applicant ticked, and nothing else.
 *
 * The client, 9 September 2026: *"not all permits MAY NOT be renewed in one go
 * (unlike in the application where everything is applied in one go), especially
 * with the fact that they have different expirations."*
 *
 * ── What this is guarding, measured ───────────────────────────────────────
 *
 * `attachRequiredPermitTypes` ran over every filing and attached the business
 * permit plus all five required clearances. On a NEW application that is right
 * — rule 1 of docs/application-flow-2026-09.md — and on a renewal it was this:
 *
 *     BEFORE submit: BUSINESS,SANITARY
 *     AFTER submit:  BUSINESS,SANITARY,FSIC,OCCUPANCY,CEC,ZONING
 *
 * A shop renewing one expiring permit was billed for six, had to complete five
 * office forms it had not asked for, and could not be approved until five
 * offices it had no business with had signed off. None of that is visible from
 * any single screen, which is why it survived: the wizard showed two permits,
 * the applicant pressed Submit, and the expansion happened afterwards.
 */

/** A business holding permits of every given code, all issued last year. */
function businessHoldingPermits(array $codes): array
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
            // Unique across repeated calls: two filings in one test each need
            // their own prior permits, and `permit_number` is unique.
            'permit_number' => $type->permit_number_prefix.'-2025-'
                .str_pad((string) (Permit::max('id') + $i + 1), 6, '0', STR_PAD_LEFT),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            // Deliberately staggered: the whole point is that they do not all
            // fall due together.
            'valid_until' => now()->addDays(20 + ($i * 60)),
            'status' => 'active',
        ]);
    }

    return [$business, $permits];
}

/** A submitted renewal covering exactly `$codes`. */
function renewalOf(array $codes): Application
{
    [$business, $permits] = businessHoldingPermits($codes);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $permits[$codes[0]]->id,
    ]);
    $app->priorPermits()->sync(collect($permits)->pluck('id')->all());
    $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id')->all());

    app(WorkflowService::class)->submit($app->fresh());

    return $app->fresh();
}

function codesOn(Application $app): array
{
    return $app->permitTypes()->pluck('code')->sort()->values()->all();
}

it('keeps a renewal to the permits that were ticked', function () {
    $app = renewalOf(['SANITARY']);

    expect(codesOn($app))->toBe(['SANITARY']);
});

it('does not force the Mayor’s Permit onto a renewal that left it out', function () {
    /*
     * The client chose this explicitly over the alternative — "Any permit,
     * freely" rather than "Business permit always included". A shop whose
     * Mayor's Permit runs to December and whose Sanitary Permit lapses in
     * September files for the sanitary permit alone, and re-issuing the business
     * permit eight months early would shorten the cover they already paid for.
     */
    $app = renewalOf(['SANITARY', 'FSIC']);

    expect(codesOn($app))->toBe(['FSIC', 'SANITARY'])
        ->and(codesOn($app))->not->toContain('BUSINESS');
});

it('still attaches all six to a new application', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);
    $app->permitTypes()->sync(PermitType::where('code', 'BUSINESS')->pluck('id')->all());

    app(WorkflowService::class)->submit($app->fresh());

    expect(codesOn($app->fresh()))
        ->toBe(['BUSINESS', 'CEC', 'FSIC', 'OCCUPANCY', 'SANITARY', 'ZONING']);
});

it('attaches a ticked permit’s type even when the caller forgot to', function () {
    /*
     * The wizard sends `permit_type_ids`; the server does not depend on it.
     * A filing that named the permit but never carried its type would renew
     * nothing — no office form, no assignment, no fee, no certificate — and the
     * applicant would have no way to tell from the screen.
     */
    [$business, $permits] = businessHoldingPermits(['SANITARY']);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $permits['SANITARY']->id,
    ]);
    $app->priorPermits()->sync([$permits['SANITARY']->id]);
    // No permitTypes()->sync at all — the caller only named the prior permit.

    app(WorkflowService::class)->submit($app->fresh());

    expect(codesOn($app->fresh()))->toBe(['SANITARY']);
});

it('gives every attached row a starting status', function () {
    $app = renewalOf(['SANITARY', 'ZONING']);

    $app->load('permitTypes');
    foreach ($app->permitTypes as $type) {
        expect($type->pivot->status)->toBe(ClearanceStatus::NotStarted);
    }
});

it('bills a partial renewal for the permits it carries and no others', function () {
    $one = renewalOf(['SANITARY']);
    $two = renewalOf(['SANITARY', 'FSIC', 'ZONING']);

    $one->load('feeAssessment');
    $two->load('feeAssessment');

    /*
     * Not an exact peso figure — the revenue-code rules own that, and pinning
     * one here would make this test fail every time the ordinance is corrected.
     * What must hold is the RELATIONSHIP: three clearances cost more than one,
     * and the one-permit filing is not being charged for the other two.
     */
    expect((float) $two->feeAssessment->total_amount)
        ->toBeGreaterThan((float) $one->feeAssessment->total_amount);
});

it('shows the clearance stage only the permits the renewal is for', function () {
    $app = renewalOf(['SANITARY', 'ZONING']);

    $rows = app(ClearanceService::class)->overview($app)['rows'];

    expect(collect($rows)->pluck('permit_type.code')->sort()->values()->all())
        ->toBe(['SANITARY', 'ZONING']);
});

it('waits for final approval on the ticked permits alone', function () {
    /*
     * `refreshReadiness` intersects the required-clearance constant with what
     * the filing carries, so a renewal of two is ready when those two are
     * approved — it must not sit in AwaitingOtherPermits waiting on three
     * clearances that were never part of it.
     */
    $app = renewalOf(['SANITARY', 'ZONING']);
    $workflow = app(WorkflowService::class);

    classifyAsOfficer($app);
    $workflow->approveMainForm($app->fresh());
    $app->refresh();
    $workflow->transition($app, ApplicationStatus::AwaitingOtherPermits, 'Paid.');

    foreach (['SANITARY', 'ZONING'] as $code) {
        $row = $workflow->pivotFor($app->fresh(), $code);
        $row->forceFill(['status' => ClearanceStatus::Approved, 'decided_at' => now()])->save();
    }

    $workflow->refreshReadiness($app->fresh());

    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval);
});

it('leaves the amendment questions off a renewal', function () {
    /*
     * The client: "REMOVE THE AMENDMENT PART on the BPLO renewal part."
     *
     * Through the endpoint rather than the model, because the removal is in
     * `amendmentAttributes` and the point is that a caller SENDING the old
     * fields no longer has them stored. A renewal that still recorded them
     * would leave BPLO reading a second answer to a question the form stopped
     * asking.
     */
    [$business, $permits] = businessHoldingPermits(['SANITARY']);
    $owner = authAs('owner@biztrack.local');

    $id = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'application_type' => 'renewal',
        'data_privacy_consent' => true,
        'permit_type_ids' => PermitType::where('code', 'SANITARY')->pluck('id')->all(),
        'prior_permit_id' => $permits['SANITARY']->id,
        'amendment_ownership' => true,
        'amendment_location' => true,
        'amendment_other' => 'Changed the shop sign',
    ])->assertCreated()->json('data.id');

    $app = Application::findOrFail($id);

    expect($app->application_type)->toBe(ApplicationType::Renewal)
        ->and($app->has_amendments)->toBeFalse()
        ->and($app->amendment_ownership)->toBeFalse()
        ->and($app->amendment_location)->toBeFalse()
        ->and($app->amendment_other)->toBeNull();
});

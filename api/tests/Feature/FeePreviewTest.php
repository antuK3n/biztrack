<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\FeeAssessment;
use App\Models\PermitType;
use App\Models\User;

/*
 * The fee ESTIMATE the wizard's tax step shows.
 *
 * The step asked for a tax bracket, gross sales, a storey count and a set of
 * flags and showed the applicant nothing back — the amount appeared only after
 * BPLO approved the form. The client asked why the step existed at all, which
 * is the fair reaction to data entry with no visible output.
 *
 * Two things have to hold for the estimate to be worth showing, and both are
 * easy to lose:
 *
 *   1. It must price the SAME permits the bill will. A new filing carries only
 *      the business permit while the wizard is open, so an estimate assessed
 *      over the draft as it stands would quote one permit and omit five
 *      clearances the applicant is about to pay for.
 *
 *   2. It must persist NOTHING. `GET /fee` calls assessFees, which does
 *      FeeAssessment::updateOrCreate; polling that while somebody types would
 *      leave a draft carrying a Tax Order of Payment nobody raised.
 */

function draftForPreview(string $type = 'new'): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => $type,
        'status' => 'draft',
    ]);
    $app->permitTypes()->sync(PermitType::where('code', 'BUSINESS')->pluck('id')->all());

    return $app->fresh();
}

const PREVIEW_PROFILE = [
    'floor_area_sqm' => 145,
    'storeys' => 2,
    'employees' => 7,
    'lines' => [['category' => 'retailer', 'gross_receipts' => 1_850_000]],
];

it('prices every permit the filing will be billed for, not just the one it holds', function () {
    $app = draftForPreview();
    $owner = authAs('owner@biztrack.local');

    // The draft holds the business permit alone.
    expect($app->permitTypes()->pluck('code')->all())->toBe(['BUSINESS']);

    $preview = $this->withHeaders($owner)
        ->postJson("/api/v1/applications/{$app->id}/fee-preview", ['fee_profile' => PREVIEW_PROFILE])
        ->assertOk()
        ->json('data');

    /*
     * The five clearances are in the estimate even though the draft does not
     * carry them yet, because `attachRequiredPermitTypes` will attach them at
     * submission and bill for them. Asserted on the SHAPE — at least one line
     * naming an office other than BPLO's own permit — rather than on a peso
     * figure the ordinance owns.
     */
    $labels = collect($preview['line_items'])->pluck('label')->implode(' | ');
    expect($preview['total_amount'])->toBeGreaterThan(0)
        ->and(strtolower($labels))->toContain('sanitary');
});

it('writes nothing — no assessment row, no change to the draft', function () {
    $app = draftForPreview();
    $owner = authAs('owner@biztrack.local');

    expect(FeeAssessment::where('application_id', $app->id)->exists())->toBeFalse();
    $permitsBefore = $app->permitTypes()->pluck('code')->sort()->values()->all();

    $this->withHeaders($owner)
        ->postJson("/api/v1/applications/{$app->id}/fee-preview", ['fee_profile' => PREVIEW_PROFILE])
        ->assertOk();

    // The whole point: an estimate leaves no trace.
    expect(FeeAssessment::where('application_id', $app->id)->exists())->toBeFalse()
        ->and($app->fresh()->fee_profile)->toBeNull()
        ->and($app->fresh()->permitTypes()->pluck('code')->sort()->values()->all())
        ->toBe($permitsBefore);
});

it('prices a renewal over the permits it actually renews', function () {
    /*
     * A renewal is NOT widened to the mandatory set — it keeps what was ticked,
     * so its estimate must too. Getting this wrong would quote a two-permit
     * renewal for six, which is the defect the renewal work fixed on the
     * billing side.
     */
    $app = draftForPreview('renewal');
    $app->permitTypes()->sync(PermitType::whereIn('code', ['SANITARY'])->pluck('id')->all());
    $owner = authAs('owner@biztrack.local');

    $preview = $this->withHeaders($owner)
        ->postJson("/api/v1/applications/{$app->id}/fee-preview", ['fee_profile' => PREVIEW_PROFILE])
        ->assertOk()
        ->json('data');

    $labels = strtolower(collect($preview['line_items'])->pluck('label')->implode(' | '));
    expect($labels)->toContain('sanitary')
        // No business tax on a renewal that is not renewing the business permit.
        ->and($labels)->not->toContain('business tax');
});

it('refuses somebody else’s filing', function () {
    $app = draftForPreview();
    $other = authAs('juan@biztrack.local');

    $this->withHeaders($other)
        ->postJson("/api/v1/applications/{$app->id}/fee-preview", ['fee_profile' => PREVIEW_PROFILE])
        ->assertForbidden();
});

it('falls back to the stored profile when the caller sends none', function () {
    $app = draftForPreview();
    $app->update(['fee_profile' => PREVIEW_PROFILE]);
    $owner = authAs('owner@biztrack.local');

    $preview = $this->withHeaders($owner)
        ->postJson("/api/v1/applications/{$app->id}/fee-preview")
        ->assertOk()
        ->json('data');

    expect($preview['total_amount'])->toBeGreaterThan(0);
});

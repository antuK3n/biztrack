<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * `GET /permits/held` — the clearances an applicant submitted a COPY of.
 *
 * The client's words: "when you submit a sub-permit instead of apply, since it
 * is assuming that you have one already, just also display it in the Profile
 * page, along with the other permits."
 *
 * Until this endpoint existed the copy was reachable from exactly one screen —
 * the clearance stage of the filing it was uploaded to — and that stage shuts
 * the moment the filing is closed (ClearanceService::isUnlocked). So a rejected
 * or cancelled filing took the applicant's own certificate off the site with
 * it, and the Profile page is what it is reachable from instead.
 *
 * The tests below are as much about what this endpoint MUST NOT say as about
 * what it says. A submitted copy is the applicant's own document: the City did
 * not issue it, assigned it no number, recorded no validity for it and will not
 * verify it. Every one of those absences is asserted, because the permit
 * certificate is a legal instrument and a Profile page that dresses an upload
 * up as one is the same class of mistake as printing a permit for a business
 * that was never approved.
 */

/**
 * A filing owned by `owner@biztrack.local`, submitted and PAID — which is the
 * state in which the clearance stage is open.
 *
 * It used to stop at the draft, because the stage opened on a draft. The
 * ordering is reversed (docs/clearances-after-payment.md): the wizard files the
 * mayor's permit alone, the Tax Order of Payment for it is settled, and only
 * then can a copy of a clearance be handed in at all. So the fixture has to pay
 * before it can upload anything.
 *
 * Deliberately a near-copy of ClearanceStageTest's fixture rather than a shared
 * import: these tests are about what happens to a held copy AFTER the clearance
 * stage is done with it, and coupling them to that file's fixture would mean a
 * change made for a clearance-stage reason silently rewrote this file's premise.
 */
function heldCopyFiling(string $name = 'Held Copy Cafe'): Application
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-HELD-001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Held Copy St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 500000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
        'fee_profile' => [
            'gross_sales' => 2000000,
            'capitalization' => 500000,
            'employees' => 12,
            'employees_in_lgu' => 6,
            'floor_area_sqm' => 120,
            'storeys' => 2,
            'business_structure' => 'sole_proprietorship',
            'property_use' => 'non_residential',
        ],
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    // BPLO accepts the main form first; the bill does not exist before that.
    bploApprovesForm($appId);
    test()->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    return Application::findOrFail($appId);
}

beforeEach(function () {
    // Keep uploads out of the developer's real storage directory.
    Storage::fake('local');
});

it('lists a clearance the applicant submitted a copy of', function () {
    $app = heldCopyFiling();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/SANITARY/held", [
        'file' => UploadedFile::fake()->create('my-sanitary-permit.pdf', 40, 'application/pdf'),
    ])->assertSuccessful();

    $rows = $this->getJson('/api/v1/permits/held')->assertOk()->json('data');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['permit_type']['code'])->toBe('SANITARY')
        ->and($rows[0]['filename'])->toBe('my-sanitary-permit.pdf')
        ->and($rows[0]['size_bytes'])->toBeGreaterThan(0)
        ->and($rows[0]['submitted_at'])->not->toBeNull()
        ->and($rows[0]['business']['name'])->toBe('Held Copy Cafe')
        ->and($rows[0]['application']['id'])->toBe($app->id);
});

it('never dresses a submitted copy up as an issued permit', function () {
    $app = heldCopyFiling();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/ZONING/held", [
        'file' => UploadedFile::fake()->create('zoning.pdf', 12, 'application/pdf'),
    ])->assertSuccessful();

    $row = $this->getJson('/api/v1/permits/held')->assertOk()->json('data.0');

    /*
     * The heart of the thing. None of these keys may ever appear: the City
     * assigned this document no number, recorded no validity for it and has
     * nothing to verify it against. A screen handed any one of them would put a
     * fabricated legal instrument in front of the applicant, and the browser
     * would have no way of knowing it was fabricated.
     */
    expect($row)->not->toHaveKey('permit_number')
        ->and($row)->not->toHaveKey('valid_from')
        ->and($row)->not->toHaveKey('valid_until')
        ->and($row)->not->toHaveKey('days_until_expiry')
        ->and($row)->not->toHaveKey('verify_url')
        ->and($row)->not->toHaveKey('status');

    // And no Permit row was written for it either — approveAndIssue only issues
    // the permit types ON the filing, and submitting a copy is exactly the act
    // of leaving one off.
    expect($app->fresh()->permits()->count())->toBe(0);
});

it('keeps ordinary documentary requirements off the list', function () {
    $app = heldCopyFiling();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/FSIC/held", [
        'file' => UploadedFile::fake()->create('fsic.pdf', 10, 'application/pdf'),
    ])->assertSuccessful();

    /*
     * The wizard's ordinary attachments — DTI certificates, lease contracts —
     * live in the same table and leave `permit_type_id` null. That null is the
     * whole predicate behind this endpoint (see App\Support\HeldPermits), so a
     * filing with both must return only the clearance copy.
     */
    $rows = $this->getJson('/api/v1/permits/held')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['permit_type']['code'])->toBe('FSIC');

    expect($app->documents()->whereNull('permit_type_id')->count())
        ->toBe($app->documents()->count() - 1);
});

it('survives the copy outliving the clearance stage', function () {
    $app = heldCopyFiling();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/CEC/held", [
        'file' => UploadedFile::fake()->create('cec.pdf', 8, 'application/pdf'),
    ])->assertSuccessful();

    /*
     * The reason this endpoint exists, restated for the reversed ordering.
     *
     * The stage used to shut at submission, so that was the moment the copy
     * fell off the site. It shuts on a closed filing now, so this drives the
     * filing to the state that actually shuts it — rejection — and asserts the
     * copy is still reachable from Profile afterwards. Same claim, moved to the
     * boundary that still exists.
     */
    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => 'Wrong zone.'])->assertOk();

    authAs('owner@biztrack.local');
    expect($this->getJson("/api/v1/applications/{$app->id}/clearances")->assertOk()->json('meta.locked_reason'))
        ->toBeString();

    $rows = $this->getJson('/api/v1/permits/held')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['permit_type']['code'])->toBe('CEC')
        ->and($rows[0]['application']['status'])->toBe('rejected');
});

it('shows an applicant nothing but their own copies', function () {
    $app = heldCopyFiling();

    $this->postJson("/api/v1/applications/{$app->id}/clearances/MARKET/held", [
        'file' => UploadedFile::fake()->create('market.pdf', 6, 'application/pdf'),
    ])->assertSuccessful();

    /*
     * An officer is not a second reader of this list. It is scoped on
     * `applicant_user_id` alone, matching the only applicant-side branch of
     * ApplicationVisibility::canView that DocumentController::download will
     * accept — a wider list would offer rows whose files answer 403.
     */
    authAs('sanitary@biztrack.local');

    expect($this->getJson('/api/v1/permits/held')->assertOk()->json('data'))->toBe([]);
});

it('does not let the held route be mistaken for a permit id', function () {
    authAs('owner@biztrack.local');

    /*
     * `permits/held` is registered ABOVE `permits/{permit}` in routes/workflow.php
     * and has to stay there: route matching is first-come, so registered after
     * it Laravel binds "held" as a permit key and answers 404 on a request that
     * is not asking for a permit at all. This test is what notices if the two
     * lines are ever reordered.
     */
    $this->getJson('/api/v1/permits/held')->assertOk()->assertJsonStructure(['data']);
});

it('names a removed business rather than dropping the copy', function () {
    $app = heldCopyFiling('Vanishing Copy Co.');

    $this->postJson("/api/v1/applications/{$app->id}/clearances/OCCUPANCY/held", [
        'file' => UploadedFile::fake()->create('occupancy.pdf', 5, 'application/pdf'),
    ])->assertSuccessful();

    /*
     * `Business` soft-deletes while its filings stay, so the eager load returns
     * null here. The row must survive that with `business: null` — which the
     * browser renders through `businessName()` as "Business removed from
     * register" — rather than vanishing or throwing. Same shape that took three
     * officer screens down (RemovedBusinessRenderingTest).
     */
    $app->business->delete();

    $rows = $this->getJson('/api/v1/permits/held')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['business'])->toBeNull()
        ->and($rows[0]['permit_type']['code'])->toBe('OCCUPANCY');
});

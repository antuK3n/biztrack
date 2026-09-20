<?php

use App\Http\Controllers\Api\OfficeFormController;
use App\Models\Application;
use App\Models\ApplicationOfficeForm;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\OfficeFormAnswers;
use App\Support\RenewalPrefill;

/*
 * Last year's answers, offered to this year's renewal.
 *
 * The client's decisions of 18 September 2026: a renewal's office form is the
 * same form, so carry the answers forward — but "filled in and flagged, per
 * field", and *not* the ones that would be wrong to carry.
 *
 * The exclusions are what this file is mostly about, because two of the three
 * are only wrong in ways nobody would notice from the screen:
 *
 *  - a DERIVED answer carried forward would describe last year's filing
 *  - an OFFICER-written issuance date would put last year's date on this
 *    year's certificate
 *  - an ATTESTATION carried forward would sign this year's form with last
 *    year's tick, which is the one that is genuinely wrong rather than untidy
 */

/** A business, last year's filing with a saved sheet, and this year's renewal. */
function renewalWithLastYearsSheet(string $code, array $lastYearsAnswers): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $type = PermitType::where('code', $code)->firstOrFail();

    $lastYear = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
        'submitted_at' => now()->subYear(),
    ]);
    $lastYear->permitTypes()->sync([$type->id]);

    ApplicationOfficeForm::create([
        'application_id' => $lastYear->id,
        'permit_type_id' => $type->id,
        'form_data' => $lastYearsAnswers,
    ]);

    $priorPermit = Permit::create([
        'application_id' => $lastYear->id,
        'business_id' => $business->id,
        'permit_type_id' => $type->id,
        'permit_number' => 'PRE-'.$code.'-'.str_pad((string) (Permit::max('id') + 1), 6, '0', STR_PAD_LEFT),
        'issued_at' => now()->subYear(),
        'valid_from' => now()->subYear(),
        'valid_until' => now()->addDays(30),
        'status' => 'active',
    ]);

    $renewal = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $priorPermit->id,
    ]);
    $renewal->priorPermits()->sync([$priorPermit->id]);
    $renewal->permitTypes()->sync([$type->id]);

    return [$renewal->fresh(), $type];
}

it('offers last year’s answers about the premises', function () {
    [$renewal] = renewalWithLastYearsSheet('SANITARY', [
        'sanitary_classification' => 'Food Establishment',
        'water_source' => 'Level III (Waterworks)',
    ]);

    $offered = RenewalPrefill::forSheet($renewal, 'SANITARY');

    expect($offered['sanitary_classification'])->toBe('Food Establishment');
    expect($offered['water_source'])->toBe('Level III (Waterworks)');
});

it('never offers an answer the system derives for itself', function () {
    /*
     * The exclusion maintains itself: it asks `derive` what it produces from an
     * empty payload rather than keeping a list here. So a derived answer added
     * next year is excluded the day it is added.
     */
    [$renewal] = renewalWithLastYearsSheet('SANITARY', [
        'sanitary_classification' => 'Food Establishment',
        'application_date' => '2025-09-18',
    ]);

    $offered = RenewalPrefill::forSheet($renewal, 'SANITARY');

    expect($offered)->toHaveKey('sanitary_classification');
    expect($offered)->not->toHaveKey('application_date');

    // And the guard is the real derived set, not a guess at it.
    $derived = array_keys(OfficeFormAnswers::derive($renewal, 'SANITARY', []));
    expect($derived)->toContain('application_date');
    foreach ($derived as $key) {
        expect($offered)->not->toHaveKey($key);
    }
});

it('never offers a date the office wrote when it issued', function () {
    [$renewal] = renewalWithLastYearsSheet('OCCUPANCY', [
        'application_type' => 'New',
        'building_permit_date' => '2025-07-04',
        'date_issued' => '2025-08-01',
    ]);

    $offered = RenewalPrefill::forSheet($renewal, 'OCCUPANCY');

    expect($offered)->not->toHaveKey('building_permit_date');
    expect($offered)->not->toHaveKey('date_issued');
});

it('never offers a certification — a signature is an act, not a fact', function () {
    /*
     * The exclusion that would be genuinely wrong to miss. `certified` is
     * MCG-CENRO-FO-001's "I certify that the above details are correct", and
     * `officeFormMissing` refuses to submit without it — so carrying it forward
     * would sign this year's form with last year's tick AND clear the one gate
     * that makes the applicant do it deliberately.
     */
    [$renewal] = renewalWithLastYearsSheet('CEC', [
        'owner_address' => '12 Mabini St., Longos, Malabon',
        'certified' => 'yes',
    ]);

    $offered = RenewalPrefill::forSheet($renewal, 'CEC');

    expect($offered['owner_address'])->toBe('12 Mabini St., Longos, Malabon');
    expect($offered)->not->toHaveKey('certified');
});

it('offers nothing on a new filing, and nothing on a first renewal', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $new = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);
    expect(RenewalPrefill::forSheet($new, 'SANITARY'))->toBe([]);

    // A renewal with no prior permit ticked: the ordinary first-renewal case,
    // and not an error.
    $renewal = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
    ]);
    expect(RenewalPrefill::forSheet($renewal, 'SANITARY'))->toBe([]);
});

it('does not offer an answer last year left blank', function () {
    /*
     * Marking a field "from your 2026 application" when 2026 said nothing would
     * send the applicant looking for an answer that was never given.
     */
    [$renewal] = renewalWithLastYearsSheet('SANITARY', [
        'sanitary_classification' => 'Food Establishment',
        'water_source' => '',
        'notes' => null,
    ]);

    $offered = RenewalPrefill::forSheet($renewal, 'SANITARY');

    expect($offered)->toHaveKey('sanitary_classification');
    expect($offered)->not->toHaveKey('water_source');
    expect($offered)->not->toHaveKey('notes');
});

it('keeps its officer-key list identical to the controller’s', function () {
    /*
     * Two lists, one test. `OfficeFormController::OFFICER_KEYS` is private —
     * making it public to share would publish a controller's internal detail —
     * so they are asserted equal here instead. A date added to one and not the
     * other would otherwise be silently carried forward.
     */
    $controller = new ReflectionClass(OfficeFormController::class);

    expect($controller->getConstant('OFFICER_KEYS'))->toBe(RenewalPrefill::OFFICER_KEYS);
});

it('serves the prefill on the office-forms payload, beside the stored answers', function () {
    [$renewal] = renewalWithLastYearsSheet('SANITARY', [
        'sanitary_classification' => 'Food Establishment',
    ]);

    $owner = authAs('owner@biztrack.local');
    $forms = test()->withHeaders($owner)
        ->getJson("/api/v1/applications/{$renewal->id}/office-forms")
        ->assertOk()
        ->json('data');

    $sanitary = collect($forms)->firstWhere('permit_type_code', 'SANITARY');

    /*
     * A SEPARATE key from `form_data`, which is the whole design: the sheet has
     * to be able to tell a carried answer from a reviewed one, and folding
     * these in would have the client autosave last year's words into the
     * register as this year's.
     */
    expect($sanitary['prefill']['sanitary_classification'])->toBe('Food Establishment');
    expect($sanitary['form_data'])->not->toHaveKey('sanitary_classification');
});

<?php

use App\Models\ApplicationCorrection;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\PsicCode;
use App\Models\User;

/*
 * ── The contact boxes have a shape, on both doors ────────────────────────────
 *
 * Client, 10 November 2026 ("screen + server"). The business form took any
 * text as a mobile, a landline, an emergency number or a website, and a
 * returned field's correction took the registration number, the names and the
 * numbers with no rule at all — so a correction could store what the first
 * filing would have refused. One copy of each rule now: App\Support\
 * ContactRules and BusinessController::registrationNumberUnclaimed.
 *
 * `filingReturnedAbout` lives in tests/Pest.php.
 */

/** A valid POST /businesses body with the given contact answers laid over it. */
function contactPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Contact Rules Shop',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'address' => ['line1' => '5 Test St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::where('code', '47111')->value('id')]],
    ], $overrides);
}

it('refuses a malformed contact answer on the business form', function (array $overrides, string $key) {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', contactPayload($overrides))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$key]);
})->with([
    'mobile that is a landline' => [['address' => ['mobile_number' => '8123 4567']], 'address.mobile_number'],
    'mobile a digit short' => [['address' => ['mobile_number' => '0917123456']], 'address.mobile_number'],
    'mobile in words' => [['address' => ['mobile_number' => 'my cellphone']], 'address.mobile_number'],
    'landline in words' => [['address' => ['telephone' => 'none']], 'address.telephone'],
    'landline too short' => [['address' => ['telephone' => '812']], 'address.telephone'],
    'website that is an e-mail' => [['address' => ['website' => 'shop@example.com']], 'address.website'],
    'website that is a sentence' => [['address' => ['website' => 'we have no site']], 'address.website'],
    'emergency number a landline' => [['emergency_contact_number' => '(02) 8281-4712'], 'emergency_contact_number'],
    'emergency number not 09' => [['emergency_contact_number' => '08171234567'], 'emergency_contact_number'],
    'owner surname with digits' => [['owner' => ['surname' => 'Cruz2']], 'owner.surname'],
    'owner suffix with digits' => [['owner' => ['suffix' => '3rd']], 'owner.suffix'],
    'officer in charge with digits' => [['president_officer_name' => 'Juan 1 Cruz'], 'president_officer_name'],
]);

it('takes every way the wizard writes a well-formed contact', function () {
    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', contactPayload([
            'address' => [
                'mobile_number' => '+639171234567',
                'telephone' => '(02) 8281-4712',
                'website' => 'https://shop.example.com.ph/about',
            ],
            'emergency_contact_number' => '0918 123 4567',
            'owner' => ['surname' => 'Dela Cruz-Santos', 'given_name' => 'Ma. Teresa', 'suffix' => 'Jr.'],
        ]))
        ->assertCreated();

    expect($res->json('data.emergency_contact_number'))->toBe('0918 123 4567');
});

it('refuses a correction the first filing would refuse, and writes nothing', function (string $code, string $bad) {
    $app = filingReturnedAbout($code);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", ['fields' => [$code => $bad]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(["fields.{$code}"]);

    expect($app->fresh()->status->value)->toBe('returned')
        ->and(ApplicationCorrection::where('application_id', $app->id)->count())->toBe(0);
})->with([
    'registration number with no digit' => ['form:registration_number', 'ABCDEF'],
    'registration number too short' => ['form:registration_number', '12'],
    'registration number with symbols' => ['form:registration_number', '#1234!'],
    'mobile that is a landline' => ['form:mobile_number', '8123 4567'],
    'landline in words' => ['form:telephone', 'none'],
    'website that is an e-mail' => ['form:website', 'shop@example.com'],
    'emergency number a landline' => ['form:emergency_contact_number', '8281 4712'],
    'surname with digits' => ['form:owner_surname', 'Cruz2'],
    'given name over 100' => ['form:owner_given_name', str_repeat('a', 101)],
    'suffix over 20' => ['form:owner_suffix', str_repeat('a', 21)],
    'officer in charge with digits' => ['form:president_officer_name', 'Juan 1'],
]);

it('refuses a corrected registration number already on file for another owner', function () {
    $app = filingReturnedAbout('form:registration_number');
    $owner = User::where('email', 'owner@biztrack.local')->value('id');
    $theirs = Business::where('owner_user_id', '!=', $owner)->firstOrFail();
    $theirs->update(['registration_number' => 'DTI-77-1234']);

    // Spelled differently: the check compares numbers, not punctuation.
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => ['form:registration_number' => 'dti 771234'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fields.form:registration_number']);

    expect($app->fresh()->business->registration_number)->not->toBe('dti 771234');
});

it('still takes well-formed corrections of those fields', function () {
    $app = filingReturnedAbout('form:registration_number,form:mobile_number,form:website,form:emergency_contact_number');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/corrections", [
            'fields' => [
                'form:registration_number' => 'DTI-2026-88001',
                'form:mobile_number' => '09171234567',
                'form:website' => 'shop.example.ph',
                'form:emergency_contact_number' => '09181234567',
            ],
        ])
        ->assertOk();

    $business = $app->fresh()->business;
    expect($business->registration_number)->toBe('DTI-2026-88001')
        ->and($business->address->website)->toBe('shop.example.ph');
});

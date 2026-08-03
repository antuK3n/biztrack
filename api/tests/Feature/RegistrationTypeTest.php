<?php

use App\Models\Barangay;
use App\Models\Business;
use App\Models\PsicCode;

/*
 * Checklist item 94 — the type of registration decides which agency's number is
 * being asked for, so `businesses.registration_type` has to hold the STRUCTURE
 * (sole proprietorship, partnership, corporation, cooperative) and the agency
 * (DTI, SEC, CDA) has to be derived from it.
 *
 * The column used to hold both vocabularies at once. These cover the mapping
 * itself, the one-way normalisation of the legacy agency codes, and the case
 * that must never be guessed: a bare "SEC", which registers partnerships and
 * corporations alike.
 */

/** A valid POST /businesses body, overridable per test. */
function registrationPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Item 94 Trading',
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-55123',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Test St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::where('code', '47111')->value('id')]],
    ], $overrides);
}

/* ── The mapping, in both directions ────────────────────────────────────── */

it('maps each structure to the agency that registers it', function (string $structure, string $agency) {
    expect(Business::registrarFor($structure))->toBe($agency);
})->with([
    ['sole_proprietorship', 'DTI'],
    ['partnership', 'SEC'],
    ['corporation', 'SEC'],
    ['cooperative', 'CDA'],
]);

it('covers every offered structure, so no structure can be left without an agency', function () {
    foreach (Business::ORGANIZATION_FORMS as $form) {
        expect(Business::registrarFor($form))->not->toBeNull();
    }
});

it('reads a legacy agency code back as a structure only where that is unambiguous', function (string $legacy, ?string $structure) {
    expect(Business::normalizeRegistrationType($legacy))->toBe($structure);
})->with([
    // One agency, one structure: safe to translate.
    ['DTI', 'sole_proprietorship'],
    ['CDA', 'cooperative'],
    // One agency, TWO structures. Null means "we do not know" and must never
    // quietly become 'corporation'.
    ['SEC', null],
]);

it('passes the four structures through normalisation untouched', function () {
    foreach (Business::ORGANIZATION_FORMS as $form) {
        expect(Business::normalizeRegistrationType($form))->toBe($form);
    }
});

it('treats blank and unrecognised registration types as unknown', function (?string $raw) {
    expect(Business::normalizeRegistrationType($raw))->toBeNull();
})->with([null, '', '   ', 'BIR', 'sole proprietor', 'Sole_Proprietorship']);

it('still names the agency for a legacy row whose structure is unknown', function () {
    // The 143 un-migrated "SEC" rows: we cannot say partnership or corporation,
    // but we can still say the number on file came from the SEC.
    expect(Business::registrarFor('SEC'))->toBe('SEC')
        ->and(Business::normalizeRegistrationType('SEC'))->toBeNull();
});

/* ── What the API accepts ───────────────────────────────────────────────── */

it('stores the structure, not the agency', function (string $sent, string $stored) {
    $id = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', registrationPayload(['registration_type' => $sent]))
        ->assertCreated()
        ->json('data.id');

    $business = Business::find($id);
    expect($business->registration_type)->toBe($stored)
        // The Form of Organization panel reads the other column; the two are
        // the same fact and must not be able to disagree.
        ->and($business->form_of_organization)->toBe($stored);
})->with([
    ['sole_proprietorship', 'sole_proprietorship'],
    ['partnership', 'partnership'],
    ['corporation', 'corporation'],
    ['cooperative', 'cooperative'],
    // Legacy clients and this repo's own seeders send the agency code. The two
    // that translate are accepted and normalised rather than refused.
    ['DTI', 'sole_proprietorship'],
    ['CDA', 'cooperative'],
]);

it('refuses a bare SEC and says which two structures it covers', function () {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', registrationPayload(['registration_type' => 'SEC']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_type')
        ->assertJsonFragment([
            'registration_type' => ['The SEC registers both partnerships and corporations, so "SEC" does not say which yours is. Choose Partnership or Corporation.'],
        ]);
});

it('refuses a registration type that is neither a structure nor a known agency', function (string $bogus) {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', registrationPayload(['registration_type' => $bogus]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_type');
})->with(['BIR', 'llc', 'Sole Proprietorship']);

/* ── The registration number, per agency ────────────────────────────────── */

it('names only the applicant’s own agency when the number is missing', function (string $structure, string $expected) {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', registrationPayload([
            'registration_type' => $structure,
            'registration_number' => '',
        ]))
        ->assertStatus(422)
        ->assertJsonFragment(['registration_number' => [$expected]]);
})->with([
    ['sole_proprietorship', 'Enter your DTI Business Name registration number.'],
    ['partnership', 'Enter your SEC registration number.'],
    ['corporation', 'Enter your SEC registration number.'],
    ['cooperative', 'Enter your CDA registration number.'],
]);

/*
 * Every specimen below is a real shape read off the issuing agency's own
 * published register — SEC's List of Lending Companies and List of Financing
 * Companies (31 May 2020), and CDA's List of Cooperatives (December 2024).
 * They are here so that anyone later tempted to tighten the rule has to delete
 * a documented, real registration number to do it.
 */
it('accepts the shapes the agencies actually print', function (string $number) {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', registrationPayload([
            'registration_number' => $number,
            'name' => 'Shape '.$number,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.registration_number', $number);
})->with([
    'CS201811119',            // SEC, CS + 9 digits (the common current form)
    'CS20190000876',          // SEC, CS + 11 digits (the long form)
    'CS94000051',             // SEC, CS + 8 digits
    'CS200729932-A',          // SEC, trailing letter after a hyphen
    'A199706994',             // SEC, single-letter prefix
    'AS094-000088',           // SEC, embedded hyphen and a padded year segment
    'ASO91-195123',           // SEC, three-letter prefix, hyphenated
    'CEO0002268',             // SEC extension office series
    '1074',                   // SEC, bare numeric — the shortest real one found
    '0000128245',             // SEC, bare numeric, zero-padded
    '9520-15005879',          // CDA, 8 digits after the prefix
    '9520-101400031174',      // CDA, 12 digits
    '9520-2016000000052740',  // CDA, 16 digits — the longest real one found
    '9520--101100034156',     // CDA, the double hyphen that is in their own list
    '10744-01500002',         // CDA, Credit Surety Fund series
    'DTI-2026-0001',          // as applicants commonly type it, agency prefix and all
    'MLB 9520 000123',        // spaces
]);

it('refuses only what could not be a registration number at all', function (string $number) {
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', registrationPayload(['registration_number' => $number]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_number');
})->with([
    '111',          // shorter than any real reference (SEC's "1074" is the floor)
    'Test',         // no digit; every specimen in every register has one
    '-12345',       // does not start on a character of the number
    'DTI #12345',   // punctuation no certificate carries
]);

it('lets a plausible-looking wrong answer through, by design', function () {
    /*
     * "certificate 1 attached" is letters, spaces and a digit, so it passes.
     * That is the trade the loose rule makes on purpose: no agency publishes a
     * format, SEC's own registers contain twenty-odd shapes and CDA's masterlist
     * runs three at once, so the only regex tight enough to catch this would
     * also refuse certificates real businesses hold. Wrongly refusing a valid
     * number stops an applicant filing at all; a wrong one is caught by the
     * officer who opens the uploaded certificate. This test exists so the trade
     * is stated rather than discovered.
     */
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/businesses', registrationPayload([
            'registration_number' => 'certificate 1 attached',
        ]))
        ->assertCreated();
});

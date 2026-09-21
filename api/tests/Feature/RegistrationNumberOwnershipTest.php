<?php

use App\Models\Barangay;
use App\Models\Business;
use App\Models\PsicCode;

/*
 * Whose DTI / SEC / CDA certificate is it?
 *
 * ── Why this is not `unique:businesses,registration_number` ───────────────
 *
 * Because that rule refuses a lawful filing, and the reason is in how the three
 * agencies differ:
 *
 *   DTI registers a business NAME. Its numbers are effectively one per
 *   business — a proprietor with two trade names holds two certificates.
 *
 *   SEC and CDA register an ENTITY. One corporation or cooperative lawfully
 *   operates several establishments under ONE number, and BizTrack records one
 *   premises per business (which is why the zoning sheet's items IV and VI are
 *   the same address). So a corporation with a main store and a branch is two
 *   business rows citing one SEC number.
 *
 * A global unique index tells that applicant their own certificate is taken and
 * leaves them nothing to do about it. What is never legitimate is the same
 * certificate under two DIFFERENT owners — a typo, or somebody filing against a
 * company that is not theirs. So the scope is the owner.
 *
 * Client's decision, 16 September 2026, chosen over a global unique and over a
 * per-agency split (strict for DTI, lenient for SEC/CDA).
 */

function certificatePayload(string $name, string $registrationNumber, string $type = 'DTI'): array
{
    return [
        'name' => $name,
        'registration_type' => $type,
        'registration_number' => $registrationNumber,
        'tin' => '246-813-579-000',
        'address' => [
            'line1' => '127 F. Sevilla Boulevard',
            'barangay_id' => Barangay::where('name', 'Tañong')->value('id'),
        ],
        'lines' => [[
            'psic_code_id' => PsicCode::where('code', '47521')->value('id'),
            'capitalization' => 850000,
        ]],
    ];
}

it('lets one owner cite the same certificate for a second establishment', function () {
    /*
     * The case a global unique index would have refused, and the whole reason
     * this rule is scoped rather than absolute.
     */
    $owner = authAs('owner@biztrack.local');

    $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', certificatePayload(
            'Bautista Hardware & Construction Supply',
            'CS-2018-12345',
            'corporation',
        ))->assertCreated();

    $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', certificatePayload(
            'Bautista Hardware — Tonsuya Branch',
            'CS-2018-12345',
            'corporation',
        ))->assertCreated();

    expect(Business::where('registration_number', 'CS-2018-12345')->count())->toBe(2);
});

it('refuses a certificate already on file for another account', function () {
    $owner = authAs('owner@biztrack.local');
    $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', certificatePayload('Bautista Hardware', 'DTI-2026-0451233'))
        ->assertCreated();

    // A different business owner, filing the same certificate.
    $other = authAs('juan@biztrack.local');
    $this->withHeaders($other)
        ->postJson('/api/v1/businesses', certificatePayload('Not Bautista Hardware', 'DTI-2026-0451233'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_number');
});

it('does not name the other account in the refusal', function () {
    /*
     * The other business's name and owner belong to somebody else. Echoing them
     * back would turn this field into a lookup for whether a given certificate
     * is registered in Malabon and to whom — a disclosure the applicant has no
     * claim to, from a form anyone can open.
     */
    $owner = authAs('owner@biztrack.local');
    $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', certificatePayload('Secret Trading Corp.', 'DTI-2026-0999111'))
        ->assertCreated();

    $other = authAs('juan@biztrack.local');
    $message = $this->withHeaders($other)
        ->postJson('/api/v1/businesses', certificatePayload('Probe', 'DTI-2026-0999111'))
        ->assertStatus(422)
        ->json('errors.registration_number.0');

    expect($message)->not->toContain('Secret Trading')
        ->and($message)->toContain('another account')
        ->and($message)->toContain('BPLO');
});

it('sees through the separators the number is printed with', function () {
    /*
     * `CS-2018-12345`, `CS201812345` and `cs 2018 12345` are one certificate
     * typed three ways. Comparing the raw strings makes the check bypassable by
     * a keystroke rather than by intent.
     */
    $owner = authAs('owner@biztrack.local');
    $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', certificatePayload('Bautista Hardware', 'CS-2018-12345', 'corporation'))
        ->assertCreated();

    $other = authAs('juan@biztrack.local');
    foreach (['CS201812345', 'cs 2018 12345', 'cs/2018/12345', 'CS.2018.12345'] as $variant) {
        $this->withHeaders($other)
            ->postJson('/api/v1/businesses', certificatePayload('Probe', $variant, 'corporation'))
            ->assertStatus(422);
    }
});

it('lets an owner save their own business without clashing with itself', function () {
    // The update path passes the row being edited so it is excluded from the
    // check; without that, every save of an unchanged number would 422.
    $owner = authAs('owner@biztrack.local');
    $id = $this->withHeaders($owner)
        ->postJson('/api/v1/businesses', certificatePayload('Bautista Hardware', 'DTI-2026-0451233'))
        ->assertCreated()->json('data.id');

    $this->withHeaders($owner)
        ->putJson("/api/v1/businesses/{$id}", certificatePayload('Bautista Hardware Renamed', 'DTI-2026-0451233'))
        ->assertOk();
});

it('still refuses a number that is not a reference at all', function () {
    // The format rule is unchanged and deliberately loose — see the note on it.
    // These are what it was pinned to reject.
    $owner = authAs('owner@biztrack.local');

    foreach (['111', 'Test', 'n/a'] as $junk) {
        $this->withHeaders($owner)
            ->postJson('/api/v1/businesses', certificatePayload('Probe', $junk))
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration_number');
    }
});

it('normalises the way the model says it does', function () {
    expect(Business::normalizeRegistrationNumber('CS-2018-12345'))->toBe('CS201812345')
        ->and(Business::normalizeRegistrationNumber('cs 2018 12345'))->toBe('CS201812345')
        ->and(Business::normalizeRegistrationNumber('CS/2018/12345'))->toBe('CS201812345')
        ->and(Business::normalizeRegistrationNumber(null))->toBe('')
        ->and(Business::normalizeRegistrationNumber('  '))->toBe('');
});

<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\Zoning\PinZone;

/*
 * The zone under the owner's pin, and the one refusal it may lead to (Ken,
 * 5 October 2026): a line of business the zone CLEARLY does not allow. Read
 * against the shipped traced polygons and the shipped ordinance lists, not
 * fixtures, because the thing under test is whether those line up.
 *
 * Points are inside one traced zone each, well away from its edges:
 *   Longos, R-2 Basic or Max    14.659117, 120.957631
 *   Longos, Institutional       14.654221, 120.960478
 *   Acacia, Industrial-2        14.667975, 120.969217
 *   Potrero, Residential-1      14.663695, 120.985820
 *   Muzon, Fishpond             14.675865, 120.947324
 *   Catmon, in no traced zone   14.669393, 120.959980
 *
 * Self-contained (no helpers from other files): Pest's parallel runner loads
 * files separately.
 */

const PZ_SENTENCE = ' zone where your pin is. To petition this, visit the Business Permits and Licensing Office (BPLO) at Malabon City Hall.';

function pzPsic(string $code): PsicCode
{
    return PsicCode::where('code', $code)->firstOrFail();
}

function pzBarangay(string $name): Barangay
{
    return Barangay::where('name', $name)->firstOrFail();
}

/** A draft new filing of owner@, pinned where asked, for one trade. */
function pzDraft(string $barangay, ?array $pin, string $code): int
{
    authAs('owner@biztrack.local');
    $address = ['line1' => '4 Zone St.', 'barangay_id' => pzBarangay($barangay)->id];
    if ($pin !== null) {
        $address += ['latitude' => $pin[0], 'longitude' => $pin[1]];
    }
    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Pin Zone Store '.random_int(10000, 99999),
        'registration_type' => 'sole_proprietorship',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => $address,
        'lines' => [['psic_code_id' => pzPsic($code)->id]],
    ])->assertCreated()->json('data.id');

    return test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'data_privacy_consent' => true,
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ])->assertCreated()->json('data.id');
}

/** An amendment of a permitted sari-sari store on Longos' R-2, asking `$changes`. */
function pzAmendment(array $changes): int
{
    $draft = Application::findOrFail(pzDraft('Longos', [14.659117, 120.957631], '47111'));
    $draft->update(['status' => 'approved']);
    $prior = Permit::create([
        'application_id' => $draft->id,
        'business_id' => $draft->business_id,
        'permit_type_id' => PermitType::where('code', PermitType::OUTCOME_CODE)->value('id'),
        'permit_number' => 'PINZONE-'.random_int(10000, 99999),
        'issued_at' => now()->subMonths(2),
        'valid_from' => now()->subMonths(2),
        'valid_until' => now()->addMonths(4),
        'status' => 'active',
    ]);
    $id = test()->postJson('/api/v1/applications', [
        'business_id' => $draft->business_id,
        'data_privacy_consent' => true,
        'application_type' => 'amendment',
        'permit_type_ids' => [PermitType::where('code', PermitType::OUTCOME_CODE)->value('id')],
        'prior_permit_id' => $prior->id,
    ])->assertCreated()->json('data.id');
    test()->postJson("/api/v1/applications/{$id}/amendments", [
        'changes' => collect($changes)->map(fn ($v, $field) => ['field' => $field, 'new_value' => $v])->values()->all(),
    ])->assertOk();

    return $id;
}

it('reads the zone from the traced polygon under the pin, and nothing else', function () {
    expect(PinZone::at(14.659117, 120.957631, pzBarangay('Longos')))
        ->toBe(['codes' => ['R-2-BASIC', 'R-2-MAX'], 'name' => 'R-2 Basic or R-2 Max'])
        ->and(PinZone::at(14.667975, 120.969217, pzBarangay('Acacia'))['codes'])->toBe(['I-2'])
        ->and(PinZone::at(14.663695, 120.98582, pzBarangay('Potrero'))['codes'])->toBe(['R-1']);

    // Unknown: ground the tracing left blank, no pin, no barangay, and a pin
    // read against another barangay's file.
    expect(PinZone::at(14.669393, 120.959980, pzBarangay('Catmon')))->toBeNull()
        ->and(PinZone::at(null, null, pzBarangay('Longos')))->toBeNull()
        ->and(PinZone::at(14.659117, 120.957631, null))->toBeNull()
        ->and(PinZone::at(14.667975, 120.969217, pzBarangay('Longos')))->toBeNull();
});

it('lets the ordinary neighbourhood trades through a residential zone, and stops a warehouse', function () {
    $r1 = ['R-1'];
    $r2 = ['R-2-BASIC', 'R-2-MAX'];
    // Ken's check: a sari-sari store, a carinderia, a barber shop, a laundry
    // and a computer shop (an internet café, 93290; computer retail and
    // repair too) pass in a residential zone.
    foreach (['47111', '56101', '96110', '96200', '93290', '47411', '95110'] as $code) {
        expect(PinZone::refuses($r1, pzPsic($code)))->toBeFalse("{$code} refused in R-1")
            ->and(PinZone::refuses($r2, pzPsic($code)))->toBeFalse("{$code} refused in R-2");
    }
    // A warehouse is not on any residential list and is no home business.
    expect(PinZone::refuses($r1, pzPsic('52101')))->toBeTrue()
        ->and(PinZone::refuses($r2, pzPsic('52101')))->toBeTrue()
        ->and(PinZone::refuses(['R-2-BASIC'], pzPsic('52101')))->toBeTrue();

    // What the lists allow a warehouse is still allowed.
    expect(PinZone::refuses(['I-1'], pzPsic('52101')))->toBeFalse()
        ->and(PinZone::refuses(['C-2'], pzPsic('52101')))->toBeFalse();
});

it('passes everything that is not clear', function () {
    $warehouse = pzPsic('52101');
    // A zone whose list names nothing: Fishpond, and Socialized Housing,
    // which defers to BP 220.
    expect(PinZone::refuses(['FISHPOND'], $warehouse))->toBeFalse()
        ->and(PinZone::refuses(['CMP'], $warehouse))->toBeFalse();
    // "Other (not listed)", a lessor (its line depends on what it leases), and
    // a code the trade table has not read.
    $unread = PsicCode::firstOrCreate(['code' => '99031'], ['title' => 'Manufacture of something new']);
    foreach ([pzPsic('00000'), pzPsic('68100'), $unread] as $psic) {
        expect(PinZone::refuses(['INSTITUTIONAL'], $psic))->toBeFalse($psic->code);
    }
    // A line that may be the trade: a veterinary clinic beside Commercial-1's
    // "medical, dental and similar clinics".
    expect(PinZone::refuses(['C-1'], pzPsic('75000')))->toBeFalse();
});

it('says which line, in which zone, in the words the map uses', function () {
    $sentence = PinZone::refusal(14.659117, 120.957631, pzBarangay('Longos'), [pzPsic('47111'), pzPsic('52101')]);
    expect($sentence)->toBe('Warehousing and storage isn\'t allowed in the Homes and apartments, some small shops'.PZ_SENTENCE);

    expect(PinZone::refusal(14.667975, 120.969217, pzBarangay('Acacia'), [pzPsic('47111')]))
        ->toBe(pzPsic('47111')->title.' isn\'t allowed in the Industry'.PZ_SENTENCE);
    expect(PinZone::refusal(14.659117, 120.957631, pzBarangay('Longos'), [pzPsic('47111')]))->toBeNull();
});

it('answers the wizard for the pin and line on screen', function () {
    authAs('owner@biztrack.local');
    $query = fn (array $pin, string $barangay, string $code) => '/api/v1/zone-at-pin?'.http_build_query([
        'latitude' => $pin[0], 'longitude' => $pin[1],
        'barangay_id' => pzBarangay($barangay)->id, 'psic_code_ids' => [pzPsic($code)->id],
    ]);

    $this->getJson($query([14.659117, 120.957631], 'Longos', '52101'))->assertOk()
        ->assertJsonPath('data.zone.codes', ['R-2-BASIC', 'R-2-MAX'])
        ->assertJsonPath('data.refusal', 'Warehousing and storage isn\'t allowed in the Homes and apartments, some small shops'.PZ_SENTENCE);
    $this->getJson($query([14.659117, 120.957631], 'Longos', '47111'))->assertOk()
        ->assertJsonPath('data.refusal', null);
    $this->getJson($query([14.669393, 120.959980], 'Catmon', '52101'))->assertOk()
        ->assertJsonPath('data.zone', null)
        ->assertJsonPath('data.refusal', null);

    $this->getJson('/api/v1/zone-at-pin?latitude=99&longitude=200')->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude', 'barangay_id']);
});

it('refuses to submit a new filing whose pin is in a zone that clearly does not allow its line', function () {
    $refused = pzDraft('Longos', [14.659117, 120.957631], '52101');
    $this->postJson("/api/v1/applications/{$refused}/submit")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Warehousing and storage isn\'t allowed in the Homes and apartments, some small shops'.PZ_SENTENCE)
        ->assertJsonPath('errors.zoning.0', 'Warehousing and storage isn\'t allowed in the Homes and apartments, some small shops'.PZ_SENTENCE);
    expect(Application::findOrFail($refused)->status->value)->toBe('draft');

    // Allowed, unclear, or with no pin at all: submitted as before.
    foreach ([['Longos', [14.659117, 120.957631], '47111'], ['Catmon', [14.669393, 120.959980], '52101'], ['Longos', null, '52101']] as [$barangay, $pin, $code]) {
        $id = pzDraft($barangay, $pin, $code);
        $this->postJson("/api/v1/applications/{$id}/submit")->assertOk();
    }
});

it('refuses an amendment that moves the business, or changes its line, to where it is clearly not allowed', function () {
    $acacia = (string) pzBarangay('Acacia')->id;

    $move = pzAmendment(['address_barangay_id' => $acacia, 'address_pin' => '14.667975,120.969217']);
    $this->postJson("/api/v1/applications/{$move}/submit")->assertUnprocessable()
        ->assertJsonPath('errors.zoning.0', pzPsic('47111')->title.' isn\'t allowed in the Industry'.PZ_SENTENCE);

    $trade = pzAmendment(['line_of_business' => (string) pzPsic('52101')->id]);
    $this->postJson("/api/v1/applications/{$trade}/submit")->assertUnprocessable()
        ->assertJsonPath('errors.zoning.0', 'Warehousing and storage isn\'t allowed in the Homes and apartments, some small shops'.PZ_SENTENCE);

    // Moving within what the zone allows, or changing nothing about place
    // or trade, is not judged.
    $fine = pzAmendment(['address_pin' => '14.6572,120.9573']);
    $this->postJson("/api/v1/applications/{$fine}/submit")->assertOk();
    $street = pzAmendment(['address_street' => 'Rizal Avenue']);
    $this->postJson("/api/v1/applications/{$street}/submit")->assertOk();
});

it('names a zone as the map’s key does', function () {
    /*
     * The refusal names the zone the owner just read on the map's key, so the
     * PHP copy of the plain names must be the table lib/zoningNames.ts holds.
     */
    $ts = (string) file_get_contents(base_path('../web/src/lib/zoningNames.ts'));
    preg_match('/const PLAIN: Record<string, string> = \{(.*?)\n\}/s', $ts, $block);
    preg_match_all("/^\s*'?([A-Z0-9-]+)'?: '([^']+)',/m", $block[1], $rows, PREG_SET_ORDER);
    $plain = collect($rows)->mapWithKeys(fn ($r) => [$r[1] => $r[2]])->all();
    preg_match("/const R2_EITHER = '([^']+)'/", $ts, $either);

    expect($plain)->toBe(PinZone::PLAIN)
        ->and($either[1])->toBe(PinZone::R2_EITHER)
        ->and(PinZone::plainName(['R-2-MAX', 'R-2-BASIC'], 'R-2 Basic or R-2 Max'))->toBe(PinZone::R2_EITHER)
        ->and(PinZone::plainName(['C-2'], 'C-2'))->toBe('Larger shops, markets and services')
        ->and(PinZone::plainName(['NEW-ZONE'], 'New Zone'))->toBe('New Zone');
});

it('finds the traced file the map draws for every barangay', function () {
    $ts = (string) file_get_contents(base_path('../web/src/lib/zoningLayers.data.ts'));
    preg_match_all('/"([^"]+)": "\/zoning\/([a-z-]+)\.geojson"/u', $ts, $rows, PREG_SET_ORDER);
    $layers = collect($rows)->mapWithKeys(fn ($r) => [$r[1] => $r[2]]);

    foreach (Barangay::orderBy('name')->get() as $barangay) {
        expect($layers->get($barangay->name))->not->toBeNull("{$barangay->name} has no layer on the map");
        expect(is_file(base_path(PinZone::DIRECTORY.'/'.$layers->get($barangay->name).'.geojson')))->toBeTrue();
    }
});

it('puts the zone at the pin on CPDD’s sheet alone, and nothing where it is unknown', function () {
    // Read as CPDO reads it, through its assignment: the application
    // endpoint does not load the office sheets at all.
    $sheets = function (int $id): array {
        $this->postJson("/api/v1/applications/{$id}/submit")->assertOk();
        $department = assignOffice($id, 'CPDO');
        $assignment = ApplicationAssignment::where('application_id', $id)->where('department_id', $department)->value('id');
        authAs('zoning@biztrack.local');

        return collect($this->getJson("/api/v1/assignments/{$assignment}")->assertOk()->json('data.application.office_forms'))
            ->mapWithKeys(fn ($form) => [$form['permit_type_code'] => $form['zone_at_pin'] ?? null])
            ->all();
    };

    $pinned = $sheets(pzDraft('Longos', [14.659117, 120.957631], '47111'));
    expect($pinned['ZONING'])->toBe(['codes' => ['R-2-BASIC', 'R-2-MAX'], 'name' => 'R-2 Basic or R-2 Max'])
        ->and(collect($pinned)->except('ZONING')->filter()->all())->toBe([]);

    expect($sheets(pzDraft('Catmon', [14.669393, 120.959980], '47111'))['ZONING'])->toBeNull();
});

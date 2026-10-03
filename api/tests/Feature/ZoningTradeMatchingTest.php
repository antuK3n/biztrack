<?php

use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\Zoning\Ordinance;
use App\Support\Zoning\TradeUses;
use App\Support\Zoning\ZoningCheck;
use App\Support\Zoning\ZoningContext;
use App\Support\ZoningConformance;

/*
 * Which line of which zone's list a trade IS — and, as much, which it is not.
 *
 * An audit of 3 October 2026 ran every register code through the matcher and
 * found trades reported as allowed ("Met") on the strength of one shared
 * word: a gasoline station, an auto-repair shop and a rent-a-car on Maximum
 * R-2's "Water refilling Station … delivery vehicles"; trucking on "Small
 * scale eatery" by way of "road"; waste collection on Residential-1's home
 * industry; a bar on the Institutional zone's "Places of worship". The first
 * test pins each of those as not listed. The second walks the whole register
 * across every zone and holds the matcher to the table in Zoning\TradeUses
 * and to the plain shape of the ordinance — no industry, vehicle trade or
 * waste business in a residential zone, nothing but hospitals and clinics in
 * the Institutional zone — so a new false listing fails here first.
 *
 * Self-contained (no helpers from other files): Pest's parallel runner loads
 * files separately.
 */

function ztmPsic(string $code): PsicCode
{
    return PsicCode::where('code', $code)->firstOrFail();
}

function ztmCheck(string $barangay, string $code, array $facts = [], ?string $lotZone = null, string $description = ''): array
{
    $ctx = ZoningContext::fromRequest([
        'barangay_id' => Barangay::where('name', $barangay)->value('id'),
        'application_type' => 'new',
        'lines' => [['psic_code_id' => PsicCode::where('code', $code)->value('id'), 'description' => $description]],
        'zoning_facts' => $facts,
    ]);
    if ($lotZone !== null) {
        $ctx = new ZoningContext($ctx->barangay, $ctx->lines, $ctx->applicationType, $ctx->floorArea, $ctx->lotArea,
            $ctx->employees, $ctx->capitalization, $ctx->street, $ctx->isRented, $ctx->storeys, $ctx->sheetIndustryType,
            ['lot_zone' => $lotZone] + $ctx->facts, ['lot_zone' => 'officer'] + $ctx->factSources, null);
    }

    return ZoningCheck::evaluate($ctx);
}

/** The use-list finding: listed, possibly listed, or not listed. */
function ztmUse(array $result): array
{
    return collect($result['findings'])->first(fn ($f) => $f['group'] === 'uses'
        && in_array($f['title'], ['On the zones’ lists of allowed uses', 'Possibly on the zones’ lists'], true));
}

it('reports none of the audit’s one-word listings as allowed', function () {
    $false = [
        // [PSIC, zones it must NOT be listed in, the line it was wrongly matched to]
        ['47300', ['R-2-MAX', 'R-3-MAX'], 'Water refilling'],
        ['45201', ['R-2-MAX', 'R-3-MAX'], 'Water refilling'],
        ['77100', ['R-2-MAX', 'R-3-MAX'], 'Water refilling'],
        ['68100', ['R-2-MAX'], 'Water refilling'],
        ['49230', ['R-2-MAX', 'R-3-MAX', 'C-1'], 'Small scale eatery'],
        ['38110', ['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX'], 'Home Industry'],
        ['10740', ['R-1', 'R-2-BASIC', 'R-3-BASIC', 'R-3-MAX'], 'Religious use'],
        ['10799', ['R-1', 'R-2-BASIC', 'R-3-BASIC', 'R-3-MAX'], 'Plant nursery'],
        ['10300', ['C-1', 'C-3', 'CBD'], 'Funeral parlors'],
        ['56302', ['INSTITUTIONAL'], 'Places of worship'],
        ['46520', ['R-2-MAX', 'C-1'], 'Recreational Facilities'],
        ['47760', ['I-1', 'I-2', 'UTILITIES'], 'Ice plants'],
        ['70200', ['UTILITIES'], 'waste management'],
        ['63110', ['I-2'], 'Poultry processing'],
        ['22200', ['C-2', 'C-3', 'CBD', 'GENERAL-COMMERCIAL'], 'Biscuit factory'],
        ['23950', ['C-2', 'C-3', 'CBD', 'GENERAL-COMMERCIAL'], 'Manufacture of ice'],
        ['46691', ['INSTITUTIONAL'], 'research facilities'],
        ['85490', ['INSTITUTIONAL'], 'Colleges'],
        ['81210', ['I-2'], 'Dry cleaning'],
        ['47990', ['R-3-BASIC'], 'Parking lots'],
        ['80100', ['R-3-BASIC'], 'UBER'],
    ];
    foreach ($false as [$code, $zones, $wrong]) {
        $psic = ztmPsic($code);
        foreach ($zones as $zone) {
            $hit = ZoningConformance::lookup($zone, $psic);
            expect($hit['certain'] ?? false)->toBeFalse("{$code} is listed in {$zone}: ".($hit['use'] ?? ''));
            expect(str_contains($hit['use'] ?? '', $wrong))->toBeFalse("{$code} in {$zone} still matches {$wrong}");
        }
    }

    // Construction materials wholesale matched "Auto repair, tire…" in C-1;
    // it is C-1's "Construction supply stores/depots".
    expect(ZoningConformance::lookup('C-1', ztmPsic('46630'))['use'])->toBe('Construction supply stores/depots');

    // End to end, the screen says so: a gasoline station on a Maximum R-2 lot
    // is not on the list, and nothing about it is "Met".
    $use = ztmUse(ztmCheck('Muzon', '47300', [], 'R-2-MAX'));
    expect($use['status'])->toBe('review')->and($use['reason'])->not->toContain('Water refilling');
    $bar = ztmUse(ztmCheck('Muzon', '56302', [], 'INSTITUTIONAL'));
    expect($bar['status'])->toBe('review')->and($bar['reason'])->not->toContain('worship');
});

it('lists a trade only on a line the table names, across the whole register and every zone', function () {
    $zones = array_keys(Ordinance::SECTION_FOR_CODE);
    $psics = PsicCode::orderBy('code')->get();
    expect($psics->count())->toBeGreaterThan(100);

    $listed = [];
    foreach ($psics as $psic) {
        $spec = TradeUses::for((string) $psic->code);
        // A code added to the register must be read against the lists before
        // it can be reported as allowed anywhere.
        expect($spec['curated'])->toBeTrue("{$psic->code} is on the register but not in TradeUses");

        foreach ($zones as $zone) {
            $hit = ZoningConformance::lookup($zone, $psic);
            if ($hit === null || ! $hit['certain']) {
                continue;
            }
            $named = collect($spec['is'])->contains(function (string $phrase) use ($hit) {
                $text = mb_strtolower($hit['use']);
                if (! str_starts_with($phrase, '=')) {
                    return str_contains($text, $phrase);
                }
                $segments = explode(': ', $text);

                return rtrim(trim((string) end($segments)), '.') === substr($phrase, 1);
            });
            expect($named)->toBeTrue("{$psic->code} listed in {$zone} on a line its table does not name: {$hit['use']}");
            $listed[$zone][] = (string) $psic->code;
        }
    }

    // Residential zones: no industry beyond a bakeshop and a tailor, no
    // vehicle trade, fuel, waste, wholesale, bar, betting, motel, funeral
    // parlour, warehouse or plain office.
    $neverResidential = ['47300', '45201', '45301', '45401', '49221', '49230', '52101', '52290', '77100', '38110',
        '56302', '92000', '55103', '96301', '59140', '62010', '62090', '63110', '68200', '69100', '69200', '70200',
        '71100', '73100', '78100', '82200', '64920', '65120'];
    foreach (['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX'] as $zone) {
        foreach ($listed[$zone] ?? [] as $code) {
            expect(in_array($code, $neverResidential, true))->toBeFalse("{$code} reported as allowed in {$zone}");
            expect(str_starts_with($code, '46'))->toBeFalse("wholesale {$code} reported as allowed in {$zone}");
            if (Ordinance::isManufacturing($code)) {
                expect(in_array($code, ['10711', '14100'], true))->toBeTrue("manufacturing {$code} reported as allowed in {$zone}");
            }
        }
    }

    // The Institutional zone takes hospitals and clinics, not bars, wholesale
    // or schools below college; Utilities takes waste management; nothing
    // else is listed in either, and nothing at all in the Cemetery zone.
    expect(array_values(array_unique($listed['INSTITUTIONAL'] ?? [])))->toEqualCanonicalizing(['86100', '86201']);
    // Waste collection is a hauling service, not a waste-management facility:
    // nothing on the register is plainly a Utilities use.
    expect($listed['UTILITIES'] ?? [])->toBe([]);
    expect($listed['CEMETERY'] ?? [])->toBe([]);
    // Resort complexes; an indoor gym is not the Parks zone's outdoor sports.
    expect(array_diff($listed['PARKS'] ?? [], ['55101']))->toBe([]);

    // The industrial zones list industry: manufacturing, a trucking garage,
    // warehousing, publishing — never a shop, an eatery or an office trade.
    foreach (['I-1', 'I-2'] as $zone) {
        foreach ($listed[$zone] ?? [] as $code) {
            expect(Ordinance::isManufacturing($code) || in_array($code, ['49230', '52101', '58130'], true))
                ->toBeTrue("{$code} reported as allowed in {$zone}");
        }
    }
});

it('offers a look-alike line to CPDO as a possibility, never as Met', function () {
    // A veterinary clinic: Commercial-1 lists "Medical, dental, and similar
    // clinics". Similar enough is CPDO's call (Art. III §2(a)).
    $use = ztmUse(ztmCheck('Niugan', '75000', [], 'C-1'));
    expect($use['status'])->toBe('review')
        ->and($use['title'])->toBe('Possibly on the zones’ lists')
        ->and($use['rules'])->toContain('III-2-a', 'III-1', 'A-89')
        ->and($use['reason'])->toContain('similar clinics');

    $note = ZoningConformance::forBarangay(Barangay::where('name', 'Niugan')->first(), ztmPsic('75000'));
    expect($note['verdict'])->toBe('possible');
});

it('offers a code nobody has read only by two shared words, and never by one', function () {
    // Codes the register does not hold. Two distinctive words in common with
    // "Water refilling Station…" is a possibility for CPDO; "station" alone,
    // which is what put a gasoline station there, is nothing.
    $two = PsicCode::firstOrCreate(['code' => '99021'], ['title' => 'Mobile water refilling']);
    $one = PsicCode::firstOrCreate(['code' => '99022'], ['title' => 'Charging station']);

    $hit = ZoningConformance::lookup('R-2-MAX', $two);
    expect($hit['certain'])->toBeFalse()->and($hit['basis'])->toBe('unvetted')->and($hit['use'])->toContain('Water refilling');
    expect(ZoningConformance::lookup('R-2-MAX', $one))->toBeNull();
});

/** The use finding's status, its line, and whether it asked `$fact`. */
function ztmRead(array $result): array
{
    $use = ztmUse($result) ?? collect($result['findings'])->firstWhere('rule', 'V-2');

    return [$use['status'] ?? null, $use['reason'] ?? '', $use['asks'] ?? []];
}

it('reads a tailor as a shop and a garment factory as industry, and neither as Met until it knows which', function () {
    // A garment factory not run from a home used to be Met in Maximum R-2 on
    // "Tailoring and Dressmaking shops".
    [$status, , $asks] = ztmRead(ztmCheck('Tonsuya', '14100', ['home_based' => false], 'R-2-MAX'));
    expect($status)->toBe('review')->and($asks)->toContain('apparel_kind');

    expect(ztmRead(ztmCheck('Tonsuya', '14100', ['home_based' => false, 'apparel_kind' => 'tailoring'], 'R-2-MAX'))[0])->toBe('met');
    expect(ztmRead(ztmCheck('Tonsuya', '14100', ['home_based' => false, 'apparel_kind' => 'factory'], 'R-2-MAX'))[0])->not->toBe('met');
    expect(ztmRead(ztmCheck('Dampalit', '14100', ['apparel_kind' => 'factory'], 'I-1'))[0])->toBe('met');
    // The applicant's own words decide it when the question is unanswered.
    expect(ztmRead(ztmCheck('Tonsuya', '14100', ['home_based' => false], 'R-2-MAX', 'Garment factory'))[0])->not->toBe('met');
    expect(ztmRead(ztmCheck('Tonsuya', '14100', ['home_based' => false], 'R-2-MAX', 'Tailoring and alterations'))[0])->toBe('met');
});

it('never lists a driving school where the ordinance lists only tutorial services', function () {
    // "Tutorial services" is in Residential-1; driving schools only in C-1's
    // and General Commercial's short-term special education.
    [$status, $reason] = ztmRead(ztmCheck('Potrero', '85490', ['home_based' => false], 'R-1', 'Driving school'));
    expect($status)->not->toBe('met')->and($reason)->not->toContain('Tutorial services');
    expect(ztmRead(ztmCheck('Potrero', '85490', ['school_kind' => 'driving'], 'R-1'))[0])->not->toBe('met');

    [$status, $reason] = ztmRead(ztmCheck('Niugan', '85490', ['school_kind' => 'driving'], 'C-1'));
    expect($status)->toBe('met')->and($reason)->toContain('Driving school');
    expect(ztmRead(ztmCheck('Potrero', '85490', ['school_kind' => 'tutorial'], 'R-1'))[0])->toBe('met');
    expect(ztmRead(ztmCheck('Potrero', '85490', ['school_kind' => 'vocational'], 'R-2-BASIC'))[1])->toContain('Vocational School');

    [$status, , $asks] = ztmRead(ztmCheck('Potrero', '85490', [], 'R-1'));
    expect($status)->toBe('review')->and($asks)->toContain('school_kind');
});

it('asks the footwear material before placing a shoe factory in Industrial-1 or -2', function () {
    [$status, , $asks] = ztmRead(ztmCheck('Dampalit', '15200', [], 'I-1'));
    expect($status)->toBe('review')->and($asks)->toContain('footwear_material');
    expect(ztmRead(ztmCheck('Dampalit', '15200', ['footwear_material' => 'rubber_plastic'], 'I-1'))[0])->not->toBe('met');
    expect(ztmRead(ztmCheck('Acacia', '15200', ['footwear_material' => 'rubber_plastic'], 'I-2'))[0])->toBe('met');
    expect(ztmRead(ztmCheck('Dampalit', '15200', ['footwear_material' => 'leather'], 'I-1'))[0])->toBe('met');
});

it('does not read books and newspapers as school supplies', function () {
    expect(ztmRead(ztmCheck('Muzon', '47610', [], 'R-2-MAX'))[0])->toBe('review');
    expect(ztmRead(ztmCheck('Niugan', '47610', [], 'C-1'))[1])->toContain('Bookstores');
});

it('asks whether an appliance repair shop is neighbourhood scale before Maximum R-2 lists it', function () {
    [$status, , $asks] = ztmRead(ztmCheck('Muzon', '95220', [], 'R-2-MAX'));
    expect($status)->toBe('review')->and($asks)->toContain('neighbourhood_scale');
    expect(ztmRead(ztmCheck('Muzon', '95220', ['neighbourhood_scale' => true], 'R-2-MAX'))[0])->toBe('met');
    expect(ztmRead(ztmCheck('Muzon', '95220', ['neighbourhood_scale' => false], 'R-2-MAX'))[0])->not->toBe('met');
    // Commercial-1's line has no such condition.
    expect(ztmRead(ztmCheck('Niugan', '95220', ['neighbourhood_scale' => false], 'C-1'))[0])->toBe('met');
});

it('asks whether a paint store handles paint in bulk before Commercial-2 lists it', function () {
    [$status, , $asks] = ztmRead(ztmCheck('Acacia', '47522', [], 'C-2'));
    expect($status)->toBe('review')->and($asks)->toContain('paint_bulk_handling');
    expect(ztmRead(ztmCheck('Acacia', '47522', ['paint_bulk_handling' => false], 'C-2'))[0])->toBe('met');
    expect(ztmRead(ztmCheck('Acacia', '47522', ['paint_bulk_handling' => true], 'C-2'))[0])->not->toBe('met');
    expect(ztmRead(ztmCheck('Acacia', '47522', ['paint_bulk_handling' => true], 'I-2'))[1])->toContain('Paint stores with bulk handling');
});

it('reads an indoor gym and waste collection only as possibly the Parks and Utilities lines', function () {
    expect(ztmRead(ztmCheck('Catmon', '93110', [], 'PARKS'))[0])->toBe('review');
    expect(ztmRead(ztmCheck('Catmon', '38110', [], 'UTILITIES'))[0])->toBe('review');
});

it('lets an "Other" filer land on a listed use no register code reaches', function () {
    // By the applicant's own words…
    [$status, $reason] = ztmRead(ztmCheck('Acacia', '00000', [], 'C-2', 'Medium scale junk shop'));
    expect($status)->toBe('met')->and($reason)->toContain('Medium scale junk shop');
    expect(ztmRead(ztmCheck('Acacia', '00000', [], 'C-2', 'Lechon'))[1])->toContain('Lechon stores');
    expect(ztmRead(ztmCheck('Acacia', '00000', [], 'C-2', 'Chicharon factory'))[1])->toContain('Chicharon factory');

    // …or by picking the use.
    expect(ztmRead(ztmCheck('Niugan', '00000', ['listed_use' => 'car_wash'], 'C-1'))[1])->toContain('Car wash');
    expect(ztmRead(ztmCheck('Muzon', '00000', ['listed_use' => 'event_planner'], 'R-2-MAX'))[0])->toBe('met');
    expect(ztmRead(ztmCheck('Potrero', '00000', ['listed_use' => 'vocational'], 'R-2-BASIC'))[1])->toContain('Vocational School');
    expect(ztmRead(ztmCheck('Niugan', '00000', ['listed_use' => 'dance_school'], 'C-1'))[1])->toContain('Dance schools');

    // Neither: not on the list, and the pick is offered.
    [$status, , $asks] = ztmRead(ztmCheck('Acacia', '00000', [], 'C-2'));
    expect($status)->toBe('review')->and($asks)->toContain('listed_use');

    // A register trade described as one reaches the line too, as a
    // possibility, ahead of the code's own loose readings.
    [$status, $reason] = ztmRead(ztmCheck('Acacia', '38110', [], 'C-2', 'Junk shop and scrap buying'));
    expect($status)->toBe('review')->and($reason)->toContain('Medium scale junk shop');
});

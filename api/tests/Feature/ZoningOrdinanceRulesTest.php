<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\WorkflowService;
use App\Support\DenrRequirements;
use App\Support\Zoning\Ordinance;
use App\Support\Zoning\ZoningCheck;
use App\Support\Zoning\ZoningContext;
use App\Support\ZoningConformance;

/*
 * City Ordinance No. 24-2018, rule by rule.
 *
 * Each test is named in docs/zoning-ordinance/rules.json as the proof of the
 * rules it covers, and ZoningRulesCatalogueTest fails if a name here changes
 * without the inventory changing with it. They run against the SHIPPED
 * ordinance extract and the SHIPPED barangay data, for the reason
 * ZoningConformanceTest gives: the thing under test is whether the documents
 * and the code line up.
 *
 * Most tests pin the lot's zone (`lot_zone`, the zoning officer's answer), so
 * a "not met" is not softened to "CPDO review" by the barangay holding other
 * zones; the softening is tested on its own.
 */

/** A PSIC code's id, making a synthetic one when a test needs a title the register lacks. */
function zoPsic(string $code, ?string $title = null): int
{
    if ($title !== null) {
        return PsicCode::firstOrCreate(['code' => $code], ['title' => $title])->id;
    }

    return PsicCode::where('code', $code)->value('id');
}

/**
 * Run the check. `$facts` are the applicant's; `$officer` (with `lot_zone`)
 * is laid over them the way ZoningContext::fromApplication does.
 */
function zoCheck(string $barangay, array $codes, array $facts = [], array $officer = [], array $extra = [], ?array $amendment = null): array
{
    $lines = array_map(fn ($c) => is_array($c) ? $c : ['psic_code_id' => is_int($c) ? $c : zoPsic($c)], $codes);
    $ctx = ZoningContext::fromRequest(array_merge([
        'barangay_id' => Barangay::where('name', $barangay)->value('id'),
        'application_type' => 'new',
        'lines' => $lines,
        'zoning_facts' => $facts,
    ], $extra));

    if ($officer !== [] || $amendment !== null) {
        $sources = $ctx->factSources;
        foreach (array_keys($officer) as $k) {
            $sources[$k] = 'officer';
        }
        $ctx = new ZoningContext($ctx->barangay, $ctx->lines, $ctx->applicationType, $ctx->floorArea, $ctx->lotArea,
            $ctx->employees, $ctx->capitalization, $ctx->street, $ctx->isRented, $ctx->storeys, $ctx->sheetIndustryType,
            $officer + $ctx->facts, $sources, $amendment);
    }

    return ZoningCheck::evaluate($ctx);
}

/**
 * The finding for `$rule`: the one it heads, else the first that cites it.
 * The use-list finding cites every zone's list, so "the Mangrove finding" has
 * to mean the one about the Mangrove Zone, not the list that names it.
 */
function zoFinding(array $result, string $rule): ?array
{
    foreach ($result['findings'] as $f) {
        if ($f['rule'] === $rule) {
            return $f;
        }
    }
    foreach ($result['findings'] as $f) {
        if (in_array($rule, $f['rules'], true)) {
            return $f;
        }
    }

    return null;
}

function zoStatus(array $result, string $rule): ?string
{
    return zoFinding($result, $rule)['status'] ?? null;
}

/** The finding that puts question `$id` to the City, e.g. a contradiction. */
function zoAsked(array $result, string $id): ?array
{
    return collect($result['findings'])->firstWhere('question', $id);
}

// ── Where: the zones in the barangay ─────────────────────────────────────

it('names the zones Art. IV §5 places in the barangay', function () {
    $r = zoCheck('Potrero', []);
    $governing = collect($r['zones'])->where('governing', true)->pluck('code')->all();

    expect($governing)->toContain('R-1', 'R-2-BASIC', 'R-2-MAX', 'C-2', 'C-3', 'CBD', 'I-2', 'INSTITUTIONAL');
    expect(zoFinding($r, 'IV-5')['reason'])->toContain('Residential-1')->toContain('Central Business District');
    expect(zoFinding($r, 'IV-1'))->not->toBeNull();
});

it('uses the written zone list over the map and names every difference', function () {
    // Catmon's sheet draws a CBD the text of §5 does not place there.
    $r = zoCheck('Catmon', []);
    $cbd = collect($r['zones'])->firstWhere('code', 'CBD');

    expect($cbd['source'])->toBe('sheet')
        ->and($cbd['governing'])->toBeFalse();
    $diff = zoFinding($r, 'IV-6-g');
    expect($diff['status'])->toBe('review')
        ->and($diff['reason'])->toContain('Central Business District')
        ->and($diff['rules'])->toContain('IV-6-l');

    // And the use lookup decides on the text's zones, not the sheet's.
    $result = ZoningConformance::forBarangay(Barangay::where('name', 'Catmon')->first(), null);
    expect(collect($result['zones'])->firstWhere('code', 'I-2')['governing'])->toBeTrue();
});

it('counts parks and utilities zones only where the sheet draws them', function () {
    expect(zoFinding(zoCheck('Catmon', []), 'IV-5-PR-UTS'))->not->toBeNull();
    $catmon = collect(zoCheck('Catmon', [])['zones']);
    expect($catmon->firstWhere('code', 'PARKS')['governing'])->toBeTrue()
        ->and($catmon->firstWhere('code', 'UTILITIES')['governing'])->toBeTrue();

    // Longos's sheet draws no park, and §5 places none in any barangay.
    expect(collect(zoCheck('Longos', [])['zones'])->firstWhere('code', 'PARKS'))->toBeNull();
});

it('maps every base zone to the section that lists its uses', function () {
    expect(Ordinance::SECTION_FOR_CODE)->toHaveCount(19);
    $sections = ZoningConformance::sections();
    foreach (Ordinance::SECTION_FOR_CODE as $code => $section) {
        expect($sections)->toHaveKey($section);
        expect(Ordinance::ZONE_NAMES)->toHaveKey($code);
    }
    // The zones finding cites the article that names the twenty base zones.
    expect(zoFinding(zoCheck('Potrero', []), 'IV-5')['rules'])->toContain('IV-2');
});

it('recognises a lot on a commercial strip street named in Art. IV §5', function () {
    $r = zoCheck('Bayan-bayanan', [], extra: ['street' => 'General Luna Street']);
    expect(zoFinding($r, 'IV-5-STRIP')['reason'])->toContain('Gen. Luna')->toContain('Commercial-1');

    // A street §5 does not name carries no strip finding.
    expect(zoFinding(zoCheck('Bayan-bayanan', [], extra: ['street' => 'Sampaguita St.']), 'IV-5-STRIP'))->toBeNull();
});

it('surfaces both readings of one lot deep when the street is a strip street', function () {
    $f = zoFinding(zoCheck('Bayan-bayanan', [], extra: ['street' => 'Gen. Luna St.']), 'IV-5-STRIP');

    expect($f['rules'])->toContain('IV-6-f', 'IV-6-k', 'A-66-67')
        ->and($f['reason'])->toContain('30 m')->toContain('average lot depth')
        ->and($f['question'])->toBe('C15');
});

it('asks CPDO which side of a boundary a split lot falls on', function () {
    $r = zoCheck('Niugan', [], officer: ['lot_split_by_boundary' => true]);
    $f = zoFinding($r, 'IV-6-e');

    expect($f['status'])->toBe('review')
        ->and($f['audience'])->toBe('officer')
        ->and($f['reason'])->toContain('larger part');
});

// ── What: the lists of allowed uses ──────────────────────────────────────

it('finds a trade among the uses the barangay’s zones allow, with inheritance', function () {
    /*
     * A pharmacy in Acacia. C-2's own list names no drugstore; §2.8 takes in
     * "All uses allowed in C1-Zone", and C-1 names drugstores. Before the
     * chains were followed this read "not on the list".
     */
    $pharmacy = PsicCode::where('code', '47721')->first();
    expect(ZoningConformance::lookup('C-2', $pharmacy)['from'])->toBe('C-1')
        ->and(ZoningConformance::lookup('C-2', $pharmacy)['via'])->toBe('V-2.8-INH');

    $f = zoFinding(zoCheck('Acacia', ['47721'], officer: ['lot_zone' => 'C-2']), 'V-2');
    expect($f['status'])->toBe('met')->and($f['rules'])->toContain('V-2.8-INH');

    // Every zone's own list is consulted: a use quoted from it is found there.
    foreach (Ordinance::SECTION_FOR_CODE as $code => $section) {
        $own = ZoningConformance::ownUses($code);
        if ($own === []) {
            continue; // Socialized Housing (BP 220) and Fishpond list nothing
        }
        $probe = new PsicCode(['code' => '99999', 'title' => 'Probe ('.mb_strtolower(explode(',', preg_replace('/^[^:]+:\s+/', '', $own[0]))[0]).')']);
        expect(ZoningConformance::lookup($code, $probe))->not->toBeNull("{$code} did not find its own first use");
    }
});

it('cites the lot’s own zone list on the use finding, for every zone with a list', function () {
    foreach ([
        ['Potrero', 'R-1', '85100', 'V-2.1-USES'], ['Potrero', 'R-2-BASIC', '55900', 'V-2.2-USES'],
        ['Muzon', 'R-2-MAX', '47111', 'V-2.3-USES'], ['Dampalit', 'R-3-BASIC', '55101', 'V-2.4-USES'],
        ['Acacia', 'R-3-MAX', '55101', 'V-2.5-USES'], ['Niugan', 'C-1', '47721', 'V-2.7-USES'],
        ['Acacia', 'C-2', '56302', 'V-2.8-USES'], ['Potrero', 'C-3', '47190', 'V-2.9-USES'],
        ['Catmon', 'GENERAL-COMMERCIAL', '47111', 'V-2.10-USES'], ['Longos', 'CBD', '47721', 'V-2.11-USES'],
        ['Dampalit', 'I-1', '10740', 'V-2.12-USES'], ['Acacia', 'I-2', '10611', 'V-2.13-USES'],
        ['Catmon', 'PARKS', '55101', 'V-2.17-USES'], ['Tugatog', 'CEMETERY', '96990', 'V-2.18-USES'],
        ['Catmon', 'UTILITIES', '38110', 'V-2.19-USES'], ['Potrero', 'INSTITUTIONAL', '86100', 'V-2.20-USES'],
    ] as [$barangay, $zone, $code, $rule]) {
        expect('V-'.Ordinance::SECTION_FOR_CODE[$zone].'-USES')->toBe($rule);
        $f = zoFinding(zoCheck($barangay, [$code], officer: ['lot_zone' => $zone]), 'V-2');
        expect($f['rules'])->toContain($rule);
        // Listed where the table names the line. A cemetery lists no trade on
        // the register; waste collection is only possibly a Utilities facility.
        expect($f['status'])->toBe(in_array($zone, ['CEMETERY', 'UTILITIES'], true) ? 'review' : 'met', "{$code} in {$zone}");
    }
});

it('resolves each zone’s inherited uses as the ordinance writes them', function () {
    expect(Ordinance::closure('R-2-BASIC'))->toBe(['R-2-BASIC', 'R-1'])
        ->and(Ordinance::closure('R-2-MAX'))->toBe(['R-2-MAX', 'R-1', 'R-2-BASIC'])
        ->and(Ordinance::closure('R-3-BASIC'))->toBe(['R-3-BASIC', 'R-1', 'R-2-BASIC'])
        ->and(Ordinance::closure('I-2'))->toBe(['I-2']);

    // C-2 reaches Basic and Maximum R-2 through C-1, so its "R-1 and R-2
    // Zones" pointer cannot change the answer whichever way it is read.
    expect(Ordinance::closure('C-2'))->toContain('C-1', 'R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-MAX')
        ->not->toContain('R-3-BASIC');
    expect(Ordinance::closure('C-3'))->toContain('C-1', 'C-2');
    expect(Ordinance::closure('GENERAL-COMMERCIAL'))->toContain('R-3-BASIC');
    expect(Ordinance::closure('CBD'))->toContain('C-1', 'C-2', 'C-3', 'GENERAL-COMMERCIAL');
    expect(Ordinance::inheritanceRule('CBD', 'GENERAL-COMMERCIAL'))->toBe('V-2.11-INH');

    // Each zone reaches the next by the clause that says so, and the finding
    // cites that clause.
    foreach ([
        ['R-2-BASIC', 'R-1', 'V-2.2-INH'], ['R-2-MAX', 'R-2-BASIC', 'V-2.3-INH'], ['R-3-BASIC', 'R-1', 'V-2.4-INH'],
        ['C-2', 'C-1', 'V-2.8-INH'], ['C-2', 'R-1', 'V-2.8-INH-R2'], ['C-3', 'C-2', 'V-2.9-INH'],
        ['GENERAL-COMMERCIAL', 'R-3-BASIC', 'V-2.10-INH'],
    ] as [$zone, $source, $rule]) {
        expect(Ordinance::inheritanceRule($zone, $source))->toBe($rule);
    }
    expect(zoFinding(zoCheck('Potrero', ['85100'], officer: ['lot_zone' => 'R-2-BASIC']), 'V-2')['rules'])->toContain('V-2.2-INH');
});

it('sends a use found only in Basic R-3 to CPDO where the inheritance skips it', function () {
    // "four (4) wheeler … refrigerated vans" appears in Basic R-3's garage
    // rule and nowhere else. A code the register does not hold, so it is
    // matched only by two shared words, and only ever as a possibility.
    $id = zoPsic('99001', 'Four wheeler refrigerated vans');
    $f = zoFinding(zoCheck('Acacia', [$id]), 'V-2.5-INH');

    expect($f['status'])->toBe('review')
        ->and($f['rules'])->toContain('V-2.7-INH')
        ->and($f['question'])->toBe('C18');
});

it('never reports a trade missing from every list as refused', function () {
    // Computer programming in Muzon: Maximum R-2 and Institutional list no office.
    $r = zoCheck('Muzon', ['62010']);
    $f = zoFinding($r, 'A-89');

    expect($f['status'])->toBe('review')
        ->and($f['rules'])->toContain('III-2', 'III-2-a')
        ->and(collect($r['findings'])->where('group', 'uses')->where('status', 'not_met'))->toBeEmpty();
});

it('sends a use allowed only on the Zoning Administrator’s conditions to CPDO', function () {
    // A tobacco shop: Maximum R-2 names no such shop, but it lists "other
    // related small scale stores" on the Zoning Administrator's conditions.
    $f = zoFinding(zoCheck('Muzon', ['47230'], officer: ['lot_zone' => 'R-2-MAX']), 'V-2.3-RETAIL-ZA');
    expect($f['status'])->toBe('review')->and($f['reason'])->toContain('Other related small scale stores');

    // Inherited from R-1 into Maximum R-2: the same condition, from §2.3's
    // first line.
    $school = zoFinding(zoCheck('Muzon', ['85100'], officer: ['lot_zone' => 'R-2-MAX']), 'V-2');
    expect($school['status'])->toBe('review')->and($school['rules'])->toContain('V-2.3-INH');
});

it('sends a site in a zone the ordinance leaves unlisted to CPDO', function () {
    $f = zoFinding(zoCheck('Longos', ['47111']), 'V-2.6-BP220');

    expect($f['status'])->toBe('review')
        ->and($f['scope'])->toContain('Socialized Housing')
        ->and($f['question'])->toBe('C22');
});

it('rules out business and building in the Mangrove Zone', function () {
    expect(zoStatus(zoCheck('Dampalit', ['47111'], officer: ['lot_zone' => 'MANGROVE']), 'V-2.15-USES'))->toBe('not_met');

    // Unpinned, Dampalit's other zones keep it a question for CPDO.
    $f = zoFinding(zoCheck('Dampalit', ['47111']), 'V-2.15-USES');
    expect($f['status'])->toBe('review')->and($f['scope'])->toContain('Mangrove');
});

it('judges a filing with several lines by its principal line', function () {
    $r = zoCheck('Muzon', [
        ['psic_code_id' => zoPsic('47111'), 'gross_sales' => 100000],
        ['psic_code_id' => zoPsic('62010'), 'gross_sales' => 500000],
    ]);
    $f = zoFinding($r, 'IV-7-a');
    expect($f['reason'])->toContain('by gross sales')->toContain('Computer programming')
        ->and($f['rules'])->toContain('IV-7-b1', 'IV-7-b2');

    $tied = zoFinding(zoCheck('Muzon', ['47111', '62010']), 'IV-7-a');
    expect($tied['status'])->toBe('review')->and($tied['reason'])->toContain('CPDO decides which is principal');
});

it('tells a new business it cannot start as a non-conforming use', function () {
    expect(zoStatus(zoCheck('Muzon', ['62010']), 'IX-12-3'))->toBe('review');
    // A listed trade gets no such warning.
    expect(zoFinding(zoCheck('Muzon', ['47111']), 'IX-12-3'))->toBeNull();
});

// ── Home occupation and home industry (§2.1; Annex A 42) ──────────────────

it('asks the home-occupation questions only of a home-based business', function () {
    $ask = zoFinding(zoCheck('Muzon', ['47111']), 'V-2.1-HO');
    expect($ask['asks'])->toBe(['home_based'])->and($ask['missing'])->toBe(['home_based']);

    expect(zoFinding(zoCheck('Muzon', ['47111'], ['home_based' => false]), 'V-2.1-HO-1'))->toBeNull();
    expect(zoFinding(zoCheck('Muzon', ['47111'], ['home_based' => true]), 'V-2.1-HO-1'))->not->toBeNull();
});

it('flags a home occupation of more than five people', function () {
    $lot = ['lot_zone' => 'R-2-MAX'];
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'persons_engaged' => 6], $lot), 'V-2.1-HO-1'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'persons_engaged' => 5], $lot), 'V-2.1-HO-1'))->toBe('met');
});

it('flags a home business that changes the outside of the house', function () {
    $lot = ['lot_zone' => 'R-2-MAX'];
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'house_altered' => 'outside'], $lot), 'V-2.1-HO-2'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'house_altered' => 'none'], $lot), 'V-2.1-HO-2'))->toBe('met');
});

it('measures a home occupation against 20% of the house, and surfaces Annex A’s quarter', function () {
    $lot = ['lot_zone' => 'R-2-MAX'];
    $at = fn (float $floor) => zoCheck('Muzon', ['47111'], ['home_based' => true, 'dwelling_floor_area_sqm' => 100], $lot, ['floor_area_sqm' => $floor]);

    expect(zoStatus($at(18), 'V-2.1-HO-3'))->toBe('met');
    $between = zoFinding($at(22), 'V-2.1-HO-3');
    expect($between['status'])->toBe('review')->and($between['reason'])->toContain('Annex A 42')->and($between['question'])->toBe('C14');
    expect(zoStatus($at(30), 'V-2.1-HO-3'))->toBe('not_met');

    // Annex A's other two tests are checked against §2.1, not instead of it.
    $strict = zoFinding(zoCheck('Muzon', ['47111'], ['home_based' => true, 'non_resident_workers' => true, 'non_household_equipment' => false], $lot), 'A-42');
    expect($strict['status'])->toBe('review')->and($strict['reason'])->toContain('non-resident');
});

it('flags a home business run from an accessory structure', function () {
    $f = zoFinding(zoCheck('Muzon', ['47111'], ['home_based' => true, 'in_accessory_structure' => true], ['lot_zone' => 'R-2-MAX']), 'V-2.1-HO-4');

    expect($f['status'])->toBe('not_met')->and($f['rules'])->toContain('V-2.1-ACC', 'III-1-ACCESSORY');
});

it('flags a home business whose customers park on the street', function () {
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'parking_on_street' => true], ['lot_zone' => 'R-2-MAX']), 'V-2.1-HO-5'))->toBe('not_met');
});

it('flags home-business equipment that is a nuisance off the premises', function () {
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'nuisance_equipment' => true], ['lot_zone' => 'R-2-MAX']), 'V-2.1-HO-6'))->toBe('not_met');
});

it('applies the home-industry conditions to manufacturing at home', function () {
    $r = zoCheck('Muzon', ['10711'], ['home_based' => true, 'dwelling_floor_area_sqm' => 100, 'industry_pollutive' => true, 'industry_hazardous' => false],
        ['lot_zone' => 'R-2-MAX'], ['floor_area_sqm' => 40]);

    expect(zoStatus($r, 'V-2.1-HI-1'))->toBe('not_met')
        ->and(zoStatus($r, 'V-2.1-HI-2'))->toBe('not_met')
        ->and(zoFinding($r, 'V-2.1-HI'))->not->toBeNull()
        ->and(zoFinding($r, 'V-2.1-HI-4'))->not->toBeNull()
        // The five-person and 20% rules are the home OCCUPATION's, not the industry's.
        ->and(zoFinding($r, 'V-2.1-HO-1'))->toBeNull();
});

it('compares a home industry’s capital with Annex A’s ₱100,000 and leaves a higher figure to CPDO', function () {
    $at = fn (int $capital) => zoCheck('Muzon', ['10711'], ['home_based' => true], ['lot_zone' => 'R-2-MAX'], ['capitalization' => $capital]);

    expect(zoStatus($at(80000), 'V-2.1-HI-3'))->toBe('met');
    $high = zoFinding($at(150000), 'V-2.1-HI-3');
    expect($high['status'])->toBe('review')
        ->and($high['rules'])->toContain('A-23')
        ->and($high['reason'])->toContain('DTI')
        ->and($high['question'])->toBe('C19');
});

// ── "Provided that": the conditions on particular uses ──────────────────

it('checks a small eatery in Maximum R-2 for a customer area and street dining', function () {
    $lot = ['lot_zone' => 'R-2-MAX'];
    expect(zoStatus(zoCheck('Muzon', ['56103'], ['customer_area' => true, 'street_for_customers' => true], $lot), 'V-2.3-EAT'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['56103'], ['customer_area' => true, 'street_for_customers' => false], $lot), 'V-2.3-EAT'))->toBe('met');
});

it('checks a water refilling station in Maximum R-2 for delivery parking', function () {
    $lot = ['lot_zone' => 'R-2-MAX'];
    expect(zoStatus(zoCheck('Muzon', ['36000'], ['delivery_parking' => false], $lot), 'V-2.3-WRS'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['36000'], ['delivery_parking' => true], $lot), 'V-2.3-WRS'))->toBe('met');
});

it('asks what the vehicles are for before applying any residential garage rule', function () {
    // A garage described by the applicant, no answer yet: the four residential
    // rules differ by purpose, so none is applied until it is given.
    $garage = [['psic_code_id' => zoPsic('77100'), 'description' => 'Garage for our rental cars']];
    $f = zoFinding(zoCheck('Muzon', $garage, [], ['lot_zone' => 'R-2-MAX']), 'V-2.3-PARK');
    expect($f['status'])->toBe('review')->and($f['asks'])->toContain('vehicle_use')
        ->and(zoFinding(zoCheck('Muzon', $garage, [], ['lot_zone' => 'R-2-MAX']), 'V-2.3-GAR')['asks'])->toContain('vehicle_use');
});

it('applies a pay parking lot’s own conditions, not a garage’s vehicle cap', function () {
    $lot = [['psic_code_id' => zoPsic('68100'), 'description' => 'Pay parking lot']];
    $ok = ['vehicle_use' => 'parking_lot', 'heavy_vehicles' => false, 'motor_pool' => false, 'onsite_maneuvering' => true, 'garage_vehicle_count' => 40];
    $mr2 = ['lot_zone' => 'R-2-MAX'];

    // Forty cars: a rentable lot has no cap on numbers, unlike a garage.
    $r = zoCheck('Muzon', $lot, $ok, $mr2);
    expect(zoStatus($r, 'V-2.3-PARK'))->toBe('met')
        ->and(zoFinding($r, 'V-2.3-GAR'))->toBeNull();
    // It is found on Maximum R-2's own list, by what it is, not by its code.
    $use = zoFinding($r, 'V-2');
    expect($use['status'])->toBe('met')->and($use['reason'])->toContain('rentable parking lots');

    expect(zoStatus(zoCheck('Muzon', $lot, ['heavy_vehicles' => true] + $ok, $mr2), 'V-2.3-PARK'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', $lot, ['motor_pool' => true] + $ok, $mr2), 'V-2.3-PARK'))->toBe('not_met');

    // Basic R-3 adds no backing onto the road.
    $br3 = ['lot_zone' => 'R-3-BASIC'];
    expect(zoStatus(zoCheck('Dampalit', $lot, $ok, $br3), 'V-2.4-PARK'))->toBe('met');
    expect(zoStatus(zoCheck('Dampalit', $lot, ['onsite_maneuvering' => false] + $ok, $br3), 'V-2.4-PARK'))->toBe('not_met');
});

it('keeps vulcanizing and vehicle repair out of a parking building', function () {
    $building = [['psic_code_id' => zoPsic('68100'), 'description' => 'Parking building']];
    $ok = ['vehicle_use' => 'parking_building', 'heavy_vehicles' => false, 'motor_pool' => false];

    expect(zoStatus(zoCheck('Muzon', $building, ['parking_repair_services' => true] + $ok, ['lot_zone' => 'R-2-MAX']), 'A-72'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', $building, ['parking_repair_services' => false] + $ok, ['lot_zone' => 'R-2-MAX']), 'A-72'))->toBe('met');
});

it('caps a ride-hailing garage at two units and a taxi garage at one', function () {
    $garage = [['psic_code_id' => zoPsic('49221'), 'description' => 'Garage']];
    $mr2 = ['lot_zone' => 'R-2-MAX'];

    expect(zoStatus(zoCheck('Muzon', $garage, ['vehicle_use' => 'ride_hailing_garage', 'garage_vehicle_count' => 2], $mr2), 'V-2.3-GAR'))->toBe('met');
    expect(zoStatus(zoCheck('Muzon', $garage, ['vehicle_use' => 'ride_hailing_garage', 'garage_vehicle_count' => 3], $mr2), 'V-2.3-GAR'))->toBe('not_met');

    // One taxi, not two: the cap of two was wrongly applied to every kind.
    expect(zoStatus(zoCheck('Muzon', $garage, ['vehicle_use' => 'taxi_garage', 'garage_vehicle_count' => 1], $mr2), 'V-2.3-GAR'))->toBe('met');
    $taxis = zoFinding(zoCheck('Muzon', $garage, ['vehicle_use' => 'taxi_garage', 'garage_vehicle_count' => 2], $mr2), 'V-2.3-GAR');
    expect($taxis['status'])->toBe('not_met')->and($taxis['reason'])->toContain('one taxi');
    expect(zoStatus(zoCheck('Dampalit', $garage, ['vehicle_use' => 'taxi_garage', 'garage_vehicle_count' => 2], ['lot_zone' => 'R-3-BASIC']), 'V-2.4-GAR'))->toBe('not_met');
});

it('checks parking for one’s own business: a Malabon business, the same owner, a two-way street, two vans or one', function () {
    $parking = [['psic_code_id' => zoPsic('49230'), 'description' => 'Parking for our delivery vans']];
    $ok = ['vehicle_use' => 'business_parking', 'existing_malabon_business' => true, 'lot_owner_is_business_owner' => true,
        'garage_vehicle_count' => 2, 'heavy_vehicles' => false, 'motor_pool' => false, 'two_way_street' => true, 'onsite_maneuvering' => true];
    $mr2 = ['lot_zone' => 'R-2-MAX'];

    expect(zoStatus(zoCheck('Muzon', $parking, $ok, $mr2), 'V-2.3-GAR'))->toBe('met');
    foreach ([
        'existing_malabon_business' => [false, 'already operating in malabon'],
        'lot_owner_is_business_owner' => [false, 'owner of the lot'],
        'two_way_street' => [false, 'two-way traffic'],
        'onsite_maneuvering' => [false, 'backing onto the road'],
        'motor_pool' => [true, 'motor pooling'],
        'heavy_vehicles' => [true, 'trailer trucks'],
        'garage_vehicle_count' => [3, 'two delivery vans'],
    ] as $fact => [$value, $words]) {
        $f = zoFinding(zoCheck('Muzon', $parking, [$fact => $value] + $ok, $mr2), 'V-2.3-GAR');
        expect($f['status'])->toBe('not_met', $fact)->and(mb_strtolower($f['reason']))->toContain($words);
    }

    // Basic R-3 allows ONE four-wheeler or 2-ton van: two was wrongly "met".
    $br3 = ['lot_zone' => 'R-3-BASIC'];
    expect(zoStatus(zoCheck('Dampalit', $parking, ['garage_vehicle_count' => 1] + $ok, $br3), 'V-2.4-GAR'))->toBe('met');
    $two = zoFinding(zoCheck('Dampalit', $parking, $ok, $br3), 'V-2.4-GAR');
    expect($two['status'])->toBe('not_met')->and($two['reason'])->toContain('one four-wheeler');
    expect(zoStatus(zoCheck('Dampalit', $parking, ['garage_vehicle_count' => 1, 'existing_malabon_business' => false] + $ok, $br3), 'V-2.4-GAR'))->toBe('not_met');
    expect(zoStatus(zoCheck('Dampalit', $parking, ['garage_vehicle_count' => 1, 'lot_owner_is_business_owner' => false] + $ok, $br3), 'V-2.4-GAR'))->toBe('not_met');
});

it('checks a tricycle terminal in Maximum R-2 for backing and motor pools', function () {
    $terminal = ['vehicle_use' => 'tricycle_terminal', 'onsite_maneuvering' => true, 'motor_pool' => false];
    expect(zoStatus(zoCheck('Muzon', ['49221'], $terminal, ['lot_zone' => 'R-2-MAX']), 'V-2.3-TRI'))->toBe('met');
    expect(zoStatus(zoCheck('Muzon', ['49221'], ['motor_pool' => true] + $terminal, ['lot_zone' => 'R-2-MAX']), 'V-2.3-TRI'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['49221'], ['onsite_maneuvering' => false] + $terminal, ['lot_zone' => 'R-2-MAX']), 'V-2.3-TRI'))->toBe('not_met');
});

it('treats a recreation business in a residential zone as a conditional use', function () {
    $f = zoFinding(zoCheck('Muzon', ['93290'], [], ['lot_zone' => 'R-2-MAX']), 'V-2.3-COND');
    expect($f['status'])->toBe('review')->and($f['rules'])->toContain('V-2.1-REC');

    // A gym is listed flat in Maximum R-2 and is not a conditional use there.
    expect(zoFinding(zoCheck('Muzon', ['93110'], [], ['lot_zone' => 'R-2-MAX']), 'V-2.3-COND'))->toBeNull();
});

it('checks a warehouse in C-1 against the 200 m² cap and its other conditions', function () {
    $ok = ['industry_pollutive' => false, 'industry_hazardous' => false, 'existing_malabon_business' => true, 'parking_on_street' => false];
    $lot = ['lot_zone' => 'C-1'];

    $big = zoFinding(zoCheck('Niugan', ['52101'], $ok, $lot, ['floor_area_sqm' => 250]), 'V-2.7-WH');
    expect($big['status'])->toBe('not_met')->and($big['reason'])->toContain('200');
    expect(zoStatus(zoCheck('Niugan', ['52101'], $ok, $lot, ['floor_area_sqm' => 150]), 'V-2.7-WH'))->toBe('met');
    expect(zoStatus(zoCheck('Niugan', ['52101'], ['industry_hazardous' => true] + $ok, $lot, ['floor_area_sqm' => 150]), 'V-2.7-WH'))->toBe('not_met');
});

it('checks parking for C-1 restaurants, food parks and recreation centres', function () {
    $lot = ['lot_zone' => 'C-1'];
    expect(zoStatus(zoCheck('Niugan', ['56101'], ['parking_on_street' => true], $lot), 'V-2.7-RESTO'))->toBe('not_met');
    // Parking on the premises still leaves the NBC count to CPDO.
    expect(zoStatus(zoCheck('Niugan', ['56101'], ['parking_on_street' => false], $lot), 'V-2.7-RESTO'))->toBe('review');

    expect(zoFinding(zoCheck('Niugan', ['93290'], [], $lot), 'V-2.7-REC'))->not->toBeNull();
    $foodPark = zoCheck('Niugan', [['psic_code_id' => zoPsic('56101'), 'description' => 'Food park with six stalls']], ['parking_on_street' => true], $lot);
    expect(zoStatus($foodPark, 'V-2.7-FOODPARK'))->toBe('not_met');
});

it('measures a lotto outlet against 200 m from institutions', function () {
    $lot = ['lot_zone' => 'C-1'];
    expect(zoStatus(zoCheck('Niugan', ['92000'], ['distance_to_institution_m' => 150], $lot), 'V-2.7-LOTTO'))->toBe('not_met');
    expect(zoStatus(zoCheck('Niugan', ['92000'], ['distance_to_institution_m' => 250], $lot), 'V-2.7-LOTTO'))->toBe('met');
});

it('checks auto repair and car washes in C-1 for parking, façade and grease traps', function () {
    $ok = ['parking_on_street' => false, 'makeshift_facade' => false, 'grease_trap' => true];
    $lot = ['lot_zone' => 'C-1'];

    expect(zoStatus(zoCheck('Niugan', ['45201'], $ok, $lot), 'V-2.7-AUTO'))->toBe('met');
    expect(zoStatus(zoCheck('Niugan', ['45201'], ['makeshift_facade' => true] + $ok, $lot), 'V-2.7-AUTO'))->toBe('not_met');
    expect(zoStatus(zoCheck('Niugan', ['45201'], ['grease_trap' => false] + $ok, $lot), 'V-2.7-CARWASH'))->toBe('not_met');
});

it('sends a condition to CPDO where one list attaches it and another does not', function () {
    // The CBD takes in C-1's conditioned auto repair AND General Commercial's flat one.
    $f = zoFinding(zoCheck('Longos', ['45201'], ['makeshift_facade' => true], ['lot_zone' => 'CBD']), 'V-2.7-AUTO');

    expect($f['status'])->toBe('review')->and($f['rules'])->toContain('V-2.10-FLAT');
});

it('checks a filling station against both of the ordinance’s siting rules', function () {
    $lot = ['lot_zone' => 'C-1'];
    $r = zoCheck('Niugan', ['47300'], ['distance_to_fuel_station_m' => 800, 'distance_to_institution_m' => 150, 'has_ecc' => false], $lot);

    expect(zoStatus($r, 'V-2.7-GAS-1KM'))->toBe('not_met')
        ->and(zoStatus($r, 'V-3-C-1'))->toBe('not_met')
        ->and(zoStatus($r, 'V-2.7-GAS-ECC'))->toBe('not_met')
        ->and(zoFinding($r, 'V-3-C-CONFLICT')['question'])->toBe('C13');

    $ok = zoCheck('Niugan', ['47300'], ['distance_to_fuel_station_m' => 1200, 'distance_to_institution_m' => 300, 'has_ecc' => true], $lot);
    expect(zoStatus($ok, 'V-2.7-GAS-1KM'))->toBe('met')->and(zoStatus($ok, 'V-3-C-1'))->toBe('met');

    // In a residential zone it also needs the homeowners' written conformity.
    expect(zoStatus(zoCheck('Niugan', ['47300'], ['hoa_consent' => false], ['lot_zone' => 'R-2-MAX']), 'V-2.7-GAS-RES'))->toBe('not_met');
});

it('allows only Category II and III funeral parlours in C-1', function () {
    expect(zoStatus(zoCheck('Niugan', ['96301'], ['funeral_category' => 'I'], ['lot_zone' => 'C-1']), 'V-2.7-FUNERAL-CAT'))->toBe('not_met');
    expect(zoStatus(zoCheck('Niugan', ['96301'], ['funeral_category' => 'II'], ['lot_zone' => 'C-1']), 'V-2.7-FUNERAL-CAT'))->toBe('met');
    // C-2 allows every category.
    expect(zoFinding(zoCheck('Acacia', ['96301'], ['funeral_category' => 'I'], ['lot_zone' => 'C-2']), 'V-2.7-FUNERAL-CAT'))->toBeNull();
});

it('checks machine shops and junk shops in C-2 for parking, firewalls and barangay hours', function () {
    $lot = ['lot_zone' => 'C-2'];
    $ok = ['parking_on_street' => false, 'nuisance_equipment' => false, 'makeshift_facade' => false, 'firewalls' => true, 'barangay_hours' => true];

    expect(zoStatus(zoCheck('Acacia', ['25920'], $ok, $lot), 'V-2.8-MACH'))->toBe('met');
    expect(zoStatus(zoCheck('Acacia', ['25920'], ['firewalls' => false] + $ok, $lot), 'V-2.8-MACH'))->toBe('not_met');

    $junk = zoCheck('Acacia', [['psic_code_id' => zoPsic('47741'), 'description' => 'Junk shop, scrap metal']], ['barangay_hours' => false] + $ok, $lot);
    expect(zoStatus($junk, 'V-2.8-JUNK'))->toBe('not_met');
});

it('checks hauling and trucking garages for their Malabon business condition', function () {
    expect(zoStatus(zoCheck('Catmon', ['49230'], ['operates_within_malabon' => false], ['lot_zone' => 'GENERAL-COMMERCIAL']), 'V-2.10-HAUL'))->toBe('not_met');

    $small = zoFinding(zoCheck('Dampalit', ['49230'], ['existing_malabon_business' => true], ['lot_zone' => 'I-1'], ['lot_area_sqm' => 800]), 'V-2.12-TRUCK');
    expect($small['status'])->toBe('not_met')->and($small['reason'])->toContain('1,000');

    // The owner must already run a business in Malabon.
    $outsider = zoFinding(zoCheck('Dampalit', ['49230'], ['existing_malabon_business' => false], ['lot_zone' => 'I-1'], ['lot_area_sqm' => 1500]), 'V-2.12-TRUCK');
    expect($outsider['status'])->toBe('not_met')->and($outsider['reason'])->toContain('already run a business in Malabon');
    expect(zoStatus(zoCheck('Dampalit', ['49230'], ['existing_malabon_business' => true], ['lot_zone' => 'I-1'], ['lot_area_sqm' => 1500]), 'V-2.12-TRUCK'))->toBe('met');
});

it('checks a container yard for a one-hectare lot and three-layer stacking', function () {
    $yard = [['psic_code_id' => zoPsic('52101'), 'description' => 'Container yard']];
    expect(zoStatus(zoCheck('Dampalit', $yard, ['container_layers' => 3], ['lot_zone' => 'I-1'], ['lot_area_sqm' => 5000]), 'V-2.12-CONT'))->toBe('not_met');
    expect(zoStatus(zoCheck('Dampalit', $yard, ['container_layers' => 4], ['lot_zone' => 'I-1'], ['lot_area_sqm' => 12000]), 'V-2.12-CONT'))->toBe('not_met');
    expect(zoStatus(zoCheck('Dampalit', $yard, ['container_layers' => 3], ['lot_zone' => 'I-1'], ['lot_area_sqm' => 12000]), 'V-2.12-CONT'))->toBe('met');
});

it('keeps pollutive industry out of I-1', function () {
    expect(zoStatus(zoCheck('Dampalit', ['10711'], ['industry_pollutive' => true], ['lot_zone' => 'I-1']), 'V-2.12-CLASS'))->toBe('not_met');
    expect(zoStatus(zoCheck('Dampalit', ['10711'], ['industry_pollutive' => false], ['lot_zone' => 'I-1']), 'V-2.12-CLASS'))->toBe('met');
    expect(zoFinding(zoCheck('Dampalit', ['10711'], ['industry_pollutive' => true], ['lot_zone' => 'I-1']), 'V-2.12-CLASS')['rules'])->toContain('V-2.13-CLASS');
    // I-2 takes pollutive industry, so the rule does not reach a lot there.
    expect(zoFinding(zoCheck('Acacia', ['10711'], ['industry_pollutive' => true], ['lot_zone' => 'I-2']), 'V-2.12-CLASS'))->toBeNull();
});

it('checks transport terminals for room to manoeuvre inside the compound', function () {
    $r = zoCheck('Longos', ['49221'], ['terminal_units' => 10, 'onsite_maneuvering' => false], ['lot_zone' => 'CBD']);

    expect(zoStatus($r, 'V-3-J-1'))->toBe('review')
        ->and(zoStatus($r, 'V-3-J-4'))->toBe('not_met')
        ->and(zoStatus($r, 'V-2.11-BUS'))->toBe('not_met')
        ->and(zoFinding($r, 'V-3-J-8')['reason'])->toContain('1:500');
});

// ── Special uses (§3) ────────────────────────────────────────────────────

it('lists the special-use permit and its conditions for a special use', function () {
    $r = zoCheck('Acacia', ['46303'], [], ['special_use' => 'slaughterhouse']);

    expect(zoFinding($r, 'V-3')['status'])->toBe('review')
        ->and(zoFinding($r, 'V-3')['reason'])->toContain('special use permit')
        ->and(zoFinding($r, 'V-2.13-SLAUGHTER'))->not->toBeNull();
});

it('measures a cemetery against 20 m from homes and 50 m from water', function () {
    $r = zoCheck('Tugatog', ['96990'], ['distance_to_residence_m' => 10, 'distance_to_water_m' => 60], ['special_use' => 'cemetery']);

    expect(zoStatus($r, 'V-3-A-1'))->toBe('not_met')->and(zoStatus($r, 'V-3-A-2'))->toBe('met');
});

it('lists the funeral-establishment documents for a funeral parlour', function () {
    $f = zoFinding(zoCheck('Acacia', ['96301']), 'V-3-B-DOCS');

    expect($f['reason'])->toContain('1:10,000')->toContain('sanitary clearance')->toContain('1:200');
});

it('measures open storage against 200 m from institutions', function () {
    $r = zoCheck('Catmon', ['52101'], ['open_storage' => true, 'distance_to_institution_m' => 150]);

    expect(zoStatus($r, 'V-3-D-1'))->toBe('not_met')->and(zoFinding($r, 'V-3-D-1')['rules'])->toContain('A-70');
});

it('measures a slaughterhouse against its distance rules', function () {
    $r = zoCheck('Acacia', ['46303'], [
        'distance_to_residence_m' => 150, 'distance_to_institution_m' => 400, 'distance_to_market_m' => 0,
        'has_ecc' => false, 'neighbour_statements' => false,
    ], ['special_use' => 'slaughterhouse']);

    expect(zoStatus($r, 'V-3-E-1'))->toBe('not_met')
        ->and(zoFinding($r, 'V-3-E-3')['reason'])->toContain('premises of a public market')
        ->and(zoFinding($r, 'V-3-E-3')['rules'])->toContain('V-3-E-4')
        ->and(zoStatus($r, 'V-3-E-5-6'))->toBe('not_met')
        ->and(zoStatus($r, 'V-3-E-8'))->toBe('not_met');

    // 25 m from a market or other food business, not merely off its premises.
    $near = zoFinding(zoCheck('Acacia', ['46303'], ['distance_to_market_m' => 20], ['special_use' => 'slaughterhouse']), 'V-3-E-3');
    expect($near['status'])->toBe('not_met')->and($near['reason'])->toContain('25 m');
    expect(zoStatus(zoCheck('Acacia', ['46303'], ['distance_to_market_m' => 30], ['special_use' => 'slaughterhouse']), 'V-3-E-3'))->toBe('met');
});

it('keeps a cockpit to a parks zone and 200 m from homes', function () {
    expect(zoStatus(zoCheck('Longos', ['93290'], ['distance_to_residence_m' => 300, 'distance_to_institution_m' => 300], ['special_use' => 'cockpit']), 'V-3-F-1'))->toBe('not_met');
    expect(zoStatus(zoCheck('Catmon', ['93290'], ['distance_to_residence_m' => 300, 'distance_to_institution_m' => 300], ['special_use' => 'cockpit']), 'V-3-F-1'))->toBe('met');
});

it('requires CENRO’s site recommendation for a materials recovery facility', function () {
    expect(zoStatus(zoCheck('Catmon', ['38110'], ['cenro_recommended' => false]), 'V-3-H-1'))->toBe('not_met');
    expect(zoStatus(zoCheck('Catmon', ['38110'], ['cenro_recommended' => true]), 'V-3-H-1'))->toBe('met');
});

it('keeps billboards to the National Road and 100 m apart', function () {
    $r = zoCheck('Tugatog', ['73100'], ['fronts_national_road' => false, 'distance_to_billboard_m' => 50], ['special_use' => 'billboard']);

    expect(zoStatus($r, 'V-3-I-1'))->toBe('not_met')->and(zoStatus($r, 'V-3-I-2c'))->toBe('not_met');
});

// ── Overlays (§4) and waterways (§2.14) ──────────────────────────────────

it('applies the flood overlay everywhere and the heritage and eco-tourism overlays where Annex C draws them', function () {
    foreach (Barangay::pluck('name') as $name) {
        expect(zoFinding(zoCheck($name, []), 'V-4.1-USES'))->not->toBeNull("{$name} has no flood finding");
    }
    expect(zoFinding(zoCheck('Baritan', ['47111']), 'V-4.3-USES')['asks'])->toBe(['heritage_house']);
    expect(zoFinding(zoCheck('Dampalit', ['47111']), 'V-4.2-SCOPE')['asks'])->toBe(['in_fishpond_area']);

    $longos = zoCheck('Longos', ['47111']);
    expect(zoFinding($longos, 'V-4.3-USES'))->toBeNull()->and(zoFinding($longos, 'V-4.2-SCOPE'))->toBeNull();
    expect(zoFinding($longos, 'ANNEX-C'))->not->toBeNull();
    expect(zoFinding($longos, 'V-4.1-USES')['rules'])->toContain('IV-3', 'V-4');
});

it('limits a declared heritage house to its listed uses on the ground floor', function () {
    $upper = zoCheck('Baritan', ['47111'], ['heritage_house' => true, 'business_floor' => 'upper']);
    expect(zoStatus($upper, 'V-4.3-USES'))->toBe('not_met');
    expect(zoStatus(zoCheck('Baritan', ['47111'], ['heritage_house' => true, 'business_floor' => 'ground']), 'V-4.3-USES'))->toBe('met');
    // Manufacturing is not among a heritage house's uses.
    expect(zoStatus(zoCheck('Baritan', ['10711'], ['heritage_house' => true, 'business_floor' => 'ground']), 'V-4.3-USES'))->toBe('not_met');

    $altered = zoFinding(zoCheck('Baritan', ['47111'], ['heritage_house' => true, 'house_altered' => 'outside']), 'V-4.3-BULK');
    expect($altered['status'])->toBe('not_met')->and($altered['rules'])->toContain('V-4.3-DESIGN');
});

it('sends new construction in the heritage overlay to CPDO under R-1 uses', function () {
    $wholesale = zoFinding(zoCheck('Baritan', ['46900'], ['heritage_house' => false, 'new_construction' => true]), 'V-4.3-NEWUSES');
    expect($wholesale['status'])->toBe('review')->and($wholesale['question'])->toBe('C25');

    // A nursery school is an R-1 use.
    expect(zoStatus(zoCheck('Baritan', ['85100'], ['heritage_house' => false, 'new_construction' => true]), 'V-4.3-NEWUSES'))->toBe('met');
});

it('applies the eco-tourism rules to a business in Dampalit’s fishponds', function () {
    $r = zoCheck('Dampalit', ['56101'], ['in_fishpond_area' => true], [], ['floor_area_sqm' => 400, 'lot_area_sqm' => 1000, 'storeys' => 2]);

    expect(zoStatus($r, 'V-4.2-AREA'))->toBe('not_met')
        ->and(zoStatus($r, 'V-4.2-STOREY'))->toBe('not_met')
        ->and(zoStatus($r, 'V-4.2-USES'))->toBe('met')
        ->and(zoFinding($r, 'V-2.16')['question'])->toBe('C17')
        ->and(zoFinding($r, 'V-4.2-USES')['rules'])->toContain('V-4.2-SCOPE');

    $ok = zoCheck('Dampalit', ['56101'], ['in_fishpond_area' => true], [], ['floor_area_sqm' => 200, 'lot_area_sqm' => 1000, 'storeys' => 1]);
    expect(zoStatus($ok, 'V-4.2-AREA'))->toBe('met')->and(zoStatus($ok, 'V-4.2-STOREY'))->toBe('met');
});

it('checks the waterway easement for a lot beside a river or creek', function () {
    $near = zoFinding(zoCheck('Niugan', ['47111'], ['beside_waterway' => true, 'waterway_name' => 'tullahan', 'waterway_setback_m' => 2]), 'V-2.14-3M');
    expect($near['status'])->toBe('not_met')->and($near['rules'])->toContain('IV-6-h', 'V-2.14-NOBLD');

    expect(zoStatus(zoCheck('Niugan', ['47111'], ['beside_waterway' => true, 'waterway_name' => 'tullahan', 'waterway_setback_m' => 5]), 'V-2.14-3M'))->toBe('met');

    // The CAMANAVA stretches need 7.5 m, and only CPDO knows which stretch this is.
    $camanava = zoFinding(zoCheck('Dampalit', ['47111'], ['beside_waterway' => true, 'waterway_name' => 'dampalit', 'waterway_setback_m' => 5]), 'V-2.14-7.5M');
    expect($camanava['status'])->toBe('review')->and($camanava['question'])->toBe('C23');

    expect(zoStatus(zoCheck('Niugan', ['47111'], ['beside_waterway' => false]), 'V-2.14-USES'))->toBe('met');
});

// ── Signs, the site, performance ─────────────────────────────────────────

it('flags a roof sign and a sign over public property', function () {
    $r = zoCheck('Niugan', ['47111'], ['has_sign' => true, 'sign_over_public' => true, 'roof_sign' => true]);

    expect(zoStatus($r, 'VII-13-NUISANCE'))->toBe('not_met')
        ->and(zoFinding($r, 'VII-13-NUISANCE')['rules'])->toContain('V-3-I-2m-n')
        ->and(zoFinding($r, 'V-3-I-2j')['question'])->toBe('C21')
        ->and(zoStatus($r, 'VII-13-LC'))->toBe('review')
        ->and(zoFinding($r, 'VII-13-LC')['rules'])->toContain('VII-5');
});

it('flags parking that spills into the street', function () {
    expect(zoStatus(zoCheck('Niugan', ['47111'], ['parking_on_street' => true]), 'VI-5-3'))->toBe('not_met');
    expect(zoStatus(zoCheck('Niugan', ['47111'], ['parking_on_street' => false]), 'VI-5-3'))->toBe('met');
});

it('asks for the homeowners’ or barangay’s approval for a business in a residential zone', function () {
    $lot = ['lot_zone' => 'R-2-MAX'];
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'hoa_consent' => false], $lot), 'VI-8-HOA'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'hoa_consent' => true], $lot), 'VI-8-HOA'))->toBe('met');
    // A shop in a commercial zone, not run from a home, is not asked.
    expect(zoFinding(zoCheck('Niugan', ['47111'], ['home_based' => false], ['lot_zone' => 'C-1']), 'VI-8-HOA'))->toBeNull();
});

it('names the road-widening setback for a site on one of the seven roads', function () {
    expect(zoFinding(zoCheck('Tugatog', ['47111'], extra: ['street' => 'Gov. Pascual Ave.']), 'VI-8-ROAD')['reason'])->toContain('3 m');
    expect(zoFinding(zoCheck('Tugatog', ['47111'], extra: ['street' => 'M.H. del Pilar St.']), 'VI-8-ROAD')['reason'])->toContain('1 m');
    expect(zoFinding(zoCheck('Tugatog', ['47111'], extra: ['street' => 'Sisa St.']), 'VI-8-ROAD'))->toBeNull();
});

it('asks traffic-generating trades for on-site parking, a traffic statement and a drainage study', function () {
    $f = zoFinding(zoCheck('Potrero', ['47190']), 'VII-4');

    expect($f['status'])->toBe('review')
        ->and($f['rules'])->toContain('VI-5-4', 'VI-6-1', 'VI-6-2')
        ->and($f['reason'])->toContain('Traffic Impact Statement');
});

it('asks for the neighbour’s consent when new construction abuts their lot', function () {
    expect(zoStatus(zoCheck('Niugan', ['47111'], ['new_construction' => true, 'abuts_neighbour' => true, 'neighbour_consent' => false]), 'VI-5-2'))->toBe('not_met');
    expect(zoStatus(zoCheck('Niugan', ['47111'], ['new_construction' => true, 'abuts_neighbour' => false]), 'VI-5-2'))->toBe('met');
});

it('compares the building’s storeys with the barangay’s soil-hazard advice', function () {
    // Baritan is Obando fine sandy loam: analysis above one storey.
    expect(zoStatus(zoCheck('Baritan', ['47111'], ['new_construction' => true], [], ['storeys' => 2]), 'VI-7'))->toBe('review');
    expect(zoStatus(zoCheck('Baritan', ['47111'], ['new_construction' => true], [], ['storeys' => 1]), 'VI-7'))->toBe('met');
    // Dampalit is hydrosol: analysis above two.
    expect(zoStatus(zoCheck('Dampalit', ['47111'], ['new_construction' => true], [], ['storeys' => 2]), 'VI-7'))->toBe('met');
});

it('asks CPDO about the 4 m buffer when the lot adjoins a conflicting zone', function () {
    expect(zoStatus(zoCheck('Niugan', ['47111'], [], ['lot_zone' => 'C-1', 'adjoins_conflicting_zone' => true, 'buffer_provided' => false]), 'VII-11'))->toBe('not_met');
    expect(zoStatus(zoCheck('Niugan', ['47111'], [], ['lot_zone' => 'C-1', 'adjoins_conflicting_zone' => true, 'buffer_provided' => true]), 'VII-11'))->toBe('met');
});

it('asks a parking business with 20 or more slots for trees and permeable paving', function () {
    $lot = [['psic_code_id' => zoPsic('68100'), 'description' => 'Pay parking lot']];
    expect(zoStatus(zoCheck('Niugan', $lot, ['parking_slots' => 25]), 'VI-4-4'))->toBe('review');
    expect(zoStatus(zoCheck('Niugan', $lot, ['parking_slots' => 10]), 'VI-4-4'))->toBe('met');

    // Twenty or more: trees at least 1.8 m tall and half the paving permeable.
    $bare = zoFinding(zoCheck('Niugan', $lot, ['parking_slots' => 25, 'parking_landscaped' => false]), 'VI-4-4');
    expect($bare['status'])->toBe('not_met')->and($bare['reason'])->toContain('1.8 m');
    expect(zoStatus(zoCheck('Niugan', $lot, ['parking_slots' => 25, 'parking_landscaped' => true]), 'VI-4-4'))->toBe('met');
    // An answer that it is a pay parking lot reaches the rule without the word in the description.
    expect(zoStatus(zoCheck('Niugan', ['68100'], ['vehicle_use' => 'parking_lot', 'parking_slots' => 25, 'parking_landscaped' => false]), 'VI-4-4'))->toBe('not_met');
});

it('lists the performance standards an industrial or noisy business must meet', function () {
    $unanswered = zoCheck('Catmon', ['10711']);
    expect(zoFinding($unanswered, 'VI-1')['missing'])->toContain('industry_pollutive');
    expect(zoFinding($unanswered, 'VI-2-13')['rules'])->toContain('VI-8-WASTE');

    $r = zoCheck('Catmon', ['10711'], ['industry_pollutive' => true, 'industry_hazardous' => true, 'nuisance_equipment' => true]);
    expect(zoFinding($r, 'VI-8-NOISE')['rules'])->toContain('VI-5-6', 'VI-5-7', 'VI-8-GLARE')
        ->and(zoFinding($r, 'VI-2-11')['rules'])->toContain('VI-8-SMOKE', 'VI-8-DUST', 'VI-8-ODOR')
        ->and(zoFinding($r, 'VI-2-8'))->not->toBeNull();
});

it('asks for an NWRB permit when the business draws from a deep well', function () {
    expect(zoFinding(zoCheck('Catmon', ['36000'], ['deep_well' => true]), 'VI-2-2')['reason'])->toContain('National Water Resources Board');
    expect(zoStatus(zoCheck('Catmon', ['36000'], ['deep_well' => false]), 'VI-2-2'))->toBe('met');
});

// ── Procedure (Art. IX) ──────────────────────────────────────────────────

it('holds the locational clearance for an ECC when DENR’s list says the trade needs one', function () {
    $code = PsicCode::pluck('code')->first(fn ($c) => $c !== '47300'
        && (DenrRequirements::resolve([], [$c])['row']['certificate'] ?? null) === 'ECC');
    expect($code)->not->toBeNull();

    $f = zoFinding(zoCheck('Catmon', [$code], ['has_ecc' => false]), 'IX-6');
    expect($f['status'])->toBe('not_met')->and($f['rules'])->toContain('III-1-ECP');
    expect(zoStatus(zoCheck('Catmon', [$code], ['has_ecc' => true]), 'IX-6'))->toBe('met');
});

it('puts the zoning clearance on every new filing', function () {
    expect(PermitType::REQUIRED_CLEARANCE_CODES)->toContain('ZONING');
    $app = new Application(['application_type' => 'new']);
    expect(PermitType::whereIn('id', WorkflowService::permitTypeIdsAtSubmission($app))->pluck('code')->all())->toContain('ZONING');

    $f = zoFinding(zoCheck('Niugan', ['47111']), 'IX-2');
    expect($f['status'])->toBe('met')->and($f['rules'])->toContain('IX-8-NEW');
});

it('treats a held locational clearance unused for a year as expired', function () {
    $old = zoCheck('Niugan', ['47111'], ['held_lc' => true, 'held_lc_issued_on' => now()->subYears(2)->toDateString(), 'held_lc_for_construction' => false]);
    expect(zoStatus($old, 'IX-9-USE'))->toBe('not_met');

    $recent = zoCheck('Niugan', ['47111'], ['held_lc' => true, 'held_lc_issued_on' => now()->subMonths(3)->toDateString(), 'held_lc_for_construction' => false]);
    expect(zoStatus($recent, 'IX-9-USE'))->toBe('met');
});

it('refuses a construction-only clearance as the business’s clearance', function () {
    expect(zoStatus(zoCheck('Niugan', ['47111'], ['held_lc' => true, 'held_lc_for_construction' => true]), 'IX-10.2-C'))->toBe('not_met');
});

it('tells a renewal whose trade is not listed that it runs as a non-conforming use', function () {
    $f = zoFinding(zoCheck('Muzon', ['62010'], ['operating_since_year' => 2010], extra: ['application_type' => 'renewal']), 'IX-12-0');

    expect($f['status'])->toBe('review')
        ->and($f['reason'])->toContain('Certificate of Non-Conformance')->toContain('renewed yearly')
        ->and($f['rules'])->toContain('IX-11-NOTICE', 'IX-11-VALID', 'IX-10.2-D', 'III-1-NCU', 'III-1-CNC');

    // A listed trade is not non-conforming.
    expect(zoFinding(zoCheck('Muzon', ['47111'], extra: ['application_type' => 'renewal']), 'IX-12-0'))->toBeNull();
});

it('flags a non-conforming use that has grown, stopped for a year, or is changing', function () {
    $renewal = ['application_type' => 'renewal'];
    $since = ['operating_since_year' => 2010];
    expect(zoStatus(zoCheck('Muzon', ['62010'], $since + ['nonconforming_expanded' => true], extra: $renewal), 'IX-12-1'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['62010'], $since + ['nonconforming_ceased' => true], extra: $renewal), 'IX-12-2'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['62010'], $since + ['nuisance_equipment' => true], extra: $renewal), 'IX-12-8'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['62010'], $since + ['lzeac_allowed' => true], extra: $renewal), 'IX-12-10'))->toBe('info');

    $amend = ['application_type' => 'amendment'];
    expect(zoStatus(zoCheck('Muzon', ['62010'], $since, extra: $amend, amendment: ['line_changed' => true, 'area_expanded' => false, 'moved' => false]), 'IX-12-6'))->toBe('not_met');
    expect(zoStatus(zoCheck('Muzon', ['62010'], $since, extra: $amend, amendment: ['line_changed' => false, 'area_expanded' => false, 'moved' => true]), 'IX-12-7'))->toBe('not_met');
});

it('shows both the continue-until-closing rule and the ten-year phase-out', function () {
    $f = zoFinding(zoCheck('Muzon', ['62010'], ['operating_since_year' => 2010], extra: ['application_type' => 'renewal']), 'IX-12-9');

    expect($f['rules'])->toContain('IX-11-CONTINUE')
        ->and($f['group'])->toBe('conflict')
        ->and($f['question'])->toBe('C16')
        ->and($f['reason'])->toContain('ten years');
});

// ── How the findings read ────────────────────────────────────────────────

it('reports a mandatory rule as not met and a recommendation only as information', function () {
    // "shall not exceed five (5)" is a shall: a failed answer is Not met.
    expect(zoStatus(zoCheck('Muzon', ['47111'], ['home_based' => true, 'persons_engaged' => 6], ['lot_zone' => 'R-2-MAX']), 'V-2.1-HO-1'))->toBe('not_met');
    // "Recommended no. of storeys" and "Buildings on stilts are encouraged" are not.
    expect(zoStatus(zoCheck('Baritan', ['47111']), 'VI-7'))->toBe('info')
        ->and(zoFinding(zoCheck('Baritan', ['47111']), 'VI-7')['rules'])->toContain('III-2-f');
    expect(zoStatus(zoCheck('Dampalit', ['56101'], ['in_fishpond_area' => true]), 'V-4.2-DESIGN'))->toBe('info');
});

it('uses the ordinance’s own definitions where a rule depends on one', function () {
    // Annex A 23's ₱100,000 cottage ceiling, Annex A 67's 30 m strip, Annex A 70's open storage.
    expect(zoFinding(zoCheck('Muzon', ['10711'], ['home_based' => true], ['lot_zone' => 'R-2-MAX'], ['capitalization' => 120000]), 'A-23')['reason'])->toContain('₱100,000');
    expect(zoFinding(zoCheck('Bayan-bayanan', [], extra: ['street' => 'Gen. Luna St.']), 'A-66-67')['reason'])->toContain('30 m');
    expect(zoFinding(zoCheck('Catmon', ['52101'], ['open_storage' => true]), 'A-70'))->not->toBeNull();
});

it('softens a not-met condition to CPDO review while the lot could be in a zone it does not bind', function () {
    // Muzon is Maximum R-2 and Institutional: six people breaks the home-
    // occupation rule only if the lot is in the residential zone.
    $f = zoFinding(zoCheck('Muzon', ['47111'], ['home_based' => true, 'persons_engaged' => 6]), 'V-2.1-HO-1');

    expect($f['status'])->toBe('review')->and($f['scope'])->toContain('Maximum Residential-2');
});

// ── The three doors ──────────────────────────────────────────────────────

it('previews the zoning check for the wizard without saving anything', function () {
    $owner = authAs('owner@biztrack.local');
    $before = Application::count();

    $res = $this->withHeaders($owner)->postJson('/api/v1/zoning-check', [
        'barangay_id' => Barangay::where('name', 'Muzon')->value('id'),
        'application_type' => 'new',
        'lines' => [['psic_code_id' => zoPsic('47111')]],
        'zoning_facts' => ['home_based' => true, 'persons_engaged' => 6, 'not_a_question' => 'dropped'],
    ])->assertOk();

    expect($res->json('data.ready'))->toBeTrue()
        ->and(collect($res->json('data.findings'))->pluck('rule'))->toContain('V-2.1-HO-1')
        ->and(collect($res->json('data.facts'))->pluck('key'))->toContain('persons_engaged')
        ->and(Application::count())->toBe($before);
});

it('saves the applicant’s zoning answers with the draft and drops unknown keys', function () {
    $owner = authAs('owner@biztrack.local');
    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Zoning Answers Store',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-77301',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '1 Zoning St.', 'barangay_id' => Barangay::where('name', 'Muzon')->value('id')],
        'lines' => [['psic_code_id' => zoPsic('47111'), 'capitalization' => 50000]],
    ])->assertCreated()->json('data.id');

    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
        'zoning_facts' => ['home_based' => true, 'lot_zone' => 'C-1'],
    ])->assertCreated()->json('data.id');

    // The applicant cannot write CPDO's own answer.
    expect(Application::find($appId)->zoning_facts)->toBe(['home_based' => true]);

    $this->withHeaders($owner)->putJson("/api/v1/applications/{$appId}", [
        'zoning_facts' => ['home_based' => true, 'persons_engaged' => 3],
    ])->assertOk()->assertJsonPath('data.zoning_facts.persons_engaged', 3);

    $this->withHeaders($owner)->getJson("/api/v1/applications/{$appId}/zoning-check")
        ->assertOk()
        ->assertJsonPath('data.ready', true);
});

it('lets only the zoning office record the lot’s zone', function () {
    $app = Application::findOrFail(scopedAssignmentFiling('Zoning Facts Filing'));
    assignOffice($app->id, 'CPDO');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/zoning-facts", ['facts' => ['lot_zone' => 'C-1']])
        ->assertForbidden();
    $this->withHeaders(authAs('sanitary@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/zoning-facts", ['facts' => ['lot_zone' => 'C-1']])
        ->assertForbidden();

    $res = $this->withHeaders(authAs('zoning@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/zoning-facts", ['facts' => ['lot_zone' => 'C-1', 'parking_on_street' => false]])
        ->assertOk();

    expect($app->fresh()->zoning_officer_facts)->toMatchArray(['lot_zone' => 'C-1', 'parking_on_street' => false]);
    $parking = collect($res->json('data.facts'))->firstWhere('key', 'parking_on_street');
    expect($parking['answered_by'])->toBe('officer');
});

// ── Definitions that carry a rule (Art. III §1; Annex A) ─────────────────

it('allows a home business in a residential zone only through the home-occupation clause', function () {
    // A lawyer's office: no residential list names it, but §2.1's home
    // occupation does — for a business run from a house someone lives in.
    $home = zoFinding(zoCheck('Potrero', ['69100'], ['home_based' => true], ['lot_zone' => 'R-1']), 'V-2');
    expect($home['status'])->toBe('met')->and($home['rules'])->toContain('V-2.1-HO')
        ->and($home['reason'])->toContain('run from a home');

    $office = zoFinding(zoCheck('Potrero', ['69100'], ['home_based' => false], ['lot_zone' => 'R-1']), 'A-89');
    expect($office['status'])->toBe('review');
});

it('reads a hotel whose rooms have kitchens as a hotel apartment', function () {
    $hotel = zoCheck('Niugan', ['55101'], ['rooms_have_kitchens' => false], ['lot_zone' => 'C-1']);
    expect(zoStatus($hotel, 'A-44'))->toBe('met')
        ->and(zoFinding($hotel, 'V-2')['reason'])->toContain('Hotel');

    $apartel = zoCheck('Niugan', ['55101'], ['rooms_have_kitchens' => true], ['lot_zone' => 'C-1']);
    expect(zoStatus($apartel, 'A-44'))->toBe('info')
        ->and(zoFinding($apartel, 'V-2')['reason'])->toContain('Apartel')
        ->and(zoFinding($apartel, 'V-2')['rules'])->toContain('A-45');
    expect(zoStatus(zoCheck('Niugan', ['55101'], [], ['lot_zone' => 'C-1']), 'A-44'))->toBe('review');
});

it('reads dry cleaning with flammable solvents as an Industrial-2 plant', function () {
    // Niugan has no Industrial-2: a laundry is listed, a solvent plant is not.
    $laundry = zoCheck('Niugan', ['96200'], ['flammable_solvents' => false], ['lot_zone' => 'C-1']);
    expect(zoStatus($laundry, 'A-28'))->toBe('met')->and(zoStatus($laundry, 'V-2'))->toBe('met');

    $plant = zoCheck('Niugan', ['96200'], ['flammable_solvents' => true], ['lot_zone' => 'C-1']);
    expect(zoStatus($plant, 'A-28'))->toBe('not_met')
        ->and(collect($plant['findings'])->where('group', 'uses')->where('status', 'met')->pluck('rule'))->not->toContain('V-2');

    $i2 = zoCheck('Acacia', ['96200'], ['flammable_solvents' => true], ['lot_zone' => 'I-2']);
    expect(zoFinding($i2, 'V-2')['status'])->toBe('met')
        ->and(zoFinding($i2, 'V-2')['reason'])->toContain('Dry cleaning plants using flammable liquids');
});

it('reads a lessor’s building as an apartment building from three families', function () {
    $lot = ['lot_zone' => 'R-1'];
    $duplex = zoCheck('Potrero', ['68100'], ['leases_what' => 'dwellings', 'families_in_building' => 2], $lot);
    expect(zoStatus($duplex, 'III-1-APT'))->toBe('met')->and(zoStatus($duplex, 'V-2'))->toBe('met');

    // Residential-1 lists houses and duplexes, not apartment buildings.
    $apartments = zoCheck('Potrero', ['68100'], ['leases_what' => 'dwellings', 'families_in_building' => 4], $lot);
    expect(zoFinding($apartments, 'III-1-APT')['reason'])->toContain('apartment building')
        ->and(zoFinding($apartments, 'A-89'))->not->toBeNull();

    expect(zoStatus(zoCheck('Potrero', ['68100'], ['leases_what' => 'dwellings', 'families_in_building' => 4], ['lot_zone' => 'R-2-BASIC']), 'V-2'))->toBe('met');
    expect(zoStatus(zoCheck('Potrero', ['68100'], [], $lot), 'III-1-APT'))->toBe('review');
});

it('keeps coffins and wreaths out of a funeral chapel at a cemetery', function () {
    $lot = ['lot_zone' => 'CEMETERY'];
    expect(zoStatus(zoCheck('Tugatog', ['96301'], ['sells_coffins' => true], $lot), 'A-38'))->toBe('not_met');
    expect(zoStatus(zoCheck('Tugatog', ['96301'], ['sells_coffins' => false], $lot), 'A-38'))->toBe('met');
    // A funeral parlour in Commercial-2 is not a fraternal chapel.
    expect(zoFinding(zoCheck('Acacia', ['96301'], ['sells_coffins' => true], ['lot_zone' => 'C-2']), 'A-38'))->toBeNull();
});

it('keeps retail out of an office building', function () {
    expect(zoStatus(zoCheck('Niugan', ['47111'], ['in_office_building' => true], ['lot_zone' => 'C-1']), 'A-64'))->toBe('not_met');
    expect(zoStatus(zoCheck('Niugan', ['47111'], ['in_office_building' => false], ['lot_zone' => 'C-1']), 'A-64'))->toBe('met');
    // A professional's office is not retail merchandising.
    expect(zoFinding(zoCheck('Niugan', ['69100'], [], ['lot_zone' => 'C-1']), 'A-64'))->toBeNull();
});

it('keeps a home pet business’s pet house to 4 sq. m. and out of the business', function () {
    $home = ['home_based' => true];
    $lot = ['lot_zone' => 'R-1'];
    expect(zoStatus(zoCheck('Potrero', ['47760'], ['pet_house_area_sqm' => 6] + $home, $lot), 'V-2.1-PET'))->toBe('not_met');
    expect(zoStatus(zoCheck('Potrero', ['47760'], ['pet_house_area_sqm' => 3] + $home, $lot), 'V-2.1-PET'))->toBe('review');
    expect(zoStatus(zoCheck('Potrero', ['47760'], ['pet_house_area_sqm' => 0] + $home, $lot), 'V-2.1-PET'))->toBe('met');
});

it('keeps warfare research out of the Institutional zone', function () {
    $lab = zoPsic('72100', 'Research and experimental development on natural sciences and engineering');
    $lot = ['lot_zone' => 'INSTITUTIONAL'];
    expect(zoStatus(zoCheck('Potrero', [$lab], ['warfare_research' => true], $lot), 'V-2.20-RESEARCH'))->toBe('not_met');
    expect(zoStatus(zoCheck('Potrero', [$lab], ['warfare_research' => false], $lot), 'V-2.20-RESEARCH'))->toBe('met');
});

it('limits new construction beside a heritage house to its roof apex and its period design', function () {
    $new = ['heritage_house' => false, 'new_construction' => true];
    expect(zoStatus(zoCheck('Concepcion', ['47111'], ['above_heritage_apex' => true] + $new), 'V-4.3-BHL'))->toBe('not_met');
    expect(zoStatus(zoCheck('Concepcion', ['47111'], ['above_heritage_apex' => false] + $new), 'V-4.3-BHL'))->toBe('met');
    expect(zoStatus(zoCheck('Concepcion', ['47111'], ['period_design' => false] + $new), 'V-4.3-NEWDESIGN'))->toBe('not_met');
    expect(zoStatus(zoCheck('Concepcion', ['47111'], ['period_design' => true] + $new), 'V-4.3-NEWDESIGN'))->toBe('met');
    // Not built new: neither applies.
    expect(zoFinding(zoCheck('Concepcion', ['47111'], ['heritage_house' => false, 'new_construction' => false]), 'V-4.3-BHL'))->toBeNull();
});

// ── The ordinance's contradictions, named where they bear ────────────────

it('names the conditions General Commercial leaves out, for a lot there too', function () {
    // C-1 attaches parking, façade and grease-trap conditions to auto repair;
    // General Commercial lists it with none. Not imposed there, not silent.
    $gc = zoCheck('Catmon', ['45201'], [], ['lot_zone' => 'GENERAL-COMMERCIAL']);
    $flat = zoAsked($gc, 'C28');
    expect($flat['status'])->toBe('review')->and($flat['rules'])->toContain('V-2.10-FLAT', 'V-2.7-AUTO')
        ->and($flat['reason'])->toContain('General Commercial');
    expect(zoFinding($gc, 'V-2.7-AUTO')['rule'])->toBe('V-2.10-FLAT');

    expect(zoAsked(zoCheck('Catmon', ['92000'], [], ['lot_zone' => 'GENERAL-COMMERCIAL']), 'C28')['rules'])->toContain('V-2.7-LOTTO');
    // And the other way round: C-2 lists hauling with none of GC's condition.
    expect(zoAsked(zoCheck('Acacia', ['49230'], [], ['lot_zone' => 'C-2']), 'C28')['rules'])->toContain('V-2.10-HAUL');
});

it('names the two height caps where Commercial-2 adjoins Residential-1', function () {
    $f = zoAsked(zoCheck('Potrero', ['47111'], ['new_construction' => true], ['lot_zone' => 'C-2']), 'C29');
    expect($f['rule'])->toBe('VII-1-1-3')->and($f['reason'])->toContain('12 m')->toContain('9 m');
});

it('sets the soil advice beside a 180 m height limit', function () {
    $f = zoAsked(zoCheck('Potrero', ['47111'], ['new_construction' => true], ['lot_zone' => 'C-3']), 'C30');
    expect($f['status'])->toBe('review')->and($f['reason'])->toContain('180 m')->toContain('1 to 4');
});

it('names the contradictions in the special uses: cockpits, filling stations, billboards, MRFs', function () {
    $cockpit = zoCheck('Catmon', ['93290'], [], ['special_use' => 'cockpit']);
    expect(zoAsked($cockpit, 'C31')['reason'])->toContain('does not name cockpits');

    $station = zoCheck('Muzon', ['47300'], [], ['lot_zone' => 'R-2-MAX']);
    expect(collect($station['findings'])->firstWhere('title', 'A residential clause for a use no residential zone lists')['question'])->toBe('C13');

    $billboard = zoCheck('Catmon', ['73100'], [], ['special_use' => 'billboard']);
    expect(collect($billboard['findings'])->firstWhere('title', 'Where a billboard may stand')['reason'])
        ->toContain('whether it be National Road')->toContain('29-30');

    $mrf = zoFinding(zoCheck('Catmon', ['38110']), 'V-3-H-2-5');
    expect($mrf['question'])->toBe('C24')->and($mrf['reason'])->toContain('Local Zoning Committee')
        ->and($mrf['rules'])->toContain('VI-1-IRR');
});

it('names an activity the ordinance lists both as commerce and as industry', function () {
    $f = zoAsked(zoCheck('Acacia', ['31001'], [], ['lot_zone' => 'C-2']), 'C32');
    expect($f['reason'])->toContain('box beds')->and($f['rules'])->toContain('V-2.13-USES');
    expect(zoAsked(zoCheck('Acacia', ['10799'], [], ['lot_zone' => 'I-2']), 'C32')['reason'])->toContain('ice plants');
});

it('names Industrial-2’s missing warehouse line for a warehouse there', function () {
    expect(zoAsked(zoCheck('Acacia', ['52101'], [], ['lot_zone' => 'I-2']), 'C33')['rules'])->toContain('V-2.13-USES');
});

it('names the riverbank that is both easement and Mangrove Zone', function () {
    $f = zoAsked(zoCheck('Dampalit', ['47111'], ['beside_waterway' => true]), 'C35');
    expect($f['reason'])->toContain('Mangrove')->and($f['rules'])->toContain('V-2.14-USES');
});

it('names the two sources of the base flood elevation for new construction', function () {
    $f = zoAsked(zoCheck('Longos', ['47111'], ['new_construction' => true]), 'C27');
    expect($f['reason'])->toContain('DPWH')->toContain('DRRMO')->and($f['rules'])->toContain('III-1-BFE');
});

it('names the two grease limits, the missing implementing guidelines and the eco-tourism share', function () {
    expect(zoFinding(zoCheck('Longos', ['56101']), 'VI-8-SEWER')['question'])->toBe('C36');
    expect(zoAsked(zoCheck('Dampalit', ['10711'], ['industry_pollutive' => false]), 'C26')['rule'])->toBe('VI-1-IRR');

    $eco = zoFinding(zoCheck('Dampalit', ['56101'], ['in_fishpond_area' => true], [], ['floor_area_sqm' => 100, 'lot_area_sqm' => 1000]), 'V-4.2-AREA');
    expect($eco['question'])->toBe('C38')->and($eco['reason'])->toContain('3% of the lot');
});

it('names the overlapping and incomplete rows of the boundary table', function () {
    expect(zoAsked(zoCheck('Muzon', []), 'C17')['title'])->toBe('Muzon’s fishponds have no zone in the text');
    expect(zoAsked(zoCheck('Catmon', []), 'C34')['rule'])->toBe('IV-5-PR-UTS');
    expect(zoAsked(zoCheck('Panghulo', []), 'C37')['reason'])->toContain('Panghulo Market');
    expect(zoAsked(zoCheck('Santulan', []), 'C37')['reason'])->toContain('Aurora St. to Javier St.');
});

it('gives Sanciangco St. both of its road-widening readings', function () {
    $f = zoFinding(zoCheck('Tonsuya', ['47111'], [], [], ['street' => 'Sanciangco St.']), 'VI-8-ROAD');
    expect($f['question'])->toBe('C39')->and($f['reason'])->toContain('3 m')->toContain('1 m');
});

it('names the machine-shop clause that reads as forbidding firewalls', function () {
    $f = zoFinding(zoCheck('Acacia', ['25920'], [], ['lot_zone' => 'C-2']), 'V-2.8-MACH');
    expect($f['question'])->toBe('C40')->and($f['reason'])->toContain('makeshift materials with firewalls');
});

it('names every difference between the map sheet and the text, in every barangay', function () {
    $checked = 0;
    foreach (Barangay::with('zoningClassifications')->get() as $barangay) {
        $text = Ordinance::textZones()[Ordinance::key($barangay->name)] ?? [];
        if ($text === []) {
            continue;
        }
        $sheet = $barangay->zoningClassifications->pluck('code')->all();
        $differ = array_merge(
            array_diff($sheet, $text, ['PARKS', 'UTILITIES']),
            array_diff($text, $sheet),
        );
        if ($differ === []) {
            continue;
        }
        $f = zoFinding(zoCheck($barangay->name, []), 'IV-6-g');
        expect($f)->not->toBeNull("{$barangay->name}: no finding names the map/text difference");
        foreach ($differ as $code) {
            expect(str_contains($f['reason'], Ordinance::ZONE_NAMES[$code]))->toBeTrue("{$barangay->name}: {$code} not named");
        }
        $checked++;
    }
    // Twenty of the twenty-one barangays differ somewhere (audit, 3 October 2026).
    expect($checked)->toBeGreaterThanOrEqual(20);

    // And where a listing rests on a zone only the text places there, the
    // use finding says so: Industrial-1 in Catmon is in the text, not on the sheet.
    $noodles = zoFinding(zoCheck('Catmon', ['10740']), 'V-2');
    expect($noodles['reason'])->toContain('map sheet does not draw Industrial-1')->and($noodles['rules'])->toContain('IV-6-g')
        ->and($noodles['question'])->toBe('C11');
});

it('reaches the pay-parking rules from a transport-support or lessor’s filing', function () {
    $mr2 = ['lot_zone' => 'R-2-MAX'];
    $light = ['heavy_vehicles' => false, 'motor_pool' => false];

    // 52290 is not a trucking garage: a pay parking lot filed under it gets
    // the parking rules from its own words, without being asked again…
    $words = [['psic_code_id' => zoPsic('52290'), 'description' => 'Pay parking lot']];
    expect(zoStatus(zoCheck('Tonsuya', $words, $light, $mr2), 'V-2.3-PARK'))->toBe('met');
    expect(zoStatus(zoCheck('Tonsuya', $words, ['heavy_vehicles' => true, 'motor_pool' => false], $mr2), 'V-2.3-PARK'))->toBe('not_met');
    // …and is asked what the vehicles are for when its words do not say.
    $forwarding = [['psic_code_id' => zoPsic('52290'), 'description' => 'Freight forwarding']];
    expect(zoFinding(zoCheck('Tonsuya', $forwarding, [], $mr2), 'V-2.3-PARK')['asks'])->toContain('vehicle_use');

    // A lessor of parking slots says so in the lessor's own question.
    $lessor = zoCheck('Tonsuya', [['psic_code_id' => zoPsic('68100'), 'description' => 'Lessor']], ['leases_what' => 'parking'] + $light, $mr2);
    expect(zoStatus($lessor, 'V-2.3-PARK'))->toBe('met')
        ->and(zoFinding($lessor, 'V-2')['reason'])->toContain('rentable parking lots');
});

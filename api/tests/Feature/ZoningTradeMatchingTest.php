<?php

use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\Zoning\Ordinance;
use App\Support\Zoning\TradeUses;
use App\Support\ZoningConformance;

/*
 * Which line of which zone's list a trade IS — and, as much, which it is not.
 *
 * An audit of 3 October 2026 ran every register code through the matcher and
 * found trades reported as allowed on the strength of one shared word: a
 * gasoline station, an auto-repair shop and a rent-a-car on Maximum R-2's
 * "Water refilling Station … delivery vehicles"; trucking on "Small
 * scale eatery" by way of "road"; waste collection on Residential-1's home
 * industry; a bar on the Institutional zone's "Places of worship". The first
 * test pins each of those as not listed. The second walks the whole register
 * across every zone and holds the matcher to the table in Zoning\TradeUses
 * and to the plain shape of the ordinance — no industry, vehicle trade or
 * waste business in a residential zone, nothing but hospitals and clinics in
 * the Institutional zone — so a new false listing fails here first.
 *
 * The tests that read a trade through the zoning checklist's answers (a
 * tailor or a garment factory, what a lessor leases, what a trade's vehicles
 * are for) went with the checklist on 5 October 2026: nothing asks those
 * questions any more, so every such code is read by its row in TradeUses.
 *
 * Self-contained (no helpers from other files): Pest's parallel runner loads
 * files separately.
 */

function ztmPsic(string $code): PsicCode
{
    return PsicCode::where('code', $code)->firstOrFail();
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

it('offers a look-alike line to CPDO as a possibility, never as listed', function () {
    // A veterinary clinic: Commercial-1 lists "Medical, dental, and similar
    // clinics". Similar enough is CPDO's call (Art. III §2(a)).
    $hit = ZoningConformance::lookup('C-1', ztmPsic('75000'));
    expect($hit['certain'])->toBeFalse()
        ->and($hit['basis'])->toBe('similar')
        ->and($hit['use'])->toContain('similar clinics');

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

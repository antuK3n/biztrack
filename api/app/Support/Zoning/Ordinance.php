<?php

namespace App\Support\Zoning;

use Illuminate\Support\Facades\Cache;

/**
 * What City Ordinance No. 24-2018 says, held as data the zoning check reads.
 *
 * ── Why this is code and not rows ───────────────────────────────────────────
 *
 * The 19 classifications and the per-barangay lists in `zoning_classifications`
 * are rows because they were read off CPDO's map SHEETS, which are proposals an
 * admin is expected to correct. Everything here is read off the ordinance's
 * TEXT, which changes only when the Sangguniang Panlungsod amends it (Art. IX
 * §22) — and when it does, `docs/zoning-ordinance/rules.json` has to change in
 * the same commit. Keeping the text-derived facts beside the check that reads
 * them is what lets one test assert they agree.
 *
 * Every constant names the article it came from. `docs/zoning-ordinance/
 * rules.json` holds the verbatim text and the page; this file holds only what
 * a computer needs to apply it.
 */
final class Ordinance
{
    /**
     * `zoning_classifications.code` → the Art. V section enumerating its uses.
     *
     * Nineteen of the twenty base zones; §2.14 Easement Zone is functional (a
     * strip along every waterway) and appears on no sheet, so it is checked
     * from the applicant's answer about the waterway instead (V-2.14-*).
     */
    public const SECTION_FOR_CODE = [
        'R-1' => '2.1',
        'R-2-BASIC' => '2.2',
        'R-2-MAX' => '2.3',
        'R-3-BASIC' => '2.4',
        'R-3-MAX' => '2.5',
        'CMP' => '2.6',
        'C-1' => '2.7',
        'C-2' => '2.8',
        'C-3' => '2.9',
        'GENERAL-COMMERCIAL' => '2.10',
        'CBD' => '2.11',
        'I-1' => '2.12',
        'I-2' => '2.13',
        'MANGROVE' => '2.15',
        'FISHPOND' => '2.16',
        'PARKS' => '2.17',
        'CEMETERY' => '2.18',
        'UTILITIES' => '2.19',
        'INSTITUTIONAL' => '2.20',
    ];

    /** Plain names, for the applicant. The officer sees the code beside it. */
    public const ZONE_NAMES = [
        'R-1' => 'Residential-1',
        'R-2-BASIC' => 'Basic Residential-2',
        'R-2-MAX' => 'Maximum Residential-2',
        'R-3-BASIC' => 'Basic Residential-3',
        'R-3-MAX' => 'Maximum Residential-3',
        'CMP' => 'Socialized Housing',
        'C-1' => 'Commercial-1',
        'C-2' => 'Commercial-2',
        'C-3' => 'Commercial-3',
        'GENERAL-COMMERCIAL' => 'General Commercial',
        'CBD' => 'Central Business District',
        'I-1' => 'Industrial-1',
        'I-2' => 'Industrial-2',
        'MANGROVE' => 'Mangrove',
        'FISHPOND' => 'Fishpond',
        'PARKS' => 'Parks and Recreation',
        'CEMETERY' => 'Cemetery',
        'UTILITIES' => 'Utilities, Transportation and Services',
        'INSTITUTIONAL' => 'Institutional',
    ];

    public const RESIDENTIAL = ['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX', 'CMP'];

    public const COMMERCIAL = ['C-1', 'C-2', 'C-3', 'GENERAL-COMMERCIAL', 'CBD'];

    public const INDUSTRIAL = ['I-1', 'I-2'];

    /**
     * Which zones' uses each zone takes in, and the clause that says so.
     *
     * Read off each zone's first "All uses allowed in …" line, exactly as
     * written — including what it leaves out:
     *
     *  - Maximum R-3 (§2.5) and C-1 (§2.7) both name every residential zone
     *    EXCEPT Basic R-3. Nothing reaches Basic R-3's own list, so a use found
     *    only there is reported to CPDO as an inheritance gap (rule V-2.5-INH /
     *    V-2.7-INH) rather than as listed or not listed.
     *  - C-2 (§2.8) names "R-1 and R-2 Zones", and no zone is called plain
     *    R-2. It does not matter: C-2 also takes in all of C-1, which already
     *    reaches Basic and Maximum R-2, so either reading gives the same set.
     *    The pointer is kept so the citation is the clause that says it.
     *  - Industrial zones inherit nothing. I-2 restates the I-1 warehouse line
     *    but does not take in I-1's list, and that is followed as written.
     *  - Socialized Housing (§2.6) delegates to BP 220 and is not a pointer to
     *    any zone here; General Commercial's "all Residential Zones" therefore
     *    stops at the five that have lists.
     */
    public const INHERITS = [
        'R-2-BASIC' => ['V-2.2-INH' => ['R-1']],
        'R-2-MAX' => ['V-2.3-INH' => ['R-1', 'R-2-BASIC']],
        'R-3-BASIC' => ['V-2.4-INH' => ['R-1', 'R-2-BASIC']],
        'R-3-MAX' => ['V-2.5-INH' => ['R-1', 'R-2-BASIC', 'R-2-MAX']],
        'C-1' => ['V-2.7-INH' => ['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-MAX']],
        'C-2' => ['V-2.8-INH' => ['C-1'], 'V-2.8-INH-R2' => ['R-1', 'R-2-BASIC', 'R-2-MAX']],
        'C-3' => ['V-2.9-INH' => ['C-1', 'C-2', 'R-2-MAX', 'R-3-MAX']],
        'GENERAL-COMMERCIAL' => ['V-2.10-INH' => ['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX']],
        'CBD' => ['V-2.11-INH' => ['C-1', 'C-2', 'C-3', 'GENERAL-COMMERCIAL']],
    ];

    /**
     * Every zone whose uses `$code` takes in, itself first.
     *
     * @return list<string>
     */
    public static function closure(string $code): array
    {
        $seen = [$code];
        $queue = [$code];
        while ($queue !== []) {
            $next = array_shift($queue);
            foreach (self::INHERITS[$next] ?? [] as $codes) {
                foreach ($codes as $c) {
                    if (! in_array($c, $seen, true)) {
                        $seen[] = $c;
                        $queue[] = $c;
                    }
                }
            }
        }

        return $seen;
    }

    /** The clause by which `$zone` reaches `$source`'s list, for the citation. */
    public static function inheritanceRule(string $zone, string $source): ?string
    {
        if ($zone === $source) {
            return null;
        }
        foreach (self::INHERITS[$zone] ?? [] as $rule => $codes) {
            if (in_array($source, $codes, true)) {
                return $rule;
            }
        }
        foreach (self::INHERITS[$zone] ?? [] as $rule => $codes) {
            foreach ($codes as $c) {
                if (in_array($source, self::closure($c), true)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /** Art. IV §5's headings, as printed, onto the codes the sheets use. */
    private const HEADING_TO_CODE = [
        'RESIDENTIAL -1 ZONE (R-1)' => 'R-1',
        'BASIC R-2 ZONE' => 'R-2-BASIC',
        'MAXIMUM R-2 ZONE' => 'R-2-MAX',
        'BASIC R-3 ZONE' => 'R-3-BASIC',
        'MAXIMUM R-3 ZONE' => 'R-3-MAX',
        'SOCIALIZED HOUSING ZONE' => 'CMP',
        'GENERAL COMMERCIAL ZONE' => 'GENERAL-COMMERCIAL',
        'COMMERCIAL -1 ZONE (C-1)' => 'C-1',
        'COMMERCIAL -2 ZONE (C-2)' => 'C-2',
        'COMMERCIAL -3 ZONE (C-3)' => 'C-3',
        'CENTRAL BUSINESS DISTRICT (CBD)' => 'CBD',
        'INDUSTRIAL - 1 ZONE (I-1)' => 'I-1',
        'INDUSTRIAL-2 ZONE (I-2)' => 'I-2',
        'INSTITUTIONAL ZONE' => 'INSTITUTIONAL',
        'FISHPOND ZONE' => 'FISHPOND',
        'MANGROVE ZONE' => 'MANGROVE',
        'CEMETERY ZONE' => 'CEMETERY',
        'PARKS AND RECREATION ZONE' => 'PARKS',
        'UTILITIES, TRANSPORTATION AND SERVICE ZONE' => 'UTILITIES',
    ];

    /**
     * The zones Art. IV §5's TEXT places in each barangay, keyed by a
     * normalised barangay name.
     *
     * This is the governing list. Art. IV §6 says so twice — "The textual
     * description of the zone boundaries shall prevail over that of the
     * Official Zoning Maps" (IV-6-g) and, where the map is inaccurate, "the
     * description of the zoning boundaries appended shall govern" (IV-6-l) —
     * and the two disagree in ways that matter: the text puts Basic R-2 in
     * Potrero alone where our reading of the sheets put it in twenty
     * barangays, and it puts industrial zones in Catmon and Maysilo that the
     * sheets' tracing missed (questions-for-malabon C11). Where they differ the
     * check says so and goes by the text.
     *
     * Parks and Utilities are absent on purpose: §5 places them city-wide on
     * "all area occupied by existing" facilities rather than in any barangay,
     * so they come from the sheet (IV-5-PR-UTS).
     *
     * Read from `docs/zoning-ordinance/zone-boundaries.json`, which was checked
     * row by row against pages 20-35 of the PDF for this purpose.
     *
     * @return array<string, list<string>>
     */
    public static function textZones(): array
    {
        return Cache::remember('zoning.ordinance.text-zones', 3600, function (): array {
            $path = base_path('../docs/zoning-ordinance/zone-boundaries.json');
            $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $code = self::HEADING_TO_CODE[$row['zone'] ?? ''] ?? null;
                $barangay = $row['barangay'] ?? null;
                if ($code === null || ! is_string($barangay) || $barangay === '') {
                    continue;
                }
                $key = self::key($barangay);
                $out[$key] ??= [];
                if (! in_array($code, $out[$key], true)) {
                    $out[$key][] = $code;
                }
            }

            return $out;
        });
    }

    /** "Bayan-bayanan", "Bayan-Bayanan" and "BAYAN BAYANAN" are one barangay. */
    public static function key(string $name): string
    {
        return (string) preg_replace('/[^a-zñ]/u', '', mb_strtolower($name));
    }

    /**
     * Streets the ordinance names, and the spellings applicants use for them.
     *
     * Patterns are deliberately anchored on the distinctive half of the name:
     * "Pascual" alone would catch S. Pascual St. in San Agustin, which is not
     * Gov. Pascual Avenue, and "Basilio" alone would catch Basilio St. in
     * Acacia, which is not Don Basilio Bautista Boulevard.
     */
    public const STREETS = [
        'gen_luna' => ['label' => 'Gen. Luna St.', 'pattern' => '/\bgen(?:eral)?\.?\s*(?:antonio\s+)?luna\b/u'],
        'gov_pascual' => ['label' => 'Gov. Pascual Ave.', 'pattern' => '/\bgov(?:ernor|\.)?\s*(?:w\.?\s*)?pascual\b/u'],
        'naval' => ['label' => 'Naval St.', 'pattern' => '/\bnaval\b/u'],
        'bonifacio' => ['label' => 'A. Bonifacio St.', 'pattern' => '/\bbonifacio\b/u'],
        'pantihan5' => ['label' => 'Pantihan 5', 'pattern' => '/\bpantihan\s*(?:5|v)\b/u'],
        'don_basilio' => ['label' => 'Don Basilio Bautista Blvd.', 'pattern' => '/\bbautista\b/u'],
        'rodriguez' => ['label' => 'Rodriguez St.', 'pattern' => '/\brodriguez\b/u'],
        'womens_club' => ['label' => 'Women’s Club', 'pattern' => '/\bwomen.?s\s*club\b/u'],
        'dalagang_bukid' => ['label' => 'Dalagang Bukid St.', 'pattern' => '/\bdalagang\s*bukid\b/u'],
        'pampano' => ['label' => 'Pampano St.', 'pattern' => '/\bpampano\b/u'],
        'langaray' => ['label' => 'Langaray St.', 'pattern' => '/\blangaray\b/u'],
        'maya_maya' => ['label' => 'Maya-Maya Ave.', 'pattern' => '/\bmaya\s*-?\s*maya\b/u'],
        'mh_del_pilar' => ['label' => 'M.H. del Pilar St.', 'pattern' => '/\b(?:m\.?\s*h\.?|marcelo\s+h\.?)\s*del\s*pilar\b/u'],
        'sanciangco' => ['label' => 'Sanciangco St.', 'pattern' => '/\bsanciangco\b/u'],
        'panghulo_road' => ['label' => 'Panghulo Road', 'pattern' => '/\bpanghulo\s*(?:road|rd)\b/u'],
        'rizal_ave' => ['label' => 'J.P. Rizal Ave.', 'pattern' => '/\brizal\s*(?:ave|avenue)\b/u'],
        'rivera' => ['label' => 'B. Rivera St.', 'pattern' => '/\brivera\b/u'],
        'lapu_lapu' => ['label' => 'Lapu-Lapu Ave.', 'pattern' => '/\blapu\s*-?\s*lapu\b/u'],
        'victoneta' => ['label' => 'Victoneta Ave.', 'pattern' => '/\bvictoneta\b/u'],
        'c4' => ['label' => 'C-4 Road', 'pattern' => '/\bc\s*-?\s*4\b/u'],
        'borromeo' => ['label' => 'Gen. Borromeo St.', 'pattern' => '/\bborromeo\b/u'],
    ];

    /**
     * Art. IV §5's commercial strips: barangay → [street, zone, depth].
     *
     * Only strips described by a street ("one lot deep along …", "one block
     * deep along …"). Areas bounded on four sides are not here — a street name
     * cannot place a lot inside a polygon, and pretending otherwise would be
     * the confident wrong answer this file exists to avoid.
     *
     * @var array<string, list<array{0: string, 1: string, 2: 'lot'|'block'}>>
     */
    public const STRIPS = [
        'baritan' => [['gen_luna', 'C-1', 'lot'], ['gov_pascual', 'C-2', 'block']],
        'bayanbayanan' => [['gen_luna', 'C-1', 'lot']],
        'catmon' => [['gov_pascual', 'C-2', 'lot']],
        'concepcion' => [['gen_luna', 'C-1', 'lot'], ['gov_pascual', 'C-2', 'block']],
        'dampalit' => [['don_basilio', 'C-1', 'lot']],
        'flores' => [['gen_luna', 'C-1', 'lot'], ['naval', 'C-1', 'lot'], ['bonifacio', 'C-1', 'lot'], ['pantihan5', 'C-1', 'lot']],
        'hulongduhat' => [['don_basilio', 'C-1', 'lot'], ['gen_luna', 'C-1', 'lot'], ['rodriguez', 'C-1', 'lot'], ['naval', 'C-1', 'lot'], ['womens_club', 'C-1', 'lot']],
        'ibaba' => [['gen_luna', 'C-1', 'lot']],
        'longos' => [['dalagang_bukid', 'C-1', 'lot'], ['pampano', 'C-1', 'lot'], ['langaray', 'C-1', 'lot'], ['maya_maya', 'C-1', 'lot'], ['lapu_lapu', 'C-2', 'block']],
        'maysilo' => [['mh_del_pilar', 'C-1', 'lot']],
        'niugan' => [['sanciangco', 'C-1', 'lot']],
        'panghulo' => [['panghulo_road', 'C-1', 'lot']],
        'potrero' => [['victoneta', 'C-2', 'lot']],
        'sanagustin' => [['gen_luna', 'C-1', 'lot'], ['rizal_ave', 'C-1', 'lot']],
        'santulan' => [['mh_del_pilar', 'C-1', 'lot']],
        'tañong' => [['c4', 'C-2', 'block']],
        'tinajeros' => [['rivera', 'C-1', 'lot']],
        'tonsuya' => [['sanciangco', 'C-1', 'lot']],
        'tugatog' => [['mh_del_pilar', 'C-1', 'lot'], ['gov_pascual', 'C-2', 'block']],
    ];

    /**
     * Art. VI §8, Provisions for road widening: street → setback in metres,
     * "both sides". Gov. W. Pascual gets three; the other six, one.
     */
    public const ROAD_WIDENING = [
        'gov_pascual' => 3.0,
        'sanciangco' => 1.0,
        'borromeo' => 1.0,
        'don_basilio' => 1.0,
        'rizal_ave' => 1.0,
        'mh_del_pilar' => 1.0,
        'panghulo_road' => 1.0,
    ];

    /** The street keys a typed street name matches. @return list<string> */
    public static function streetKeys(?string $street): array
    {
        $text = mb_strtolower(trim((string) $street));
        if ($text === '') {
            return [];
        }
        $keys = [];
        foreach (self::STREETS as $key => $street) {
            if (preg_match($street['pattern'], $text) === 1) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Art. VI §7: soil, hazard, the storeys it recommends, and the storey count
     * above which geotechnical and structural analysis is "REQUIRED".
     *
     * Transcribed from the page image (p. 57 printed): the text layer
     * interleaves the table's columns.
     */
    public const GEOHAZARD = [
        'Prensa clay loam' => ['hazard' => 'ground shaking', 'storeys' => '1 to 4', 'analysis_above' => 1,
            'barangays' => ['potrero', 'acacia', 'maysilo', 'panghulo', 'tinajeros', 'tugatog', 'santulan']],
        'Obando fine sandy loam' => ['hazard' => 'liquefaction', 'storeys' => '1', 'analysis_above' => 1,
            'barangays' => ['hulongduhat', 'baritan', 'bayanbayanan', 'concepcion', 'flores', 'ibaba', 'sanagustin', 'tañong']],
        'Hydrosol' => ['hazard' => 'liquefaction', 'storeys' => '1 to 2', 'analysis_above' => 2,
            'barangays' => ['dampalit', 'catmon', 'longos', 'muzon', 'niugan', 'tonsuya']],
    ];

    /** Art. VII §1.4, metres. */
    public const HEIGHT_LIMITS = [
        'R-1' => 11, 'R-2-BASIC' => 13, 'R-2-MAX' => 16, 'R-3-BASIC' => 11, 'R-3-MAX' => 36,
        'CMP' => 16, 'C-1' => 15, 'C-2' => 18, 'C-3' => 180, 'GENERAL-COMMERCIAL' => 18,
        'CBD' => 180, 'I-1' => 15, 'I-2' => 21, 'PARKS' => 15, 'CEMETERY' => 15,
        'UTILITIES' => 15, 'INSTITUTIONAL' => 18,
    ];

    /**
     * Art. IV §5 marks Heritage over rows in eight barangays; Annex C maps it
     * in five (the seeded overlay). The three below are §5-only, and a filing
     * there is told so rather than silently left out (questions C7).
     */
    public const HERITAGE_TEXT_ONLY = ['bayanbayanan', 'dampalit', 'flores'];

    /**
     * Trades, by PSIC code, that a rule names. Only unambiguous codes: a
     * code that could be two things is left to the text matcher and to CPDO,
     * for the same reason `DenrRequirements` keeps its list short — the
     * failure that matters is telling someone a condition does not apply when
     * it does.
     */
    public const TRADES = [
        'small_eatery' => ['56101', '56103'],
        'restaurant' => ['56101', '56102', '56103', '56301', '56302'],
        'water_refilling' => ['36000'],
        'passenger_terminal' => ['49221'],
        // A trucking garage. 52290 (freight forwarding and other transport
        // support) is not one: it may be a pay parking lot or an office, so
        // it is asked what its vehicles are for (`transport_support`).
        'trucking' => ['49230'],
        'transport_support' => ['52290'],
        'vehicle_rental' => ['77100'],
        'recreation' => ['93110', '93290', '59140'],
        'warehouse' => ['52101'],
        'betting' => ['92000'],
        'auto_repair' => ['45201', '45401'],
        'fuel' => ['47300'],
        'funeral' => ['96301'],
        'machine_shop' => ['25920', '31001', '16220'],
        'waste' => ['38110'],
        'advertising' => ['73100'],
        'school' => ['85100', '85490'],
        'market' => ['47810', '47820'],
        'mall' => ['47190'],
        'hospital' => ['86100'],
        'food_processing' => ['10300', '10500', '10611', '10711', '10740', '10799', '10800', '11040'],
        'laundry' => ['96200'],
    ];

    /**
     * Art. V §2.1's home occupation names its trades: "the practice of one's
     * profession such as offices of physicians, surgeons, dentists,
     * architects, engineers, lawyers, and other professionals", and "home
     * business such as dressmaking, tailoring, baking, running a sari-sari
     * store/neighbourhood convenience store and the like".
     *
     * `is`: codes that are those trades. `like_divisions`: PSIC divisions of
     * the small trades "and the like" reaches (retail, repair, personal
     * services, food service, offices), which CPDO judges case by case.
     */
    public const HOME_OCCUPATION = [
        'is' => ['69100', '69200', '70200', '71100', '75000', '86201', '14100', '10711', '47111', '47112'],
        'like_divisions' => ['47', '56', '62', '73', '74', '82', '85', '95', '96'],
    ];

    /**
     * What vehicles kept on a lot are for, as the applicant answers it
     * (`vehicle_use`), and the use-list lines each is. The register has no
     * PSIC code for a pay parking lot or a taxi garage, and the residential
     * zones give each a different limit (Art. V §2.3, §2.4): a rentable lot
     * has none on numbers, a ride-hailing garage two units, a taxi garage one,
     * parking for one's own business two vans in Maximum R-2 and one in
     * Basic R-3.
     *
     * @var array<string, array{label: string, phrases: list<string>}>
     */
    public const VEHICLE_USES = [
        'parking_lot' => ['label' => 'A pay parking lot',
            'phrases' => ['rentable parking lots/parking buildings', '=parking lots/garage facilities', '=parking lots, garage facilities', 'parking structures/facilities']],
        'parking_building' => ['label' => 'A pay parking building',
            'phrases' => ['rentable parking lots/parking buildings', 'parking buildings (above', '=parking buildings', 'parking structures/facilities']],
        'ride_hailing_garage' => ['label' => 'A garage for ride-hailing cars',
            'phrases' => ['garage for uber and grab', 'transportation terminals/garage']],
        'taxi_garage' => ['label' => 'A taxi garage',
            'phrases' => ['garage for one (1) unit taxi', 'transportation terminals/garage']],
        'business_parking' => ['label' => 'Parking for your own business’s vans or trucks',
            'phrases' => ['parking lot in support to existing business', '=parking lots/garage facilities', '=parking lots, garage facilities']],
        'tricycle_terminal' => ['label' => 'A tricycle or pedicab terminal',
            'phrases' => ['tricycle/pedicab terminals', 'transportation terminals/garage', 'other types of transportation complexes']],
        'terminal' => ['label' => 'A jeepney, UV Express or bus terminal',
            'phrases' => ['transportation terminals/garage', 'bus terminals', 'bus and railway depots', 'other types of transportation complexes']],
        'trucking_garage' => ['label' => 'A trucking or hauling garage',
            'phrases' => ['hauling services and garage terminals', 'trucking garage']],
        'other' => ['label' => 'Something else', 'phrases' => []],
    ];

    /**
     * Trades whose business is what their vehicles are for, so the answer to
     * "what are the vehicles for" (or the description's own words) says which
     * listed use they are. Every other trade is judged by its own code, and
     * its vehicles are a second use (ZoningCheck::secondVehicleUse). 68100,
     * a lessor, joins them only when it answers that it leases parking.
     */
    public const VEHICLE_TRADES = ['49221', '49230', '52290', '77100', '00000'];

    /** Is this PSIC code one of `$trade`'s? */
    public static function is(string $trade, string $psic): bool
    {
        return in_array($psic, self::TRADES[$trade] ?? [], true);
    }

    /** PSIC section C, divisions 10-33: manufacturing. */
    public static function isManufacturing(string $psic): bool
    {
        $division = (int) substr($psic, 0, 2);

        return $division >= 10 && $division <= 33;
    }

    /**
     * Art. V §3's special uses, each with the PSIC codes that are plainly it.
     * Cemeteries, slaughterhouses, cockpits and base stations have no code on
     * the register's list, so they are reached through the zoning officer's
     * "special use" answer instead.
     */
    public const SPECIAL_USES = [
        'cemetery' => ['label' => 'Cemetery or memorial park', 'rule' => 'V-3-A-1', 'psic' => []],
        'funeral' => ['label' => 'Funeral establishment', 'rule' => 'V-3-B', 'psic' => ['96301']],
        'filling_station' => ['label' => 'Filling station', 'rule' => 'V-3-C-1', 'psic' => ['47300']],
        'open_storage' => ['label' => 'Open storage', 'rule' => 'V-3-D-1', 'psic' => []],
        'slaughterhouse' => ['label' => 'Slaughterhouse or abattoir', 'rule' => 'V-3-E-1', 'psic' => []],
        'cockpit' => ['label' => 'Cockpit', 'rule' => 'V-3-F-1', 'psic' => []],
        'base_station' => ['label' => 'Base station (cell site, wireless)', 'rule' => 'V-3-G', 'psic' => []],
        'mrf' => ['label' => 'Materials recovery facility or transfer station', 'rule' => 'V-3-H-1', 'psic' => ['38110']],
        'billboard' => ['label' => 'Billboard', 'rule' => 'V-3-I-1', 'psic' => []],
        'terminal' => ['label' => 'Transport terminal', 'rule' => 'V-3-J-1', 'psic' => ['49221']],
    ];

    /**
     * Art. IV §5's Potrero area that is listed word for word under C-3 and
     * under the CBD (IV-5-POTRERO, questions C20).
     */
    public const POTRERO_DOUBLE = 'potrero';

    /**
     * The ordinance was approved by the Sangguniang Panlungsod on 26 November
     * 2018 (the minutes, PDF p. 4). It TAKES EFFECT only after HLURB approval
     * and publication (Art. IX §29), a date the City has not given us — so the
     * ten-year phase-out (Art. IX §12.9) is reported against an unknown start.
     */
    public const APPROVED_ON = '2018-11-26';
}

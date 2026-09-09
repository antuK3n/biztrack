<?php

namespace App\Support;

/**
 * Which DENR permits a business has to obtain, by what it does.
 *
 * ── What this is ──────────────────────────────────────────────────────────
 *
 * The right-hand half of MCG-CENRO-FO-001 v2.0 — "REQUIRED DENR PERMITS FOR
 * APPLICATION" — as a lookup rather than a printed checklist. On paper the
 * officer ticks the boxes that apply; here the applicant is TOLD which ones
 * apply to them, on the CEC sheet, before they get to the counter.
 *
 * The client's instruction is exact about the scope: "just list them somewhere
 * visible (it is not there where the applicant will submit the said DENR
 * permits; just a list)". Nothing here collects a document. These permits are
 * issued by the DENR, not by the LGU, and the form's own footnote says they are
 * complied with WITHIN SIX MONTHS of the CEC being issued — so a filing that
 * demanded them up front would refuse the applicant a clearance the LGU is
 * willing to give.
 *
 * ── Why it lives beside the fee rules and not in the browser ───────────────
 *
 * `conditions.business_category` on the seeded CENRO fee rules keys on exactly
 * these 28 slugs, and the money and the paperwork must not be able to disagree
 * about which row an applicant is on. Deriving it here means the applicant's
 * sheet and the officer's review sheet read one answer, computed once, from the
 * same field the Tax Order of Payment was computed from.
 *
 * ── What is NOT decided here ──────────────────────────────────────────────
 *
 * Three of the paper's rules are conditional on facts the system does not hold,
 * and every one of them is printed as a caveat rather than resolved:
 *
 *  - "If >1 ha". The register stores `floor_area_sqm`, which is FLOOR area. A
 *    four-storey building on a quarter-hectare plot has more floor than site,
 *    so answering a question about hectares with it would be wrong in the
 *    direction that adds requirements to businesses that do not have them.
 *  - "with hazardous or toxic materials". Never asked, anywhere.
 *  - PTO is "REQUIRED IF WITH GENERATOR" per the form's own legend. Also never
 *    asked.
 *
 * Printing the condition and letting the applicant read it is the honest
 * option. Guessing is not, and inventing three new questions on the BPLO form
 * to satisfy a list that is advisory would be a poor trade.
 */
final class DenrRequirements
{
    /** The paper's legend, with the client's wording for CNC (2026-09-08). */
    public const GLOSSARY = [
        'ECC' => 'Environmental Compliance Certificate',
        'CNC' => 'Certificate on Non-Coverage',
        'WDP' => 'Waste Water Discharge Permit',
        'HWP' => 'Hazardous Waste Permit',
        'PTO' => 'Permit to Operate (Air Pollution) — required if you have a generator',
        'PCO' => 'Registered Pollution Control Officer',
    ];

    /**
     * The 28 rows, in the paper's own order.
     *
     * `label` is the paper's wording for the row, printed back to the applicant
     * so they can see WHICH row they were placed on and object if it is wrong.
     * A derivation the reader cannot check is one they have to trust blindly.
     *
     * @var list<array{categories: list<string>, label: string, pco: bool, certificate: string, permits: list<string>, remarks: string}>
     */
    private const ROWS = [
        [
            'categories' => ['fuel_depot'],
            'label' => 'Fuel Depot & Fuel Storage Facilities',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['gasoline_lpg_station'],
            'label' => 'Gasoline Service, LPG and auto LPG filling / refilling stations',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['big_scale_manufacturing'],
            'label' => 'All Big Scale Manufacturing Industries',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['hospital_clinic_lab'],
            'label' => 'Private Hospitals and Medical and Dental Clinics and Laboratories',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['shopping_center_mall_market'],
            'label' => 'Shopping Centers, Malls and Markets',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['garbage_contractor'],
            'label' => 'Garbage Contractors, Terminal of Garbage Trucks, Garbage Transfer Station',
            'pco' => true, 'certificate' => 'CNC',
            'permits' => ['WDP', 'HWP'], 'remarks' => '',
        ],
        [
            'categories' => ['lpg_retailer'],
            'label' => 'Retailer of LPG',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => [], 'remarks' => '',
        ],
        [
            'categories' => ['junkshop'],
            'label' => 'Junkshops',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => ['HWP'], 'remarks' => '',
        ],
        [
            'categories' => ['funeral_cremation'],
            'label' => 'Funeral Parlors and Cremation Services',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['highrise_building'],
            'label' => 'High-Rise Building',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['fastfood_chain'],
            'label' => 'Fastfood Chains or Quick Service Restaurants',
            'pco' => true, 'certificate' => 'CNC',
            'permits' => ['WDP', 'HWP', 'PTO'],
            'remarks' => 'If the site is larger than 1 hectare, an ECC is required instead of a CNC.',
        ],
        [
            'categories' => ['catering_commissary'],
            'label' => 'Catering Services, commissaries',
            'pco' => true, 'certificate' => 'CNC',
            'permits' => ['WDP', 'HWP'],
            'remarks' => 'If the site is larger than 1 hectare, an ECC is required instead of a CNC.',
        ],
        [
            'categories' => ['housing_development'],
            'label' => 'Housing Development Projects such as Residential Subdivisions, Parks (Memorial Park included), Condominiums, Townhouses',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['vehicle_terminal_garage'],
            'label' => 'Vehicle Terminal / Garage of Transport / Trucking Services',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => [],
            'remarks' => 'If the site is larger than 1 hectare, an ECC, a Hazardous Waste Permit and a Pollution Control Officer are required.',
        ],
        [
            'categories' => ['hotel_motel_inn'],
            'label' => 'Hotels, Motels, Apartels, Inns and similar establishments',
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['amusement_place'],
            'label' => 'Amusement places such as KTV, Videoke bars, beer houses and the like',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => ['WDP'], 'remarks' => '',
        ],
        [
            // One paper row, four seeded categories — the same grouping the
            // environmental fee rule uses, so the fee and this cannot disagree.
            'categories' => [
                'welding_machine_shop',
                'vehicle_repair_shop',
                'refrigeration_aircon_repair_shop',
                'furniture_sash_fabricator',
            ],
            'label' => 'Welding shops, machine shops, vehicle repair and repainting shops, refrigerator and air conditioning repair shops, furniture shops and sash factories, fabricators (steel, concrete)',
            'pco' => true, 'certificate' => 'CNC',
            'permits' => ['WDP', 'HWP'], 'remarks' => '',
        ],
        [
            'categories' => ['warehouse_storage'],
            'label' => 'Warehouses, storage facilities',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => [],
            'remarks' => 'If you store hazardous or toxic materials, an ECC, a Hazardous Waste Permit and a Pollution Control Officer are required.',
        ],
        [
            'categories' => ['animal_farm'],
            'label' => 'Animal Farm like piggery, Animal Breeding Services',
            'pco' => false, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['school_learning_center'],
            'label' => "Schools, Montessori's, learning centers, review centers and other similar establishments",
            'pco' => true, 'certificate' => 'ECC',
            'permits' => ['WDP', 'HWP', 'PTO'], 'remarks' => '',
        ],
        [
            'categories' => ['laundry_dry_cleaning'],
            'label' => 'Laundry shops and dry cleaning services',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => ['WDP', 'HWP'], 'remarks' => '',
        ],
        [
            'categories' => ['tailoring_clothes_manufacturing'],
            'label' => 'Haberdashery, tailoring, clothes manufacturing services and others',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => [], 'remarks' => '',
        ],
        [
            'categories' => ['water_refilling_station'],
            'label' => 'Water refilling Stations',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => ['WDP'], 'remarks' => '',
        ],
        [
            'categories' => ['midrise_building'],
            'label' => 'Medium Rise Building',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => [], 'remarks' => '',
        ],
        [
            'categories' => ['small_scale_manufacturing'],
            'label' => 'Small-Scale Manufacturing Industries',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => ['WDP'], 'remarks' => '',
        ],
        [
            'categories' => ['car_wash'],
            'label' => 'Car Wash Shops',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => ['WDP'], 'remarks' => '',
        ],
        [
            'categories' => ['apartment_three_door'],
            'label' => 'Three-door apartments and up',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => [], 'remarks' => '',
        ],
        [
            'categories' => ['cenro_other'],
            'label' => 'Such other activities and projects as may be determined by CENRO officers',
            'pco' => false, 'certificate' => 'CNC',
            'permits' => [],
            'remarks' => 'If you handle hazardous or toxic materials, an ECC, a Hazardous Waste Permit and a Pollution Control Officer are required.',
        ],
    ];

    /**
     * Declared categories that plainly ARE one of the paper's rows under
     * another name.
     *
     * The applicant's category picker offers the REVENUE CODE's vocabulary —
     * 30 suggestions drawn from the business-tax schedule — and CENRO's table
     * uses its own. Six slugs coincide exactly (`fastfood_chain`, `junkshop`,
     * `laundry_dry_cleaning`, `small_scale_manufacturing`,
     * `vehicle_repair_shop`, `water_refilling_station`); these five are the
     * cases where the picker's word and a CENRO row unambiguously name the same
     * trade.
     *
     * Deliberately short. `manufacturer` is NOT here and must not be added: the
     * paper splits manufacturing into "All Big Scale" (row 3, ECC and three
     * permits) and "Small-Scale" (row 25, a CNC and one), and nothing on the
     * filing says which a plain "Manufacturer" is. Guessing big would hand a
     * backyard workshop a list of DENR permits it does not need; guessing small
     * would let a factory believe it needs one permit when it needs four. An
     * unmatched category says so, which is the only safe answer.
     */
    private const ALIASES = [
        'private_hospital' => 'hospital_clinic_lab',
        'medical_clinic' => 'hospital_clinic_lab',
        'dental_clinic' => 'hospital_clinic_lab',
        'gasoline_station' => 'gasoline_lpg_station',
        'hotel' => 'hotel_motel_inn',
        'tailor_dress_shop' => 'tailoring_clothes_manufacturing',
    ];

    /**
     * The PSIC line of business, mapped onto CENRO's rows.
     *
     * ── Why this exists, and why it should have from the start ────────────────
     *
     * The first cut read only the fee profile's declared CATEGORY, which is free
     * text with a 30-item datalist drawn from the Revenue Code's business-tax
     * vocabulary. CENRO's table uses its own words, six of the two lists
     * coincide, and the result was a panel that said "CENRO will determine this"
     * on most filings — with the client's fair objection: the system HAS a line
     * of business, so why can it not say?
     *
     * It does. `business_lines.psic_code_id` is the standard classification the
     * applicant picked from a list of 135, and it is a far better signal than a
     * free-typed category: 47300 is a filling station whatever its owner called
     * their trade. Both are consulted now, category first (it is the more
     * specific claim, and it is what the fee was priced from), PSIC second.
     *
     * Only unambiguous codes are here. A code that could be two rows is left
     * out and handled by AMBIGUOUS_SCALE_PSIC or the catch-all below, because
     * the failure that matters is TELLING SOMEONE THEY NEED LESS THAN THEY DO.
     */
    private const PSIC_TO_CATEGORY = [
        '47300' => 'gasoline_lpg_station',      // Retail sale of automotive fuel
        '86100' => 'hospital_clinic_lab',       // Hospital activities
        '86201' => 'hospital_clinic_lab',       // Medical and dental clinics
        '86901' => 'hospital_clinic_lab',       // Medical and diagnostic laboratories
        '47190' => 'shopping_center_mall_market', // Department store
        '47810' => 'shopping_center_mall_market', // Retail via stalls and markets
        '47820' => 'shopping_center_mall_market',
        '38110' => 'garbage_contractor',        // Collection of non-hazardous waste
        '96301' => 'funeral_cremation',         // Funeral and related activities
        '56102' => 'fastfood_chain',            // Fast-food and quick-service
        '56210' => 'catering_commissary',       // Event catering
        '56290' => 'catering_commissary',       // Institutional catering, canteens
        '49221' => 'vehicle_terminal_garage',   // Passenger land transport
        '49230' => 'vehicle_terminal_garage',   // Freight transport by road
        '52290' => 'vehicle_terminal_garage',   // Freight forwarding
        '77100' => 'vehicle_terminal_garage',   // Rent-a-car
        '55101' => 'hotel_motel_inn',           // Hotels and resorts
        '55102' => 'hotel_motel_inn',           // Apartelles, pension houses, inns
        '55103' => 'hotel_motel_inn',           // Motels and lodging houses
        '55900' => 'hotel_motel_inn',           // Dormitories, boarding houses
        '56302' => 'amusement_place',           // Bars, beer houses
        '93290' => 'amusement_place',           // Billiard hall, videoke, internet cafe
        // Paper row 17 in its own words: machine shops, vehicle repair,
        // refrigeration and air-conditioning, furniture and sash, fabricators
        // (steel, concrete).
        '25920' => 'welding_machine_shop',      // Treatment and coating of metals
        '45201' => 'vehicle_repair_shop',       // Maintenance and repair of vehicles
        '45401' => 'vehicle_repair_shop',       // Repair of motorcycles
        '43220' => 'refrigeration_aircon_repair_shop', // Plumbing, heating, A/C
        '95210' => 'refrigeration_aircon_repair_shop', // Repair of consumer electronics
        '95220' => 'refrigeration_aircon_repair_shop', // Repair of household appliances
        '31001' => 'furniture_sash_fabricator', // Manufacture of furniture
        '16220' => 'furniture_sash_fabricator', // Builders' carpentry and joinery
        '23950' => 'furniture_sash_fabricator', // Concrete products (hollow blocks)
        '52101' => 'warehouse_storage',         // Warehousing and storage
        '85100' => 'school_learning_center',    // Pre-primary and primary education
        '85490' => 'school_learning_center',    // Review, tutorial, driving schools
        '96200' => 'laundry_dry_cleaning',      // Laundry and dry-cleaning
        '14100' => 'tailoring_clothes_manufacturing', // Wearing apparel, tailoring
        '36000' => 'water_refilling_station',   // Water collection, treatment, supply
        '68100' => 'apartment_three_door',      // Lessor of apartments and stalls
    ];

    /**
     * Manufacturing that CENRO's table splits by SCALE, which nothing on a
     * filing states.
     *
     * Row 3 is "All Big Scale Manufacturing Industries" — an ECC, three DENR
     * permits and a Pollution Control Officer. Row 25 is "Small-Scale
     * Manufacturing Industries" — a CNC and one permit. The paper defines
     * neither, and the register holds capitalisation, floor area and headcount
     * without any of them being the LGU's stated test.
     *
     * So these codes deliberately produce NO answer, and the sheet says why.
     * They are kept out of the catch-all below on purpose: row 28 would tell a
     * factory it needs no DENR permits at all, which is the one wrong answer
     * with consequences.
     */
    private const AMBIGUOUS_SCALE_PSIC = [
        '10300', // Processing and preserving of fruits and vegetables
        '10500', // Dairy products
        '10611', // Rice and corn milling
        '10711', // Bakery products
        '10740', // Noodles
        '10799', // Other food products (ice plant)
        '10800', // Prepared animal feeds
        '11040', // Soft drinks and bottled water
        '15200', // Footwear
        '17020', // Paper and paperboard containers
        '20230', // Soap, detergents and cleaning preparations
        '22200', // Plastic products
        '32110', // Jewellery
    ];

    /** The paper's own catch-all, row 28. */
    private const CATCH_ALL_CATEGORY = 'cenro_other';

    /**
     * The row for a set of declared categories, or null when none applies.
     *
     * A filing can declare several lines of business. The FIRST paper row any
     * of them matches wins, and "first" is the paper's own order, which runs
     * from the most environmentally consequential (fuel depots) to the least
     * (whatever CENRO decides). So a business that both stores fuel and sells
     * LPG at retail is told about the fuel depot, which is the answer that
     * keeps it legal.
     *
     * @param  list<string>  $categories  Declared category slugs.
     * @return array{label: string, pco: bool, certificate: string, permits: list<string>, remarks: string}|null
     */
    public static function forCategories(array $categories): ?array
    {
        $slugs = [];
        foreach ($categories as $category) {
            $slug = (string) $category;
            if ($slug === '') {
                continue;
            }
            $slugs[] = $slug;
            if (isset(self::ALIASES[$slug])) {
                $slugs[] = self::ALIASES[$slug];
            }
        }

        if ($slugs === []) {
            return null;
        }

        foreach (self::ROWS as $row) {
            if (array_intersect($row['categories'], $slugs) !== []) {
                return [
                    'label' => $row['label'],
                    'pco' => $row['pco'],
                    'certificate' => $row['certificate'],
                    'permits' => $row['permits'],
                    'remarks' => $row['remarks'],
                ];
            }
        }

        return null;
    }

    /**
     * The answer for a whole filing, from everything it declares.
     *
     * ── Three outcomes, and the middle one is the point ───────────────────────
     *
     * `''`          a specific row matched, by category or by PSIC code.
     * `'catch_all'` nothing matched, so the paper's own row 28 applies — "such
     *               other activities and projects as may be determined by CENRO
     *               officers". A sari-sari store is not on CENRO's table, and
     *               row 28 is where the table itself puts it.
     * `'scale'`     the trade IS manufacturing and the table splits
     *               manufacturing by a scale nothing on the filing states.
     *               No row, and the sheet says why.
     *
     * The `scale` case is kept OUT of the catch-all deliberately. Row 28 says a
     * CNC and no DENR permits; applying it to a factory would tell somebody who
     * needs an ECC, three permits and a Pollution Control Officer that they need
     * none of it. Every other unmatched trade can safely fall to row 28 because
     * the table put it there; a factory cannot, because the table has a row for
     * it and we cannot tell which.
     *
     * @param  list<string>  $categories  Declared fee-profile category slugs.
     * @param  list<string>  $psicCodes  PSIC codes on the business's lines.
     * @return array{row: array{label: string, pco: bool, certificate: string, permits: list<string>, remarks: string}|null, reason: string}
     */
    public static function resolve(array $categories, array $psicCodes): array
    {
        // The declared category first: it is the more specific claim, and it is
        // the field the CENRO environmental fee was priced from.
        if (($row = self::forCategories($categories)) !== null) {
            return ['row' => $row, 'reason' => ''];
        }

        $codes = array_map(fn ($c) => (string) $c, $psicCodes);

        $mapped = [];
        foreach ($codes as $code) {
            if (isset(self::PSIC_TO_CATEGORY[$code])) {
                $mapped[] = self::PSIC_TO_CATEGORY[$code];
            }
        }
        if ($mapped !== [] && ($row = self::forCategories($mapped)) !== null) {
            return ['row' => $row, 'reason' => ''];
        }

        if (array_intersect($codes, self::AMBIGUOUS_SCALE_PSIC) !== []) {
            return ['row' => null, 'reason' => 'scale'];
        }

        return [
            'row' => self::forCategories([self::CATCH_ALL_CATEGORY]),
            'reason' => 'catch_all',
        ];
    }

    /**
     * The permits this business must actually obtain, as code => meaning.
     *
     * The list the applicant is shown, flattened into the items that have to be
     * complied with: the certificate it needs (an ECC or a CNC, never both),
     * every DENR permit on its row, and the Pollution Control Officer when one
     * is required. In the paper's legend order, so the sheet and the
     * requirements raised off it read alike.
     *
     * Empty for a row that needs nothing and for a business the table cannot
     * place — an unplaced filing raises no obligations, which is the same
     * caution `resolve()` takes.
     *
     * @return array<string, string>
     */
    public static function outstandingFor(array $categories, array $psicCodes): array
    {
        $row = self::resolve($categories, $psicCodes)['row'];
        if ($row === null) {
            return [];
        }

        $codes = array_merge(
            [$row['certificate']],
            $row['permits'],
            $row['pco'] ? ['PCO'] : [],
        );

        $out = [];
        foreach (array_keys(self::GLOSSARY) as $code) {
            if (in_array($code, $codes, true)) {
                $out[$code] = self::GLOSSARY[$code];
            }
        }

        return $out;
    }

    /**
     * Every category slug this table can place, for the seeder parity test.
     *
     * @return list<string>
     */
    public static function knownCategories(): array
    {
        return array_merge(...array_map(fn (array $r) => $r['categories'], self::ROWS));
    }

    /**
     * Every category a PSIC code can be mapped onto, for the same test.
     *
     * @return list<string>
     */
    public static function psicMappedCategories(): array
    {
        return array_values(array_unique(array_values(self::PSIC_TO_CATEGORY)));
    }
}

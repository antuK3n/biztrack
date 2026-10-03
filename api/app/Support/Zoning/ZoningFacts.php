<?php

namespace App\Support\Zoning;

/**
 * The questions the zoning ordinance needs answered that the filing does not
 * already answer, and who answers each.
 *
 * ── Asked only when a rule needs it ────────────────────────────────────────
 *
 * Seventy-odd questions would be a form nobody finishes. None of these is asked
 * up front: the check runs, each finding names the facts it read, and the
 * screen asks only the ones a finding is waiting on. A sari-sari store in
 * Longos meets perhaps five of them; a filling station meets its own set.
 *
 * ── Never asked twice ─────────────────────────────────────────────────────
 *
 * Floor area, lot area, employees, capital, the street, the rent answer and
 * the storeys are on the filing already (fee profile, address, ZONING sheet)
 * and are read from there. Nothing here duplicates them.
 *
 * ── Two hands ───────────────────────────────────────────────────────────────
 *
 * `applicant` facts are the owner's to know (is the business run from a home,
 * will customers park on the street). `officer` facts are CPDO's to determine
 * (which zone the lot is actually in, whether it adjoins a conflicting zone).
 * The officer may also record any applicant fact — a measured distance beats
 * a guessed one — and when both exist the officer's answer is the one the
 * check reads. They are stored in two columns so neither side can overwrite
 * the other's answers through its own endpoint.
 */
final class ZoningFacts
{
    /**
     * key => [label, type, who, help?, options?, unit?].
     *
     * `type`: bool | number | choice | date. `who`: applicant | officer.
     *
     * @var array<string, array<string, mixed>>
     */
    public const SCHEMA = [
        // ── Home businesses (Art. V §2.1; Annex A 42) ─────────────────────
        'home_based' => ['label' => 'Is the business run from a house that someone lives in?', 'type' => 'bool', 'who' => 'applicant'],
        'persons_engaged' => ['label' => 'How many people work in the business, counting you?', 'type' => 'number', 'who' => 'applicant', 'unit' => 'people'],
        'non_resident_workers' => ['label' => 'Does anyone who does not live in the house work in the business?', 'type' => 'bool', 'who' => 'applicant'],
        'dwelling_floor_area_sqm' => ['label' => 'Floor area of the whole house', 'type' => 'number', 'who' => 'applicant', 'unit' => 'sq. m.'],
        'house_altered' => ['label' => 'Will the house be altered for the business?', 'type' => 'choice', 'who' => 'applicant',
            'options' => ['none' => 'No', 'inside' => 'Inside only', 'outside' => 'Yes, the outside changes']],
        'in_accessory_structure' => ['label' => 'Is the business in a garage, servants’ quarters or other outbuilding rather than the house itself?', 'type' => 'bool', 'who' => 'applicant'],
        'non_household_equipment' => ['label' => 'Will you use machines other than ordinary household appliances?', 'type' => 'bool', 'who' => 'applicant'],

        // ── Operations ───────────────────────────────────────────────────
        'nuisance_equipment' => ['label' => 'Will any machine or process make noise, vibration, glare, fumes, odours or electrical interference that can be noticed outside?', 'type' => 'bool', 'who' => 'applicant'],
        'parking_on_street' => ['label' => 'Will customers or your own vehicles park on the street, the sidewalk or the front yard?', 'type' => 'bool', 'who' => 'applicant',
            'help' => 'A parking space is off the street and at least 20 sq. m. (Annex A 73).'],
        'customer_area' => ['label' => 'Is there a waiting or dining area for customers inside the premises?', 'type' => 'bool', 'who' => 'applicant'],
        'street_for_customers' => ['label' => 'Will customers wait or eat on the road, street or alley?', 'type' => 'bool', 'who' => 'applicant'],
        'delivery_parking' => ['label' => 'Is there parking on the premises for your delivery vehicles?', 'type' => 'bool', 'who' => 'applicant'],
        'industry_pollutive' => ['label' => 'Does the work give off smoke, dust, fumes, odours or wastewater?', 'type' => 'bool', 'who' => 'applicant'],
        'industry_hazardous' => ['label' => 'Does the business use or store flammable, explosive or toxic materials?', 'type' => 'bool', 'who' => 'applicant'],
        'existing_malabon_business' => ['label' => 'Does this serve a business you already run in Malabon?', 'type' => 'bool', 'who' => 'applicant'],
        'operates_within_malabon' => ['label' => 'Does the hauling business operate within Malabon?', 'type' => 'bool', 'who' => 'applicant'],
        'makeshift_facade' => ['label' => 'Is the shopfront made of makeshift materials such as tarpaulin, scrap or plastic sheets?', 'type' => 'bool', 'who' => 'applicant'],
        'grease_trap' => ['label' => 'Will you install a grease trap and dirt stopper and dispose of waste properly?', 'type' => 'bool', 'who' => 'applicant'],
        'firewalls' => ['label' => 'Will the shop have firewalls?', 'type' => 'bool', 'who' => 'applicant'],
        'barangay_hours' => ['label' => 'Have you agreed operating hours with the barangay?', 'type' => 'bool', 'who' => 'applicant'],
        'deep_well' => ['label' => 'Will the business draw water from a deep well?', 'type' => 'bool', 'who' => 'applicant'],
        'has_ecc' => ['label' => 'Do you already have an Environmental Compliance Certificate (ECC) from DENR?', 'type' => 'bool', 'who' => 'applicant'],
        'hoa_consent' => ['label' => 'Do you have the written approval of the homeowners’ association (or the barangay, if there is none) and your immediate neighbours?', 'type' => 'bool', 'who' => 'applicant'],

        // ── Vehicles ─────────────────────────────────────────────────────
        'garage_vehicle_count' => ['label' => 'How many vehicles will be kept there?', 'type' => 'number', 'who' => 'applicant', 'unit' => 'vehicles'],
        'heavy_vehicles' => ['label' => 'Will container vans, tractor heads, trailer trucks or other heavy vehicles be kept there?', 'type' => 'bool', 'who' => 'applicant'],
        'motor_pool' => ['label' => 'Will vehicles be pooled and serviced there as a motor pool?', 'type' => 'bool', 'who' => 'applicant'],
        'two_way_street' => ['label' => 'Can the street to the premises take two-way traffic?', 'type' => 'bool', 'who' => 'applicant'],
        'onsite_maneuvering' => ['label' => 'Can vehicles turn and park inside the lot without backing onto the road?', 'type' => 'bool', 'who' => 'applicant'],
        'terminal_units' => ['label' => 'Most vehicles at the terminal at one time', 'type' => 'number', 'who' => 'applicant', 'unit' => 'vehicles'],
        'parking_slots' => ['label' => 'How many car parking slots?', 'type' => 'number', 'who' => 'applicant', 'unit' => 'slots'],
        'vehicle_use' => ['label' => 'What are the vehicles kept on the lot for?', 'type' => 'choice', 'who' => 'applicant',
            'options' => [
                'parking_lot' => 'A pay parking lot for the public',
                'parking_building' => 'A pay parking building for the public',
                'ride_hailing_garage' => 'A garage for Grab or other ride-hailing cars',
                'taxi_garage' => 'A garage for a taxi',
                'business_parking' => 'Parking for my own business’s delivery vans or trucks',
                'tricycle_terminal' => 'A tricycle or pedicab terminal',
                'terminal' => 'A jeepney, UV Express or bus terminal',
                'trucking_garage' => 'A trucking or hauling garage',
                'other' => 'Something else',
            ]],
        'lot_owner_is_business_owner' => ['label' => 'Does the owner of the parking lot also own the business it serves?', 'type' => 'bool', 'who' => 'applicant'],
        'parking_repair_services' => ['label' => 'Will the parking building also offer tire vulcanizing or vehicle repair?', 'type' => 'bool', 'who' => 'applicant'],
        'parking_landscaped' => ['label' => 'Will the parking lot have trees at least 1.8 m tall, and at least half its paving permeable?', 'type' => 'bool', 'who' => 'applicant'],

        // ── Distances (metres, straight line) ──────────────────────────────
        'distance_to_institution_m' => ['label' => 'Distance to the nearest school, church, hospital or government office', 'type' => 'number', 'who' => 'applicant', 'unit' => 'm'],
        'distance_to_fuel_station_m' => ['label' => 'Distance to the nearest existing gasoline or LPG station', 'type' => 'number', 'who' => 'applicant', 'unit' => 'm'],
        'distance_to_residence_m' => ['label' => 'Distance to the nearest house', 'type' => 'number', 'who' => 'applicant', 'unit' => 'm'],
        'distance_to_market_m' => ['label' => 'Distance to the nearest market or food business', 'type' => 'number', 'who' => 'applicant', 'unit' => 'm'],
        'distance_to_water_m' => ['label' => 'Distance to the nearest river or water supply', 'type' => 'number', 'who' => 'applicant', 'unit' => 'm'],
        'distance_to_billboard_m' => ['label' => 'Distance to the nearest other billboard', 'type' => 'number', 'who' => 'applicant', 'unit' => 'm'],

        // ── The site ──────────────────────────────────────────────────────
        'beside_waterway' => ['label' => 'Is the lot beside a river, creek or other waterway?', 'type' => 'bool', 'who' => 'applicant'],
        'waterway_name' => ['label' => 'Which waterway?', 'type' => 'choice', 'who' => 'applicant',
            'options' => ['malabon_navotas' => 'Malabon–Navotas River', 'tullahan' => 'Tullahan River', 'muzon' => 'Muzon River', 'dampalit' => 'Dampalit River', 'other' => 'Another river or creek']],
        'waterway_setback_m' => ['label' => 'Distance from the building to the edge of the waterway', 'type' => 'number', 'who' => 'applicant', 'unit' => 'm'],
        'in_fishpond_area' => ['label' => 'Is the lot within Dampalit’s fishpond area?', 'type' => 'bool', 'who' => 'applicant'],
        'heritage_house' => ['label' => 'Is the building a declared heritage house (house of ancestry)?', 'type' => 'bool', 'who' => 'applicant'],
        'business_floor' => ['label' => 'Which floor is the business on?', 'type' => 'choice', 'who' => 'applicant',
            'options' => ['ground' => 'Ground floor', 'upper' => 'An upper floor']],
        'new_construction' => ['label' => 'Will you build a new structure for this business?', 'type' => 'bool', 'who' => 'applicant'],
        'above_heritage_apex' => ['label' => 'Will the new building rise above the roof apex of the nearest declared heritage house?', 'type' => 'bool', 'who' => 'applicant'],
        'period_design' => ['label' => 'Will the new building’s design, landscaping included, follow the period design of the heritage houses?', 'type' => 'bool', 'who' => 'applicant'],
        'abuts_neighbour' => ['label' => 'Will the new structure be built right against a neighbour’s property line?', 'type' => 'bool', 'who' => 'applicant'],
        'neighbour_consent' => ['label' => 'Do you have that neighbour’s written consent?', 'type' => 'bool', 'who' => 'applicant'],
        'open_storage' => ['label' => 'Will goods be stored under a roof with no walls, or in the open?', 'type' => 'bool', 'who' => 'applicant'],
        'container_layers' => ['label' => 'How many container vans high will they be stacked?', 'type' => 'number', 'who' => 'applicant', 'unit' => 'layers'],

        // ── Signs (Art. VII §13; Art. V §3.I) ─────────────────────────────
        'has_sign' => ['label' => 'Will you put up a business sign?', 'type' => 'bool', 'who' => 'applicant'],
        'sign_over_public' => ['label' => 'Will the sign hang over the sidewalk, the street or other public property?', 'type' => 'bool', 'who' => 'applicant'],
        'roof_sign' => ['label' => 'Will the sign stand on the roof?', 'type' => 'bool', 'who' => 'applicant'],
        'fronts_national_road' => ['label' => 'Does the lot front the National Road?', 'type' => 'bool', 'who' => 'applicant'],

        // ── Particular trades ─────────────────────────────────────────────
        'funeral_category' => ['label' => 'Which category of funeral establishment?', 'type' => 'choice', 'who' => 'applicant',
            'options' => ['I' => 'Category I', 'II' => 'Category II', 'III' => 'Category III']],
        'cenro_recommended' => ['label' => 'Has CENRO recommended the site?', 'type' => 'bool', 'who' => 'applicant'],
        'neighbour_statements' => ['label' => 'Do you have sworn statements from the owners of the land right next to the site?', 'type' => 'bool', 'who' => 'applicant'],
        'sells_coffins' => ['label' => 'Will coffins or flower wreaths be displayed or sold at the chapel?', 'type' => 'bool', 'who' => 'applicant'],
        'rooms_have_kitchens' => ['label' => 'Do the guest rooms have their own kitchens or cooking facilities?', 'type' => 'bool', 'who' => 'applicant'],
        'flammable_solvents' => ['label' => 'Will dry cleaning use flammable solvents?', 'type' => 'bool', 'who' => 'applicant'],
        'leases_what' => ['label' => 'What do you lease out?', 'type' => 'choice', 'who' => 'applicant',
            'options' => ['dwellings' => 'Houses, apartments or rooms to live in', 'commercial' => 'Stalls or commercial space', 'parking' => 'Parking slots or a parking lot']],

        // ── Which listed use a trade is, where one code covers several ────
        'apparel_kind' => ['label' => 'Is it a tailoring or dressmaking shop, or a garment factory?', 'type' => 'choice', 'who' => 'applicant',
            'options' => ['tailoring' => 'A tailoring or dressmaking shop', 'factory' => 'A garment factory']],
        'school_kind' => ['label' => 'What kind of school is it?', 'type' => 'choice', 'who' => 'applicant',
            'options' => ['tutorial' => 'Tutorial or review centre', 'driving' => 'Driving school', 'vocational' => 'Vocational or technical school', 'short_course' => 'Dance, self-defense, speech or other short course']],
        'footwear_material' => ['label' => 'What are the shoes or slippers made of?', 'type' => 'choice', 'who' => 'applicant',
            'options' => ['leather' => 'Leather, fabric or other material', 'rubber_plastic' => 'Rubber or plastic', 'wood' => 'Wood']],
        'paint_bulk_handling' => ['label' => 'Will the store handle paint in bulk (drums, mixing, refilling)?', 'type' => 'bool', 'who' => 'applicant'],
        'neighbourhood_scale' => ['label' => 'Is it a small repair shop serving the neighbourhood?', 'type' => 'bool', 'who' => 'applicant'],
        'listed_use' => ['label' => 'Is the business one of these?', 'type' => 'choice', 'who' => 'applicant',
            'help' => 'Pick the nearest. CPDO can change it.',
            'options' => [
                'junk_shop' => 'Medium-scale junk shop', 'lechon' => 'Lechon store', 'chicharon' => 'Chicharon factory',
                'car_wash' => 'Car wash', 'event_planner' => 'Event planner', 'vocational' => 'Vocational or technical school',
                'dance_school' => 'Dance school', 'self_defense' => 'Self-defense school', 'sped' => 'Special education (SPED) school',
                'none' => 'None of these',
            ]],
        'families_in_building' => ['label' => 'How many families or households can live in the building?', 'type' => 'number', 'who' => 'applicant', 'unit' => 'families'],
        'in_office_building' => ['label' => 'Is the shop inside an office building?', 'type' => 'bool', 'who' => 'applicant'],
        'pet_house_area_sqm' => ['label' => 'Floor area of any pet house or kennel on the lot (0 if none)', 'type' => 'number', 'who' => 'applicant', 'unit' => 'sq. m.'],
        'warfare_research' => ['label' => 'Will the facility work with nuclear, radioactive, chemical or biological warfare materials?', 'type' => 'bool', 'who' => 'applicant'],

        // ── A clearance already held (Art. IX §9, §10.2.C) ────────────────
        'held_lc' => ['label' => 'Do you already hold a locational clearance for this business at this address?', 'type' => 'bool', 'who' => 'applicant'],
        'held_lc_issued_on' => ['label' => 'When was it issued?', 'type' => 'date', 'who' => 'applicant'],
        'held_lc_for_construction' => ['label' => 'Was it issued for building or renovating, rather than for the business?', 'type' => 'bool', 'who' => 'applicant'],

        // ── Existing businesses (Art. IX §§11-12) ─────────────────────────
        'operating_since_year' => ['label' => 'Year the business started operating at this address', 'type' => 'number', 'who' => 'applicant'],
        'nonconforming_expanded' => ['label' => 'Has the business taken up more floor area or land since 2018?', 'type' => 'bool', 'who' => 'applicant'],
        'nonconforming_ceased' => ['label' => 'Has the business stopped operating for more than a year at any time since 2018?', 'type' => 'bool', 'who' => 'applicant'],
        'lzeac_allowed' => ['label' => 'Was the business allowed by a variance or exception under the old zoning ordinance?', 'type' => 'bool', 'who' => 'applicant'],

        // ── CPDO's own determinations ─────────────────────────────────────
        'lot_zone' => ['label' => 'Zone of this lot, as CPDO reads the map and Art. IV §5', 'type' => 'choice', 'who' => 'officer'],
        'lot_split_by_boundary' => ['label' => 'Does a zone boundary cross the lot?', 'type' => 'bool', 'who' => 'officer'],
        'special_use' => ['label' => 'Special use under Art. V §3', 'type' => 'choice', 'who' => 'officer'],
        'adjoins_conflicting_zone' => ['label' => 'Does the lot adjoin a zone whose uses conflict with this one?', 'type' => 'bool', 'who' => 'officer'],
        'buffer_provided' => ['label' => 'Is a 4 m open buffer kept along that boundary?', 'type' => 'bool', 'who' => 'officer'],
    ];

    /** Keys the applicant may write. */
    public static function applicantKeys(): array
    {
        return array_keys(array_filter(self::SCHEMA, fn (array $f) => $f['who'] === 'applicant'));
    }

    /** Every key; the officer may record any fact. */
    public static function officerKeys(): array
    {
        return array_keys(self::SCHEMA);
    }

    /**
     * Validation rules for a `zoning_facts` payload under `$prefix`.
     *
     * `nullable` throughout: clearing an answer is a real edit, and an
     * unanswered question is how most facts start.
     *
     * @param  list<string>  $keys
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix, array $keys): array
    {
        $rules = [$prefix => ['sometimes', 'nullable', 'array']];
        foreach ($keys as $key) {
            $field = self::SCHEMA[$key];
            $rules["{$prefix}.{$key}"] = match ($field['type']) {
                'bool' => ['nullable', 'boolean'],
                'number' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
                'date' => ['nullable', 'date', 'before_or_equal:today'],
                'choice' => ['nullable', 'string', 'max:40', ...(isset($field['options']) ? ['in:'.implode(',', array_keys($field['options']))] : [])],
                default => ['nullable'],
            };
        }

        return $rules;
    }

    /**
     * Keep only known keys, typed, dropping nulls — so a stored payload is
     * always something the check can read without guarding every key.
     *
     * @param  list<string>  $keys
     * @return array<string, bool|float|string>
     */
    public static function clean(?array $facts, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (! is_array($facts) || ! array_key_exists($key, $facts) || $facts[$key] === null || $facts[$key] === '') {
                continue;
            }
            $value = $facts[$key];
            $out[$key] = match (self::SCHEMA[$key]['type']) {
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'number' => (float) $value,
                default => (string) $value,
            };
        }

        return $out;
    }

    /**
     * The schema entries for `$keys`, shaped for the screen, with the value
     * the check read and who gave it.
     *
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $sources
     * @param  array<string, array<string, string>>  $options  Options computed per filing (lot_zone, special_use).
     * @return list<array<string, mixed>>
     */
    public static function describe(array $keys, array $values, array $sources, array $options = []): array
    {
        $out = [];
        foreach ($keys as $key) {
            $field = self::SCHEMA[$key] ?? null;
            if ($field === null) {
                continue;
            }
            $choices = $options[$key] ?? $field['options'] ?? null;
            $out[] = [
                'key' => $key,
                'label' => $field['label'],
                'type' => $field['type'],
                'who' => $field['who'],
                'help' => $field['help'] ?? null,
                'unit' => $field['unit'] ?? null,
                'options' => $choices === null ? null : array_map(
                    fn ($value, $label) => ['value' => (string) $value, 'label' => $label],
                    array_keys($choices),
                    array_values($choices),
                ),
                'value' => $values[$key] ?? null,
                'answered_by' => $sources[$key] ?? null,
            ];
        }

        return $out;
    }
}

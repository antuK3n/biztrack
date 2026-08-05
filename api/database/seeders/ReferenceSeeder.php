<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\OfficeSignatory;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Database\Seeder;

/** Reference & lookup data (master plan §11). Idempotent (updateOrCreate). */
class ReferenceSeeder extends Seeder
{
    /**
     * Catch-all PSIC row for trades that are not on the list. Deliberately not
     * a real PSIC number: business_lines.psic_code_id is NOT NULL, so the
     * free-text line still needs a row to hang on.
     */
    public const OTHER_PSIC_CODE = '00000';

    public function run(): void
    {
        // --- Departments (paper Table 29 + prototype LGU Section p37) --------
        $departments = [
            ['code' => 'BPLO', 'name' => 'Business Permits and Licensing Office',
                'description' => 'Processes and issues business permits; central intake for applications.'],
            ['code' => 'CHO', 'name' => 'City Health Office',
                'description' => 'Issues sanitary permits and health certificates.'],
            ['code' => 'BFP', 'name' => 'Bureau of Fire Protection',
                'description' => 'Conducts fire safety inspections and issues the FSIC.'],
            ['code' => 'CPDO', 'name' => 'City Planning and Development Office (Zoning)',
                'description' => 'Issues the zoning / locational clearance for the business location.'],
            ['code' => 'OBO', 'name' => 'Office of the Building Official',
                'description' => 'Issues occupancy permits for business premises.'],
            ['code' => 'CENRO', 'name' => 'City Environment and Natural Resources Office',
                'description' => 'Issues the City Environmental Certificate.'],
            ['code' => 'CMO-MARKET', 'name' => 'Office of the City Market Administrator',
                'description' => 'Issues market clearance for market-based businesses.'],
        ];
        foreach ($departments as $d) {
            Department::updateOrCreate(['code' => $d['code']], $d);
        }

        // --- Barangays (21, Malabon; verify vs PSGC before defense) ----------
        $barangays = [
            'Acacia', 'Baritan', 'Bayan-bayanan', 'Catmon', 'Concepcion', 'Dampalit',
            'Flores', 'Hulong Duhat', 'Ibaba', 'Longos', 'Maysilo', 'Muzon', 'Niugan',
            'Panghulo', 'Potrero', 'San Agustin', 'Santulan', 'Tañong', 'Tinajeros',
            'Tonsuya', 'Tugatog',
        ];
        foreach ($barangays as $name) {
            Barangay::updateOrCreate(['name' => $name]);
        }

        // --- PSIC codes (PSIC 2009 sections; verify vs PSA before defense) ---
        // Broad enough that an applicant can find their actual trade: the 15
        // original codes are kept verbatim (fee rules and demo data point at
        // them) and the rest cover what a BPLO counter really sees. The
        // OTHER_PSIC_CODE row is the escape hatch: picking it makes the wizard
        // require a free-text line (business_lines.line_of_business).
        $psic = [
            // Manufacturing (Section C)
            ['10300', 'Processing and preserving of fruits and vegetables'],
            ['10500', 'Manufacture of dairy products'],
            ['10611', 'Rice and corn milling'],
            ['10711', 'Manufacture of bakery products (bakeshop)'],
            ['10740', 'Manufacture of noodles and similar products'],
            ['10799', 'Manufacture of other food products (ice plant)'],
            ['10800', 'Manufacture of prepared animal feeds'],
            ['11040', 'Manufacture of soft drinks and bottled water'],
            ['14100', 'Manufacture of wearing apparel (garments and tailoring)'],
            ['15200', 'Manufacture of footwear'],
            ['16220', "Manufacture of builders' carpentry and joinery"],
            ['17020', 'Manufacture of paper and paperboard containers'],
            ['18120', 'Printing services'],
            ['20230', 'Manufacture of soap, detergents and cleaning preparations'],
            ['22200', 'Manufacture of plastic products'],
            ['23950', 'Manufacture of concrete products (hollow blocks)'],
            ['25920', 'Treatment and coating of metals (machine shop)'],
            ['31001', 'Manufacture of furniture'],
            ['32110', 'Manufacture of jewellery and related articles'],

            // Water, waste (Section E)
            ['36000', 'Water collection, treatment and supply (water refilling)'],
            ['38110', 'Collection of non-hazardous waste'],

            // Construction (Section F)
            ['41000', 'Construction of buildings (general contractor)'],
            ['43210', 'Electrical installation'],
            ['43220', 'Plumbing, heating and air-conditioning installation'],
            ['43300', 'Building completion and finishing'],

            // Motor vehicle trade and repair (Section G, 45)
            ['45201', 'Maintenance and repair of motor vehicles (auto repair)'],
            ['45301', 'Sale of motor vehicle parts and accessories'],
            ['45401', 'Sale, maintenance and repair of motorcycles'],

            // Wholesale trade (Section G, 46)
            ['46100', 'Wholesale on a fee or contract basis (commission agent)'],
            ['46301', 'Wholesale of rice, corn and other grains'],
            ['46302', 'Wholesale of fruits and vegetables'],
            ['46303', 'Wholesale of meat, poultry and seafood'],
            ['46309', 'Wholesale of other food, beverages and tobacco'],
            ['46410', 'Wholesale of textiles, clothing and footwear'],
            ['46491', 'Wholesale of household appliances and furniture'],
            ['46520', 'Wholesale of electronic and telecommunications equipment'],
            ['46630', 'Wholesale of construction materials and hardware'],
            ['46691', 'Wholesale of chemical and pharmaceutical products'],
            ['46900', 'Non-specialized wholesale trade'],

            // Retail trade (Section G, 47)
            ['47111', 'Retail sale in non-specialized stores (sari-sari store)'],
            ['47112', 'Retail sale in non-specialized stores (grocery or mini-mart)'],
            ['47190', 'Other retail sale in non-specialized stores (department store)'],
            ['47211', 'Retail sale of rice, corn and other grains'],
            ['47212', 'Retail sale of fruits and vegetables'],
            ['47213', 'Retail sale of meat and meat products'],
            ['47214', 'Retail sale of fish and other seafood'],
            ['47219', 'Retail sale of other food products (dry goods)'],
            ['47220', 'Retail sale of beverages'],
            ['47230', 'Retail sale of tobacco products'],
            ['47300', 'Retail sale of automotive fuel (gasoline station)'],
            ['47411', 'Retail sale of computers and peripheral equipment'],
            ['47412', 'Retail sale of telecommunications equipment (cellphone shop)'],
            ['47420', 'Retail sale of audio and video equipment'],
            ['47510', 'Retail sale of textiles'],
            ['47521', 'Retail sale of hardware and building materials'],
            ['47522', 'Retail sale of paints, glass and plumbing supplies'],
            ['47591', 'Retail sale of furniture'],
            ['47592', 'Retail sale of household appliances'],
            ['47610', 'Retail sale of books, newspapers and stationery'],
            ['47640', 'Retail sale of sporting goods'],
            ['47650', 'Retail sale of games and toys'],
            ['47711', 'Retail sale of clothing and apparel'],
            ['47712', 'Retail sale of footwear and leather goods'],
            ['47721', 'Retail sale of pharmaceutical goods (pharmacy)'],
            ['47722', 'Retail sale of medical and orthopaedic goods'],
            ['47723', 'Retail sale of cosmetics and toilet articles'],
            ['47730', 'Retail sale of jewellery and watches'],
            ['47733', 'Retail sale of agricultural supplies, feeds and fertilizers'],
            ['47741', 'Retail sale of second-hand goods (ukay-ukay)'],
            ['47760', 'Retail sale of flowers, plants, pets and pet food'],
            ['47810', 'Retail sale of food products via stalls and markets'],
            ['47820', 'Retail sale of textiles and footwear via stalls and markets'],
            ['47912', 'Retail sale via internet (online store)'],
            ['47990', 'Other retail sale not in stores (direct selling)'],

            // Transportation and storage (Section H)
            ['49221', 'Passenger land transport (jeepney, UV express, tricycle)'],
            ['49230', 'Freight transport by road (trucking)'],
            ['52101', 'Warehousing and storage'],
            ['52290', 'Other transportation support activities (freight forwarding)'],
            ['53100', 'Postal and courier activities'],

            // Accommodation and food service (Section I)
            ['55101', 'Hotels and resorts'],
            ['55102', 'Apartelles, pension houses and inns'],
            ['55103', 'Motels and lodging houses'],
            ['55900', 'Other accommodation (dormitory and boarding house)'],
            ['56101', 'Restaurants and carinderia'],
            ['56102', 'Fast-food and quick-service restaurants'],
            ['56103', 'Refreshment stands, kiosks and food carts'],
            ['56210', 'Event catering services'],
            ['56290', 'Other food service activities (canteen and institutional catering)'],
            ['56301', 'Beverage serving activities (coffee shop)'],
            ['56302', 'Bars, beer houses and drinking places'],

            // Information and communication (Section J)
            ['58130', 'Publishing of newspapers and periodicals'],
            ['59140', 'Motion picture projection (cinema)'],
            ['61100', 'Wired telecommunications activities'],
            ['62010', 'Computer programming activities'],
            ['62090', 'Other information technology and computer service activities'],
            ['63110', 'Data processing, hosting and related activities'],

            // Finance and real estate (Sections K and L)
            ['64920', 'Other credit granting (lending investor and pawnshop)'],
            ['64990', 'Other financial service activities (money remittance)'],
            ['65120', 'Non-life insurance'],
            ['68100', 'Lessor of real estate (apartments, stalls, commercial space)'],
            ['68200', 'Real estate activities on a fee or contract basis (brokerage)'],

            // Professional, scientific and technical (Section M)
            ['69100', 'Legal activities'],
            ['69200', 'Accounting, bookkeeping and auditing activities'],
            ['70200', 'Management consultancy activities'],
            ['71100', 'Architectural and engineering activities'],
            ['73100', 'Advertising'],
            ['74200', 'Photographic activities (photo studio)'],
            ['75000', 'Veterinary activities'],

            // Administrative and support services (Section N)
            ['77100', 'Renting and leasing of motor vehicles (rent-a-car)'],
            ['77290', 'Renting and leasing of other personal and household goods'],
            ['78100', 'Activities of employment placement agencies'],
            ['79110', 'Travel agency activities'],
            ['80100', 'Private security activities'],
            ['81210', 'General cleaning of buildings (janitorial services)'],
            ['82200', 'Activities of call centres'],
            ['82990', 'Other business support service activities'],

            // Education, health (Sections P and Q)
            ['85100', 'Pre-primary and primary education (private school)'],
            ['85490', 'Other education (review, tutorial and driving schools)'],
            ['86100', 'Hospital activities'],
            ['86201', 'Medical and dental clinic activities'],
            ['86901', 'Medical and diagnostic laboratory activities'],

            // Arts, entertainment and recreation (Section R)
            ['92000', 'Gambling and betting activities (lotto outlet)'],
            ['93110', 'Operation of sports and fitness facilities (gym)'],
            ['93290', 'Other amusement and recreation (billiard hall, videoke, internet cafe)'],

            // Other service activities (Section S)
            ['95110', 'Repair of computers and peripheral equipment'],
            ['95210', 'Repair of consumer electronics'],
            ['95220', 'Repair of household appliances'],
            ['95230', 'Repair of footwear and leather goods'],
            ['95290', 'Repair of other personal and household goods'],
            ['96110', 'Barbershop and hairdressing'],
            ['96120', 'Beauty parlour, salon and spa services'],
            ['96200', 'Laundry and dry-cleaning services'],
            ['96301', 'Funeral and related activities'],
            ['96990', 'Other personal service activities'],

            // Escape hatch — the applicant types their own line of business.
            [self::OTHER_PSIC_CODE, 'Other (not listed)'],
        ];
        foreach ($psic as [$code, $title]) {
            PsicCode::updateOrCreate(['code' => $code], ['title' => $title]);
        }

        // --- Document types --------------------------------------------------
        $docs = [
            ['DTI_SEC_CDA', 'Business Registration (DTI / SEC / CDA)', 'Your DTI, SEC, or CDA certificate of registration.'],
            ['LEASE_TITLE', 'Lease Contract or Land Title', 'Proof you can operate at this address: a lease contract or land title.'],
            ['BRGY_CLEARANCE', 'Barangay Business Clearance', 'A clearance from the barangay where your business is located.'],
            ['CEDULA', 'Community Tax Certificate (Cedula)', 'Your current cedula.'],
            ['VALID_ID', 'Valid Government ID', 'Any government-issued ID of the owner or authorized representative.'],
            ['PRIOR_PERMIT', 'Previous Mayor\'s Permit', 'Your last Mayor\'s Permit. Needed for renewals only.'],
            ['OCCUPANCY', 'Occupancy Permit', 'Certificate of occupancy for the building, where applicable.'],
            ['SANITARY_REQ', 'Sanitary Requirements', 'Health cards and sanitary documents for food-related businesses.'],
            ['FIRE_REQ', 'Fire Safety Requirements', 'Fire safety documents required for the FSIC.'],
            ['LOCATIONAL', 'Locational / Zoning Clearance', 'Zoning clearance for your business location.'],
            // Repeatable "Other Requirements": applicants may attach several files.
            ['OTHER', 'Other Requirements', 'Any other supporting documents. You can add more than one file.'],
        ];
        foreach ($docs as [$code, $name, $help]) {
            DocumentType::updateOrCreate(['code' => $code], ['name' => $name, 'help_text' => $help]);
        }

        // --- Permit types (7, prototype LGU Section p37; validity 365) -------
        // Manuscript names 3 (BUSINESS/SANITARY/FSIC); the rest are additive
        // per the prototype (most-recent team agreement). BUSINESS is what the
        // whole application is FOR, so the wizard attaches it implicitly and
        // only shows the six supporting clearances as cards.
        $dept = fn (string $c) => Department::where('code', $c)->first()->id;

        $business = PermitType::updateOrCreate(['code' => 'BUSINESS'], [
            'name' => "Mayor's / Business Permit",
            'permit_number_prefix' => 'MCB',
            'issuing_department_id' => $dept('BPLO'),
            'validity_days' => 365, 'description' => "Annual mayor's permit to operate.",
            'requires_inspection' => false,
            'base_fee' => 1000, 'per_line_surcharge' => 150,
        ]);
        $sanitary = PermitType::updateOrCreate(['code' => 'SANITARY'], [
            'name' => 'Sanitary Permit / Health Certificate',
            'permit_number_prefix' => 'MCS',
            'issuing_department_id' => $dept('CHO'),
            'validity_days' => 365, 'description' => 'Health/sanitary clearance to operate.',
            'requires_inspection' => true,
            'base_fee' => 500, 'per_line_surcharge' => 50,
        ]);
        $fsic = PermitType::updateOrCreate(['code' => 'FSIC'], [
            'name' => 'Fire Safety Inspection Certificate',
            'permit_number_prefix' => 'MCF',
            'issuing_department_id' => $dept('BFP'),
            'validity_days' => 365, 'description' => 'Fire safety clearance to operate.',
            'requires_inspection' => true,
            'base_fee' => 700, 'per_line_surcharge' => 80,
        ]);
        $occupancy = PermitType::updateOrCreate(['code' => 'OCCUPANCY'], [
            'name' => 'Occupancy Permit',
            'permit_number_prefix' => 'MCO',
            'issuing_department_id' => $dept('OBO'),
            'validity_days' => 365, 'description' => 'Certificate of occupancy for the business premises.',
            /*
             * All six supporting clearances are inspected. Only SANITARY and
             * FSIC carried the flag, so OBO, CENRO, CPDO and the Market Office
             * were routed filings they could review on paper and then had no
             * way to inspect — the client's report was that those four "cannot
             * approve inspection ... so basically their permits also have
             * inspections lol, not just those two".
             *
             * It is the obvious reading of what each office issues: an
             * occupancy permit certifies the premises, an environmental
             * certificate and a locational clearance are both about the site,
             * and a market clearance is about a stall that has to be looked at.
             * None of those can honestly be granted from the desk.
             *
             * BUSINESS stays false, and that is the one real exception: the
             * Mayor's Permit is issued by BPLO on the strength of the six
             * clearances rather than a visit of its own. Setting it true would
             * put a seventh visit on every filing and stall approveAndIssue
             * behind an inspection nobody performs.
             */
            'requires_inspection' => true,
            'base_fee' => 800, 'per_line_surcharge' => 0,
        ]);
        $cec = PermitType::updateOrCreate(['code' => 'CEC'], [
            'name' => 'City Environmental Certificate',
            'permit_number_prefix' => 'MCE',
            'issuing_department_id' => $dept('CENRO'),
            'validity_days' => 365, 'description' => 'Environmental compliance certificate.',
            // Inspected, like the other clearances — see the note on OCCUPANCY.
            'requires_inspection' => true,
            'base_fee' => 400, 'per_line_surcharge' => 0,
        ]);
        $zoning = PermitType::updateOrCreate(['code' => 'ZONING'], [
            'name' => 'Zoning / Locational Clearance',
            'permit_number_prefix' => 'MCZ',
            'issuing_department_id' => $dept('CPDO'),
            'validity_days' => 365, 'description' => 'Confirms the business location conforms to the city zoning ordinance.',
            // Inspected, like the other clearances — see the note on OCCUPANCY.
            'requires_inspection' => true,
            // Legacy flat-fee fallback only. The real charge comes from the
            // revenue-code rules (Sec. 3.D.01: 45 filing + 345 verification +
            // 345 processing = 735), which supersede this column.
            'base_fee' => 735, 'per_line_surcharge' => 0,
        ]);
        $market = PermitType::updateOrCreate(['code' => 'MARKET'], [
            'name' => 'Market Clearance',
            'permit_number_prefix' => 'MCM',
            'issuing_department_id' => $dept('CMO-MARKET'),
            'validity_days' => 365, 'description' => 'Clearance for market-based businesses.',
            // Inspected, like the other clearances — see the note on OCCUPANCY.
            'requires_inspection' => true,
            'base_fee' => 300, 'per_line_surcharge' => 0,
        ]);

        // --- Requirement checklists (context per paper Table 59 enum) --------
        $byCode = fn (string $c) => DocumentType::where('code', $c)->first()->id;
        $req = function (PermitType $pt, array $rows) use ($byCode) {
            $sync = [];
            foreach ($rows as $code => $meta) {
                $sync[$byCode($code)] = [
                    'context' => $meta['context'] ?? 'all',
                    'is_mandatory' => $meta['is_mandatory'] ?? true,
                    'notes' => $meta['notes'] ?? null,
                ];
            }
            $pt->documentTypes()->sync($sync);
        };
        /*
         * The business permit's seven, and why it is seven and not six.
         *
         * Checklist item 96 says the list "should be 6". Six is what a NEW
         * filing already shows: PRIOR_PERMIT is context-gated to renewals,
         * because a business being registered for the first time has no
         * previous Mayor's Permit and demanding one is an unclearable block
         * rather than a requirement. A renewal shows seven, and the seventh is
         * the permit being renewed — which the counter must see. Neither number
         * is guessed at here; both fall out of the contexts below.
         *
         * What we cannot check is whether these are the SAME six the counter's
         * paper form names. We have never been given a copy — it is the oldest
         * open item on the list (`docs/questions-for-malabon.md` E1, and now
         * E9, which names all seven and asks). Until it comes back, nothing is
         * deleted from this list on a guess.
         *
         * OCCUPANCY is deliberately not mandatory. Its help text says "where
         * applicable", and since `docs/clearances-after-payment.md` the
         * Occupancy Permit is applied for in its own stage after payment — so
         * requiring the certificate up front asks the applicant to produce the
         * output of a stage they have not reached. It still appears in the
         * list, marked optional, for the applicant who already holds one.
         * Migration 2026_08_03_000020 carries the same change to databases
         * that were seeded before it.
         */
        $req($business, [
            'DTI_SEC_CDA' => [], 'LEASE_TITLE' => [], 'BRGY_CLEARANCE' => [],
            'CEDULA' => [], 'VALID_ID' => [],
            'OCCUPANCY' => ['is_mandatory' => false, 'notes' => 'Where applicable — otherwise applied for in the LGU Clearances stage.'],
            'PRIOR_PERMIT' => ['context' => 'renewal', 'notes' => 'Required for renewals only.'],
        ]);
        $req($sanitary, ['SANITARY_REQ' => [], 'VALID_ID' => []]);
        $req($fsic, ['FIRE_REQ' => [], 'VALID_ID' => []]);
        $req($occupancy, [
            'OCCUPANCY' => ['context' => 'occupancy'],
            'VALID_ID' => ['context' => 'occupancy'],
        ]);
        $req($cec, ['LOCATIONAL' => [], 'VALID_ID' => []]);
        $req($zoning, ['LEASE_TITLE' => [], 'BRGY_CLEARANCE' => [], 'VALID_ID' => []]);
        $req($market, ['BRGY_CLEARANCE' => [], 'VALID_ID' => []]);

        /*
         * --- Form signatories -------------------------------------------------
         *
         * Starting values only. These are the officeholders named in the printed
         * CENRO application form (MCG-CENRO-FO-001 v2.0) as of August 2026.
         *
         * firstOrCreate, deliberately, where the rest of this seeder uses
         * updateOrCreate: every other row here is reference data the seeder owns,
         * but a signatory name is owned by the admin the moment they edit it.
         * Re-seeding must not quietly restore a predecessor's name over the
         * correction that replaced them. Seed only offices whose names were read
         * off an actual document — a guess is worse than a blank.
         */
        $signatory = function (string $deptCode, array $rows) use ($dept) {
            foreach ($rows as $i => [$role, $name]) {
                OfficeSignatory::firstOrCreate(
                    ['department_id' => $dept($deptCode), 'role' => $role],
                    ['name' => $name, 'sort_order' => $i, 'is_active' => true],
                );
            }
        };
        $signatory('CENRO', [
            ['Evaluator', 'Elizabeth E. Gutierrez'],
            ['Chief-CENRO', 'Mark Lloyd A. Mesina'],
        ]);
    }
}

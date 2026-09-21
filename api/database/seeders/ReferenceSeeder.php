<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\OfficeSignatory;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\TaxClassification;
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
            /*
             * CMO-MARKET (Office of the City Market Administrator) was here and
             * is gone [client, 2026-09-06]. It issued the Market Clearance, which
             * has been removed from the system entirely — the client confirmed
             * with the LGU that neither the clearance nor its admin is needed.
             * A business that genuinely needs one is asked for it by hand,
             * through Other Requirements.
             *
             * To bring it back: restore this row, the MARKET permit type below,
             * its requirement checklist, the `market.stall_rental` rule in
             * database/data/revenue_code/system.json, and MARKET in
             * PermitType::OFFICE_FORM_CODES / CLEARANCE_ORDER plus the web's
             * OFFICE_FORM_CODES. It was never used: no filing in the register
             * ever requested it.
             */
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
        /*
         * The classification comes from TaxClassification, not from the rows
         * above, and deliberately so: migration 2026_09_16_000100 populates
         * the same three columns from the same constant, and two hand-kept
         * copies of a 135-row mapping would drift the first time one was
         * corrected. The rows above own the code and the title; the mapping
         * owns what the Revenue Code does with them.
         *
         * A code absent from the mapping gets nulls rather than a guess, which
         * shows up as the old Tax Classification question being asked for it —
         * visible, and recoverable, in a way a wrong class would not be.
         */
        foreach ($psic as [$code, $title]) {
            [$taxClass, $permitCategory, $branch] = TaxClassification::FOR_PSIC[$code] ?? [null, null, null];

            PsicCode::updateOrCreate(['code' => $code], [
                'title' => $title,
                'category' => $taxClass,
                'permit_category' => $permitCategory,
                'category_branch' => $branch,
            ]);
        }

        // --- Document types --------------------------------------------------
        $docs = [
            ['DTI_SEC_CDA', 'Proof of Business Registration (DTI / SEC / CDA)', 'Your certificate of registration: DTI if you are a sole proprietor, SEC for a corporation, partnership or OPC, CDA for a cooperative.'],
            ['LEASE_TITLE', 'Lease Contract or Land Title', 'Proof you can operate at this address: a lease contract or land title.'],
            ['BRGY_CLEARANCE', 'Barangay Business Clearance', 'A clearance from the barangay where your business is located.'],
            ['CEDULA', 'Community Tax Certificate (Cedula)', 'Your current cedula.'],
            ['VALID_ID', 'Valid Government ID', 'Any government-issued ID of the owner or authorized representative.'],
            ['PRIOR_PERMIT', 'Previous Mayor\'s Permit', 'Your last Mayor\'s Permit. Needed for renewals only.'],
            ['OCCUPANCY', 'Occupancy Permit', 'Certificate of occupancy for the building, where applicable.'],
            ['SANITARY_REQ', 'Sanitary Requirements', 'Health cards and sanitary documents for food-related businesses.'],
            ['FIRE_REQ', 'Fire Safety Requirements', 'Fire safety documents required for the FSIC.'],
            ['LOCATIONAL', 'Locational / Zoning Clearance', 'Zoning clearance for your business location.'],
            /*
             * Section B items 7 and 8 both read "Yes (Please attach a copy of
             * your ...)", and neither copy had anywhere to go: the applicant
             * was told to put them under Other Requirements, which is a bin,
             * not a requirement. These three give each answer its own slot.
             *
             * LEASE_CONTRACT and LAND_TITLE split what LEASE_TITLE covered
             * with a slash. One applicant holds one of the two, never both, and
             * which one is decided by item 8 — so naming both in one
             * requirement made every applicant read a label half of which did
             * not apply to them. LEASE_TITLE itself stays seeded: filings
             * already hold documents under it.
             */
            ['TAX_INCENTIVE_CERT', 'Tax Incentive Certificate', 'The certificate from the government entity granting your tax incentive. Asked because you answered Yes to item 7.'],
            ['LEASE_CONTRACT', 'Contract of Lease', 'Your lease over the premises. Asked because you answered Yes to item 8.'],
            // Item 3's owned branch, quoted exactly: the paper has no spaces
            // around that slash, and this row quotes the paper in full.
            ['LAND_TITLE', 'Tax Declaration/Transfer Certificate of Title (TCT)', 'Proof you own the premises, since you are not paying rent for them. Either document will do.'],
            /*
             * MCG-BPLO-FO-001's documentary requirements, items 3, 5 and 6.
             *
             * These names are the paper's own, near enough word for word, and
             * that is on purpose: a clerk reconciling this screen against the
             * printed checklist reads down one and finds each line in the
             * other. Reword them only alongside the paper.
             */
            ['LESSOR_PERMIT', 'Business Permit of Lessor', "The lessor's own business permit, which the paper asks for alongside your lease."],
            ['LOCATION_SKETCH', 'Sketch and photos of location of business', 'A sketch of how to reach the premises, with photos of the place of business.'],
            ['SPA_AUTHORIZATION', 'SPA / Authorization to Transact, with ID photocopies', 'A special power of attorney or authorisation letter for the person filing on your behalf, together with photocopies of their ID. Only needed if somebody is transacting for you.'],
            // Repeatable "Other Requirements": applicants may attach several files.
            ['OTHER', 'Other Requirements', 'Any other supporting documents. You can add more than one file.'],
            /*
             * MCG-BPLO-FO-003's own three, which every one of its four boxes
             * asks for in some combination. Added here as well as in the
             * amendment migrations because `$req()` below SYNCS — a fresh
             * database drops anything a migration added and writes this list
             * over the top, so a type that lives only in a migration is a type
             * no test ever sees.
             */
            ['AMEND_AFFIDAVIT', 'Affidavit requesting the amendment', 'An affidavit asking BPLO to acknowledge the change you are filing for.'],
            ['AMEND_CORP_DOCS', 'Amended Articles of Incorporation, Board Resolution and Secretary’s Certification', 'For a corporation, partnership or cooperative: the amended articles together with the board resolution and the secretary’s certification.'],
            ['AMEND_DEED_TRANSFER', 'Deed of Transfer', 'Deed of Sale or Assignment, Affidavit of Self-Adjudication, or Extra-Judicial Settlement of the estate of a deceased owner.'],
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
        // MARKET (Market Clearance, MCM, CMO-MARKET) removed 2026-09-06 — see
        // the note where its department used to be seeded.

        // --- Requirement checklists (context per paper Table 59 enum) --------
        $byCode = fn (string $c) => DocumentType::where('code', $c)->first()->id;
        /*
         * `order` is the row's position on the office's printed checklist, and
         * it is left off wherever the office has no printed order to follow —
         * those rows default to 100 and sort by document type id, which is
         * where they have always come out. Only the business permit sets it,
         * because only the business permit's list is read side by side with a
         * paper one. See PermitType::documentTypes().
         */
        $req = function (PermitType $pt, array $rows) use ($byCode) {
            $sync = [];
            foreach ($rows as $code => $meta) {
                $sync[$byCode($code)] = [
                    'context' => $meta['context'] ?? 'all',
                    'is_mandatory' => $meta['is_mandatory'] ?? true,
                    'notes' => $meta['notes'] ?? null,
                    'display_order' => $meta['order'] ?? 100,
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
            /*
             * LEASE_TITLE is no longer here, and the document type is still
             * seeded above. It was "Lease Contract or Land Title", mandatory
             * for everyone, so every applicant read a label half of which did
             * not apply to them — and the half that did was decided by item 8,
             * which the requirement had no way to see. LEASE_CONTRACT and
             * LAND_TITLE replace it below, one each, gated on that answer.
             *
             * The TYPE stays because filings already carry attachments under
             * it: dropping it would orphan them from their own name in every
             * officer's document list. It is simply no longer demanded of a new
             * filing.
             */
            /*
             * The paper's six, and only those — questions-for-malabon E9,
             * answered by the client supplying the page on 16 September 2026.
             * Barangay Business Clearance and the Cedula are NOT on it and are
             * no longer demanded; their document types stay seeded because
             * filings already carry attachments under them.
             *
             * ── The two items BizTrack issues itself are absent ──────────────
             *
             * Item 2, the Locational Clearance, and item 4, the Occupancy
             * Permit. Both are on the paper and neither is asked here, for one
             * reason: BizTrack ISSUES them, in the LGU Clearances stage after
             * the first payment. Asking a new business for either up front
             * demands the output of a stage it has not reached.
             *
             * Occupancy was kept for a while, optional, "for the applicant who
             * already holds one" — and that reason stopped holding. HeldPermits
             * mints a HELD_<CODE> slot on demand for every clearance, so such
             * an applicant uploads their certificate in the clearance stage
             * through "Upload an existing copy", which also spares them the
             * office form and the inspection. The optional row here was a
             * second, worse route to the same place: it took the file and left
             * the clearance un-applied-for. Removed at the client's instruction,
             * 16 September 2026.
             *
             * Item 6 is ONE row, not two. The paper prints "Special power of
             * attorney (SPA)/Authorization to transact for representative
             * together with photocopies of IDs" — one requirement, two
             * documents, handed in together. It was briefly seeded as SPA plus
             * a separate VALID_ID, which asked twice for one item and left
             * every owner filing in person looking at an optional "Valid
             * Government ID" this paper never asks them for. The IDs live in
             * the SPA row's own name and help text now.
             *
             * VALID_ID the TYPE stays seeded: the sanitary and fire checklists
             * ask for it in their own right, and filings already carry
             * attachments under it.
             *
             * ── Certain first, conditional last ─────────────────────────────
             *
             * `order` is NOT the paper's printed number. It was, briefly, and
             * that was wrong for a screen: the printed sequence interleaves
             * rows every applicant uploads with rows that appear only because
             * of an answer to section B, and since the screen shows only the
             * rows that apply, the applicant read the printed list with holes
             * punched through the middle of it.
             *
             * So bands, ordered by how certain the row is — and then, at the
             * very end, by whether it is owed at all:
             *
             *   10-20  required of everyone
             *   40     required by the filing (a renewal needs its old permit)
             *   50-60  required by the applicant's own answers
             *   70     OPTIONAL, and therefore last of all
             *
             * The optional row is the one place certainty and obligation come
             * apart: the SPA is shown to everyone, which by certainty alone put
             * it third, with five required rows beneath it — the one box an
             * applicant may leave empty, ahead of five they may not, on a step
             * whose whole job is saying what they still owe.
             *
             * Gaps are kept so a row can join a band without renumbering. 30 is
             * where another always-required row would go.
             */
            /*
             * `amend_sole` and not `amend_trade_name`: FO-003 asks for DTI
             * registration "For Single Proprietor" on boxes I, II and III, and
             * a corporation filing any of them owes amended Articles instead.
             * Asked of everybody, it sent corporations looking for a DTI
             * certificate they have never held.
             */
            'DTI_SEC_CDA' => ['order' => 10, 'context' => 'new,renewal,amend_sole', 'notes' => 'Paper item 1. FO-003 asks it of a sole proprietor on boxes I–III.'],
            'LOCATION_SKETCH' => ['order' => 20, 'context' => 'new,renewal,amend_address', 'notes' => 'Paper item 5. FO-003 I asks for a picture and sketch map of the new location.'],
            'PRIOR_PERMIT' => ['order' => 40, 'context' => 'renewal', 'notes' => 'Not on the documentary list — required for renewals only.'],
            /*
             * Item 3, all three rows of it, and the tax incentive certificate.
             *
             * The paper prints item 3 as one line — "Contract of Lease AND
             * Business Permit of Lessor (if leased), or Tax Declaration / TCT
             * (if owned)" — and an applicant sees either the first two rows or
             * the third, never all three, decided by item 8. They stay
             * adjacent because they are one requirement with two branches.
             *
             * These contexts are answer-driven, which is a THIRD kind of
             * context. Until now `context` took 'all' or an application type,
             * and both are facts about the FILING; these are facts about the
             * applicant's answers on it. ApplyWizard::requiredDocs resolves
             * them — see the note there — and the API re-checks at submission,
             * because the browser is not the only way in.
             *
             * The tax incentive certificate is last of all: answer-driven AND
             * absent from the documentary list, so it is the one row a clerk
             * reconciling this screen against the paper will not find there.
             */
            /*
             * The tenure pair carries an amendment token EACH, not one between
             * them. FO-003 section I asks for "Contract of Lease and/or Proof
             * of Ownership", and which of the two you owe is decided by
             * whether you rent — exactly as on a new application. Sharing one
             * `amend_address` token made an address amendment match both, so
             * a shop that rents was told to produce a land title.
             */
            'LEASE_CONTRACT' => ['order' => 50, 'context' => 'rented,amend_address_rented', 'notes' => 'Paper item 3 — required when item 8 is Yes. FO-003 I asks the same of a move.'],
            'LESSOR_PERMIT' => ['order' => 51, 'context' => 'rented', 'notes' => 'Paper item 3 — asked with the Contract of Lease.'],
            'LAND_TITLE' => ['order' => 52, 'context' => 'owned,amend_address_owned', 'notes' => 'Paper item 3 — required when item 8 is No. FO-003 I asks the same of a move.'],
            'TAX_INCENTIVE_CERT' => ['order' => 60, 'context' => 'tax_incentives', 'notes' => 'Not on the documentary list — section B item 7, required when the answer is Yes.'],
            /*
             * Last, because it is the only row nobody is obliged to fill. Paper
             * item 6 states its own condition in its own wording — the paper
             * does not ASK whether a representative is filing, and neither do
             * we; a question invented for that was put in and taken back out on
             * 16 September 2026. So the row shows to everyone and is owed by
             * almost none of them, which is exactly what belongs at the bottom.
             */
            'SPA_AUTHORIZATION' => ['order' => 70, 'is_mandatory' => false, 'notes' => 'Paper item 6 — attach this if somebody is transacting on your behalf.'],
            /*
             * ── MCG-BPLO-FO-003, the Amendment Form ──────────────────────
             *
             * Its four boxes each print their own requirements list, and the
             * `amend_*` tokens are how one pivot says so —
             * `AmendableFields::GROUPS` names the boxes and
             * `ApplyWizard::requiredDocs` resolves the tokens.
             *
             * Two requirements the paper asks for are deliberately absent:
             * the "Photocopy of Business/Mayor's Permit", which BizTrack
             * issued and holds, and the "Zoning clearance", which is a record
             * here rather than an upload and which a cross-barangay move
             * re-applies for as part of the amendment. Both decisions are
             * written up in the 000190 migration.
             */
            'AMEND_AFFIDAVIT' => ['order' => 80, 'context' => 'amendment', 'notes' => 'FO-003 — every box asks for it.'],
            'AMEND_DEED_TRANSFER' => ['order' => 85, 'context' => 'amend_owner', 'notes' => 'FO-003 II.'],
            'AMEND_CORP_DOCS' => ['order' => 90, 'context' => 'amend_corporate', 'notes' => 'FO-003 I–III, for a corporation, partnership or cooperative.'],
            /*
             * "Other documents that may be required" — the paper's own last
             * line on all four boxes, and optional by definition. It is also
             * where the unnumbered box's "(if required)" corporate papers land,
             * since one pivot row cannot be mandatory and optional at once.
             */
            'OTHER' => ['order' => 99, 'context' => 'amendment', 'is_mandatory' => false, 'notes' => 'FO-003 — "Other documents that may be required".'],
        ]);
        $req($sanitary, ['SANITARY_REQ' => [], 'VALID_ID' => []]);
        $req($fsic, ['FIRE_REQ' => [], 'VALID_ID' => []]);
        $req($occupancy, [
            'OCCUPANCY' => ['context' => 'occupancy'],
            'VALID_ID' => ['context' => 'occupancy'],
        ]);
        $req($cec, ['LOCATIONAL' => [], 'VALID_ID' => []]);
        $req($zoning, ['LEASE_TITLE' => [], 'BRGY_CLEARANCE' => [], 'VALID_ID' => []]);

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

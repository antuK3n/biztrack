<?php

namespace App\Support;

/**
 * Line of business → the Revenue Code classes that price it.
 *
 * ── Why this exists ───────────────────────────────────────────────────────
 *
 * The wizard used to ask the applicant to classify their own business, from a
 * type-ahead of 273 labels, under the heading "Tax Classification (Malabon
 * Revenue Code)". `psic_codes.category` has existed the whole time for exactly
 * this mapping and was empty for all 135 codes, so the work fell to the person
 * least equipped to do it.
 *
 * It was not merely unfriendly. It was MIS-BILLING, because two fee groups key
 * on two different lists and one box held one answer:
 *
 *   • `business_tax` matches only the 22 broad classes of Sec. 2J.02 —
 *     retailer, wholesaler, manufacturer, contractor, restaurant, bank …
 *   • `mayors_permit` matches only the 117 fine categories of Sec. 3A.03 —
 *     carinderia, barber_shop, cellphone_dealer, movie_house …
 *
 * Measured on a carinderia with ₱1,200,000 of gross sales and 45 sq. m.:
 *
 *   picks "Carinderia"   ₱2,218.25   ₱550 permit fee, NO business tax
 *   picks "Restaurant"   ₱11,707.00  ₱9,750 business tax, ₱450 catch-all
 *   both                 ₱11,968.25  correct
 *
 * The more precise, more obviously-correct answer was the one that lost
 * ₱9,750 — 81% of the bill. No applicant could get it right, because the
 * correct answer needs both keys and the screen offered one box.
 *
 * So each PSIC code carries BOTH keys here, and the applicant is asked
 * neither. Client decision, 16 September 2026.
 *
 * ── The one question that survives ────────────────────────────────────────
 *
 * Sec. 2J.02(c) taxes dealers in ESSENTIAL commodities at half the ordinary
 * rate, and PSIC cannot tell rice from radios: "Retail sale of rice, corn and
 * other grains" is essential, "Retail sale of jewellery" is not, and a
 * sari-sari store is both at once. So a code is marked one of three ways:
 *
 *   • essential outright     — 47211 rice, 46301 wholesale grains, 47721 pharmacy
 *   • ordinary outright      — 47730 jewellery, 47650 toys, 47741 ukay-ukay
 *   • BRANCH_ESSENTIALS      — genuinely mixed; the applicant answers one Yes/No
 *
 * The essential list is the LGC Sec. 143(c) enumeration the Code adopts: rice
 * and corn; wheat or cassava flour, meat, dairy, locally manufactured/
 * processed/preserved food, sugar, salt and other agricultural, marine and
 * fresh-water products; cooking oil and cooking gas; laundry soap, detergents
 * and medicine; agricultural implements, equipment and post-harvest
 * facilities, fertilisers, pesticides, insecticides, herbicides and other farm
 * inputs; poultry and other animal feeds; school supplies; cement.
 *
 * NOTE for BPLO: a mixed trader is charged ONE rate on the whole of their
 * gross. Apportioning essential from non-essential turnover is what the Code
 * contemplates and is beyond what this asks; "mainly" is the approximation,
 * and it is the applicant's declaration, on the record, not our guess.
 *
 * ── Where a permit category is null, and why that is deliberate ───────────
 *
 * A null permit category is not an oversight. It means the Code's fine
 * category cannot be determined from the line of business alone, so the fee
 * falls to Sec. 3A.03 item 64 — "all other businesses not specifically
 * mentioned", priced by office area — which is precisely what that item is
 * for. Guessing a specific fee would be worse: the spreads are wide (a hotel
 * is ₱2,200 to ₱11,000 by class; a bar ₱4,400 to ₱16,500 by whether it has
 * VIP rooms and live entertainment) and a wrong guess bills a real amount
 * confidently.
 *
 * Every such case is listed in docs/psic-to-revenue-code-class.md with its fee
 * spread, which is the list BPLO needs to review. That review is the point:
 * classify a trade wrong here and every filing in that trade is mis-taxed
 * systematically and silently.
 *
 * ── Two things this mapping cannot reach ──────────────────────────────────
 *
 * 40 of the 165 mayor's-permit rules are unreachable whatever is mapped here,
 * blocked by four facts nothing in BizTrack collects: `office_location` (35
 * rules), `goods_class` (32), `warehouse_location` (24), `factory_location`
 * (4). That is why a manufacturer can only ever match the ₱6,050 "multiple
 * products" rule and never the ₱4,400–₱8,800 ones that name what they handle.
 * Logged, not fixed; collecting those four is its own decision.
 */
class TaxClassification
{
    /** The applicant answers one Yes/No: do they mainly deal in essentials? */
    public const BRANCH_ESSENTIALS = 'essentials';

    /** Half-rate counterpart of each ordinary class, under Sec. 2J.02(c). */
    public const ESSENTIAL_OF = [
        'retailer' => 'essential_retailer',
        'wholesaler' => 'essential_wholesaler',
        'manufacturer' => 'essential_manufacturer',
    ];

    /**
     * The liquor licence category a trade falls into, when it serves liquor.
     *
     * Sec. 3A.03 charges a liquor filing fee by HOW the liquor is sold, not by
     * what the business otherwise is: ₱150 retail, ₱300 wholesale, ₱750 for
     * serving on the premises, ₱1,000 for manufacturing, ₱5,000 in an
     * amusement place. Those are fine categories, and no PSIC code maps to
     * one, because no line of business tells you whether a shop happens to
     * stock beer.
     *
     * So the "sells or serves liquor" answer supplies a THIRD key, the same
     * way the line of business supplies the first two. Without this the flag
     * was inert for everyone except an amusement place: the old 273-label
     * picker let an applicant type `liquor_retailer` themselves, and removing
     * that picker took the only route those nine rules had. Caught by
     * measuring each flag's effect rather than by any test.
     *
     * `amusement_place` needs no entry — it is already the class AND the
     * category the ₱5,000 rule keys on.
     */
    public const LIQUOR_OF = [
        'retailer' => 'liquor_retailer',
        'essential_retailer' => 'liquor_retailer',
        'wholesaler' => 'liquor_wholesaler',
        'essential_wholesaler' => 'liquor_wholesaler',
        'restaurant' => 'liquor_serving',
        'bar_nightclub' => 'liquor_serving',
        'manufacturer' => 'liquor_manufacturer',
        'essential_manufacturer' => 'liquor_manufacturer',
    ];

    /**
     * PSIC code => [tax class, permit category, branch].
     *
     * Tax class drives `business_tax` (Sec. 2J.02). Permit category drives
     * `mayors_permit` (Sec. 3A.03), null meaning item 64's catch-all. Branch
     * names a follow-up question, null meaning none is asked.
     *
     * @var array<string, array{0: ?string, 1: ?string, 2: ?string}>
     */
    public const FOR_PSIC = [
        // The applicant typed their own line of business, so there is nothing
        // to derive from. The only code that still needs the old question.
        '00000' => [null, null, null],

        // ── Manufacturing, Sec. 2J.02(a) ──────────────────────────────────
        // Food and feed manufacture is essential outright: "locally
        // manufactured, processed or preserved food" and "poultry and other
        // animal feeds" are both on the Sec. 143(c) list.
        '10300' => ['manufacturer', null, null],                       // fruit & vegetable processing → essential
        '10500' => ['manufacturer', null, null],                       // dairy → essential
        '10611' => ['manufacturer', null, null],                       // rice & corn milling → essential (miller)
        '10711' => ['manufacturer', null, null],                       // bakeshop → essential
        '10740' => ['manufacturer', null, null],                       // noodles → essential
        '10799' => ['manufacturer', null, self::BRANCH_ESSENTIALS],    // "other food products (ice plant)" — ice is not food
        '10800' => ['manufacturer', null, null],                       // animal feeds → essential
        '11040' => ['manufacturer', null, self::BRANCH_ESSENTIALS],    // bottled water may be, soft drinks are not
        '14100' => ['manufacturer', 'manufacturer_small_scale', null], // RTW is named in that rule
        '15200' => ['manufacturer', null, null],                       // footwear
        '16220' => ['manufacturer', null, null],                       // builders' carpentry
        '17020' => ['manufacturer', null, null],                       // paperboard containers
        '18120' => ['printing_publication', null, null],               // Sec. 2E.01, not 2J.02
        '20230' => ['manufacturer', null, self::BRANCH_ESSENTIALS],    // laundry soap & detergents are essential; other cleaners are not
        '22200' => ['manufacturer', 'manufacturer_small_scale', null], // "assembled plastic products" is named in that rule
        '23950' => ['manufacturer', null, self::BRANCH_ESSENTIALS],    // cement is essential; hollow blocks made of it are a judgement
        '25920' => ['contractor', 'lathe_machine_shop', null],         // a machine shop is a contractor, Sec. 2J.02(e)
        '31001' => ['manufacturer', null, null],                       // furniture
        '32110' => ['manufacturer', 'goldsmith', null],                // jewellery manufacture
        '36000' => ['manufacturer', null, self::BRANCH_ESSENTIALS],    // water refilling — processed, but water is not enumerated
        '38110' => ['contractor', null, null],                         // waste collection is a service

        // ── Construction, Sec. 2J.02(e) ───────────────────────────────────
        // Permit category null throughout: the Code prices general building
        // contractors by CAB class (D ₱1,100 to AA ₱6,600) and we do not know
        // the applicant's class. See the doc — a question BPLO may want asked.
        '41000' => ['contractor', null, null],
        '43210' => ['contractor', null, null],
        '43220' => ['contractor', null, null],
        '43300' => ['contractor', null, null],

        // ── Motor trade ───────────────────────────────────────────────────
        '45201' => ['contractor', 'motor_repair_similar_shop', null],  // "motor repair and painting shops"
        '45301' => ['retailer', null, null],                           // parts and accessories
        '45401' => ['retailer', null, null],                           // sale AND repair; the sale is the trade

        // ── Wholesale, Sec. 2J.02(b) ──────────────────────────────────────
        // The fine category is 'wholesaler' (₱5,000, General Merchandise) for
        // all of them; the Code does not subdivide wholesale by commodity.
        '46100' => ['wholesaler', null, null],                          // commission agent — indentor vs business agent, unresolved
        '46301' => ['wholesaler', 'wholesaler', null],                  // rice, corn, grains → essential
        '46302' => ['wholesaler', 'wholesaler', null],                  // fruit & vegetables → essential
        '46303' => ['wholesaler', 'wholesaler', null],                  // meat, poultry, seafood → essential
        '46309' => ['wholesaler', 'wholesaler', self::BRANCH_ESSENTIALS], // food is essential, tobacco is not
        '46410' => ['wholesaler', 'wholesaler', null],                  // textiles, clothing, footwear
        '46491' => ['wholesaler', 'wholesaler', null],                  // appliances, furniture
        '46520' => ['wholesaler', 'wholesaler', null],                  // electronics, telecoms
        '46630' => ['wholesaler', 'wholesaler', self::BRANCH_ESSENTIALS], // cement is essential; the rest of hardware is not
        '46691' => ['wholesaler', 'wholesaler', self::BRANCH_ESSENTIALS], // medicine is essential; industrial chemicals are not
        '46900' => ['wholesaler', 'wholesaler', self::BRANCH_ESSENTIALS], // non-specialised, so genuinely mixed

        // ── Retail, Sec. 2J.02(d) ─────────────────────────────────────────
        // No fine category exists for retail stores — that is item 64's job,
        // priced by office area, and is correct rather than missing.
        '47111' => ['retailer', null, self::BRANCH_ESSENTIALS],        // sari-sari: rice and soap beside cigarettes
        '47112' => ['retailer', null, self::BRANCH_ESSENTIALS],        // grocery / mini-mart
        '47190' => ['retailer', null, self::BRANCH_ESSENTIALS],        // department store
        '47211' => ['retailer', null, null],                           // rice, corn, grains → essential
        '47212' => ['retailer', null, null],                           // fruit & vegetables → essential
        '47213' => ['retailer', null, null],                           // meat → essential
        '47214' => ['retailer', null, null],                           // fish & seafood → essential
        '47219' => ['retailer', null, null],                           // other food products → essential
        '47220' => ['retailer', null, null],                           // beverages — not enumerated
        '47230' => ['retailer', null, null],                           // tobacco — not enumerated
        '47300' => ['retailer', 'gas_station', null],                   // priced by number of pumps, which we do not collect
        '47411' => ['retailer', null, null],                           // computers
        '47412' => ['retailer', 'cellphone_dealer', null],              // "dealer in cellphones, prepaid cards"
        '47420' => ['retailer', null, null],                           // audio & video
        '47510' => ['retailer', null, null],                           // textiles
        '47521' => ['retailer', null, self::BRANCH_ESSENTIALS],        // cement sits in a hardware store
        '47522' => ['retailer', null, null],                           // paints, glass, plumbing
        '47591' => ['retailer', null, null],                           // furniture
        '47592' => ['retailer', null, null],                           // appliances
        '47610' => ['retailer', null, self::BRANCH_ESSENTIALS],        // school supplies ARE enumerated; books are not
        '47640' => ['retailer', null, null],                           // sporting goods
        '47650' => ['retailer', null, null],                           // games & toys
        '47711' => ['retailer', null, null],                           // clothing
        '47712' => ['retailer', null, null],                           // footwear & leather
        '47721' => ['retailer', null, null],                           // pharmacy → essential (medicine)
        '47722' => ['retailer', null, self::BRANCH_ESSENTIALS],        // medical goods: medicine yes, orthopaedic devices unclear
        '47723' => ['retailer', null, null],                           // cosmetics
        '47730' => ['retailer', null, null],                           // jewellery & watches
        '47733' => ['retailer', null, null],                           // feeds, fertilisers, farm inputs → essential
        '47741' => ['retailer', null, null],                           // ukay-ukay
        '47760' => ['retailer', null, null],                           // flowers, plants, pets — plant_seller vs pets, unresolved
        '47810' => ['retailer', null, null],                           // food via stalls → essential
        '47820' => ['retailer', null, null],                           // textiles & footwear via stalls
        '47912' => ['retailer', null, self::BRANCH_ESSENTIALS],        // an online store sells anything
        '47990' => ['retailer', null, self::BRANCH_ESSENTIALS],        // direct selling

        // ── Transport, Sec. 2K.05 ─────────────────────────────────────────
        '49221' => ['puv_operator', null, null],                        // jeepney, UV express, tricycle
        '49230' => ['puv_operator', null, null],                        // trucking — cargo trucks are ₱6,000/unit
        '52101' => ['contractor', 'warehouse_bodega', null],            // priced by area
        '52290' => ['contractor', 'logistic_service', null],            // freight forwarding
        '53100' => ['contractor', null, null],                          // postal & courier

        // ── Accommodation, Sec. 2J.02(e) ──────────────────────────────────
        // The Code prices hotels by accredited class — Economy ₱2,200,
        // Standard ₱6,600, First Class ₱8,800, De Luxe ₱11,000 — and we do
        // not know it. Null rather than a guess across a ₱8,800 spread.
        '55101' => ['contractor', null, null],                          // hotels & resorts
        '55102' => ['contractor', null, null],                          // apartelle ₱2,200 vs pension house ₱1,100
        '55103' => ['contractor', null, null],                          // motels & lodging houses
        '55900' => ['lessor', 'boarding_house', null],                  // a boarding house is Sec. 2J.02(h)(4)

        // ── Food service, Sec. 2J.02(h)(1) and (2) ────────────────────────
        // 56101 is the case that proved the defect, and its permit category
        // is null for the same reason the defect mattered: PSIC lumps
        // "Restaurants AND carinderia" into one code while the Code charges
        // ₱550 for a carinderia and ₱5,500 for a restaurant with multiple
        // meal offerings. A 10x spread is not something to guess at.
        '56101' => ['restaurant', null, null],
        '56102' => ['restaurant', null, null],                          // franchised ₱3,300 vs non-franchised ₱2,200
        '56103' => ['restaurant', null, null],                          // stands, kiosks, food carts
        '56210' => ['restaurant', 'independent_caterer', null],
        '56290' => ['restaurant', null, null],                          // canteen & institutional catering
        '56301' => ['restaurant', 'cafe_cafeteria', null],              // coffee shop
        '56302' => ['bar_nightclub', null, null],                       // ₱4,400-₱16,500 by VIP rooms and live entertainment

        // ── Information, Sec. 2E.01 / 2F.03 ───────────────────────────────
        '58130' => ['printing_publication', null, null],                // newspaper publishing
        '59140' => ['amusement_place', 'movie_house', null],
        '61100' => ['franchise_holder', null, null],                    // telecoms hold a legislative franchise
        '62010' => ['contractor', null, null],
        '62090' => ['contractor', null, null],
        '63110' => ['contractor', null, null],

        // ── Finance, Sec. 2J.02(f) ────────────────────────────────────────
        '64920' => ['bank', 'financial_institution', null],             // lending investor, pawnshop
        '64990' => ['bank', 'financial_institution', null],             // money remittance
        '65120' => ['bank', 'financial_institution', null],             // non-life insurance

        // ── Real estate, Sec. 2J.02(h)(3)-(5) ─────────────────────────────
        '68100' => ['lessor', null, null],                              // by doors, storeys or area — four bracket rules, unresolved
        '68200' => ['real_estate_dealer', 'real_estate_dealer', null],  // brokerage

        // ── Professional and business services, Sec. 2J.02(e) ─────────────
        '69100' => ['contractor', null, null],                          // legal
        '69200' => ['contractor', null, null],                          // accounting
        '70200' => ['contractor', null, null],                          // management consultancy
        '71100' => ['contractor', null, null],                          // architectural & engineering
        '73100' => ['contractor', null, null],                          // advertising
        '74200' => ['contractor', null, null],                          // ordinary ₱550 vs sophisticated ₱2,200, unresolved
        '75000' => ['contractor', 'veterinary_clinic', null],           // clinic ₱1,100, not animal hospital ₱3,300
        '77100' => ['contractor', null, null],                          // rent-a-car: lessor of movables, not of real estate
        '77290' => ['contractor', null, null],                          // renting household goods
        '78100' => ['contractor', 'recruitment_service', null],
        '79110' => ['contractor', null, null],                          // travel agency is not a tour guide
        '80100' => ['contractor', null, null],                          // security — manpower service, unresolved
        '81210' => ['contractor', 'janitorial_manpower_service', null],
        '82200' => ['contractor', null, null],                          // call centres
        '82990' => ['contractor', null, null],                          // other business support

        // ── Education and health, Sec. 2J.02(e) ───────────────────────────
        '85100' => ['contractor', 'learning_institute', null],
        '85490' => ['contractor', 'learning_institute', null],          // review, tutorial, driving schools
        '86100' => ['contractor', null, null],                          // no hospital category in Sec. 3A.03
        '86201' => ['contractor', null, null],                          // medical & dental clinic
        '86901' => ['contractor', 'medical_dental_lab', null],

        // ── Amusement, Sec. 2J.02(h)(7) ───────────────────────────────────
        '92000' => ['amusement_place', null, null],                     // lotto outlet
        '93110' => ['amusement_place', 'sports_recreational_facility', null],
        '93290' => ['amusement_place', null, null],                     // billiard vs videoke vs internet cafe, priced differently

        // ── Repair, Sec. 2J.02(e) ─────────────────────────────────────────
        '95110' => ['contractor', null, null],                          // computers
        '95210' => ['contractor', null, null],                          // consumer electronics
        '95220' => ['contractor', null, null],                          // household appliances
        '95230' => ['contractor', null, null],                          // footwear & leather
        '95290' => ['contractor', null, null],                          // other goods

        // ── Personal services, Sec. 2J.02(e) ──────────────────────────────
        '96110' => ['contractor', 'barber_shop', null],                 // priced per tonsorial seat
        '96120' => ['contractor', null, null],                          // no beauty parlour category; massage rules are not it
        '96200' => ['contractor', 'motor_repair_similar_shop', null],   // that rule names "ordinary laundry shops"
        '96301' => ['contractor', 'funeral_independent', null],         // ₱5,500; a memorial park is ₱11,000
        '96990' => ['contractor', 'other_independent_contractor', null],
    ];

    /**
     * Codes whose commodity is essential outright, so no question is asked.
     *
     * Kept as a list rather than a fourth column because it is a property of
     * the goods and not of the classification: these are the codes whose PSIC
     * title names something on the Sec. 143(c) enumeration and nothing else.
     */
    public const ESSENTIAL_OUTRIGHT = [
        '10300', '10500', '10611', '10711', '10740', '10800',   // food & feed manufacture
        '46301', '46302', '46303',                               // wholesale of grains, produce, meat
        '47211', '47212', '47213', '47214', '47219',             // retail of grains, produce, meat, fish, food
        '47721', '47733', '47810',                               // pharmacy, farm inputs, food stalls
    ];

    /**
     * The tax class for a line of business, after the essentials answer.
     *
     * `$essentials` is the applicant's own declaration and only ever moves an
     * ordinary class to its half-rate counterpart — it cannot invent a class,
     * and it is ignored for a code that is essential outright or that has no
     * half-rate form (a contractor has no cheaper twin).
     */
    public static function taxClass(string $psic, bool $essentials = false): ?string
    {
        $row = self::FOR_PSIC[$psic] ?? null;
        if ($row === null || $row[0] === null) {
            return null;
        }

        $class = $row[0];

        if (in_array($psic, self::ESSENTIAL_OUTRIGHT, true) || ($essentials && $row[2] === self::BRANCH_ESSENTIALS)) {
            return self::ESSENTIAL_OF[$class] ?? $class;
        }

        return $class;
    }

    /** The Sec. 3A.03 fine category, or null for item 64's catch-all. */
    public static function permitCategory(string $psic): ?string
    {
        return self::FOR_PSIC[$psic][1] ?? null;
    }

    /** The follow-up this code needs, or null when nothing is asked. */
    public static function branch(string $psic): ?string
    {
        return self::FOR_PSIC[$psic][2] ?? null;
    }

    /** True when the code classifies itself and the applicant is asked nothing. */
    public static function isDerivable(string $psic): bool
    {
        $row = self::FOR_PSIC[$psic] ?? null;

        return $row !== null && $row[0] !== null;
    }
}

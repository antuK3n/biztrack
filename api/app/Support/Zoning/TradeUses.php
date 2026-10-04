<?php

namespace App\Support\Zoning;

/**
 * What City Ordinance No. 24-2018 calls each trade on the register's PSIC
 * list: the use-list lines that ARE the trade, and the ones that MAY be.
 *
 * ── Why a table, and not word overlap ──────────────────────────────────────
 *
 * The matcher used to accept one shared word. An independent audit (3 October
 * 2026) ran every register code through it and found a gasoline station, an
 * auto-repair shop and a rent-a-car reported as allowed in Maximum R-2
 * because they share "station" or "vehicles" with "Water refilling Station,
 * with parking space for delivery vehicles"; trucking matched "Small scale
 * eatery" on the word "road"; a bar matched the Institutional zone's "Places
 * of worship". Each of those was reported as allowed. Two texts sharing a word
 * is not two texts naming the same activity.
 *
 * The register's list is closed (134 codes and an "Other" row, ReferenceSeeder and
 * a later migration), so every code
 * is read here once, by hand, against the use lists in zone-uses.json and the
 * ordinance's definitions (Art. III §1, Annex A):
 *
 *  - `is`: a use line containing one of these phrases names this trade. The
 *    note under the map says "Allowed here".
 *  - `maybe`: a line that is this trade only at some scale, for some goods,
 *    or as an instance of a "like:" list (Art. III §2(a): "and the like"
 *    takes in similar uses, and similarity is CPDO's to judge). The note says
 *    it may be on the list — never allowed.
 *  - `def`: the rule in docs/zoning-ordinance/rules.json whose definition
 *    decided the reading.
 *
 * A code not in this table (one added to the register later) is never listed:
 * its nearest line is offered as a possibility, under Art. III §1 (terms the
 * ordinance does not define take their national-code meaning) — see
 * ZoningConformance::lookup. Nor is it ever refused at a pin: PinZone refuses
 * only a trade this table has read, and passes a `maybe`.
 *
 * Phrases are lowercase substrings of a use line. A phrase starting `=` must
 * be the whole of the line's last segment ("Commercial housing like: Hotel"
 * is "hotel"), so "=offices" is the C-1 line "Offices" and never the
 * "offices of physicians" inside the home-occupation clause.
 *
 * Lines a business can never be are not matched at all, whatever a phrase
 * says: "All uses allowed in …" pointers, customary accessory uses (R-1:
 * "shall not include any activity conducted for monetary gain"), family
 * recreation "for the exclusive use of the members of the family", and the
 * home-occupation and home-industry clauses, which are a question of the
 * trade rather than a line it can be (Ordinance::HOME_OCCUPATION).
 *
 * ── The readings that needed an answer are gone ────────────────────────────
 *
 * Several codes used to be narrowed by the applicant's answers to the zoning
 * checklist or by the words of their own description: a tailor or a garment
 * factory, a driving school or a tutorial service, a dry cleaner's solvents, a
 * hotel's in-room kitchens, what a lessor leases, a junk shop or a car wash
 * filed under "Other". The checklist that asked those questions was removed on
 * 5 October 2026 (Ken), so those readings (`DECIDED`, `DESCRIBED` and the
 * definitions quoted beside them) went with it; they are in this file's
 * history. Unanswered, each such code was always read by its row below, every
 * candidate line a possibility, and that is how it is read now.
 */
final class TradeUses
{
    /**
     * @var array<string, array{is?: list<string>, maybe?: list<string>, def?: string}>
     */
    public const USES = [
        // "Other (not listed)" (ReferenceSeeder::OTHER_PSIC_CODE): the trade is
        // in the applicant's own words, which only CPDO can read against the
        // lists. Matched to nothing, so it is reported as not on the list.
        '00000' => [],

        // ── Manufacturing (PSIC 10-33) ────────────────────────────────────
        '10300' => ['maybe' => ['quick freezing and cold packaging for fruits and vegetables', 'repacking of food products']],
        '10500' => ['is' => ['dairies and creameries'], 'maybe' => ['manufacturing of ice cream']],
        '10611' => ['is' => ['corn mill/rice mill']],
        '10711' => ['is' => ['bakeshop and bakery goods store', 'bakery, cake, pastry and delicatessen', 'other bakery products not elsewhere', 'other bakery production not elsewhere'],
            'maybe' => ['biscuit factory', 'doughnut and hopia factory']],
        '10740' => ['is' => ['other noodles']],
        '10799' => ['is' => ['ice plants and cold storage', 'manufacture of ice, ice blocks'], 'maybe' => ['manufacture of food products n.e.c.']],
        '10800' => ['is' => ['production prepared feeds for animals'], 'maybe' => ['unprepared animal feeds']],
        '11040' => [],
        // Builders' carpentry: doors, windows and mill work; a carpentry
        // shop taking custom jobs may be C-2's furniture-shop service line.
        '16220' => ['is' => ['manufacture of doors, windows and sashes', 'miscellaneous fabricated mill work'],
            'maybe' => ['woodworking establishments', 'furniture shops service operation']],
        // 14100, 15200 (and 47522, 85490, 95220 below) cover more than one
        // listed use, and nothing says which: every candidate is only "maybe".
        '14100' => ['maybe' => ['tailoring and dressmaking', 'dressmaking and tailoring', 'garments factory', 'garment and undergarment factories', 'miscellaneous wearing apparel']],
        '15200' => ['maybe' => ['manufacture of shoes except', 'manufacture of slipper and sandal', 'footwear parts except', 'rubber shoes and slippers', 'manufacture of plastic footwear', 'wooden shoes, shoe lace']],
        '17020' => ['is' => ['containers and boxes of paper and paper boards', 'wood and cardboard box factories']],
        '18120' => ['is' => ['printing, publication and graphics', 'engraving, photo developing and printing', 'printing/typesetting, copiers', 'printing, publishing and allied']],
        '20230' => ['maybe' => ['miscellaneous chemical products', 'perfumes, cosmetics and other toilet preparations', 'waxes and polishing preparations']],
        '22200' => ['is' => ['other fabricated plastic products', 'manufacture of plastic furniture', 'manufacture of plastic footwear']],
        '23950' => ['is' => ['structural concrete products']],
        '25920' => ['is' => ['machine shop/welding shop', 'machine shop service operation', '=welding shops'],
            'maybe' => ['stamped coated and engraved metal', 'sheet metal works']],
        '31001' => ['is' => ['manufacture of wood furniture', 'manufacture of rattan furniture', 'household metal furniture', 'office, store and restaurant metal furniture', 'miscellaneous furniture and fixture', 'manufacture of plastic furniture'],
            'maybe' => ['furniture shops service operation', 'manufacture of house furnishing']],
        '32110' => [],

        // ── Water, waste, construction (36-43) ────────────────────────────
        '36000' => ['is' => ['water refilling station', '=water stations'], 'maybe' => ['pumping plants']],
        // Collection is a hauling service; a waste-management facility is
        // where it ends up. Either may be how CPDO reads a collector.
        '38110' => ['maybe' => ['liquid and solid waste management facilities', 'hauling services and garage terminals']],
        '41000' => ['maybe' => ['=offices']],
        '43210' => ['maybe' => ['=offices']],
        '43220' => ['maybe' => ['=offices']],
        '43300' => ['maybe' => ['=offices']],

        // ── Vehicles (45) ──────────────────────────────────────────────────
        '45201' => ['is' => ['auto repair, tire', 'motor vehicles and accessory repair']],
        '45301' => ['is' => ['accessory and spare parts shops']],
        '45401' => ['is' => ['motor vehicles and accessory repair'], 'maybe' => ['car display and dealer stores', 'auto sales and rentals']],

        // ── Wholesale (46): "Wholesale stores", C-2 and General Commercial ─
        '46100' => ['maybe' => ['=wholesale stores', '=offices']],
        '46301' => ['is' => ['=wholesale stores']],
        '46302' => ['is' => ['=wholesale stores']],
        '46303' => ['is' => ['=wholesale stores']],
        '46309' => ['is' => ['=wholesale stores']],
        '46410' => ['is' => ['=wholesale stores']],
        '46491' => ['is' => ['=wholesale stores']],
        '46520' => ['is' => ['=wholesale stores']],
        '46630' => ['is' => ['=wholesale stores', 'construction supply stores/depots', '=lumber/hardware', 'gravel and sand stores', 'gravel, sand and chb stores']],
        '46691' => ['is' => ['=wholesale stores']],
        '46900' => ['is' => ['=wholesale stores']],

        // ── Retail (47) ────────────────────────────────────────────────────
        '47111' => ['is' => ['=sari-sari store'], 'maybe' => ['=convenience stores']],
        '47112' => ['is' => ['=groceries', '=convenience stores'], 'maybe' => ['=supermarkets', '=sari-sari store']],
        '47190' => ['is' => ['department store'], 'maybe' => ['shopping center', '=general merchandise'], 'def' => 'A-24'],
        '47211' => ['is' => ['other related small scale stores'], 'maybe' => ['=groceries', 'wet and dry markets', '=convenience stores']],
        '47212' => ['is' => ['=fruit stand', '=fruit stands'], 'maybe' => ['wet and dry markets', '=groceries']],
        '47213' => ['is' => ['frozen foods like meat'], 'maybe' => ['wet and dry markets']],
        '47214' => ['is' => ['frozen foods like meat'], 'maybe' => ['wet and dry markets']],
        // Small shops with no line of their own are Maximum R-2's "Other
        // related small scale stores", which it allows on the Zoning
        // Administrator's conditions.
        //
        // "Dry goods" here is food; the ordinance's "Dry goods" sits with
        // haberdashery and knitted wear, which is cloth. Same words, different
        // trade: not matched to it.
        '47219' => ['is' => ['other related small scale stores'], 'maybe' => ['=groceries', '=convenience stores', 'delicatessen']],
        '47220' => ['is' => ['other related small scale stores'], 'maybe' => ['liquor and wine stores', '=groceries', '=convenience stores']],
        '47230' => ['is' => ['other related small scale stores'], 'maybe' => ['=convenience stores', '=sari-sari store']],
        '47300' => ['is' => ['gasoline filling stations'], 'def' => 'A-41'],
        '47411' => ['is' => ['consumer electronics such as']],
        '47412' => ['is' => ['consumer electronics such as']],
        '47420' => ['is' => ['consumer electronics such as', '=home appliance stores']],
        '47510' => ['is' => ['dry goods, haberdasheries']],
        '47521' => ['is' => ['=lumber/hardware', 'construction supply stores/depots', 'gravel and sand stores', 'gravel, sand and chb stores']],
        // Glass and plumbing for building, not the household glassware the
        // lists name beside kitchen wares.
        '47522' => ['maybe' => ['paint stores without bulk handling', 'paint stores with bulk handling', '=lumber/hardware', 'construction supply stores/depots', 'glassware']],
        '47591' => ['is' => ['other related small scale stores'], 'maybe' => ['household equipment and appliances', 'product showroom/display store', '=general merchandise']],
        '47592' => ['is' => ['=home appliance stores', 'consumer electronics such as', 'household equipment and appliances']],
        // Books and newspapers are a bookstore's; "School supplies" may take
        // in a stationer, not a bookseller, so it is only a possibility.
        '47610' => ['is' => ['bookstores and office supply'], 'maybe' => ['=school supplies', '=art supplies', 'art supplies and novelties']],
        '47640' => ['is' => ['sporting goods, dry goods']],
        '47650' => ['is' => ['other related small scale stores'], 'maybe' => ['souvenir and novelty', 'art supplies and novelties', '=general merchandise']],
        '47711' => ['is' => ['ready and knitted wear']],
        '47712' => ['is' => ['other related small scale stores'], 'maybe' => ['dry goods, haberdasheries', '=general merchandise']],
        '47721' => ['is' => ['=drug stores', '=drugstores']],
        '47722' => ['is' => ['other related small scale stores'], 'maybe' => ['=drug stores', '=drugstores']],
        '47723' => ['is' => ['wellness products, beauty products']],
        '47730' => ['is' => ['=jewelry shops']],
        '47733' => ['is' => ['other related small scale stores'], 'maybe' => ['gardens and landscaping supply']],
        '47741' => ['is' => ['other related small scale stores'], 'maybe' => ['ready and knitted wear', 'curio or antique']],
        // A plant nursery grows plants; a shop selling them is a flower shop.
        '47760' => ['is' => ['=flower shops', 'pet shop'], 'maybe' => ['=plant nurseries', '=plant nursery']],
        '47810' => ['is' => ['wet and dry markets'], 'maybe' => ['=fruit stand', '=fruit stands'], 'def' => 'A-86'],
        '47820' => ['is' => ['wet and dry markets'], 'def' => 'A-86'],
        '47912' => ['maybe' => ['=offices']],
        '47990' => ['maybe' => ['=offices']],

        // ── Transport and storage (49-53) ──────────────────────────────────
        '49221' => ['is' => ['transportation terminals/garage'],
            'maybe' => ['tricycle/pedicab terminals', 'bus terminals', 'bus and railway depots', 'other types of transportation complexes'], 'def' => 'A-18'],
        '49230' => ['is' => ['hauling services and garage terminals', 'trucking garage'], 'maybe' => ['=motor pool']],
        '52101' => ['is' => ['warehouse/storage facility', 'warehouses where highly combustible', 'warehouse for pollutive'], 'def' => 'III-1-WAREHOUSE'],
        '52290' => ['maybe' => ['=offices']],
        '53100' => ['is' => ['courier services']],

        // ── Accommodation and food (55-56) ─────────────────────────────────
        // Annex A 44: a hotel has no cooking in its rooms; with it, it is a
        // hotel apartment (item 45), which is 55102's line.
        '55101' => ['is' => ['=hotels', '=hotel', 'resort complexes'], 'def' => 'A-44'],
        '55102' => ['is' => ['pension house', 'hotel apartments or apartels', '=apartel']],
        '55103' => ['is' => ['=motel'], 'maybe' => ['=boarding houses', '=boarding house'], 'def' => 'A-60'],
        '55900' => ['is' => ['=dormitories', '=dormitory', '=boarding houses', '=boarding house'], 'def' => 'A-27'],
        '56101' => ['is' => ['restaurants and other eateries', 'small scale eatery, carinderia'], 'def' => 'A-76'],
        '56102' => ['is' => ['restaurants and other eateries'], 'def' => 'A-76'],
        '56103' => ['is' => ['take home kiosk'], 'maybe' => ['restaurants and other eateries', 'food parks']],
        '56210' => ['is' => ['=catering services']],
        '56290' => ['is' => ['=catering services']],
        '56301' => ['is' => ['restaurants and other eateries'], 'def' => 'A-76'],
        '56302' => ['is' => ['bars, sing-along lounges'], 'def' => 'A-5'],

        // ── Information, finance, real estate, professions (58-75) ──────────
        '58130' => ['is' => ['printing, publication and graphics', 'printing, publishing and allied']],
        '59140' => ['is' => ['movie house/theater']],
        '61100' => ['maybe' => ['telecommunication facilities', '=offices', 'radio and television stations']],
        '62010' => ['is' => ['=offices']],
        '62090' => ['is' => ['=offices']],
        '63110' => ['is' => ['=offices'], 'maybe' => ['business process outsourcing']],
        '64920' => ['is' => ['=money lending', '=pawnshops']],
        '64990' => ['is' => ['=bayad centers'], 'maybe' => ['=foreign exchange', '=banks']],
        '65120' => ['is' => ['=insurance']],
        // A lessor's line depends on what it leases — a house (Residential-1),
        // an apartment building (Art. III §1: three or more families), stalls
        // or parking — and nothing asks which. See PENDING.
        '68100' => [],
        '68200' => ['is' => ['=offices']],
        '69100' => ['is' => ['=offices']],
        '69200' => ['is' => ['=offices']],
        '70200' => ['is' => ['=offices']],
        '71100' => ['is' => ['=offices']],
        '73100' => ['is' => ['=offices'], 'maybe' => ['signboard and streamer painting']],
        '74200' => ['is' => ['photo and portrait', '=photo shops', 'photo/video, lights & sounds']],
        '75000' => ['maybe' => ['medical, dental and similar clinic', 'medical, dental, and similar clinics']],

        // ── Rental, support and services (77-82) ───────────────────────────
        '77100' => ['is' => ['auto sales and rentals']],
        '77290' => [],
        '78100' => ['is' => ['=offices']],
        '79110' => ['is' => ['=travel agencies']],
        '80100' => ['is' => ['=security agencies']],
        '81210' => ['is' => ['=janitorial services']],
        '82200' => ['is' => ['business process outsourcing']],
        '82990' => ['maybe' => ['=offices', 'printing/typesetting, copiers and duplicating']],

        // ── Education and health (85-86) ───────────────────────────────────
        '85100' => ['is' => ['nursery/elementary school']],
        '85490' => ['maybe' => ['=tutorial services', '=tutorial centers', '=driving school', 'vocational school', 'vocational/technical school', '=dance schools', '=schools for self-defense', '=speech clinics', 'training centers']],
        '86100' => ['is' => ['general hospitals, medical centers']],
        '86201' => ['is' => ['medical, dental and similar clinic', 'medical, dental, and similar clinics', 'specialty hospitals, medical, dental', 'clinic, nursing and convalescing home']],
        '86901' => ['maybe' => ['medical, dental and similar clinic', 'medical, dental, and similar clinics', 'general hospitals, medical centers']],

        // ── Recreation (92-93) ─────────────────────────────────────────────
        '92000' => ['is' => ['lotto terminals']],
        // The Parks zone's gyms are "open air or outdoor sports activities and
        // support facilities"; an indoor gym only may be one of them.
        '93110' => ['is' => ['=gym', '=gymnasium'], 'maybe' => ['low rise stadia, gyms']],
        '93290' => ['is' => ['billiard hall', 'internet cafe and cyber stations', 'bars, sing-along lounges'], 'maybe' => ['other sports and recreational establishment']],

        // ── Repair and personal services (95-96) ───────────────────────────
        '95110' => ['is' => ['cameras, computers'], 'maybe' => ['renovation and repair of office machinery']],
        '95210' => ['is' => ['cameras, computers'], 'maybe' => ['furniture and appliances repair']],
        // Maximum R-2 lists these repair shops "on neighborhood scale";
        // Commercial-1 and General Commercial without the condition.
        '95220' => ['is' => ['=house furniture and appliances repair shops'], 'maybe' => ['repair shops on neighborhood scale']],
        '95230' => ['is' => ['shoe shine/repair', 'repair shops for watches, bags']],
        '95290' => ['maybe' => ['=watch repair shops', 'bicycle repair', 'repair shops for watches, bags']],
        '96110' => ['is' => ['barbershop', 'barber shop']],
        '96120' => ['is' => ['beauty parlor', 'wellness facilities such as sauna, spa']],
        // Annex A 28: dry cleaning with flammable solvents is I-2's "Dry
        // cleaning plants using flammable liquids", not a laundry.
        '96200' => ['is' => ['laundries and laundromats', '=laundries'], 'def' => 'A-48'],
        '96301' => ['is' => ['funeral parlors']],
        '96990' => [],
    ];

    /**
     * Trades the lists cannot place at all without knowing more than the code
     * says, with the neutral reason. The headline under the map stays
     * undetermined for them (ZoningConformance::forBarangay), and PinZone never
     * refuses them: their row above names no line.
     *
     * @var array<string, string>
     */
    public const PENDING = [
        '68100' => 'Which zones list a lessor depends on what it leases: a house or duplex (Residential-1), an apartment building for three or more families (Basic Residential-2 and the zones above it), stalls or commercial space, or parking.',
    ];

    /**
     * The table's reading of `$code`.
     *
     * `curated` is false for a code the table has not read; `pending` is the
     * reason for a trade it cannot place (PENDING), else null.
     *
     * @return array{curated: bool, is: list<string>, maybe: list<string>, pending: ?string}
     */
    public static function for(string $code): array
    {
        if (! array_key_exists($code, self::USES)) {
            return ['curated' => false, 'is' => [], 'maybe' => [], 'pending' => null];
        }
        $spec = self::USES[$code];

        return [
            'curated' => true,
            'is' => $spec['is'] ?? [],
            'maybe' => $spec['maybe'] ?? [],
            'pending' => self::PENDING[$code] ?? null,
        ];
    }
}

<?php

namespace App\Support\DefenseAccounts;

use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\MalabonGeo;
use App\Support\Zoning\PinZone;
use App\Support\ZoningConformance;

/**
 * Who the owners are: names, businesses, places, numbers.
 *
 * ── Deterministic, so a second run asks for the same people ─────────────────
 *
 * Everything here is derived from the owner's position in the list — the
 * scenario and the number within it — never from a random draw. That is what
 * makes the generator idempotent in the way Ken asked: a second run computes
 * the same e-mail addresses, finds them already registered and skips them,
 * instead of minting a fresh set of strangers beside the first.
 *
 * ── Like real people and real shops ─────────────────────────────────────────
 *
 * Ken, 5 October 2026: "none of these is to be dummy data" — every account
 * signs in and every filing went through the rules. And 6 October: they must
 * also LOOK like Malabon's register — natural Filipino names, shops named
 * the way shops there are ("Aling Nena's Sari-Sari Store", "R. Castillo Auto
 * Repair", "Kusina ni Lola Cora"), nothing that reads as generated.
 *
 * So the given names are a pool of forty per gender with the nickname a
 * neighbour would use (Corazon → Cora, Ricardo → Carding), and each owner
 * takes one name from it once: the pool is walked with a stride that has no
 * factor in common with its size, so no name repeats inside a run of eighty
 * and neighbours in the list are not neighbours in the alphabet. Each trade
 * has six ways a shop of that kind is named; an owner takes the next one no
 * shop in the run has, sign or trade name.
 *
 * The streets are Malabon's own: named roads from OpenStreetMap (6 October
 * 2026), placed in barangays by the same outlines the map draws
 * (`streets.json`). The shop's street is the named road nearest its pin, so
 * the address and the pin agree; the owner's home is on a street of their
 * home barangay.
 *
 * The DTI numbers, TINs and mobile numbers are generated (the mobiles in the
 * 0999 000 0001 range so they are recognisable as such: "generate numbers
 * since anything won't be sent to them").
 */
final class Roster
{
    /**
     * The sixteen scenarios, in the order the PDF lists them, with the one
     * phrase the PDF prints for the state each account is left in.
     */
    public const SCENARIOS = [
        'draft' => ['Draft', 'Draft saved, not submitted'],
        'for-approval' => ['For approval', 'Submitted, waiting on BPLO'],
        'returned' => ['Returned', 'Returned by BPLO for a correction'],
        'awaiting-payment' => ['Awaiting payment', 'Approved by BPLO, bill unpaid'],
        'offices-reviewing' => ['Offices reviewing', 'Paid; clearances with the offices'],
        'approved' => ['Approved', 'Every permit issued'],
        'rejected' => ['Rejected', 'Rejected by BPLO'],
        'expired' => ['Expired', 'Business Permit expired 31 Dec 2025, not renewed'],
        'renewal' => ['Late renewal', 'Expired; late renewal waiting on BPLO'],
        'amendment-review' => ['Amendment under review', 'Amendment with BPLO for review'],
        'amendment-approved' => ['Amendment approved', 'Amendment approved, permit reissued'],
        'suspended' => ['Suspended', 'Business Permit suspended: a clearance refused'],
        'revoked' => ['Revoked', "Mayor's Permit revoked by BPLO"],
        'blacklisted' => ['Blacklisted', 'Owner blacklisted by the super admin'],
        'open-requirement' => ['Open requirement', 'Office requirement waiting on the owner'],
        'messages' => ['Messages', 'With a BPLO officer; messages exchanged'],
    ];

    /** Given name => the name the street calls them by. */
    private const MEN = [
        'Ricardo' => 'Carding', 'Eduardo' => 'Eddie', 'Rogelio' => 'Roger', 'Danilo' => 'Danny', 'Jose Mari' => 'Jom',
        'Mark Anthony' => 'Mac', 'John Paul' => 'JP', 'Rodel' => 'Dels', 'Arnel' => 'Arnel', 'Noel' => 'Noel',
        'Reynaldo' => 'Rey', 'Alfredo' => 'Fred', 'Benjamin' => 'Benjie', 'Ernesto' => 'Ernie', 'Romeo' => 'Romy',
        'Antonio' => 'Tonyo', 'Christian' => 'Ian', 'Raymond' => 'Ray', 'Joel' => 'Joel', 'Dennis' => 'Dennis',
        'Edgardo' => 'Ed', 'Manuel' => 'Maning', 'Vicente' => 'Enteng', 'Rolando' => 'Lando', 'Wilfredo' => 'Willy',
        'Gerardo' => 'Gerry', 'Jayson' => 'Jay', 'Michael' => 'Mike', 'Rommel' => 'Mel', 'Nestor' => 'Nestor',
        'Feliciano' => 'Ciano', 'Aurelio' => 'Rely', 'Bienvenido' => 'Ben', 'Teodoro' => 'Teddy', 'Lorenzo' => 'Enzo',
        'Pablo' => 'Pabling', 'Isidro' => 'Sidro', 'Jerome' => 'Jerome', 'Ramon' => 'Mon', 'Florencio' => 'Ensyo',
    ];

    private const WOMEN = [
        'Nenita' => 'Nena', 'Corazon' => 'Cora', 'Rosario' => 'Rosing', 'Teresita' => 'Tess', 'Erlinda' => 'Linda',
        'Josefina' => 'Pina', 'Remedios' => 'Medy', 'Carmelita' => 'Mely', 'Leonora' => 'Nora', 'Gloria' => 'Glo',
        'Evangeline' => 'Vangie', 'Marilou' => 'Malou', 'Jocelyn' => 'Joy', 'Cristina' => 'Tina', 'Maria Lourdes' => 'Lulu',
        'Analyn' => 'Ana', 'Rowena' => 'Weng', 'Kristine' => 'Tin', 'Mary Grace' => 'Grace', 'Liza' => 'Liza',
        'Aileen' => 'Aileen', 'Rachelle' => 'Chelle', 'Divina' => 'Vina', 'Imelda' => 'Melda', 'Mylene' => 'Mylene',
        'Charmaine' => 'Cha', 'Jennifer' => 'Jen', 'Sheila' => 'Sheila', 'Lorna' => 'Lorna', 'Precious' => 'Precious',
        'Angelica' => 'Gel', 'Rhea' => 'Rhea', 'Maricel' => 'Cel', 'Normita' => 'Norma', 'Concepcion' => 'Connie',
        'Felicidad' => 'Fely', 'Milagros' => 'Mila', 'Estrella' => 'Esting', 'Rebecca' => 'Becky', 'Luzviminda' => 'Luz',
    ];

    private const SURNAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Villanueva', 'Ramos', 'Aquino', 'Castillo',
        'Gonzales', 'Navarro', 'Torres', 'Flores', 'Domingo', 'Mercado', 'Pascual', 'Soriano', 'Valdez', 'Manalo',
        'Salazar', 'Fernandez', 'Lopez', 'Rivera', 'Aguilar', 'Cruz', 'Del Rosario', 'Ocampo', 'Tolentino', 'Javier',
        'Galang', 'Mangahas', 'Sison', 'Dizon', 'Lacson', 'Pangilinan', 'Yap', 'Tan', 'Robles', 'Concepcion',
        'Evangelista', 'Samson', 'De Guzman', 'Panganiban', 'Bernardo', 'Marquez', 'Austria', 'Vergara', 'Mallari', 'Ignacio',
        'Lim', 'Sarmiento', 'Cabrera', 'Estrada', 'Morales', 'Santiago', 'Fajardo', 'Alcantara', 'Padilla', 'Tiongson',
    ];

    /**
     * The trades, by PSIC code: six ways a Malabon shop of that kind is
     * named, the name an incorporated one is registered under, and which of
     * the five other permits BPLO ticks for it. BPLO decides those ticks with
     * no rule behind them (client, 5 October 2026: "BPLO decides, no rules");
     * these are what a clerk would plausibly tick — the health office for
     * anything selling food or touching people, fire for every premises, the
     * environment office for anything with waste water or smoke, the building
     * official for a shop with a structure of its own.
     *
     * In the names: {S} surname, {N} nickname, {I} initials ("JM", or "R."),
     * {B} barangay, {A} Aling/Mang, {T} Tita/Tito, {L} Lola/Lolo, {K} Ate/Kuya.
     *
     * @var array<string, array{0: list<string>, 1: string, 2: list<string>}>
     */
    private const TRADES = [
        '47111' => [["{A} {N}'s Sari-Sari Store", '{S} Sari-Sari Store', 'Tindahan ni {A} {N}', '{B} Variety Store', '{I} {S} General Merchandise', "{N}'s Mini Mart"],
            '{S} Consumer Goods Trading Corp.', ['ZONING', 'SANITARY', 'FSIC']],
        '56101' => [["{T} {N}'s Carinderia", 'Kusina ni {L} {N}', 'Pancit Malabon ni {A} {N}', "{S}'s Lutong Bahay", '{B} Panciteria', "{N}'s Eatery"],
            '{S} Food Services Inc.', ['ZONING', 'SANITARY', 'FSIC', 'CEC']],
        '10711' => [['Golden Crust Bakeshop', '{S} Bakery', 'Panaderia ni {A} {N}', "{N}'s Bake House", 'Bagong Luto Bakeshop', '{B} Pandesal Bakery'],
            '{S} Bakeries Inc.', ['ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY']],
        '47521' => [['{I} {S} Hardware & Construction Supply', '{S} Builders Supply', '{B} Hardware', 'Matibay Construction Supply', "{N}'s Hardware & Electrical", '{S} Lumber & Hardware'],
            '{S} Construction Supply Corp.', ['ZONING', 'FSIC', 'OCCUPANCY']],
        '96200' => [['Sparkle Laundry Hub', "{N}'s Laundry Shop", 'Labada ni {A} {N}', 'Fresh & Fold Laundry', '{B} Wash & Dry', '{S} Laundromat'],
            '{S} Laundry Services Inc.', ['ZONING', 'FSIC', 'CEC']],
        '36000' => [['Malabon Aqua Refilling Station', '{S} Purified Water Station', 'Agua {N} Water Refilling', '{B} Water Refilling Station', 'Crystal Drop Water Station', '{I} {S} Water Station'],
            '{S} Water Corporation', ['ZONING', 'SANITARY', 'FSIC', 'CEC']],
        '47721' => [['{S} Pharmacy', 'Botika ni {A} {N}', '{B} Drugstore', '{I} {S} Drug Store', 'Mabuhay Botika', "{N}'s Family Pharmacy"],
            '{S} Pharma Distributors Inc.', ['ZONING', 'SANITARY', 'FSIC']],
        '96120' => [["{N}'s Beauty Salon", 'Ganda ni {N} Salon', '{S} Hair & Nail Studio', 'Beauty Corner by {N}', '{B} Beauty Parlor', 'Bella {N} Salon'],
            '{S} Beauty Services Inc.', ['ZONING', 'SANITARY', 'FSIC']],
        '45201' => [['{I} {S} Auto Repair', "{K} {N}'s Vulcanizing Shop", '{B} Vulcanizing & Auto Supply', '{S} Motor Works', "{N}'s Tire & Vulcanizing", 'Tibay Vulcanizing'],
            '{S} Automotive Services Inc.', ['ZONING', 'FSIC', 'CEC']],
        '96110' => [["{N}'s Barbershop", 'Gupitan ni {A} {N}', '{S} Barber Shop', 'Classic Cuts Barbershop', '{B} Barbershop', "{K} {N}'s Barbershop"],
            '{S} Grooming Services Inc.', ['ZONING', 'SANITARY', 'FSIC']],
        '47211' => [['{B} Rice Dealer', '{S} Bigasan', 'Bigasan ni {A} {N}', '{I} {S} Rice Trading', 'Bagong Ani Rice Center', "{N}'s Rice Store"],
            '{S} Grains Trading Corp.', ['ZONING', 'SANITARY', 'FSIC']],
        '47214' => [['{S} Fish Dealer', '{B} Fresh Fish', 'Isdaan ni {A} {N}', 'Malabon Bay Seafood Trading', "{N}'s Fish & Seafood", '{I} {S} Fish Trading'],
            '{S} Seafood Trading Corp.', ['ZONING', 'SANITARY', 'FSIC', 'CEC']],
        '18120' => [['{I} {S} Printing Services', '{S} Printing Press', "{N}'s Print & Copy", 'Print Hub {B}', 'Tinta {N} Printing', '{B} Xerox & Printing'],
            '{S} Printing Corp.', ['ZONING', 'FSIC', 'CEC']],
        '47412' => [["{N}'s Cellphone & Accessories", '{B} Mobile Shop', '{I} {S} Gadget Center', 'Konek Cellphone Repair & Accessories', '{S} Cellphone Center', 'Load & Gadgets ni {N}'],
            '{S} Mobile Trading Inc.', ['ZONING', 'FSIC']],
        '47610' => [['{S} School & Office Supplies', "{N}'s Bookstore", '{B} School Supplies', "{T} {N}'s Gift & School Supplies", 'Lapis at Papel School Supplies', '{I} {S} Office Supplies'],
            '{S} Book & Supplies Corp.', ['ZONING', 'FSIC']],
        '95110' => [['{I} {S} Computer Services', "{N}'s PC Clinic", '{S} Computer Repair', '{B} Laptop & PC Repair', 'TechFix {B}', '{N} Computer Shop'],
            '{S} IT Solutions Inc.', ['ZONING', 'FSIC']],
    ];

    /**
     * Zones a shop is pinned in, best first. A pin whose zone LISTS the trade
     * is what turns the wizard's zoning box green; commercial zones first so
     * the map looks like a high street, and never a zone where nobody builds.
     */
    private const ZONE_PREFERENCE = ['C-1', 'C-2', 'CBD', 'GENERAL-COMMERCIAL', 'C-3', 'R-2-BASIC+R-2-MAX', 'R-2-BASIC', 'R-3-BASIC', 'R-3-MAX', 'CMP', 'I-1', 'I-2'];

    /** @var array<string, array<string, list<array{0: float, 1: float}>>> grid points by barangay, then zone */
    private array $zonePoints = [];

    /** @var array<string, int> how many shops of each trade this run has named */
    private array $named = [];

    /**
     * Every shop and trade name given out so far. Two shops of one name would
     * be one shop to a clerk reading the queue, so an owner takes the next of
     * the trade's names nobody has; past the sixth, the barangay is added.
     *
     * @var list<string>
     */
    private array $taken = [];

    /** @var array<string, array<string, list<array{0: float, 1: float}>>>|null barangay => street => points */
    private static ?array $streets = null;

    /**
     * Every owner for this run, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function owners(string $emailBase, int $perScenario): array
    {
        [$local, $domain] = explode('@', strtolower(trim($emailBase)), 2);
        $local = explode('+', $local)[0];

        $this->named = [];
        $this->taken = [];
        $out = [];
        $index = 0;
        foreach (array_keys(self::SCENARIOS) as $s => $slug) {
            for ($n = 1; $n <= $perScenario; $n++) {
                $index++;
                $owner = $this->owner($index, $s, $slug, $n, "{$local}+bt-{$slug}-{$n}@{$domain}");
                $out[] = $owner;
            }
        }

        return $out;
    }

    /**
     * One owner and their business. `index` runs 1… across the whole run and
     * gives the mobile number; `s` is the scenario's position.
     *
     * @return array<string, mixed>
     */
    private function owner(int $index, int $s, string $slug, int $n, string $email): array
    {
        $female = $index % 2 === 0;
        $pool = $female ? self::WOMEN : self::MEN;
        $givens = array_keys($pool);
        // 17 and 40 share no factor, so the first forty of a gender each get
        // a different given name; 23 and 60 likewise for the surnames.
        $given = $givens[(intdiv($index - 1, 2) * 17 + 5) % count($givens)];
        $nick = $pool[$given];
        $surname = self::SURNAMES[($index * 23 + 7) % count(self::SURNAMES)];
        // A stride of its own for the middle name, nudged every thirteenth
        // owner, so a surname and middle name never pair the same way twice.
        $middle = self::SURNAMES[($index * 7 + intdiv($index, 13) * 3 + 11) % count(self::SURNAMES)];
        if ($middle === $surname) {
            $middle = self::SURNAMES[($index * 7 + intdiv($index, 13) * 3 + 12) % count(self::SURNAMES)];
        }

        $barangays = Barangay::orderBy('id')->pluck('name', 'id')->all();
        $barangayIds = array_keys($barangays);
        $homeBarangay = $barangays[$barangayIds[($index * 5) % count($barangayIds)]];
        $barangayId = $barangayIds[($index * 8 + $s) % count($barangayIds)];
        $barangay = $barangays[$barangayId];

        $codes = array_keys(self::TRADES);
        $trade = (string) $codes[($index * 7) % count($codes)];
        // A trade the barangay's zones do not list would file under an amber
        // note; the next trade in the table is tried instead.
        $pin = null;
        foreach ($codes as $ignored) {
            if (($pin = $this->pin($barangayId, $trade, $index)) !== null) {
                break;
            }
            $trade = self::nextTrade($trade);
        }
        [$names, $corporateName, $ticks] = self::TRADES[$trade];

        $use = $this->named[$trade] = ($this->named[$trade] ?? -1) + 1;
        // "Precious' Bookstore", not "Precious's".
        $fill = fn (string $template) => preg_replace("/s's\\b/", "s'", strtr($template, [
            '{S}' => $surname,
            '{N}' => $nick,
            '{I}' => str_contains($given, ' ') ? implode('', array_map(fn ($w) => $w[0], explode(' ', $given))) : $given[0].'.',
            '{B}' => $barangay,
            '{A}' => $female ? 'Aling' : 'Mang',
            '{T}' => $female ? 'Tita' : 'Tito',
            '{L}' => $female ? 'Lola' : 'Lolo',
            '{K}' => $female ? 'Ate' : 'Kuya',
        ]));

        // A shop of four in five is a sole proprietorship registered with DTI;
        // the rest are incorporated with SEC under the family name and trade
        // under the name on the sign. A sole proprietor's DTI name usually IS
        // the sign, so only one in three gives a separate trade name.
        $corporate = $index % 5 === 0;
        $free = function (int $from) use ($names, $fill): ?string {
            for ($k = 0; $k < count($names); $k++) {
                $candidate = $fill($names[($from + $k) % count($names)]);
                if (! in_array($candidate, $this->taken, true)) {
                    return $candidate;
                }
            }

            return null;
        };
        $sign = $free($use) ?? $fill($names[$use % count($names)]).' - '.$barangay;
        $this->taken[] = $sign;
        $name = $corporate ? $fill($corporateName) : $sign;
        $tradeName = $corporate ? $sign : ($index % 3 === 0 ? $free($use + 3) : null);
        $this->taken[] = $name;
        if ($tradeName !== null) {
            $this->taken[] = $tradeName;
        }

        $seed = crc32($email);
        $street = self::streetNear($barangay, $pin);

        return [
            'index' => $index,
            'scenario' => $slug,
            'n' => $n,
            'email' => $email,
            'first_name' => $given,
            'nickname' => $nick,
            'middle_name' => $middle,
            'last_name' => $surname,
            'gender' => $female ? 'F' : 'M',
            'mobile' => sprintf('0999000%04d', $index),
            'home_street' => (3 + ($seed % 180)).' '.self::streetIn($homeBarangay, $index),
            'home_barangay' => $homeBarangay,
            'business' => [
                'name' => $name,
                'trade_name' => $tradeName,
                // What an amendment renames the shop to: another of its
                // trade's names, as when an owner rebrands.
                'new_trade_name' => $fill($names[($use + 2) % count($names)]),
                'psic' => $trade,
                'ticks' => $ticks,
                'registration_type' => $corporate ? 'corporation' : 'sole_proprietorship',
                'registration_number' => $corporate
                    ? sprintf('CS2019%05d', 10000 + $seed % 89999)
                    : sprintf('%07d', 3000000 + $seed % 6999999),
                'tin' => sprintf('%03d-%03d-%03d-000', 100 + $seed % 800, ($seed >> 8) % 1000, ($seed >> 16) % 1000),
                'barangay_id' => $barangayId,
                'barangay' => $barangay,
                'pin' => $pin,
                'house_no' => (string) (1 + ($seed >> 4) % 250),
                'street' => $street,
                'capital' => [50000, 80000, 120000, 150000, 250000, 400000][$index % 6],
                'floor_area' => [12, 18, 24, 30, 45, 60][($index + 1) % 6],
                'employees' => 1 + $index % 4,
            ],
        ];
    }

    /**
     * The named road nearest the pin, among those inside the barangay — the
     * street a shop at that spot gives as its address.
     *
     * @param  array{0: float, 1: float}|null  $pin
     */
    public static function streetNear(string $barangay, ?array $pin): string
    {
        $streets = self::streets()[$barangay] ?? [];
        if ($pin === null || $streets === []) {
            return self::streetIn($barangay, 0);
        }

        $best = null;
        $bestDistance = INF;
        foreach ($streets as $name => $points) {
            foreach ($points as [$lat, $lng]) {
                $d = ($lat - $pin[0]) ** 2 + (($lng - $pin[1]) * cos(deg2rad($lat))) ** 2;
                if ($d < $bestDistance) {
                    [$best, $bestDistance] = [$name, $d];
                }
            }
        }

        return (string) $best;
    }

    /** One of the barangay's streets, picked by `$k`. */
    public static function streetIn(string $barangay, int $k): string
    {
        $names = array_keys(self::streets()[$barangay] ?? []);

        return $names === [] ? 'M.H. Del Pilar St.' : $names[($k * 7) % count($names)];
    }

    /** @return array<string, array<string, list<array{0: float, 1: float}>>> */
    private static function streets(): array
    {
        return self::$streets ??= json_decode((string) file_get_contents(__DIR__.'/streets.json'), true);
    }

    /**
     * A map pin inside the business's barangay whose zone lists its trade,
     * which is what the wizard's zoning box draws green. Null when none of
     * the barangay's traced zones lists it — the caller then tries another
     * trade rather than file under an amber note.
     *
     * Points are a grid over the barangay, grouped by the zone each falls in;
     * the owner's index picks among a zone's points so neighbours in the
     * list are not stacked on one spot.
     *
     * @return array{0: float, 1: float}|null [lat, lng]
     */
    public function pin(int $barangayId, string $psicCode, int $index): ?array
    {
        $barangay = Barangay::with('zoningClassifications')->find($barangayId);
        $psic = PsicCode::where('code', $psicCode)->first();
        if ($barangay === null || $psic === null) {
            return null;
        }

        $byZone = $this->zonesOf($barangay);
        foreach (self::ZONE_PREFERENCE as $zone) {
            $points = $byZone[$zone] ?? [];
            if ($points === []) {
                continue;
            }
            [$lat, $lng] = $points[$index % count($points)];
            $answer = ZoningConformance::forPin($barangay, $psic, $lat, $lng);
            if ($answer['verdict'] === 'listed'
                && PinZone::refusal($lat, $lng, $barangay, [$psic]) === null
                && MalabonGeo::pinProblem($lat, $lng, $barangay->name) === null) {
                return [$lat, $lng];
            }
        }

        return null;
    }

    /** @return array<string, list<array{0: float, 1: float}>> */
    private function zonesOf(Barangay $barangay): array
    {
        if (isset($this->zonePoints[$barangay->name])) {
            return $this->zonePoints[$barangay->name];
        }

        $shape = collect(MalabonGeo::data()['barangays'])->firstWhere('name', $barangay->name);
        $byZone = [];
        if ($shape !== null) {
            $ring = $shape['rings'][0];
            $lngs = array_column($ring, 0);
            $lats = array_column($ring, 1);
            $steps = 18;
            for ($i = 1; $i < $steps; $i++) {
                for ($j = 1; $j < $steps; $j++) {
                    $lng = round(min($lngs) + (max($lngs) - min($lngs)) * $i / $steps, 6);
                    $lat = round(min($lats) + (max($lats) - min($lats)) * $j / $steps, 6);
                    if (! MalabonGeo::inPolygon($lng, $lat, $shape['rings'])) {
                        continue;
                    }
                    $zone = PinZone::at($lat, $lng, $barangay);
                    if ($zone !== null) {
                        $byZone[implode('+', $zone['codes'])][] = [$lat, $lng];
                    }
                }
            }
        }

        return $this->zonePoints[$barangay->name] = $byZone;
    }

    /**
     * Another trade to try when the first one's zone list leaves no green
     * spot in the barangay — the next in the table, wrapping.
     */
    public static function nextTrade(string $code): string
    {
        $codes = array_keys(self::TRADES);
        $at = array_search($code, $codes, true);

        return (string) $codes[((int) $at + 1) % count($codes)];
    }
}

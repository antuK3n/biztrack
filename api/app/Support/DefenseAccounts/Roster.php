<?php

namespace App\Support\DefenseAccounts;

use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\MalabonGeo;
use App\Support\Zoning\PinZone;
use App\Support\ZoningConformance;

/**
 * Who the defense accounts are: names, businesses, places, numbers.
 *
 * ── Deterministic, so a second run asks for the same people ─────────────────
 *
 * Everything here is derived from the owner's position in the list — the
 * scenario and the number within it — never from a random draw. That is what
 * makes the generator idempotent in the way Ken asked: a second run computes
 * the same e-mail addresses, finds them already registered and skips them,
 * instead of minting a fresh set of strangers beside the first.
 *
 * ── Plausible, not real ─────────────────────────────────────────────────────
 *
 * Ken: "none of these is to be dummy data" — meaning every account must sign
 * in and every filing must have gone through the rules, not that the people
 * exist. The names are common Filipino names put together by position; the
 * DTI numbers, TINs and mobile numbers are generated (the mobiles in the
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

    private const MEN = [
        'Juan', 'Jose', 'Antonio', 'Ricardo', 'Eduardo', 'Ramon', 'Rogelio', 'Danilo', 'Ernesto', 'Romeo',
        'Mark Anthony', 'John Paul', 'Christian', 'Jerome', 'Rodel', 'Arnel', 'Noel', 'Reynaldo', 'Alfredo', 'Benjamin',
    ];

    private const WOMEN = [
        'Maria', 'Rosario', 'Teresita', 'Lourdes', 'Corazon', 'Erlinda', 'Marites', 'Josefina', 'Analyn', 'Rowena',
        'Kristine', 'Mary Grace', 'Jocelyn', 'Liza', 'Marilou', 'Evangeline', 'Cristina', 'Aileen', 'Rachelle', 'Divina',
    ];

    private const SURNAMES = [
        'Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Villanueva', 'Ramos', 'Aquino', 'Castillo',
        'Gonzales', 'Navarro', 'Torres', 'Flores', 'Domingo', 'Mercado', 'Pascual', 'Soriano', 'Valdez', 'Manalo',
        'Salazar', 'Fernandez', 'Lopez', 'Rivera', 'Aguilar', 'Cruz', 'Del Rosario', 'Ocampo', 'Tolentino', 'Javier',
        'Galang', 'Mangahas', 'Sison', 'Dizon', 'Lacson', 'Pangilinan', 'Yap', 'Tan', 'Robles', 'Concepcion',
        'Evangelista', 'Samson', 'De Guzman', 'Panganiban', 'Bernardo', 'Marquez', 'Austria', 'Vergara', 'Mallari', 'Ignacio',
        'Lim', 'Sarmiento', 'Cabrera', 'Estrada', 'Morales', 'Santiago', 'Fajardo', 'Alcantara', 'Padilla', 'Tiongson',
    ];

    private const STREETS = [
        'Gov. Pascual Ave.', 'M.H. Del Pilar St.', 'Gen. Luna St.', 'Rizal Ave. Ext.', 'Sanciangco St.',
        'P. Aquino Ave.', 'C. Arellano St.', 'Leono St.', 'F. Sevilla Blvd.', 'Hernandez St.',
        'Estrella St.', 'Mabini St.', 'Bonifacio St.', 'Burgos St.', 'Sampaguita St.',
        'Ilang-Ilang St.', 'Kapalaran St.', 'Pampano St.', 'Bangus St.', 'Dagat-Dagatan St.',
    ];

    /**
     * The trades, by PSIC code: how a Malabon shop of that kind is named, and
     * which of the five other permits BPLO ticks for it. BPLO decides those
     * ticks with no rule behind them (client, 5 October 2026: "BPLO decides,
     * no rules"); these are what a clerk would plausibly tick — the health
     * office for anything selling food or touching people, fire for every
     * premises, the environment office for anything with waste water or
     * smoke, the building official for a shop with a structure of its own.
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    private const TRADES = [
        '47111' => [['{S} Sari-Sari Store', 'Tindahan ni {A} {G}'], ['ZONING', 'SANITARY', 'FSIC']],
        '56101' => [['Kusina ni {G}', '{S} Carinderia'], ['ZONING', 'SANITARY', 'FSIC', 'CEC']],
        '10711' => [['Panaderia {S}', '{B} Bakeshop'], ['ZONING', 'SANITARY', 'FSIC', 'CEC', 'OCCUPANCY']],
        '47521' => [['{S} Hardware and Construction Supply', '{S} Builders Hardware'], ['ZONING', 'FSIC', 'OCCUPANCY']],
        '96200' => [['{S} Laundry Hub', 'Labada Express {B}'], ['ZONING', 'FSIC', 'CEC']],
        '36000' => [['Aqua {S} Water Refilling Station', '{B} Purified Water Station'], ['ZONING', 'SANITARY', 'FSIC', 'CEC']],
        '47721' => [['Botika {S}', '{S} Family Pharmacy'], ['ZONING', 'SANITARY', 'FSIC']],
        '96120' => [['{G} Beauty Salon', '{S} Beauty Lounge'], ['ZONING', 'SANITARY', 'FSIC']],
        '45201' => [['{S} Vulcanizing and Auto Repair', '{S} Vulcanizing Shop'], ['ZONING', 'FSIC', 'CEC']],
        '96110' => [['{S} Barbershop', 'Gupit {G} Barber Shop'], ['ZONING', 'SANITARY', 'FSIC']],
        '47211' => [['Bigasan ni {G}', '{S} Rice Dealer'], ['ZONING', 'SANITARY', 'FSIC']],
        '47214' => [['{S} Fish Dealer', '{B} Isdaan ni {G}'], ['ZONING', 'SANITARY', 'FSIC', 'CEC']],
        '18120' => [['{S} Printing Services', '{G} Print and Copy Center'], ['ZONING', 'FSIC', 'CEC']],
        '47412' => [['{G} Cellphone and Accessories', '{S} Mobile Shop'], ['ZONING', 'FSIC']],
        '47610' => [['{S} School and Office Supplies', '{G} Bookstore'], ['ZONING', 'FSIC']],
        '95110' => [['{S} Computer Repair Services', '{G} PC Clinic'], ['ZONING', 'FSIC']],
    ];

    /**
     * Zones a shop is pinned in, best first. A pin whose zone LISTS the trade
     * is what turns the wizard's zoning box green; commercial zones first so
     * the map looks like a high street, and never a zone where nobody builds.
     */
    private const ZONE_PREFERENCE = ['C-1', 'C-2', 'CBD', 'GENERAL-COMMERCIAL', 'C-3', 'R-2-BASIC+R-2-MAX', 'R-2-BASIC', 'R-3-BASIC', 'R-3-MAX', 'CMP', 'I-1', 'I-2'];

    /** @var array<string, array<string, list<array{0: float, 1: float}>>> grid points by barangay, then zone */
    private array $zonePoints = [];

    /**
     * Every owner for this run, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function owners(string $emailBase, int $perScenario): array
    {
        [$local, $domain] = explode('@', strtolower(trim($emailBase)), 2);
        $local = explode('+', $local)[0];

        $out = [];
        $index = 0;
        foreach (array_keys(self::SCENARIOS) as $s => $slug) {
            for ($n = 1; $n <= $perScenario; $n++) {
                $index++;
                $owner = $this->owner($index, $s, $slug, $n, "{$local}+bt-{$slug}-{$n}@{$domain}");
                // Two shops of one name would be one shop to a clerk reading
                // the queue; the second is named for its barangay, as a
                // branch would be.
                if (in_array($owner['business']['name'], array_map(fn ($o) => $o['business']['name'], $out), true)) {
                    $owner['business']['name'] .= ' - '.$owner['business']['barangay'];
                }
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
        // Halved because the genders alternate; with the surname's stride of
        // 13 over 60 no two owners in a run of 120 share a full name.
        $given = ($female ? self::WOMEN : self::MEN)[(intdiv($index, 2) * 7) % 20];
        $surname = self::SURNAMES[($index * 13) % count(self::SURNAMES)];
        $middle = self::SURNAMES[($index * 13 + 29) % count(self::SURNAMES)];

        $barangays = Barangay::orderBy('id')->pluck('name', 'id')->all();
        $barangayIds = array_keys($barangays);
        $homeBarangay = $barangays[$barangayIds[($index * 5) % count($barangayIds)]];
        $barangayId = $barangayIds[($index * 8 + $s) % count($barangayIds)];

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
        [$names, $ticks] = self::TRADES[$trade];

        // A shop of four in five is a sole proprietorship registered with DTI;
        // the rest are incorporated with SEC, under the family name.
        $corporate = $index % 5 === 0;
        $template = $corporate
            ? collect($names)->first(fn (string $t) => str_contains($t, '{S}')).' Inc.'
            : $names[$index % count($names)];

        $short = explode(' ', $given)[0];
        $name = strtr($template, [
            '{S}' => $surname,
            '{G}' => $short,
            '{A}' => $female ? 'Aling' : 'Mang',
            '{B}' => $barangays[$barangayId],
        ]);

        $seed = crc32($email);

        return [
            'index' => $index,
            'scenario' => $slug,
            'n' => $n,
            'email' => $email,
            'first_name' => $given,
            'middle_name' => $middle,
            'last_name' => $surname,
            'gender' => $female ? 'F' : 'M',
            'mobile' => sprintf('0999000%04d', $index),
            'home_street' => (10 + ($seed % 290)).' '.self::STREETS[($index * 3) % count(self::STREETS)],
            'home_barangay' => $homeBarangay,
            'business' => [
                'name' => $name,
                'psic' => $trade,
                'ticks' => $ticks,
                'registration_type' => $corporate ? 'corporation' : 'sole_proprietorship',
                'registration_number' => $corporate
                    ? sprintf('CS2019%05d', 10000 + $seed % 89999)
                    : sprintf('%07d', 3000000 + $seed % 6999999),
                'tin' => sprintf('%03d-%03d-%03d-000', 100 + $seed % 800, ($seed >> 8) % 1000, ($seed >> 16) % 1000),
                'barangay_id' => $barangayId,
                'barangay' => $barangays[$barangayId],
                'pin' => $pin,
                'house_no' => (string) (1 + ($seed >> 4) % 250),
                'street' => self::STREETS[($index * 7 + 3) % count(self::STREETS)],
                'capital' => [50000, 80000, 120000, 150000, 250000, 400000][$index % 6],
                'floor_area' => [12, 18, 24, 30, 45, 60][($index + 1) % 6],
                'employees' => 1 + $index % 4,
            ],
        ];
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

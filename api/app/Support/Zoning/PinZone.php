<?php

namespace App\Support\Zoning;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\MalabonGeo;
use App\Support\ZoningConformance;

/**
 * The zone under an owner's map pin, and whether their line of business is
 * clearly not allowed in it (Ken, 5 October 2026).
 *
 * ── Where the zone comes from ──────────────────────────────────────────────
 *
 * The polygons traced from CPDO's sheets that the wizard's map draws,
 * `web/public/zoning/<slug>.geojson` (scripts/trace-zoning-sheets.py), read
 * here from those same files rather than from a copy: the zone the map shows
 * under a pin and the zone this judges are then one answer. Point in polygon
 * on the chosen barangay's file. A pin in no traced zone, in two of them, or
 * no pin at all, and the zone is unknown.
 *
 * The tracing is approximate — the sheet's placement, the barangay outline it
 * is clipped to and the trace itself each round — which is the reason the rule
 * below refuses only what is clear, and passes everything else in silence.
 *
 * ── When a line of business is refused ─────────────────────────────────────
 *
 * Only when all of these hold:
 *
 *  - it is not one of the small neighbourhood shops Ken named, which pass in
 *    every zone (NEIGHBOURHOOD);
 *  - the zone at the pin is known, and its list (with every list it takes in,
 *    Ordinance::closure) names at least one use;
 *  - the trade has a row in TradeUses naming at least one line, `is` or
 *    `maybe` ("Other (not listed)", a lessor, a code nobody has read: pass);
 *  - none of those lines is on the zone's list;
 *  - and it is not a trade the ordinance lets a household run at home where
 *    the zone takes in Residential-1's list: Art. V §2.1's home occupation and
 *    home industry, by trade (Ordinance::homeBusiness), whatever the business
 *    turns out to be. A sari-sari store, a carinderia or a barber shop in a
 *    residential zone is never refused here; CPDO applies §2.1's conditions.
 *    Factories, filling stations, bars and funeral parlours pass on it too,
 *    and Ken kept it that way (5 October 2026).
 *
 * A "maybe" line is enough to pass: "and the like" is CPDO's to judge.
 *
 * ── Who asks ───────────────────────────────────────────────────────────────
 *
 * The wizard, as the pin and the line of business change (`GET zone-at-pin`,
 * which holds Next), the note under the map (ZoningConformance::forPin, which
 * says it), and ApplicationController::submit, so the API cannot be used
 * around it: a new filing always, an amendment that moves the business or
 * changes its line. All three say the same sentence (`refusal`).
 */
final class PinZone
{
    /** The traced files, where the browser fetches them from. */
    public const DIRECTORY = '../web/public/zoning';

    /**
     * Plain zone names, as the map's key prints them — a copy of PLAIN and
     * R2_EITHER in web/src/lib/zoningNames.ts, which PinZoneTest holds equal.
     * The refusal names the zone in the words the owner just read on the map.
     */
    public const PLAIN = [
        'R-1' => 'Homes',
        'R-2-BASIC' => 'Homes and apartments',
        'R-2-MAX' => 'Homes and small shops',
        'R-3-BASIC' => 'Homes, condos and hotels',
        'R-3-MAX' => 'Homes, condos and small shops',
        'CMP' => 'Socialized housing',
        'C-1' => 'Neighborhood shops and services',
        'C-2' => 'Larger shops, markets and services',
        'C-3' => 'Malls and large businesses',
        'GENERAL-COMMERCIAL' => 'Shops, services and trade',
        'CBD' => 'Main business district',
        'I-1' => 'Light industry',
        'I-2' => 'Industry',
        'EASEMENT' => 'Riverbank strip, no building',
        'MANGROVE' => 'Mangroves',
        'FISHPOND' => 'Fishponds',
        'PARKS' => 'Parks and recreation',
        'CEMETERY' => 'Cemeteries',
        'UTILITIES' => 'Utilities and terminals',
        'INSTITUTIONAL' => 'Government, schools and churches',
    ];

    /** The one traced area that is two classifications at once. */
    public const R2_EITHER = 'Homes and apartments, some small shops';

    /**
     * The small neighbourhood shops Ken said pass ANYWHERE, in every zone
     * (5 October 2026), as the register files them. Each code is wider than
     * the shop he named, and the rest of it passes too:
     *
     *  - 47111 sari-sari store; 47112 grocery or mini-mart
     *  - 56101 restaurants and carinderia; 56103 refreshment stands, kiosks
     *    and food carts (the small eateries)
     *  - 96110 barbershop and hairdressing; 96120 beauty parlour, salon, spa
     *  - 96200 laundry and dry-cleaning
     *  - 14100 wearing apparel, garments and tailoring (a garment factory too)
     *  - 10711 bakery products (bakeshop)
     *  - 93290 the internet café, filed with billiard halls and videoke
     */
    public const NEIGHBOURHOOD = ['47111', '47112', '56101', '56103', '96110', '96120', '96200', '14100', '10711', '93290'];

    /** @var array<string, list<array{codes: list<string>, name: string, polygons: list<list<list<array{0: float, 1: float}>>>}>> */
    private static array $files = [];

    /**
     * The zone the traced map puts `($lat, $lng)` in, inside `$barangay`'s
     * file: its codes (two only for the R-2 pair the sheet cannot split) and
     * the sheet's own name for it. Null when it is unknown.
     *
     * @return array{codes: list<string>, name: string}|null
     */
    public static function at(?float $lat, ?float $lng, ?Barangay $barangay): ?array
    {
        if ($lat === null || $lng === null || $barangay === null) {
            return null;
        }
        $hits = array_values(array_filter(
            self::zones($barangay->name),
            static function (array $zone) use ($lat, $lng): bool {
                foreach ($zone['polygons'] as $rings) {
                    if (MalabonGeo::inPolygon($lng, $lat, $rings)) {
                        return true;
                    }
                }

                return false;
            },
        ));

        // On a seam between two traced zones the pin says nothing about which.
        return count($hits) === 1 ? ['codes' => $hits[0]['codes'], 'name' => $hits[0]['name']] : null;
    }

    /** The name the map's key gives a zone; `$fallback` for a code it does not know. */
    public static function plainName(array $codes, string $fallback): string
    {
        sort($codes);
        if ($codes === ['R-2-BASIC', 'R-2-MAX']) {
            return self::R2_EITHER;
        }

        return count($codes) === 1 ? (self::PLAIN[$codes[0]] ?? $fallback) : $fallback;
    }

    /** Is `$psic` clearly not allowed in the zone made of `$codes`? See the class note. */
    public static function refuses(array $codes, PsicCode $psic): bool
    {
        if (in_array((string) $psic->code, self::NEIGHBOURHOOD, true)) {
            return false;
        }
        $row = TradeUses::USES[(string) $psic->code] ?? null;
        if ($row === null || (($row['is'] ?? []) === [] && ($row['maybe'] ?? []) === [])) {
            return false;
        }

        $uses = [];
        $takesInR1 = false;
        foreach ($codes as $code) {
            foreach (Ordinance::closure($code) as $source) {
                $uses = array_merge($uses, ZoningConformance::matchable($source));
                $takesInR1 = $takesInR1 || $source === 'R-1';
            }
        }
        if ($uses === []) {
            return false;
        }
        if ($takesInR1 && Ordinance::homeBusiness((string) $psic->code)) {
            return false;
        }

        return ZoningConformance::matchUse($psic, $uses) === null;
    }

    /**
     * The sentence the owner is stopped with, naming the first line of
     * business the zone at the pin clearly does not allow — or null.
     *
     * @param  iterable<PsicCode|null>  $lines
     */
    public static function refusal(?float $lat, ?float $lng, ?Barangay $barangay, iterable $lines): ?string
    {
        $zone = self::at($lat, $lng, $barangay);
        if ($zone === null) {
            return null;
        }
        foreach ($lines as $psic) {
            if ($psic !== null && self::refuses($zone['codes'], $psic)) {
                return "{$psic->title} isn't allowed in the ".self::plainName($zone['codes'], $zone['name'])
                    .' zone where your pin is. To petition this, visit the Business Permits and Licensing Office (BPLO) at Malabon City Hall.';
            }
        }

        return null;
    }

    /**
     * The refusal for a filing about to be submitted.
     *
     * A new filing is judged on its business's pin, barangay and lines. An
     * amendment only when it moves the business (a new pin) or changes its
     * line, and then on the place and line it asks for, with the register's
     * for the half it leaves alone. A renewal is not judged: it changes
     * neither.
     */
    public static function refusalFor(Application $application): ?string
    {
        $application->loadMissing(['business.address.barangay', 'business.lines.psicCode', 'requestedChanges']);
        $business = $application->business;
        $requested = $application->requestedChanges->whereNotNull('new_value')->pluck('new_value', 'field');
        $lines = $business?->lines->map(fn ($line) => $line->psicCode)->all() ?? [];

        if ($application->application_type === ApplicationType::Amendment) {
            if (! $requested->has('address_pin') && ! $requested->has('line_of_business')) {
                return null;
            }
            if ($requested->has('line_of_business')) {
                $lines = [PsicCode::find((int) $requested->get('line_of_business'))];
            }
        } elseif ($application->application_type !== ApplicationType::New) {
            return null;
        }
        [$lat, $lng, $barangay] = self::place($application);

        return self::refusal($lat, $lng, $barangay, $lines);
    }

    /**
     * The zone at a filing's pin, for CPDO's sheet.
     *
     * @return array{codes: list<string>, name: string}|null
     */
    public static function forApplication(Application $application): ?array
    {
        return self::at(...self::place($application));
    }

    /**
     * Where a filing is: the pin and barangay an amendment asks for, else the
     * business's own.
     *
     * @return array{0: ?float, 1: ?float, 2: ?Barangay}
     */
    private static function place(Application $application): array
    {
        $application->loadMissing(['business.address.barangay', 'requestedChanges']);
        $address = $application->business?->address;
        $requested = $application->application_type === ApplicationType::Amendment
            ? $application->requestedChanges->whereNotNull('new_value')->pluck('new_value', 'field')
            : collect();
        [$lat, $lng] = self::pin($requested->get('address_pin')) ?? [$address?->latitude, $address?->longitude];
        $barangay = $requested->has('address_barangay_id')
            ? Barangay::find((int) $requested->get('address_barangay_id'))
            : $address?->barangay;

        return [$lat, $lng, $barangay];
    }

    /** "14.66,120.95" → [14.66, 120.95]; anything else null. */
    private static function pin(mixed $value): ?array
    {
        $parts = is_string($value) ? explode(',', $value) : [];

        return count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))
            ? [(float) $parts[0], (float) $parts[1]]
            : null;
    }

    /**
     * One barangay's traced zones, read once per request. A barangay with no
     * file has no zones, so every pin in it is unknown.
     *
     * @return list<array{codes: list<string>, name: string, polygons: list<list<list<array{0: float, 1: float}>>>}>
     */
    private static function zones(string $barangay): array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', str_replace('ñ', 'n', mb_strtolower($barangay))), '-');
        if (array_key_exists($slug, self::$files)) {
            return self::$files[$slug];
        }
        $path = base_path(self::DIRECTORY."/{$slug}.geojson");
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        $zones = [];
        foreach (is_array($raw) ? (array) ($raw['features'] ?? []) : [] as $feature) {
            $geometry = $feature['geometry'] ?? [];
            $polygons = match ($geometry['type'] ?? null) {
                'MultiPolygon' => $geometry['coordinates'] ?? [],
                'Polygon' => [$geometry['coordinates'] ?? []],
                default => [],
            };
            $codes = array_values(array_map('strval', (array) ($feature['properties']['codes'] ?? [])));
            if ($codes === [] || $polygons === []) {
                continue;
            }
            $zones[] = [
                'codes' => $codes,
                'name' => (string) ($feature['properties']['name'] ?? implode(' or ', $codes)),
                'polygons' => $polygons,
            ];
        }

        return self::$files[$slug] = $zones;
    }
}

<?php

namespace App\Support;

/**
 * Which barangay a map pin is in, on the server (checklist Zoning 3).
 *
 * The wizard has refused a pin outside the chosen barangay since September,
 * but only in the browser — the API checked that the coordinates were numbers
 * and nothing else, so any caller that is not the wizard (an older tab, a
 * script, a draft saved from a build before the check) could store a business
 * whose pin and barangay contradict each other. CPDO then inspects one of the
 * two and the filing is wrong about the other.
 *
 * This is a port of `web/src/lib/malabonGeo.ts`, and it reads the same
 * polygons: `resources/geo/malabon-barangays.json` is generated from
 * `web/src/lib/malabonGeo.data.ts`, and MalabonGeoTest fails when the two
 * drift. Two copies of one boundary that disagree would be worse than either
 * alone — the browser would accept a pin the server refuses.
 *
 * It answers "roughly where is this", never "does this conform". CPDO decides.
 */
class MalabonGeo
{
    /** Metres per degree at Malabon's latitude (~14.66 N). Same figures as the web. */
    private const M_PER_DEG_LAT = 110574.0;

    private const M_PER_DEG_LNG = 111320.0 * 0.96744467; // cos(14.66°), as malabonGeo.ts computes it

    /*
     * How far outside its barangay a pin may sit before it is refused.
     *
     * 150 m, the browser's BARANGAY_TOLERANCE_M, and it must stay equal to it.
     * The request for this check suggested about 30 m. That would refuse pins
     * the browser has already accepted, and the applicant would meet a 422 on
     * Save for a pin the map told them was fine — with no way to know which of
     * the two to believe. It is also tighter than the data can support: the
     * stored boundaries are rounded to 0.001° of longitude (about 108 m here)
     * and simplified to edges averaging 230 m, so a true boundary can sit ~90 m
     * from the stored one. See the constant's note in malabonGeo.ts.
     */
    public const TOLERANCE_M = 150.0;

    /** @var array{outline: list<array{0: float, 1: float}>, barangays: list<array{name: string, psgc: string, rings: list<list<array{0: float, 1: float}>>}>}|null */
    private static ?array $data = null;

    /** @return array{outline: list<array{0: float, 1: float}>, barangays: list<array{name: string, psgc: string, rings: list<list<array{0: float, 1: float}>>}>} */
    public static function data(): array
    {
        if (self::$data === null) {
            $raw = json_decode((string) file_get_contents(resource_path('geo/malabon-barangays.json')), true);
            self::$data = [
                'outline' => $raw['outline'] ?? [],
                'barangays' => $raw['barangays'] ?? [],
            ];
        }

        return self::$data;
    }

    /** The barangay a pin falls in, or null if it falls in none of the polygons. */
    public static function barangayContaining(float $lat, float $lng): ?string
    {
        foreach (self::data()['barangays'] as $b) {
            if (self::inPolygon($lng, $lat, $b['rings'])) {
                return $b['name'];
            }
        }

        return null;
    }

    /**
     * Metres from a pin to the named barangay; 0 inside it; null when the
     * barangay has no polygon on file.
     */
    public static function metresFromBarangay(float $lat, float $lng, string $barangay): ?float
    {
        foreach (self::data()['barangays'] as $b) {
            if ($b['name'] !== $barangay) {
                continue;
            }
            if (self::inPolygon($lng, $lat, $b['rings'])) {
                return 0.0;
            }
            $best = INF;
            foreach ($b['rings'] as $ring) {
                for ($i = 0; $i < count($ring) - 1; $i++) {
                    $best = min($best, self::distanceToSegment($lng, $lat, $ring[$i], $ring[$i + 1]));
                }
            }

            return $best;
        }

        return null;
    }

    /**
     * Why this pin cannot be stored against this barangay, in words an
     * applicant can act on — or null when it can.
     *
     * An unknown barangay passes, as it does in the browser: the barangay
     * table is seeded data and the polygons are a shipped file, so one can
     * gain a row the other lacks, and refusing an applicant over our own
     * bookkeeping gap is the wrong failure. The pin is still reviewed by CPDO.
     */
    public static function pinProblem(float $lat, float $lng, string $barangay): ?string
    {
        $metres = self::metresFromBarangay($lat, $lng, $barangay);
        if ($metres === null || $metres <= self::TOLERANCE_M) {
            return null;
        }

        $actual = self::barangayContaining($lat, $lng);

        return $actual !== null
            ? "The pin is in {$actual}, but the barangay chosen is {$barangay}. Move the pin into {$barangay}, or change the barangay."
            : "The pin is outside {$barangay}. Move the pin into {$barangay}, or change the barangay.";
    }

    /**
     * Even-odd point in polygon, [lng, lat]. Public for PinZone, which asks
     * the same question of the traced zoning polygons.
     *
     * @param  list<list<array{0: float, 1: float}>>  $rings  Outer ring first, then holes.
     */
    public static function inPolygon(float $lng, float $lat, array $rings): bool
    {
        if ($rings === [] || ! self::inRing($lng, $lat, $rings[0])) {
            return false;
        }
        foreach (array_slice($rings, 1) as $hole) {
            if (self::inRing($lng, $lat, $hole)) {
                return false;
            }
        }

        return true;
    }

    /** Even-odd ray cast, [lng, lat]. */
    private static function inRing(float $lng, float $lat, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $lat) !== ($yj > $lat) && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** Metres from a point to a segment, in a local flat projection. */
    private static function distanceToSegment(float $lng, float $lat, array $a, array $b): float
    {
        $px = $lng * self::M_PER_DEG_LNG;
        $py = $lat * self::M_PER_DEG_LAT;
        $ax = $a[0] * self::M_PER_DEG_LNG;
        $ay = $a[1] * self::M_PER_DEG_LAT;
        $bx = $b[0] * self::M_PER_DEG_LNG;
        $by = $b[1] * self::M_PER_DEG_LAT;
        $dx = $bx - $ax;
        $dy = $by - $ay;
        if ($dx == 0.0 && $dy == 0.0) {
            return hypot($px - $ax, $py - $ay);
        }
        $t = max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / ($dx * $dx + $dy * $dy)));

        return hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
    }
}

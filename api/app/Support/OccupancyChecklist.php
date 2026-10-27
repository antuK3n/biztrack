<?php

namespace App\Support;

/**
 * Which "Verified & complied" lines of NBC Form B-13 a building actually
 * needs, and what its use is called [client, 28 October 2026: "Others
 * (Specify) it depends sa business ata to pati yung iba, make sure to check
 * and reflect nang maayos"].
 *
 * The form lists twelve disciplines and an "Others". A building is verified
 * only on the ones that apply to it; the rest are marked N/A. Which apply
 * follows from what the system knows of the building — its floor area, its
 * storeys, and what the business does there.
 *
 * ── The reading, discipline by discipline ──────────────────────────────────
 *
 * Every building with a building permit (PD 1096, Sec. 302) carries the
 * locational, line-and-grade, architectural, civil/structural, electrical,
 * sanitary and plumbing documents, and every business premises needs its
 * FSIC (RA 9514) — so those eight always apply. Accessibility (BP 344)
 * applies to buildings the public enters, which every business premises is.
 *
 * The other three, and "Others", turn on the building:
 *
 *   - MECHANICAL: mechanical works — a commercial kitchen's exhaust, ovens,
 *     lifts, central air. Food businesses always; any business from three
 *     storeys or 500 sq m.
 *   - ELECTRONICS: fire detection and alarm, CCTV, data and sound systems —
 *     from two storeys or 500 sq m.
 *   - INTERIOR DESIGN: a fit-out large enough to be designed — from 100 sq m.
 *   - OTHERS: a fire sprinkler system from 2,000 sq m or four storeys
 *     (mercantile thresholds of the Fire Code's IRR); an LPG installation
 *     for a food business; otherwise N/A.
 *
 * These are BizTrack's reading of the codes, set out here so the OBO can
 * correct a threshold in one place. Where the floor area or the storeys are
 * not known, a line that depends on them prints blank — unknown is not N/A.
 */
class OccupancyChecklist
{
    public const LINES = [
        'Line and Grade (Geodetic)', 'Architectural', 'Civil/Structural', 'Electrical', 'Mechanical',
        'Sanitary', 'Plumbing', 'Electronics', 'Interior Design', 'Accessibility',
    ];

    private const ALWAYS = [
        'Line and Grade (Geodetic)', 'Architectural', 'Civil/Structural', 'Electrical',
        'Sanitary', 'Plumbing', 'Accessibility',
    ];

    private const FOOD = ['food', 'bakery', 'bakeshop', 'carinderia', 'restaurant', 'eatery', 'canteen', 'kitchen', 'catering', 'cafe', 'coffee', 'lechon'];

    /**
     * @param  array<string, string|null>  $evaluators  line => the OBO evaluator's name on file
     * @return array{lines: array<string, string|null>, others: string|null, use: string|null}
     *   `lines`: line => evaluator name, 'N/A', or null (applies, nobody on file / not decidable)
     */
    public static function for(?float $areaSqm, ?int $storeys, ?string $lineOfBusiness, ?string $declaredUse, array $evaluators): array
    {
        $food = self::isFood($lineOfBusiness);
        $knownSize = $areaSqm !== null || $storeys !== null;
        $area = $areaSqm ?? 0.0;
        $floors = $storeys ?? 1;

        $applies = [
            'Mechanical' => $food || ($knownSize ? ($floors >= 3 || $area >= 500) : null),
            'Electronics' => $knownSize ? ($floors >= 2 || $area >= 500) : null,
            'Interior Design' => $areaSqm !== null ? $area >= 100 : null,
        ];

        $lines = [];
        foreach (self::LINES as $line) {
            $verdict = in_array($line, self::ALWAYS, true) ? true : ($applies[$line] ?? null);
            $lines[$line] = match ($verdict) {
                true => $evaluators[$line] ?? null,
                false => 'N/A',
                null => null,
            };
        }

        $sprinkler = $knownSize && ($area >= 2000 || $floors >= 4);
        $others = match (true) {
            $sprinkler && $food => 'Fire Sprinkler System; LPG Installation',
            $sprinkler => 'Fire Sprinkler System',
            $food => 'LPG Installation',
            $knownSize => 'N/A',
            default => null,
        };

        return [
            'lines' => $lines,
            'others' => $others,
            'use' => self::useOf($declaredUse, $lineOfBusiness, $food),
        ];
    }

    /**
     * The use or character of occupancy: what the applicant declared on the
     * OBO sheet when it says more than "commercial", otherwise read off the
     * line of business. Short, because the form gives it one line beside
     * the AREA box and a longer one wraps and pushes the signature off.
     */
    private static function useOf(?string $declared, ?string $lineOfBusiness, bool $food): ?string
    {
        $declared = trim((string) $declared);
        if ($declared !== '' && strcasecmp($declared, 'Commercial') !== 0) {
            return $declared;
        }

        $lob = strtolower((string) $lineOfBusiness);
        if ($lob === '') {
            return $declared !== '' ? $declared : null;
        }

        return match (true) {
            $food => 'Food Service',
            str_contains($lob, 'repair') || str_contains($lob, 'laundry') || str_contains($lob, 'salon') || str_contains($lob, 'service') => 'Service Shop',
            str_contains($lob, 'office') || str_contains($lob, 'bank') || str_contains($lob, 'consult') => 'Business Office',
            str_contains($lob, 'warehouse') || str_contains($lob, 'storage') => 'Warehouse',
            str_contains($lob, 'manufactur') || str_contains($lob, 'factory') => 'Industrial',
            default => 'Mercantile (Store)',
        };
    }

    private static function isFood(?string $lineOfBusiness): bool
    {
        $lob = strtolower((string) $lineOfBusiness);
        foreach (self::FOOD as $word) {
            if (str_contains($lob, $word)) {
                return true;
            }
        }

        return false;
    }
}

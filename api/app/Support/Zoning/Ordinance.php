<?php

namespace App\Support\Zoning;

use Illuminate\Support\Facades\Cache;

/**
 * What City Ordinance No. 24-2018 says, held as data the zoning code reads.
 *
 * ── Why this is code and not rows ───────────────────────────────────────────
 *
 * The 19 classifications and the per-barangay lists in `zoning_classifications`
 * are rows because they were read off CPDO's map SHEETS, which are proposals an
 * admin is expected to correct. Everything here is read off the ordinance's
 * TEXT, which changes only when the Sangguniang Panlungsod amends it (Art. IX
 * §22).
 *
 * Every constant names the article it came from. `docs/zoning-ordinance/
 * rules.json` holds the verbatim text and the page; this file holds only what
 * a computer needs to apply it.
 *
 * It used to hold much more — streets and their commercial strips, road
 * widening, geohazard soils, height limits, the special uses, what a trade's
 * vehicles are for — all of it read only by the rule-by-rule zoning checklist,
 * which Ken removed on 5 October 2026. They are in the history of this file if
 * the checklist ever comes back.
 */
final class Ordinance
{
    /**
     * `zoning_classifications.code` → the Art. V section enumerating its uses.
     *
     * Nineteen of the twenty base zones; §2.14 Easement Zone is functional (a
     * strip along every waterway) and appears on no sheet, so it has no code.
     */
    public const SECTION_FOR_CODE = [
        'R-1' => '2.1',
        'R-2-BASIC' => '2.2',
        'R-2-MAX' => '2.3',
        'R-3-BASIC' => '2.4',
        'R-3-MAX' => '2.5',
        'CMP' => '2.6',
        'C-1' => '2.7',
        'C-2' => '2.8',
        'C-3' => '2.9',
        'GENERAL-COMMERCIAL' => '2.10',
        'CBD' => '2.11',
        'I-1' => '2.12',
        'I-2' => '2.13',
        'MANGROVE' => '2.15',
        'FISHPOND' => '2.16',
        'PARKS' => '2.17',
        'CEMETERY' => '2.18',
        'UTILITIES' => '2.19',
        'INSTITUTIONAL' => '2.20',
    ];

    /** Plain names, for the applicant. The officer sees the code beside it. */
    public const ZONE_NAMES = [
        'R-1' => 'Residential-1',
        'R-2-BASIC' => 'Basic Residential-2',
        'R-2-MAX' => 'Maximum Residential-2',
        'R-3-BASIC' => 'Basic Residential-3',
        'R-3-MAX' => 'Maximum Residential-3',
        'CMP' => 'Socialized Housing',
        'C-1' => 'Commercial-1',
        'C-2' => 'Commercial-2',
        'C-3' => 'Commercial-3',
        'GENERAL-COMMERCIAL' => 'General Commercial',
        'CBD' => 'Central Business District',
        'I-1' => 'Industrial-1',
        'I-2' => 'Industrial-2',
        'MANGROVE' => 'Mangrove',
        'FISHPOND' => 'Fishpond',
        'PARKS' => 'Parks and Recreation',
        'CEMETERY' => 'Cemetery',
        'UTILITIES' => 'Utilities, Transportation and Services',
        'INSTITUTIONAL' => 'Institutional',
    ];

    /**
     * Which zones' uses each zone takes in, and the clause that says so.
     *
     * Read off each zone's first "All uses allowed in …" line, exactly as
     * written — including what it leaves out:
     *
     *  - Maximum R-3 (§2.5) and C-1 (§2.7) both name every residential zone
     *    EXCEPT Basic R-3. Nothing reaches Basic R-3's own list, and that is
     *    followed as written (questions-for-malabon C18).
     *  - C-2 (§2.8) names "R-1 and R-2 Zones", and no zone is called plain
     *    R-2. It does not matter: C-2 also takes in all of C-1, which already
     *    reaches Basic and Maximum R-2, so either reading gives the same set.
     *    The pointer is kept so the citation is the clause that says it.
     *  - Industrial zones inherit nothing. I-2 restates the I-1 warehouse line
     *    but does not take in I-1's list, and that is followed as written.
     *  - Socialized Housing (§2.6) delegates to BP 220 and is not a pointer to
     *    any zone here; General Commercial's "all Residential Zones" therefore
     *    stops at the five that have lists.
     */
    public const INHERITS = [
        'R-2-BASIC' => ['V-2.2-INH' => ['R-1']],
        'R-2-MAX' => ['V-2.3-INH' => ['R-1', 'R-2-BASIC']],
        'R-3-BASIC' => ['V-2.4-INH' => ['R-1', 'R-2-BASIC']],
        'R-3-MAX' => ['V-2.5-INH' => ['R-1', 'R-2-BASIC', 'R-2-MAX']],
        'C-1' => ['V-2.7-INH' => ['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-MAX']],
        'C-2' => ['V-2.8-INH' => ['C-1'], 'V-2.8-INH-R2' => ['R-1', 'R-2-BASIC', 'R-2-MAX']],
        'C-3' => ['V-2.9-INH' => ['C-1', 'C-2', 'R-2-MAX', 'R-3-MAX']],
        'GENERAL-COMMERCIAL' => ['V-2.10-INH' => ['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX']],
        'CBD' => ['V-2.11-INH' => ['C-1', 'C-2', 'C-3', 'GENERAL-COMMERCIAL']],
    ];

    /**
     * Every zone whose uses `$code` takes in, itself first.
     *
     * @return list<string>
     */
    public static function closure(string $code): array
    {
        $seen = [$code];
        $queue = [$code];
        while ($queue !== []) {
            $next = array_shift($queue);
            foreach (self::INHERITS[$next] ?? [] as $codes) {
                foreach ($codes as $c) {
                    if (! in_array($c, $seen, true)) {
                        $seen[] = $c;
                        $queue[] = $c;
                    }
                }
            }
        }

        return $seen;
    }

    /** The clause by which `$zone` reaches `$source`'s list, for the citation. */
    public static function inheritanceRule(string $zone, string $source): ?string
    {
        if ($zone === $source) {
            return null;
        }
        foreach (self::INHERITS[$zone] ?? [] as $rule => $codes) {
            if (in_array($source, $codes, true)) {
                return $rule;
            }
        }
        foreach (self::INHERITS[$zone] ?? [] as $rule => $codes) {
            foreach ($codes as $c) {
                if (in_array($source, self::closure($c), true)) {
                    return $rule;
                }
            }
        }

        return null;
    }

    /** Art. IV §5's headings, as printed, onto the codes the sheets use. */
    private const HEADING_TO_CODE = [
        'RESIDENTIAL -1 ZONE (R-1)' => 'R-1',
        'BASIC R-2 ZONE' => 'R-2-BASIC',
        'MAXIMUM R-2 ZONE' => 'R-2-MAX',
        'BASIC R-3 ZONE' => 'R-3-BASIC',
        'MAXIMUM R-3 ZONE' => 'R-3-MAX',
        'SOCIALIZED HOUSING ZONE' => 'CMP',
        'GENERAL COMMERCIAL ZONE' => 'GENERAL-COMMERCIAL',
        'COMMERCIAL -1 ZONE (C-1)' => 'C-1',
        'COMMERCIAL -2 ZONE (C-2)' => 'C-2',
        'COMMERCIAL -3 ZONE (C-3)' => 'C-3',
        'CENTRAL BUSINESS DISTRICT (CBD)' => 'CBD',
        'INDUSTRIAL - 1 ZONE (I-1)' => 'I-1',
        'INDUSTRIAL-2 ZONE (I-2)' => 'I-2',
        'INSTITUTIONAL ZONE' => 'INSTITUTIONAL',
        'FISHPOND ZONE' => 'FISHPOND',
        'MANGROVE ZONE' => 'MANGROVE',
        'CEMETERY ZONE' => 'CEMETERY',
        'PARKS AND RECREATION ZONE' => 'PARKS',
        'UTILITIES, TRANSPORTATION AND SERVICE ZONE' => 'UTILITIES',
    ];

    /**
     * The zones Art. IV §5's TEXT places in each barangay, keyed by a
     * normalised barangay name.
     *
     * This is the governing list. Art. IV §6 says so twice — "The textual
     * description of the zone boundaries shall prevail over that of the
     * Official Zoning Maps" (IV-6-g) and, where the map is inaccurate, "the
     * description of the zoning boundaries appended shall govern" (IV-6-l) —
     * and the two disagree in ways that matter: the text puts Basic R-2 in
     * Potrero alone where our reading of the sheets put it in twenty
     * barangays, and it puts industrial zones in Catmon and Maysilo that the
     * sheets' tracing missed (questions-for-malabon C11). Where they differ the
     * headline under the map goes by the text.
     *
     * Parks and Utilities are absent on purpose: §5 places them city-wide on
     * "all area occupied by existing" facilities rather than in any barangay,
     * so they come from the sheet (IV-5-PR-UTS).
     *
     * Read from `docs/zoning-ordinance/zone-boundaries.json`, which was checked
     * row by row against pages 20-35 of the PDF for this purpose.
     *
     * @return array<string, list<string>>
     */
    public static function textZones(): array
    {
        return Cache::remember('zoning.ordinance.text-zones', 3600, function (): array {
            $path = base_path('../docs/zoning-ordinance/zone-boundaries.json');
            $rows = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $code = self::HEADING_TO_CODE[$row['zone'] ?? ''] ?? null;
                $barangay = $row['barangay'] ?? null;
                if ($code === null || ! is_string($barangay) || $barangay === '') {
                    continue;
                }
                $key = self::key($barangay);
                $out[$key] ??= [];
                if (! in_array($code, $out[$key], true)) {
                    $out[$key][] = $code;
                }
            }

            return $out;
        });
    }

    /** "Bayan-bayanan", "Bayan-Bayanan" and "BAYAN BAYANAN" are one barangay. */
    public static function key(string $name): string
    {
        return (string) preg_replace('/[^a-zñ]/u', '', mb_strtolower($name));
    }

    /**
     * Art. V §2.1's home occupation names its trades: "the practice of one's
     * profession such as offices of physicians, surgeons, dentists,
     * architects, engineers, lawyers, and other professionals", and "home
     * business such as dressmaking, tailoring, baking, running a sari-sari
     * store/neighbourhood convenience store and the like".
     *
     * `is`: codes that are those trades. `like_divisions`: PSIC divisions of
     * the small trades "and the like" reaches (retail, repair, personal
     * services, food service, offices), which CPDO judges case by case.
     */
    public const HOME_OCCUPATION = [
        'is' => ['69100', '69200', '70200', '71100', '75000', '86201', '14100', '10711', '47111', '47112'],
        'like_divisions' => ['47', '56', '62', '73', '74', '82', '85', '95', '96'],
    ];

    /** PSIC section C, divisions 10-33: manufacturing. */
    public static function isManufacturing(string $psic): bool
    {
        $division = (int) substr($psic, 0, 2);

        return $division >= 10 && $division <= 33;
    }
}

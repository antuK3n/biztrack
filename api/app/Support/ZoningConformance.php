<?php

namespace App\Support;

use App\Models\Barangay;
use App\Models\PsicCode;
use Illuminate\Support\Facades\Cache;

/**
 * What City Ordinance No. 24-2018 LISTS for the zones on a barangay's CPDO
 * sheet, and whether the applicant's declared trade is one of the listed uses.
 *
 * ── What this is, and the word it must never say ───────────────────────────
 *
 * This is a LOOKUP, not a determination. `docs/zoning-ordinance/README.md`
 * sets out four reasons the ordinance cannot be turned into an "is my business
 * allowed here" checker, and every one of them still holds:
 *
 *   1. §2.16 Fishpond Zone enumerates no uses at all, so there is no textual
 *      basis to allow or refuse anything in the barangays carrying it.
 *   2. The inheritance chains are broken — §2.8 inherits from "R-2 Zones" and
 *      no zone is called that. Resolving it would be legislating.
 *   3. The same activity carries different conditions in different zones, and
 *      filling stations get two conflicting rules outright.
 *   4. Conditionality is embedded in "provided that" prose, so "permitted" and
 *      "permitted if" are not machine-separable.
 *
 * Add Annex A entry 89 — anything unlisted is "referred to other appropriate
 * national and local laws" — and the enumeration is explicitly OPEN. Absence
 * from the list is not prohibition.
 *
 * So the verdicts below are `listed`, `not_listed` and `undetermined`, and the
 * screen says CPDO decides. What changed is that the sentence is now anchored
 * to 695 real uses read off the ordinance instead of to a `?zoning=deny` query
 * parameter, which is what it was anchored to before.
 *
 * ── Why `not_listed` is still worth showing ────────────────────────────────
 *
 * Because it is the applicant's only early warning. A trade absent from every
 * zone on their barangay's sheet is the case CPDO is most likely to question,
 * and hearing that at Part 2 is cheaper than hearing it after paying. It is
 * phrased as what it is: not on the list, CPDO decides.
 */
class ZoningConformance
{
    /**
     * `zoning_classifications.code` → the ordinance section enumerating its uses.
     *
     * Nineteen of the ordinance's twenty sections; §2.14 Easement Zone has no
     * CPDO sheet classification and therefore no row to map from. The join is by
     * section rather than by name because the extraction preserves the zone
     * headings verbatim, typos included — §2.4 reads "BASIC RESIDENTIAL 3 - Z0NE"
     * with a zero for the letter O, and matching on that string would break the
     * day somebody corrects it.
     */
    private const SECTION_FOR_CODE = [
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

    /**
     * Words that carry no zoning meaning and would match almost anything.
     *
     * PSIC titles and ordinance uses share a lot of connective tissue —
     * "activities", "services", "other", "n.e.c." — and a match on those alone
     * would report every trade as listed in every zone. The words kept are the
     * ones a planner would actually read: the goods, the verb, the place.
     */
    private const STOPWORDS = [
        'and', 'or', 'of', 'the', 'for', 'in', 'on', 'to', 'with', 'other', 'others',
        'activities', 'activity', 'service', 'services', 'nec', 'n', 'e', 'c',
        'related', 'including', 'include', 'included', 'etc', 'such', 'as', 'any',
        'general', 'specialized', 'non', 'units', 'unit', 'type', 'types', 'kind',
        'establishment', 'establishments', 'business', 'businesses', 'shall', 'be',
    ];

    /**
     * Words that name the COMMERCE rather than the activity.
     *
     * Shared between a PSIC title and an ordinance use, one of these alone
     * proves nothing: every manufacturer shares `manufacture` with every other
     * manufacturer, and a zone that wants one may refuse the next. They still
     * count towards the two-shared-words rule — they are weak, not worthless.
     */
    private const GENERIC = [
        'manufacture', 'manufacturing', 'retail', 'wholesale', 'sale', 'sales',
        'store', 'stores', 'shop', 'shops', 'repair', 'trade', 'trading',
        'supply', 'supplies', 'products', 'product', 'goods', 'center', 'centre',
        'house', 'houses', 'small', 'large', 'scale', 'operation', 'operations',
    ];

    /**
     * The ordinance's use lists, keyed by section.
     *
     * Cached for an hour rather than read per request: the file is 78 KB of
     * JSON and the zoning step re-asks this on every pin move. It is a
     * committed document that changes when an ordinance changes, so an hour is
     * conservative rather than risky.
     *
     * @return array<string, array{zone: string, allowed_uses: list<string>}>
     */
    public static function sections(): array
    {
        return Cache::remember('zoning.ordinance.sections', 3600, function (): array {
            $path = base_path('../docs/zoning-ordinance/zone-uses.json');
            if (! is_file($path)) {
                return [];
            }
            $raw = json_decode((string) file_get_contents($path), true);
            if (! is_array($raw)) {
                return [];
            }

            $out = [];
            foreach ($raw as $entry) {
                if (! isset($entry['section'])) {
                    continue;
                }
                $out[(string) $entry['section']] = [
                    'zone' => (string) ($entry['zone'] ?? ''),
                    'allowed_uses' => array_values(array_filter(
                        array_map('strval', (array) ($entry['allowed_uses'] ?? [])),
                    )),
                ];
            }

            return $out;
        });
    }

    /**
     * The zoning picture for one barangay, answered for one declared trade.
     *
     * `$psic` absent is normal and not an error: the applicant picks the
     * barangay on Location & Zoning before naming a line of business further
     * down the same step, so this is asked with no trade for as long as it
     * takes them to scroll.
     *
     * @return array{
     *     verdict: 'listed'|'not_listed'|'undetermined',
     *     reason: string,
     *     zones: list<array{code: string, name: string, use_count: int, listed: bool, matched_use: ?string}>,
     *     trade: ?string,
     * }
     */
    public static function forBarangay(?Barangay $barangay, ?PsicCode $psic): array
    {
        $trade = $psic?->title;

        if ($barangay === null) {
            return self::undetermined('No barangay chosen yet.', [], $trade);
        }

        $sections = self::sections();
        $zones = [];
        $enumerated = 0;
        $anyListed = false;

        foreach ($barangay->zoningClassifications as $classification) {
            $section = self::SECTION_FOR_CODE[$classification->code] ?? null;
            $uses = $section !== null ? ($sections[$section]['allowed_uses'] ?? []) : [];
            $enumerated += count($uses);

            $matched = $psic !== null ? self::matchUse($psic, $uses) : null;
            if ($matched !== null) {
                $anyListed = true;
            }

            $zones[] = [
                'code' => $classification->code,
                'name' => $classification->name,
                'use_count' => count($uses),
                'listed' => $matched !== null,
                'matched_use' => $matched,
            ];
        }

        if ($zones === []) {
            return self::undetermined('This barangay has no zoning sheet on file.', $zones, $trade);
        }
        if ($psic === null) {
            return self::undetermined('No line of business named yet.', $zones, $trade);
        }
        /*
         * Every zone on the sheet enumerates nothing — Dampalit's Fishpond Zone
         * is the real case. Reporting "not listed" there would be reporting the
         * ordinance's silence as a refusal.
         */
        if ($enumerated === 0) {
            return self::undetermined(
                'The ordinance lists no uses for the zones on this barangay’s sheet.',
                $zones,
                $trade,
            );
        }

        return [
            'verdict' => $anyListed ? 'listed' : 'not_listed',
            'reason' => $anyListed
                ? 'The ordinance lists this use for a zone on this barangay’s sheet.'
                : 'This use is not among those the ordinance lists for this barangay’s zones.',
            'zones' => $zones,
            'trade' => $trade,
        ];
    }

    /**
     * The first listed use that reads as the same activity as `$psic`, or null.
     *
     * Two signals, strongest first:
     *
     *  1. **The bracketed colloquial name.** PSIC titles carry the everyday word
     *     in brackets — "Retail sale in non-specialized stores (sari-sari
     *     store)" — and that half is what the ordinance is written in. An exact
     *     substring hit on it is as close to certain as this gets.
     *  2. **One shared DISTINCTIVE word.** A word naming the thing rather than
     *     the commerce around it. PSIC 56101 "Restaurants and carinderia" and
     *     §2.7 "Restaurants and other eateries, provided that adequate parking
     *     lots…" share exactly one word, and it is the whole answer — an earlier
     *     two-word floor reported that pair as not listed, which was wrong in
     *     the commonest case there is.
     *  3. **Two shared words of any kind**, which is what catches a pair whose
     *     only overlap is generic but repeated.
     *
     * The generic list is what stops (2) firing on "Manufacture of jewellery"
     * against "Manufacture of cement": `manufacture` names the commerce, not the
     * activity, so on its own it says nothing about whether a zone wants it.
     *
     * A miss is reported as a miss. This deliberately under-claims — a trade the
     * ordinance lists in words we did not match reads as `not_listed`, and the
     * screen's answer to `not_listed` is "CPDO decides", which is also the right
     * answer when we simply failed to match.
     */
    private static function matchUse(PsicCode $psic, array $uses): ?string
    {
        if ($uses === []) {
            return null;
        }

        $title = mb_strtolower($psic->title);

        $colloquial = null;
        if (preg_match('/\(([^)]+)\)\s*$/u', $title, $m) === 1) {
            $colloquial = trim($m[1]);
        }

        $terms = self::significantWords($title);

        foreach ($uses as $use) {
            $haystack = mb_strtolower($use);

            if ($colloquial !== null && $colloquial !== '' && str_contains($haystack, $colloquial)) {
                return $use;
            }

            $shared = array_intersect($terms, self::significantWords($haystack));
            if ($shared === []) {
                continue;
            }
            $distinctive = array_diff($shared, self::GENERIC);
            if ($distinctive !== [] || count($shared) >= 2) {
                return $use;
            }
        }

        return null;
    }

    /** Lowercased words of three letters or more that are not stopwords. */
    private static function significantWords(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true),
        )));
    }

    private static function undetermined(string $reason, array $zones, ?string $trade): array
    {
        return ['verdict' => 'undetermined', 'reason' => $reason, 'zones' => $zones, 'trade' => $trade];
    }
}

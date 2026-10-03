<?php

namespace App\Support;

use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\Zoning\Ordinance;
use App\Support\Zoning\TradeUses;
use Illuminate\Support\Facades\Cache;

/**
 * What City Ordinance No. 24-2018 LISTS for the zones in a barangay, and
 * whether the applicant's declared trade is one of the listed uses.
 *
 * ── What this is, and what it is not ───────────────────────────────────────
 *
 * The headline of the zoning check: is the trade on the list of any zone in
 * the barangay. Everything the ordinance attaches to that answer — the
 * "provided that" conditions, the overlays, the special uses, the
 * non-conforming rules — is App\Support\Zoning\ZoningCheck, which reads this.
 *
 * It is still not a verdict, for the reason that was always true and the
 * ordinance itself writes down: the lists are open. Art. III §2(a) reads "and
 * the like" as taking in every similar use, Art. III §2 construes the lists in
 * favour of the applicant, and Annex A item 89 refers anything unlisted to
 * other laws. So a trade on no list is `not_listed` — CPDO decides — and never
 * "prohibited".
 *
 * ── What changed with the full reading of the ordinance ────────────────────
 *
 * This used to look a trade up in each zone's OWN list and nowhere else, and
 * the docblock called the inheritance chains "broken". Read clause by clause,
 * most of them are not: C-2 says "All uses allowed in C1-Zone", the CBD takes
 * in C-1, C-2, C-3 and General Commercial, and so on. Ignoring that told a
 * drugstore in a C-2-only stretch that it was not listed, when §2.8 lists it
 * by reference. The chains are now followed exactly as written
 * (`Ordinance::INHERITS`), and the one real gap — Maximum R-3 and C-1 skip
 * Basic R-3 — is reported as a gap rather than papered over.
 *
 * The zones it reads are Art. IV §5's TEXT for the barangay, not only the map
 * sheet, because Art. IV §6 says the text prevails over the map. Sheet-only
 * zones are still returned, marked, so nobody loses sight of what the map
 * draws.
 *
 * ── Listed, possibly listed, not listed ─────────────────────────────────────
 *
 * A trade is LISTED in a zone only where Zoning\TradeUses — the register's
 * 133 codes read one by one against the lists and Annex A's definitions —
 * names the line. A line it marks as only possibly this trade (a scale, a
 * "like:" list, a code nobody has read yet) is POSSIBLE: CPDO checks, never
 * Met. One shared word is no longer evidence of anything; it reported a
 * gasoline station as a water refilling station (audit of 3 October 2026).
 *
 * ── Why `not_listed` is still worth showing ────────────────────────────────
 *
 * Because it is the applicant's only early warning. A trade absent from every
 * zone in their barangay is the case CPDO is most likely to question, and
 * hearing that at Location & Zoning is cheaper than hearing it after paying.
 */
class ZoningConformance
{
    /**
     * `zoning_classifications.code` → the ordinance section enumerating its
     * uses. Held in `Ordinance` beside the inheritance chains it pairs with.
     */
    private const SECTION_FOR_CODE = Ordinance::SECTION_FOR_CODE;

    /**
     * Words that carry no zoning meaning and would match almost anything.
     *
     * Only for a code Zoning\TradeUses has not read (matchUnvetted), whose
     * nearest line is offered to CPDO and never reported as listed. PSIC
     * titles and ordinance uses share a lot of connective tissue —
     * "activities", "services", "other", "n.e.c." — that says nothing.
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
     * Shared between a PSIC title and an ordinance use, these prove nothing:
     * every manufacturer shares `manufacture` with every other manufacturer,
     * and a zone that wants one may refuse the next. They do not count
     * towards the two shared words an unread code needs before its nearest
     * line is even offered to CPDO.
     */
    private const GENERIC = [
        'manufacture', 'manufacturing', 'retail', 'wholesale', 'sale', 'sales',
        'store', 'stores', 'shop', 'shops', 'repair', 'trade', 'trading',
        'supply', 'supplies', 'products', 'product', 'goods', 'center', 'centre',
        'house', 'houses', 'small', 'large', 'scale', 'operation', 'operations',
        // A billiard hall is not a barangay hall: these name a kind of room,
        // not what is done in it.
        'hall', 'halls', 'room', 'rooms', 'facility', 'facilities', 'building', 'buildings',
        // The words behind the audit's false listings: a gasoline station
        // and a water refilling STATION, trucking and an eatery's ROAD,
        // plastics and a biscuit FACTORY, a flower shop and an ice PLANT.
        'station', 'stations', 'road', 'roads', 'factory', 'factories', 'plant', 'plants',
        'place', 'places', 'parking', 'vehicles', 'vehicle', 'equipment', 'materials',
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
     * A zone's own uses, without its "All uses allowed in …" pointers and
     * without its customary accessory uses.
     *
     * The pointers are how a zone inherits, and `lookup` follows them; matched
     * as text they would claim a trade is listed because it shares the word
     * "allowed" with a sentence about another zone.
     *
     * Accessory uses are left out because the ordinance allows them only as
     * "incidental to any of the principal uses" — the offices, canteens and
     * storerooms OF an allowed use, never a business in their own right. R-1
     * says so outright: its accessory uses "shall not include any activity
     * conducted for monetary gain". Matching a trade against them reported a
     * software company as allowed in the Institutional zone because that
     * zone's accessory list says "Offices".
     *
     * @return list<string>
     */
    public static function ownUses(string $code): array
    {
        $section = self::SECTION_FOR_CODE[$code] ?? null;
        $uses = $section !== null ? (self::sections()[$section]['allowed_uses'] ?? []) : [];

        return array_values(array_filter(
            $uses,
            static fn (string $use): bool => preg_match('/^All (?:allowable )?uses (?:allowed )?(?:in|under|according)/i', $use) !== 1
                // Anywhere in the line: the industrial lists prefix it with
                // their class ("Non-Pollutive/Non-Hazardous Industries -
                // Customary accessory uses …: Offices"), and a line-start
                // test let a software company read as allowed in Industrial-1.
                && preg_match('/Customary accessory uses/i', $use) !== 1,
        ));
    }

    /**
     * The lines of a zone's own list a business can be: its own uses, less
     * the family-only recreation (R-1 §2.1: "for the exclusive use of the
     * members of the family") and the home-occupation and home-industry
     * clauses, which ZoningCheck applies on their own six and four conditions
     * rather than as a listing.
     *
     * @return list<string>
     */
    public static function matchable(string $code): array
    {
        return array_values(array_filter(
            self::ownUses($code),
            static fn (string $use): bool => preg_match('/^Home (?:occupation|industry)\b/i', $use) !== 1
                && ! str_contains(mb_strtolower($use), 'exclusive use of the members of the family'),
        ));
    }

    /**
     * The first line in `$zone` — its own list, then each zone it takes in, in
     * the order Art. V names them — that is `$psic`'s activity, and how sure.
     *
     * `certain` is true only for a line Zoning\TradeUses names as this trade.
     * Its `maybe` lines, and anything found for a code the table does not
     * hold, come back with `certain` false: a possibility for CPDO, never a
     * listing. Certain lines anywhere in the zone's reach are preferred to a
     * possible line in its own list.
     *
     * `$facts` are the applicant's answers the definitions turn on (a hotel's
     * in-room kitchens, a dry cleaner's solvents, a lessor's families).
     *
     * @param  array<string, mixed>  $facts
     * @return array{use: string, from: string, via: ?string, certain: bool, basis: string, definition: ?string}|null
     */
    public static function lookup(string $zone, PsicCode $psic, array $facts = []): ?array
    {
        $spec = TradeUses::for((string) $psic->code, $facts);
        foreach (['is', 'maybe'] as $tier) {
            foreach (Ordinance::closure($zone) as $source) {
                $uses = self::matchable($source);
                $matched = match (true) {
                    $spec['curated'] => self::firstPhrase($spec[$tier], $uses),
                    $tier === 'maybe' => self::matchUnvetted($psic, $uses),
                    default => null,
                };
                if ($matched !== null) {
                    return [
                        'use' => $matched,
                        'from' => $source,
                        'via' => Ordinance::inheritanceRule($zone, $source),
                        'certain' => $spec['curated'] && $tier === 'is',
                        // `listed`: the table names the line. `similar`: the
                        // table says it may be this trade. `unvetted`: a code
                        // the table has not read; the line is the nearest
                        // wording, offered under Art. III §1 for CPDO to read.
                        'basis' => $spec['curated'] ? ($tier === 'is' ? 'listed' : 'similar') : 'unvetted',
                        'definition' => $spec['def'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * The first line in `$zone`'s reach containing one of `$phrases` — for a
     * use named by the applicant's answer rather than by a PSIC code (a pay
     * parking lot, a taxi garage), which the register has no code for.
     *
     * @param  list<string>  $phrases
     * @return array{use: string, from: string, via: ?string, certain: bool, basis: string, definition: ?string}|null
     */
    public static function lookupPhrases(string $zone, array $phrases): ?array
    {
        foreach (Ordinance::closure($zone) as $source) {
            $matched = self::firstPhrase($phrases, self::matchable($source));
            if ($matched !== null) {
                return [
                    'use' => $matched,
                    'from' => $source,
                    'via' => Ordinance::inheritanceRule($zone, $source),
                    'certain' => true,
                    'basis' => 'listed',
                    'definition' => null,
                ];
            }
        }

        return null;
    }

    /**
     * The zones in a barangay: Art. IV §5's text, and the CPDO sheet.
     *
     * `governing` is what the check decides on. It is the text's list (Art.
     * IV §6: the text prevails over the map), plus Parks and Utilities where
     * the sheet draws them, since §5 places those two city-wide on existing
     * facilities rather than in any barangay. A barangay the text does not
     * name — none today — falls back to its sheet rather than to nothing.
     *
     * @return list<array{code: string, name: string, source: 'text'|'sheet'|'both', governing: bool}>
     */
    public static function zonesFor(?Barangay $barangay): array
    {
        if ($barangay === null) {
            return [];
        }
        $text = Ordinance::textZones()[Ordinance::key($barangay->name)] ?? [];
        $sheet = $barangay->zoningClassifications->pluck('name', 'code')->all();

        $codes = array_values(array_unique(array_merge($text, array_keys($sheet))));
        usort($codes, fn ($a, $b) => array_search($a, array_keys(self::SECTION_FOR_CODE), true)
            <=> array_search($b, array_keys(self::SECTION_FOR_CODE), true));

        $out = [];
        foreach ($codes as $code) {
            $inText = in_array($code, $text, true);
            $onSheet = array_key_exists($code, $sheet);
            $out[] = [
                'code' => $code,
                'name' => $sheet[$code] ?? Ordinance::ZONE_NAMES[$code] ?? $code,
                'source' => $inText && $onSheet ? 'both' : ($inText ? 'text' : 'sheet'),
                'governing' => $text === []
                    ? true
                    : ($inText || in_array($code, ['PARKS', 'UTILITIES'], true)),
            ];
        }

        return $out;
    }

    /**
     * The zoning picture for one barangay, answered for one declared trade.
     *
     * `$psic` absent is normal and not an error: the applicant picks the
     * barangay on Location & Zoning before naming a line of business further
     * down the same step, so this is asked with no trade for as long as it
     * takes them to scroll.
     *
     * `possible` is a zone whose list may hold the trade but does not name it
     * (TradeUses' `maybe`, or a code the table has not read): the verdict is
     * then `possible`, which the screen says as "CPDO checks", never as
     * allowed.
     *
     * @return array{
     *     verdict: 'listed'|'possible'|'not_listed'|'undetermined',
     *     reason: string,
     *     zones: list<array{code: string, name: string, use_count: int, listed: bool, possible: bool, matched_use: ?string, source: string, governing: bool, via: ?string, from: ?string}>,
     *     trade: ?string,
     * }
     */
    public static function forBarangay(?Barangay $barangay, ?PsicCode $psic): array
    {
        $trade = $psic?->title;

        if ($barangay === null) {
            return self::undetermined('No barangay chosen yet.', [], $trade);
        }

        $zones = [];
        $enumerated = 0;
        $anyListed = false;
        $anyPossible = false;

        foreach (self::zonesFor($barangay) as $zone) {
            $count = 0;
            foreach (Ordinance::closure($zone['code']) as $source) {
                $count += count(self::ownUses($source));
            }
            $hit = $psic !== null ? self::lookup($zone['code'], $psic) : null;
            $certain = $hit !== null && $hit['certain'];
            if ($zone['governing']) {
                $enumerated += $count;
                $anyListed = $anyListed || $certain;
                $anyPossible = $anyPossible || ($hit !== null && ! $certain);
            }

            $zones[] = [
                'code' => $zone['code'],
                'name' => $zone['name'],
                'use_count' => $count,
                'listed' => $certain,
                'possible' => $hit !== null && ! $certain,
                'matched_use' => $hit['use'] ?? null,
                'source' => $zone['source'],
                'governing' => $zone['governing'],
                'via' => $hit['via'] ?? null,
                'from' => $hit['from'] ?? null,
            ];
        }

        if ($zones === []) {
            return self::undetermined('This barangay has no zoning sheet on file.', $zones, $trade);
        }
        if ($psic === null) {
            return self::undetermined('No line of business named yet.', $zones, $trade);
        }
        /*
         * Every zone here enumerates nothing — a barangay that was Fishpond
         * alone would be the case. Reporting "not listed" there would be
         * reporting the ordinance's silence as a refusal.
         */
        if ($enumerated === 0) {
            return self::undetermined(
                'The ordinance lists no uses for the zones in this barangay.',
                $zones,
                $trade,
            );
        }

        return [
            'verdict' => $anyListed ? 'listed' : ($anyPossible ? 'possible' : 'not_listed'),
            'reason' => match (true) {
                $anyListed => 'The ordinance lists this use for a zone in this barangay.',
                $anyPossible => 'A zone here lists a use this may be; CPDO decides whether it is.',
                default => 'This use is not among those the ordinance lists for this barangay’s zones.',
            },
            'zones' => $zones,
            'trade' => $trade,
        ];
    }

    /**
     * Whether any line of `$uses` is `$psic`'s activity, certainly or
     * possibly — for the questions that ask "is it on THIS list at all"
     * (the Basic R-3 inheritance gap).
     *
     * @param  list<string>  $uses
     */
    public static function matchUse(PsicCode $psic, array $uses, array $facts = []): ?string
    {
        $spec = TradeUses::for((string) $psic->code, $facts);
        if ($spec['curated']) {
            return self::firstPhrase($spec['is'], $uses) ?? self::firstPhrase($spec['maybe'], $uses);
        }

        return self::matchUnvetted($psic, $uses);
    }

    /**
     * The first line containing one of `$phrases`, phrase order first: the
     * table lists a trade's plainest name before its looser ones.
     *
     * @param  list<string>  $phrases
     * @param  list<string>  $uses
     */
    private static function firstPhrase(array $phrases, array $uses): ?string
    {
        foreach ($phrases as $phrase) {
            foreach ($uses as $use) {
                if (self::phraseHits($phrase, $use)) {
                    return $use;
                }
            }
        }

        return null;
    }

    /** `=phrase` is the line's last segment exactly; otherwise a substring. */
    private static function phraseHits(string $phrase, string $use): bool
    {
        $text = mb_strtolower(trim($use));
        if (! str_starts_with($phrase, '=')) {
            return str_contains($text, $phrase);
        }
        $segments = explode(': ', $text);

        return rtrim(trim((string) end($segments)), '.') === substr($phrase, 1);
    }

    /**
     * For a code Zoning\TradeUses has not read: the line nearest in wording,
     * offered to CPDO and never reported as listed.
     *
     * The PSIC title's bracketed everyday name ("(sari-sari store)") found in
     * a line, or two shared words that are neither connective nor generic.
     * One shared word is not enough: "station" put a gasoline station on the
     * water-refilling line, "road" put trucking on the eatery line.
     *
     * @param  list<string>  $uses
     */
    private static function matchUnvetted(PsicCode $psic, array $uses): ?string
    {
        if ($uses === []) {
            return null;
        }
        $title = mb_strtolower($psic->title);
        if (preg_match('/\(([^)]+)\)\s*$/u', $title, $m) === 1 && trim($m[1]) !== '') {
            foreach ($uses as $use) {
                if (str_contains(mb_strtolower($use), trim($m[1]))) {
                    return $use;
                }
            }
        }
        $terms = array_diff(self::significantWords($title), self::GENERIC);
        foreach ($uses as $use) {
            $shared = array_intersect($terms, array_diff(self::significantWords($use), self::GENERIC));
            if (count($shared) >= 2) {
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

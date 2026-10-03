<?php

namespace App\Support;

use App\Models\Barangay;
use App\Models\PsicCode;
use App\Support\Zoning\Ordinance;
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
        // A billiard hall is not a barangay hall: these name a kind of room,
        // not what is done in it.
        'hall', 'halls', 'room', 'rooms', 'facility', 'facilities', 'building', 'buildings',
    ];

    /**
     * The ordinance's own word for a trade whose PSIC title shares none with
     * it — "pharmacy" against "Drugstores", "call centre" against "Business
     * Process Outsourcing". Matched as a substring of the use, after the
     * bracketed name and before the shared-word rule, because a word chosen by
     * a person is better evidence than a word two texts happen to share.
     *
     * Kept to trades on the register's PSIC list whose title and the
     * ordinance plainly name the same thing. A code absent here falls through
     * to the shared-word rule as before.
     */
    private const ALIASES = [
        '47112' => ['groceries', 'convenience store'],
        '47190' => ['department store'],
        '47411' => ['consumer electronics'],
        '47412' => ['consumer electronics', 'cellular phone'],
        '47420' => ['consumer electronics'],
        '47521' => ['lumber/hardware', 'construction supply'],
        '47522' => ['paint stores without bulk', 'glassware'],
        '47592' => ['home appliance'],
        '47721' => ['drugstore', 'drug store'],
        '47730' => ['jewelry'],
        '47760' => ['flower shop', 'pet shop', 'plant nurser'],
        '47810' => ['wet and dry markets'],
        '47820' => ['wet and dry markets'],
        '45301' => ['spare parts'],
        '45201' => ['auto repair'],
        '45401' => ['motor vehicles and accessory repair'],
        '47300' => ['gasoline filling station'],
        '49221' => ['tricycle', 'transportation terminals'],
        '49230' => ['hauling services', 'trucking garage'],
        '52101' => ['warehouse/storage facility'],
        '53100' => ['courier'],
        '55101' => ['hotel'],
        '55102' => ['apartel', 'pension house'],
        '55103' => ['motel'],
        '55900' => ['dormitor', 'boarding house'],
        '56102' => ['restaurants and other eateries'],
        '56301' => ['restaurants and other eateries'],
        '56302' => ['bars, sing-along'],
        '59140' => ['movie house'],
        '62010' => ['=offices'],
        '62090' => ['=offices'],
        '63110' => ['=offices'],
        '64920' => ['money lending', 'pawnshop'],
        '64990' => ['bayad center', 'foreign exchange'],
        '65120' => ['insurance'],
        '68200' => ['=offices'],
        '69100' => ['=offices'],
        '69200' => ['=offices'],
        '70200' => ['=offices'],
        '71100' => ['=offices'],
        '73100' => ['=offices'],
        '74200' => ['photo and portrait'],
        '77100' => ['auto sales and rentals'],
        '78100' => ['=offices'],
        '79110' => ['travel agenc'],
        '80100' => ['security agenc'],
        '81210' => ['janitorial'],
        '82200' => ['business process outsourcing'],
        '82990' => ['=offices'],
        '85100' => ['nursery/elementary school'],
        '85490' => ['tutorial', 'driving school'],
        '86100' => ['hospital'],
        '86201' => ['medical, dental'],
        '93290' => ['billiard', 'internet cafe'],
        '96110' => ['barber'],
        '96120' => ['beauty parlor'],
        '96200' => ['laundr'],
        '96301' => ['funeral parlor'],
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
                && preg_match('/^Customary accessory uses/i', $use) !== 1,
        ));
    }

    /**
     * The first use in `$zone` — its own list, then each zone it takes in, in
     * the order Art. V names them — that reads as `$psic`'s activity.
     *
     * @return array{use: string, from: string, via: ?string}|null
     */
    public static function lookup(string $zone, PsicCode $psic): ?array
    {
        /*
         * Strong evidence across every list the zone reaches before weak
         * evidence in any of them. Zone by zone, a private school matched
         * Maximum R-2's own "School supplies" on the one word "school" before
         * the search ever reached the "Nursery/Elementary School" that R-1
         * names and Maximum R-2 inherits.
         */
        foreach ([true, false] as $strong) {
            foreach (Ordinance::closure($zone) as $source) {
                $uses = self::ownUses($source);
                $matched = $strong ? self::matchStrong($psic, $uses) : self::matchWeak($psic, $uses);
                if ($matched !== null) {
                    return [
                        'use' => $matched,
                        'from' => $source,
                        'via' => Ordinance::inheritanceRule($zone, $source),
                    ];
                }
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
     * @return array{
     *     verdict: 'listed'|'not_listed'|'undetermined',
     *     reason: string,
     *     zones: list<array{code: string, name: string, use_count: int, listed: bool, matched_use: ?string, source: string, governing: bool, via: ?string, from: ?string}>,
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

        foreach (self::zonesFor($barangay) as $zone) {
            $count = 0;
            foreach (Ordinance::closure($zone['code']) as $source) {
                $count += count(self::ownUses($source));
            }
            $hit = $psic !== null ? self::lookup($zone['code'], $psic) : null;
            if ($zone['governing']) {
                $enumerated += $count;
                $anyListed = $anyListed || $hit !== null;
            }

            $zones[] = [
                'code' => $zone['code'],
                'name' => $zone['name'],
                'use_count' => $count,
                'listed' => $hit !== null,
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
            'verdict' => $anyListed ? 'listed' : 'not_listed',
            'reason' => $anyListed
                ? 'The ordinance lists this use for a zone in this barangay.'
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
    public static function matchUse(PsicCode $psic, array $uses): ?string
    {
        return self::matchStrong($psic, $uses) ?? self::matchWeak($psic, $uses);
    }

    /**
     * The bracketed colloquial name, then an alias, anywhere in the list.
     *
     * Across the whole list before any shared-word test: this was one pass
     * trying both on each use in turn, so an early use sharing two generic
     * words ("Small scale eatery…") beat a later one quoting the trade's own
     * name — the strongest signal losing to the weakest because of list order.
     */
    private static function matchStrong(PsicCode $psic, array $uses): ?string
    {
        if ($uses === []) {
            return null;
        }
        $title = mb_strtolower($psic->title);
        if (preg_match('/\(([^)]+)\)\s*$/u', $title, $m) === 1 && trim($m[1]) !== '') {
            $colloquial = trim($m[1]);
            foreach ($uses as $use) {
                if (str_contains(mb_strtolower($use), $colloquial)) {
                    return $use;
                }
            }
        }

        // An alias starting "=" must be the whole use ("Offices"), not a word
        // inside one ("offices of physicians", "local offices").
        foreach (self::ALIASES[(string) $psic->code] ?? [] as $alias) {
            foreach ($uses as $use) {
                $text = mb_strtolower(trim($use));
                if (str_starts_with($alias, '=') ? $text === substr($alias, 1) : str_contains($text, $alias)) {
                    return $use;
                }
            }
        }

        return null;
    }

    /** One shared distinctive word, or two shared words of any kind. */
    private static function matchWeak(PsicCode $psic, array $uses): ?string
    {
        $terms = self::significantWords(mb_strtolower($psic->title));
        foreach ($uses as $use) {
            $shared = array_intersect($terms, self::significantWords(mb_strtolower($use)));
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

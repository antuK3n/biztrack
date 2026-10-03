<?php

namespace App\Support\Zoning;

use App\Models\PsicCode;
use App\Support\DenrRequirements;
use App\Support\ZoningConformance;
use Carbon\CarbonImmutable;

/**
 * City Ordinance No. 24-2018, applied to one filing, rule by rule.
 *
 * ── What it answers, and what it never says ────────────────────────────────
 *
 * Every rule of the ordinance that a business filing can trip produces a
 * FINDING with the rule's citation (article, section, printed page): met, not
 * met, or CPDO review. The applicant reads it as an early warning on Location
 * & Zoning; the zoning officer reads the same list as a cited checklist when
 * deciding the clearance. Nothing here refuses a filing — Art. II §3(1) makes
 * every land use "a use by right" subject to review, and the review is CPDO's
 * (Art. IX §14). A "not met" says which condition the answers do not satisfy;
 * what happens next is the officer's call, or a variance or exception from the
 * Local Zoning Board of Appeals (Art. VIII).
 *
 * ── The lot's zone is usually unknown, and that is handled, not ignored ────
 *
 * A barangay holds several zones and BizTrack cannot tell which one a pin is
 * in (the sheets are rasters; see ZoningClassification). So a condition that
 * binds only in some zones — the 200 m² warehouse cap binds in C-1, not in
 * General Commercial — is evaluated, and when the lot might be in a zone where
 * it does not bind, a failure is reported as CPDO review with the zone named
 * ("applies if your lot is in Commercial-1"), never as a flat "not met". Once
 * CPDO records the lot's zone (`lot_zone`), every such finding resolves.
 *
 * ── Contradictions are surfaced, never resolved here ───────────────────────
 *
 * Where the ordinance disagrees with itself (two filing-station siting rules,
 * two definitions of a home occupation, two readings of "one lot deep", a
 * ten-year phase-out against "until it ceases operation"), both rules are
 * evaluated and a separate finding names the conflict and the question put to
 * the City in docs/questions-for-malabon.md. Picking a side would be
 * legislating.
 *
 * Every rule id used here exists in docs/zoning-ordinance/rules.json, which
 * holds the verbatim text; ZoningRulesCatalogueTest fails if one does not.
 */
final class ZoningCheck
{
    /** @var list<array<string, mixed>> */
    private array $findings = [];

    /** @var list<string> governing zone codes */
    private array $governing = [];

    /** @var list<array<string, mixed>> */
    private array $zones = [];

    /** @var list<string> sheet-only zone codes */
    private array $sheetOnly = [];

    private ?string $lotZone = null;

    /** @var list<string> */
    private array $overlays = [];

    /** Principal line's listing result, shared by several groups. */
    private ?array $principal = null;

    private bool $principalListed = false;

    /** @var list<string> zones the principal line is listed in */
    private array $listedIn = [];

    private function __construct(private readonly ZoningContext $ctx) {}

    /**
     * @return array{
     *     ready: bool,
     *     zones: list<array<string, mixed>>,
     *     overlays: list<string>,
     *     lot_zone: ?string,
     *     findings: list<array<string, mixed>>,
     *     summary: array<string, int>,
     *     facts: list<array<string, mixed>>,
     *     principle: array<string, string>,
     * }
     */
    public static function evaluate(ZoningContext $ctx): array
    {
        return (new self($ctx))->run();
    }

    private function run(): array
    {
        if ($this->ctx->barangay === null) {
            return $this->result(false);
        }

        $this->zones();
        $this->uses();
        $this->homeBusiness();
        $this->definitions();
        $this->provisos();
        $this->specialUses();
        $this->overlaysGroup();
        $this->waterways();
        $this->signs();
        $this->site();
        $this->performance();
        $this->environmental();
        $this->heldClearance();
        $this->nonConforming();
        $this->amendment();
        $this->procedure();

        return $this->result(true);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Where: the zones in the barangay, and which one the lot is in
    // ────────────────────────────────────────────────────────────────────

    private function zones(): void
    {
        $barangay = $this->ctx->barangay;
        $this->zones = ZoningConformance::zonesFor($barangay);
        $this->governing = array_values(array_map(
            fn ($z) => $z['code'],
            array_filter($this->zones, fn ($z) => $z['governing']),
        ));
        $this->sheetOnly = array_values(array_map(
            fn ($z) => $z['code'],
            array_filter($this->zones, fn ($z) => ! $z['governing']),
        ));
        $this->overlays = $barangay->zoningOverlays->pluck('code')->all();

        $lotZone = $this->ctx->fact('lot_zone');
        $all = array_map(fn ($z) => $z['code'], $this->zones);
        $this->lotZone = is_string($lotZone) && in_array($lotZone, $all, true) ? $lotZone : null;

        $this->add(['IV-5', 'IV-1', 'IV-2'], 'info',
            "Art. IV §5 places these zones in {$barangay->name}: ".$this->names($this->governing).'.',
            ['group' => 'where']);

        $textOnly = array_values(array_map(fn ($z) => $z['code'],
            array_filter($this->zones, fn ($z) => $z['source'] === 'text')));
        if ($this->sheetOnly !== [] || $textOnly !== []) {
            $parts = [];
            if ($this->sheetOnly !== []) {
                $parts[] = 'The map sheet also shows '.$this->names($this->sheetOnly).', which the text does not place here.';
            }
            if ($textOnly !== []) {
                $parts[] = 'The text places '.$this->names($textOnly).' here, which the map sheet does not show.';
            }
            $this->add(['IV-6-g', 'IV-6-l'], 'review',
                implode(' ', $parts).' Where they differ the text prevails, and this check goes by the text.',
                ['group' => 'where', 'question' => 'C11']);
        }

        // Commercial strips along a named street (§5), and the two readings
        // of "one lot deep" (§6 against Annex A 66-67).
        $keys = Ordinance::streetKeys($this->ctx->street);
        $strips = array_values(array_filter(
            Ordinance::STRIPS[Ordinance::key($barangay->name)] ?? [],
            fn ($s) => in_array($s[0], $keys, true),
        ));
        foreach ($strips as [$street, $zone, $depth]) {
            $label = Ordinance::STREETS[$street]['label'];
            $deep = $depth === 'lot'
                ? 'one lot deep (Annex A 67: 30 m from the road’s centre; Art. IV §6: the 2018 parcels, or the average lot depth nearby)'
                : 'one block deep (Annex A 66: 70 m from the road’s centre)';
            $this->add(['IV-5-STRIP', 'IV-6-f', 'IV-6-k', 'A-66-67'], $this->lotZone !== null ? 'info' : 'review',
                "{$label} carries a ".Ordinance::ZONE_NAMES[$zone]." strip in {$barangay->name}, {$deep}. If your lot fronts it within that depth, it is likely in ".Ordinance::ZONE_NAMES[$zone].'. The ordinance gives two ways to measure the depth; CPDO decides which.',
                ['group' => 'where', 'question' => 'C15']);
        }

        if (Ordinance::key($barangay->name) === Ordinance::POTRERO_DOUBLE
            && in_array('C-3', $this->governing, true) && in_array('CBD', $this->governing, true)) {
            $this->add('IV-5-POTRERO', 'review',
                'One Potrero area (bounded by the Tullahan River, NLEX and the listed lots) is written under both Commercial-3 and the Central Business District. The CBD allows more (it takes in General Commercial uses); CPDO decides which applies to a lot there.',
                ['group' => 'where', 'question' => 'C20', 'audience' => 'officer']);
        }

        $key = Ordinance::key($barangay->name);
        if ($key === 'muzon') {
            $this->add(['IV-5', 'V-2.16'], 'review',
                'Art. IV §5 places Maximum Residential-2 in Muzon "excluding areas occupied by existing fishponds" and puts no other zone on those fishponds; the map sheet draws a Fishpond zone there. A lot on Muzon’s fishponds has no zone in the text, and the Fishpond Zone lists no uses in any case. The City has been asked; CPDO decides.',
                ['group' => 'where', 'question' => 'C17', 'title' => 'Muzon’s fishponds have no zone in the text']);
        }
        $facilities = array_values(array_intersect(array_column($this->zones, 'code'), ['PARKS', 'UTILITIES']));
        if ($facilities !== []) {
            $this->add('IV-5-PR-UTS', 'review',
                'Art. IV §5 places '.$this->names($facilities).' on "all area occupied by existing" facilities, with "Follow Base Zone" in its overlay column. This check treats a park or utility the sheet draws as its own zone; whether a lot there takes the surrounding base zone instead has been asked of the City. CPDO decides.',
                ['group' => 'where', 'audience' => 'officer', 'question' => 'C34', 'title' => 'Parks and utilities: own zone or base zone']);
        }
        if ($key === 'panghulo' && in_array('C-1', $this->governing, true) && in_array('C-2', $this->governing, true)) {
            $this->add('IV-5', 'review',
                'Two §5 rows overlap in Panghulo: Commercial-1’s area bounded by Rodriguez St., Narra St., M.H. del Pilar St. and Panghulo Road, and Commercial-2’s Panghulo Market bounded by Rodriguez, M.H. del Pilar and Narra Streets. A lot in that block may be either; Commercial-2 allows more. The City has been asked; CPDO decides.',
                ['group' => 'where', 'audience' => 'officer', 'question' => 'C37', 'title' => 'Overlapping commercial rows in Panghulo']);
        }
        if ($key === 'santulan' && in_array('C-1', $this->governing, true)) {
            $this->add('IV-5', 'review',
                'One Santulan Commercial-1 row reads "One lot deep commercial strip (right side from Aurora St. to Javier St." with the street it runs along left out, and the Luis S./M.H. del Pilar/Rodriguez/Narra block is listed twice. Where that strip lies is for CPDO; the City has been asked.',
                ['group' => 'where', 'audience' => 'officer', 'question' => 'C37', 'title' => 'An incomplete Commercial-1 row in Santulan']);
        }

        // CPDO's own determination of the lot's zone (Art. IV §6).
        if ($this->lotZone === null) {
            $this->add(['IV-6-a', 'IV-6-b', 'IV-6-c', 'IV-6-d', 'IV-6-i', 'IV-6-j'], 'review',
                'Record the zone of this lot to resolve every finding below that depends on it. Boundaries follow road right-of-way lines, lot lines, 15 m either side of the railroad, and the map at 1:10,000.',
                ['group' => 'where', 'audience' => 'officer', 'officer_asks' => ['lot_zone', 'lot_split_by_boundary']]);
        } else {
            $this->add(['IV-6-a', 'IV-6-b', 'IV-6-c', 'IV-6-d', 'IV-6-i', 'IV-6-j'], 'info',
                'CPDO placed this lot in '.$this->zoneName($this->lotZone).'.',
                ['group' => 'where', 'officer_asks' => ['lot_zone', 'lot_split_by_boundary']]);
        }
        if ($this->ctx->fact('lot_split_by_boundary') === true) {
            $this->add(['IV-6-e', 'IV-6-k'], 'review',
                'A zone boundary crosses this lot. It is in the zone holding the larger part of it; if split evenly, the zone where the principal structure stands.',
                ['group' => 'where', 'audience' => 'officer', 'officer_asks' => ['lot_split_by_boundary']]);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  What: is the trade on a zone's list
    // ────────────────────────────────────────────────────────────────────

    private function uses(): void
    {
        $lines = $this->ctx->lines;
        if ($lines === []) {
            $this->add('V-2', 'review', 'Name your line of business to check it against the zones’ lists.',
                ['group' => 'uses']);

            return;
        }

        // Art. IV §7: a filing with several lines is judged by its principal
        // one — the greatest impact, read as revenue (b.1), else capital.
        $principal = $lines[0];
        $basis = null;
        if (count($lines) > 1) {
            foreach (['gross_sales' => 'gross sales', 'capitalization' => 'capital'] as $field => $label) {
                $values = array_map(fn ($l) => $l[$field] ?? 0, $lines);
                if (max($values) > 0) {
                    $top = array_keys($values, max($values));
                    $principal = $lines[$top[0]];
                    $basis = count($top) > 1 ? null : $label;
                    break;
                }
            }
        }

        $codes = $this->lotZone !== null ? [$this->lotZone] : $this->governing;
        $this->principal = $principal;
        $title = $principal['psic']->title;

        // What the business IS, when the applicant's answer says more than
        // the PSIC code can: a pay parking lot or a taxi garage has no code
        // of its own on the register.
        $vehicle = $this->vehicleUse();
        $phrases = $vehicle !== null ? (Ordinance::VEHICLE_USES[$vehicle]['phrases'] ?? []) : [];
        if ($phrases !== []) {
            $hits = [];
            foreach ($codes as $code) {
                $hit = ZoningConformance::lookupPhrases($code, $phrases);
                if ($hit !== null) {
                    $hits[$code] = $hit;
                }
            }
            $title = Ordinance::VEHICLE_USES[$vehicle]['label'];
        } else {
            $hits = $this->hits($principal['psic'], $codes, (string) ($principal['description'] ?? ''));
        }
        $hits = $this->withHomeAllowance($principal['psic'], $codes, $hits);
        // The answers the trade's reading turns on (a shop or a factory, the
        // kind of school), asked on the finding they decide.
        $decides = $phrases !== [] ? ['vehicle_use']
            : TradeUses::for((string) $principal['psic']->code, $this->ctx->facts, (string) ($principal['description'] ?? ''))['asks'];

        $certain = array_filter($hits, fn ($h) => $h['certain']);
        $possible = array_diff_key($hits, $certain);
        $this->listedIn = array_keys($certain);
        $this->principalListed = $certain !== [];

        if ($certain !== []) {
            $za = array_filter($certain, fn ($h) => $h['via'] === 'V-2.3-INH'
                || str_contains(mb_strtolower($h['use']), 'conditions deemed appropriate by the zoning administrator'));
            $unconditional = array_diff_key($certain, $za);
            $first = reset($certain);
            $rules = ['V-2'];
            foreach (array_keys($certain) as $code) {
                $rules[] = 'V-'.Ordinance::SECTION_FOR_CODE[$code].'-USES';
                $via = $certain[$code]['via'];
                if ($via !== null) {
                    $rules[] = $via;
                }
            }
            $home = array_filter($certain, fn ($h) => $h['basis'] === 'home') !== [];
            if ($home) {
                $rules[] = 'V-2.1-HO';
            }
            $where = $this->names(array_keys($certain));
            $reason = $home && count(array_filter($certain, fn ($h) => $h['basis'] !== 'home')) === 0
                ? "{$title} is allowed in {$where} as a business run from a home, on the conditions below."
                : "{$title} is allowed in {$where}: “".$this->clause($first['use']).'”.';
            $status = $unconditional !== [] ? 'met' : 'review';
            if ($unconditional === []) {
                $rules[] = array_filter($za, fn ($h) => $h['via'] === 'V-2.3-INH') !== []
                    ? 'V-2.3-INH' : 'V-2.3-RETAIL-ZA';
                $reason .= ' In Maximum Residential-2 it is allowed on conditions the Zoning Administrator sets.';
            }
            $reason .= $this->definitionNote($first['definition'] ?? null, $rules);
            $textOnly = $this->textOnlyNote(array_keys($certain), $rules);
            $reason .= $textOnly;
            $others = array_values(array_diff($codes, array_keys($certain)));
            if ($others !== [] && $this->lotZone === null) {
                $reason .= ' It is not on the list for '.$this->names($others).', so it matters which of these your lot is in.';
            }
            $this->add(array_values(array_unique($rules)), $status, $reason,
                ['group' => 'uses', 'title' => 'On the zones’ lists of allowed uses', 'question' => $textOnly !== '' ? 'C11' : null, 'asks' => $decides]);
        } elseif ($possible !== []) {
            $this->possiblyListed($title, $possible, $codes, $decides);
        } else {
            $this->notListed($principal['psic'], $codes, (string) ($principal['description'] ?? ''), $decides);
        }

        if (count($lines) > 1) {
            $others = [];
            foreach ($lines as $line) {
                if ($line['psic']->id === $principal['psic']->id) {
                    continue;
                }
                $h = array_filter($this->hits($line['psic'], $codes, (string) ($line['description'] ?? '')), fn ($hit) => $hit['certain']);
                $others[] = $line['psic']->title.($h === [] ? ' (not plainly on the list)' : ' (listed in '.$this->names(array_keys($h)).')');
            }
            $how = $basis !== null
                ? "Your principal line, by {$basis}, is {$title} (Art. IV §7 b.1)."
                : "No figure sets your lines apart, so CPDO decides which is principal — by impact, then by the area each takes (Art. IV §7 b.1-b.2). {$title} is checked above.";
            $status = $basis === null ? 'review' : ($this->principalListed ? 'met' : 'review');
            $this->add(['IV-7-a', 'IV-7-b1', 'IV-7-b2', 'IV-7-b3'], $status,
                $how.' Other lines: '.implode('; ', $others).'. A lot with several uses conforms if its principal use is allowed.',
                ['group' => 'uses']);
        }

        if (in_array('55900', $this->ctx->psicCodes(), true)) {
            $this->add('A-27', 'info', 'A dormitory, under the ordinance, boards ten or more people in common halls (Annex A 27); fewer is a boarding house.',
                ['group' => 'uses', 'audience' => 'officer']);
        }

        // A zone the ordinance leaves without a list of its own.
        if (in_array('CMP', $codes, true)) {
            $this->add('V-2.6-BP220', 'review',
                'The Socialized Housing zone allows what BP 220 allows; the ordinance lists nothing itself. CPDO judges a business there.',
                ['group' => 'uses', 'applies_in' => ['CMP'], 'question' => 'C22']);
        }
        if (in_array('MANGROVE', $codes, true)) {
            $this->add('V-2.15-USES', 'not_met',
                'Only mangrove planting is allowed in the Mangrove Zone, and no permanent building or structure. A business cannot operate there.',
                ['group' => 'uses', 'applies_in' => ['MANGROVE']]);
        }
    }

    /**
     * The trade may be on a zone's list but the list does not name it: a
     * "like:" list's example of a similar trade, a line that is this trade
     * only at some scale, or a code nobody has read against the lists yet.
     *
     * Never Met. Art. III §2(a) takes "and the like" to include similar uses,
     * and how similar is CPDO's call; Art. III §1 gives a term the ordinance
     * does not define its meaning in the national codes, and Annex A 89 sends
     * an undefined business type to the laws that define it. So the finding
     * names the line, says why it is only possible, and leaves it to CPDO.
     *
     * @param  array<string, array<string, mixed>>  $possible
     */
    private function possiblyListed(string $title, array $possible, array $codes, array $asks = []): void
    {
        $first = reset($possible);
        $rules = ['V-2', 'III-2-a', 'III-1', 'A-89'];
        foreach (array_keys($possible) as $code) {
            $rules[] = 'V-'.Ordinance::SECTION_FOR_CODE[$code].'-USES';
        }
        $why = $first['basis'] === 'unvetted'
            ? 'This trade has not been read against the lists yet, so the nearest wording is offered rather than a match.'
            : 'It may be the same trade, or may not — at a different scale, or for different goods.';
        $reason = "{$title} may fall under “".$this->clause($first['use']).'”, which '.$this->names(array_keys($possible))
            .(count($possible) > 1 ? ' list' : ' lists').". {$why} The lists take in similar uses (Art. III §2), and a term the ordinance does not define carries its meaning in the national codes (Art. III §1); CPDO decides whether this is one.";
        $reason .= $this->definitionNote($first['definition'] ?? null, $rules);
        $textOnly = $this->textOnlyNote(array_keys($possible), $rules);
        $reason .= $textOnly;
        $others = array_values(array_diff($codes, array_keys($possible)));
        if ($others !== [] && $this->lotZone === null) {
            $reason .= ' '.$this->names($others).' '.(count($others) > 1 ? 'do' : 'does').' not list anything like it.';
        }
        $this->add(array_values(array_unique($rules)), 'review', $reason,
            ['group' => 'uses', 'title' => 'Possibly on the zones’ lists', 'question' => $textOnly !== '' ? 'C11' : null, 'asks' => $asks]);
    }

    /**
     * The definition that decided how a trade was read, quoted for the
     * officer; its rule id is cited with the finding.
     *
     * @param  list<string>  $rules
     */
    private function definitionNote(?string $definition, array &$rules): string
    {
        if ($definition === null || ! isset(TradeUses::DEFINITIONS[$definition])) {
            return '';
        }
        $rules[] = $definition;

        return ' '.TradeUses::DEFINITIONS[$definition];
    }

    /**
     * Zones the text places in the barangay that the map sheet does not draw,
     * named on the finding that relies on them (Art. IV §6: the text
     * prevails), so the difference is never silent where it decides.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $rules
     */
    private function textOnlyNote(array $codes, array &$rules): string
    {
        $textOnly = array_values(array_filter($codes, function (string $code): bool {
            foreach ($this->zones as $zone) {
                if ($zone['code'] === $code) {
                    return $zone['source'] === 'text';
                }
            }

            return false;
        }));
        if ($textOnly === []) {
            return '';
        }
        $rules[] = 'IV-6-g';

        return ' The map sheet does not draw '.$this->names($textOnly).' in '.$this->ctx->barangay->name
            .'; Art. IV §5’s text places it here, and the text prevails (the City has been asked about the difference).';
    }

    /**
     * Art. V §2.1 allows a home occupation (a professional's office; a
     * dressmaker, tailor, baker or sari-sari store "and the like") and a home
     * industry (a cottage industry) in Residential-1 and every zone that takes
     * in its uses — as a business run from a home, on conditions.
     *
     * Those clauses are not matched as list lines (ZoningConformance::
     * matchable leaves them out); they are added here when the applicant says
     * the business is run from a house someone lives in, so the conditions
     * in homeBusiness() are what decide it.
     *
     * @param  array<string, array<string, mixed>>  $hits
     * @return array<string, array<string, mixed>>
     */
    private function withHomeAllowance(PsicCode $psic, array $codes, array $hits): array
    {
        if ($this->ctx->fact('home_based') !== true) {
            return $hits;
        }
        $code = (string) $psic->code;
        $kind = match (true) {
            in_array($code, Ordinance::HOME_OCCUPATION['is'], true) => 'is',
            Ordinance::isManufacturing($code) => 'industry',
            in_array(substr($code, 0, 2), Ordinance::HOME_OCCUPATION['like_divisions'], true) => 'like',
            default => null,
        };
        if ($kind === null) {
            return $hits;
        }
        $line = $kind === 'industry' ? 'Home Industry classified as cottage industry' : 'Home occupation for the practice of one\'s profession';
        foreach ($codes as $zone) {
            if (! in_array('R-1', Ordinance::closure($zone), true) || ($hits[$zone]['certain'] ?? false)) {
                continue;
            }
            $hits[$zone] = [
                'use' => $line,
                'from' => 'R-1',
                'via' => Ordinance::inheritanceRule($zone, 'R-1'),
                // A professional's office or one of the named home businesses
                // is the clause itself; another small trade is "and the
                // like", which is CPDO's to judge.
                'certain' => $kind !== 'like',
                'basis' => $kind === 'like' ? 'similar' : 'home',
                'definition' => null,
            ];
        }

        return $hits;
    }

    /**
     * The trade is on no governing zone's list. Never "refused": the lists
     * are open (Art. III §2, Annex A 89). Three more specific reasons are
     * looked for first, because each changes what CPDO is asked.
     */
    private function notListed(PsicCode $psic, array $codes, string $description = '', array $asks = []): void
    {
        $title = $psic->title;
        $rules = ['V-2', 'III-2', 'III-2-a', 'A-89'];
        foreach ($codes as $code) {
            if (isset(Ordinance::SECTION_FOR_CODE[$code])) {
                $rules[] = 'V-'.Ordinance::SECTION_FOR_CODE[$code].'-USES';
            }
        }

        // Listed only in a zone the map draws and the text does not.
        $sheetHits = $this->lotZone === null ? $this->hits($psic, $this->sheetOnly, $description) : [];
        if ($sheetHits !== []) {
            $this->add(array_merge($rules, ['IV-6-g']), 'review',
                "{$title} is on the list for ".$this->names(array_keys($sheetHits)).', which the map sheet shows here but the text of Art. IV §5 does not. The text prevails; CPDO decides.',
                ['group' => 'uses', 'question' => 'C11', 'asks' => $asks]);

            return;
        }

        // The Basic R-3 gap: Maximum R-3 and C-1 inherit every residential
        // zone except Basic R-3, so a use listed only there is reachable from
        // nowhere that inherits.
        $skipping = array_values(array_filter($codes, fn ($c) => in_array($c, ['R-3-MAX', 'C-1', 'C-2', 'C-3', 'CBD'], true)));
        if ($skipping !== [] && ZoningConformance::matchUse($psic, ZoningConformance::matchable('R-3-BASIC'), $this->ctx->facts, $description) !== null) {
            $this->add(array_merge($rules, ['V-2.5-INH', 'V-2.7-INH']), 'review',
                "{$title} is listed for Basic Residential-3, but ".$this->names($skipping).' inherit every residential zone except Basic Residential-3. Whether that gap was meant is for the City to say; CPDO decides meanwhile.',
                ['group' => 'uses', 'question' => 'C18', 'asks' => $asks]);

            return;
        }

        if ($codes === ['FISHPOND'] || ($this->lotZone === 'FISHPOND')) {
            return; // reported by the Fishpond finding in overlaysGroup()
        }

        $this->add($rules, 'review',
            "{$title} is not on the list for ".($this->lotZone !== null ? $this->zoneName($this->lotZone) : 'any zone in '.$this->ctx->barangay->name).'. That is not a refusal: the ordinance reads its lists to include similar uses ("and the like", Art. III §2) and refers unlisted ones to other laws (Annex A 89). CPDO decides, and the Local Zoning Board of Appeals can grant an exception.',
            ['group' => 'uses', 'title' => 'On the zones’ lists of allowed uses', 'asks' => $asks]);

        if ($this->ctx->applicationType === 'new') {
            $this->add('IX-12-3', 'review',
                'A new business cannot rely on the non-conforming rules, which protect only uses that existed before the ordinance: if CPDO finds the trade does not fit the zone, the way forward is an exception from the Local Zoning Board of Appeals.',
                ['group' => 'uses']);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  Home occupation and home industry (Art. V §2.1; Annex A 42)
    // ────────────────────────────────────────────────────────────────────

    private function homeBusiness(): void
    {
        $residential = array_values(array_intersect($this->candidates(), ['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX']));
        if ($residential === []) {
            return;
        }
        $home = $this->ctx->fact('home_based');
        if ($home === null) {
            $this->add('V-2.1-HO', 'review',
                'In a residential zone a business run from a home is allowed as a home occupation, on conditions. Say whether yours is.',
                ['group' => 'home', 'asks' => ['home_based'], 'applies_in' => $residential]);

            return;
        }
        if ($home !== true) {
            return;
        }

        $manufacturing = array_filter($this->ctx->psicCodes(), fn ($c) => Ordinance::isManufacturing($c)) !== [];
        $opts = fn (array $asks) => ['group' => 'home', 'asks' => $asks, 'applies_in' => $residential];
        $share = $this->homeShare();

        if ($manufacturing) {
            $this->add('V-2.1-HI', 'info',
                'Making goods at home is a home industry (cottage industry): allowed in residential zones on four conditions, below.',
                $opts(['home_based']));

            // HI-1: 30% of the floor area, no outside change, no nuisance.
            [$status, $reason] = match (true) {
                $share === null => ['review', $this->ctx->floorArea === null
                    ? 'Give the floor area your business uses (Business Operation) and the house’s floor area to check the 30% limit.'
                    : 'Give the floor area of the whole house to check the 30% limit.'],
                $share > 30.0 => ['not_met', sprintf('The business uses %.0f%% of the house’s floor area; a home industry may use at most 30%%.', $share)],
                $this->ctx->fact('house_altered') === 'outside' => ['not_met', 'The outside of the house would change; a home industry may not change it.'],
                $this->ctx->fact('nuisance_equipment') === true => ['not_met', 'Equipment noticeable outside would make it a nuisance, which a home industry may not be.'],
                default => ['met', sprintf('The business uses %.0f%% of the house’s floor area (limit 30%%).', $share)],
            };
            $this->add('V-2.1-HI-1', $status, $reason, $opts(['dwelling_floor_area_sqm', 'house_altered', 'nuisance_equipment']));

            $pollutive = $this->pollutive();
            $hazardous = $this->hazardous();
            [$status, $reason] = match (true) {
                $pollutive === null || $hazardous === null => ['review', 'Say whether the work is pollutive or hazardous.'],
                $pollutive || $hazardous => ['not_met', 'A home industry must be non-pollutive and non-hazardous.'],
                default => ['met', 'Non-pollutive and non-hazardous.'],
            };
            $this->add('V-2.1-HI-2', $status, $reason, $opts(['industry_pollutive', 'industry_hazardous']));

            $capital = $this->ctx->capitalization;
            [$status, $reason] = match (true) {
                $capital === null => ['review', 'Give your capital to compare with the cottage-industry ceiling.'],
                $capital <= 100000 => ['met', sprintf('Capital of ₱%s is within ₱100,000 (Annex A 23).', number_format($capital))],
                default => ['review', sprintf('Capital of ₱%s is above Annex A 23’s ₱100,000 for a cottage industry. §2.1 refers instead to "the capitalization as set by the DTI", which may be higher; CPDO decides which applies.', number_format($capital))],
            };
            $this->add(['V-2.1-HI-3', 'A-23'], $status, $reason, ['group' => 'home', 'applies_in' => $residential, 'question' => 'C19']);

            $this->add('V-2.1-HI-4', 'info',
                'The home-occupation rules on outbuildings, traffic and equipment apply too (below).',
                $opts([]));
        } else {
            $persons = $this->ctx->fact('persons_engaged');
            [$status, $reason] = match (true) {
                $persons === null => ['review', 'Say how many people work in the business, counting you.'],
                $persons > 5 => ['not_met', sprintf('%d people would work in it; the limit is five, counting the owner.', $persons)],
                default => ['met', sprintf('%d of the five allowed, counting the owner.', $persons)],
            };
            $this->add('V-2.1-HO-1', $status, $reason, $opts(['persons_engaged']));

            $altered = $this->ctx->fact('house_altered');
            [$status, $reason] = match ($altered) {
                null => ['review', 'Say whether the house will be altered for the business.'],
                'outside' => ['not_met', 'The outside of the house would change.'],
                default => ['met', 'The outside of the house stays as it is.'],
            };
            $this->add('V-2.1-HO-2', $status, $reason, $opts(['house_altered']));

            [$status, $reason] = match (true) {
                $share === null => ['review', $this->ctx->floorArea === null
                    ? 'Give the floor area your business uses (Business Operation) and the house’s floor area to check the 20% limit.'
                    : 'Give the floor area of the whole house to check the 20% limit.'],
                $share > 25.0 => ['not_met', sprintf('The business uses %.0f%% of the house; §2.1 allows 20%% and Annex A 42 a quarter.', $share)],
                $share > 20.0 => ['review', sprintf('The business uses %.0f%% of the house: over §2.1’s 20%%, within Annex A 42’s quarter. The two disagree; CPDO decides.', $share)],
                default => ['met', sprintf('The business uses %.0f%% of the house (limit 20%%).', $share)],
            };
            $this->add('V-2.1-HO-3', $status, $reason, $opts(['dwelling_floor_area_sqm']) + ['question' => 'C14']);
        }

        $accessory = $this->ctx->fact('in_accessory_structure');
        $this->add(['V-2.1-HO-4', 'V-2.1-ACC', 'III-1-ACCESSORY'], ...$this->yesNo($accessory,
            'Run from a garage or other outbuilding, which may not be used for anything done for money.',
            'Run from the house itself, not an outbuilding.',
            'Say whether the business is in a garage or other outbuilding.'), ...[$opts(['in_accessory_structure'])]);

        $street = $this->ctx->fact('parking_on_street');
        $this->add('V-2.1-HO-5', ...$this->yesNo($street,
            'Customers or vehicles would park on the street, sidewalk or front yard; a home business must park off the street and not in the front yard.',
            'Parking stays off the street and out of the front yard. CPDO judges whether the traffic suits a residential street.',
            'Say whether customers or vehicles will park on the street.'), ...[$opts(['parking_on_street'])]);

        $nuisance = $this->ctx->fact('nuisance_equipment');
        $this->add('V-2.1-HO-6', ...$this->yesNo($nuisance,
            'Equipment would make noise, fumes, glare or interference noticeable outside.',
            'Nothing noticeable outside the premises.',
            'Say whether any equipment makes noise, fumes or interference noticeable outside.'), ...[$opts(['nuisance_equipment'])]);

        if (! $manufacturing) {
            // Annex A 42 is stricter than §2.1 on three counts; each is
            // reported against both, so neither definition wins by default.
            $issues = [];
            if ($this->ctx->fact('non_resident_workers') === true) {
                $issues[] = 'someone who does not live in the house works in it (Annex A 42 allows no non-resident employee; §2.1 allows up to five people)';
            }
            if ($this->ctx->fact('non_household_equipment') === true) {
                $issues[] = 'it uses machines beyond household appliances (Annex A 42 does not allow them)';
            }
            if ($altered === 'inside') {
                $issues[] = 'the inside of the house would be altered (Annex A 42 allows no alteration; §2.1 speaks only of the outside)';
            }
            $answered = $this->ctx->fact('non_resident_workers') !== null && $this->ctx->fact('non_household_equipment') !== null;
            [$status, $reason] = match (true) {
                $issues !== [] => ['review', 'Annex A 42 defines a home occupation more strictly than §2.1, and here they part: '.implode('; ', $issues).'. CPDO decides which definition governs.'],
                ! $answered => ['review', 'Annex A 42 adds conditions §2.1 does not have. Answer the two questions to check them.'],
                default => ['met', 'Also within Annex A 42’s stricter definition: no non-resident workers, household equipment only.'],
            };
            $this->add('A-42', $status, $reason, $opts(['non_resident_workers', 'non_household_equipment']) + ['question' => 'C14', 'group' => 'home']);
        }
    }

    /** Business floor area as a share of the dwelling's, or null. */
    private function homeShare(): ?float
    {
        $dwelling = $this->ctx->fact('dwelling_floor_area_sqm');
        $floor = $this->ctx->floorArea;
        if (! is_numeric($dwelling) || (float) $dwelling <= 0 || $floor === null) {
            return null;
        }

        return round($floor / (float) $dwelling * 100, 1);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Definitions that carry a rule (Art. III §1; Annex A)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Most of the ordinance's definitions only say what a word means. These
     * say what a thing may not be or do, and each decides a filing:
     *
     *  - Art. III §1, apartment building: three or more families. A lessor of
     *    a duplex is in Residential-1's list; of an apartment building, not.
     *  - Annex A 28 with Industrial-2's list: dry cleaning with flammable
     *    solvents is a dry-cleaning plant, which only Industrial-2 lists.
     *  - Annex A 38: a funeral chapel at a cemetery shows or sells no coffins
     *    or wreaths.
     *  - Annex A 44: a hotel has no cooking in its rooms; with it, it is a
     *    hotel apartment (item 45).
     *  - Annex A 64: an office building holds no retail merchandising.
     *  - Art. V §2.1: a pet house is at most 4.00 sq. m., and like every
     *    accessory use it is never used for gain.
     *  - Art. V §2.20: research facilities, except nuclear, radioactive,
     *    chemical and biological warfare facilities.
     *
     * Annex A 72 (no vulcanizing or repair in a parking building) is with the
     * parking rules, in rentableParking().
     */
    private function definitions(): void
    {
        $codes = $this->ctx->psicCodes();
        $titles = $this->ctx->titles();

        if (in_array('68100', $codes, true)) {
            $what = $this->ctx->fact('leases_what');
            $families = $this->ctx->fact('families_in_building');
            [$status, $reason, $asks] = match (true) {
                $what === null => ['review', 'Say what you lease out: an apartment building is one for three or more families (Art. III §1), and the zones list houses, duplexes and apartments differently.', ['leases_what']],
                $what === 'commercial' => ['review', 'Leasing stalls or commercial space: no zone lists it as such. CPDO judges it by what the tenants do there.', ['leases_what']],
                ! is_numeric($families) => ['review', 'Say how many families can live in the building: three or more make it an apartment building (Art. III §1).', ['leases_what', 'families_in_building']],
                (float) $families >= 3 => ['met', sprintf('%d families: an apartment building (Art. III §1), which Basic Residential-2 and the zones above it list.', $families), ['leases_what', 'families_in_building']],
                default => ['met', sprintf('%d %s: a single-detached or duplex house (Art. III §1), which Residential-1 lists.', $families, (float) $families === 1.0 ? 'family' : 'families'), ['leases_what', 'families_in_building']],
            };
            $this->add('III-1-APT', $status, $reason, ['group' => 'uses', 'asks' => $asks]);
        }

        if (array_filter($codes, fn ($c) => Ordinance::is('laundry', $c)) !== []) {
            $solvents = $this->ctx->fact('flammable_solvents');
            $industrial2 = in_array('I-2', $this->candidates(), true);
            [$status, $reason] = match (true) {
                $solvents === null => ['review', 'Say whether dry cleaning will use flammable solvents: a dry-cleaning plant using them belongs in Industrial-2.'],
                $solvents === false => ['met', 'No flammable solvents: a laundry, which the commercial zones and Maximum R-2 list.'],
                $industrial2 => ['review', 'Dry cleaning with flammable solvents is a "dry cleaning plant using flammable liquids" (Annex A 28; Art. V §2.13), which only Industrial-2 lists. CPDO checks the lot is in Industrial-2.'],
                default => ['not_met', 'Dry cleaning with flammable solvents is a "dry cleaning plant using flammable liquids" (Annex A 28; Art. V §2.13), which only Industrial-2 lists, and no zone here is Industrial-2.'],
            };
            $this->add('A-28', $status, $reason, ['group' => 'uses', 'asks' => ['flammable_solvents']]);
        }

        if (array_filter($codes, fn ($c) => Ordinance::is('funeral', $c)) !== []) {
            $this->add('A-38', ...$this->yesNo($this->ctx->fact('sells_coffins'),
                'A funeral chapel at a cemetery is a fraternal chapel (Annex A 38): coffins and flower wreaths are not displayed or sold there, though dedicated wreaths may be.',
                'No coffins or wreaths shown or sold at the chapel.',
                'Say whether coffins or flower wreaths will be displayed or sold at the chapel.'),
                ...[['group' => 'uses', 'asks' => ['sells_coffins'], 'applies_in' => ['CEMETERY']]]);
        }

        if (in_array('55101', $codes, true)) {
            $kitchens = $this->ctx->fact('rooms_have_kitchens');
            [$status, $reason] = match ($kitchens) {
                null => ['review', 'Say whether the rooms have their own kitchens: a hotel has none (Annex A 44), and one whose rooms do is a hotel apartment.'],
                true => ['info', 'Rooms with their own cooking make this a hotel apartment or apartel (Annex A 44-45), and the use finding reads it as one. The zones that list hotels list those too.'],
                default => ['met', 'No cooking in the rooms: a hotel as Annex A 44 defines it.'],
            };
            $this->add('A-44', $status, $reason, ['group' => 'uses', 'asks' => ['rooms_have_kitchens']]);
        }

        // Retail merchandising (PSIC 47, not online or door-to-door).
        $retail = array_filter($codes, fn ($c) => str_starts_with($c, '47') && ! in_array($c, ['47912', '47990'], true)) !== [];
        if ($retail) {
            $this->add('A-64', ...$this->yesNo($this->ctx->fact('in_office_building'),
                'An office building, as Annex A 64 defines it, houses offices and professional services, not retail merchandising. A shop belongs in a commercial building.',
                'Not in an office building.',
                'Say whether the shop is inside an office building: Annex A 64 keeps retail out of one.'),
                ...[['group' => 'uses', 'asks' => ['in_office_building']]]);
        }

        // Pet houses (Art. V §2.1): for a pet business run from a home.
        $pets = array_filter($codes, fn ($c) => in_array($c, ['47760', '75000'], true)) !== []
            || preg_grep('/\b(?:pet|pets|kennel|grooming|boarding\s+for\s+(?:dogs|cats))\b/i', $titles) !== [];
        if ($pets && $this->ctx->fact('home_based') === true) {
            $area = $this->ctx->fact('pet_house_area_sqm');
            $residential = array_values(array_intersect($this->candidates(), ['R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX']));
            [$status, $reason] = match (true) {
                ! is_numeric($area) => ['review', 'Give the floor area of any pet house or kennel on the lot (0 if none): Residential-1 allows pet houses of at most 4.00 sq. m.'],
                (float) $area > 4.0 => ['not_met', sprintf('A %s sq. m. pet house is larger than the 4.00 sq. m. Residential-1 allows, and an accessory structure may not be used for the business.', rtrim(rtrim(number_format((float) $area, 2), '0'), '.'))],
                (float) $area > 0 => ['review', 'Within 4.00 sq. m. A pet house is an accessory use, kept for the household’s own pets: it may not house animals for the business.'],
                default => ['met', 'No pet house on the lot.'],
            };
            $this->add(['V-2.1-PET', 'V-2.1-ACC'], $status, $reason,
                ['group' => 'home', 'asks' => ['pet_house_area_sqm'], 'applies_in' => $residential]);
        }

        // Research facilities (Art. V §2.20).
        $research = array_filter($codes, fn ($c) => str_starts_with($c, '72')) !== []
            || preg_grep('/\bresearch\b/i', $titles) !== [];
        if ($research) {
            $this->add('V-2.20-RESEARCH', ...$this->yesNo($this->ctx->fact('warfare_research'),
                'The Institutional zone allows research facilities "except nuclear, radioactive, chemical and biological warfare facilities".',
                'Not a nuclear, radioactive, chemical or biological warfare facility.',
                'Say whether the facility will work with nuclear, radioactive, chemical or biological warfare materials.'),
                ...[['group' => 'uses', 'asks' => ['warfare_research'], 'applies_in' => ['INSTITUTIONAL']]]);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  The "provided that" conditions on particular uses (Art. V §2.3-2.12)
    // ────────────────────────────────────────────────────────────────────

    private function provisos(): void
    {
        $codes = $this->ctx->psicCodes();
        $has = fn (string $trade) => array_filter($codes, fn ($c) => Ordinance::is($trade, $c)) !== [];
        // Uses no PSIC code names (junk shops, food parks, car washes,
        // container yards) are recognised in the line's title or the
        // applicant's own description of it.
        $titles = $this->ctx->titles();
        $clause = fn (string $pattern) => preg_grep($pattern, $titles) !== [];

        if ($has('small_eatery')) {
            $customer = $this->ctx->fact('customer_area');
            $street = $this->ctx->fact('street_for_customers');
            [$status, $reason] = match (true) {
                $customer === null || $street === null => ['review', 'Say whether customers have a waiting or dining area inside, and whether they will use the street.'],
                $street === true => ['not_met', 'Customers would wait or eat on the road, street or alley, which is prohibited.'],
                $customer === false => ['not_met', 'There is no waiting or dining area for customers inside.'],
                default => ['met', 'Customers have an area inside and keep off the street. CPDO may set other requirements.'],
            };
            $this->proviso('V-2.3-EAT', $status, $reason, ['R-2-MAX', 'R-3-MAX'], [], ['customer_area', 'street_for_customers']);
        }

        if ($has('water_refilling')) {
            $this->proviso('V-2.3-WRS', ...$this->yesNo($this->ctx->fact('delivery_parking'),
                'There is parking for the delivery vehicles on the premises.',
                'There is no parking for the delivery vehicles on the premises.',
                'Say whether there is parking for your delivery vehicles.', invert: true),
                ...[['R-2-MAX', 'R-3-MAX'], [], ['delivery_parking']]);
        }

        // Vehicles kept on the lot: a trade that is about vehicles, a line
        // the applicant describes as parking or a garage, or an answer
        // already given. The register has no code for a pay parking lot.
        $vehicles = $has('passenger_terminal') || $has('trucking') || $has('vehicle_rental') || $has('transport_support')
            || $clause('/\bpay\s*parking\b|\bparking\s*(?:lot|building)s?\b|\bgarage\b|\btaxi\b|\btnvs\b|\bgrab\b|\buber\b/i')
            || $this->vehicleUse() !== null;
        if ($vehicles) {
            $this->vehicleRules($has('passenger_terminal'), $has('trucking'));
        }

        if ($has('recreation') || $clause('/\b(?:swimming pool|basketball|badminton|resort)\b/i')) {
            // Maximum R-2 lists a gym flat (personal service shops); only a
            // recreation business it does NOT list is its conditional use.
            $flat = false;
            foreach ($this->ctx->lines as $line) {
                $hit = ZoningConformance::lookup('R-2-MAX', $line['psic'], $this->ctx->facts);
                $flat = $flat || ($hit !== null && $hit['certain'] && ! str_contains($hit['use'], 'operated for business purposes'));
            }
            $nuisance = $this->ctx->fact('nuisance_equipment');
            $street = $this->ctx->fact('parking_on_street');
            if (! $flat) {
                $this->proviso(['V-2.3-COND', 'V-2.1-REC'], 'review',
                    'A pool or court run as a business is a conditional use in Maximum Residential-2: CPDO sets the conditions — no nuisance, noisy equipment in a soundproof room, a 4 m buffer along the whole boundary, parking without backing onto the road, proper drainage.'
                    .($nuisance === true ? ' Your equipment is noticeable outside, so it must be in a soundproof room.' : '')
                    .($street === true ? ' Parking on the street does not meet the parking condition.' : ''),
                    ['R-2-MAX', 'R-3-MAX'], [], ['nuisance_equipment', 'parking_on_street']);
            }
            $this->proviso('V-2.7-REC', ...$this->parkingNbc(), ...[['C-1'], [], ['parking_on_street'], null, ['GENERAL-COMMERCIAL']]);
        }

        if ($has('warehouse') || $clause('/\bwarehous/i')) {
            $this->warehouse();
        }

        if ($has('restaurant')) {
            $this->proviso('V-2.7-RESTO', ...$this->parkingNbc(), ...[['C-1', 'C-2', 'C-3'], ['CBD'], ['parking_on_street'], null, ['GENERAL-COMMERCIAL']]);
        }
        if ($clause('/\bfood\s*parks?\b/i')) {
            $this->proviso('V-2.7-FOODPARK', ...$this->parkingNbc(), ...[['C-1', 'C-2', 'C-3'], ['CBD'], ['parking_on_street']]);
        }

        if ($has('betting')) {
            [$status, $reason] = $this->distance('distance_to_institution_m', 200, 'the nearest school, church, hospital or other institution');
            $this->proviso('V-2.7-LOTTO', $status, $reason, ['C-1', 'C-2', 'C-3'], ['CBD'], ['distance_to_institution_m'], null, ['GENERAL-COMMERCIAL']);
        }

        if ($has('auto_repair') || $clause('/\bcar\s*wash/i') || $this->ctx->fact('listed_use') === 'car_wash') {
            $street = $this->ctx->fact('parking_on_street');
            $facade = $this->ctx->fact('makeshift_facade');
            $trap = $this->ctx->fact('grease_trap');
            [$status, $reason] = match (true) {
                $street === true => ['not_met', 'Vehicles would park on the street or sidewalk; the shop needs its own parking and loading space.'],
                $facade === true => ['not_met', 'The façade may not be of makeshift materials.'],
                $trap === false => ['not_met', 'A grease trap, dirt stopper and proper waste disposal are required.'],
                $street === null || $facade === null || $trap === null => ['review', 'Answer the parking, façade and grease-trap questions.'],
                default => ['met', 'Off-street parking, a proper façade and a grease trap. CPDO also judges whether the street can take the traffic, and may set other conditions.'],
            };
            $this->proviso(['V-2.7-AUTO', 'V-2.7-CARWASH'], $status, $reason, ['C-1', 'C-2', 'C-3'], ['CBD'], ['parking_on_street', 'makeshift_facade', 'grease_trap'], null, ['GENERAL-COMMERCIAL']);
        }

        if ($has('fuel')) {
            $this->fillingStation();
        }

        if ($has('funeral')) {
            $category = $this->ctx->fact('funeral_category');
            [$status, $reason] = match ($category) {
                null => ['review', 'Say which category of funeral establishment this is.'],
                'I' => ['not_met', 'Commercial-1 allows only Category II and III funeral parlours; Category I is allowed from Commercial-2 up.'],
                default => ['met', "A Category {$category} funeral parlour is allowed in Commercial-1."],
            };
            $this->proviso('V-2.7-FUNERAL-CAT', $status, $reason, ['C-1'], [], ['funeral_category']);
            $this->proviso('V-2.7-FUNERAL', 'review',
                'In Commercial-1 a funeral parlour must adopt waste disposal and odour control, meet the City’s and DOH’s sanitary requirements and HLURB’s rules for funeral establishments, and any other condition CPDO sets.',
                ['C-1'], [], []);
        }

        if ($has('machine_shop')) {
            $this->machineShop('V-2.8-MACH');
        }
        if ($clause('/\bjunk\s*shop|\bjunkshop/i') || $this->ctx->fact('listed_use') === 'junk_shop') {
            $this->machineShop('V-2.8-JUNK');
        }

        if ($has('trucking') || $this->vehicleUse() === 'trucking_garage') {
            $within = $this->ctx->fact('operates_within_malabon');
            $this->proviso('V-2.10-HAUL', ...$this->yesNo($within,
                'The hauling business operates within Malabon.',
                'In General Commercial, hauling services are allowed only for a business operating within Malabon.',
                'Say whether the hauling business operates within Malabon.', invert: true),
                ...[['GENERAL-COMMERCIAL'], ['CBD'], ['operates_within_malabon'], null, ['C-2', 'C-3']]);

            $lot = $this->ctx->lotArea;
            $existing = $this->ctx->fact('existing_malabon_business');
            [$status, $reason] = match (true) {
                $lot !== null && $lot < 1000 => ['not_met', sprintf('A trucking garage in Industrial-1 needs at least 1,000 sq. m. for vehicles to back and turn inside; the lot is %s sq. m.', number_format($lot))],
                $existing === false => ['not_met', 'The owner of a trucking garage in Industrial-1 must already run a business in Malabon.'],
                $lot === null || $existing === null => ['review', 'Give the lot area, and say whether this serves a business you already run in Malabon.'],
                default => ['met', 'At least 1,000 sq. m., for an existing Malabon business.'],
            };
            $this->proviso('V-2.12-TRUCK', $status, $reason, ['I-1'], [], ['existing_malabon_business']);
        }

        if ($clause('/\bcontainer\s*yard/i')) {
            $lot = $this->ctx->lotArea;
            $layers = $this->ctx->fact('container_layers');
            [$status, $reason] = match (true) {
                $lot !== null && $lot < 10000 => ['not_met', sprintf('A container yard needs a lot of at least one hectare; the lot is %s sq. m.', number_format($lot))],
                is_numeric($layers) && $layers > 3 => ['not_met', 'Container vans may be stacked at most three high.'],
                $lot === null || $layers === null => ['review', 'Give the lot area and how high the vans will be stacked.'],
                default => ['met', 'At least one hectare, stacked three high or less.'],
            };
            $this->proviso('V-2.12-CONT', $status, $reason, ['I-1'], [], ['container_layers']);
        }

        // The same activity listed as commerce and as industry (C-14): the
        // class that governs turns on scale and process, which the
        // ordinance never defines.
        $dual = array_values(array_filter([
            in_array('31001', $codes, true) || $clause('/\bmattress|\bbox\s*beds?\b/i')
                ? 'wood and rattan furniture and box beds are Commercial-2 and General Commercial uses, and Industrial-2 pollutive, hazardous ones' : null,
            in_array('10711', $codes, true) || $clause('/\bbiscuit|\bdoughnut|\bhopia\b/i')
                ? 'biscuit, doughnut, hopia and other bakery factories are Commercial-2 and General Commercial uses, and Industrial-1 ones' : null,
            in_array('10799', $codes, true) || $clause('/\bice\s*plant|\bcold\s*storage|\bdry\s*ice\b/i')
                ? 'ice plants and cold storage are Industrial-1 (non-pollutive) and Industrial-2 (pollutive) uses, ice-making is Commercial-2, and dry ice, which Commercial-2 excludes, is Industrial-1' : null,
            $clause('/\binsignia|\bbadges?\b/i')
                ? 'insignia and badges are Commercial-1 and General Commercial uses, and Industrial-1 ones' : null,
        ]));
        if ($dual !== []) {
            $this->add(['V-2.8-USES', 'V-2.10-USES', 'V-2.12-USES', 'V-2.13-USES'], 'review',
                'The ordinance lists the same activity in more than one class: '.implode('; ', $dual).'. Which governs turns on scale and process, which the ordinance does not define; the City has been asked, and CPDO decides.',
                ['group' => 'conflict', 'question' => 'C32', 'title' => 'Listed both as commerce and as industry',
                    'applies_in' => ['C-1', 'C-2', 'C-3', 'GENERAL-COMMERCIAL', 'CBD', 'I-1', 'I-2']]);
        }

        // I-1 takes non-pollutive industry only; I-2 takes pollutive.
        $industrial = array_filter($codes, fn ($c) => Ordinance::isManufacturing($c) || Ordinance::is('warehouse', $c)) !== [];
        if ($industrial && array_intersect($this->candidates(), ['I-1', 'I-2']) !== []) {
            $pollutive = $this->pollutive();
            [$status, $reason] = match ($pollutive) {
                null => ['review', 'Say whether the work is pollutive: Industrial-1 takes only non-pollutive industry.'],
                true => ['not_met', 'Industrial-1 takes only non-pollutive industry; a pollutive one belongs in Industrial-2.'],
                default => ['met', 'Non-pollutive, which Industrial-1 allows.'],
            };
            $this->proviso(['V-2.12-CLASS', 'V-2.13-CLASS'], $status, $reason, ['I-1'], [], ['industry_pollutive', 'industry_hazardous']);
        }
    }

    /**
     * What vehicles on the lot are for: the applicant's answer; else a lessor
     * who leases parking; else what the line's own description plainly says
     * ("pay parking lot", "taxi garage"). The register has no code for a pay
     * parking lot, so an operator files under transport support or real
     * estate and the description is the only place it is said. An inferred
     * kind is still asked (vehicle_use), so the applicant can correct it.
     */
    private function vehicleUse(): ?string
    {
        $answer = $this->ctx->fact('vehicle_use');
        if (is_string($answer) && isset(Ordinance::VEHICLE_USES[$answer])) {
            return $answer;
        }
        if ($this->ctx->fact('leases_what') === 'parking') {
            return 'parking_lot';
        }
        $words = implode(' ', $this->ctx->descriptions());
        foreach ([
            'parking_building' => '/\bparking\s+building\b/',
            'parking_lot' => '/\bpay\s*parking\b|\bparking\s+(?:lot|area|space)s?\s+for\s+rent\b|\bparking\s+lot\b/',
            'taxi_garage' => '/\btaxi\b/',
            'ride_hailing_garage' => '/\b(?:grab|uber|tnvs|ride[- ]hailing)\b/',
            'tricycle_terminal' => '/\b(?:tricycle|pedicab)\s+terminal\b/',
            'trucking_garage' => '/\b(?:trucking|hauling)\b/',
        ] as $kind => $pattern) {
            if (preg_match($pattern, $words) === 1) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Terminals, garages and parking: the residential provisos (§2.3, §2.4)
     * and the CBD bus rule (§2.11), each by what the vehicles are for.
     *
     * These used to share one cap of two vehicles. The ordinance gives each
     * kind its own: a rentable parking lot has no cap on numbers (light
     * vehicles only, no motor pool); a garage keeps two ride-hailing units or
     * one taxi; parking for one's own business keeps two delivery vans or
     * trucks in Maximum R-2 and ONE four-wheeler or 2-ton van in Basic R-3.
     */
    private function vehicleRules(bool $terminalTrade, bool $trucking): void
    {
        $kind = $this->vehicleUse();
        if ($kind === null && $trucking) {
            return; // a trucking garage: V-2.10-HAUL and V-2.12-TRUCK, in provisos()
        }
        if ($kind === null && ! $terminalTrade) {
            $this->add(['V-2.3-PARK', 'V-2.3-GAR', 'V-2.4-PARK', 'V-2.4-GAR'], 'review',
                'Say what the vehicles kept on the lot are for. In a residential zone a pay parking lot, a garage for ride-hailing cars or a taxi, and parking for your own business each have their own limits.',
                ['group' => 'conditions', 'asks' => ['vehicle_use'], 'applies_in' => ['R-2-MAX', 'R-3-MAX', 'R-3-BASIC'],
                    'title' => 'What the vehicles are for']);

            return;
        }

        match ($kind ?? 'terminal') {
            'parking_lot', 'parking_building' => $this->rentableParking($kind),
            'ride_hailing_garage', 'taxi_garage' => $this->residentialGarage($kind),
            'business_parking' => $this->businessParking(),
            'tricycle_terminal', 'terminal' => $this->terminalRules($kind),
            default => null,
        };
    }

    /** Rentable parking: §2.3 and §2.4, and Annex A 72 for a parking building. */
    private function rentableParking(string $kind): void
    {
        $heavy = $this->ctx->fact('heavy_vehicles');
        $pool = $this->ctx->fact('motor_pool');
        $turn = $this->ctx->fact('onsite_maneuvering');

        $fails = array_values(array_filter([
            $heavy === true ? 'it may take light vehicles only' : null,
            $pool === true ? 'a motor pool is not allowed' : null,
        ]));
        [$status, $reason] = match (true) {
            $fails !== [] => ['not_met', 'A pay parking lot or building in Maximum Residential-2: '.implode('; ', $fails).'.'],
            $heavy === null || $pool === null => ['review', 'Say whether heavy vehicles will park there and whether it will be a motor pool.'],
            default => ['met', 'Light vehicles only, no motor pool. There is no limit on the number; CPDO judges whether the traffic is more than the area normally carries.'],
        };
        $this->proviso('V-2.3-PARK', $status, $reason, ['R-2-MAX', 'R-3-MAX'], [], ['vehicle_use', 'heavy_vehicles', 'motor_pool']);

        $fails[] = $turn === false ? 'vehicles must come and go without backing onto the road' : null;
        $fails = array_values(array_filter($fails));
        [$status, $reason] = match (true) {
            $fails !== [] => ['not_met', 'A pay parking lot or building in Basic Residential-3: '.implode('; ', $fails).'.'],
            $heavy === null || $pool === null || $turn === null => ['review', 'Say whether heavy vehicles will park there, whether it will be a motor pool, and whether vehicles can turn inside without backing onto the road.'],
            default => ['met', 'Light vehicles only, no motor pool, no backing onto the road. CPDO judges the traffic.'],
        };
        $this->proviso('V-2.4-PARK', $status, $reason, ['R-3-BASIC'], [], ['vehicle_use', 'heavy_vehicles', 'motor_pool', 'onsite_maneuvering']);

        if ($kind === 'parking_building') {
            $this->add('A-72', ...$this->yesNo($this->ctx->fact('parking_repair_services'),
                'Annex A 72 defines a parking building as one that may offer fuel, washing, greasing and cleaning, "except vulcanizing of tires and repair of vehicles". Those belong in an auto-repair or vulcanizing shop.',
                'No tire vulcanizing or vehicle repair, as Annex A 72 requires of a parking building.',
                'Say whether the parking building will also offer tire vulcanizing or vehicle repair.'),
                ...[['group' => 'conditions', 'asks' => ['parking_repair_services']]]);
        }
    }

    /** A residential garage for ride-hailing units (two) or a taxi (one). */
    private function residentialGarage(string $kind): void
    {
        $cap = $kind === 'taxi_garage' ? 1 : 2;
        $what = $kind === 'taxi_garage' ? 'one taxi' : 'two ride-hailing units';
        $count = $this->ctx->fact('garage_vehicle_count');
        [$status, $reason] = match (true) {
            $count === null => ['review', "Say how many vehicles the garage will keep; a residential garage may keep {$what}."],
            $count > $cap => ['not_met', sprintf('%d vehicles; a residential garage may keep %s.', $count, $what)],
            default => ['met', sprintf('%d, within the %s a residential garage may keep.', $count, $what)],
        };
        $this->proviso('V-2.3-GAR', $status, $reason, ['R-2-MAX', 'R-3-MAX'], [], ['vehicle_use', 'garage_vehicle_count']);
        $this->proviso('V-2.4-GAR', $status, $reason, ['R-3-BASIC'], [], ['vehicle_use', 'garage_vehicle_count']);
    }

    /**
     * Parking for one's own business: an existing Malabon business, the lot
     * owner also its owner, motor pooling not allowed — and two delivery
     * vans or trucks in Maximum R-2 with a two-way street and no backing,
     * against one four-wheeler or 2-ton van in Basic R-3.
     */
    private function businessParking(): void
    {
        $count = $this->ctx->fact('garage_vehicle_count');
        $heavy = $this->ctx->fact('heavy_vehicles');
        $pool = $this->ctx->fact('motor_pool');
        $twoWay = $this->ctx->fact('two_way_street');
        $turn = $this->ctx->fact('onsite_maneuvering');
        $existing = $this->ctx->fact('existing_malabon_business');
        $owner = $this->ctx->fact('lot_owner_is_business_owner');

        $common = array_values(array_filter([
            $existing === false ? 'it must serve a business already operating in Malabon' : null,
            $owner === false ? 'the owner of the lot must also own the business it serves' : null,
            $pool === true ? 'motor pooling is not allowed' : null,
        ]));

        $fails = array_merge($common, array_values(array_filter([
            is_numeric($count) && $count > 2 ? sprintf('%d vehicles is more than the two delivery vans or trucks allowed', $count) : null,
            $heavy === true ? 'container vans, tractor heads and trailer trucks are not allowed' : null,
            $twoWay === false ? 'the street to it must take two-way traffic' : null,
            $turn === false ? 'vehicles must come and go without backing onto the road' : null,
        ])));
        $asks = ['vehicle_use', 'existing_malabon_business', 'lot_owner_is_business_owner', 'garage_vehicle_count', 'heavy_vehicles', 'motor_pool', 'two_way_street', 'onsite_maneuvering'];
        $missing = array_filter($asks, fn ($k) => $this->ctx->fact($k) === null) !== [];
        [$status, $reason] = match (true) {
            $fails !== [] => ['not_met', ucfirst(implode('; ', $fails)).'.'],
            $missing => ['review', 'Answer the questions about the business, the lot’s owner, the vehicles and the street to check the Maximum Residential-2 conditions.'],
            default => ['met', 'An existing Malabon business on its owner’s lot, two vans or trucks at most, no motor pool, a two-way street and no backing onto it.'],
        };
        $this->proviso('V-2.3-GAR', $status, $reason, ['R-2-MAX', 'R-3-MAX'], [], $asks);

        $fails = array_merge($common, array_values(array_filter([
            is_numeric($count) && $count > 1 ? sprintf('%d vehicles; Basic Residential-3 allows one four-wheeler or 2-ton van', $count) : null,
            $heavy === true ? 'only a four-wheeler or a 2-ton delivery, closed or refrigerated van is allowed' : null,
        ])));
        $asks = ['vehicle_use', 'existing_malabon_business', 'lot_owner_is_business_owner', 'garage_vehicle_count', 'heavy_vehicles', 'motor_pool'];
        $missing = array_filter($asks, fn ($k) => $this->ctx->fact($k) === null) !== [];
        [$status, $reason] = match (true) {
            $fails !== [] => ['not_met', ucfirst(implode('; ', $fails)).'.'],
            $missing => ['review', 'Answer the questions about the business, the lot’s owner and the vehicles to check the Basic Residential-3 conditions.'],
            default => ['met', 'An existing Malabon business on its owner’s lot, one four-wheeler or 2-ton van, no motor pool.'],
        };
        $this->proviso('V-2.4-GAR', $status, $reason, ['R-3-BASIC'], [], $asks);
    }

    /** Tricycle terminals in Maximum R-2 (§2.3); bus terminals in the CBD (§2.11). */
    private function terminalRules(?string $kind): void
    {
        $turn = $this->ctx->fact('onsite_maneuvering');
        $pool = $this->ctx->fact('motor_pool');
        [$status, $reason] = $this->yesNo($turn,
            'Vehicles turn inside the terminal without backing onto the road.',
            'Vehicles would have to back onto the road; a terminal must let them manoeuvre inside.',
            'Say whether vehicles can turn inside the lot.', invert: true);
        if ($kind !== 'terminal') {
            $this->proviso(['V-2.3-TRI'], $pool === true ? 'not_met' : $status,
                $pool === true ? 'A motor pool is not allowed at a tricycle or pedicab terminal.' : $reason.' CPDO judges whether the traffic suits the area.',
                ['R-2-MAX', 'R-3-MAX'], [], ['onsite_maneuvering', 'motor_pool']);
        }
        if ($kind !== 'tricycle_terminal') {
            $this->proviso('V-2.11-BUS', $status, $reason, ['CBD'], [], ['onsite_maneuvering']);
        }
    }

    private function warehouse(): void
    {
        $floor = $this->ctx->floorArea;
        $pollutive = $this->pollutive();
        $hazardous = $this->hazardous();
        $existing = $this->ctx->fact('existing_malabon_business');
        $street = $this->ctx->fact('parking_on_street');

        $fails = [];
        if ($floor !== null && $floor > 200) {
            $fails[] = sprintf('%s sq. m. is over the 200 sq. m. cap', number_format($floor));
        }
        if ($pollutive === true || $hazardous === true) {
            $fails[] = 'only non-pollutive, non-hazardous finished products may be stored';
        }
        if ($existing === false) {
            $fails[] = 'it must support a commercial activity already operating in the city';
        }
        if ($street === true) {
            $fails[] = 'the street and sidewalk may not be used for parking';
        }
        [$status, $reason] = match (true) {
            $fails !== [] => ['not_met', ucfirst(implode('; ', $fails)).'.'],
            $floor === null || $pollutive === null || $hazardous === null || $existing === null || $street === null => ['review', 'Answer the floor area, goods, parking and existing-business questions to check the Commercial-1 warehouse conditions.'],
            default => ['met', sprintf('%s sq. m. of safe finished goods for an existing Malabon business, parked off the street. CPDO checks the loading space against the NBC.', number_format($floor))],
        };
        $this->proviso('V-2.7-WH', $status, $reason, ['C-1', 'C-2', 'C-3'], ['CBD'],
            ['industry_pollutive', 'industry_hazardous', 'existing_malabon_business', 'parking_on_street'], null, ['GENERAL-COMMERCIAL']);

        if (in_array('I-2', $this->candidates(), true)) {
            $this->add(['V-2.13-USES', 'V-2.7-WH'], 'review',
                'Industrial-2’s pollutive, non-hazardous heading ends with a warehouse "for non-pollutive/non-hazardous industries" — Industrial-1’s line, repeated — and no line names a warehouse for pollutive, non-hazardous goods. The City has been asked whether one was meant; CPDO decides which line a warehouse here falls under.',
                ['group' => 'conflict', 'applies_in' => ['I-2'], 'question' => 'C33', 'title' => 'Industrial-2’s warehouse lines']);
        }

        if ($this->ctx->fact('open_storage') === null) {
            $this->add('A-70', 'review', 'Say whether goods will be kept under a roof with no walls (open storage), which has its own siting rule.',
                ['group' => 'conditions', 'asks' => ['open_storage']]);
        }
    }

    private function fillingStation(): void
    {
        $residential = array_values(array_intersect($this->candidates(), Ordinance::RESIDENTIAL));
        if ($residential !== []) {
            $this->proviso('V-2.7-GAS-RES', ...$this->yesNo($this->ctx->fact('hoa_consent'),
                'You have the written conformity of the homeowners’ association or barangay and the fire department.',
                'A filling station in a residential zone needs the written conformity of the homeowners’ association or barangay council and the local fire department.',
                'Say whether you have the homeowners’ or barangay’s written conformity.', invert: true),
                ...[$residential, [], ['hoa_consent']]);
        }

        [$status, $reason] = $this->distance('distance_to_fuel_station_m', 1000, 'the nearest existing gasoline or LPG station');
        $this->proviso('V-2.7-GAS-1KM', $status, $reason, ['C-1', 'C-2', 'C-3'], ['CBD'], ['distance_to_fuel_station_m'], 'C13', ['GENERAL-COMMERCIAL']);

        $ecc = $this->ctx->fact('has_ecc');
        $this->proviso('V-2.7-GAS-ECC', ...$this->yesNo($ecc,
            'You have the ECC, which a filling station needs before applying.',
            'A filling station must secure its ECC before applying for the locational clearance.',
            'Say whether you already have the ECC.', invert: true),
            ...[['C-1', 'C-2', 'C-3'], ['CBD'], ['has_ecc']]);

        $this->proviso(['V-2.7-GAS-DOE', 'V-2.7-GAS-SAFE', 'V-ZONE-AGENCY-GUIDELINES'], 'review',
            'It must meet Department of Energy standards, pose no hazard to a residential community, and have a buffer strip and firefighting equipment. CPDO checks these.',
            ['C-1', 'C-2', 'C-3'], ['CBD'], []);

        if ($residential !== []) {
            $this->add(['V-2.7-GAS-RES', 'V-2.10-USES'], 'review',
                '§2.7 lets a filling station into a residential zone with the written conformity of the homeowners’ association or barangay and the fire department, yet no residential zone lists filling stations; and General Commercial lists them with no condition at all. The City has been asked how these fit; CPDO decides meanwhile.',
                ['group' => 'conflict', 'applies_in' => $residential, 'question' => 'C13', 'title' => 'A residential clause for a use no residential zone lists']);
        }

        $this->add('V-3-C-CONFLICT', 'review',
            'The ordinance gives filling stations two siting rules: §2.7 says DOE standards and 1 km from any existing station; §3.C says Energy Regulatory Board standards and 200 m from schools, churches and hospitals. Both are checked here; the City has been asked which governs.',
            ['group' => 'conflict', 'question' => 'C13']);
    }

    private function machineShop(string $rule): void
    {
        $street = $this->ctx->fact('parking_on_street');
        $nuisance = $rule === 'V-2.8-MACH' ? $this->ctx->fact('nuisance_equipment') : false;
        $facade = $rule === 'V-2.8-MACH' ? $this->ctx->fact('makeshift_facade') : false;
        $fire = $this->ctx->fact('firewalls');
        $hours = $this->ctx->fact('barangay_hours');
        $fails = array_values(array_filter([
            $street === true ? 'parking must be off the street and outside the setback' : null,
            $nuisance === true ? 'no equipment may be a nuisance off the premises' : null,
            $facade === true ? 'the façade may not be of makeshift materials' : null,
            $fire === false ? 'firewalls are required' : null,
            $hours === false ? 'operating hours must be agreed with the barangay' : null,
        ]));
        $asks = $rule === 'V-2.8-MACH'
            ? ['parking_on_street', 'nuisance_equipment', 'makeshift_facade', 'firewalls', 'barangay_hours']
            : ['parking_on_street', 'firewalls', 'barangay_hours'];
        $missing = array_filter($asks, fn ($k) => $this->ctx->fact($k) === null) !== [];
        [$status, $reason] = match (true) {
            $fails !== [] => ['not_met', ucfirst(implode('; ', $fails)).'.'],
            $missing => ['review', 'Answer the parking, firewall and barangay-hours questions.'],
            default => ['met', 'Off-street parking, firewalls and agreed hours. CPDO judges the traffic and may set other conditions.'],
        };
        $this->proviso($rule, $status, $reason.($rule === 'V-2.8-MACH'
            ? ' (The clause reads "the facade be not made of makeshift materials with firewalls"; it is read as requiring firewalls, as the junk-shop clause says outright. The City has been asked to confirm.)'
            : ''), ['C-2', 'C-3'], ['CBD'], $asks, $rule === 'V-2.8-MACH' ? 'C40' : null, ['GENERAL-COMMERCIAL']);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Special uses (Art. V §3)
    // ────────────────────────────────────────────────────────────────────

    private function specialUses(): void
    {
        $use = $this->ctx->fact('special_use');
        if (! is_string($use) || ! isset(Ordinance::SPECIAL_USES[$use])) {
            $use = null;
            foreach (Ordinance::SPECIAL_USES as $key => $special) {
                if (array_intersect($special['psic'], $this->ctx->psicCodes()) !== []) {
                    $use = $key;
                    break;
                }
            }
        }
        if ($use === null && $this->ctx->fact('open_storage') === true) {
            $use = 'open_storage';
        }
        if ($use === null) {
            if ($this->ctx->lines !== []) {
                $this->add('V-3', 'info', 'Not one of the special uses that need a special use permit.',
                    ['group' => 'special', 'audience' => 'officer', 'officer_asks' => ['special_use']]);
            }

            return;
        }

        $label = Ordinance::SPECIAL_USES[$use]['label'];
        $this->add('V-3', 'review',
            "{$label} is a special use: it needs a special use permit, on the conditions below.",
            ['group' => 'special', 'officer_asks' => ['special_use'], 'question' => 'C24']);

        $opts = fn (array $asks = []) => ['group' => 'special', 'asks' => $asks];
        match ($use) {
            'cemetery' => $this->cemetery($opts),
            'funeral' => $this->add(['V-3-B', 'V-3-B-DOCS', 'V-ZONE-AGENCY-GUIDELINES'], 'review',
                'Bring: a 1:10,000 vicinity map showing every use within 500 m; a 1:200 site development plan; the title, or the contract of sale or lease with the survey plan; the City Health Office’s sanitary clearance under PD 856; a floor plan; and, before the building permit, an ECC (HLURB rules under EO 648).',
                $opts()),
            'filling_station' => $this->add('V-3-C-1', ...$this->distance('distance_to_institution_m', 200, 'the nearest school, church, hospital or similar institution'),
                ...[$opts(['distance_to_institution_m']) + ['question' => 'C13']]),
            'open_storage' => $this->add(['V-3-D-1', 'A-70'], ...$this->distance('distance_to_institution_m', 200, 'the nearest school, church, hospital or similar institution'),
                ...[$opts(['distance_to_institution_m'])]),
            'slaughterhouse' => $this->slaughterhouse($opts),
            'cockpit' => $this->cockpit($opts),
            'base_station' => $this->add('V-3-G', 'review',
                'A base station follows HLURB Resolution 626 and NTC/DOTC guidelines; the operator answers for its soundness; in a residential zone it is allowed only where public welfare demands and nothing is interfered with.',
                $opts()),
            'mrf' => $this->add('V-3-H-1', ...$this->yesNo($this->ctx->fact('cenro_recommended'),
                'CENRO has recommended the site.',
                'A materials recovery facility must be on a site CENRO recommends.',
                'Say whether CENRO has recommended the site.', invert: true), ...[$opts(['cenro_recommended'])]),
            'billboard' => $this->billboard($opts),
            'terminal' => $this->terminal($opts),
            default => null,
        };

        $extras = [
            'cemetery' => ['V-3-A-3', 'V-3-A-4-6', 'V-3-A-7', 'V-3-A-8-10'],
            'filling_station' => ['V-3-C-2-3'],
            'open_storage' => ['V-3-D-2'],
            'slaughterhouse' => ['V-3-E-0', 'V-3-E-2', 'V-3-E-7', 'V-3-E-9-11', 'V-2.13-SLAUGHTER'],
            'cockpit' => ['V-3-F-2-4'],
            'mrf' => ['V-3-H-2-5', 'VI-1-IRR'],
            'billboard' => ['V-3-I-2a', 'V-3-I-2b', 'V-3-I-2d-i', 'V-3-I-2k-l', 'V-3-I-2o-q', 'V-3-I-3-5'],
            'terminal' => ['V-3-J-2', 'V-3-J-3', 'V-3-J-5-7'],
        ][$use] ?? [];
        if ($extras !== []) {
            $this->add($extras, 'review', $use === 'mrf'
                ? 'Further conditions CPDO checks for this special use. Further safeguards are to be "recommended by the Local Zoning Committee subject to the Implementing Rules and Regulations", neither of which the ordinance creates or attaches; the City has been asked.'
                : 'Further conditions CPDO checks for this special use.',
                ['group' => 'special', 'audience' => 'officer', 'question' => $use === 'mrf' ? 'C24' : null]);
        }
    }

    private function cemetery(callable $opts): void
    {
        $this->add('V-3-A-1', ...$this->distance('distance_to_residence_m', 20, 'the nearest dwelling'), ...[$opts(['distance_to_residence_m'])]);
        $this->add('V-3-A-2', ...$this->distance('distance_to_water_m', 50, 'the nearest river or water supply'), ...[$opts(['distance_to_water_m'])]);
    }

    private function slaughterhouse(callable $opts): void
    {
        $home = $this->ctx->fact('distance_to_residence_m');
        $inst = $this->ctx->fact('distance_to_institution_m');
        [$status, $reason] = match (true) {
            is_numeric($home) && $home < 200 => ['not_met', sprintf('%s m from the nearest home; a slaughterhouse must be at least 200 m from residential areas.', number_format($home))],
            is_numeric($inst) && $inst < 200 => ['not_met', sprintf('%s m from the nearest school, church or public building; the minimum is 200 m.', number_format($inst))],
            $home === null || $inst === null => ['review', 'Give the distances to the nearest house and to the nearest school, church or public building.'],
            default => ['met', 'At least 200 m from homes, schools, churches and public buildings.'],
        };
        $this->add('V-3-E-1', $status, $reason, $opts(['distance_to_residence_m', 'distance_to_institution_m']));

        $market = $this->ctx->fact('distance_to_market_m');
        [$status, $reason] = match (true) {
            $market === null => ['review', 'Give the distance to the nearest market or food business.'],
            (float) $market <= 0 => ['not_met', 'A slaughterhouse may not share the premises of a public market.'],
            $market < 25 => ['not_met', sprintf('%s m from a market or food business; the minimum is 25 m.', number_format($market))],
            default => ['met', sprintf('%s m from the nearest market or food business (minimum 25 m).', number_format($market))],
        };
        $this->add(['V-3-E-3', 'V-3-E-4'], $status, $reason, $opts(['distance_to_market_m']));

        $this->add('V-3-E-5-6', ...$this->yesNo($this->ctx->fact('has_ecc'),
            'You have the ECC; waste and odour control are checked by CPDO.',
            'A slaughterhouse must secure an ECC.',
            'Say whether you already have the ECC.', invert: true), ...[$opts(['has_ecc'])]);
        $this->add('V-3-E-8', ...$this->yesNo($this->ctx->fact('neighbour_statements'),
            'You have sworn statements from the adjacent landowners.',
            'Sworn statements from the owners of the land immediately adjacent are a prerequisite of the special use permit.',
            'Say whether you have the adjacent landowners’ sworn statements.', invert: true), ...[$opts(['neighbour_statements'])]);
    }

    private function cockpit(callable $opts): void
    {
        $inParks = in_array('PARKS', $this->candidates(), true);
        $home = $this->ctx->fact('distance_to_residence_m');
        $inst = $this->ctx->fact('distance_to_institution_m');
        [$status, $reason] = match (true) {
            ! $inParks => ['not_met', 'A cockpit may be located only in a Parks and Recreation zone.'],
            is_numeric($home) && $home < 200 => ['not_met', 'A cockpit must be at least 200 m from the nearest residence.'],
            is_numeric($inst) && $inst < 200 => ['not_met', 'A cockpit must be at least 200 m from the nearest institution.'],
            $home === null || $inst === null => ['review', 'Give the distances to the nearest house and the nearest institution.'],
            default => ['met', 'In a Parks and Recreation zone, at least 200 m from homes and institutions.'],
        };
        $this->add('V-3-F-1', $status, $reason, $opts(['distance_to_residence_m', 'distance_to_institution_m']));
        $this->add(['V-3-F-1', 'V-2.17-USES', 'IV-5-PR-UTS'], 'review',
            'Art. V §3.F puts cockpits in Parks and Recreation zones, but that zone’s own list (§2.17) does not name cockpits, and §5 maps no Parks zone of its own — only existing parks, marked "follow base zone". The City has been asked where a cockpit may go; CPDO decides meanwhile.',
            ['group' => 'conflict', 'question' => 'C31', 'title' => 'Cockpits belong in a zone that does not list them']);
    }

    private function billboard(callable $opts): void
    {
        $this->add('V-3-I-1', ...$this->yesNo($this->ctx->fact('fronts_national_road'),
            'The lot fronts the National Road.',
            'Billboards may stand only on lots fronting the National Road.',
            'Say whether the lot fronts the National Road.', invert: true), ...[$opts(['fronts_national_road'])]);
        $this->add('V-3-I-2c', ...$this->distance('distance_to_billboard_m', 100, 'the nearest other billboard'), ...[$opts(['distance_to_billboard_m'])]);
        $this->add(['V-3-I-1', 'V-3-I-2m-n', 'V-3-I-2b', 'VII-13-LC'], 'review',
            '§3.I.1 allows billboards only on lots fronting the National Road, while §3.I(m) bars signs along road rights-of-way "whether it be National Road", and Art. VII §13 allows a billboard in any zone with a locational clearance. The setback table also skips roads 29-30, 24-25 and 19-20 m wide. The City has been asked; CPDO decides meanwhile.',
            ['group' => 'conflict', 'question' => 'C21', 'title' => 'Where a billboard may stand']);
    }

    private function terminal(callable $opts): void
    {
        $units = $this->ctx->fact('terminal_units');
        [$status, $reason] = match (true) {
            $units === null => ['review', 'Give the most vehicles at the terminal at one time.'],
            $units > 3 => ['review', sprintf('%d vehicles at once is a large terminal (over three buses, or over six jeepneys or taxis): it must be a reasonable distance from residential zones, which CPDO judges.', $units)],
            default => ['met', 'A small terminal; the distance rule for large ones does not apply.'],
        };
        $this->add('V-3-J-1', $status, $reason, $opts(['terminal_units']));
        $this->add('V-3-J-4', ...$this->yesNo($this->ctx->fact('onsite_maneuvering'),
            'Vehicles back and turn inside the compound.',
            'Vehicles must back and manoeuvre only inside the terminal compound.',
            'Say whether vehicles can turn inside the lot.', invert: true), ...[$opts(['onsite_maneuvering'])]);
        $this->add('V-3-J-8', 'review', 'Submit a site development plan at 1:500 with the application.', $opts());
    }

    // ────────────────────────────────────────────────────────────────────
    //  Overlays (Art. V §4) and the Fishpond zone (§2.16)
    // ────────────────────────────────────────────────────────────────────

    private function overlaysGroup(): void
    {
        $name = $this->ctx->barangay->name;
        $key = Ordinance::key($name);

        if (in_array('FLD-OZ', $this->overlays, true)) {
            $this->add(['V-4.1-USES', 'V-4.1-FP', 'V-4', 'IV-3', 'ANNEX-C'], 'info',
                "{$name} is in the Flood Overlay Zone. It changes no allowed use; a new building must be flood-proofed — ground floor above 0.5 m (low susceptibility), 1.0 m (moderate), 2.0 m (high) or 3.0 m (very high) per the CLUP 2018-2027 assessment.",
                ['group' => 'overlay']);
            if ($this->ctx->fact('new_construction') === true) {
                $this->add(['V-4.1-FP', 'III-1-BFE'], 'review',
                    'The ground-floor height is set from the base flood elevation, which Art. III defines as the DPWH regional office’s calculation while §4.1 takes it from the CLUP 2018-2027 assessment unless the DRRMO updates it; and the table’s classes share their edges (0.5, 1.0 and 2.0 m each fall in two classes). CPDO reads the site’s class off Annex C’s flood map. The City has been asked which source and which class edges govern.',
                    ['group' => 'conflict', 'question' => 'C27', 'title' => 'Which base flood elevation']);
            }
        }

        // Heritage: Annex C maps it in five barangays; Art. IV §5 marks it in
        // three more. Asked in all eight, and the three are told why.
        $heritage = in_array('HTG-OZ', $this->overlays, true);
        $textOnly = in_array($key, Ordinance::HERITAGE_TEXT_ONLY, true);
        if ($heritage || $textOnly) {
            if ($textOnly) {
                $this->add('IV-5-HTG', 'review',
                    "Art. IV §5 marks part of {$name} with the Heritage overlay; Annex C’s heritage maps do not include it. The City has been asked which is right.",
                    ['group' => 'overlay', 'question' => 'C7']);
            }
            $this->heritage();
        }

        if (in_array('ETM-OZ', $this->overlays, true)) {
            $this->ecotourism();
        }
    }

    private function heritage(): void
    {
        $house = $this->ctx->fact('heritage_house');
        if ($house === null) {
            $this->add('V-4.3-USES', 'review',
                'Part of this barangay is in the Heritage overlay, which governs declared heritage houses (houses of ancestry). Say whether the building is one.',
                ['group' => 'overlay', 'asks' => ['heritage_house']]);

            return;
        }
        if ($house === true) {
            $allowed = $this->heritageUse();
            $floor = $this->ctx->fact('business_floor');
            [$status, $reason] = match (true) {
                $allowed === false => ['not_met', 'A declared heritage house may hold only a residence, a museum, or a shop, office, restaurant, craftsman’s workshop or retail outlet on the ground floor.'],
                $floor === null => ['review', 'Say which floor the business is on: shops, offices and restaurants are allowed on the ground floor only.'],
                $floor === 'upper' => ['not_met', 'In a declared heritage house a shop, office or restaurant may be on the ground floor only.'],
                $allowed === null => ['review', 'On the ground floor. Whether the trade counts as a shop, office, restaurant or workshop is for CPDO.'],
                default => ['met', 'A use the heritage overlay allows, on the ground floor.'],
            };
            $this->add('V-4.3-USES', $status, $reason, ['group' => 'overlay', 'asks' => ['heritage_house', 'business_floor']]);

            $altered = $this->ctx->fact('house_altered');
            $this->add(['V-4.3-BULK', 'V-4.3-DESIGN', 'V-4.3-ARTIFACTS'], $altered === 'outside' ? 'not_met' : 'review',
                ($altered === 'outside' ? 'The outside would change; a declared heritage house keeps its height, floor area and original design. ' : '')
                .'Repairs keep the original design inside and out, and any business sign blends with the house’s period design.',
                ['group' => 'overlay', 'asks' => ['house_altered']]);

            return;
        }
        if ($this->ctx->fact('new_construction') === true) {
            $r1hit = $this->principal !== null ? ZoningConformance::lookup('R-1', $this->principal['psic'], $this->ctx->facts) : null;
            $r1 = $r1hit !== null && $r1hit['certain'];
            $this->add('V-4.3-NEWUSES', $r1 ? 'met' : 'review',
                $r1
                    ? 'New construction in the heritage overlay takes R-1 uses, and the trade is one.'
                    : 'New construction in the heritage overlay is limited to R-1 uses, even where the base zone is commercial, and the trade is not an R-1 use. Read literally this overrides the base zone; the City has been asked whether it means to.',
                ['group' => 'overlay', 'asks' => ['new_construction'], 'question' => 'C25']);

            // The buffer around a declared house: no higher than its roof
            // apex (NHCP 2012), and designed like it.
            $this->add('V-4.3-BHL', ...$this->yesNo($this->ctx->fact('above_heritage_apex'),
                'The new building would rise above the roof apex of the declared heritage house; buildings around it may not (Art. V §4.3, after NHCP’s 2012 standards).',
                'No higher than the roof apex of the declared heritage house.',
                'Say whether the new building will rise above the roof apex of the nearest declared heritage house.'),
                ...[['group' => 'overlay', 'asks' => ['above_heritage_apex']]]);
            $this->add('V-4.3-NEWDESIGN', ...$this->yesNo($this->ctx->fact('period_design'),
                'The building and its landscaping follow the period design of the declared heritage houses.',
                'New building and landscape designs in the heritage overlay must be made similar to the period design of the declared heritage houses.',
                'Say whether the new building’s design, landscaping included, will follow the period design of the heritage houses.', invert: true),
                ...[['group' => 'overlay', 'asks' => ['period_design']]]);
        }
    }

    /** true / false / null (CPDO decides) for the heritage house's use list. */
    private function heritageUse(): ?bool
    {
        $codes = $this->ctx->psicCodes();
        if ($codes === []) {
            return null;
        }
        foreach ($codes as $code) {
            $division = (int) substr($code, 0, 2);
            // Retail (47), restaurants (56), offices for professional and
            // financial services (62-75, 78-82), repair workshops (95), and
            // personal services (96) are shops, offices or workshops.
            if ($division === 47 || $division === 56 || ($division >= 62 && $division <= 75)
                || ($division >= 78 && $division <= 82) || $division === 95 || $division === 96) {
                continue;
            }
            if (Ordinance::isManufacturing($code) && in_array($code, ['14100', '32110', '31001'], true)) {
                continue; // tailoring, jewellery, furniture: a craftsman's workshop
            }
            if (Ordinance::isManufacturing($code) || in_array($division, [45, 46, 49, 52, 55], true)) {
                return false;
            }

            return null;
        }

        return true;
    }

    private function ecotourism(): void
    {
        $inside = $this->ctx->fact('in_fishpond_area');
        if ($inside === null) {
            $this->add('V-4.2-SCOPE', 'review',
                'Dampalit’s fishponds are the Fishpond Zone and the Eco-Tourism overlay. Say whether the lot is within them.',
                ['group' => 'overlay', 'asks' => ['in_fishpond_area']]);

            return;
        }
        if ($inside !== true) {
            return;
        }

        $this->add('V-2.16', 'review',
            'The Fishpond Zone section lists no allowable uses at all, so nothing can be said to be allowed or not by the base zone. The Eco-Tourism overlay adds the uses below; the City has been asked about the rest.',
            ['group' => 'uses', 'question' => 'C17']);

        $codes = $this->ctx->psicCodes();
        $tourism = array_filter($codes, fn ($c) => str_starts_with($c, '56') || in_array($c, ['47711', '47730', '47760', '47990', '64990', '77290', '93290'], true)) !== [];
        $this->add(['V-4.2-USES', 'V-4.2-SCOPE'], $tourism ? 'met' : 'review',
            $tourism
                ? 'Dining, water-recreation rentals and tourism retail are allowed in the eco-tourism area, and the trade is one of them.'
                : 'The eco-tourism area adds dining, water-recreation rentals and tourism retail (souvenirs, money changers, clothes). The trade is not plainly one of them; CPDO decides.',
            ['group' => 'overlay']);

        $floor = $this->ctx->floorArea;
        $lot = $this->ctx->lotArea;
        [$status, $reason] = match (true) {
            $floor === null || $lot === null || $lot <= 0 => ['review', 'Give the floor area your business uses and the lot area to check the 30% limit.'],
            $floor > 0.3 * $lot => ['not_met', sprintf('The business would use %.0f%% of the lot; at most 30%% is allowed.', $floor / $lot * 100)],
            default => ['met', sprintf('The business uses %.0f%% of the lot (limit 30%%).', $floor / $lot * 100)],
        };
        $this->add('V-4.2-AREA', $status, $reason.' Of the 30%, "10% of 30%" may be on land for parking and toilets — 3% of the lot, or 10% of it; the City has been asked which, and CPDO decides.',
            ['group' => 'overlay', 'question' => 'C38']);

        $storeys = $this->ctx->storeys;
        [$status, $reason] = match (true) {
            $storeys === null => ['review', 'The storey count is asked on CPDD’s sheet; eco-tourism facilities should be one storey.'],
            $storeys > 1 => ['not_met', sprintf('%d storeys; dining and eco-tourism facilities should be one storey.', $storeys)],
            default => ['met', 'One storey.'],
        };
        $this->add('V-4.2-STOREY', $status, $reason, ['group' => 'overlay']);
        $this->add('V-4.2-DESIGN', 'info',
            'Designs follow DOT standards; stilts are encouraged; appliances sit at least 1,000 mm above the floor; no impermeable paving outside the building and no firewalls on property lines.',
            ['group' => 'overlay']);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Waterways: the Easement Zone (Art. V §2.14; Art. IV §6)
    // ────────────────────────────────────────────────────────────────────

    private function waterways(): void
    {
        $beside = $this->ctx->fact('beside_waterway');
        $rules = ['V-2.14-3M', 'V-2.14-NOBLD', 'V-2.14-USES', 'IV-6-h'];
        if ($beside === null) {
            $this->add($rules, 'review',
                'Every river, creek and waterway keeps an easement free of buildings. Say whether your lot is beside one.',
                ['group' => 'site', 'asks' => ['beside_waterway']]);

            return;
        }
        if ($beside !== true) {
            $this->add($rules, 'met', 'Not beside a waterway, so the easement does not touch the lot.', ['group' => 'site', 'asks' => ['beside_waterway']]);

            return;
        }

        if (in_array('MANGROVE', $this->governing, true)) {
            $this->add(['V-2.15-USES', 'V-2.14-USES', 'IV-5'], 'review',
                'The Mangrove Zone here is written as "areas along easement of Malabon–Navotas, Chungkang, Batasan, Muzon Rivers" — the same banks the Easement Zone keeps a 3 m easement on. On those banks a lot may be Mangrove, where nothing may be built at all, rather than only clear of the easement. The City has been asked which governs; CPDO decides.',
                ['group' => 'conflict', 'question' => 'C35', 'title' => 'Riverbank: easement or Mangrove Zone']);
        }

        $name = $this->ctx->fact('waterway_name');
        $setback = $this->ctx->fact('waterway_setback_m');
        $dampalit = Ordinance::key($this->ctx->barangay->name) === 'dampalit';
        $camanava = $name === 'malabon_navotas' || $dampalit;
        [$status, $reason] = match (true) {
            $setback === null || $name === null => ['review', 'Say which waterway, and how far the building is from its edge.'],
            $setback < 3 => ['not_met', sprintf('The building is %s m from the waterway; no structure may stand within the 3 m easement.', $setback)],
            $camanava && $setback < 7.5 => ['review', sprintf('%s m clears the 3 m easement, but parts of the Malabon–Navotas River’s left bank and Dampalit’s polder dike need 7.5 m for the CAMANAVA flood-control works. Whether this stretch is one of them is for CPDO.', $setback)],
            default => ['met', sprintf('The building is %s m from the waterway, clear of the easement.', $setback)],
        };
        $this->add(array_merge($rules, $camanava ? ['V-2.14-7.5M'] : []), $status, $reason,
            ['group' => 'site', 'asks' => ['beside_waterway', 'waterway_name', 'waterway_setback_m'], 'question' => $camanava ? 'C23' : null]);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Signs (Art. VII §§5, 13; Art. V §3.I)
    // ────────────────────────────────────────────────────────────────────

    private function signs(): void
    {
        $sign = $this->ctx->fact('has_sign');
        if ($sign === null) {
            $this->add('VII-13-LC', 'review', 'A business sign needs CPDO’s clearance. Say whether you will put one up.',
                ['group' => 'signs', 'asks' => ['has_sign']]);

            return;
        }
        if ($sign !== true) {
            return;
        }
        $this->add(['VII-13-LC', 'VII-5'], 'review',
            'Your sign needs CPDO’s clearance: it must suit the zone, not be oversized for the building, and block no scenic view. Tell CPDO its size and where it goes.',
            ['group' => 'signs', 'asks' => ['has_sign']]);

        $this->add(['VII-13-NUISANCE', 'V-3-I-2m-n'], ...$this->yesNo($this->ctx->fact('sign_over_public'),
            'A sign may not hang over the sidewalk, street or other public property unless CPDO expressly allows it.',
            'The sign stays within the property.',
            'Say whether the sign will hang over the sidewalk or street.'), ...[['group' => 'signs', 'asks' => ['sign_over_public']]]);

        $roof = $this->ctx->fact('roof_sign');
        $this->add('V-3-I-2j', $roof === null ? 'review' : ($roof ? 'review' : 'met'),
            $roof === null ? 'Say whether the sign will stand on the roof.'
                : ($roof ? 'Art. V §3.I says "Roof Signs shall not be allowed". It sits in the billboard section, and whether it reaches an ordinary business sign is a question put to the City; CPDO decides meanwhile.' : 'Not a roof sign.'),
            ['group' => 'signs', 'asks' => ['roof_sign'], 'question' => 'C21']);

        $this->add(['V-3-I-2d-i', 'V-3-I-2o-q', 'V-3-I-2k-l', 'V-3-I-3-5'], 'info',
            'A sign may not block traffic signs, fire exits or required light and air; it uses incombustible or approved materials, stays clear of power lines, and is never on trees, posts or fences or across a carriageway.',
            ['group' => 'signs', 'audience' => 'officer']);

        $this->add(['VII-13-OBSOLETE', 'V-3-I-2r'], 'info',
            'Take the sign down or switch it off when PAGASA announces a low-pressure area, and remove it within 60 days if the business closes.',
            ['group' => 'signs']);

        if (in_array('HTG-OZ', $this->overlays, true) || $this->ctx->fact('heritage_house') === true) {
            $this->add('VII-13-1-3', 'info',
                'Near heritage buildings a sign may not block their view or project into the road leading to them.',
                ['group' => 'signs']);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  The site: parking, neighbours, roads, construction (Art. VI, VII)
    // ────────────────────────────────────────────────────────────────────

    private function site(): void
    {
        $street = $this->ctx->fact('parking_on_street');
        $this->add('VI-5-3', ...$this->yesNo($street,
            'Customers or vehicles would park on the street or sidewalk; parking may never encroach on the street right-of-way.',
            'Parking stays off the street.',
            'Say whether customers or your vehicles will park on the street or sidewalk.'),
            ...[['group' => 'site', 'asks' => ['parking_on_street']]]);

        // The homeowners' or barangay's approval (Art. VI §8): a use that
        // brings traffic or outsiders into a residential area.
        $residentialOnly = $this->listedIn !== [] && array_diff($this->listedIn, Ordinance::RESIDENTIAL) === [];
        $residential = array_values(array_intersect($this->candidates(), Ordinance::RESIDENTIAL));
        if ($residential !== [] && ($this->ctx->fact('home_based') === true || $residentialOnly
            || ($this->lotZone !== null && in_array($this->lotZone, Ordinance::RESIDENTIAL, true)))) {
            $this->add('VI-8-HOA', ...$this->yesNo($this->ctx->fact('hoa_consent'),
                'You have the approval of the homeowners’ association (or barangay) and the immediate neighbours.',
                'A business that brings traffic or outsiders into a residential area needs the prior approval of a majority of the homeowners’ association’s household heads — or the barangay, if there is none — especially the immediate neighbours.',
                'Say whether you have the homeowners’ association’s or barangay’s written approval.', invert: true),
                ...[['group' => 'site', 'asks' => ['hoa_consent'], 'applies_in' => $residential]]);
        }

        // Road-widening setbacks on seven named roads.
        foreach (Ordinance::streetKeys($this->ctx->street) as $key) {
            if (isset(Ordinance::ROAD_WIDENING[$key])) {
                $m = Ordinance::ROAD_WIDENING[$key];
                if ($key === 'sanciangco') {
                    // "three (3) meters both sides of Gov. W. Pascual Avenue. and
                    // Sanciangco St., Gen. Borromeo Street, … would require
                    // setback of one (1) meter": the stray full stop puts
                    // Sanciangco in either group.
                    $this->add('VI-8-ROAD', 'review',
                        'Sanciangco St. is set aside for widening, but the sentence can be read to give it the 3 m setback of Gov. Pascual Ave. or the 1 m of the roads after it. CPDO checks the building line; the City has been asked which.',
                        ['group' => 'site', 'question' => 'C39']);

                    continue;
                }
                $this->add('VI-8-ROAD', 'review',
                    sprintf('%s is set aside for widening: a locational clearance there requires a %s m setback on both sides. CPDO checks the building line.', Ordinance::STREETS[$key]['label'], rtrim(rtrim(number_format($m, 1), '0'), '.')),
                    ['group' => 'site']);
            }
        }

        // Traffic generators: on-site parking and loading, a 5 m parking
        // setback, a traffic statement, a drainage study.
        $codes = $this->ctx->psicCodes();
        $traffic = array_filter($codes, fn ($c) => Ordinance::is('mall', $c) || Ordinance::is('market', $c)
            || Ordinance::is('school', $c) || Ordinance::is('passenger_terminal', $c) || Ordinance::is('trucking', $c)
            || Ordinance::is('hospital', $c) || $c === '59140') !== [];
        $tall = $this->ctx->storeys !== null && $this->ctx->storeys >= 4
            && array_filter($codes, fn ($c) => in_array(substr($c, 0, 3), ['551', '681'], true)) !== [];
        if ($traffic || $tall) {
            $this->add(['VII-4', 'VI-5-4', 'VI-6-2', 'VI-6-1', 'VI-6-0'], 'review',
                'This draws traffic: it needs on-site parking with a 5 m setback, loading bays so street traffic is not impeded, a Traffic Impact Statement, and a Drainage Impact Assessment signed by a licensed engineer or planner.',
                ['group' => 'site']);
        }
        if (array_filter($codes, fn ($c) => Ordinance::is('mall', $c)) !== []) {
            $this->add('IX-4', 'info', 'CPDO may seek HLURB’s or consultants’ help in evaluating a mall.',
                ['group' => 'site', 'audience' => 'officer']);
        }

        // New construction: the building rules this clearance comes before.
        $new = $this->ctx->fact('new_construction');
        if ($new === null) {
            $this->add('V-1', 'review', 'Say whether you will build a new structure for the business; several building rules then apply.',
                ['group' => 'site', 'asks' => ['new_construction']]);
        } elseif ($new === true) {
            $this->add(['V-1', 'IX-7', 'VI-6-1', 'V-4.1-FP', 'VI-2-1', 'VI-2-3-6', 'VI-2-9-10', 'VI-5-1', 'VI-5-8', 'VII-8', 'VI-4-0', 'VI-5-0'], 'review',
                'Your building permit will need this clearance first. The new building must be flood-proofed, keep natural drainage, have street and fire access, see-through road fences, and stay within the zone’s height limit; a Drainage Impact Assessment is required in a flood-prone area.',
                ['group' => 'site', 'asks' => ['new_construction']]);
            $abuts = $this->ctx->fact('abuts_neighbour');
            $consent = $this->ctx->fact('neighbour_consent');
            [$status, $reason] = match (true) {
                $abuts === null => ['review', 'Say whether the new structure will be built against a neighbour’s property line.'],
                $abuts === false => ['met', 'Not built against a neighbour’s property.'],
                $consent === true => ['met', 'You have the neighbour’s written consent.'],
                $consent === false => ['not_met', 'Building against a neighbour’s property needs their prior written consent, which CPDO requires before the clearance.'],
                default => ['review', 'Say whether you have the neighbour’s written consent.'],
            };
            $this->add('VI-5-2', $status, $reason, ['group' => 'site', 'asks' => ['abuts_neighbour', 'neighbour_consent']]);

            $limits = [];
            foreach ($this->candidates() as $code) {
                if (isset(Ordinance::HEIGHT_LIMITS[$code])) {
                    $limits[] = $this->zoneName($code).' '.Ordinance::HEIGHT_LIMITS[$code].' m';
                }
            }
            $this->add(['VII-1-4', 'VII-1-1-3', 'VII-3', 'VII-6', 'VII-9'], 'info',
                'Height limits: '.implode('; ', $limits).'. Lower where a commercial or industrial lot adjoins a residential zone without a street between. Yards and parking: one building’s required yard or parking may not count for another; several principal buildings on one lot each meet the yards as if alone; a lot on a zone boundary takes the stricter zone’s yards.',
                ['group' => 'site', 'audience' => 'officer']);

            // Art. VII §1(1) and §1(2) give a Commercial-2 building beside
            // Residential-1 two different caps for the same narrow gap.
            $commercial = array_values(array_intersect($this->candidates(), ['C-2', 'C-3']));
            if ($commercial !== [] && array_intersect($this->governing, ['R-1', 'R-2-BASIC', 'R-2-MAX']) !== []) {
                $this->add('VII-1-1-3', 'review',
                    'Where a Commercial-2 lot adjoins Residential-1 with no street or open space between, Art. VII §1(1) caps the building facing it at 12 m or four storeys (when the gap is not over 6 m) and §1(2) at 9 m or three storeys (when it is not over 4 m). A gap of 4 m or less meets both, with different limits. The City has been asked which governs; CPDO decides meanwhile.',
                    ['group' => 'conflict', 'applies_in' => $commercial, 'question' => 'C29',
                        'title' => 'Two height caps beside Residential-1']);
            }
        }

        // Geological hazard by barangay soil (Art. VI §7).
        $key = Ordinance::key($this->ctx->barangay->name);
        foreach (Ordinance::GEOHAZARD as $soil => $hazard) {
            if (! in_array($key, $hazard['barangays'], true)) {
                continue;
            }
            $storeys = $this->ctx->storeys;
            $advice = "{$soil} ({$hazard['hazard']}): {$hazard['storeys']} storey(s) recommended";
            [$status, $reason] = match (true) {
                $storeys === null => ['info', "{$advice}; geotechnical and structural analysis is required above {$hazard['analysis_above']}."],
                $storeys > $hazard['analysis_above'] => ['review', "{$advice}. The building has {$storeys} storeys, so geotechnical and structural engineering analysis and design are required."],
                default => ['met', "{$advice}. The building has {$storeys}."],
            };
            // "Recommended" is not "shall" (Art. III §2(f)): advice reads as
            // information, and only the "REQUIRED" analysis can be wanting.
            $this->add(['VI-7', 'III-2-f'], $status, $reason, ['group' => 'site', 'audience' => $new === true ? 'both' : 'officer']);

            // The soil advice and the zone's height limit, side by side where
            // they are furthest apart: 1-4 or 1-2 storeys against 180 m.
            $tall = array_values(array_intersect($this->candidates(), ['C-3', 'CBD']));
            if ($new === true && $tall !== []) {
                $this->add(['VI-7', 'VII-1-4'], 'review',
                    "Art. VI §7 recommends {$hazard['storeys']} storey(s) on {$soil} here, while ".$this->names($tall).' allow buildings up to 180 m (Art. VII §1.4). The ordinance does not say how the two fit; the City has been asked, and CPDO decides with the geotechnical analysis.',
                    ['group' => 'conflict', 'applies_in' => $tall, 'question' => 'C30', 'title' => 'Soil advice against a 180 m height limit']);
            }
        }

        // The 4 m buffer between conflicting zones (Art. VII §11).
        $intense = $this->lotZone !== null
            ? in_array($this->lotZone, array_merge(Ordinance::COMMERCIAL, Ordinance::INDUSTRIAL), true)
            : array_intersect($this->candidates(), array_merge(Ordinance::COMMERCIAL, Ordinance::INDUSTRIAL)) !== [];
        if ($intense && array_intersect($this->governing, Ordinance::RESIDENTIAL) !== []) {
            $adjoins = $this->ctx->fact('adjoins_conflicting_zone');
            $buffer = $this->ctx->fact('buffer_provided');
            [$status, $reason] = match (true) {
                $adjoins === null => ['review', 'Say whether the lot adjoins a residential zone: the more intense use keeps a 4 m open buffer along the boundary.'],
                $adjoins === false => ['met', 'Does not adjoin a conflicting zone.'],
                $buffer === true => ['met', 'A 4 m open buffer is kept along the boundary.'],
                $buffer === false => ['not_met', 'The lot adjoins a conflicting zone without the 4 m open buffer the more intense use must provide.'],
                default => ['review', 'Say whether a 4 m open buffer is kept along that boundary.'],
            };
            $this->add('VII-11', $status, $reason,
                ['group' => 'site', 'audience' => 'officer', 'officer_asks' => ['adjoins_conflicting_zone', 'buffer_provided']]);
        }

        // Parking lots of 20+ slots (Art. VI §4.4): trees and permeable paving.
        $parkingTrade = in_array($this->vehicleUse(), ['parking_lot', 'parking_building'], true)
            || preg_grep('/\bparking\s*(?:lot|building|area)/i', $this->ctx->titles()) !== [];
        if ($parkingTrade) {
            $slots = $this->ctx->fact('parking_slots');
            $green = $this->ctx->fact('parking_landscaped');
            [$status, $reason] = match (true) {
                $slots === null => ['review', 'Say how many parking slots there will be: a lot of 20 or more must be planted and half permeable.'],
                $slots < 20 => ['met', 'Fewer than 20 slots.'],
                $green === null => ['review', sprintf('%d slots: say whether the lot will have trees at least 1.8 m tall by occupancy and half its paving permeable.', $slots)],
                $green === false => ['not_met', sprintf('%d slots: a parking lot of 20 or more must have trees at least 1.8 m tall when the occupancy permit is issued, and at least half its paving permeable.', $slots)],
                default => ['met', sprintf('%d slots, planted with trees and half permeable.', $slots)],
            };
            $this->add('VI-4-4', $status, $reason, ['group' => 'site', 'asks' => ['parking_slots', 'parking_landscaped']]);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  Performance standards (Art. VI §§1, 2, 5, 8)
    // ────────────────────────────────────────────────────────────────────

    private function performance(): void
    {
        $codes = $this->ctx->psicCodes();
        $manufacturing = array_filter($codes, fn ($c) => Ordinance::isManufacturing($c)) !== [];
        $industrial = $manufacturing || array_filter($codes, fn ($c) => Ordinance::is('warehouse', $c)) !== [];

        if ($industrial && ($this->pollutive() === null || $this->hazardous() === null)) {
            $this->add(['VI-1', 'VI-2-0'], 'review',
                'Industry must meet the ordinance’s performance standards. Say whether the work is pollutive or hazardous.',
                ['group' => 'performance', 'asks' => ['industry_pollutive', 'industry_hazardous']]);
        }
        if ($industrial || $this->ctx->fact('nuisance_equipment') === true || $this->pollutive() === true) {
            $this->add('VI-1-IRR', 'review',
                'Art. VI §1 says the performance standards "shall be enforced through the Implementing Guidelines that is made part of this Zoning Ordinance". No guidelines are attached to it; the City has been asked for them. CPDO applies the standards as written meanwhile.',
                ['group' => 'performance', 'audience' => 'officer', 'question' => 'C26']);
        }
        if ($manufacturing) {
            $this->add(['VI-2-13', 'VI-8-WASTE', 'VI-1', 'VI-2-0'], 'review',
                'CPDO may ask for a description of your process: industrial processes may not harm the environment, and waste must be disposed of without nuisance.',
                ['group' => 'performance']);
        }
        if ($this->ctx->fact('nuisance_equipment') === true) {
            $residential = array_intersect($this->candidates(), Ordinance::RESIDENTIAL) !== [];
            $this->add(array_merge(['VI-8-NOISE', 'VI-5-6', 'VI-5-7', 'VI-8-GLARE'], $residential ? ['V-2.1-ACC-PWR'] : []), 'review',
                'Noisy or vibrating machinery must be inside a building with noise-absorbing materials and silencers, set back by an open yard of at least 3 m planted with trees, on shock-absorbing mountings, within DENR noise levels; glare and heat may not reach past the property line.',
                ['group' => 'performance', 'asks' => ['nuisance_equipment']]);
        }
        if ($this->pollutive() === true) {
            $this->add(['VI-2-11', 'VI-8-SMOKE', 'VI-8-DUST', 'VI-8-ODOR', 'VI-2-7'], 'review',
                'Emissions must meet DENR’s air standards: smoke no darker than Ringelmann No. 2, dust under 0.3 g/m³, odours and gases enclosed and filtered; wastewater meets DENR’s Class C standards.',
                ['group' => 'performance', 'asks' => ['industry_pollutive']]);
        }
        if ($this->hazardous() === true) {
            $this->add('VI-2-8', 'review', 'Toxic or hazardous waste needs handling and treatment facilities approved by DENR.',
                ['group' => 'performance', 'asks' => ['industry_hazardous']]);
        }

        // Waste and wastewater: shown to CPDO for the trades that make them.
        $food = array_filter($codes, fn ($c) => str_starts_with($c, '56') || Ordinance::is('market', $c)
            || Ordinance::is('mall', $c) || Ordinance::is('food_processing', $c)) !== [];
        if ($food) {
            $this->add('VI-2-12', 'info',
                'A business generating a significant volume of solid waste must provide collection and disposal facilities.',
                ['group' => 'performance', 'audience' => 'officer']);
        }
        if ($food || $manufacturing || array_filter($codes, fn ($c) => Ordinance::is('laundry', $c) || Ordinance::is('auto_repair', $c)) !== []) {
            $this->add('VI-8-SEWER', 'review',
                'Nothing dangerous into drains or waterways; wastewater between pH 6.5 and 8.5. For grease and oil the sentence gives two limits — "in excess of 300 PPM or exceed daily average of 10 PPM" — which cannot both be the limit; the City has been asked which, and CPDO applies DENR’s effluent standards meanwhile.',
                ['group' => 'performance', 'audience' => 'officer', 'question' => 'C36']);
        }

        $water = $manufacturing || array_filter($codes, fn ($c) => Ordinance::is('water_refilling', $c)
            || Ordinance::is('laundry', $c) || Ordinance::is('auto_repair', $c)) !== [];
        if ($water) {
            $well = $this->ctx->fact('deep_well');
            $this->add('VI-2-2', $well === null ? 'review' : ($well ? 'review' : 'met'),
                $well === null ? 'Say whether the business will draw water from a deep well.'
                    : ($well ? 'A deep well needs a water permit from the National Water Resources Board.' : 'No deep well.'),
                ['group' => 'performance', 'asks' => ['deep_well']]);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  ECC before the clearance (Art. IX §6)
    // ────────────────────────────────────────────────────────────────────

    private function environmental(): void
    {
        if ($this->ctx->lines === [] || array_filter($this->ctx->psicCodes(), fn ($c) => Ordinance::is('fuel', $c)) !== []) {
            return; // a filling station's ECC is its own finding (V-2.7-GAS-ECC)
        }
        $denr = DenrRequirements::resolve([], $this->ctx->psicCodes());
        if (($denr['row']['certificate'] ?? null) !== 'ECC') {
            return;
        }
        $this->add(['IX-6', 'III-1-ECP', 'III-1-ECA'], ...$this->yesNo($this->ctx->fact('has_ecc'),
            'You have the ECC.',
            'DENR’s list puts this trade under the Environmental Impact Statement System, and no locational clearance is issued before its ECC requirements are met.',
            'DENR’s list says this trade needs an ECC. Say whether you already have it.', invert: true),
            ...[['group' => 'procedure', 'asks' => ['has_ecc']]]);
    }

    // ────────────────────────────────────────────────────────────────────
    //  A clearance already held (Art. IX §9, §10.2.C)
    // ────────────────────────────────────────────────────────────────────

    private function heldClearance(): void
    {
        if ($this->ctx->applicationType !== 'new') {
            return;
        }
        $held = $this->ctx->fact('held_lc');
        if ($held !== true) {
            if ($held === null) {
                $this->add('IX-9-USE', 'review', 'Say whether you already hold a locational clearance for this business at this address.',
                    ['group' => 'procedure', 'asks' => ['held_lc']]);
            }

            return;
        }

        $construction = $this->ctx->fact('held_lc_for_construction');
        $this->add('IX-10.2-C', ...$this->yesNo($construction,
            'It was issued for building or renovating, and a construction clearance cannot be used for the business that will run inside.',
            'It was issued for the business.',
            'Say whether it was issued for building or renovating.'), ...[['group' => 'procedure', 'asks' => ['held_lc_for_construction']]]);

        $issued = $this->ctx->fact('held_lc_issued_on');
        try {
            $date = is_string($issued) ? CarbonImmutable::parse($issued) : null;
        } catch (\Throwable) {
            $date = null;
        }
        [$status, $reason] = match (true) {
            $date === null => ['review', 'Give the date it was issued: a clearance not used within a year expires.'],
            $date->lt(CarbonImmutable::today()->subYear()) => ['not_met', 'Issued '.$date->toFormattedDateString().' and not used within a year, so it has expired; a new clearance is needed.'],
            default => ['met', 'Issued '.$date->toFormattedDateString().', within the year allowed to start the business.'],
        };
        $this->add('IX-9-USE', $status, $reason, ['group' => 'procedure', 'asks' => ['held_lc_issued_on']]);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Existing uses that do not conform (Art. IX §§10.2.d, 11, 12)
    // ────────────────────────────────────────────────────────────────────

    private function nonConforming(): void
    {
        if (! in_array($this->ctx->applicationType, ['renewal', 'amendment'], true)
            || $this->principal === null || $this->principalListed) {
            return;
        }

        $since = $this->ctx->fact('operating_since_year');
        [$status, $reason] = match (true) {
            $since === null => ['review', 'The trade is not on the list for the zones here. If the business was already operating here when the ordinance was approved (26 November 2018), it may continue as a non-conforming use. Say when it started.'],
            $since <= 2018 => ['review', sprintf('Operating since %d, before the ordinance: it may continue as a non-conforming use. CPDO issues a Notice of Non-Conformance citing what it does not conform to, and a Certificate of Non-Conformance valid one year and renewed yearly "for a period specified under the amended Zoning Ordinance" — a period it never specifies (asked of the City).', $since)],
            default => ['review', sprintf('Started in %d, after the ordinance, in a zone that does not list the trade: the non-conforming rules do not cover it. CPDO decides whether it fits a listed use or needed an exception.', $since)],
        };
        $this->add(['IX-12-0', 'IX-11-NOTICE', 'IX-11-VALID', 'IX-10.2-D', 'III-1-NCU', 'III-1-CNC', 'III-1-NNC', 'IX-14-1b', 'IX-28', 'PREAMBLE'], $status, $reason,
            ['group' => 'nonconforming', 'asks' => ['operating_since_year']]);

        if (is_numeric($since) && $since > 2018) {
            return;
        }

        $fails = [];
        if ($this->ctx->fact('nonconforming_expanded') === true) {
            $fails[] = ['IX-12-1', 'It has taken up more area since the ordinance, which a non-conforming use may not.'];
        }
        if ($this->ctx->fact('nonconforming_ceased') === true) {
            $fails[] = ['IX-12-2', 'It stopped for more than a year, and a non-conforming use that has ceased that long cannot be revived.'];
        }
        $amend = $this->ctx->amendment;
        if ($amend !== null && ($amend['line_changed'] || $amend['area_expanded'] === true)) {
            $fails[] = ['IX-12-6', 'This amendment would change or enlarge a non-conforming use, which may not increase its non-conformity.'];
        }
        if ($amend !== null && $amend['moved']) {
            $fails[] = ['IX-12-7', 'A non-conforming use that moves must conform to the zone it moves to; the non-conforming allowance does not travel with it.'];
        }
        if ($this->ctx->fact('nuisance_equipment') === true || $this->pollutive() === true) {
            $fails[] = ['IX-12-8', 'A non-conforming use may not be a nuisance or pollute.'];
        }
        foreach ($fails as [$rule, $reason]) {
            $this->add($rule, 'not_met', $reason, ['group' => 'nonconforming']);
        }
        if ($fails === []) {
            $answered = $this->ctx->fact('nonconforming_expanded') !== null && $this->ctx->fact('nonconforming_ceased') !== null;
            $this->add(['IX-12-1', 'IX-12-2', 'IX-12-6', 'IX-12-7', 'IX-12-8'], $answered ? 'met' : 'review',
                $answered ? 'Not enlarged, moved or idle for a year since the ordinance.' : 'Say whether the business has grown or stopped for a year since 2018.',
                ['group' => 'nonconforming', 'asks' => ['nonconforming_expanded', 'nonconforming_ceased']]);
        }
        if ($this->ctx->fact('lzeac_allowed') === true) {
            $this->add('IX-12-10', 'info', 'Allowed by a variance, exception or deviation under the old ordinance: it is treated as a non-conforming use and may operate until it ceases. (The ordinance calls the body that granted it the "LZEAC", which it does not define; read here as the old zoning board.)',
                ['group' => 'nonconforming', 'asks' => ['lzeac_allowed'], 'question' => 'C16']);
        }
        $this->add(['IX-11-CONTINUE', 'IX-12-9', 'IX-29'], 'review',
            'The ordinance says two things about how long a non-conforming use lasts: §11 lets it continue until it ceases operation; §12.9 tells the owner to phase it out and relocate within ten years of the ordinance taking effect — a date the City has not given us. Both are shown; the City has been asked which governs.',
            ['group' => 'conflict', 'question' => 'C16']);
        $this->add(['IX-12-4', 'IX-12-5', 'IX-11-MONITOR'], 'info',
            'A damaged non-conforming building may be rebuilt only up to 50% of its replacement cost; the use may not displace a conforming one; it is monitored and any violation can suspend its clearance.',
            ['group' => 'nonconforming', 'audience' => 'officer']);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Amendments: a change of activity or area needs a new clearance (§8)
    // ────────────────────────────────────────────────────────────────────

    private function amendment(): void
    {
        $amend = $this->ctx->amendment;
        if ($amend === null) {
            return;
        }
        $why = array_values(array_filter([
            $amend['line_changed'] ? 'the line of business changes' : null,
            $amend['area_expanded'] === true ? 'the floor area grows' : null,
            $amend['moved'] ? 'the premises move' : null,
        ]));
        if ($why !== []) {
            $this->add(['IX-8-CHANGE', 'IX-9-CHANGE'], 'info',
                'This amendment needs a new locational clearance because '.implode(' and ', $why).', and it carries one.',
                ['group' => 'procedure']);
        } elseif ($amend['area_expanded'] === null) {
            $this->add(['IX-8-CHANGE', 'IX-9-CHANGE'], 'review',
                'The register has no earlier floor area to compare, so whether the area grows — which would need a new locational clearance — is for CPDO and BPLO to judge.',
                ['group' => 'procedure']);
        } else {
            $this->add(['IX-8-CHANGE', 'A-63'], 'met',
                'Neither the activity nor the area changes, so no new locational clearance is needed. A change of owner is not a change of occupancy (Annex A 63).',
                ['group' => 'procedure']);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  Who decides, and what to do when a rule is not met
    // ────────────────────────────────────────────────────────────────────

    private function procedure(): void
    {
        $type = $this->ctx->applicationType;
        if ($type === 'new') {
            $this->add(['IX-2', 'IX-8-NEW'], 'met',
                'A new business needs a locational clearance before it operates, and this filing applies for one.',
                ['group' => 'procedure']);
        } elseif ($type === 'renewal') {
            $this->add('IX-2', 'info',
                'A locational clearance used within its first year does not expire under the ordinance; the yearly renewal is the City’s practice, and a non-conforming use renews its certificate yearly.',
                ['group' => 'procedure', 'question' => 'A28']);
        }

        $this->add(['IX-14-1a', 'IX-13', 'IX-15', 'IX-16', 'IX-17', 'III-1-LC'], 'info',
            'CPDO’s Zoning Administrator decides the clearance. Variances, exceptions, complaints and appeals from that decision go to the Local Zoning Board of Appeals, and its decisions can be appealed to HLURB.',
            ['group' => 'procedure']);

        $trouble = ! $this->principalListed
            || array_filter($this->findings, fn ($f) => $f['status'] === 'not_met') !== [];
        if ($trouble && $this->ctx->lines !== []) {
            $this->add(['VIII-1', 'VIII-1-1', 'VIII-1-2', 'VIII-2'], 'info',
                'If a rule is not met: for a use the zone does not list, apply to the Local Zoning Board of Appeals for an exception; for a size, design or performance rule, a variance. Apply in writing citing the sections; post a sign at the site; file affidavits of no objection from the owners in front and on each side within 15 days; the board holds a hearing in the barangay and decides within 30 days.',
                ['group' => 'procedure']);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Record one finding.
     *
     * `applies_in` scopes a rule to zones. If none of them is a candidate the
     * rule does not reach this lot and is dropped. If only some are, a "not
     * met" becomes CPDO review, because whether it binds depends on which of
     * the candidate zones the lot is in — said in the `scope` line.
     *
     * @param  string|list<string>  $rules
     * @param  array<string, mixed>  $opts
     */
    private function add(string|array $rules, string $status, string $reason, array $opts = []): void
    {
        $rules = (array) $rules;
        $scope = null;
        if (isset($opts['applies_in'])) {
            $candidates = $this->candidates();
            $inScope = array_values(array_intersect($opts['applies_in'], $candidates));
            if ($inScope === []) {
                return;
            }
            if ($this->lotZone === null && count($inScope) < count($candidates)) {
                $scope = 'Applies if your lot is in the '.$this->names($inScope).' zone'.(count($inScope) > 1 ? 's' : '').'.';
                if ($status === 'not_met') {
                    $status = 'review';
                }
            }
        }

        $asks = array_values(array_unique($opts['asks'] ?? []));
        $officerAsks = array_values(array_unique($opts['officer_asks'] ?? []));
        $missing = array_values(array_filter(
            array_merge($asks, $officerAsks),
            fn ($k) => $this->ctx->fact($k) === null,
        ));

        $citations = array_values(array_unique(array_map(fn ($id) => Rulebook::citation($id), $rules)));
        $first = Rulebook::get($rules[0]);

        $this->findings[] = [
            'rule' => $rules[0],
            'rules' => $rules,
            'group' => $opts['group'] ?? 'conditions',
            'status' => $status,
            // A finding about several rules may name itself; most take the
            // title of the rule they are about.
            'title' => (string) ($opts['title'] ?? $first['title'] ?? $rules[0]),
            'rule_text' => (string) ($first['plain'] ?? ''),
            'reason' => $reason,
            'citation' => $citations[0] ?? $rules[0],
            'citations' => $citations,
            /*
             * Every rule the finding applies, in the inventory's words, so the
             * officer can read each one — a finding about a special use cites
             * six rules and the officer is deciding against all of them.
             */
            'rules_detail' => array_map(function (string $id) {
                $rule = Rulebook::get($id);

                return [
                    'id' => $id,
                    'title' => (string) ($rule['title'] ?? $id),
                    'citation' => Rulebook::citation($id),
                    'plain' => (string) ($rule['plain'] ?? ''),
                ];
            }, $rules),
            'scope' => $scope,
            'asks' => $asks,
            'officer_asks' => $officerAsks,
            'missing' => $missing,
            'question' => $opts['question'] ?? null,
            'audience' => $opts['audience'] ?? 'both',
        ];
    }

    /**
     * A "provided that" condition attached to a use in some zones.
     *
     * `$binds` are the zones whose list carries the condition (directly or by
     * inheritance with no other listing). `$mixed` are zones that reach the
     * use both through that list and through one that lists it flat — the
     * CBD takes in C-1's conditioned auto repair AND General Commercial's
     * unconditioned one — where the condition is sent to CPDO rather than
     * imposed or dropped (V-2.10-FLAT).
     *
     * `$flat` are zones that list the same use with no such condition at all
     * — General Commercial restating C-1 and C-2's uses without their
     * provisos, or C-2 taking trucks, tow trucks and buses with none of
     * General Commercial's hauling condition. For a lot that may be in one of
     * them the condition is not imposed, and the difference is named to CPDO
     * with the question put to the City (C28), so a General Commercial lot is
     * never told silently that the condition is gone.
     *
     * @param  string|list<string>  $rules
     * @param  list<string>  $flat
     */
    private function proviso(string|array $rules, string $status, string $reason, array $binds, array $mixed, array $asks, ?string $question = null, array $flat = []): void
    {
        $candidates = $this->candidates();
        $inBinds = array_intersect($binds, $candidates);
        $inMixed = array_values(array_intersect($mixed, $candidates));
        $inFlat = array_values(array_diff(array_intersect($flat, $candidates), $binds, $mixed));
        $rules = (array) $rules;

        if ($inFlat !== []) {
            $title = Rulebook::get($rules[0])['title'] ?? $rules[0];
            $this->add(array_merge(['V-2.10-FLAT'], $rules), 'review',
                $this->names($inFlat).' '.(count($inFlat) > 1 ? 'list' : 'lists').' this use without the condition another zone attaches to it ('.$title.'). Whether that was meant has been asked of the City; CPDO decides whether to apply it to a lot there.',
                ['group' => 'conflict', 'applies_in' => $inFlat, 'question' => 'C28',
                    'title' => 'Listed elsewhere without this condition']);
        }
        if ($inBinds === [] && $inMixed === []) {
            return;
        }
        if ($inBinds === []) {
            $this->add(array_merge($rules, ['V-2.10-FLAT']), 'review',
                $reason.' Here '.$this->names($inMixed).' takes in this use both with these conditions and without them (General Commercial lists it flat), so CPDO decides whether they apply.',
                ['group' => 'conditions', 'asks' => $asks, 'applies_in' => $inMixed, 'question' => $question]);

            return;
        }
        $this->add($rules, $status, $reason,
            ['group' => 'conditions', 'asks' => $asks, 'applies_in' => array_merge($binds, $mixed), 'question' => $question]);
    }

    /** Zones the lot could be in: CPDO's answer, else every governing zone. @return list<string> */
    private function candidates(): array
    {
        return $this->lotZone !== null ? [$this->lotZone] : $this->governing;
    }

    /** @return array<string, array{use: string, from: string, via: ?string, certain: bool, basis: string, definition: ?string}> zone => hit */
    private function hits(PsicCode $psic, array $codes, string $description = ''): array
    {
        $out = [];
        foreach ($codes as $code) {
            $hit = ZoningConformance::lookup($code, $psic, $this->ctx->facts, $description);
            if ($hit !== null) {
                $out[$code] = $hit;
            }
        }

        return $out;
    }

    /**
     * [status, reason] for a yes/no answer where YES fails the rule — or,
     * with `$invert`, where YES satisfies it.
     */
    private function yesNo(?bool $answer, string $whenYes, string $whenNo, string $unanswered, bool $invert = false): array
    {
        if ($answer === null) {
            return ['review', $unanswered];
        }
        if ($invert) {
            return $answer ? ['met', $whenYes] : ['not_met', $whenNo];
        }

        return $answer ? ['not_met', $whenYes] : ['met', $whenNo];
    }

    /** [status, reason] for a minimum distance. */
    private function distance(string $fact, float $min, string $from): array
    {
        $value = $this->ctx->fact($fact);
        if (! is_numeric($value)) {
            return ['review', "Give the distance to {$from}; the minimum is ".number_format($min).' m.'];
        }

        return $value >= $min
            ? ['met', sprintf('%s m from %s (minimum %s m).', number_format($value), $from, number_format($min))]
            : ['not_met', sprintf('%s m from %s; the minimum is %s m.', number_format($value), $from, number_format($min))];
    }

    private function parkingNbc(): array
    {
        $street = $this->ctx->fact('parking_on_street');

        return match ($street) {
            null => ['review', 'Say whether customers will park on the street; parking must be on the premises, as the NBC requires.'],
            true => ['not_met', 'Customers would park on the street; adequate parking on the premises is required, per the NBC.'],
            default => ['review', 'Parking is on the premises. CPDO checks it against the NBC’s minimum.'],
        };
    }

    /** Pollutive, from the applicant's answer or CPDD's sheet (VIII.E). */
    private function pollutive(): ?bool
    {
        $fact = $this->ctx->fact('industry_pollutive');
        if (is_bool($fact)) {
            return $fact;
        }

        return match ($this->ctx->sheetIndustryType) {
            'Pollutive' => true,
            'Non-Pollutive' => false,
            default => null,
        };
    }

    private function hazardous(): ?bool
    {
        $fact = $this->ctx->fact('industry_hazardous');
        if (is_bool($fact)) {
            return $fact;
        }

        return match ($this->ctx->sheetIndustryType) {
            'Hazardous' => true,
            'Non-Hazardous' => false,
            default => null,
        };
    }

    private function zoneName(string $code): string
    {
        return Ordinance::ZONE_NAMES[$code] ?? $code;
    }

    /** "Commercial-1, Commercial-2 and Institutional". */
    private function names(array $codes): string
    {
        $names = array_values(array_unique(array_map(fn ($c) => $this->zoneName($c), $codes)));
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }

        return implode(', ', array_slice($names, 0, -1)).' and '.end($names);
    }

    /** A use's head, without its list heading or its provisos. */
    private function clause(string $use): string
    {
        $bare = (string) preg_replace('/^[A-Za-z\/ ]{3,60}?(?:\s+like)?:\s+/', '', $use);
        $head = trim((string) preg_split('/,?\s*(?:provided\s+that|subject\s+to\s+conditions)\b|;/i', $bare)[0]);

        return mb_strlen($head) > 140 ? rtrim(mb_substr($head, 0, 140)).'…' : $head;
    }

    private function result(bool $ready): array
    {
        $summary = ['met' => 0, 'not_met' => 0, 'review' => 0, 'info' => 0];
        foreach ($this->findings as $f) {
            $summary[$f['status']]++;
        }

        // The questions to put, in the order the findings ask them.
        $keys = [];
        foreach ($this->findings as $f) {
            foreach (array_merge($f['asks'], $f['officer_asks']) as $k) {
                $keys[$k] = true;
            }
        }
        $zoneOptions = [];
        foreach ($this->zones as $z) {
            $zoneOptions[$z['code']] = $this->zoneName($z['code']).' ('.$z['code'].')';
        }
        $specialOptions = ['none' => 'None'] + array_map(fn ($s) => $s['label'], Ordinance::SPECIAL_USES);

        return [
            'ready' => $ready,
            'zones' => $this->zones,
            'overlays' => $this->overlays,
            'lot_zone' => $this->lotZone,
            'findings' => $this->findings,
            'summary' => $summary,
            'facts' => ZoningFacts::describe(array_keys($keys), $this->ctx->facts, $this->ctx->factSources,
                ['lot_zone' => $zoneOptions, 'special_use' => $specialOptions]),
            'principle' => [
                'text' => 'Every land use is a right, subject to the ordinance’s review. CPDO reviews every site; nothing here refuses your filing.',
                'citation' => Rulebook::citation('II-3-1'),
            ],
        ];
    }
}

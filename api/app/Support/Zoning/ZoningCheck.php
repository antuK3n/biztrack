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
        $hits = $this->hits($principal['psic'], $codes);
        $this->principal = $principal;
        $this->listedIn = array_keys($hits);
        $this->principalListed = $hits !== [];
        $title = $principal['psic']->title;

        if ($hits !== []) {
            $za = array_filter($hits, fn ($h) => $h['via'] === 'V-2.3-INH'
                || str_contains(mb_strtolower($h['use']), 'conditions deemed appropriate by the zoning administrator'));
            $unconditional = array_diff_key($hits, $za);
            $first = reset($hits);
            $rules = ['V-2', 'III-2-a'];
            foreach (array_keys($hits) as $code) {
                $rules[] = 'V-'.Ordinance::SECTION_FOR_CODE[$code].'-USES';
                $via = $hits[$code]['via'];
                if ($via !== null) {
                    $rules[] = $via;
                }
            }
            $where = $this->names(array_keys($hits));
            $reason = "{$title} is allowed in {$where}: “".$this->clause($first['use']).'”.';
            $status = $unconditional !== [] ? 'met' : 'review';
            if ($unconditional === []) {
                $rules[] = array_filter($za, fn ($h) => $h['via'] === 'V-2.3-INH') !== []
                    ? 'V-2.3-INH' : 'V-2.3-RETAIL-ZA';
                $reason .= ' In Maximum Residential-2 it is allowed on conditions the Zoning Administrator sets.';
            }
            $others = array_values(array_diff($codes, array_keys($hits)));
            if ($others !== [] && $this->lotZone === null) {
                $reason .= ' It is not on the list for '.$this->names($others).', so it matters which of these your lot is in.';
            }
            $this->add(array_values(array_unique($rules)), $status, $reason,
                ['group' => 'uses', 'title' => 'On the zones’ lists of allowed uses']);
        } else {
            $this->notListed($principal['psic'], $codes);
        }

        if (count($lines) > 1) {
            $others = [];
            foreach ($lines as $line) {
                if ($line['psic']->id === $principal['psic']->id) {
                    continue;
                }
                $h = $this->hits($line['psic'], $codes);
                $others[] = $line['psic']->title.($h === [] ? ' (not on the list)' : ' (listed in '.$this->names(array_keys($h)).')');
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
     * The trade is on no governing zone's list. Never "refused": the lists
     * are open (Art. III §2, Annex A 89). Three more specific reasons are
     * looked for first, because each changes what CPDO is asked.
     */
    private function notListed(PsicCode $psic, array $codes): void
    {
        $title = $psic->title;
        $rules = ['V-2', 'III-2', 'III-2-a', 'A-89'];
        foreach ($codes as $code) {
            if (isset(Ordinance::SECTION_FOR_CODE[$code])) {
                $rules[] = 'V-'.Ordinance::SECTION_FOR_CODE[$code].'-USES';
            }
        }

        // Listed only in a zone the map draws and the text does not.
        $sheetHits = $this->lotZone === null ? $this->hits($psic, $this->sheetOnly) : [];
        if ($sheetHits !== []) {
            $this->add(array_merge($rules, ['IV-6-g']), 'review',
                "{$title} is on the list for ".$this->names(array_keys($sheetHits)).', which the map sheet shows here but the text of Art. IV §5 does not. The text prevails; CPDO decides.',
                ['group' => 'uses', 'question' => 'C11']);

            return;
        }

        // The Basic R-3 gap: Maximum R-3 and C-1 inherit every residential
        // zone except Basic R-3, so a use listed only there is reachable from
        // nowhere that inherits.
        $skipping = array_values(array_filter($codes, fn ($c) => in_array($c, ['R-3-MAX', 'C-1', 'C-2', 'C-3', 'CBD'], true)));
        if ($skipping !== [] && ZoningConformance::matchUse($psic, ZoningConformance::ownUses('R-3-BASIC')) !== null) {
            $this->add(array_merge($rules, ['V-2.5-INH', 'V-2.7-INH']), 'review',
                "{$title} is listed for Basic Residential-3, but ".$this->names($skipping).' inherit every residential zone except Basic Residential-3. Whether that gap was meant is for the City to say; CPDO decides meanwhile.',
                ['group' => 'uses', 'question' => 'C18']);

            return;
        }

        if ($codes === ['FISHPOND'] || ($this->lotZone === 'FISHPOND')) {
            return; // reported by the Fishpond finding in overlaysGroup()
        }

        $this->add($rules, 'review',
            "{$title} is not on the list for any zone in ".$this->ctx->barangay->name.'. That is not a refusal: the ordinance reads its lists to include similar uses ("and the like", Art. III §2) and refers unlisted ones to other laws (Annex A 89). CPDO decides, and the Local Zoning Board of Appeals can grant an exception.',
            ['group' => 'uses', 'title' => 'On the zones’ lists of allowed uses']);

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

        $vehicles = $has('passenger_terminal') || $has('trucking') || $has('vehicle_rental');
        if ($vehicles) {
            $this->vehicleRules($has('passenger_terminal'));
        }

        if ($has('recreation') || $clause('/\b(?:swimming pool|basketball|badminton|resort)\b/i')) {
            // Maximum R-2 lists a gym flat (personal service shops); only a
            // recreation business it does NOT list is its conditional use.
            $flat = false;
            foreach ($this->ctx->lines as $line) {
                $hit = ZoningConformance::lookup('R-2-MAX', $line['psic']);
                $flat = $flat || ($hit !== null && ! str_contains($hit['use'], 'operated for business purposes'));
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
            $this->proviso('V-2.7-REC', ...$this->parkingNbc(), ...[['C-1'], [], ['parking_on_street']]);
        }

        if ($has('warehouse') || $clause('/\bwarehous/i')) {
            $this->warehouse();
        }

        if ($has('restaurant')) {
            $this->proviso('V-2.7-RESTO', ...$this->parkingNbc(), ...[['C-1', 'C-2', 'C-3'], ['CBD'], ['parking_on_street']]);
        }
        if ($clause('/\bfood\s*parks?\b/i')) {
            $this->proviso('V-2.7-FOODPARK', ...$this->parkingNbc(), ...[['C-1', 'C-2', 'C-3'], ['CBD'], ['parking_on_street']]);
        }

        if ($has('betting')) {
            [$status, $reason] = $this->distance('distance_to_institution_m', 200, 'the nearest school, church, hospital or other institution');
            $this->proviso('V-2.7-LOTTO', $status, $reason, ['C-1', 'C-2', 'C-3'], ['CBD'], ['distance_to_institution_m']);
        }

        if ($has('auto_repair') || $clause('/\bcar\s*wash/i')) {
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
            $this->proviso(['V-2.7-AUTO', 'V-2.7-CARWASH'], $status, $reason, ['C-1', 'C-2', 'C-3'], ['CBD'], ['parking_on_street', 'makeshift_facade', 'grease_trap']);
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
        if ($clause('/\bjunk\s*shop|\bjunkshop/i')) {
            $this->machineShop('V-2.8-JUNK');
        }

        if ($has('trucking')) {
            $within = $this->ctx->fact('operates_within_malabon');
            $this->proviso('V-2.10-HAUL', ...$this->yesNo($within,
                'The hauling business operates within Malabon.',
                'In General Commercial, hauling services are allowed only for a business operating within Malabon.',
                'Say whether the hauling business operates within Malabon.', invert: true),
                ...[['GENERAL-COMMERCIAL'], ['CBD'], ['operates_within_malabon']]);

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

    /** Terminals and garages: residential provisos (§2.3, §2.4), the CBD bus rule (§2.11). */
    private function vehicleRules(bool $terminal): void
    {
        $count = $this->ctx->fact('garage_vehicle_count');
        $heavy = $this->ctx->fact('heavy_vehicles');
        $pool = $this->ctx->fact('motor_pool');
        $twoWay = $this->ctx->fact('two_way_street');
        $turn = $this->ctx->fact('onsite_maneuvering');
        $existing = $this->ctx->fact('existing_malabon_business');
        $rented = $this->ctx->isRented;

        $fails = [];
        if ($heavy === true) {
            $fails[] = 'container vans, tractor heads and trailer trucks are not allowed';
        }
        if ($pool === true) {
            $fails[] = 'a motor pool is not allowed';
        }
        if (is_numeric($count) && $count > 2) {
            $fails[] = sprintf('%d vehicles is more than a residential garage may keep (two ride-hailing units, one taxi, or two delivery vans)', $count);
        }
        if ($twoWay === false) {
            $fails[] = 'the street must take two-way traffic';
        }
        if ($turn === false) {
            $fails[] = 'vehicles must turn inside the lot without backing onto the road';
        }
        if ($rented === true) {
            $fails[] = 'a parking lot for an existing business must be on the business owner’s own lot';
        }
        $unanswered = $count === null || $heavy === null || $pool === null || $twoWay === null || $turn === null;
        [$status, $reason] = match (true) {
            $fails !== [] => ['not_met', ucfirst(implode('; ', $fails)).'.'],
            $unanswered => ['review', 'Answer the vehicle questions to check the residential garage limits.'],
            $existing === false => ['review', 'A parking lot here is allowed only in support of a business already operating in Malabon; CPDO decides whether this garage fits another allowance.'],
            default => ['met', 'Within the residential garage limits.'],
        };
        $asks = ['garage_vehicle_count', 'heavy_vehicles', 'motor_pool', 'two_way_street', 'onsite_maneuvering', 'existing_malabon_business'];
        $this->proviso(['V-2.3-GAR', 'V-2.3-PARK'], $status, $reason, ['R-2-MAX', 'R-3-MAX'], [], $asks);

        $fails = array_values(array_filter([
            $heavy === true ? 'only one four-wheeler or 2-ton van is allowed' : null,
            $pool === true ? 'motor pooling is not allowed' : null,
            is_numeric($count) && $count > 2 ? sprintf('%d vehicles is more than a residential garage may keep', $count) : null,
            $turn === false ? 'vehicles must turn inside the lot without backing onto the road' : null,
            $rented === true ? 'a parking lot for an existing business must be on the business owner’s own lot' : null,
        ]));
        [$status, $reason] = match (true) {
            $fails !== [] => ['not_met', ucfirst(implode('; ', $fails)).'.'],
            $count === null || $heavy === null || $pool === null || $turn === null => ['review', 'Answer the vehicle questions to check the Basic Residential-3 limits.'],
            default => ['met', 'Within the Basic Residential-3 garage limits.'],
        };
        $this->proviso(['V-2.4-GAR', 'V-2.4-PARK'], $status, $reason, ['R-3-BASIC'], [], ['garage_vehicle_count', 'heavy_vehicles', 'motor_pool', 'onsite_maneuvering']);

        if ($terminal) {
            [$status, $reason] = $this->yesNo($turn,
                'Vehicles turn inside the terminal without backing onto the road.',
                'Vehicles would have to back onto the road; a terminal must let them manoeuvre inside.',
                'Say whether vehicles can turn inside the lot.', invert: true);
            $this->proviso(['V-2.3-TRI'], $pool === true ? 'not_met' : $status,
                $pool === true ? 'A motor pool is not allowed at a tricycle or pedicab terminal.' : $reason.' CPDO judges whether the traffic suits the area.',
                ['R-2-MAX', 'R-3-MAX'], [], ['onsite_maneuvering', 'motor_pool']);
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
            ['industry_pollutive', 'industry_hazardous', 'existing_malabon_business', 'parking_on_street']);

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
        $this->proviso('V-2.7-GAS-1KM', $status, $reason, ['C-1', 'C-2', 'C-3'], ['CBD'], ['distance_to_fuel_station_m'], 'C13');

        $ecc = $this->ctx->fact('has_ecc');
        $this->proviso('V-2.7-GAS-ECC', ...$this->yesNo($ecc,
            'You have the ECC, which a filling station needs before applying.',
            'A filling station must secure its ECC before applying for the locational clearance.',
            'Say whether you already have the ECC.', invert: true),
            ...[['C-1', 'C-2', 'C-3'], ['CBD'], ['has_ecc']]);

        $this->proviso(['V-2.7-GAS-DOE', 'V-2.7-GAS-SAFE', 'V-ZONE-AGENCY-GUIDELINES'], 'review',
            'It must meet Department of Energy standards, pose no hazard to a residential community, and have a buffer strip and firefighting equipment. CPDO checks these.',
            ['C-1', 'C-2', 'C-3'], ['CBD'], []);

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
        $this->proviso($rule, $status, $reason, ['C-2', 'C-3'], ['CBD'], $asks);
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
            'mrf' => ['V-3-H-2-5'],
            'billboard' => ['V-3-I-2a', 'V-3-I-2b', 'V-3-I-2d-i', 'V-3-I-2k-l', 'V-3-I-2o-q', 'V-3-I-3-5'],
            'terminal' => ['V-3-J-2', 'V-3-J-3', 'V-3-J-5-7'],
        ][$use] ?? [];
        if ($extras !== []) {
            $this->add($extras, 'review', 'Further conditions CPDO checks for this special use.',
                ['group' => 'special', 'audience' => 'officer']);
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
    }

    private function billboard(callable $opts): void
    {
        $this->add('V-3-I-1', ...$this->yesNo($this->ctx->fact('fronts_national_road'),
            'The lot fronts the National Road.',
            'Billboards may stand only on lots fronting the National Road.',
            'Say whether the lot fronts the National Road.', invert: true), ...[$opts(['fronts_national_road'])]);
        $this->add('V-3-I-2c', ...$this->distance('distance_to_billboard_m', 100, 'the nearest other billboard'), ...[$opts(['distance_to_billboard_m'])]);
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
            $r1 = $this->principal !== null && ZoningConformance::lookup('R-1', $this->principal['psic']) !== null;
            $this->add('V-4.3-NEWUSES', $r1 ? 'met' : 'review',
                $r1
                    ? 'New construction in the heritage overlay takes R-1 uses, and the trade is one.'
                    : 'New construction in the heritage overlay is limited to R-1 uses, even where the base zone is commercial, and the trade is not an R-1 use. Read literally this overrides the base zone; the City has been asked whether it means to.',
                ['group' => 'overlay', 'asks' => ['new_construction'], 'question' => 'C25']);
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
            default => ['met', sprintf('The business uses %.0f%% of the lot (limit 30%%, a tenth of it on land for parking and toilets).', $floor / $lot * 100)],
        };
        $this->add('V-4.2-AREA', $status, $reason, ['group' => 'overlay']);

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
            $this->add(['VII-1-4', 'VII-1-1-3'], 'info',
                'Height limits: '.implode('; ', $limits).'. Lower where a commercial or industrial lot adjoins a residential zone without a street between.',
                ['group' => 'site', 'audience' => 'officer']);
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
            $this->add('VI-7', $status, $reason, ['group' => 'site', 'audience' => $new === true ? 'both' : 'officer']);
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

        // Parking lots of 20+ slots (Art. VI §4.4).
        if (preg_grep('/\bparking\s*(?:lot|building|area)/i', $this->ctx->titles()) !== []) {
            $slots = $this->ctx->fact('parking_slots');
            [$status, $reason] = match (true) {
                $slots === null => ['review', 'Say how many parking slots there will be.'],
                $slots >= 20 => ['review', sprintf('%d slots: a lot of 20 or more must be planted with trees at least 1.8 m tall at occupancy and be half paved with permeable material.', $slots)],
                default => ['met', 'Fewer than 20 slots.'],
            };
            $this->add('VI-4-4', $status, $reason, ['group' => 'site', 'asks' => ['parking_slots']]);
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
            $this->add('VI-8-SEWER', 'info',
                'Nothing dangerous into drains or waterways; wastewater between pH 6.5 and 8.5, grease and oil within 300 ppm (10 ppm daily average).',
                ['group' => 'performance', 'audience' => 'officer']);
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
            $since <= 2018 => ['review', sprintf('Operating since %d, before the ordinance: it may continue as a non-conforming use. CPDO issues a Notice of Non-Conformance citing what it does not conform to, and a Certificate of Non-Conformance valid one year and renewed yearly.', $since)],
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
            $this->add('IX-12-10', 'info', 'Allowed by the old LZEAC: it is treated as a non-conforming use and may operate until it ceases.',
                ['group' => 'nonconforming', 'asks' => ['lzeac_allowed']]);
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
     * @param  string|list<string>  $rules
     */
    private function proviso(string|array $rules, string $status, string $reason, array $binds, array $mixed, array $asks, ?string $question = null): void
    {
        $candidates = $this->candidates();
        $inBinds = array_intersect($binds, $candidates);
        $inMixed = array_values(array_intersect($mixed, $candidates));
        if ($inBinds === [] && $inMixed === []) {
            return;
        }
        $rules = (array) $rules;
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

    /** @return array<string, array{use: string, from: string, via: ?string}> zone => hit */
    private function hits(PsicCode $psic, array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $hit = ZoningConformance::lookup($code, $psic);
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

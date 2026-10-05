<?php

namespace App\Support;

use App\Enums\PermitStatus;
use App\Models\PermitType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How many Business Permits expiring this 31 December are expected to be
 * renewed on time, renewed late, or not renewed — estimated from the register's
 * own renewal history.
 *
 * ── Why it exists ──────────────────────────────────────────────────────────
 *
 * Objective 3 of the paper promises analytics "to support data-driven
 * planning", and the adviser's midterm note was that the dashboard had none:
 * "Wala akong nakikita na forecasting. Puro dashboard lang 'to." Renewal Risk
 * Prediction once answered that and was removed with its screen (checklist
 * 2026-09-27, item 6). Her correction at the time was to state the answer as a
 * probability per renewal rather than an open-ended risk score
 * (docs/r-integration-revisions.md, item 0.7). This is that answer, as one
 * dashboard panel [Ken, 5 October 2026].
 *
 * ── The question ───────────────────────────────────────────────────────────
 *
 * A Business Permit expires on 31 December and is renewed without penalty from
 * 1 to 20 January (RenewalSeason). For each permit expiring this 31 December,
 * how likely is it to be renewed on time, late, or not at all? Summed over the
 * permits, those probabilities are the expected counts BPLO plans the season
 * from, and summed per barangay they say where the non-renewals are expected.
 *
 * ── What the past is read as ───────────────────────────────────────────────
 *
 * A business's Business Permits are read as TERMS, one per expiry date. An
 * amendment re-issues the permit inside the same term, and that is not a
 * renewal; grouping by expiry date is what keeps it from being read as one.
 * A term's outcome is the day the business filed for its NEXT term — the
 * filing's `submitted_at` where there is a filing, else the day the permit was
 * issued or began, for a permit recorded from paper:
 *
 *   on time      on or before the penalty-free day (20 January after a
 *                31 December expiry — RenewalSeason::penaltyFreeUntil), which
 *                is the day the ordinance's surcharge is judged on
 *   late         after it, within HORIZON_DAYS of the expiry
 *   not renewed  no next term within HORIZON_DAYS
 *
 * A term only counts once HORIZON_DAYS have passed since it expired. Before
 * that, a late renewal that has not arrived yet would be read as no renewal at
 * all — RenewalOutcomes' case 4, and the reason its SETTLE_DAYS is reused here.
 *
 * A term whose permit the City revoked is left out: it was taken away, so
 * whatever followed was not the business's renewal decision. A term of a
 * business later removed from the register stays in the history — not
 * renewing is exactly what happened — but such a business is not forecast.
 *
 * ── The model ──────────────────────────────────────────────────────────────
 *
 * Two facts about a business, both known before its permit expires:
 *
 *   years      how many earlier terms it has held (0 for a new business),
 *              capped at YEARS_CAP
 *   last_late  1 when its last renewal was late, else 0
 *
 * New businesses close more often than established ones, and a business that
 * renewed late once is likelier to do so again; those are the two readings the
 * register can support without inventing a column. Two logistic regressions
 * (Glm) are fitted on the past terms: one for the chance of renewing at all,
 * one for the chance of renewing on time given that it renews. Per permit,
 *
 *   on time      = P(renew) × P(on time | renew)
 *   late         = P(renew) × (1 − P(on time | renew))
 *   not renewed  = 1 − P(renew)
 *
 * which always add up to one. A fact that never varies in the history says
 * nothing and is left out of that model rather than making the fit singular.
 *
 * ── When it says nothing ───────────────────────────────────────────────────
 *
 * Fewer than MIN_OUTCOMES past terms of any one outcome, or a fit that does
 * not settle, and there is no estimate: the payload says why and the panel
 * shows its empty state. A number from a model that had nothing to learn from
 * would read exactly like one that had.
 *
 * Same split as the dashboard: dataset() reads the register, compute() is
 * arithmetic only.
 */
final class RenewalForecast
{
    /** Days after expiry before a past term's outcome is settled (RenewalOutcomes). */
    public const HORIZON_DAYS = RenewalOutcomes::SETTLE_DAYS;

    /**
     * Past terms needed of EACH outcome before anything is estimated.
     *
     * Ten outcomes per fitted fact is the usual floor for a logistic
     * regression, and each model fits two.
     */
    public const MIN_OUTCOMES = 20;

    /** Earlier terms beyond this count as this many: the tenth year says no more than the fifth. */
    public const YEARS_CAP = 5;

    /** Barangays listed, most expected non-renewals first. */
    public const TOP_BARANGAYS = 5;

    /** The facts both models may use, in design-matrix order. */
    private const TERMS = ['years', 'last_late'];

    /**
     * The register's facts: one row per settled past term, one per permit due.
     *
     * @return array{
     *     expires_on: string,
     *     on_time_until: string,
     *     horizon_days: int,
     *     history: list<array{years: int, last_late: int, outcome: string}>,
     *     due: list<array{years: int, last_late: int, barangay: string|null}>
     * }
     */
    public static function dataset(CarbonImmutable $today): array
    {
        $expires = RenewalSeason::endOfTermFor($today);
        $settledBy = $today->subDays(self::HORIZON_DAYS)->toDateString();

        $facts = [
            'expires_on' => $expires->toDateString(),
            'on_time_until' => RenewalSeason::penaltyFreeUntil($expires)->toDateString(),
            'horizon_days' => self::HORIZON_DAYS,
            'history' => [],
            'due' => [],
        ];

        $rows = DB::table('permits')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->leftJoin('applications', 'applications.id', '=', 'permits.application_id')
            ->where('permit_types.code', PermitType::OUTCOME_CODE)
            ->orderBy('permits.business_id')
            ->orderBy('permits.valid_until')
            ->orderBy('permits.id')
            ->get([
                'permits.business_id', 'permits.status', 'permits.valid_from', 'permits.valid_until',
                'permits.issued_at', 'applications.submitted_at', 'businesses.deleted_at',
            ]);

        $terms = [];
        foreach ($rows as $row) {
            $expiry = CarbonImmutable::parse($row->valid_until)->toDateString();
            $filed = $row->submitted_at !== null
                ? ManilaCalendar::dateOf($row->submitted_at)
                : ($row->issued_at !== null
                    ? ManilaCalendar::dateOf($row->issued_at)
                    : CarbonImmutable::parse($row->valid_from)->toDateString());

            $term = $terms[(int) $row->business_id][$expiry]
                ?? ['filed' => $filed, 'revoked' => false, 'status' => null, 'removed' => $row->deleted_at !== null];
            $term['filed'] = min($term['filed'], $filed);
            $term['revoked'] = $term['revoked'] || $row->status === PermitStatus::Revoked->value;
            // Ordered by id within the term, so the last one read is the permit in force.
            $term['status'] = (string) $row->status;
            $terms[(int) $row->business_id][$expiry] = $term;
        }

        $due = [];
        foreach ($terms as $businessId => $chain) {
            $expiries = array_keys($chain);
            $lastLate = 0;

            foreach ($expiries as $index => $expiry) {
                $term = $chain[$expiry];
                $next = $expiries[$index + 1] ?? null;
                $features = ['years' => min($index, self::YEARS_CAP), 'last_late' => $lastLate];

                $onTimeUntil = RenewalSeason::penaltyFreeUntil(CarbonImmutable::parse($expiry))->toDateString();
                $lateUntil = CarbonImmutable::parse($expiry)->addDays(self::HORIZON_DAYS)->toDateString();
                $renewed = $next === null ? null : $chain[$next]['filed'];

                if ($expiry <= $settledBy && ! $term['revoked']) {
                    $facts['history'][] = $features + ['outcome' => match (true) {
                        $renewed !== null && $renewed <= $onTimeUntil => 'on_time',
                        $renewed !== null && $renewed <= $lateUntil => 'late',
                        default => 'not_renewed',
                    }];
                }

                if (
                    $expiry === $facts['expires_on']
                    && ! $term['removed']
                    && in_array($term['status'], [PermitStatus::Active->value, PermitStatus::Suspended->value], true)
                ) {
                    $due[(int) $businessId] = $features;
                }

                // What the NEXT term knows about this one: was it renewed late?
                $lastLate = $renewed !== null && $renewed > $onTimeUntil ? 1 : 0;
            }
        }

        $barangays = DB::table('business_addresses')
            ->join('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
            ->whereIn('business_addresses.business_id', array_keys($due) ?: [0])
            ->where('business_addresses.address_type', 'business_location')
            ->pluck('barangays.name', 'business_addresses.business_id');

        foreach ($due as $businessId => $features) {
            $name = $barangays[$businessId] ?? null;
            $facts['due'][] = $features + ['barangay' => $name === null ? null : (string) $name];
        }

        return $facts;
    }

    /**
     * The fits and the sums. No database.
     *
     * @param  array<string, mixed>  $facts  as returned by dataset()
     * @return array<string, mixed>
     */
    public static function compute(array $facts): array
    {
        $history = $facts['history'];
        $due = $facts['due'];

        $counts = ['on_time' => 0, 'late' => 0, 'not_renewed' => 0];
        foreach ($history as $row) {
            $counts[$row['outcome']]++;
        }

        $out = [
            'expires_on' => (string) $facts['expires_on'],
            'on_time_until' => (string) $facts['on_time_until'],
            'horizon_days' => (int) $facts['horizon_days'],
            'permits' => count($due),
            'history' => count($history),
            'history_outcomes' => $counts,
            // Why there is no estimate, or null when there is one.
            'unavailable' => null,
            'expected' => null,
            'shares' => null,
            'barangays' => [],
        ];

        if ($due === []) {
            return ['unavailable' => 'no_permits'] + $out;
        }

        if (min($counts) < self::MIN_OUTCOMES) {
            return ['unavailable' => 'thin_history'] + $out;
        }

        $renews = self::fit($history, static fn (array $row): int => $row['outcome'] === 'not_renewed' ? 0 : 1);
        $onTime = self::fit(
            array_values(array_filter($history, static fn (array $row): bool => $row['outcome'] !== 'not_renewed')),
            static fn (array $row): int => $row['outcome'] === 'on_time' ? 1 : 0,
        );

        if ($renews === null || $onTime === null) {
            return ['unavailable' => 'thin_history'] + $out;
        }

        $pRenew = Glm::predict(Glm::design($due, $renews['terms'])['matrix'], $renews['coefficients']);
        $pOnTime = Glm::predict(Glm::design($due, $onTime['terms'])['matrix'], $onTime['coefficients']);

        $sums = ['on_time' => 0.0, 'late' => 0.0, 'not_renewed' => 0.0];
        $byBarangay = [];
        foreach ($due as $i => $row) {
            $notRenewed = 1.0 - $pRenew[$i];
            $sums['on_time'] += $pRenew[$i] * $pOnTime[$i];
            $sums['late'] += $pRenew[$i] * (1.0 - $pOnTime[$i]);
            $sums['not_renewed'] += $notRenewed;

            if ($row['barangay'] !== null) {
                $byBarangay[$row['barangay']] ??= ['barangay' => $row['barangay'], 'expected' => 0.0, 'permits' => 0];
                $byBarangay[$row['barangay']]['expected'] += $notRenewed;
                $byBarangay[$row['barangay']]['permits']++;
            }
        }

        $total = count($due);
        $out['expected'] = self::wholeCounts($sums, $total);
        $out['shares'] = array_map(static fn (float $sum): float => Rounding::statistic($sum / $total * 100, 1), $sums);

        usort($byBarangay, static fn (array $a, array $b): int => [$b['expected'], $a['barangay']] <=> [$a['expected'], $b['barangay']]);
        foreach (array_slice($byBarangay, 0, self::TOP_BARANGAYS) as $row) {
            $out['barangays'][] = [
                'barangay' => $row['barangay'],
                'not_renewed' => (int) round($row['expected']),
                'permits' => $row['permits'],
            ];
        }

        return $out;
    }

    /**
     * One logistic regression on the facts that vary in `$rows`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): int  $outcome
     * @return array{terms: list<array{name: string, kind: string}>, coefficients: list<float>}|null
     */
    private static function fit(array $rows, callable $outcome): ?array
    {
        $terms = [];
        foreach (self::TERMS as $name) {
            if (count(array_unique(array_column($rows, $name))) > 1) {
                $terms[] = ['name' => $name, 'kind' => 'numeric'];
            }
        }

        $fit = Glm::binomial(Glm::design($rows, $terms)['matrix'], array_map($outcome, $rows));

        if ($fit === null) {
            return null;
        }

        foreach ($fit['coefficients'] as $coefficient) {
            if (! is_finite($coefficient)) {
                return null;
            }
        }

        return ['terms' => $terms, 'coefficients' => $fit['coefficients']];
    }

    /**
     * Expected counts as whole permits that still add up to the permits due.
     *
     * Each sum rounded on its own can make 230 permits into 229 or 231 expected
     * outcomes; the largest remainders take the leftover units instead.
     *
     * @param  array<string, float>  $sums
     * @return array<string, int>
     */
    private static function wholeCounts(array $sums, int $total): array
    {
        $whole = array_map(static fn (float $sum): int => (int) floor($sum), $sums);
        $remainders = [];
        foreach ($sums as $key => $sum) {
            $remainders[$key] = $sum - $whole[$key];
        }
        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $total - array_sum($whole)) as $key) {
            $whole[$key]++;
        }

        return $whole;
    }
}

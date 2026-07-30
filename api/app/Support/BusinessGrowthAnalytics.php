<?php

namespace App\Support;

use App\Enums\PermitStatus;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Business Lifecycle Monitoring, computed from the register.
 *
 * Naming: the client's paper calls this "Business Growth Analysis" and mockup 122
 * calls it "Business Lifecycle Monitoring". The mockup is newer, so it wins on
 * naming; the paper wins on formulas. That split is deliberate and is recorded in
 * docs/r-integration-spec.md §4.
 *
 * Same shape as the other analytics features: `dataset()` gathers facts and is
 * what `analytics:refresh` pushes to R, `compute()` turns facts into statistics
 * and doubles as the fallback, and R's `POST /growth/lifecycle` returns the same
 * schema from the same facts.
 *
 * DEFINITIONS THAT ARE CHOICES
 *
 *  - **Lifecycle status** (Active / Expired / Inactive / Closed) is derived from
 *    permits, not from `businesses.status`. That column is the moderation state
 *    the admin sets (active / flagged / suspended / blacklisted) and answers a
 *    different question. A business is Closed when its registration was removed
 *    (soft delete), Active when it holds a permit valid today, Expired when its
 *    permits have all lapsed, and Inactive when it is registered but has never
 *    been issued one.
 *  - **A closure is dated by `deleted_at`.** There is no closed_at column, and
 *    `updated_at` moves for every edit, so the soft-delete timestamp is the only
 *    honest closure date in the schema.
 *  - Growth compares the requested period against the equally long period that
 *    ended where it began.
 *
 * See cohortObservations() for how the survival measure is defined; it is the one
 * figure here that is a statistic rather than a count, and the reasoning behind
 * it is longer than a line.
 */
final class BusinessGrowthAnalytics
{
    /** The R endpoint that computes this dataset. */
    public const R_ENDPOINT = '/growth/lifecycle';

    public const DEFAULT_PERIOD_MONTHS = 12;

    /** How many barangays and industries the screen lists. */
    private const TOP_N = 6;

    /**
     * Days after a permit expires before a missing renewal counts as a lapse.
     *
     * Without a grace period every business whose permit expired yesterday would
     * be recorded as having failed to renew, when in practice it is simply still
     * within the window where a renewal normally lands. Thirty days matches the
     * first renewal reminder, so a business counts as lapsed only once it has had
     * a reminder and a month.
     */
    private const RENEWAL_GRACE_DAYS = 30;

    /**
     * Days of coverage gap tolerated between consecutive permits.
     *
     * A permit valid to 31 December replaced by one effective 1 January is
     * contiguous cover and an on-time renewal, even though the dates are not
     * equal. One day is what makes that read correctly rather than as a lapse.
     */
    private const COVERAGE_GAP_TOLERANCE_DAYS = 1;

    /**
     * The permit type whose sequence defines a renewal cycle.
     *
     * The mayor's permit is the one every business must hold, so it is the only
     * one whose chain is comparable across the register. Following the sanitary
     * or fire chains as well would count a single year's renewal up to three
     * times for some businesses and once for others.
     */
    private const CYCLE_PERMIT_TYPE = 'BUSINESS';

    /**
     * The sentence that has to travel with the survival figure, wherever it is
     * shown. Kept server-side so an export cannot ship the number without it.
     */
    public const SURVIVAL_METHODOLOGY = 'Cohort survival is a Kaplan-Meier estimate over observed '
        .'renewal cycles: of the businesses that reached a given renewal, the share that had renewed '
        .'every previous one without a lapse in cover. Businesses still within their current permit '
        .'are censored rather than counted as failures. It describes what this cohort did, and is not '
        .'a forecast of what any business will do next.';

    /**
     * @return array<string, mixed>
     */
    public static function build(int $periodMonths = self::DEFAULT_PERIOD_MONTHS): array
    {
        return self::compute(self::dataset($periodMonths));
    }

    /**
     * The facts every panel needs. No rates, no ranks, no survival curve — R's
     * job (and compute()'s).
     *
     * @return array<string, mixed>
     */
    public static function dataset(int $periodMonths = self::DEFAULT_PERIOD_MONTHS): array
    {
        $now = CarbonImmutable::now();
        $periodStart = $now->subMonths($periodMonths)->startOfDay();
        $priorStart = $periodStart->subMonths($periodMonths)->startOfDay();

        return [
            'params' => ['months' => $periodMonths],
            'now' => $now->toISOString(),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $now->toDateString(),
            'prior_period_start' => $priorStart->toDateString(),
            'top_n' => self::TOP_N,
            'survival_methodology' => self::SURVIVAL_METHODOLOGY,

            'registrations' => self::countRegistrations($periodStart, $now, includeEnd: true),
            'registrations_prior' => self::countRegistrations($priorStart, $periodStart),
            'closures' => self::closures($periodStart, $now),
            'status_counts' => self::statusCounts(),
            'barangays' => self::barangayCounts($periodStart, $priorStart, $now),
            'closure_months' => self::closureMonths($periodStart, $now, $periodMonths),
            'industries' => self::industryCounts($periodStart, $priorStart, $now),
            'cohorts' => self::cohortObservations($now),
        ];
    }

    /**
     * The local (PHP) engine: facts in, lifecycle statistics out, no database.
     *
     * @param  array<string, mixed>  $dataset  as returned by dataset()
     * @return array<string, mixed>
     */
    public static function compute(array $dataset): array
    {
        $periodMonths = (int) $dataset['params']['months'];
        $registered = (int) $dataset['registrations'];
        $prior = (int) $dataset['registrations_prior'];
        $topN = (int) ($dataset['top_n'] ?? self::TOP_N);

        return [
            'generated_at' => (string) $dataset['now'],
            'period_months' => $periodMonths,
            'period_start' => (string) $dataset['period_start'],
            'period_end' => (string) $dataset['period_end'],
            'prior_period_start' => (string) $dataset['prior_period_start'],
            'registrations' => $registered,
            'registrations_prior' => $prior,
            // Null when there is nothing to compare against: a percentage change
            // from zero is not a number, and inventing one would be a lie. The
            // screen renders this as "No prior period".
            'growth_rate' => $prior > 0
                ? Rounding::statistic((($registered - $prior) / $prior) * 100, 1)
                : null,
            'closures' => (int) $dataset['closures'],
            'cohort_survival' => self::computeSurvival($dataset['cohorts'], (string) $dataset['survival_methodology']),
            'status_summary' => self::computeStatusSummary($dataset['status_counts']),
            'top_barangays' => self::computeBarangays($dataset['barangays'], $topN),
            'closure_trend' => self::computeClosureTrend($dataset['closure_months']),
            'industry_growth' => self::computeIndustries($dataset['industries'], $topN),
        ];
    }

    /* ── facts ─────────────────────────────────────────────────────────── */

    /**
     * The period end is inclusive because it is "now": timestamps are stored to
     * the second, so an exclusive bound silently drops anything registered in
     * the current second. Interior boundaries stay exclusive so a row cannot
     * land in both the period and the one before it.
     */
    private static function countRegistrations(CarbonImmutable $from, CarbonImmutable $to, bool $includeEnd = false): int
    {
        return Business::withTrashed()
            ->where('created_at', '>=', $from)
            ->where('created_at', $includeEnd ? '<=' : '<', $to)
            ->count();
    }

    private static function closures(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return Business::onlyTrashed()
            ->where('deleted_at', '>=', $from)
            ->where('deleted_at', '<=', $to)
            ->count();
    }

    /**
     * Active / Expired / Inactive / Closed, derived from permits + soft deletes.
     *
     * @return array<string, int>
     */
    private static function statusCounts(): array
    {
        $today = CarbonImmutable::now()->toDateString();

        $businesses = Business::withTrashed()->get(['id', 'deleted_at']);

        // One pass over permits: has each business ever held one, and does it
        // hold one that is valid today?
        $everPermitted = [];
        $livePermitted = [];
        foreach (DB::table('permits')->get(['business_id', 'status', 'valid_until']) as $permit) {
            $everPermitted[$permit->business_id] = true;
            if ($permit->status === PermitStatus::Active->value
                && CarbonImmutable::parse($permit->valid_until)->toDateString() >= $today) {
                $livePermitted[$permit->business_id] = true;
            }
        }

        $counts = ['active' => 0, 'expired' => 0, 'inactive' => 0, 'closed' => 0];

        foreach ($businesses as $business) {
            if ($business->deleted_at !== null) {
                $counts['closed']++;
            } elseif (! isset($everPermitted[$business->id])) {
                $counts['inactive']++;
            } elseif (isset($livePermitted[$business->id])) {
                $counts['active']++;
            } else {
                $counts['expired']++;
            }
        }

        return $counts;
    }

    /**
     * New registrations per barangay, this period and the one before it.
     *
     * @return list<array{barangay: string, registrations: int, prior: int}>
     */
    private static function barangayCounts(CarbonImmutable $periodStart, CarbonImmutable $priorStart, CarbonImmutable $now): array
    {
        $rows = DB::table('businesses')
            ->join('business_addresses', 'business_addresses.business_id', '=', 'businesses.id')
            ->join('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
            ->where('businesses.created_at', '>=', $priorStart)
            ->where('businesses.created_at', '<=', $now)
            ->get(['barangays.name as barangay', 'businesses.created_at']);

        $current = [];
        $prior = [];
        foreach ($rows as $row) {
            $createdAt = CarbonImmutable::parse($row->created_at);
            if ($createdAt >= $periodStart) {
                $current[$row->barangay] = ($current[$row->barangay] ?? 0) + 1;
            } else {
                $prior[$row->barangay] = ($prior[$row->barangay] ?? 0) + 1;
            }
        }

        $names = array_unique([...array_keys($current), ...array_keys($prior)]);
        sort($names);

        $out = [];
        foreach ($names as $name) {
            $out[] = [
                'barangay' => (string) $name,
                'registrations' => $current[$name] ?? 0,
                'prior' => $prior[$name] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Closures per month across the period.
     *
     * @return list<array{month: string, closures: int}>
     */
    private static function closureMonths(CarbonImmutable $periodStart, CarbonImmutable $now, int $periodMonths): array
    {
        $closures = Business::onlyTrashed()
            ->where('deleted_at', '>=', $periodStart)
            ->where('deleted_at', '<=', $now)
            ->pluck('deleted_at');

        // Walk from the first of the month: adding months to, say, the 29th
        // overflows past February and would drop that bucket entirely.
        $buckets = [];
        $cursor = $periodStart->startOfMonth();
        for ($i = 0; $i <= $periodMonths; $i++) {
            $buckets[$cursor->addMonths($i)->format('Y-m')] = 0;
        }

        foreach ($closures as $closedAt) {
            $month = CarbonImmutable::parse($closedAt)->format('Y-m');
            if (array_key_exists($month, $buckets)) {
                $buckets[$month]++;
            }
        }

        $trend = [];
        foreach ($buckets as $month => $count) {
            $trend[] = ['month' => $month, 'closures' => $count];
        }

        return $trend;
    }

    /**
     * Registrations by line of business (PSIC), this period against the last.
     *
     * `count` is how many live businesses carry that line today; the two period
     * counts are what "growing" and "declining" read.
     *
     * @return list<array{industry: string, psic_code: string, count: int, registrations: int, prior: int}>
     */
    private static function industryCounts(CarbonImmutable $periodStart, CarbonImmutable $priorStart, CarbonImmutable $now): array
    {
        $lines = DB::table('business_lines')
            ->join('businesses', 'businesses.id', '=', 'business_lines.business_id')
            ->join('psic_codes', 'psic_codes.id', '=', 'business_lines.psic_code_id')
            ->whereNull('businesses.deleted_at')
            ->orderBy('psic_codes.code')
            ->get(['psic_codes.code as psic_code', 'psic_codes.title as industry', 'businesses.created_at']);

        $totals = [];
        foreach ($lines as $line) {
            $key = $line->psic_code;
            $totals[$key] ??= [
                'industry' => (string) $line->industry,
                'psic_code' => (string) $line->psic_code,
                'count' => 0,
                'registrations' => 0,
                'prior' => 0,
            ];
            $totals[$key]['count']++;

            $createdAt = CarbonImmutable::parse($line->created_at);
            if ($createdAt >= $periodStart && $createdAt <= $now) {
                $totals[$key]['registrations']++;
            } elseif ($createdAt >= $priorStart && $createdAt < $periodStart) {
                $totals[$key]['prior']++;
            }
        }

        return array_values($totals);
    }

    /**
     * One survival observation per business: how many renewal cycles it cleared,
     * and whether it then lapsed or is still being watched.
     *
     * WHY THIS IS A SURVIVAL MEASURE AND NOT A RATIO
     *
     * The paper's formula reads "businesses that continued renewing on time ÷
     * total businesses in the group", and taken literally as a single division
     * that figure is wrong in a way that flatters the LGU: a business registered
     * last month has not had a renewal to miss, so counting it in the denominator
     * drags the rate toward whatever share of the register is simply too new to
     * have failed. Cohort survival is the measure that handles this — businesses
     * still inside their current permit are *censored*, meaning they count while
     * they were observed and stop counting once there is nothing left to observe.
     * That is why the paper names the `survival` package.
     *
     * WHAT A CYCLE IS. Each business's mayor's-permit chain is ordered by
     * `valid_from`. Cycle k is the k-th renewal of that chain. A renewal is on
     * time when the new permit takes effect before the old one's cover ends
     * (within COVERAGE_GAP_TOLERANCE_DAYS). The observation for a business is:
     *
     *   time  = the cycle it failed at, or the last cycle it is known to have
     *           cleared if it has not failed
     *   event = 1 if it lapsed at `time`, 0 if it is still under observation
     *
     * A business with one permit still in force has time 0 and event 0: it has
     * not yet reached its first renewal, so it informs no cycle and correctly
     * drops out of the risk set. A business whose only permit expired more than
     * RENEWAL_GRACE_DAYS ago with no successor failed at cycle 1.
     *
     * The cohort is the year the chain started, which is what lets the screen show
     * whether recent cohorts renew better or worse than older ones.
     *
     * @return list<array{cohort: string, business_id: int, time: int, event: int}>
     */
    private static function cohortObservations(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();

        $rows = DB::table('permits')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNull('businesses.deleted_at')
            ->where('permit_types.code', self::CYCLE_PERMIT_TYPE)
            ->whereIn('permits.status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->orderBy('permits.business_id')
            ->orderBy('permits.valid_from')
            ->orderBy('permits.id')
            ->get(['permits.business_id', 'permits.valid_from', 'permits.valid_until']);

        $chains = [];
        foreach ($rows as $row) {
            $chains[(int) $row->business_id][] = [
                'from' => CarbonImmutable::parse($row->valid_from)->startOfDay(),
                'until' => CarbonImmutable::parse($row->valid_until)->startOfDay(),
            ];
        }

        $observations = [];
        foreach ($chains as $businessId => $chain) {
            $cohort = $chain[0]['from']->format('Y');
            $cleared = 0;
            $event = 0;

            for ($i = 0; $i < count($chain); $i++) {
                $current = $chain[$i];
                $next = $chain[$i + 1] ?? null;

                if ($next !== null) {
                    $deadline = $current['until']->addDays(self::COVERAGE_GAP_TOLERANCE_DAYS);
                    if ($next['from']->lessThanOrEqualTo($deadline)) {
                        $cleared++;

                        continue;
                    }

                    // A gap in cover: this is the cycle the business lapsed at.
                    $event = 1;
                    $cleared++;
                    break;
                }

                // End of the chain. Still inside the permit (or its grace) means
                // there is nothing yet to judge; past it with no successor is a
                // lapse at the next cycle.
                if ($today->greaterThan($current['until']->addDays(self::RENEWAL_GRACE_DAYS))) {
                    $event = 1;
                    $cleared++;
                }
            }

            $observations[] = [
                'cohort' => $cohort,
                'business_id' => (int) $businessId,
                'time' => $cleared,
                'event' => $event,
            ];
        }

        return $observations;
    }

    /* ── statistics ────────────────────────────────────────────────────── */

    /**
     * Kaplan-Meier survival over renewal cycles, overall and per cohort.
     *
     * The product-limit estimator: at each cycle t, S(t) = S(t-1) × (1 − d/n)
     * where n is how many businesses reached cycle t and d how many lapsed there.
     * Censored businesses leave the risk set without ever counting as failures,
     * which is the whole reason for using this rather than a single division.
     *
     * R computes the same thing through `survival::survfit`; this reproduces it so
     * the fallback cannot quietly become a second, different measure.
     *
     * @param  list<array{cohort: string, business_id: int, time: int, event: int}>  $observations
     * @return array<string, mixed>
     */
    private static function computeSurvival(array $observations, string $methodology): array
    {
        $overall = self::survivalCurve($observations);

        $byCohort = [];
        $grouped = [];
        foreach ($observations as $observation) {
            $grouped[(string) $observation['cohort']][] = $observation;
        }
        ksort($grouped);

        foreach ($grouped as $cohort => $rows) {
            $curve = self::survivalCurve($rows);
            $byCohort[] = [
                'cohort' => (string) $cohort,
                'businesses' => $curve['businesses'],
                'renewals_observed' => $curve['renewals_observed'],
                'lapses' => $curve['lapses'],
                'max_cycle' => $curve['max_cycle'],
                'survival' => $curve['survival'],
                'points' => $curve['points'],
            ];
        }

        return [
            'methodology' => $methodology,
            'grace_days' => self::RENEWAL_GRACE_DAYS,
            'businesses' => $overall['businesses'],
            'renewals_observed' => $overall['renewals_observed'],
            'lapses' => $overall['lapses'],
            'max_cycle' => $overall['max_cycle'],
            // The headline: survival through the last cycle any business reached.
            // Null when no business has reached a first renewal yet — there is no
            // cohort to have survived anything.
            'survival' => $overall['survival'],
            'points' => $overall['points'],
            'cohorts' => $byCohort,
        ];
    }

    /**
     * @param  list<array{time: int, event: int}>  $rows
     * @return array<string, mixed>
     */
    private static function survivalCurve(array $rows): array
    {
        $maxCycle = 0;
        $lapses = 0;
        foreach ($rows as $row) {
            $maxCycle = max($maxCycle, (int) $row['time']);
            $lapses += (int) $row['event'];
        }

        $points = [];
        $survival = 1.0;
        $estimable = false;

        for ($t = 1; $t <= $maxCycle; $t++) {
            $atRisk = 0;
            $events = 0;
            foreach ($rows as $row) {
                if ((int) $row['time'] >= $t) {
                    $atRisk++;
                }
                if ((int) $row['time'] === $t && (int) $row['event'] === 1) {
                    $events++;
                }
            }

            if ($atRisk === 0) {
                break;
            }

            $estimable = true;
            $survival *= 1 - ($events / $atRisk);

            $points[] = [
                'cycle' => $t,
                'at_risk' => $atRisk,
                'lapses' => $events,
                'survival' => Rounding::statistic($survival * 100, 1),
            ];
        }

        return [
            'businesses' => count($rows),
            // How many renewal cycles the cohort actually lived through, which is
            // the sample size behind the curve.
            'renewals_observed' => array_sum(array_map(static fn (array $r): int => (int) $r['time'], $rows)),
            'lapses' => $lapses,
            'max_cycle' => $maxCycle,
            'survival' => $estimable ? Rounding::statistic($survival * 100, 1) : null,
            'points' => $points,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{status: string, label: string, count: int, share: float|null}>
     */
    private static function computeStatusSummary(array $counts): array
    {
        $labels = [
            'active' => 'Active',
            'expired' => 'Expired',
            'inactive' => 'Inactive',
            'closed' => 'Closed',
        ];

        $total = 0;
        foreach ($labels as $status => $_label) {
            $total += (int) ($counts[$status] ?? 0);
        }

        $summary = [];
        foreach ($labels as $status => $label) {
            $count = (int) ($counts[$status] ?? 0);
            $summary[] = [
                'status' => $status,
                'label' => $label,
                'count' => $count,
                'share' => $total > 0 ? Rounding::statistic(($count / $total) * 100, 1) : null,
            ];
        }

        return $summary;
    }

    /**
     * Barangays ranked by the INCREASE between periods, as the spec asks — not by
     * how many they have. A barangay with 300 registrations and no change is not
     * growing.
     *
     * @param  list<array{barangay: string, registrations: int, prior: int}>  $facts
     * @return list<array<string, mixed>>
     */
    private static function computeBarangays(array $facts, int $topN): array
    {
        $out = [];
        foreach ($facts as $row) {
            $current = (int) $row['registrations'];
            $prior = (int) $row['prior'];
            $out[] = [
                'barangay' => (string) $row['barangay'],
                'registrations' => $current,
                'prior' => $prior,
                'delta' => $current - $prior,
                'growth_rate' => $prior > 0
                    ? Rounding::statistic((($current - $prior) / $prior) * 100, 1)
                    : null,
            ];
        }

        // Delta descending, then volume, then name so ties hold a stable order.
        usort(
            $out,
            static fn (array $a, array $b) => [$b['delta'], $b['registrations'], $a['barangay']]
                <=> [$a['delta'], $a['registrations'], $b['barangay']],
        );

        return array_slice($out, 0, $topN);
    }

    /**
     * @param  list<array{month: string, closures: int}>  $facts
     * @return list<array{month: string, closures: int}>
     */
    private static function computeClosureTrend(array $facts): array
    {
        $trend = [];
        foreach ($facts as $row) {
            $trend[] = ['month' => (string) $row['month'], 'closures' => (int) $row['closures']];
        }

        return $trend;
    }

    /**
     * @param  list<array{industry: string, psic_code: string, count: int, registrations: int, prior: int}>  $facts
     * @return list<array<string, mixed>>
     */
    private static function computeIndustries(array $facts, int $topN): array
    {
        $out = [];
        foreach ($facts as $row) {
            $current = (int) $row['registrations'];
            $prior = (int) $row['prior'];
            $delta = $current - $prior;

            $out[] = [
                'industry' => (string) $row['industry'],
                'psic_code' => (string) $row['psic_code'],
                'count' => (int) $row['count'],
                'registrations' => $current,
                'prior' => $prior,
                'delta' => $delta,
                'direction' => match (true) {
                    $delta > 0 => 'growing',
                    $delta < 0 => 'declining',
                    default => 'steady',
                },
            ];
        }

        usort(
            $out,
            static fn (array $a, array $b) => [$b['count'], $b['delta'], $a['psic_code']]
                <=> [$a['count'], $a['delta'], $b['psic_code']],
        );

        return array_slice($out, 0, $topN);
    }
}

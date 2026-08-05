<?php

namespace App\Support;

use App\Enums\PermitStatus;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BusinessGrowthAnalytics
{
    public const R_ENDPOINT = '/growth/lifecycle';

    public const DEFAULT_PERIOD_MONTHS = 12;

    private const TOP_N = 6;

    private const RENEWAL_GRACE_DAYS = 30;

    private const COVERAGE_GAP_TOLERANCE_DAYS = 1;

    private const CYCLE_PERMIT_TYPE = 'BUSINESS';

    public const SURVIVAL_METHODOLOGY = 'Cohort survival is a Kaplan-Meier estimate over observed '
        .'renewal cycles: of the businesses that reached a given renewal, the share that had renewed '
        .'every previous one without a lapse in cover. Businesses still within their current permit '
        .'are censored rather than counted as failures. It describes what this cohort did, and is not '
        .'a forecast of what any business will do next.';

    public static function build(int $periodMonths = self::DEFAULT_PERIOD_MONTHS): array
    {
        return self::compute(self::dataset($periodMonths));
    }

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

            'grace_days' => self::RENEWAL_GRACE_DAYS,

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

    private static function statusCounts(): array
    {
        $today = CarbonImmutable::now()->toDateString();

        $businesses = Business::withTrashed()->get(['id', 'deleted_at']);

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

    private static function closureMonths(CarbonImmutable $periodStart, CarbonImmutable $now, int $periodMonths): array
    {
        $closures = Business::onlyTrashed()
            ->where('deleted_at', '>=', $periodStart)
            ->where('deleted_at', '<=', $now)
            ->pluck('deleted_at');

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

                    $event = 1;
                    $cleared++;
                    break;
                }

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

            'survival' => $overall['survival'],
            'points' => $overall['points'],
            'cohorts' => $byCohort,
        ];
    }

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

            'renewals_observed' => array_sum(array_map(static fn (array $r): int => (int) $r['time'], $rows)),
            'lapses' => $lapses,
            'max_cycle' => $maxCycle,
            'survival' => $estimable ? Rounding::statistic($survival * 100, 1) : null,
            'points' => $points,
        ];
    }

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

        usort(
            $out,
            static fn (array $a, array $b) => [$b['delta'], $b['registrations'], $a['barangay']]
                <=> [$a['delta'], $a['registrations'], $b['barangay']],
        );

        return array_slice($out, 0, $topN);
    }

    private static function computeClosureTrend(array $facts): array
    {
        $trend = [];
        foreach ($facts as $row) {
            $trend[] = ['month' => (string) $row['month'], 'closures' => (int) $row['closures']];
        }

        return $trend;
    }

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

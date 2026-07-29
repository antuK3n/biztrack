<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Business Growth Analysis, computed from the register itself.
 *
 * Every figure here is derived from `businesses`, `business_addresses`,
 * `business_lines` -> `psic_codes`, `permits`, `applications` and
 * `application_status_history`. Where the data cannot answer a question the
 * value is null and the screen says so, rather than showing a plausible number.
 *
 * Two definitions worth stating plainly, because they are choices:
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
 *
 * Growth compares the requested period against the equally long period that
 * ended where it began.
 */
final class BusinessGrowthAnalytics
{
    public const DEFAULT_PERIOD_MONTHS = 12;

    /** How many barangays and industries the screen lists. */
    private const TOP_N = 6;

    /**
     * @return array<string, mixed>
     */
    public static function build(int $periodMonths = self::DEFAULT_PERIOD_MONTHS): array
    {
        $now = CarbonImmutable::now();
        $periodStart = $now->subMonths($periodMonths)->startOfDay();
        $priorStart = $periodStart->subMonths($periodMonths)->startOfDay();

        $registered = self::countRegistrations($periodStart, $now, includeEnd: true);
        $registeredPrior = self::countRegistrations($priorStart, $periodStart);

        return [
            'generated_at' => $now->toISOString(),
            'period_months' => $periodMonths,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $now->toDateString(),
            'prior_period_start' => $priorStart->toDateString(),
            'registrations' => $registered,
            'registrations_prior' => $registeredPrior,
            // Null when there is nothing to compare against: a percentage change
            // from zero is not a number, and inventing one would be a lie.
            'growth_rate' => $registeredPrior > 0
                ? round((($registered - $registeredPrior) / $registeredPrior) * 100, 1)
                : null,
            'renewal_performance' => self::renewalPerformance($periodStart, $now),
            'closures' => self::closures($periodStart, $now),
            'status_summary' => self::statusSummary(),
            'top_barangays' => self::topBarangays($periodStart, $priorStart, $now),
            'closure_trend' => self::closureTrend($periodStart, $now, $periodMonths),
            'industry_growth' => self::industryGrowth($periodStart, $priorStart, $now),
        ];
    }

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
     * Share of renewal filings decided in the period that were approved.
     *
     * Decisions are dated from the status history, not from the application row,
     * so a renewal approved last year does not count towards this quarter.
     *
     * @return array{rate: float|null, approved: int, decided: int}
     */
    private static function renewalPerformance(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $renewalIds = Application::where('application_type', ApplicationType::Renewal->value)->pluck('id');

        if ($renewalIds->isEmpty()) {
            return ['rate' => null, 'approved' => 0, 'decided' => 0];
        }

        $decisions = ApplicationStatusHistory::whereIn('application_id', $renewalIds)
            ->whereIn('to_status', [ApplicationStatus::Approved->value, ApplicationStatus::Rejected->value])
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->get(['application_id', 'to_status', 'created_at']);

        // One decision per application: the first terminal transition wins.
        $firstDecision = [];
        foreach ($decisions->sortBy('created_at') as $row) {
            $firstDecision[$row->application_id] ??= $row->to_status;
        }

        $decided = count($firstDecision);
        $approved = count(array_filter(
            $firstDecision,
            static fn ($status) => (string) $status === ApplicationStatus::Approved->value,
        ));

        return [
            'rate' => $decided > 0 ? round(($approved / $decided) * 100, 1) : null,
            'approved' => $approved,
            'decided' => $decided,
        ];
    }

    /**
     * Active / Expired / Inactive / Closed, derived from permits + soft deletes.
     *
     * @return list<array{status: string, label: string, count: int, share: float}>
     */
    private static function statusSummary(): array
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

        $total = array_sum($counts);
        $labels = [
            'active' => 'Active',
            'expired' => 'Expired',
            'inactive' => 'Inactive',
            'closed' => 'Closed',
        ];

        $summary = [];
        foreach ($counts as $status => $count) {
            $summary[] = [
                'status' => $status,
                'label' => $labels[$status],
                'count' => $count,
                'share' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        }

        return $summary;
    }

    /**
     * New registrations per barangay this period against the one before it.
     *
     * @return list<array{barangay: string, registrations: int, prior: int, delta: int, growth_rate: float|null}>
     */
    private static function topBarangays(CarbonImmutable $periodStart, CarbonImmutable $priorStart, CarbonImmutable $now): array
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
        $out = [];
        foreach ($names as $name) {
            $now_ = $current[$name] ?? 0;
            $then = $prior[$name] ?? 0;
            $out[] = [
                'barangay' => $name,
                'registrations' => $now_,
                'prior' => $then,
                'delta' => $now_ - $then,
                'growth_rate' => $then > 0 ? round((($now_ - $then) / $then) * 100, 1) : null,
            ];
        }

        usort($out, static fn (array $a, array $b) => [$b['delta'], $b['registrations']] <=> [$a['delta'], $a['registrations']]);

        return array_slice($out, 0, self::TOP_N);
    }

    /**
     * Closures per month across the period.
     *
     * @return list<array{month: string, closures: int}>
     */
    private static function closureTrend(CarbonImmutable $periodStart, CarbonImmutable $now, int $periodMonths): array
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
     * `count` is how many live businesses carry that line today; `delta` is the
     * change in new registrations, which is what "growing" and "declining" read.
     *
     * @return list<array{industry: string, psic_code: string, count: int, registrations: int, prior: int, delta: int, direction: string}>
     */
    private static function industryGrowth(CarbonImmutable $periodStart, CarbonImmutable $priorStart, CarbonImmutable $now): array
    {
        $lines = DB::table('business_lines')
            ->join('businesses', 'businesses.id', '=', 'business_lines.business_id')
            ->join('psic_codes', 'psic_codes.id', '=', 'business_lines.psic_code_id')
            ->whereNull('businesses.deleted_at')
            ->get(['psic_codes.code as psic_code', 'psic_codes.title as industry', 'businesses.created_at']);

        $totals = [];
        foreach ($lines as $line) {
            $key = $line->psic_code;
            $totals[$key] ??= [
                'industry' => $line->industry,
                'psic_code' => $line->psic_code,
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

        $out = [];
        foreach ($totals as $row) {
            $delta = $row['registrations'] - $row['prior'];
            $out[] = [...$row, 'delta' => $delta, 'direction' => match (true) {
                $delta > 0 => 'growing',
                $delta < 0 => 'declining',
                default => 'steady',
            }];
        }

        usort($out, static fn (array $a, array $b) => [$b['count'], $b['delta']] <=> [$a['count'], $a['delta']]);

        return array_slice($out, 0, self::TOP_N);
    }
}

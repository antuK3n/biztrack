<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StaffingSimulation
{
    public const DEFAULT_WINDOW_MONTHS = 12;

    public const DEFAULT_HORIZON_MONTHS = Des::DEFAULT_MONTHS;

    public const DEFAULT_REPS = Des::DEFAULT_REPS;

    public const MAX_ADDED_REVIEWERS = 10;

    private const DEADLINE_WORKING_DAYS = ['complex' => 7, 'simple' => 3];

    private const DELAYS = [
        'complex_intake' => [0.25, 1.0],
        'complex_issuance' => [0.1, 0.5],
        'simple_intake' => [0.2, 0.8],
        'simple_issuance' => [0.1, 0.4],
    ];

    public static function build(
        int $windowMonths = self::DEFAULT_WINDOW_MONTHS,
        ?string $office = null,
        int $added = 1,
        int $demandPercent = 100,
        int $horizonMonths = self::DEFAULT_HORIZON_MONTHS,
        int $reps = self::DEFAULT_REPS,
    ): array {
        $now = CarbonImmutable::now();
        $windowStart = $now->subMonths($windowMonths)->startOfDay();

        $departments = Department::orderBy('id')->get(['id', 'code', 'name']);
        $names = $departments->pluck('name', 'code')->all();

        $reviewSamples = self::reviewDurations($windowStart, $now);
        $inspectionSamples = self::inspectionDurations($windowStart, $now);
        $headcount = self::headcount();

        $arrivals = self::arrivalRate($windowStart, $now);
        $mix = self::complexityMix($windowStart, $now);

        $notes = [];

        $pooled = Des::fitLognormal([...array_merge(...array_values($reviewSamples)), ...array_merge(...array_values($inspectionSamples))]);

        $reviewFits = [];
        foreach ($reviewSamples as $code => $samples) {
            $fit = Des::fitLognormal($samples);
            if ($fit === null) {
                if ($pooled === null) {
                    continue;
                }
                $reviewFits[$code] = [...$pooled, 'source' => 'pooled', 'n' => count($samples)];

                continue;
            }
            $reviewFits[$code] = [...$fit, 'source' => 'fitted'];
        }

        $inspectionFits = [];
        foreach ($inspectionSamples as $code => $samples) {
            $fit = Des::fitLognormal($samples);
            if ($fit === null) {
                if ($pooled === null) {
                    continue;
                }
                $inspectionFits[$code] = [...$pooled, 'source' => 'pooled', 'n' => count($samples)];

                continue;
            }
            $inspectionFits[$code] = [...$fit, 'source' => 'fitted'];
        }

        $frontOffice = self::frontOffice($reviewFits, $reviewSamples);

        if ($reviewFits === [] || $arrivals <= 0.0 || $frontOffice === null) {
            return [
                'generated_at' => $now->toISOString(),
                'window_months' => $windowMonths,
                'window_start' => $windowStart->toDateString(),
                'data_sufficient' => false,
                'reason' => $arrivals <= 0.0
                    ? 'No filing was submitted in this window, so there is no arrival rate to simulate.'
                    : 'No office has completed enough reviews in this window to fit a service time. '
                        .Des::MIN_FIT_SAMPLES.' completed reviews is the minimum.',
                'horizon_days' => max(1, $horizonMonths) * 30,
                'reps' => $reps,
                'seed' => Des::DEFAULT_SEED,
                'deadlines_working_days' => self::DEADLINE_WORKING_DAYS,
                'front_office' => null,
                'change' => null,
                'observed' => [
                    'submissions' => self::submissions($windowStart, $now),
                    'arrivals_per_day' => round($arrivals, 4),
                    'complex_share' => $mix['complex_share'],
                    'window_days' => (int) round($windowStart->diffInDays($now)),
                    'completed_reviews' => array_sum(array_map('count', $reviewSamples)),
                    'completed_inspections' => array_sum(array_map('count', $inspectionSamples)),
                ],
                'offices' => [],
                'fits' => [],
                'baseline' => null,
                'scenario' => null,
                'notes' => $notes,
            ];
        }

        $capacities = [];
        foreach (array_keys($reviewFits) as $code) {
            $capacities[$code] = max(1, $headcount[$code] ?? 0);
            if (($headcount[$code] ?? 0) < 1) {
                $notes[] = $names[$code].' has no active officer on record; the simulation gives it one, '
                    .'because an office with zero reviewers has an infinite queue and tells you nothing.';
            }
        }

        foreach (array_keys($inspectionFits) as $code) {
            $capacities[$code] ??= max(1, $headcount[$code] ?? 0);
        }

        $borrowed = [];
        foreach ([$reviewFits, $inspectionFits] as $fits) {
            foreach ($fits as $code => $fit) {
                if ($fit['source'] === 'pooled') {
                    $borrowed[$code] = true;
                }
            }
        }
        $borrowed = array_keys($borrowed);
        if ($borrowed !== []) {
            $notes[] = 'Service times for '.implode(', ', array_map(fn ($c) => $names[$c] ?? $c, $borrowed))
                .' are borrowed from the pooled fit across every office: fewer than '.Des::MIN_FIT_SAMPLES
                .' of their own reviews completed in this window.';
        }

        if ($inspectionFits === []) {
            $notes[] = 'No inspection was conducted in this window, so the simulated pipeline is review-only. '
                .'Inspection queueing is missing from these figures, not zero.';
        }

        $office = $office !== null && isset($capacities[$office]) ? $office : self::busiestOffice($capacities, $reviewFits, $arrivals, $mix);
        $added = max(0, min(self::MAX_ADDED_REVIEWERS, $added));
        $demandPercent = max(25, min(400, $demandPercent));

        $horizonDays = max(1, $horizonMonths) * 30;

        $demand = $arrivals * ($demandPercent / 100);

        $baseline = Des::simulate(
            self::model($capacities, $reviewFits, $inspectionFits, $frontOffice, $demand, $mix, $horizonDays, $reps)
        );

        $scenarioCapacities = $capacities;
        $scenarioCapacities[$office] += $added;
        $scenario = Des::simulate(
            self::model($scenarioCapacities, $reviewFits, $inspectionFits, $frontOffice, $demand, $mix, $horizonDays, $reps)
        );

        return [
            'generated_at' => $now->toISOString(),
            'window_months' => $windowMonths,
            'window_start' => $windowStart->toDateString(),
            'data_sufficient' => true,
            'reason' => null,
            'horizon_days' => $horizonDays,
            'reps' => $reps,
            'seed' => Des::DEFAULT_SEED,
            'observed' => [
                'submissions' => self::submissions($windowStart, $now),
                'arrivals_per_day' => round($arrivals, 4),
                'window_days' => (int) round($windowStart->diffInDays($now)),
                'complex_share' => $mix['complex_share'],
                'complex_filings' => $mix['complex'],
                'simple_filings' => $mix['simple'],
                'completed_reviews' => array_sum(array_map('count', $reviewSamples)),
                'completed_inspections' => array_sum(array_map('count', $inspectionSamples)),
            ],
            'deadlines_working_days' => self::DEADLINE_WORKING_DAYS,
            'front_office' => ['code' => $frontOffice, 'name' => $names[$frontOffice] ?? $frontOffice],
            'change' => [
                'office' => $office,
                'office_name' => $names[$office] ?? $office,
                'added_reviewers' => $added,
                'demand_percent' => $demandPercent,
                'arrivals_per_day' => round($demand, 4),
            ],
            'fits' => self::shapeFits($reviewFits, $inspectionFits, $names),
            'offices' => self::shapeOffices($capacities, $scenarioCapacities, $baseline, $scenario, $names, $inspectionFits),
            'baseline' => self::shapeRun($baseline),
            'scenario' => self::shapeRun($scenario),
            'notes' => $notes,
        ];
    }

    private static function model(
        array $capacities,
        array $reviewFits,
        array $inspectionFits,
        string $frontOffice,
        float $arrivalsPerDay,
        array $mix,
        float $horizonDays,
        int $reps,
    ): array {
        $stage = static fn (string $code, array $fit): array => [
            'resource' => $code,
            'meanlog' => $fit['meanlog'],
            'sdlog' => $fit['sdlog'],
        ];

        $reviewStages = [];
        foreach ($reviewFits as $code => $fit) {
            $reviewStages[] = $stage($code, $fit);
        }

        $inspectionStages = [];
        foreach ($inspectionFits as $code => $fit) {
            $inspectionStages[] = $stage($code, $fit);
        }

        $complexPhases = [
            ['kind' => 'delay', 'min' => self::DELAYS['complex_intake'][0], 'max' => self::DELAYS['complex_intake'][1]],
            ['kind' => 'parallel', 'stages' => $reviewStages],
        ];
        if ($inspectionStages !== []) {
            $complexPhases[] = ['kind' => 'parallel', 'stages' => $inspectionStages];
        }
        $complexPhases[] = ['kind' => 'delay', 'min' => self::DELAYS['complex_issuance'][0], 'max' => self::DELAYS['complex_issuance'][1]];

        return [
            'resources' => $capacities,
            'arrivals_per_day' => $arrivalsPerDay,
            'classes' => [
                [
                    'key' => 'complex',
                    'share' => $mix['complex_share'],
                    'deadline_days' => self::DEADLINE_WORKING_DAYS['complex'],
                    'phases' => $complexPhases,
                ],
                [
                    'key' => 'simple',
                    'share' => 1 - $mix['complex_share'],
                    'deadline_days' => self::DEADLINE_WORKING_DAYS['simple'],
                    'phases' => [
                        ['kind' => 'delay', 'min' => self::DELAYS['simple_intake'][0], 'max' => self::DELAYS['simple_intake'][1]],
                        ['kind' => 'parallel', 'stages' => [$stage($frontOffice, $reviewFits[$frontOffice])]],
                        ['kind' => 'delay', 'min' => self::DELAYS['simple_issuance'][0], 'max' => self::DELAYS['simple_issuance'][1]],
                    ],
                ],
            ],
            'horizon_days' => $horizonDays,
            'reps' => $reps,
            'seed' => Des::DEFAULT_SEED,
        ];
    }

    private static function reviewDurations(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('application_assignments')
            ->join('departments', 'departments.id', '=', 'application_assignments.department_id')
            ->whereNotNull('application_assignments.completed_at')
            ->whereNotNull('application_assignments.assigned_at')
            ->where('application_assignments.completed_at', '>=', $from)
            ->where('application_assignments.completed_at', '<=', $to)
            ->get([
                'departments.code as code',
                'application_assignments.assigned_at',
                'application_assignments.completed_at',
            ]);

        return self::durations($rows, 'assigned_at', 'completed_at');
    }

    private static function inspectionDurations(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('inspections')
            ->join('departments', 'departments.id', '=', 'inspections.department_id')
            ->whereNotNull('inspections.conducted_at')
            ->whereNotNull('inspections.scheduled_at')
            ->where('inspections.conducted_at', '>=', $from)
            ->where('inspections.conducted_at', '<=', $to)
            ->get(['departments.code as code', 'inspections.scheduled_at', 'inspections.conducted_at']);

        return self::durations($rows, 'scheduled_at', 'conducted_at');
    }

    private static function durations($rows, string $startColumn, string $endColumn): array
    {
        $samples = [];
        foreach ($rows as $row) {
            $start = CarbonImmutable::parse($row->{$startColumn});
            $end = CarbonImmutable::parse($row->{$endColumn});
            $days = ($end->getTimestamp() - $start->getTimestamp()) / 86400;
            if ($days > 0) {
                $samples[$row->code][] = $days;
            }
        }

        ksort($samples);

        return $samples;
    }

    private static function headcount(): array
    {
        $rows = DB::table('users')
            ->join('departments', 'departments.id', '=', 'users.department_id')
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->groupBy('departments.code')
            ->get(['departments.code as code', DB::raw('count(*) as officers')]);

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->code] = (int) $row->officers;
        }

        return $counts;
    }

    private static function submissions(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::table('applications')
            ->whereNull('deleted_at')
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $from)
            ->where('submitted_at', '<=', $to)
            ->count();
    }

    private static function arrivalRate(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $days = max(1, $from->diffInDays($to));

        return self::submissions($from, $to) / $days;
    }

    private static function complexityMix(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('applications')
            ->whereNull('deleted_at')
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '>=', $from)
            ->where('submitted_at', '<=', $to)
            ->groupBy('application_type')
            ->get(['application_type', DB::raw('count(*) as filings')]);

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->application_type] = (int) $row->filings;
        }

        $complex = (int) ($counts[ApplicationType::New->value] ?? 0);
        $simple = (int) ($counts[ApplicationType::Renewal->value] ?? 0)
            + (int) ($counts[ApplicationType::Amendment->value] ?? 0);
        $total = $complex + $simple;

        return [
            'complex_share' => $total > 0 ? round($complex / $total, 4) : 0.5,
            'complex' => $complex,
            'simple' => $simple,
        ];
    }

    private static function frontOffice(array $reviewFits, array $samples): ?string
    {
        if (isset($reviewFits['BPLO'])) {
            return 'BPLO';
        }
        if ($reviewFits === []) {
            return null;
        }

        $best = null;
        $bestCount = -1;
        foreach (array_keys($reviewFits) as $code) {
            $count = count($samples[$code] ?? []);
            if ($count > $bestCount) {
                $best = $code;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private static function busiestOffice(array $capacities, array $reviewFits, float $arrivals, array $mix): string
    {
        $worst = array_key_first($capacities);
        $worstLoad = -1.0;

        foreach ($reviewFits as $code => $fit) {
            $load = ($arrivals * $mix['complex_share'] * $fit['mean_days']) / max(1, $capacities[$code] ?? 1);
            if ($load > $worstLoad) {
                $worst = $code;
                $worstLoad = $load;
            }
        }

        return (string) $worst;
    }

    private static function shapeFits(array $reviewFits, array $inspectionFits, array $names): array
    {
        $out = [];
        foreach (['review' => $reviewFits, 'inspection' => $inspectionFits] as $kind => $fits) {
            foreach ($fits as $code => $fit) {
                $out[] = [
                    'code' => $code,
                    'name' => $names[$code] ?? $code,
                    'kind' => $kind,
                    'meanlog' => round($fit['meanlog'], 6),
                    'sdlog' => round($fit['sdlog'], 6),
                    'mean_days' => round($fit['mean_days'], 3),
                    'median_days' => round($fit['median_days'], 3),
                    'observations' => $fit['n'],
                    'source' => $fit['source'],
                ];
            }
        }

        return $out;
    }

    private static function shapeOffices(
        array $capacities,
        array $scenarioCapacities,
        array $baseline,
        array $scenario,
        array $names,
        array $inspectionFits,
    ): array {
        $rows = [];
        foreach ($capacities as $code => $capacity) {
            $before = $baseline['resources'][$code];
            $after = $scenario['resources'][$code];

            $rows[] = [
                'code' => $code,
                'name' => $names[$code] ?? $code,
                'inspects' => isset($inspectionFits[$code]),
                'reviewers' => $capacity,
                'reviewers_after' => $scenarioCapacities[$code],
                'baseline' => [
                    'utilisation' => round($before['utilisation'], 4),
                    'queue_length' => round($before['queue_length'], 3),
                    'max_queue' => round($before['max_queue'], 1),
                    'wait_days' => round($before['mean_wait_days'], 3),
                ],
                'scenario' => [
                    'utilisation' => round($after['utilisation'], 4),
                    'queue_length' => round($after['queue_length'], 3),
                    'max_queue' => round($after['max_queue'], 1),
                    'wait_days' => round($after['mean_wait_days'], 3),
                ],
                'wait_delta_days' => round($after['mean_wait_days'] - $before['mean_wait_days'], 3),
                'queue_delta' => round($after['queue_length'] - $before['queue_length'], 3),
            ];
        }

        usort($rows, static fn (array $a, array $b) => $b['baseline']['wait_days'] <=> $a['baseline']['wait_days']);

        return $rows;
    }

    private static function shapeRun(array $run): array
    {
        return [
            'arrivals' => round($run['arrivals'], 1),
            'finished' => round($run['finished'], 1),

            'backlog' => round($run['unfinished'], 1),
            'mean_flow_days' => $run['mean_flow_days'] === null ? null : round($run['mean_flow_days'], 2),
            'p90_flow_days' => $run['p90_flow_days'] === null ? null : round($run['p90_flow_days'], 2),
            'on_time_rate' => $run['on_time_rate'] === null ? null : round($run['on_time_rate'] * 100, 1),
            'judged' => round($run['judged'], 1),
        ];
    }
}

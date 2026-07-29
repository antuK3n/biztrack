<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Department;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Feature 6 on real data: the staffing/queue simulation, driven by the register
 * instead of the synthetic frames `r/R/generate.R` used to build.
 *
 * The maths lives in Des (the port of r/R/des.R); this class only decides what
 * to feed it and shapes the answer for the Staffing Simulation screen. It
 * answers one question — "what happens to the backlog if I add one reviewer to
 * OBO?" — by running the observed pipeline twice: once at today's headcount, and
 * once with the change the admin asked for.
 *
 * WHERE EACH INPUT COMES FROM
 *
 *  - Service times: `application_assignments.assigned_at -> completed_at`, in
 *    days, per office, over the requested window. Fitted lognormal by MLE.
 *  - Inspection times: `inspections.scheduled_at -> conducted_at`, per office.
 *  - Arrival rate: filings submitted in the window, divided by the window's
 *    calendar days. Not a knob — the observed rate. The `demand` parameter
 *    scales it so an admin can ask what next renewal season would do.
 *  - Complexity mix: `application_type`. New filings traverse the full pipeline;
 *    renewals and amendments are the lighter revalidation path, matching both
 *    real LGU practice and des.R's own modelling note. The `complexity` column
 *    exists but is unpopulated, so it is not used.
 *  - Reviewer headcount: active officers with `users.department_id` set.
 *
 * DIVERGENCES FROM r/R/des.R, AND WHY
 *
 *  - **Inspections seize the office, not a separate inspector pool.** config.R
 *    declared `INSPECTORS <- list(sanitary = 2L, fire = 2L)` as standalone
 *    resources. The register has no such pool: an inspection's
 *    `inspector_user_id` is a user who belongs to a department, so the same
 *    officers review and inspect. Modelling a separate pool would invent
 *    capacity the LGU does not have, and would make "add one reviewer to CHO"
 *    silently fail to relieve CHO's inspection queue.
 *  - **The offices are whichever ones the data routes to**, not a hard-coded
 *    BPLO/CHO/BFP triple. The register has seven.
 *  - **An office with too little history borrows the pooled fit** across every
 *    office rather than dropping out of the pipeline, and the response names it,
 *    so the screen can say the number rests on borrowed timings. Dropping the
 *    office instead would quietly remove a bottleneck and flatter the answer.
 */
final class StaffingSimulation
{
    /** Months of history the service-time fits are drawn from. */
    public const DEFAULT_WINDOW_MONTHS = 12;

    /** Simulated horizon, in 30-day months. */
    public const DEFAULT_HORIZON_MONTHS = Des::DEFAULT_MONTHS;

    /** Replications averaged per scenario. */
    public const DEFAULT_REPS = Des::DEFAULT_REPS;

    /** Most extra reviewers a single scenario may add. */
    public const MAX_ADDED_REVIEWERS = 10;

    /**
     * RA 11032 processing deadlines in working days (`DEADLINES` in r/config.R).
     * Only the two the pipeline distinguishes are used.
     */
    private const DEADLINE_WORKING_DAYS = ['complex' => 7, 'simple' => 3];

    /**
     * Fixed intake/payment and issuance delays, uniform over these ranges. Taken
     * from des.R, where they stand in for the parts of the pipeline that are not
     * queueing for an officer. Left as the reference had them: the register does
     * not timestamp "waiting for the applicant to pay" separately from the
     * status change, so there is nothing better to fit.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const DELAYS = [
        'complex_intake' => [0.25, 1.0],
        'complex_issuance' => [0.1, 0.5],
        'simple_intake' => [0.2, 0.8],
        'simple_issuance' => [0.1, 0.4],
    ];

    /**
     * Build the baseline and one what-if scenario.
     *
     * @param  string|null  $office  Department code to add reviewers to.
     * @param  int  $added  Extra reviewers for that office.
     * @param  int  $demandPercent  Arrival rate as a percentage of observed.
     * @return array<string, mixed>
     */
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

        // Pooled fit, used by any office whose own history is too thin. Pooling
        // reviews and inspections together is deliberate: both are "an officer
        // working a filing", and splitting the pool would leave nothing to
        // borrow from on a register this young.
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

        // The office the simple (revalidation) path runs through: BPLO when it
        // has history, otherwise whichever office handled the most filings.
        $frontOffice = self::frontOffice($reviewFits, $reviewSamples);

        if ($reviewFits === [] || $arrivals <= 0.0 || $frontOffice === null) {
            // Same envelope shape as a successful run, minus the runs themselves.
            // A caller rendering this should show `reason`, and should not have to
            // guard every other key to do it.
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

        // An office that only ever inspects still needs capacity to seize.
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

        // Demand scales BOTH runs. It sets the operating point the question is
        // asked at ("at next renewal season's volume, does a hire help?"); if it
        // moved only the scenario, the comparison would mix a staffing change
        // with a demand change and neither column would mean anything.
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

    /**
     * Assemble the Des model: intake delay, parallel reviews, parallel
     * inspections, issuance delay for new filings; a single-office
     * revalidation for renewals and amendments.
     *
     * @param  array<string, int>  $capacities
     * @param  array<string, array<string, mixed>>  $reviewFits
     * @param  array<string, array<string, mixed>>  $inspectionFits
     * @param  array{complex_share: float, complex: int, simple: int}  $mix
     * @return array<string, mixed>
     */
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

    /**
     * Completed review durations in days, keyed by office code.
     *
     * @return array<string, list<float>>
     */
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

    /**
     * Conducted inspection durations in days, keyed by office code.
     *
     * @return array<string, list<float>>
     */
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

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, list<float>>
     */
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

    /**
     * Active officers per office. A user with no `department_id` — the super
     * admin, an applicant — is nobody's reviewer.
     *
     * @return array<string, int>
     */
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

    /** Observed filings per calendar day over the window. */
    private static function arrivalRate(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $days = max(1, $from->diffInDays($to));

        return self::submissions($from, $to) / $days;
    }

    /**
     * Share of filings that traverse the full pipeline.
     *
     * New filings are complex; renewals and amendments are the revalidation
     * path. When nothing was submitted, fall back to an even split rather than
     * dividing by zero — the caller has already refused to simulate by then.
     *
     * @return array{complex_share: float, complex: int, simple: int}
     */
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

    /**
     * The office the revalidation path runs through.
     *
     * @param  array<string, array<string, mixed>>  $reviewFits
     * @param  array<string, list<float>>  $samples
     */
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

    /**
     * Default target for the what-if: the office carrying the most simulated
     * work per reviewer, which is where a hire buys the most relief.
     *
     * Offered load is arrival rate times mean service time over headcount — the
     * same ratio utilisation converges to, computed without running anything.
     *
     * @param  array<string, int>  $capacities
     * @param  array<string, array<string, mixed>>  $reviewFits
     * @param  array{complex_share: float, complex: int, simple: int}  $mix
     */
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

    /**
     * @param  array<string, array<string, mixed>>  $reviewFits
     * @param  array<string, array<string, mixed>>  $inspectionFits
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
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

    /**
     * Per-office comparison rows: today's queue against the scenario's.
     *
     * @param  array<string, int>  $capacities
     * @param  array<string, int>  $scenarioCapacities
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $scenario
     * @param  array<string, string>  $names
     * @param  array<string, array<string, mixed>>  $inspectionFits
     * @return list<array<string, mixed>>
     */
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

        // Worst queue first: the screen's job is to point at the bottleneck.
        usort($rows, static fn (array $a, array $b) => $b['baseline']['wait_days'] <=> $a['baseline']['wait_days']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private static function shapeRun(array $run): array
    {
        return [
            'arrivals' => round($run['arrivals'], 1),
            'finished' => round($run['finished'], 1),
            // Filings still in the pipeline when the clock stops — the backlog
            // the staffing question is actually about.
            'backlog' => round($run['unfinished'], 1),
            'mean_flow_days' => $run['mean_flow_days'] === null ? null : round($run['mean_flow_days'], 2),
            'p90_flow_days' => $run['p90_flow_days'] === null ? null : round($run['p90_flow_days'], 2),
            'on_time_rate' => $run['on_time_rate'] === null ? null : round($run['on_time_rate'] * 100, 1),
            'judged' => round($run['judged'], 1),
        ];
    }
}

<?php

namespace App\Support;

use App\Models\ApplicationAssignment;
use App\Models\Department;
use Carbon\CarbonImmutable;

/**
 * Feature 7 on real data: per-department control charts over weekly review
 * turnaround, computed from `application_assignments` rather than the synthetic
 * frames `r/R/generate.R` used to build.
 *
 * The maths lives in Spc (the port of r/R/spc.R); this class only supplies it
 * with rows and shapes the answer for the Permit Processing Time Monitoring
 * screen. Rows are pulled into PHP and bucketed there instead of grouped in
 * SQL, because ISO-week bucketing is spelled differently in SQLite and
 * PostgreSQL and the volume here is one LGU's review history.
 */
final class ProcessingTimeAnalytics
{
    /**
     * How far back the chart looks by default, in weeks.
     *
     * A year, deliberately: limits are calibrated on the first 24 weeks of
     * whatever window it is given, so a 26-week window would leave only two
     * weeks being monitored and let a slowdown help set the limits meant to
     * catch it. 52 weeks keeps roughly half the window under observation.
     */
    public const DEFAULT_WINDOW_WEEKS = 52;

    /**
     * @return array{
     *     generated_at: string,
     *     window_weeks: int,
     *     window_start: string,
     *     min_completions_per_week: int,
     *     calibration_weeks_cap: int,
     *     completed_reviews: int,
     *     departments: list<array<string, mixed>>,
     *     thin: list<array<string, mixed>>
     * }
     */
    public static function build(int $windowWeeks = self::DEFAULT_WINDOW_WEEKS): array
    {
        $now = CarbonImmutable::now();
        $windowStart = $now->subWeeks($windowWeeks)->startOfWeek(CarbonImmutable::MONDAY);

        $departments = Department::orderBy('id')->get(['id', 'code', 'name']);
        $names = $departments->pluck('name', 'code')->all();

        $reviews = ApplicationAssignment::query()
            ->join('departments', 'departments.id', '=', 'application_assignments.department_id')
            ->whereNotNull('application_assignments.completed_at')
            ->whereNotNull('application_assignments.assigned_at')
            ->where('application_assignments.completed_at', '>=', $windowStart)
            ->orderBy('application_assignments.completed_at')
            ->get([
                'departments.code as department_code',
                'application_assignments.assigned_at',
                'application_assignments.completed_at',
            ])
            ->map(fn ($row) => [
                'department_code' => $row->department_code,
                'assigned_at' => $row->assigned_at,
                'completed_at' => $row->completed_at,
            ])
            ->all();

        $weekly = Spc::weeklyTurnaround($reviews);

        /** @var array<string, list<array{week_start: string, n: int, mean_days: float}>> $byDepartment */
        $byDepartment = [];
        foreach ($weekly as $row) {
            $byDepartment[$row['department_code']][] = [
                'week_start' => $row['week_start'],
                'n' => $row['n'],
                'mean_days' => $row['mean_days'],
            ];
        }

        // Completions per department, so a department that fell short can say by
        // how much instead of silently vanishing from the chart.
        $completionsPerDepartment = [];
        foreach ($reviews as $review) {
            $code = $review['department_code'];
            $completionsPerDepartment[$code] = ($completionsPerDepartment[$code] ?? 0) + 1;
        }

        $charted = [];
        $thin = [];

        foreach ($departments as $department) {
            $code = $department->code;
            $weeks = $byDepartment[$code] ?? [];

            if ($weeks === []) {
                $completed = $completionsPerDepartment[$code] ?? 0;
                if ($completed > 0) {
                    $thin[] = [
                        'code' => $code,
                        'name' => $department->name,
                        'completed_reviews' => $completed,
                        'reason' => 'No week in this window reached '.Spc::MIN_COMPLETIONS_PER_WEEK.' completed reviews.',
                    ];
                }

                continue;
            }

            $charted[] = self::shape($code, $names[$code] ?? $code, $weeks, $completionsPerDepartment[$code] ?? 0);
        }

        return [
            'generated_at' => $now->toISOString(),
            'window_weeks' => $windowWeeks,
            'window_start' => $windowStart->toDateString(),
            'min_completions_per_week' => Spc::MIN_COMPLETIONS_PER_WEEK,
            'calibration_weeks_cap' => Spc::CALIBRATION_WEEKS,
            'completed_reviews' => count($reviews),
            'departments' => $charted,
            'thin' => $thin,
        ];
    }

    /**
     * @param  list<array{week_start: string, n: int, mean_days: float}>  $weeks
     * @return array<string, mixed>
     */
    private static function shape(string $code, string $name, array $weeks, int $completedReviews): array
    {
        $result = Spc::analyse($weeks);
        $limits = $result['limits'];
        $rows = $result['weeks'];

        $points = array_map(static fn (array $row) => [
            'week_start' => $row['week_start'],
            'reviews' => $row['n'],
            'mean_days' => round($row['mean_days'], 3),
            'deviation_days' => round($row['deviation_days'], 3),
            'ewma' => round($row['ewma'], 3),
            'status' => $row['status'],
            'rule_hit' => $row['rule_hit'],
        ], $rows);

        $flagged = array_values(array_map(static fn (array $row) => [
            'week_start' => $row['week_start'],
            'mean_days' => round($row['mean_days'], 3),
            'deviation_days' => round($row['deviation_days'], 2),
            'rule_hit' => $row['rule_hit'],
        ], array_filter($rows, static fn (array $row) => $row['status'] === 'out_of_control')));

        $latest = $rows[count($rows) - 1];

        return [
            'code' => $code,
            'name' => $name,
            'completed_reviews' => $completedReviews,
            'center' => round($limits['center'], 3),
            'lcl' => round($limits['lcl'], 3),
            'ucl' => round($limits['ucl'], 3),
            'sigma' => round($limits['sigma'], 4),
            'calibration_weeks' => $limits['calibration_weeks'],
            // "Outside" / "Inside" on the Process Status Indicator: the reading is
            // about the most recent week, not the history.
            'status' => $latest['status'] === 'out_of_control' ? 'outside' : 'inside',
            'latest_week' => $latest['week_start'],
            'latest_mean_days' => round($latest['mean_days'], 3),
            'points' => $points,
            'flagged' => $flagged,
            'trend' => [
                'direction' => $result['trend']['direction'],
                'magnitude' => round($result['trend']['magnitude'], 4),
                'ewma' => round($result['trend']['ewma'], 3),
                'deviation_days' => round($result['trend']['deviation_days'], 2),
                'drift_flagged' => $result['trend']['drift_flagged'],
            ],
        ];
    }
}

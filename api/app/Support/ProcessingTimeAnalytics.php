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
 * This class is split in two on purpose, because R is the primary engine and
 * this is its fallback (docs/r-integration-spec.md):
 *
 *  - **dataset()** runs the SQL and returns the review rows. Laravel owns all
 *    SQL — R never touches the database — so this is also exactly what
 *    `analytics:refresh` pushes to the R service.
 *  - **compute()** turns those rows into the screen's payload with no database
 *    access at all. R's `POST /spc/processing-time` returns the same schema from
 *    the same rows, which is what makes the two comparable on one fixture (see
 *    AnalyticsParityTest) and what keeps the fallback honest.
 *
 * The maths lives in Spc (the port of r/R/spc.R). Rows are pulled into PHP and
 * bucketed there instead of grouped in SQL, because ISO-week bucketing is
 * spelled differently in SQLite and PostgreSQL and the volume here is one LGU's
 * review history.
 */
final class ProcessingTimeAnalytics
{
    /** The R endpoint that computes this dataset. */
    public const R_ENDPOINT = '/spc/processing-time';

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
     * The register rows this dataset is computed from, plus the parameters that
     * frame them. Pushed to R as-is; also the input to the local compute().
     *
     * Timestamps are serialised to ISO 8601 strings rather than left as Carbon
     * instances: this array crosses a JSON boundary to R, and the fallback must
     * see byte-for-byte the same input R does or the parity test is comparing
     * two different questions.
     *
     * @return array{
     *     params: array{weeks: int},
     *     now: string,
     *     window_start: string,
     *     min_completions_per_week: int,
     *     calibration_weeks_cap: int,
     *     departments: list<array{code: string, name: string}>,
     *     reviews: list<array{department_code: string, assigned_at: string, completed_at: string}>
     * }
     */
    public static function dataset(int $windowWeeks = self::DEFAULT_WINDOW_WEEKS): array
    {
        $now = CarbonImmutable::now();
        $windowStart = $now->subWeeks($windowWeeks)->startOfWeek(CarbonImmutable::MONDAY);

        $departments = Department::orderBy('id')->get(['id', 'code', 'name'])
            ->map(fn ($row) => ['code' => (string) $row->code, 'name' => (string) $row->name])
            ->all();

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
                'department_code' => (string) $row->department_code,
                'assigned_at' => CarbonImmutable::parse($row->assigned_at)->toISOString(),
                'completed_at' => CarbonImmutable::parse($row->completed_at)->toISOString(),
            ])
            ->all();

        return [
            'params' => ['weeks' => $windowWeeks],
            'now' => $now->toISOString(),
            'window_start' => $windowStart->toDateString(),
            // Sent rather than hardcoded on the R side so one change of policy
            // cannot leave the two engines disagreeing about the rules.
            'min_completions_per_week' => Spc::MIN_COMPLETIONS_PER_WEEK,
            'calibration_weeks_cap' => Spc::CALIBRATION_WEEKS,
            'departments' => $departments,
            'reviews' => $reviews,
        ];
    }

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
        return self::compute(self::dataset($windowWeeks));
    }

    /**
     * The local (PHP) engine: dataset in, screen payload out, no database.
     *
     * @param  array<string, mixed>  $dataset  as returned by dataset()
     * @return array<string, mixed>
     */
    public static function compute(array $dataset): array
    {
        // Echoed, not re-parsed. Round-tripping it through Carbon re-formats the
        // fractional seconds (".000Z" becomes ".000000Z"), which is the same
        // instant but not the same bytes R returns — and the two engines have to
        // be indistinguishable.
        $now = (string) $dataset['now'];
        $windowWeeks = (int) $dataset['params']['weeks'];
        $windowStart = (string) $dataset['window_start'];

        /** @var list<array{code: string, name: string}> $departments */
        $departments = $dataset['departments'];
        $names = array_column($departments, 'name', 'code');

        /** @var list<array{department_code: string, assigned_at: string, completed_at: string}> $reviews */
        $reviews = $dataset['reviews'];

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
            $code = $department['code'];
            $weeks = $byDepartment[$code] ?? [];

            $completed = $completionsPerDepartment[$code] ?? 0;

            if ($weeks === []) {
                if ($completed > 0) {
                    $thin[] = [
                        'code' => $code,
                        'name' => $department['name'],
                        'completed_reviews' => $completed,
                        'reason' => 'No week in this window reached '.$dataset['min_completions_per_week'].' completed reviews.',
                    ];
                }

                continue;
            }

            $shaped = self::shape($code, $names[$code] ?? $code, $weeks, $completed);

            // No variation in the calibration window means no estimate of
            // variation, so there is no control chart to draw. Say that, rather
            // than draw a chart whose limits sit exactly on the centre line — with
            // a zero-width band every week that is not identical to the others
            // reads as out of control, which is an artifact of the arithmetic and
            // not a finding about the office.
            if ($shaped === null) {
                $thin[] = [
                    'code' => $code,
                    'name' => $department['name'],
                    'completed_reviews' => $completed,
                    'reason' => 'Weekly turnaround did not vary across the calibration window, so no control limits can be fitted.',
                ];

                continue;
            }

            $charted[] = $shaped;
        }

        return [
            'generated_at' => $now,
            'window_weeks' => $windowWeeks,
            'window_start' => $windowStart,
            'min_completions_per_week' => (int) $dataset['min_completions_per_week'],
            'calibration_weeks_cap' => (int) $dataset['calibration_weeks_cap'],
            'completed_reviews' => count($reviews),
            'departments' => $charted,
            'thin' => $thin,
        ];
    }

    /**
     * @param  list<array{week_start: string, n: int, mean_days: float}>  $weeks
     * @return array<string, mixed>|null null when no control limits can be fitted
     */
    private static function shape(string $code, string $name, array $weeks, int $completedReviews): ?array
    {
        $result = Spc::analyse($weeks);
        $limits = $result['limits'];
        $rows = $result['weeks'];

        // Sigma comes from the mean moving range of the calibration window, so
        // zero means every calibration week had the identical mean. See the caller.
        if ($limits['sigma'] <= 0.0) {
            return null;
        }

        $points = array_map(static fn (array $row) => [
            'week_start' => $row['week_start'],
            'reviews' => $row['n'],
            'mean_days' => Rounding::statistic($row['mean_days'], 3),
            'deviation_days' => Rounding::statistic($row['deviation_days'], 3),
            'ewma' => Rounding::statistic($row['ewma'], 3),
            'status' => $row['status'],
            'rule_hit' => $row['rule_hit'],
        ], $rows);

        $flagged = array_values(array_map(static fn (array $row) => [
            'week_start' => $row['week_start'],
            'mean_days' => Rounding::statistic($row['mean_days'], 3),
            'deviation_days' => Rounding::statistic($row['deviation_days'], 2),
            'rule_hit' => $row['rule_hit'],
        ], array_filter($rows, static fn (array $row) => $row['status'] === 'out_of_control')));

        $latest = $rows[count($rows) - 1];

        return [
            'code' => $code,
            'name' => $name,
            'completed_reviews' => $completedReviews,
            'center' => Rounding::statistic($limits['center'], 3),
            'lcl' => Rounding::statistic($limits['lcl'], 3),
            'ucl' => Rounding::statistic($limits['ucl'], 3),
            'sigma' => Rounding::statistic($limits['sigma'], 4),
            'calibration_weeks' => $limits['calibration_weeks'],
            // "Outside" / "Inside" on the Process Status Indicator: the reading is
            // about the most recent week, not the history.
            'status' => $latest['status'] === 'out_of_control' ? 'outside' : 'inside',
            'latest_week' => $latest['week_start'],
            'latest_mean_days' => Rounding::statistic($latest['mean_days'], 3),
            'points' => $points,
            'flagged' => $flagged,
            'trend' => [
                'direction' => $result['trend']['direction'],
                'magnitude' => Rounding::statistic($result['trend']['magnitude'], 4),
                'ewma' => Rounding::statistic($result['trend']['ewma'], 3),
                'deviation_days' => Rounding::statistic($result['trend']['deviation_days'], 2),
                'drift_flagged' => $result['trend']['drift_flagged'],
            ],
        ];
    }
}

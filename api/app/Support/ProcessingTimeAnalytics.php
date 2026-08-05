<?php

namespace App\Support;

use App\Models\ApplicationAssignment;
use App\Models\Department;
use Carbon\CarbonImmutable;

final class ProcessingTimeAnalytics
{
    public const R_ENDPOINT = '/spc/processing-time';

    public const DEFAULT_WINDOW_WEEKS = 52;

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

            'min_completions_per_week' => Spc::MIN_COMPLETIONS_PER_WEEK,
            'calibration_weeks_cap' => Spc::CALIBRATION_WEEKS,
            'departments' => $departments,
            'reviews' => $reviews,
        ];
    }

    public static function build(int $windowWeeks = self::DEFAULT_WINDOW_WEEKS): array
    {
        return self::compute(self::dataset($windowWeeks));
    }

    public static function compute(array $dataset): array
    {
        $now = (string) $dataset['now'];
        $windowWeeks = (int) $dataset['params']['weeks'];
        $windowStart = (string) $dataset['window_start'];

        $departments = $dataset['departments'];
        $names = array_column($departments, 'name', 'code');

        $reviews = $dataset['reviews'];

        $weekly = Spc::weeklyTurnaround($reviews);

        $byDepartment = [];
        foreach ($weekly as $row) {
            $byDepartment[$row['department_code']][] = [
                'week_start' => $row['week_start'],
                'n' => $row['n'],
                'mean_days' => $row['mean_days'],
            ];
        }

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

    private static function shape(string $code, string $name, array $weeks, int $completedReviews): ?array
    {
        $result = Spc::analyse($weeks);
        $limits = $result['limits'];
        $rows = $result['weeks'];

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

<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class Spc
{
    public const MIN_COMPLETIONS_PER_WEEK = 3;

    public const CALIBRATION_WEEKS = 24;

    private const D2_MOVING_RANGE_2 = 1.128;

    public const SIGMA_MULTIPLIER = 3.0;

    public const EWMA_LAMBDA = 0.2;

    public static function weeklyTurnaround(iterable $reviews): array
    {
        $buckets = [];

        foreach ($reviews as $review) {
            $assigned = $review['assigned_at'] ?? null;
            $completed = $review['completed_at'] ?? null;
            if ($assigned === null || $completed === null) {
                continue;
            }

            $assignedAt = CarbonImmutable::parse($assigned);
            $completedAt = CarbonImmutable::parse($completed);

            $turnaround = ($completedAt->getTimestamp() - $assignedAt->getTimestamp()) / 86400;

            $weekStart = $completedAt->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

            $buckets[$review['department_code']][$weekStart][] = $turnaround;
        }

        $rows = [];
        ksort($buckets);
        foreach ($buckets as $code => $weeks) {
            ksort($weeks);
            foreach ($weeks as $weekStart => $values) {
                if (count($values) < self::MIN_COMPLETIONS_PER_WEEK) {
                    continue;
                }
                $rows[] = [
                    'department_code' => $code,
                    'week_start' => $weekStart,
                    'n' => count($values),
                    'mean_days' => array_sum($values) / count($values),
                ];
            }
        }

        return $rows;
    }

    public static function controlLimits(array $values): array
    {
        $values = array_values($values);
        $calibrationWeeks = min(self::CALIBRATION_WEEKS, count($values));
        $calibration = array_slice($values, 0, $calibrationWeeks);

        if ($calibration === []) {
            return ['center' => 0.0, 'lcl' => 0.0, 'ucl' => 0.0, 'sigma' => 0.0, 'calibration_weeks' => 0];
        }

        $center = array_sum($calibration) / count($calibration);

        $ranges = [];
        for ($i = 1; $i < count($calibration); $i++) {
            $ranges[] = abs($calibration[$i] - $calibration[$i - 1]);
        }
        $sigma = $ranges === []
            ? 0.0
            : (array_sum($ranges) / count($ranges)) / self::D2_MOVING_RANGE_2;

        $halfWidth = self::SIGMA_MULTIPLIER * $sigma;

        return [
            'center' => $center,
            'lcl' => max(0.0, $center - $halfWidth),
            'ucl' => $center + $halfWidth,
            'sigma' => $sigma,
            'calibration_weeks' => $calibrationWeeks,
        ];
    }

    public static function sampleStdDev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sumSquares = 0.0;
        foreach ($values as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / ($n - 1));
    }

    public static function ewma(array $values, float $center, float $stdDev, float $lambda = self::EWMA_LAMBDA, float $nsigmas = self::SIGMA_MULTIPLIER): array
    {
        $z = [];
        $ucl = [];
        $lcl = [];
        $violations = [];

        $previous = $center;
        $varianceFactor = $lambda / (2 - $lambda);

        foreach (array_values($values) as $i => $value) {
            $previous = $lambda * $value + (1 - $lambda) * $previous;
            $z[] = $previous;

            $spread = $nsigmas * $stdDev * sqrt($varianceFactor * (1 - (1 - $lambda) ** (2 * ($i + 1))));
            $ucl[] = $center + $spread;
            $lcl[] = $center - $spread;

            if ($previous > $center + $spread || $previous < $center - $spread) {
                $violations[] = $i;
            }
        }

        return ['z' => $z, 'ucl' => $ucl, 'lcl' => $lcl, 'violations' => $violations];
    }

    public static function analyse(array $weeks): array
    {
        $weeks = array_values($weeks);
        $values = array_map(static fn (array $week): float => (float) $week['mean_days'], $weeks);
        $limits = self::controlLimits($values);

        $calibration = array_slice($values, 0, $limits['calibration_weeks']);
        $ewma = self::ewma($values, $limits['center'], self::sampleStdDev($calibration));
        $drift = array_fill_keys($ewma['violations'], true);

        $rows = [];
        foreach ($weeks as $i => $week) {
            $mean = (float) $week['mean_days'];
            $beyond = $mean > $limits['ucl'] || $mean < $limits['lcl'];
            $drifting = isset($drift[$i]);

            $hits = [];
            if ($beyond) {
                $hits[] = 'beyond_limits';
            }
            if ($drifting) {
                $hits[] = 'ewma_drift';
            }

            $rows[] = [
                'week_start' => $week['week_start'],
                'n' => (int) $week['n'],
                'mean_days' => $mean,
                'deviation_days' => $mean - $limits['center'],
                'ewma' => $ewma['z'][$i],
                'ewma_ucl' => $ewma['ucl'][$i],
                'ewma_lcl' => $ewma['lcl'][$i],
                'status' => $hits === [] ? 'in_control' : 'out_of_control',
                'rule_hit' => $hits === [] ? null : implode('+', $hits),
            ];
        }

        return [
            'limits' => $limits,
            'weeks' => $rows,
            'trend' => self::trend($limits['center'], $ewma, $rows),
        ];
    }

    private static function trend(float $center, array $ewma, array $rows): array
    {
        if ($rows === []) {
            return ['direction' => 'steady', 'magnitude' => 0.0, 'ewma' => 0.0, 'deviation_days' => 0.0, 'drift_flagged' => false];
        }

        $last = count($rows) - 1;
        $smoothed = $ewma['z'][$last];
        $band = $ewma['ucl'][$last] - $center;
        $ratio = $band > 0 ? ($smoothed - $center) / $band : 0.0;

        $direction = 'steady';
        if ($ratio >= 0.5) {
            $direction = 'rising';
        } elseif ($ratio <= -0.5) {
            $direction = 'easing';
        }

        return [
            'direction' => $direction,
            'magnitude' => min(1.0, abs($ratio)),
            'ewma' => $smoothed,
            'deviation_days' => $smoothed - $center,
            'drift_flagged' => str_contains((string) ($rows[$last]['rule_hit'] ?? ''), 'ewma_drift'),
        ];
    }
}

<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Statistical process control over review turnaround.
 *
 * This was ported to PHP from the project's original R prototype, `r/R/spc.R`
 * (Feature 7). R is no longer part of the project — this class is the only
 * implementation. The lineage notes below are kept as the record of what the
 * port decided, because they explain the constants and the edge cases; they are
 * not instructions to go and check anything against R.
 *
 * The port was faithful to the reference down to the constants qcc uses:
 *
 *  - Weekly buckets are ISO weeks (Monday start) keyed on the completion date,
 *    and a week needs at least 3 completed reviews before its mean is trusted
 *    (`weekly_turnaround`).
 *  - Control limits come from an individuals (X-bar one) chart fitted on the
 *    FIRST 24 weeks only, so a recent slowdown cannot widen the limits that are
 *    supposed to catch it (`compute_control_limits`).
 *  - Sigma is the average moving range of 2 divided by Hartley's d2. qcc ships
 *    the *tabulated* d2 = 1.128, not 2/sqrt(pi) = 1.1283792, and the difference
 *    is visible in the third decimal of every limit — so 1.128 it is.
 *  - EWMA (lambda 0.2, 3 sigma) runs over every week with the calibration
 *    window's sample standard deviation, catching drift the Shewhart chart
 *    misses (`detect_processing_anomalies`).
 *
 * Verified against qcc 2.7 at porting time: for weekly means alternating 2/3 over 12 weeks,
 * qcc::qcc(type = "xbar.one") reports center 2.5, sigma 0.886524822695036,
 * UCL 5.15957446808511, LCL -0.159574468085107 — the values SpcTest asserts.
 */
final class Spc
{
    /** A week with fewer completions than this has too few points for a stable mean. */
    public const MIN_COMPLETIONS_PER_WEEK = 3;

    /** Limits are fitted on at most this many leading weeks. */
    public const CALIBRATION_WEEKS = 24;

    /** Hartley's d2 for a moving range of size 2, as tabulated in qcc. */
    private const D2_MOVING_RANGE_2 = 1.128;

    /** Control limit width, in sigmas (qcc's default nsigmas). */
    public const SIGMA_MULTIPLIER = 3.0;

    /** EWMA smoothing constant (qcc::ewma default). */
    public const EWMA_LAMBDA = 0.2;

    /**
     * Bucket completed reviews into per-department ISO weeks.
     *
     * Ported from `weekly_turnaround()`. Rows missing either timestamp are
     * dropped rather than treated as zero — the reference's NA handling, and
     * still the right answer: an unfinished review has no turnaround. Weeks
     * under the minimum are discarded entirely.
     *
     * @param  iterable<array{department_code: string, assigned_at: mixed, completed_at: mixed}>  $reviews
     * @return list<array{department_code: string, week_start: string, n: int, mean_days: float}>
     */
    public static function weeklyTurnaround(iterable $reviews): array
    {
        /** @var array<string, array<string, list<float>>> $buckets */
        $buckets = [];

        foreach ($reviews as $review) {
            $assigned = $review['assigned_at'] ?? null;
            $completed = $review['completed_at'] ?? null;
            if ($assigned === null || $completed === null) {
                continue;
            }

            $assignedAt = CarbonImmutable::parse($assigned);
            $completedAt = CarbonImmutable::parse($completed);

            // as.numeric(completed_at - assigned_at, units = "days") — fractional.
            $turnaround = ($completedAt->getTimestamp() - $assignedAt->getTimestamp()) / 86400;

            // floor_date(as.Date(completed_at), unit = "week", week_start = 1).
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

    /**
     * Individuals-chart control limits fitted on the leading calibration window.
     *
     * Ported from `compute_control_limits()` / qcc's `stats.xbar.one` +
     * `sd.xbar.one(std.dev = "MR", k = 2)`. LCL is clamped at 0 because a
     * turnaround cannot be negative.
     *
     * @param  list<float>  $values  Weekly means in week order.
     * @return array{center: float, lcl: float, ucl: float, sigma: float, calibration_weeks: int}
     */
    public static function controlLimits(array $values): array
    {
        $values = array_values($values);
        $calibrationWeeks = min(self::CALIBRATION_WEEKS, count($values));
        $calibration = array_slice($values, 0, $calibrationWeeks);

        if ($calibration === []) {
            return ['center' => 0.0, 'lcl' => 0.0, 'ucl' => 0.0, 'sigma' => 0.0, 'calibration_weeks' => 0];
        }

        $center = array_sum($calibration) / count($calibration);

        // Mean of the |x_i - x_{i-1}| moving ranges over the calibration window.
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

    /**
     * Sample standard deviation: n-1 denominator, as `stats::sd` in the reference.
     *
     * @param  list<float>  $values
     */
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

    /**
     * EWMA chart — ported from `qcc::ewma` for individual observations.
     *
     * z_i = lambda * x_i + (1 - lambda) * z_{i-1}, with z_0 = center, and
     * time-varying limits center +- nsigmas * sd * sqrt(lambda / (2 - lambda) *
     * (1 - (1 - lambda)^(2i))).
     *
     * @param  list<float>  $values
     * @return array{z: list<float>, ucl: list<float>, lcl: list<float>, violations: list<int>}
     *                                                                                          `violations` are zero-based indices — a deliberate divergence from the reference, which reported them one-based.
     */
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

    /**
     * Flag out-of-control weeks for one department.
     *
     * Ported from `detect_processing_anomalies()` for a single department: a week is
     * out of control when it sits beyond the Shewhart limits, when the EWMA
     * breaches its own limit, or both.
     *
     * @param  list<array{week_start: string, n: int, mean_days: float}>  $weeks  Ordered by week.
     * @return array{
     *     limits: array{center: float, lcl: float, ucl: float, sigma: float, calibration_weeks: int},
     *     weeks: list<array{week_start: string, n: int, mean_days: float, deviation_days: float, ewma: float, ewma_ucl: float, ewma_lcl: float, status: string, rule_hit: string|null}>,
     *     trend: array{direction: string, magnitude: float, ewma: float, deviation_days: float, drift_flagged: bool}
     * }
     */
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

    /**
     * Gradual slowdown reading for the weighted-trend bar.
     *
     * The EWMA is the weighted trend: a run of small increases, none of which
     * breaches a control limit on its own, still walks the smoothed value away
     * from centre. Magnitude is how far it has walked as a fraction of the EWMA
     * limit, so a full bar means the drift has reached the flagging threshold.
     *
     * @param  array{z: list<float>, ucl: list<float>, lcl: list<float>, violations: list<int>}  $ewma
     * @param  list<array<string, mixed>>  $rows
     * @return array{direction: string, magnitude: float, ewma: float, deviation_days: float, drift_flagged: bool}
     */
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

<?php

namespace App\Support;

/**
 * This project's rounding convention for analytics figures.
 *
 * PHP's `round()` rounds a half away from zero: 2.5625 to three places gives
 * 2.563. Half-to-even follows IEC 60559 and rounds a half to the nearest EVEN
 * digit: the same input gives 2.562. Neither is wrong, but they are not
 * interchangeable, and this codebase settled on half-to-even.
 *
 * The reason to keep it is not aesthetic. Every figure a user has already seen,
 * and every number in the frozen golden fixtures, was produced under
 * half-to-even. Switching to PHP's default would silently move published
 * numbers by a digit in the last place, with no code change visible anywhere
 * near the screens that show them — and the golden test would be the only thing
 * that noticed. Treat this as settled unless there is a reason to re-publish.
 *
 * Rounding is display formatting, so it belongs at the edge — the statistics in
 * Spc are computed at full precision and only rounded when shaped for a payload.
 */
final class Rounding
{
    /**
     * Round half to even (IEC 60559).
     *
     * Half-to-even is also the statistically conventional choice: rounding every
     * half in the same direction biases the mean of a rounded series upward,
     * which matters when the rounded values are then read as a trend.
     */
    public static function statistic(float $value, int $precision = 3): float
    {
        return round($value, $precision, PHP_ROUND_HALF_EVEN);
    }
}

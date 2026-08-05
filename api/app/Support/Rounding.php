<?php

namespace App\Support;

/**
 * Rounding that matches R's, for the analytics figures both engines produce.
 *
 * PHP's `round()` rounds a half away from zero: 2.5625 to three places gives
 * 2.563. R's `round()` follows IEC 60559 and rounds a half to the nearest EVEN
 * digit: the same input gives 2.562. Neither is wrong, but a figure that renders
 * as 2.563 when the R service is up and 2.562 when the PHP fallback runs is a
 * visible inconsistency for no reason — and it was caught by the parity fixture,
 * not by reasoning, which is the argument for having the fixture.
 *
 * R is the primary engine, so the fallback matches R rather than the other way
 * round: the number a user normally sees is R's, and the fallback's job is to
 * reproduce it.
 *
 * Rounding is display formatting, so it belongs at the edge — the statistics in
 * Spc are computed at full precision and only rounded when shaped for a payload.
 */
final class Rounding
{
    /**
     * Round half to even, as R's `round()` does.
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

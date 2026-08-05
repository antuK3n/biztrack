<?php

namespace App\Support;

final class Rounding
{
    public static function statistic(float $value, int $precision = 3): float
    {
        return round($value, $precision, PHP_ROUND_HALF_EVEN);
    }
}

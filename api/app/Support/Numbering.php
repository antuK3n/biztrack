<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;
use Illuminate\Database\Eloquent\Builder;

class Numbering
{
    public static function trackingId(): string
    {
        $year = now()->year;

        return sprintf('BIZ-%d-%05d', $year, self::next(
            Application::withTrashed(), 'tracking_id', "BIZ-{$year}-"
        ));
    }

    public static function permitNumber(string $prefix): string
    {
        $year = now()->year;

        return sprintf('%s-%d-%06d', $prefix, $year, self::next(
            Permit::query(), 'permit_number', "{$prefix}-{$year}-"
        ));
    }

    public static function paymentReference(): string
    {
        $year = now()->year;

        return sprintf('PAY-%d-%06d', $year, self::next(
            Payment::query(), 'reference_number', "PAY-{$year}-"
        ));
    }

    public static function ban(): string
    {
        $year = now()->year;

        return sprintf('BAN-%d-%04d', $year, self::next(
            Business::withTrashed(), 'ban', "BAN-{$year}-"
        ));
    }

    private static function next(Builder $query, string $column, string $prefix): int
    {
        $highest = $query
            ->where($column, 'like', $prefix.'%')
            ->orderByDesc($column)
            ->value($column);

        return $highest === null ? 1 : ((int) substr($highest, strlen($prefix))) + 1;
    }
}

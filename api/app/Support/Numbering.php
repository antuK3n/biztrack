<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;

/**
 * Canonical identifier generators (master plan §6.3). Prototype-grade yearly
 * counters derived from existing rows — not concurrency-safe, adequate for the
 * demo; a DB sequence replaces this at hardening.
 */
class Numbering
{
    public static function trackingId(): string
    {
        $year = now()->year;
        $n = Application::whereYear('created_at', $year)
            ->whereNotNull('tracking_id')->count() + 1;

        return sprintf('BIZ-%d-%05d', $year, $n);
    }

    public static function permitNumber(string $prefix): string
    {
        $year = now()->year;
        $n = Permit::where('permit_number', 'like', "{$prefix}-{$year}-%")->count() + 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $n);
    }

    public static function paymentReference(): string
    {
        $year = now()->year;
        $n = Payment::whereYear('created_at', $year)
            ->whereNotNull('reference_number')->count() + 1;

        return sprintf('PAY-%d-%06d', $year, $n);
    }

    public static function ban(): string
    {
        $year = now()->year;
        $n = Business::whereYear('created_at', $year)->whereNotNull('ban')->count() + 1;

        return sprintf('BAN-%d-%04d', $year, $n);
    }
}

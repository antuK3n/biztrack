<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Canonical identifier generators (master plan §6.3).
 *
 * Each number is one past the highest already issued this year. It is
 * deliberately NOT a row count: counting breaks the moment anything is deleted,
 * because the count drops and the next insert collides on the unique index.
 * That happened during testing and surfaced to the applicant as a 500 on a
 * perfectly valid business.
 *
 * Soft-deleted rows are counted for the same reason: a trashed application
 * still owns its tracking id as far as anyone holding a printout is concerned.
 *
 * Still not concurrency-safe by design (prototype scope): two simultaneous
 * inserts can read the same maximum, and the unique index is what catches it.
 * A database sequence replaces this at hardening.
 */
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

    /**
     * One past the highest identifier already issued under `$prefix`.
     *
     * The suffix is zero-padded to a fixed width, so ordering the column
     * descending as a string yields the numeric maximum. That keeps this
     * working on both SQLite (dev) and PostgreSQL (production) without any
     * database-specific substring arithmetic.
     */
    private static function next(Builder $query, string $column, string $prefix): int
    {
        $highest = $query
            ->where($column, 'like', $prefix.'%')
            ->orderByDesc($column)
            ->value($column);

        return $highest === null ? 1 : ((int) substr($highest, strlen($prefix))) + 1;
    }
}

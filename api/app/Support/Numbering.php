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

    /**
     * The business's permanent account number — `BP-YYYY-NNNN`.
     *
     * The column is still `ban` (Business Account Number, which is what a BPLO
     * counter actually calls it) but the VALUE no longer says so. It read
     * `BAN-2026-0001`, and this product has a real blacklist: `businesses`
     * carries a `status` alongside this column, and the admin side has a
     * blacklist modal. So the same three letters meant two opposite things in
     * one system, one of them a sanction — and the one an owner meets first, on
     * their own record, is the wrong one. "BAN-2026-0001" reads as a notice
     * that you have been banned.
     *
     * `BP-` for Business Permit: unambiguous, and it sits in the same family as
     * the permit numbers already on the certificate. Not `MCB-`, which is taken
     * by the Mayor's permit itself — reusing it would blur the account number
     * with one of the permits hanging off it.
     *
     * The column name stays because renaming it is migration risk across 718
     * rows and every reference to it, for no gain a user can see. If BPLO
     * confirms they print "BAN" on something the owner receives, the fix is the
     * on-screen LABEL, not this value — see docs/questions-for-malabon.md.
     */
    public static function ban(): string
    {
        $year = now()->year;

        return sprintf('BP-%d-%04d', $year, self::next(
            Business::withTrashed(), 'ban', "BP-{$year}-"
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

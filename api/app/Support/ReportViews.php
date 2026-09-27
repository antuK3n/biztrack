<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reporting views (report_businesses, report_permits, report_payments) and
 * the one piece of care they need on SQLite.
 *
 * The views themselves are defined by the 2026_09_27_000130 migration, which
 * explains what they hold and why. This class exists for the trap that
 * migration describes: on SQLite, Laravel's `->change()` rebuilds a table by
 * dropping and renaming it, SQLite re-validates every view during the rename,
 * and a view naming the table mid-rebuild stops the migration with
 * "error in view report_…". Left alone, the views would make every future
 * column change on nine tables fail on dev and demo databases — and only there,
 * since PostgreSQL alters columns in place.
 *
 * So around every `artisan migrate` on SQLite (AppServiceProvider listens for
 * MigrationsStarted / MigrationsEnded) the views are dropped first and put back
 * afterwards, by re-running the defining migration's own `up()`. Nothing
 * outside a migration run ever sees them missing. On PostgreSQL this does
 * nothing at all.
 */
class ReportViews
{
    public const NAMES = ['report_businesses', 'report_permits', 'report_payments'];

    public const MIGRATION = '2026_09_27_000130_give_reporting_tools_stable_views';

    public static function suspend(): void
    {
        if (! self::onSqlite()) {
            return;
        }
        foreach (array_reverse(self::NAMES) as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }

    public static function resume(): void
    {
        if (! self::onSqlite() || ! Schema::hasTable('migrations')) {
            return;
        }
        // Only if the defining migration is applied: after rolling it back,
        // the views are meant to be gone.
        $applied = DB::table('migrations')->where('migration', self::MIGRATION)->exists();
        if (! $applied) {
            return;
        }

        (require database_path('migrations/'.self::MIGRATION.'.php'))->up();
    }

    private static function onSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}

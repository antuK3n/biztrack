<?php

namespace App\Support;

use Illuminate\Database\Events\MigrationsEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reporting views (report_businesses, report_permits, report_payments) and
 * the care they need while migrations run.
 *
 * The views themselves are defined by the 2026_09_27_000130 migration, which
 * explains what they hold and why. This class exists because a view pins the
 * columns it reads, on both engines, in two different ways:
 *
 *  - SQLite: Laravel's `->change()` rebuilds a table by dropping and renaming
 *    it, SQLite re-validates every view during the rename, and a view naming
 *    the table mid-rebuild stops the migration with "error in view report_…".
 *  - PostgreSQL: `->change()` always emits ALTER COLUMN … TYPE, even when the
 *    type is unchanged, and PostgreSQL refuses it on any column a view reads
 *    ("cannot alter type of a column used by a view or rule"); dropping such a
 *    column is refused the same way. Measured on PostgreSQL 16 against
 *    businesses.name. This class used to do nothing on PostgreSQL on the
 *    belief that it "alters columns in place" — it does, and still refuses.
 *
 * Left alone, the views would make every future column change on the nine
 * tables they read fail — in production as well as on dev and demo. So around
 * every `artisan migrate` that has something to run (AppServiceProvider listens
 * for MigrationsStarted / MigrationsEnded) the views are dropped first and put
 * back afterwards, by re-running the defining migration's own `up()`. A run
 * with nothing pending fires neither event and touches nothing.
 *
 * ── Grants (PostgreSQL) ────────────────────────────────────────────────────
 *
 * A dropped view takes its grants with it, and the reporting role reads ONLY
 * these views, so a deploy that re-created them would silently lock Excel and
 * Power BI out. Two things put the grants back:
 *
 *  1. whoever held a privilege on a view when the run started is granted it
 *     again at the end (same process, so remembered in memory);
 *  2. the configured reporting role (config biztrack.report_role, the one
 *     `biztrack:report-role-sql` creates) is granted SELECT whenever it exists,
 *     which also covers a run that died halfway and left the views dropped —
 *     the next successful migrate restores both the views and its access.
 *
 * Views missing between a failed migration and the next successful one is the
 * cost, on either engine; a report run in that gap errors rather than reading
 * a half-migrated schema.
 */
class ReportViews
{
    public const NAMES = ['report_businesses', 'report_permits', 'report_payments'];

    public const MIGRATION = '2026_09_27_000130_give_reporting_tools_stable_views';

    /** @var list<array{view: string, grantee: string, privilege: string}> */
    private static array $grants = [];

    public static function suspend(?MigrationsEvent $event = null): void
    {
        if (self::pretending($event)) {
            return;
        }

        self::$grants = self::onPostgres() ? self::currentGrants() : [];

        foreach (array_reverse(self::NAMES) as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }

    public static function resume(?MigrationsEvent $event = null): void
    {
        if (self::pretending($event) || ! Schema::hasTable('migrations')) {
            return;
        }
        // Only if the defining migration is applied: after rolling it back,
        // the views are meant to be gone.
        $applied = DB::table('migrations')->where('migration', self::MIGRATION)->exists();
        if (! $applied) {
            return;
        }

        (require database_path('migrations/'.self::MIGRATION.'.php'))->up();

        if (self::onPostgres()) {
            self::restoreGrants();
        }
    }

    /**
     * Privileges other roles hold on the views, the owner's own excepted — the
     * owner is whoever re-creates them and gets its rights back by doing so.
     *
     * @return list<array{view: string, grantee: string, privilege: string}>
     */
    private static function currentGrants(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::NAMES), '?'));

        return array_map(
            fn ($row) => ['view' => $row->view, 'grantee' => $row->grantee, 'privilege' => $row->privilege],
            DB::select(
                "SELECT table_name AS view, grantee, privilege_type AS privilege
                   FROM information_schema.role_table_grants
                  WHERE table_schema = current_schema()
                    AND table_name IN ({$placeholders})
                    AND grantee <> current_user",
                self::NAMES,
            ),
        );
    }

    private static function restoreGrants(): void
    {
        $grants = self::$grants;
        self::$grants = [];

        $role = (string) config('biztrack.report_role', '');
        if ($role !== '' && DB::table('pg_roles')->where('rolname', $role)->exists()) {
            foreach (self::NAMES as $view) {
                $grants[] = ['view' => $view, 'grantee' => $role, 'privilege' => 'SELECT'];
            }
        }

        $grammar = DB::connection()->getQueryGrammar();
        foreach ($grants as $g) {
            // Privilege names come from the catalogue, never from input; the
            // role and view are quoted as identifiers all the same.
            if (! preg_match('/^[A-Z ]+$/', $g['privilege'])) {
                continue;
            }
            DB::statement(sprintf(
                'GRANT %s ON %s TO %s',
                $g['privilege'],
                $grammar->wrapTable($g['view']),
                $g['grantee'] === 'PUBLIC' ? 'PUBLIC' : $grammar->wrap($g['grantee']),
            ));
        }
    }

    /** `migrate --pretend` prints SQL; it must not drop anything for real. */
    private static function pretending(?MigrationsEvent $event): bool
    {
        return (bool) ($event?->options['pretend'] ?? false);
    }

    private static function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}

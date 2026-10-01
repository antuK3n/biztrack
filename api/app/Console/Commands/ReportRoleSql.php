<?php

namespace App\Console\Commands;

use App\Support\ReportViews;
use Illuminate\Console\Command;

/**
 * Print the SQL that creates the read-only PostgreSQL role for ODBC reporting.
 *
 * Ken's checklist, "Migration 2 (OUT)". Prints rather than runs, on purpose:
 *
 *  - Creating a role needs a superuser (or CREATEROLE), and the application's
 *    own database account should not hold that power just so this command can
 *    use it once.
 *  - The role needs a password, and this command never takes one. The SQL reads
 *    it from a psql variable, so MISD types it into their own terminal and it
 *    never passes through BizTrack, its logs or its shell history:
 *
 *        php artisan biztrack:report-role-sql > report-role.sql
 *        psql -U postgres -d biztrack -v report_password='…' -f report-role.sql
 *
 * The role may connect and read the three report views, and nothing else — no
 * table, and so none of the owners' contact details the views leave out. A view
 * reads its tables with its owner's rights, which is why SELECT on the views
 * alone is enough. docs/odbc.md walks through the rest.
 */
class ReportRoleSql extends Command
{
    protected $signature = 'biztrack:report-role-sql {role=biztrack_report : Name of the read-only role}';

    protected $description = 'Print SQL creating a read-only PostgreSQL role that can see only the report views (for ODBC tools).';

    public function handle(): int
    {
        $role = (string) $this->argument('role');
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            $this->error('Use a lower-case role name: letters, digits and underscores.');

            return self::FAILURE;
        }

        $database = (string) config('database.connections.pgsql.database', 'biztrack');
        $views = implode(', ', ReportViews::NAMES);

        $this->line(<<<SQL
            -- Read-only reporting role for ODBC tools (Excel, Power BI).
            -- Run as a PostgreSQL superuser:
            --   psql -U postgres -d {$database} -v report_password='…' -f this-file.sql
            -- Re-running is safe; it only resets the password and the grants.

            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE {$role} LOGIN;
                END IF;
            END
            \$\$;

            ALTER ROLE {$role} WITH LOGIN PASSWORD :'report_password'
                NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
            -- Belt and braces: every session this role opens is read-only.
            ALTER ROLE {$role} SET default_transaction_read_only = on;

            GRANT CONNECT ON DATABASE {$database} TO {$role};
            GRANT USAGE ON SCHEMA public TO {$role};
            REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {$role};
            GRANT SELECT ON {$views} TO {$role};
            SQL);

        return self::SUCCESS;
    }
}

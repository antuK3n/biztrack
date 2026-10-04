<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three read-only views for Excel, Power BI and anything else that speaks ODBC.
 *
 * Ken's checklist, 27 September 2026, "Migration 2 (OUT)". MISD and BPLO want
 * to build their own reports without a developer in the loop. Pointing those
 * tools at the raw tables would work until the next migration renamed a column
 * or split a table — this codebase has done both, often — and every saved
 * report would break at once. The views are the contract instead: plain column
 * names that say what they hold, stable across schema changes, and the only
 * objects the read-only reporting role may see (docs/odbc.md).
 *
 *   report_businesses  one row per business on the register
 *   report_permits     one row per permit, with its business and office
 *   report_payments    one row per payment, with its filing and business
 *
 * ── What is deliberately left out (RA 10173) ───────────────────────────────
 *
 * Owners' emails, mobile numbers, TINs, street addresses beyond the barangay,
 * uploaded documents and anything under `users` except an owner's name. A
 * report that counts businesses per barangay needs none of it, and a view
 * handed to a BI tool is a copy of the data nobody here controls afterwards.
 * If a report genuinely needs one of those, add it here on purpose — do not
 * grant the reporting role a table.
 *
 * ── Portable SQL ───────────────────────────────────────────────────────────
 *
 * The same statements run on SQLite (dev, demo) and PostgreSQL 16 (production):
 * `||` for concatenation, COALESCE, CASE, correlated subqueries with LIMIT 1 —
 * nothing either engine lacks. The barangay is a subquery rather than a join
 * so a business with a second address row is still one row.
 *
 * Soft-deleted businesses are excluded, as every screen excludes them.
 *
 * ── A trap for later migrations (both engines) ─────────────────────────────
 *
 * On SQLite, Laravel's `->change()` rebuilds a table by creating a copy,
 * dropping the original and renaming the copy back. SQLite validates every
 * view during that rename, and while the original is dropped a view naming it
 * fails validation — so a later `->change()` on businesses, permits, payments,
 * applications, business_addresses, barangays, permit_types, departments,
 * users or legacy_owners would stop with "error in view report_…" (measured on
 * a copy of the register before the fix below). PostgreSQL does not rebuild,
 * but it refuses ALTER COLUMN … TYPE (which `->change()` always emits) and
 * DROP COLUMN on any column a view reads — measured on PostgreSQL 16, 30
 * September 2026. This paragraph used to say PostgreSQL was unaffected; it
 * was wrong.
 *
 * App\Support\ReportViews handles it, so a later migration needs to do
 * nothing: on either engine the views are dropped when `artisan migrate`
 * starts and re-created by this `up()` when it ends (AppServiceProvider), with
 * their PostgreSQL grants put back. This `up()` therefore starts by dropping
 * whatever is there, so running it twice is safe.
 * Change a view by editing it here AND in a new migration that re-runs the
 * CREATE — never by editing only this file, which production has already run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->down();

        DB::statement(<<<'SQL'
            CREATE VIEW report_businesses AS
            SELECT
                b.id AS business_id,
                b.ban AS account_no,
                b.name AS business_name,
                b.trade_name AS trade_name,
                COALESCE(b.form_of_organization, b.registration_type) AS organization_type,
                b.registration_number AS registration_number,
                b.status AS status,
                (SELECT g.name FROM business_addresses a
                    JOIN barangays g ON g.id = a.barangay_id
                    WHERE a.business_id = b.id ORDER BY a.id LIMIT 1) AS barangay,
                COALESCE(
                    u.first_name || ' ' || u.last_name,
                    lo.first_name || ' ' || lo.last_name
                ) AS owner_name,
                CASE WHEN b.owner_user_id IS NULL THEN 0 ELSE 1 END AS owner_has_account,
                CASE WHEN b.legacy_id IS NULL THEN 0 ELSE 1 END AS from_old_register,
                b.legacy_id AS old_register_id,
                b.created_at AS registered_at,
                b.status_changed_at AS status_changed_at
            FROM businesses b
            LEFT JOIN users u ON u.id = b.owner_user_id AND u.deleted_at IS NULL
            LEFT JOIN legacy_owners lo ON lo.id = b.legacy_owner_id
            WHERE b.deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW report_permits AS
            SELECT
                p.id AS permit_id,
                p.permit_number AS permit_number,
                t.code AS permit_type_code,
                t.name AS permit_type,
                d.name AS issuing_office,
                p.status AS status,
                p.valid_from AS valid_from,
                p.valid_until AS valid_until,
                p.issued_at AS issued_at,
                prior.permit_number AS renews_permit_number,
                app.tracking_id AS tracking_id,
                app.application_type AS application_type,
                b.id AS business_id,
                b.ban AS account_no,
                b.name AS business_name,
                (SELECT g.name FROM business_addresses a
                    JOIN barangays g ON g.id = a.barangay_id
                    WHERE a.business_id = b.id ORDER BY a.id LIMIT 1) AS barangay,
                CASE WHEN p.legacy_id IS NULL THEN 0 ELSE 1 END AS from_old_register,
                p.legacy_id AS old_register_id
            FROM permits p
            JOIN permit_types t ON t.id = p.permit_type_id
            LEFT JOIN departments d ON d.id = t.issuing_department_id
            LEFT JOIN permits prior ON prior.id = p.prior_permit_id
            LEFT JOIN applications app ON app.id = p.application_id
            JOIN businesses b ON b.id = p.business_id AND b.deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW report_payments AS
            SELECT
                pay.id AS payment_id,
                pay.reference_number AS reference_number,
                pay.amount AS amount,
                pay.method AS method,
                pay.status AS status,
                pay.paid_at AS paid_at,
                app.tracking_id AS tracking_id,
                app.application_type AS application_type,
                app.payment_mode AS payment_mode,
                b.id AS business_id,
                b.ban AS account_no,
                b.name AS business_name,
                (SELECT g.name FROM business_addresses a
                    JOIN barangays g ON g.id = a.barangay_id
                    WHERE a.business_id = b.id ORDER BY a.id LIMIT 1) AS barangay
            FROM payments pay
            JOIN applications app ON app.id = pay.application_id AND app.deleted_at IS NULL
            JOIN businesses b ON b.id = app.business_id AND b.deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS report_payments');
        DB::statement('DROP VIEW IF EXISTS report_permits');
        DB::statement('DROP VIEW IF EXISTS report_businesses');
    }
};

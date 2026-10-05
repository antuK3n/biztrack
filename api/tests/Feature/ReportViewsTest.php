<?php

use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;
use App\Support\LegacyImport\LegacyImporter;
use App\Support\LegacyImport\Sources\CsvSource;
use App\Support\ReportViews;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Migration 2 (OUT) — stable views for ODBC reporting tools (Ken's checklist,
 * 27 September 2026). The views are the contract Excel and Power BI read, so
 * what is pinned here is their shape: every row the register holds, the plain
 * column names, and none of the owners' contact details.
 */

it('reports every live business, permit and payment through the views', function () {
    expect(DB::table('report_businesses')->count())->toBe(Business::count())
        ->and(DB::table('report_permits')->count())->toBe(Permit::whereHas('business')->count())
        ->and(DB::table('report_payments')->count())->toBe(Payment::whereHas('application', fn ($a) => $a->whereHas('business'))->count());
});

it('names its columns plainly and leaves out owners’ contact details', function () {
    $business = (array) DB::table('report_businesses')->first();
    $permit = (array) DB::table('report_permits')->first();
    $payment = (array) DB::table('report_payments')->first();

    expect(array_keys($business))->toContain('account_no', 'business_name', 'barangay', 'owner_name', 'status', 'from_old_register')
        ->and(array_keys($permit))->toContain('permit_number', 'permit_type', 'issuing_office', 'valid_until', 'business_name', 'barangay')
        ->and(array_keys($payment))->toContain('reference_number', 'amount', 'paid_at', 'tracking_id', 'business_name');

    foreach ([$business, $permit, $payment] as $row) {
        expect(array_keys($row))->not->toContain('email', 'owner_email', 'mobile_number', 'tin', 'password');
    }
});

it('shows an imported, unclaimed business with the old register’s owner name', function () {
    $path = tempnam(sys_get_temp_dir(), 'report-views');
    file_put_contents($path, "legacy_business_id,business_name,owner_first_name,owner_last_name,address_line,barangay,legacy_permit_id,permit_type,permit_number,valid_from,valid_until\n"
        ."R-1,Report Shop,Ana,Cruz,3 Street,Longos,RP-1,BUSINESS,OLD-R-1,2025-01-01,2099-12-31\n");
    app(LegacyImporter::class)->run(new CsvSource($path, 'export.csv'));
    unlink($path);

    $row = DB::table('report_businesses')->where('old_register_id', 'R-1')->first();
    expect($row->owner_name)->toBe('Ana Cruz')
        ->and((int) $row->owner_has_account)->toBe(0)
        ->and((int) $row->from_old_register)->toBe(1)
        ->and($row->barangay)->toBe('Longos');

    $permit = DB::table('report_permits')->where('permit_number', 'OLD-R-1')->first();
    expect($permit->tracking_id)->toBeNull()
        ->and($permit->issuing_office)->not->toBeNull();
});

/** The report views that exist right now, on whichever engine is connected. */
function reportViewNames(): array
{
    return collect(Schema::getViews())->pluck('name')
        ->filter(fn ($name) => str_starts_with($name, 'report_'))
        ->sort()->values()->all();
}

/*
 * Both engines, not only SQLite. SQLite cannot rebuild a table a view reads;
 * PostgreSQL cannot ALTER COLUMN … TYPE or DROP COLUMN one. Either way a later
 * `->change()` on a reported table would fail unless the views step aside.
 */
it('steps the views aside while migrations run, and puts them back after', function () {
    event(new MigrationsStarted('up'));
    expect(reportViewNames())->toBe([]);

    event(new MigrationsEnded('up'));
    expect(reportViewNames())->toBe(collect(ReportViews::NAMES)->sort()->values()->all());
});

it('leaves the views alone when migrate only pretends', function () {
    event(new MigrationsStarted('up', ['pretend' => true]));
    expect(reportViewNames())->toBe(collect(ReportViews::NAMES)->sort()->values()->all());
    event(new MigrationsEnded('up', ['pretend' => true]));
});

/*
 * `payments` because no other table points a foreign key at it: SQLite's
 * rebuild drops the table, and inside the test's open transaction it cannot
 * switch foreign keys off to do that with rows pointing in. A real migration
 * runs outside one. Widening the column is the smallest change that is still a
 * real ALTER on both engines.
 */
it('lets a migration change a column the views read', function () {
    event(new MigrationsStarted('up'));
    Schema::table('payments', fn (Blueprint $t) => $t->string('method', 300)->change());
    event(new MigrationsEnded('up'));

    expect(DB::table('report_payments')->count())
        ->toBe(Payment::whereHas('application', fn ($a) => $a->whereHas('business'))->count());
});

/*
 * A re-created view has lost its grants, and the reporting login reads nothing
 * BUT the views — so a deploy that migrated would lock Excel and Power BI out
 * without a word. PostgreSQL only: SQLite has no logins.
 */
it('gives the reporting login its access back after the views are re-created', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Grants exist only on PostgreSQL.');
    }

    // Roles are cluster-wide; created inside the test's transaction, so the
    // rollback takes them away again.
    $configured = 'report_cfg_'.bin2hex(random_bytes(4));
    $other = 'report_other_'.bin2hex(random_bytes(4));
    DB::statement("CREATE ROLE {$configured} NOLOGIN");
    DB::statement("CREATE ROLE {$other} NOLOGIN");
    config(['biztrack.report_role' => $configured]);
    // The other role holds a grant when the run starts, and must keep it.
    DB::statement("GRANT SELECT ON report_permits TO {$other}");

    event(new MigrationsStarted('up'));
    event(new MigrationsEnded('up'));

    $can = fn (string $role, string $view) => (bool) DB::selectOne(
        "SELECT has_table_privilege(?, ?, 'SELECT') AS ok", [$role, $view]
    )->ok;

    foreach (ReportViews::NAMES as $view) {
        expect($can($configured, $view))->toBeTrue("{$configured} lost SELECT on {$view}");
    }
    expect($can($other, 'report_permits'))->toBeTrue()
        ->and($can($other, 'report_businesses'))->toBeFalse();
});

it('prints a read-only role limited to the report views, and never asks for its password', function () {
    Artisan::call('biztrack:report-role-sql');
    $sql = Artisan::output();

    expect($sql)->toContain("PASSWORD :'report_password'")
        ->and($sql)->toContain('GRANT SELECT ON report_businesses, report_permits, report_payments TO biztrack_report')
        ->and($sql)->toContain('default_transaction_read_only = on')
        ->and($sql)->not->toMatch('/GRANT SELECT ON (ALL TABLES|businesses|users)/');
});

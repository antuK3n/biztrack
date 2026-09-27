<?php

use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;
use App\Support\LegacyImport\LegacyImporter;
use App\Support\LegacyImport\Sources\OdbcSource;
use App\Support\ReportViews;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE export (legacy_business_id TEXT, business_name TEXT, owner_first_name TEXT, owner_last_name TEXT, address_line TEXT, barangay TEXT, legacy_permit_id TEXT, permit_type TEXT, permit_number TEXT, valid_from TEXT, valid_until TEXT)');
    $pdo->exec("INSERT INTO export VALUES ('R-1', 'Report Shop', 'Ana', 'Cruz', '3 Street', 'Longos', 'RP-1', 'BUSINESS', 'OLD-R-1', '2025-01-01', '2099-12-31')");
    app(LegacyImporter::class)->run(new OdbcSource('X', table: 'export', connect: fn () => $pdo));

    $row = DB::table('report_businesses')->where('old_register_id', 'R-1')->first();
    expect($row->owner_name)->toBe('Ana Cruz')
        ->and((int) $row->owner_has_account)->toBe(0)
        ->and((int) $row->from_old_register)->toBe(1)
        ->and($row->barangay)->toBe('Longos');

    $permit = DB::table('report_permits')->where('permit_number', 'OLD-R-1')->first();
    expect($permit->tracking_id)->toBeNull()
        ->and($permit->issuing_office)->not->toBeNull();
});

it('steps the views aside while migrations run on SQLite, and puts them back after', function () {
    event(new MigrationsStarted('up'));
    expect(DB::select("SELECT name FROM sqlite_master WHERE type = 'view' AND name LIKE 'report_%'"))->toBe([]);

    event(new MigrationsEnded('up'));
    expect(collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'view'"))->pluck('name')->sort()->values()->all())
        ->toBe(collect(ReportViews::NAMES)->sort()->values()->all());
});

it('prints a read-only role limited to the report views, and never asks for its password', function () {
    Artisan::call('biztrack:report-role-sql');
    $sql = Artisan::output();

    expect($sql)->toContain("PASSWORD :'report_password'")
        ->and($sql)->toContain('GRANT SELECT ON report_businesses, report_permits, report_payments TO biztrack_report')
        ->and($sql)->toContain('default_transaction_read_only = on')
        ->and($sql)->not->toMatch('/GRANT SELECT ON (ALL TABLES|businesses|users)/');
});

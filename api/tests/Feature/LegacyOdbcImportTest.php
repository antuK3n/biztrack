<?php

use App\Models\Business;
use App\Models\Permit;
use App\Support\LegacyImport\LegacyImporter;
use App\Support\LegacyImport\Sources\OdbcSource;
use App\Support\LegacyImport\SourceUnreadable;

/*
 * Migration 2 (IN) — importing from an ODBC source (Ken's checklist,
 * 27 September 2026).
 *
 * No ODBC database exists here, so the source is faked the one way that still
 * exercises every line of OdbcSource past the connect: a PDO over an in-memory
 * SQLite table shaped like an old system's export, with its own column names.
 * The query aliases them to the template's, exactly as MISD would against the
 * real source, and the rows then go through the same LegacyImporter as a CSV.
 */

/** A stand-in for the city's old database: its own table, its own column names. */
function fakeOldRegister(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE bpls_master (
        biz_no TEXT, biz_name TEXT, own_fname TEXT, own_lname TEXT, street TEXT, brgy TEXT,
        prm_id TEXT, prm_kind TEXT, prm_no TEXT, date_issued TEXT, date_expiry TEXT
    )');
    $insert = $pdo->prepare('INSERT INTO bpls_master VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute(['9001', 'Panaderia Uno', 'Jose', 'Bautista', '1 Rizal Ave', 'CATMON', 'M-1', 'BUSINESS', 'OLDBP-9001', '2025-01-10 00:00:00', '2099-12-31 00:00:00']);
    $insert->execute(['9002', 'Carinderia Dos', 'Rosa', 'Lim', '2 Rizal Ave', 'Nowhere', 'M-2', 'BUSINESS', 'OLDBP-9002', '2025-01-10 00:00:00', '2099-12-31 00:00:00']);

    return $pdo;
}

const OLD_REGISTER_QUERY = 'SELECT biz_no AS legacy_business_id, biz_name AS business_name,
    own_fname AS owner_first_name, own_lname AS owner_last_name, street AS address_line,
    brgy AS barangay, prm_id AS legacy_permit_id, prm_kind AS permit_type,
    prm_no AS permit_number, date_issued AS valid_from, date_expiry AS valid_until
    FROM bpls_master';

afterEach(function () {
    OdbcSource::$assumeAvailable = null;
});

it('dry-runs and imports an ODBC query through the same pipeline as a CSV', function () {
    $source = new OdbcSource('OLDBPLS', query: OLD_REGISTER_QUERY, connect: fn () => fakeOldRegister());
    $importer = app(LegacyImporter::class);

    $preview = $importer->preview($source);
    expect($preview['total_rows'])->toBe(2)
        ->and($preview['will_create'])->toBe(1)
        ->and($preview['rejected'])->toBe(1)
        ->and($preview['rejects'][0]['reasons'][0]['kind'])->toBe('unknown_barangay');
    expect(Business::where('legacy_id', '9001')->exists())->toBeFalse();

    $result = $importer->run($source);
    expect($result['will_create'])->toBe(1);

    $business = Business::where('legacy_id', '9001')->firstOrFail();
    expect($business->owner_user_id)->toBeNull()
        ->and($business->address->barangay->name)->toBe('Catmon')
        // ODBC hands dates back as timestamps; the time is dropped.
        ->and(Permit::where('legacy_id', 'M-1')->first()->valid_from->toDateString())->toBe('2025-01-10');
});

it('reads a whole table when the source already uses the template’s column names', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE export (legacy_business_id TEXT, business_name TEXT, owner_first_name TEXT, owner_last_name TEXT, address_line TEXT, barangay TEXT)');
    $pdo->exec("INSERT INTO export VALUES ('T-1', 'Table Shop', 'Ana', 'Cruz', '3 Street', 'Tañong')");

    $result = app(LegacyImporter::class)->run(new OdbcSource('OLDBPLS', table: 'export', connect: fn () => $pdo));

    expect($result['will_create'])->toBe(1)
        ->and(Business::where('legacy_id', 'T-1')->first()->address->barangay->name)->toBe('Tañong');
});

it('refuses anything but a single SELECT or a plain table name', function (?string $table, ?string $query) {
    expect(fn () => OdbcSource::statementFor($table, $query))->toThrow(SourceUnreadable::class);
})->with([
    'an update' => [null, 'UPDATE bpls_master SET biz_name = NULL'],
    'two statements' => [null, 'SELECT 1; DROP TABLE bpls_master'],
    'a table name with SQL in it' => ['bpls_master; DROP TABLE x', null],
    'both a table and a query' => ['bpls_master', 'SELECT 1'],
    'neither' => [null, null],
]);

it('says the source is missing a column instead of importing blanks', function () {
    $source = new OdbcSource('OLDBPLS', query: 'SELECT biz_no AS legacy_business_id FROM bpls_master', connect: fn () => fakeOldRegister());

    expect(fn () => app(LegacyImporter::class)->preview($source))
        ->toThrow(SourceUnreadable::class, 'business_name');
});

it('turns a connection failure into a plain message, not a crash', function () {
    $source = new OdbcSource('OLDBPLS', table: 'bpls_master', connect: fn () => throw new PDOException('[unixODBC][Driver Manager]Data source name not found'));

    expect(fn () => app(LegacyImporter::class)->preview($source))
        ->toThrow(SourceUnreadable::class, 'Data source name not found');
});

it('says plainly that pdo_odbc is missing when the server lacks it', function () {
    OdbcSource::$assumeAvailable = false;

    $guide = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/legacy-imports/guide')->assertOk()->json('data.odbc');
    expect($guide['available'])->toBeFalse()
        ->and($guide['message'])->toContain('pdo_odbc');

    test()->postJson('/api/v1/admin/legacy-imports/odbc', ['dsn' => 'OLDBPLS', 'table' => 'bpls_master'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.dsn.0', fn ($m) => str_contains($m, 'pdo_odbc is not installed'));

    // And the default connector refuses before PDO is ever asked.
    expect(fn () => iterator_to_array((new OdbcSource('OLDBPLS', table: 'bpls_master'))->rows()))
        ->toThrow(SourceUnreadable::class, 'pdo_odbc');
});

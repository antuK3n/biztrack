<?php

use App\Models\Permit;
use App\Models\PermitType;
use Illuminate\Support\Facades\DB;

/*
 * Migration 2026_10_05_100000: Business Permits issued under the 20 January
 * term move to 31 December of the year before (Ken, 5 October 2026; see
 * RenewalSeason). The five clearances, and any Business Permit already on
 * another date, are left exactly as they were.
 */

function termMigrationPermit(string $code, string $validUntil): Permit
{
    $business = Permit::query()->value('business_id');

    return Permit::create([
        'permit_number' => 'TERM-'.$code.'-'.uniqid(),
        'business_id' => $business,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => 'active',
        'valid_from' => '2026-02-01',
        'valid_until' => $validUntil,
        'issued_at' => '2026-02-01 09:00:00',
    ]);
}

it('moves a Business Permit ending 20 January to 31 December of the year before, once', function () {
    $moved = termMigrationPermit(PermitType::OUTCOME_CODE, '2027-01-20');
    $lapsed = termMigrationPermit(PermitType::OUTCOME_CODE, '2026-01-20');
    $paper = termMigrationPermit(PermitType::OUTCOME_CODE, '2026-10-12');
    $clearance = termMigrationPermit('SANITARY', '2027-01-20');

    $migration = require database_path('migrations/2026_10_05_100000_the_mayors_permit_expires_on_31_december.php');
    $migration->up();
    // Idempotent: a second run finds nothing on 20 January and moves nothing.
    $migration->up();

    $stored = fn (Permit $p) => (string) DB::table('permits')->where('id', $p->id)->value('valid_until');
    $date = fn (Permit $p) => substr($stored($p), 0, 10);
    $before = strlen($stored($clearance));

    expect($date($moved))->toBe('2026-12-31')
        ->and($date($lapsed))->toBe('2025-12-31')
        ->and($date($paper))->toBe('2026-10-12')
        ->and($date($clearance))->toBe('2027-01-20')
        // Stored in the same shape as before, time part and all.
        ->and(strlen($stored($moved)))->toBe($before);
});

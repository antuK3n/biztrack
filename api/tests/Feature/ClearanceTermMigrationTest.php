<?php

use App\Models\Application;
use App\Models\Permit;
use App\Models\PermitType;
use Illuminate\Support\Facades\DB;

/*
 * Migration 2026_10_20_000100: a clearance the system issued to 31 December
 * of its own year moves to a year from its `valid_from` [client, 20 October
 * 2026: "the rest 1 year on the date issued"]. The Mayor's Permit, a renewal
 * already on its anniversary, and a permit recorded from paper are left alone.
 */

function clearanceTermPermit(string $code, string $from, string $until, bool $issuedHere = true): Permit
{
    $app = Application::query()->first();

    return Permit::create([
        'permit_number' => 'CTERM-'.$code.'-'.uniqid(),
        'business_id' => $app->business_id,
        'application_id' => $issuedHere ? $app->id : null,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => 'active',
        'valid_from' => $from,
        'valid_until' => $until,
        'issued_at' => $from.' 09:00:00',
    ]);
}

it('moves a clearance anchored to 31 December to a year from issue, once', function () {
    $anchored = clearanceTermPermit('ZONING', '2026-10-06', '2026-12-31');
    $renewed = clearanceTermPermit('SANITARY', '2026-01-01', '2027-01-01');
    $mayors = clearanceTermPermit(PermitType::OUTCOME_CODE, '2026-10-06', '2026-12-31');
    $paper = clearanceTermPermit('FSIC', '2026-03-01', '2026-12-31', issuedHere: false);

    $migration = require database_path('migrations/2026_10_20_000100_a_clearance_runs_one_year_from_issue.php');
    $migration->up();
    $migration->up();

    $date = fn (Permit $p) => substr((string) DB::table('permits')->where('id', $p->id)->value('valid_until'), 0, 10);

    expect($date($anchored))->toBe('2027-10-06')
        ->and($date($renewed))->toBe('2027-01-01')
        ->and($date($mayors))->toBe('2026-12-31')
        ->and($date($paper))->toBe('2026-12-31');
});

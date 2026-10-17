<?php

use App\Support\DashboardAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Three dashboard figures the client fixed or added on 18 October 2026.
 *
 *  - New and Closed Businesses counted every business RECORD created and
 *    every one removed, so test records made and deleted the same month read
 *    as 34 openings and 22 closures on a register of 24. It now counts real
 *    openings (the first submitted filing, or the first permit for a business
 *    from the old register) and real closures (a retired Mayor's Permit, a
 *    blacklisting), and a removed business in neither.
 *  - Fees Collected (new): completed payments this month and this year, by
 *    the permit each fee line was for.
 *  - Suspended Permits (new): how many, how many for over 30 days, the oldest.
 *
 * Every case runs in March 2031, years after anything the demo seed writes.
 * Timestamps are written as the register stores them: Manila clock time,
 * since 5 October 2026 (config/app.php).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-20 04:00:00', 'UTC')); // 20 March, noon in Manila
});

/* ── new and closed businesses ─────────────────────────────────────────── */

function marchMovement(): array
{
    return collect(DashboardAnalytics::build()['business_movement']['rows'])->firstWhere('month', '2031-03');
}

it('counts a business as new in the month of its first submitted filing, not when its record was created', function () {
    $before = marchMovement();

    $opened = anaBusiness(['created_at' => '2031-03-02 01:00:00']);
    anaFiling($opened, ['status' => 'for_approval', 'submitted_at' => '2031-03-05 01:00:00']);

    // Started as a draft in January, submitted in March: it opened in March.
    $late = anaBusiness(['created_at' => '2031-01-10 01:00:00']);
    anaFiling($late, ['status' => 'for_approval', 'submitted_at' => '2031-03-10 01:00:00']);

    // A record with only a draft has applied for nothing.
    $draftOnly = anaBusiness(['created_at' => '2031-03-03 01:00:00']);
    anaFiling($draftOnly, ['status' => 'draft', 'submitted_at' => null]);

    $january = collect(DashboardAnalytics::build()['business_movement']['rows'])->firstWhere('month', '2031-01');

    expect(marchMovement()['registered'])->toBe($before['registered'] + 2)
        ->and($january['registered'])->toBe(0);
});

it('ignores a business created and removed in the window, in both columns', function () {
    $before = marchMovement();

    // The pattern behind October's 34/22: a test business filed, then deleted.
    $test = anaBusiness(['created_at' => '2031-03-04 01:00:00', 'deleted_at' => '2031-03-06 01:00:00']);
    anaFiling($test, ['status' => 'approved', 'submitted_at' => '2031-03-04 02:00:00']);
    anaPermit($test, 'BUSINESS', ['issued_at' => '2031-03-05 01:00:00', 'status' => 'retired', 'updated_at' => '2031-03-06 00:00:00']);

    // Removing a record is not a business closing either.
    anaBusiness(['created_at' => '2029-05-01 01:00:00', 'deleted_at' => '2031-03-12 01:00:00']);

    $after = marchMovement();

    expect($after['registered'])->toBe($before['registered'])
        ->and($after['closed'])->toBe($before['closed']);
});

it('counts a real closure: BPLO retiring the Mayor’s Permit', function () {
    $before = marchMovement();

    $business = anaBusiness(['created_at' => '2029-05-01 01:00:00']);
    anaFiling($business, ['status' => 'approved', 'submitted_at' => '2029-05-02 01:00:00']);
    anaPermit($business, 'BUSINESS', [
        'issued_at' => '2029-05-10 01:00:00',
        'status' => 'retired',
        'updated_at' => '2031-03-12 03:00:00', // the retirement
    ]);
    // A retired clearance is not the business closing; only the Mayor's Permit is.
    $other = anaBusiness(['created_at' => '2029-05-01 01:00:00']);
    anaPermit($other, 'SANITARY', ['issued_at' => '2029-05-10 01:00:00', 'status' => 'retired', 'updated_at' => '2031-03-12 03:00:00']);

    expect(marchMovement()['closed'])->toBe($before['closed'] + 1);
});

it('opens a business from the old register on its first permit, not on the day it was imported', function () {
    $before = marchMovement();

    // Imported in March with a permit the old register issued in 2028.
    $imported = anaBusiness(['created_at' => '2031-03-08 01:00:00']);
    anaPermit($imported, 'BUSINESS', ['issued_at' => null, 'valid_from' => '2028-01-15', 'application_id' => null]);

    expect(marchMovement()['registered'])->toBe($before['registered']);
});

/* ── fees collected ────────────────────────────────────────────────────── */

/**
 * A completed (or other) payment on a fresh filing, settling an assessment
 * with these lines.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function feePayment(array $lines, ?string $paidAt, string $status = 'completed', ?float $amount = null, array $business = []): void
{
    $filing = anaFiling(anaBusiness($business), ['status' => 'approved', 'submitted_at' => '2031-01-05 01:00:00']);
    $total = array_sum(array_column($lines, 'amount'));
    $assessment = DB::table('fee_assessments')->insertGetId([
        'application_id' => $filing,
        'line_items' => json_encode($lines),
        'total_amount' => $total,
        'created_at' => '2031-01-05 01:00:00',
        'updated_at' => '2031-01-05 01:00:00',
    ]);
    DB::table('payments')->insert([
        'application_id' => $filing,
        'fee_assessment_id' => $assessment,
        'amount' => $amount ?? $total,
        'method' => 'gcash',
        'status' => $status,
        'paid_at' => $paidAt,
        'created_at' => '2031-01-05 01:00:00',
        'updated_at' => '2031-01-05 01:00:00',
    ]);
}

function feeLine(string $label, float $amount, ?array $permits = null): array
{
    return ['label' => $label, 'amount' => $amount] + ($permits === null ? [] : ['permit_codes' => $permits]);
}

function seedFees(): void
{
    // This month: a Business Permit bill with a Sanitary fee folded into it.
    feePayment([
        feeLine('Mayor’s permit fee', 1000, ['BUSINESS']),
        feeLine('Sanitary inspection fee', 200, ['SANITARY']),
        feeLine('Sanitary Permit fee (June 2030, unbilled until now)', 300),
        feeLine('Late surcharge and interest — Sanitary Permit (2 months late)', 50),
        feeLine('Surcharge for late renewal (25%, Sec. 8A.04)', 100),
    ], '2031-03-10 01:00:00');
    // An instalment: half of a 200 Zoning bill.
    feePayment([feeLine('Locational clearance fee', 200, ['ZONING'])], '2031-03-11 01:00:00', 'completed', 100.0);
    // Half past midnight on the 1st: this month.
    feePayment([feeLine('City Environmental Certificate fee', 40, ['CEC'])], '2031-03-01 00:30:00');
    // This year, not this month.
    feePayment([feeLine('Fire Code fee', 500, ['FSIC'])], '2031-02-05 01:00:00');

    // None of these is money collected this year.
    feePayment([feeLine('Mayor’s permit fee', 9999, ['BUSINESS'])], null, 'pending');
    feePayment([feeLine('Mayor’s permit fee', 4444, ['BUSINESS'])], '2031-03-09 01:00:00', 'failed');
    feePayment([feeLine('Mayor’s permit fee', 777, ['BUSINESS'])], '2030-12-15 01:00:00');
    feePayment([feeLine('Mayor’s permit fee', 888, ['BUSINESS'])], '2031-03-09 01:00:00', 'completed', null, ['deleted_at' => '2031-03-15 01:00:00']);
}

it('sums completed payments only, this month and this year, and gives a folded Sanitary fee to Sanitary', function () {
    seedFees();

    $fees = DashboardAnalytics::build()['fees_collected'];
    $rows = collect($fees['rows'])->keyBy('code');

    expect(array_column($fees['rows'], 'code'))->toBe(['BUSINESS', 'SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'CEC', 'OTHER'])
        ->and($fees['month_start'])->toBe('2031-03-01')
        ->and($fees['year_start'])->toBe('2031-01-01')
        ->and($rows['BUSINESS']['month'])->toEqual(1000.0)
        // 200 billed as Sanitary, plus the 300 deferred Sanitary fee that rode on the Business Permit bill.
        ->and($rows['SANITARY']['month'])->toEqual(500.0)
        ->and($rows['ZONING']['month'])->toEqual(100.0)
        ->and($rows['CEC']['month'])->toEqual(40.0)
        ->and($rows['FSIC']['month'])->toEqual(0.0)
        ->and($rows['FSIC']['year'])->toEqual(500.0)
        // The penalty and the surcharge belong to no single permit.
        ->and($rows['OTHER']['month'])->toEqual(150.0)
        ->and($fees['month_total'])->toEqual(1790.0)
        ->and($fees['year_total'])->toEqual(2290.0)
        ->and($fees['payments_month'])->toBe(3)
        ->and($fees['payments_year'])->toBe(4);
});

it('shows one office only its own permit’s fees, and no Other row', function () {
    seedFees();

    $fees = DashboardAnalytics::build(12, 'CHO')['fees_collected'];

    expect(array_column($fees['rows'], 'code'))->toBe(['SANITARY'])
        ->and($fees['rows'][0]['month'])->toEqual(500.0)
        ->and($fees['month_total'])->toEqual(500.0)
        ->and($fees['year_total'])->toEqual(500.0)
        ->and($fees['payments_month'])->toBe(1);
});

/* ── suspended permits ─────────────────────────────────────────────────── */

/** A suspended permit on a fresh business. */
function suspendedPermit(string $typeCode, ?string $since, ?string $waitsOn, array $business = []): int
{
    return anaPermit(anaBusiness($business), $typeCode, [
        'status' => 'suspended',
        'issued_at' => '2030-06-01 01:00:00',
        'suspended_at' => $since,
        'suspended_for_permit_type_id' => $waitsOn === null ? null : anaPermitTypeId($waitsOn),
    ]);
}

it('counts suspended permits and how many have been suspended over 30 days', function () {
    $before = DashboardAnalytics::build()['suspended_permits'];

    suspendedPermit('BUSINESS', '2031-01-10 01:00:00', 'SANITARY');   // 69 days
    // The register's cutoff is the end of the day 30 days back: 18 February.
    suspendedPermit('BUSINESS', '2031-02-18 12:00:00', 'FSIC');       // long, just
    suspendedPermit('BUSINESS', '2031-02-19 01:00:00', 'FSIC');       // not yet
    suspendedPermit('BUSINESS', '2031-03-15 01:00:00', 'ZONING');
    suspendedPermit('BUSINESS', null, null);                          // no date: no age
    // Neither of these is suspended today.
    anaPermit(anaBusiness(), 'BUSINESS', ['status' => 'active']);
    suspendedPermit('BUSINESS', '2031-01-02 01:00:00', 'SANITARY', ['deleted_at' => '2031-03-01 01:00:00']);

    $after = DashboardAnalytics::build()['suspended_permits'];

    expect($after['total'])->toBe($before['total'] + 5)
        ->and($after['long'])->toBe($before['long'] + 2)
        ->and($after['undated'])->toBe($before['undated'] + 1)
        ->and($after['long_after_days'])->toBe(30);
});

it('shows one office the permits suspended for its permit, oldest first, with the office each waits on', function () {
    $office = anaOffice('TSP');

    $newest = suspendedPermit('BUSINESS', '2031-03-15 01:00:00', $office['permit_code']);
    $oldest = suspendedPermit('BUSINESS', '2031-01-10 01:00:00', $office['permit_code'], ['name' => 'Oldest Sari-sari']);
    // Its own permit, suspended with no permit named: it waits on this office too.
    $own = suspendedPermit($office['permit_code'], '2031-02-01 01:00:00', null);
    // Another office's hold, and not this office's.
    suspendedPermit('BUSINESS', '2031-01-05 01:00:00', 'SANITARY');

    $suspended = DashboardAnalytics::build(12, $office['code'])['suspended_permits'];

    expect($suspended['total'])->toBe(3)
        ->and($suspended['long'])->toBe(2)
        ->and(array_column($suspended['oldest'], 'permit_id'))->toBe([$oldest, $own, $newest])
        ->and($suspended['oldest'][0]['business'])->toBe('Oldest Sari-sari')
        ->and($suspended['oldest'][0]['suspended_on'])->toBe('2031-01-10')
        ->and($suspended['oldest'][0]['days'])->toBe(69)
        ->and($suspended['oldest'][0]['waits_on']['office'])->toBe('Test Office TSP')
        ->and($suspended['oldest'][1]['waits_on'])->toBeNull();
});

<?php

use App\Support\AnalyticsOffice;
use App\Support\DashboardAnalytics;
use App\Support\LguReports;
use Carbon\CarbonImmutable;

/*
 * Whose time a filing's time was (App\Support\FilingClock).
 *
 * Two rules, pinned on the dashboard's RA 11032 tier panel and on the
 * processing-time report, which must agree:
 *
 *  - one office is judged on its own review, not on the whole filing;
 *  - nobody is charged for the time a filing sat with its applicant
 *    (Pending Payment, Returned).
 *
 * Most cases run in March 2031, years after anything the demo seed writes,
 * so the figures hold only the rows written here. Instants are UTC; 01:00 UTC
 * is 09:00 in Manila, inside the same Manila date.
 */

function whoseTimeReport(string $key, ?string $office, string $from = '2031-03-01', string $to = '2031-03-31'): array
{
    return LguReports::build($key, CarbonImmutable::parse($from), CarbonImmutable::parse($to), AnalyticsOffice::scope($office));
}

it('judges an office on its own review, not on the filing’s whole lifetime', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));
    $office = anaOffice();
    $filing = anaFiling(anaBusiness(), [
        'status' => 'approved',
        'complexity' => 'simple',
        'submitted_at' => '2031-03-03 01:00:00', // Monday
        'decided_at' => '2031-03-24 01:00:00',   // three weeks later
    ]);
    // The office held it for one working day of those fifteen.
    anaAssignment($filing, $office['code'], [
        'assigned_at' => '2031-03-17 01:00:00',
        'completed_at' => '2031-03-18 01:00:00',
    ]);

    $simple = collect(DashboardAnalytics::build(12, $office['code'])['processing_tiers'])->firstWhere('tier', 'simple');
    $reported = whoseTimeReport('pending-processing', $office['code'])['sections'][0]['rows'][0];

    expect($simple['observations'])->toBe(1)
        ->and($simple['mean_working_days'])->toEqual(1.0)
        ->and($simple['within_statutory'])->toBe(1)
        ->and($reported['mean_days'])->toEqual(1.0)
        ->and($reported['within'])->toBe(1);
});

it('leaves the applicant’s time out of the whole register’s processing time', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));
    $filing = anaFiling(anaBusiness(), [
        'status' => 'approved',
        'complexity' => 'simple',
        'submitted_at' => '2031-03-03 01:00:00',
        'decided_at' => '2031-03-19 01:00:00', // 12 working days after submission
    ]);
    anaHistory($filing, [
        ['for_approval', '2031-03-03 01:00:00'],
        ['pending_payment', '2031-03-04 01:00:00'],        // BPLO took one day
        ['approved', '2031-03-17 01:00:00'], // the applicant took nine to pay
        ['approved', '2031-03-19 01:00:00'],               // the offices took two
    ]);

    $simple = collect(DashboardAnalytics::build()['processing_tiers'])->firstWhere('tier', 'simple');
    $reported = whoseTimeReport('pending-processing', null)['sections'][0]['rows'][0];

    expect($simple['observations'])->toBe(1)
        ->and($simple['mean_working_days'])->toEqual(3.0)
        ->and($simple['within_statutory'])->toBe(1)
        ->and($reported['decided'])->toBe(1)
        ->and($reported['mean_days'])->toEqual(3.0);
});

it('times BPLO by the days at its own desk, not by its re-stamped review', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));
    $filing = anaFiling(anaBusiness(), [
        'status' => 'approved',
        'complexity' => 'simple',
        'submitted_at' => '2031-03-03 01:00:00',
        'decided_at' => '2031-03-11 01:00:00',
    ]);
    anaHistory($filing, [
        ['for_approval', '2031-03-03 01:00:00'],
        ['pending_payment', '2031-03-05 01:00:00'],        // two days at BPLO's desk
        ['approved', '2031-03-07 01:00:00'],
        ['for_final_approval', '2031-03-10 01:00:00'],
        ['approved', '2031-03-11 01:00:00'],               // and one more
    ]);
    // Stamped at intake and stamped again at the final approval: the whole filing.
    anaAssignment($filing, 'BPLO', ['assigned_at' => '2031-03-03 01:00:00', 'completed_at' => '2031-03-11 01:00:00']);

    $reported = whoseTimeReport('pending-processing', 'BPLO')['sections'][0]['rows'][0];
    $simple = collect(DashboardAnalytics::build(12, 'BPLO')['processing_tiers'])->firstWhere('tier', 'simple');

    expect($reported['decided'])->toBe(1)
        ->and($reported['mean_days'])->toEqual(3.0)
        ->and($simple['mean_working_days'])->toEqual(3.0);
});

it('does not count an office’s review of a dead filing as pending', function () {
    $this->travelTo(CarbonImmutable::parse('2031-04-10 04:00:00', 'UTC'));
    $office = anaOffice();
    $business = anaBusiness();

    $live = anaFiling($business, ['status' => 'approved', 'submitted_at' => '2031-03-03 01:00:00']);
    $rejected = anaFiling($business, [
        'status' => 'rejected', 'submitted_at' => '2031-03-03 01:00:00', 'decided_at' => '2031-03-20 01:00:00',
    ]);
    anaHistory($rejected, [['for_approval', '2031-03-03 01:00:00'], ['rejected', '2031-03-20 01:00:00']]);
    // Both reviews left open, as the register held them before rejection closed them.
    anaAssignment($live, $office['code'], ['assigned_at' => '2031-03-10 01:00:00']);
    anaAssignment($rejected, $office['code'], ['assigned_at' => '2031-03-10 01:00:00']);

    $pending = whoseTimeReport('pending-processing', $office['code'])['sections'][1]['total'];

    expect($pending['total'])->toBe(1);
});

it('leaves a filing waiting on its applicant out of what is pending, and out of its age', function () {
    $this->travelTo(CarbonImmutable::parse('2031-04-10 04:00:00', 'UTC'));
    // The demo seed's unfinished filings are still unfinished in 2031, so this
    // measures what the rows below add.
    $before = whoseTimeReport('pending-processing', null)['sections'][1]['total'];
    $business = anaBusiness();

    $unpaid = anaFiling($business, ['status' => 'pending_payment', 'submitted_at' => '2031-03-03 01:00:00']);
    anaHistory($unpaid, [['for_approval', '2031-03-03 01:00:00'], ['pending_payment', '2031-03-04 01:00:00']]);

    // Submitted 3 March and still with the offices on 31 March: twenty working
    // days on the wall, but fifteen of them were spent returned to the applicant.
    $waited = anaFiling($business, ['status' => 'approved', 'submitted_at' => '2031-03-03 01:00:00']);
    anaHistory($waited, [
        ['for_approval', '2031-03-03 01:00:00'],
        ['returned', '2031-03-04 01:00:00'],
        ['for_approval', '2031-03-25 01:00:00'],
        ['pending_payment', '2031-03-26 01:00:00'],
        ['approved', '2031-03-26 02:00:00'],
    ]);

    $after = whoseTimeReport('pending-processing', null)['sections'][1]['total'];

    // One filing, aged 1 (3→4 March) + 4 (25→31 March) = 5 working days.
    expect($after['total'] - $before['total'])->toBe(1)
        ->and($after['within_7'] - $before['within_7'])->toBe(1);
});

it('counts a filing waiting for BPLO’s final approval as BPLO’s pending work', function () {
    $this->travelTo(CarbonImmutable::parse('2031-04-10 04:00:00', 'UTC'));
    $before = whoseTimeReport('pending-processing', 'BPLO')['sections'][1]['total'];
    $filing = anaFiling(anaBusiness(), ['status' => 'for_final_approval', 'submitted_at' => '2031-03-03 01:00:00']);
    anaHistory($filing, [
        ['for_approval', '2031-03-03 01:00:00'],
        ['pending_payment', '2031-03-04 01:00:00'],
        ['approved', '2031-03-05 01:00:00'],
        ['for_final_approval', '2031-03-27 01:00:00'],
    ]);
    // BPLO's review row was completed at intake, so an assignment-based count missed it.
    anaAssignment($filing, 'BPLO', ['assigned_at' => '2031-03-03 01:00:00', 'completed_at' => '2031-03-04 01:00:00']);

    $after = whoseTimeReport('pending-processing', 'BPLO')['sections'][1]['total'];

    // One day of intake plus two waiting for the final signature (27 → 31 March).
    expect($after['total'] - $before['total'])->toBe(1)
        ->and($after['within_3'] - $before['within_3'])->toBe(1);
});

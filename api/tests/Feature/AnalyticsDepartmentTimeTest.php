<?php

use App\Support\DashboardAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Time in Office (the dashboard's `stages` panel).
 *
 * It was "Average Processing Time by Department", timed from review
 * assignments — which close when the filing is routed, so on the live register
 * every office read 0 days and "CHO 0 days" was announced as the bottleneck.
 * The client, 18 October 2026: time from the moment the office received the
 * permit to the moment it issued or refused it, so the review AND the
 * inspection wait are both inside; name a slowest office only when it is
 * meaningfully slower (at least a day and a quarter above the average).
 *
 * Read off each permit's own status history (rows with a permit_type_id).
 * March 2031 holds nothing the demo seed writes. Timestamps are Manila clock
 * time, as the register stores them. 3 March 2031 is a Monday.
 */

/** A filing carrying one permit, with that permit's history. Returns the filing. */
function officeStint(string $permitCode, array $moves, array $filingMoves = []): int
{
    $filing = anaFiling(anaBusiness(), ['status' => 'approved', 'submitted_at' => '2031-03-03 01:00:00']);
    anaCarries($filing, $permitCode, ['status' => 'approved']);
    anaHistory($filing, $moves, $permitCode);
    if ($filingMoves !== []) {
        anaHistory($filing, $filingMoves);
    }

    return $filing;
}

/** A permit received on 3 March and decided $days working days later (Mon–Fri only). */
function stintOfDays(string $permitCode, int $days): void
{
    $decided = CarbonImmutable::parse('2031-03-03 01:00:00', 'UTC')->addWeekdays($days)->toDateTimeString();
    officeStint($permitCode, [['for_approval', '2031-03-03 01:00:00'], ['approved', $decided]]);
}

function timeInOffice(?string $office = null): array
{
    return DashboardAnalytics::build(12, $office)['stages'];
}

it('spans the office’s review and its inspection: received day 0, inspected day 3, issued day 4 is 4 working days', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    $filing = officeStint('SANITARY', [
        ['not_started', '2031-03-01 01:00:00'],     // on the filing, nobody has it yet
        ['for_approval', '2031-03-03 01:00:00'],    // Monday: City Health receives it
        ['for_inspection', '2031-03-04 01:00:00'],  // form read, visit booked
        ['approved', '2031-03-07 01:00:00'],        // Friday: issued
    ]);
    DB::table('inspections')->insert([
        'application_id' => $filing,
        'department_id' => anaDepartmentId('CHO'),
        'status' => 'completed',
        'result' => 'passed',
        'scheduled_at' => '2031-03-06 01:00:00',
        'conducted_at' => '2031-03-06 01:00:00',     // Thursday, day 3
        'created_at' => '2031-03-04 01:00:00',
        'updated_at' => '2031-03-06 01:00:00',
    ]);

    $cho = collect(timeInOffice()['rows'])->firstWhere('code', 'CHO');

    expect(timeInOffice()['unit'])->toBe('working_days')
        ->and($cho['reviews'])->toBe(1)
        ->and($cho['mean_days'])->toEqual(4.0);
});

it('leaves out the days a permit was back with its applicant', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    officeStint('FSIC', [
        ['for_approval', '2031-03-03 01:00:00'],    // Mon
        ['returned', '2031-03-04 01:00:00'],        // Tue: sent back after a day
        ['for_approval', '2031-03-11 01:00:00'],    // the next Tue: answered
        ['approved', '2031-03-13 01:00:00'],        // Thu: two more days
    ]);

    expect(collect(timeInOffice()['rows'])->firstWhere('code', 'BFP')['mean_days'])->toEqual(3.0);
});

it('times BPLO by its own desk, leaving out the applicant’s days to pay', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    // The Business Permit's row goes For Approval at submission and Approved
    // at payment. BPLO read it in two days; the applicant took a week to pay.
    officeStint('BUSINESS', [
        ['for_approval', '2031-03-03 01:00:00'],
        ['approved', '2031-03-12 01:00:00'],
    ], [
        ['for_approval', '2031-03-03 01:00:00'],
        ['pending_payment', '2031-03-05 01:00:00'],
        ['approved', '2031-03-12 01:00:00'],
    ]);

    $stages = timeInOffice('BPLO');

    expect($stages['rows'])->toHaveCount(1)
        ->and($stages['rows'][0]['code'])->toBe('BPLO')
        ->and($stages['rows'][0]['mean_days'])->toEqual(2.0);
});

it('counts a refusal as the end of the office’s time, and a re-application as a new round', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    officeStint('ZONING', [
        ['for_approval', '2031-03-03 01:00:00'],
        ['rejected', '2031-03-05 01:00:00'],        // refused after 2 days
        ['for_approval', '2031-03-17 01:00:00'],
        ['approved', '2031-03-21 01:00:00'],        // issued 4 days after the second try
    ]);

    $cpdo = collect(timeInOffice()['rows'])->firstWhere('code', 'CPDO');

    expect($cpdo['reviews'])->toBe(2)
        ->and($cpdo['mean_days'])->toEqual(3.0);
});

it('leaves out a permit with no history of its own rather than reading it as 0 days', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    // The old panel's evidence: an assignment opened and closed at once.
    $filing = anaFiling(anaBusiness(), ['status' => 'approved', 'submitted_at' => '2031-03-03 01:00:00']);
    anaAssignment($filing, 'CENRO', ['assigned_at' => '2031-03-03 01:00:00', 'completed_at' => '2031-03-03 01:00:00']);

    expect(collect(timeInOffice()['rows'])->firstWhere('code', 'CENRO'))->toBeNull();
});

it('names no slowest office when the offices are equal', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    foreach (['SANITARY', 'FSIC'] as $permit) {
        foreach ([2, 2, 2] as $days) {
            stintOfDays($permit, $days);
        }
    }

    $stages = timeInOffice();

    expect(collect($stages['rows'])->every(fn ($row) => $row['drawn']))->toBeTrue()
        ->and($stages['bottleneck'])->toBeNull();
});

it('names no slowest office when every office took 0 days', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    foreach (['SANITARY', 'FSIC', 'ZONING'] as $permit) {
        foreach ([0, 0, 0] as $days) {
            stintOfDays($permit, $days);
        }
    }

    expect(timeInOffice()['bottleneck'])->toBeNull();
});

it('names the slowest office when it is a day and a quarter above the average', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    foreach ([1, 1, 1] as $days) {
        stintOfDays('SANITARY', $days);
    }
    foreach ([4, 4, 4] as $days) {
        stintOfDays('FSIC', $days);
    }

    $stages = timeInOffice();

    // Average 2.5; BFP at 4.0 is 1.5 days and 60% above it.
    expect($stages['mean_days'])->toEqual(2.5)
        ->and($stages['bottleneck']['code'])->toBe('BFP')
        ->and($stages['bottleneck']['above_average_days'])->toEqual(1.5);
});

it('does not name a slowest office from too few permits to draw', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    stintOfDays('CEC', 9);                  // one permit: listed, not drawn
    foreach ([1, 1, 2] as $days) {
        stintOfDays('FSIC', $days);
    }
    foreach ([1, 1, 1] as $days) {
        stintOfDays('SANITARY', $days);
    }

    $stages = timeInOffice();
    $rows = collect($stages['rows'])->keyBy('code');

    expect($stages['min_reviews'])->toBe(3)
        ->and($rows['CENRO']['drawn'])->toBeFalse()
        ->and($rows['BFP']['drawn'])->toBeTrue()
        ->and($stages['bottleneck'])->toBeNull();
});

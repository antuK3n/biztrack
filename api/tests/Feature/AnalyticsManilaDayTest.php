<?php

use App\Support\AnalyticsOffice;
use App\Support\DashboardAnalytics;
use App\Support\LguReports;
use App\Support\ManilaCalendar;
use Carbon\CarbonImmutable;

/*
 * A "day", a "month" and a "working day" are Malabon's, not UTC's.
 *
 * The app stored UTC and the City lives eight hours ahead. Every figure that
 * cut time into days used the UTC date, so the nightly refresh (03:00 Manila,
 * 19:00 UTC the day before) computed yesterday, a report for September lost
 * the last eight hours of the 30th, and a filing received at 7 am on a Monday
 * was a working day older than it was. Each case below is one of those edges.
 *
 * Since 5 October 2026 the app clock is Asia/Manila (config/app.php), so a
 * STORED timestamp is a Manila wall-clock time and the fixtures below write
 * Manila times. The travelled-to instants are still given in UTC, because
 * the edge being pinned is the server clock reading 19:00 UTC.
 */

it('reads today, and where a Manila day starts and ends, on the Manila clock', function () {
    // 19:00 UTC on 30 September is 03:00 Manila on 1 October.
    $at = CarbonImmutable::parse('2026-09-30 19:00:00', 'UTC');

    expect(ManilaCalendar::today($at)->toDateString())->toBe('2026-10-01')
        ->and(ManilaCalendar::startOfDay('2026-10-01')->toDateTimeString())->toBe('2026-10-01 00:00:00')
        ->and(ManilaCalendar::startOfNextDay('2026-10-01')->toDateTimeString())->toBe('2026-10-02 00:00:00')
        ->and(ManilaCalendar::monthOf('2026-10-01 01:00:00'))->toBe('2026-10')
        ->and(ManilaCalendar::dateOf('2026-09-30 23:59:59'))->toBe('2026-09-30');
});

it('counts working days between Manila dates', function () {
    // Monday 7 September, 07:00 Manila (Sunday 6 September in UTC). Decided
    // the same Monday afternoon, it took no working days at all; the UTC
    // dates (Sunday → Monday) used to make it one.
    expect(ManilaCalendar::workingDaysBetween('2026-09-07 07:00:00', '2026-09-07 15:00:00'))->toBe(0)
        // Friday 11 September 16:00 Manila → Monday 14 September 09:00 Manila.
        ->and(ManilaCalendar::workingDaysBetween('2026-09-11 16:00:00', '2026-09-14 09:00:00'))->toBe(1)
        // Three full weeks, Monday to Monday: fifteen.
        ->and(ManilaCalendar::workingDaysBetween('2026-09-07 10:00:00', '2026-09-28 10:00:00'))->toBe(15);
});

it('puts the 3 am refresh on the Manila day, so the 1st of the month is the new month', function () {
    // 03:00 Manila on 1 March 2027 — when routes/console.php runs the refresh.
    $this->travelTo(CarbonImmutable::parse('2027-02-28 19:00:00', 'UTC'));

    $before = DashboardAnalytics::build();
    $business = anaBusiness();
    // 01:00 Manila on 1 March: filed this month.
    anaFiling($business, ['submitted_at' => '2027-03-01 01:00:00', 'created_at' => '2027-03-01 01:00:00']);
    // 23:00 Manila on 28 February: filed last month.
    anaFiling($business, ['submitted_at' => '2027-02-28 23:00:00', 'created_at' => '2027-02-28 23:00:00']);
    $after = DashboardAnalytics::build();

    expect($after['today'])->toBe('2027-03-01')
        ->and($after['month_start'])->toBe('2027-03-01')
        ->and($after['kpis']['applications_this_month'] - $before['kpis']['applications_this_month'])->toBe(1);
});

it('reads a permit that ran out yesterday in Manila as expired, not as due', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-28 19:00:00', 'UTC')); // 1 March, 03:00 Manila
    $office = anaOffice();
    $business = anaBusiness();
    anaFiling($business);
    anaPermit($business, $office['permit_code'], [
        'issued_at' => '2026-03-01 02:00:00',
        'valid_until' => '2027-02-28',
    ]);

    $rows = collect(DashboardAnalytics::build(12, $office['code'])['expiry']['rows'])->keyBy('window');

    expect($rows['expired']['total'])->toBe(1)
        ->and($rows['next_30d']['total'])->toBe(0);
});

it('bounds a report period by Manila dates and buckets it by Manila month', function () {
    $office = anaOffice();
    $business = anaBusiness();
    $permit = fn (string $issuedAt) => anaPermit($business, $office['permit_code'], [
        'application_id' => anaFiling($business, ['status' => 'approved']),
        'issued_at' => $issuedAt,
    ]);

    $permit('2027-02-01 01:00:00'); // 1 February, 01:00 Manila — February's (31 January in UTC)
    $permit('2027-03-01 01:00:00'); // 1 March, 01:00 Manila — after the period

    $report = LguReports::build(
        'permits-issued',
        CarbonImmutable::parse('2027-01-01'),
        CarbonImmutable::parse('2027-02-28'),
        AnalyticsOffice::scope($office['code']),
    );
    $byMonth = collect($report['sections'][0]['rows'])->keyBy('label');

    expect($report['sections'][0]['total']['total'])->toBe(1)
        ->and($byMonth['January 2027']['total'])->toBe(0)
        ->and($byMonth['February 2027']['total'])->toBe(1);
});

it('ages a decided filing in working days between Manila dates on the report', function () {
    $office = anaOffice();
    $business = anaBusiness();
    // Received Monday 7 September at 07:00 Manila, finished that afternoon.
    $filing = anaFiling($business, [
        'status' => 'approved',
        'complexity' => 'simple',
        'submitted_at' => '2026-09-07 07:00:00',
        'decided_at' => '2026-09-07 15:00:00',
    ]);
    anaAssignment($filing, $office['code'], [
        'assigned_at' => '2026-09-07 07:00:00',
        'completed_at' => '2026-09-07 15:00:00',
    ]);

    $report = LguReports::build(
        'pending-processing',
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-09-30'),
        AnalyticsOffice::scope($office['code']),
    );
    $simple = $report['sections'][0]['rows'][0];

    expect($simple['decided'])->toBe(1)
        ->and($simple['mean_days'])->toEqual(0.0);
});

it('defaults a report to the Manila month to date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 19:00:00', 'UTC')); // 1 October, 03:00 Manila

    $period = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/reports/permits-issued')
        ->assertOk()
        ->json('data.period');

    expect($period)->toBe(['from' => '2026-10-01', 'to' => '2026-10-01']);
});

it('marks only the running month of new and closed businesses as partial', function () {
    $rows = \App\Support\DashboardAnalytics::build(12)['business_movement']['rows'];

    expect(collect($rows)->where('partial', true)->count())->toBe(1)
        ->and(end($rows)['partial'])->toBeTrue()
        ->and(collect($rows)->slice(0, -1)->every(fn ($r) => $r['partial'] === false))->toBeTrue();
});

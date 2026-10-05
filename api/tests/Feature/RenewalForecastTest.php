<?php

use App\Enums\PermitStatus;
use App\Support\DashboardAnalytics;
use App\Support\RenewalForecast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The renewal estimate on the Analytics dashboard (RenewalForecast).
 *
 * Two halves, tested apart. The reading of the register — what counts as a
 * term, a renewal, on time or late — is checked against permit histories built
 * one row at a time, where the right answer is known by construction. The
 * arithmetic is checked on facts handed straight to compute(), where the
 * pattern in the history is put there on purpose and the estimate must find it.
 */

function forecastBusiness(string $name, ?int $barangayId = null): int
{
    $id = (int) DB::table('businesses')->insertGetId([
        'name' => $name,
        'owner_user_id' => DB::table('users')->value('id'),
        'status' => 'active',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    if ($barangayId !== null) {
        DB::table('business_addresses')->insert([
            'business_id' => $id,
            'line1' => '1 Forecast Street',
            'barangay_id' => $barangayId,
            'address_type' => 'business_location',
        ]);
    }

    return $id;
}

/** A Business Permit recorded from paper: no filing, so it was filed the day it was issued. */
function forecastPermit(int $businessId, string $issued, string $until, string $status = 'expired'): int
{
    static $n = 0;
    $n++;

    return (int) DB::table('permits')->insertGetId([
        'permit_number' => 'FC-'.$businessId.'-'.$n,
        'application_id' => null,
        'business_id' => $businessId,
        'permit_type_id' => DB::table('permit_types')->where('code', 'BUSINESS')->value('id'),
        'status' => $status,
        'valid_from' => $issued,
        'valid_until' => $until,
        'issued_at' => $issued.' 09:00:00',
    ]);
}

/** The history rows dataset() wrote for one business, oldest term first. */
function forecastHistoryOf(callable $build): array
{
    $before = count(RenewalForecast::dataset(CarbonImmutable::parse('2026-10-05'))['history']);
    $build();

    return array_slice(RenewalForecast::dataset(CarbonImmutable::parse('2026-10-05'))['history'], $before);
}

/** `$n` copies of one history row. */
function forecastRows(int $n, int $years, int $lastLate, string $outcome): array
{
    return array_fill(0, $n, ['years' => $years, 'last_late' => $lastLate, 'outcome' => $outcome]);
}

function forecastFacts(array $history, array $due): array
{
    return [
        'expires_on' => '2026-12-31',
        'on_time_until' => '2027-01-20',
        'horizon_days' => RenewalForecast::HORIZON_DAYS,
        'history' => $history,
        'due' => $due,
    ];
}

/** A history with every outcome well represented, and a clear pattern in it. */
function forecastPatternedHistory(): array
{
    return [
        // Established businesses that renewed on time last year: mostly on time again.
        ...forecastRows(60, 3, 0, 'on_time'), ...forecastRows(10, 3, 0, 'late'), ...forecastRows(5, 3, 0, 'not_renewed'),
        // Late last year: mostly late again.
        ...forecastRows(10, 3, 1, 'on_time'), ...forecastRows(40, 3, 1, 'late'), ...forecastRows(10, 3, 1, 'not_renewed'),
        // New businesses: many never come back.
        ...forecastRows(20, 0, 0, 'on_time'), ...forecastRows(10, 0, 0, 'late'), ...forecastRows(30, 0, 0, 'not_renewed'),
    ];
}

it('reads a renewal filed by 20 January as on time, after it as late, and none within the horizon as not renewed', function () {
    $rows = forecastHistoryOf(function () {
        $onTime = forecastBusiness('Filed on the fifteenth');
        forecastPermit($onTime, '2024-03-01', '2024-12-31');
        forecastPermit($onTime, '2025-01-15', '2025-12-31');

        $late = forecastBusiness('Filed on the twenty-first');
        forecastPermit($late, '2024-03-01', '2024-12-31');
        forecastPermit($late, '2025-01-21', '2025-12-31');

        $gone = forecastBusiness('Never came back');
        forecastPermit($gone, '2024-03-01', '2024-12-31');
    });

    // Each business's 2025 term is history too: 270 days have passed since
    // 31 December 2025 and none of them renewed it.
    expect(array_column($rows, 'outcome'))->toBe(['on_time', 'not_renewed', 'late', 'not_renewed', 'not_renewed']);
});

it('counts a renewal arriving after the horizon as no renewal, and leaves a term out until the horizon has passed', function () {
    $rows = forecastHistoryOf(function () {
        // 2023-12-31 + 270 days is 27 September 2024; this came back in October.
        $slow = forecastBusiness('Ten months late');
        forecastPermit($slow, '2023-03-01', '2023-12-31');
        forecastPermit($slow, '2024-10-15', '2024-12-31');
    });

    // The 2023 term is not renewed within the horizon; the 2024 term was not
    // renewed either. The 2025 season is not involved: neither term is current.
    expect(array_column($rows, 'outcome'))->toBe(['not_renewed', 'not_renewed']);

    $recent = forecastHistoryOf(function () {
        // Expired 31 January 2026: 270 days have not passed by 5 October 2026.
        $open = forecastBusiness('Not settled yet');
        forecastPermit($open, '2025-02-01', '2026-01-31');
    });

    expect($recent)->toBe([]);
});

it('does not read an amendment inside the same term as a renewal, nor learn from a revoked permit', function () {
    $rows = forecastHistoryOf(function () {
        $amended = forecastBusiness('Amended in June');
        forecastPermit($amended, '2024-03-01', '2024-12-31', PermitStatus::Superseded->value);
        forecastPermit($amended, '2024-06-10', '2024-12-31');
        forecastPermit($amended, '2025-01-10', '2025-12-31');

        $revoked = forecastBusiness('Taken away');
        forecastPermit($revoked, '2024-03-01', '2024-12-31', PermitStatus::Revoked->value);
    });

    // One 2024 term for the amended business, renewed on 10 January, then
    // its 2025 term, never renewed; nothing at all from the revoked one.
    expect($rows)->toBe([
        ['years' => 0, 'last_late' => 0, 'outcome' => 'on_time'],
        ['years' => 1, 'last_late' => 0, 'outcome' => 'not_renewed'],
    ]);
});

it('knows a business by its years with a permit and whether its last renewal was late', function () {
    $barangay = (int) DB::table('barangays')->value('id');
    $business = forecastBusiness('Late last January', $barangay);
    forecastPermit($business, '2024-02-01', '2024-12-31');
    forecastPermit($business, '2025-02-14', '2025-12-31');
    forecastPermit($business, '2026-01-12', '2026-12-31', PermitStatus::Active->value);

    $removed = forecastBusiness('Closed since');
    forecastPermit($removed, '2026-03-01', '2026-12-31', PermitStatus::Active->value);
    DB::table('businesses')->where('id', $removed)->update(['deleted_at' => '2026-06-01 00:00:00']);

    $due = RenewalForecast::dataset(CarbonImmutable::parse('2026-10-05'))['due'];
    $name = DB::table('barangays')->where('id', $barangay)->value('name');

    // Two earlier terms; the renewal into 2026 was on time, the one into 2025
    // late — the LAST one is what counts. The closed business is not forecast.
    expect($due)->toContain(['years' => 2, 'last_late' => 0, 'barangay' => $name]);
    expect(count($due))->toBe(DB::table('permits')
        ->join('businesses', 'businesses.id', '=', 'permits.business_id')
        ->whereNull('businesses.deleted_at')
        ->where('permits.valid_until', 'like', '2026-12-31%')
        ->whereIn('permits.status', ['active', 'suspended'])
        ->where('permits.permit_type_id', DB::table('permit_types')->where('code', 'BUSINESS')->value('id'))
        ->count());
});

it('estimates nothing when any outcome has too little history to learn from', function () {
    $history = [
        ...forecastRows(60, 2, 0, 'on_time'),
        ...forecastRows(40, 2, 1, 'late'),
        ...forecastRows(RenewalForecast::MIN_OUTCOMES - 1, 0, 0, 'not_renewed'),
    ];

    $out = RenewalForecast::compute(forecastFacts($history, [['years' => 1, 'last_late' => 0, 'barangay' => 'Acacia']]));

    expect($out['unavailable'])->toBe('thin_history')
        ->and($out['expected'])->toBeNull()
        ->and($out['shares'])->toBeNull()
        ->and($out['barangays'])->toBe([])
        ->and($out['permits'])->toBe(1);
});

it('says there is nothing to estimate when no Business Permit expires this 31 December', function () {
    $out = RenewalForecast::compute(forecastFacts(forecastPatternedHistory(), []));

    expect($out['unavailable'])->toBe('no_permits')
        ->and($out['expected'])->toBeNull();
});

it('adds each permit\'s three chances up to whole counts that total the permits due', function () {
    $due = [
        ...array_fill(0, 7, ['years' => 3, 'last_late' => 0, 'barangay' => 'Acacia']),
        ...array_fill(0, 5, ['years' => 3, 'last_late' => 1, 'barangay' => 'Tinajeros']),
        ...array_fill(0, 9, ['years' => 0, 'last_late' => 0, 'barangay' => 'Tinajeros']),
        ['years' => 0, 'last_late' => 0, 'barangay' => null],
    ];

    $out = RenewalForecast::compute(forecastFacts(forecastPatternedHistory(), $due));

    expect($out['unavailable'])->toBeNull()
        ->and($out['permits'])->toBe(22)
        ->and(array_sum($out['expected']))->toBe(22)
        ->and(array_sum($out['shares']))->toEqualWithDelta(100.0, 0.2);
});

it('finds the pattern the history holds: late last time means late again, new means likelier gone', function () {
    $estimate = fn (array $row): array => RenewalForecast::compute(forecastFacts(
        forecastPatternedHistory(),
        array_fill(0, 100, $row + ['barangay' => 'Acacia']),
    ))['expected'];

    $punctual = $estimate(['years' => 3, 'last_late' => 0]);
    $lateLastTime = $estimate(['years' => 3, 'last_late' => 1]);
    $new = $estimate(['years' => 0, 'last_late' => 0]);

    expect($lateLastTime['late'])->toBeGreaterThan($punctual['late'])
        ->and($punctual['on_time'])->toBeGreaterThan($lateLastTime['on_time'])
        ->and($new['not_renewed'])->toBeGreaterThan($punctual['not_renewed']);
});

it('ranks barangays by expected non-renewals, naming how many permits each has due', function () {
    $due = [
        ...array_fill(0, 4, ['years' => 3, 'last_late' => 0, 'barangay' => 'Acacia']),
        ...array_fill(0, 4, ['years' => 0, 'last_late' => 0, 'barangay' => 'Tinajeros']),
        ...array_fill(0, 2, ['years' => 0, 'last_late' => 0, 'barangay' => 'Muzon']),
    ];

    $out = RenewalForecast::compute(forecastFacts(forecastPatternedHistory(), $due));

    expect(array_column($out['barangays'], 'barangay'))->toBe(['Tinajeros', 'Muzon', 'Acacia'])
        ->and(array_column($out['barangays'], 'permits'))->toBe([4, 2, 4]);
});

it('leaves a fact that never varies out of the fit instead of failing on it', function () {
    // Nobody in this history was ever late before: last_late is all zeros.
    $history = [
        ...forecastRows(40, 3, 0, 'on_time'), ...forecastRows(25, 3, 0, 'late'),
        ...forecastRows(15, 0, 0, 'not_renewed'), ...forecastRows(10, 3, 0, 'not_renewed'),
        ...forecastRows(20, 0, 0, 'on_time'), ...forecastRows(20, 1, 0, 'late'),
    ];

    $out = RenewalForecast::compute(forecastFacts($history, [['years' => 1, 'last_late' => 0, 'barangay' => null]]));

    expect($out['unavailable'])->toBeNull()
        ->and(array_sum($out['expected']))->toBe(1);
});

it('is on the dashboard for BPLO and every office, and not for an office that does not issue the Business Permit', function () {
    expect(DashboardAnalytics::build(12)['renewal_forecast'])->toBeArray()
        ->and(DashboardAnalytics::build(12, 'BPLO')['renewal_forecast'])->toBeArray()
        ->and(DashboardAnalytics::build(12, 'CHO')['renewal_forecast'])->toBeNull();
});

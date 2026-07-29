<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\Department;
use App\Models\User;
use App\Support\Spc;
use Carbon\CarbonImmutable;

/*
 * Feature 7 (Permit Processing Time Monitoring) and Business Growth Analysis,
 * now served by the site instead of the standalone r/ project.
 *
 * The point of these tests is that the endpoints compute from the register and
 * that the statistics survive the trip through the database and the JSON
 * envelope — the maths itself is pinned against qcc in tests/Unit/SpcTest.php.
 */

/**
 * Give one department a run of weekly turnarounds, three completions a week.
 *
 * @param  list<float>  $weeklyMeans  One mean turnaround, in days, per week.
 */
function seedWeeklyTurnaround(string $departmentCode, array $weeklyMeans, ?CarbonImmutable $firstWeek = null): void
{
    $department = Department::where('code', $departmentCode)->firstOrFail();
    $application = Application::firstOrFail();
    $firstWeek ??= CarbonImmutable::now()
        ->startOfWeek(CarbonImmutable::MONDAY)
        ->subWeeks(count($weeklyMeans));

    foreach ($weeklyMeans as $weekIndex => $mean) {
        // Tuesday noon, so the bucket the completion falls into is unambiguous.
        $completedAt = $firstWeek->addWeeks($weekIndex)->addDay()->setTime(12, 0);
        for ($i = 0; $i < Spc::MIN_COMPLETIONS_PER_WEEK; $i++) {
            ApplicationAssignment::create([
                'application_id' => $application->id,
                'department_id' => $department->id,
                'status' => 'completed',
                'assigned_at' => $completedAt->subSeconds((int) round($mean * 86400)),
                'completed_at' => $completedAt,
            ]);
        }
    }
}

/* ── access ───────────────────────────────────────────────────────────── */

it('serves the processing time monitor to the super admin only', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time')
        ->assertOk();

    // An office reviewer holds application.view_all but not analytics.view;
    // letting them read this would summarise filings never routed to them.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time')
        ->assertForbidden();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time')
        ->assertForbidden();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time')
        ->assertForbidden();
});

it('refuses both analytics feeds to a caller with no session', function () {
    // No authAs() anywhere in this test: Sanctum::actingAs would outlive it.
    test()->getJson('/api/v1/analytics/processing-time')->assertUnauthorized();
    test()->getJson('/api/v1/analytics/business-growth')->assertUnauthorized();
    test()->get('/api/v1/analytics/processing-time/report')->assertUnauthorized();
    test()->get('/api/v1/analytics/business-growth/report')->assertUnauthorized();
});

it('serves business growth analysis to the super admin only', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertForbidden();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertForbidden();
});

it('refuses both report downloads to anyone without analytics.view', function () {
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->get('/api/v1/analytics/processing-time/report')
        ->assertForbidden();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->get('/api/v1/analytics/business-growth/report')
        ->assertForbidden();
});

/* ── Feature 7: the injected slowdown must be caught ──────────────────── */

it('flags an injected slowdown and leaves the steady office in control', function () {
    // Start from a clean review history so the seeded demo assignments do not
    // land in the same weekly buckets as the series under test.
    ApplicationAssignment::query()->delete();

    // CHO gets the slowdown generate.R injects: a calm baseline, then a run of
    // small increases. BPLO stays calm. Same series as the qcc-pinned unit test.
    seedWeeklyTurnaround('CHO', array_merge(
        array_merge(...array_fill(0, 12, [2.0, 3.0])),
        [3.5, 4.0, 4.5, 5.0, 5.5, 6.0],
    ));
    seedWeeklyTurnaround('BPLO', array_merge(...array_fill(0, 15, [2.0, 3.0])));

    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time?weeks=52')
        ->assertOk()
        ->json('data');

    $departments = collect($body['departments'])->keyBy('code');

    $cho = $departments['CHO'];
    expect($cho['points'])->toHaveCount(30);
    // Centre and limits are fitted on the calm first 24 weeks (qcc-verified).
    expect($cho['center'])->toBe(2.5);
    expect($cho['ucl'])->toBe(5.16);
    expect($cho['calibration_weeks'])->toBe(24);

    // The last four weeks are out of control; the first two of them by drift
    // alone, before any single week breaches the control limit.
    $rules = array_column($cho['points'], 'rule_hit');
    expect(array_slice($rules, 26))->toBe([
        'ewma_drift', 'ewma_drift', 'beyond_limits+ewma_drift', 'beyond_limits+ewma_drift',
    ]);
    expect($cho['flagged'])->toHaveCount(4);
    expect($cho['status'])->toBe('outside');
    expect($cho['trend']['direction'])->toBe('rising');

    // Flagged weeks carry a signed deviation for the small table on the screen.
    expect($cho['flagged'][3]['deviation_days'])->toBe(3.5);

    $bplo = $departments['BPLO'];
    expect($bplo['status'])->toBe('inside');
    expect($bplo['flagged'])->toBe([]);
    expect($bplo['trend']['direction'])->toBe('steady');
});

it('drops weeks that did not reach three completed reviews and says why', function () {
    ApplicationAssignment::query()->delete();

    $department = Department::where('code', 'BFP')->firstOrFail();
    $application = Application::firstOrFail();
    $completedAt = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY)->subWeek()->addDay();

    // Two completions in the week — one short of the minimum.
    foreach ([1, 2] as $days) {
        ApplicationAssignment::create([
            'application_id' => $application->id,
            'department_id' => $department->id,
            'status' => 'completed',
            'assigned_at' => $completedAt->subDays($days),
            'completed_at' => $completedAt,
        ]);
    }

    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time')
        ->assertOk()
        ->json('data');

    expect(collect($body['departments'])->pluck('code'))->not->toContain('BFP');
    $thin = collect($body['thin'])->keyBy('code');
    expect($thin)->toHaveKey('BFP');
    expect($thin['BFP']['completed_reviews'])->toBe(2);
    expect($body['min_completions_per_week'])->toBe(3);
});

it('ignores completions outside the requested window', function () {
    seedWeeklyTurnaround(
        'CENRO',
        [2.0, 3.0, 2.0, 3.0],
        CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(60),
    );

    $inWindow = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time?weeks=8')
        ->assertOk()
        ->json('data');
    expect(collect($inWindow['departments'])->pluck('code'))->not->toContain('CENRO');

    $wide = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time?weeks=104')
        ->assertOk()
        ->json('data');
    expect(collect($wide['departments'])->pluck('code'))->toContain('CENRO');
});

/* ── Business growth ──────────────────────────────────────────────────── */

it('counts business lifecycle status from permits and soft deletes', function () {
    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk()
        ->json('data');

    $summary = collect($body['status_summary'])->keyBy('status');
    expect($summary->keys()->sort()->values()->all())
        ->toBe(['active', 'closed', 'expired', 'inactive']);

    $total = Business::withTrashed()->count();
    expect($summary->sum('count'))->toBe($total);
    expect(round($summary->sum('share')))->toBe(100.0);

    // The seeded demo register issues at least one permit that is valid today.
    expect($summary['active']['count'])->toBeGreaterThan(0);
});

it('counts a closure in the period it happened in', function () {
    $before = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')->json('data.closures');

    Business::firstOrFail()->delete();

    $after = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')->json('data');

    expect($after['closures'])->toBe($before + 1);
    $currentMonth = collect($after['closure_trend'])->firstWhere('month', CarbonImmutable::now()->format('Y-m'));
    expect($currentMonth['closures'])->toBeGreaterThan(0);
});

it('ranks barangays by the change in new registrations', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $longos = Barangay::where('name', 'Longos')->firstOrFail();

    foreach (range(1, 3) as $i) {
        $business = Business::create([
            'owner_user_id' => $owner->id,
            'name' => "Growth Test Store {$i}",
            'registration_type' => 'DTI',
        ]);
        BusinessAddress::create([
            'business_id' => $business->id,
            'line1' => "{$i} Test Street",
            'barangay_id' => $longos->id,
        ]);
    }

    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk()
        ->json('data');

    $longosRow = collect($body['top_barangays'])->firstWhere('barangay', 'Longos');
    expect($longosRow)->not->toBeNull();
    expect($longosRow['registrations'])->toBeGreaterThanOrEqual(3);
    expect($longosRow['delta'])->toBe($longosRow['registrations'] - $longosRow['prior']);
});

it('leaves the growth rate null when there is no prior period to compare against', function () {
    // Everything the seeder creates is registered "now", so a one-month window
    // has an empty prior month. A percentage change from zero is not a number.
    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth?months=1')
        ->assertOk()
        ->json('data');

    expect($body['registrations_prior'])->toBe(0);
    expect($body['growth_rate'])->toBeNull();
});

it('reports renewal performance with its own numerator and denominator', function () {
    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk()
        ->json('data.renewal_performance');

    expect($body)->toHaveKeys(['rate', 'approved', 'decided']);
    if ($body['decided'] === 0) {
        expect($body['rate'])->toBeNull();
    } else {
        expect((float) $body['rate'])->toBe(round(($body['approved'] / $body['decided']) * 100, 1));
        expect($body['approved'])->toBeLessThanOrEqual($body['decided']);
    }
});

it('groups industry growth by PSIC line of business', function () {
    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk()
        ->json('data.industry_growth');

    expect($body)->not->toBeEmpty();
    foreach ($body as $row) {
        expect($row)->toHaveKeys(['industry', 'psic_code', 'count', 'registrations', 'prior', 'delta', 'direction']);
        expect($row['delta'])->toBe($row['registrations'] - $row['prior']);
        expect($row['direction'])->toBeIn(['growing', 'declining', 'steady']);
    }
});

/* ── Generate Report ──────────────────────────────────────────────────── */

it('generates a processing time PDF that carries the flagged weeks', function () {
    seedWeeklyTurnaround('CHO', array_merge(
        array_merge(...array_fill(0, 12, [2.0, 3.0])),
        [3.5, 4.0, 4.5, 5.0, 5.5, 6.0],
    ));

    $response = test()->withHeaders(authAs('admin@biztrack.local'))
        ->get('/api/v1/analytics/processing-time/report?weeks=52')
        ->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    expect(strlen($response->getContent()))->toBeGreaterThan(2000);
});

it('generates a business growth PDF', function () {
    $response = test()->withHeaders(authAs('admin@biztrack.local'))
        ->get('/api/v1/analytics/business-growth/report')
        ->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    expect(strlen($response->getContent()))->toBeGreaterThan(2000);
});

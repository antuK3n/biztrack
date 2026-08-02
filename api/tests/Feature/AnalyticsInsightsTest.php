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

it('serves the processing time monitor to the super admin and to BPLO', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time')
        ->assertOk();

    /*
     * Checklist item 78 — "the dashboard should be transferred to BPLO admin,
     * not super admin". BPLO is the issuing office that coordinates every other
     * office's clearance and is the one office role already holding
     * application.view_any_office, so the aggregate summarises nothing it could
     * not already open one filing at a time.
     */
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/processing-time')
        ->assertOk();

    // An ordinary office reviewer holds application.view_all but not
    // analytics.view; letting them read this would summarise filings never
    // routed to them.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
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

it('serves business growth analysis to the super admin and to BPLO', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk();

    test()->withHeaders(authAs('bplo@biztrack.local'))
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
    test()->withHeaders(authAs('sanitary@biztrack.local'))
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

it('reports renewal performance as a cohort survival curve, not a single ratio', function () {
    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk()
        ->json('data.cohort_survival');

    expect($body)->toHaveKeys([
        'methodology', 'grace_days', 'businesses', 'renewals_observed',
        'lapses', 'max_cycle', 'survival', 'points', 'cohorts',
    ]);

    // The measure must never be sold as a forecast: it describes a cohort that
    // has already been observed.
    expect($body['methodology'])->toContain('not a forecast');

    if ($body['points'] === []) {
        // Nothing has reached a first renewal, so there is no rate — and null is
        // the only honest answer. A 0% here would read as total failure to renew.
        expect($body['survival'])->toBeNull();
        expect($body['max_cycle'])->toBe(0);

        return;
    }

    // A survival curve is monotonically non-increasing by construction: it is a
    // running product of terms that are each at most 1. A rise would mean
    // businesses came back from a lapse, which the estimator cannot express.
    $previous = 100.0;
    foreach ($body['points'] as $point) {
        expect($point['survival'])->toBeLessThanOrEqual($previous);
        expect($point['at_risk'])->toBeGreaterThan(0);
        expect($point['lapses'])->toBeLessThanOrEqual($point['at_risk']);
        $previous = (float) $point['survival'];
    }

    // The headline is the last point, so the card and the curve cannot disagree.
    expect((float) $body['survival'])->toBe((float) end($body['points'])['survival']);
});

it('leaves a cohort that has not reached a renewal without a survival rate', function () {
    // The divide-by-zero guard the spec asks for, at cohort level: a business
    // registered this year has had no renewal to miss, so its cohort has no rate
    // rather than a fabricated 0% or a flattering 100%.
    $cohorts = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/business-growth')
        ->assertOk()
        ->json('data.cohort_survival.cohorts');

    foreach ($cohorts as $cohort) {
        if ($cohort['max_cycle'] === 0) {
            expect($cohort['survival'])->toBeNull(
                "Cohort {$cohort['cohort']} reached no renewal cycle but still reported a rate.",
            );
        } else {
            expect($cohort['survival'])->not->toBeNull();
        }
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

/* ── Analytics Dashboard (spec §1) ─────────────────────────────────────── */

it('serves the analytics dashboard to the super admin and to BPLO', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk();

    /*
     * Checklist item 78 asked for exactly this screen. BPLO gets the panels and
     * the PDF; the boundary below is what makes "transferred to BPLO" different
     * from "opened to every office".
     */
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->get('/api/v1/analytics/dashboard/report')
        ->assertOk();

    // These panels count every office's filings, decisions, inspections and
    // permits, and the barangay ranking is a register-wide summary. An ordinary
    // office reviewer holds application.view_all but not analytics.view; letting
    // them read this would hand them an aggregate of filings
    // ApplicationVisibility deliberately keeps out of their queue.
    foreach (['sanitary@biztrack.local', 'owner@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))
            ->getJson('/api/v1/analytics/dashboard')
            ->assertForbidden();

        test()->withHeaders(authAs($email))
            ->get('/api/v1/analytics/dashboard/report')
            ->assertForbidden();
    }
});

it('refuses the dashboard and its report to a caller with no session', function () {
    // No authAs() anywhere in this test: Sanctum::actingAs would outlive it.
    test()->getJson('/api/v1/analytics/dashboard')->assertUnauthorized();
    test()->get('/api/v1/analytics/dashboard/report')->assertUnauthorized();
});

it('returns every panel the dashboard spec asks for', function () {
    $body = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json();

    expect($body['data'])->toHaveKeys([
        'kpis', 'volume', 'decisions', 'processing_tiers', 'stages', 'compliance',
        'expiry', 'top_barangays', 'top_lines_of_business', 'organization_forms',
        'inspections', 'officer_activity', 'map',
    ]);

    // Provenance is mandatory on every analytics response: these are batch figures
    // and the screen has to be able to say when and by what they were computed.
    expect($body['meta'])->toHaveKeys(['source', 'engine', 'computed_at', 'fallback_reason']);

    // The three compliance indicators the spec names, no more and no fewer.
    expect(array_column($body['data']['compliance'], 'indicator'))
        ->toBe(['ra11032_processing', 'permit_validity', 'renewal']);
});

it('excludes pending filings from the approval rate denominator', function () {
    $decisions = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json('data.decisions');

    $byOutcome = array_column($decisions['rows'], 'count', 'outcome');

    // "Decisioned" is approved + returned + rejected. Pending and cancelled are
    // in the table but must not reach the denominator — dividing by the grand
    // total is the specific mistake the spec warns about.
    expect($decisions['decisioned'])
        ->toBe($byOutcome['approved'] + $byOutcome['returned'] + $byOutcome['rejected']);
    expect($decisions['total'])->toBe(array_sum($byOutcome));

    if ($decisions['decisioned'] === 0) {
        expect($decisions['approval_rate'])->toBeNull();
    } else {
        expect((float) $decisions['approval_rate'])
            ->toBe(round(($decisions['approved'] / $decisions['decisioned']) * 100, 1));
    }
});

it('measures RA 11032 tiers against the statute and not against the recorded deadline', function () {
    $tiers = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json('data.processing_tiers');

    expect(array_column($tiers, 'tier'))->toBe(['simple', 'complex', 'highly_technical']);

    // The statutory limits are law (RA 11032, the Ease of Doing Business Act), not
    // configurable service targets.
    expect(array_column($tiers, 'statutory_working_days'))->toBe([3, 7, 20]);

    foreach ($tiers as $tier) {
        if ($tier['observations'] === 0) {
            // A tier with no decided filing has no mean, and MUST NOT report as
            // compliant: a bar drawn at zero days against a 20-day limit would
            // read as excellent performance rather than as absent data.
            expect($tier['mean_working_days'])->toBeNull();
            expect($tier['breaching'])->toBeFalse();
            expect($tier['within_statutory_rate'])->toBeNull();

            continue;
        }

        // `breaching` and `within_statutory` must be on the same yardstick, or the
        // panel contradicts itself: a high pass rate beside a breach flag reads as
        // a bug even when both numbers are individually right.
        expect($tier['breaching'])->toBe($tier['mean_working_days'] > $tier['statutory_working_days']);
        expect($tier['within_statutory'])->toBeLessThanOrEqual($tier['observations']);

        // No tolerance band on a legal threshold.
        expect($tier['overage_days'])
            ->toBe(round($tier['mean_working_days'] - $tier['statutory_working_days'], 1));
    }
});

it('derives the bottleneck from the computed means rather than naming an office', function () {
    $stages = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json('data.stages');

    if ($stages['rows'] === []) {
        expect($stages['bottleneck'])->toBeNull();

        return;
    }

    // Slowest first, so the bottleneck is the head of the table and cannot be a
    // separately-decided answer that disagrees with the rows above it.
    $means = array_column($stages['rows'], 'mean_days');
    expect($means)->toBe(array_reverse(collect($means)->sort()->values()->all()));
    expect($stages['bottleneck']['code'])->toBe($stages['rows'][0]['code']);
    expect($stages['bottleneck']['mean_days'])->toBe($stages['rows'][0]['mean_days']);
});

it('nests the permit expiry windows cumulatively', function () {
    $expiry = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json('data.expiry');

    $rows = array_column($expiry['rows'], null, 'window');
    expect($rows)->toHaveKeys(['next_30d', 'next_60d', 'next_90d', 'expired']);

    // 30d ⊂ 60d ⊂ 90d, as the mockup's own figures do.
    expect($rows['next_30d']['total'])->toBeLessThanOrEqual($rows['next_60d']['total']);
    expect($rows['next_60d']['total'])->toBeLessThanOrEqual($rows['next_90d']['total']);

    // Each row's per-type counts have to add up to its own total, or a column is
    // being dropped from the table.
    foreach ($expiry['rows'] as $row) {
        expect(array_sum($row['counts']))->toBe($row['total']);
    }
});

it('divides the inspection pass rate by completed inspections, not scheduled', function () {
    $inspections = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json('data.inspections');

    foreach ([...$inspections['rows'], $inspections['combined']] as $row) {
        expect($row['passed'] + $row['failed'] + $row['conditional'])
            ->toBeLessThanOrEqual($row['completed']);

        if ($row['completed'] === 0) {
            // Nothing completed means no rate. Reporting 0% would say every
            // inspection failed, when none has happened.
            expect($row['pass_rate'])->toBeNull();
        } else {
            expect((float) $row['pass_rate'])->toBe(round(($row['passed'] / $row['completed']) * 100, 1));
        }
    }
});

it('reports a compliance indicator it cannot compute as null with a reason', function () {
    $compliance = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json('data.compliance');

    foreach ($compliance as $indicator) {
        if ($indicator['rate'] === null) {
            // Either there was nothing to divide, or the numerator is unknowable
            // and a reason says so. What must never happen is a null rate with an
            // empty denominator AND no explanation, because the screen then has
            // nothing honest to print.
            expect($indicator['denominator'] === 0 || $indicator['unavailable_reason'] !== null)
                ->toBeTrue("Indicator {$indicator['indicator']} has no rate and no reason.");

            continue;
        }

        expect($indicator['denominator'])->toBeGreaterThan(0);
        expect((float) $indicator['rate'])
            ->toBe(round(($indicator['numerator'] / $indicator['denominator']) * 100, 1));
    }
});

it('plots businesses from their own coordinates rather than reporting none', function () {
    $map = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json('data.map');

    // The seeder writes latitude and longitude on every business-location
    // address, so this screen has a real point layer. It rendered an empty map for
    // a while because it read coordinates off the inspections feed, where they are
    // null on every row — an empty state that was honest about what it had been
    // handed and wrong about the register.
    expect($map['mapped'])->toBeGreaterThan(0);
    expect($map['plotted'])->toBeGreaterThan(0);
    expect($map['plotted'])->toBeLessThanOrEqual($map['mapped']);

    foreach ($map['points'] as $point) {
        expect($point['latitude'])->toBeGreaterThan(14.0)->toBeLessThan(15.0);
        expect($point['longitude'])->toBeGreaterThan(120.0)->toBeLessThan(121.5);
        expect($point['permit_state'])->toBeIn(['active', 'lapsed']);
    }
});

it('says a form of organization is unrecorded rather than reporting four zeros', function () {
    $forms = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk()
        ->json('data.organization_forms');

    expect(array_column($forms['rows'], 'form'))
        ->toBe(['sole_proprietorship', 'corporation', 'partnership', 'cooperative']);

    // The column exists and nothing populates it. The parts must still sum, so a
    // reader can see the breakdown is empty because the field is blank and not
    // because Malabon has no corporations.
    expect($forms['recorded'] + $forms['unrecorded'])->toBe($forms['total']);

    foreach ($forms['rows'] as $row) {
        if ($forms['recorded'] === 0) {
            expect($row['count'])->toBe(0);
            expect($row['share'])->toBeNull();
        }
    }
});

it('generates an analytics dashboard PDF', function () {
    $response = test()->withHeaders(authAs('admin@biztrack.local'))
        ->get('/api/v1/analytics/dashboard/report')
        ->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    expect(strlen($response->getContent()))->toBeGreaterThan(2000);
});

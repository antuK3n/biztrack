<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsOffice;
use App\Support\DashboardAnalytics;
use Illuminate\Support\Facades\Hash;

/*
 * One analytics dashboard for every office, scoped on the SERVER (checklist
 * "Manage Approved Permits – Ken", item 1).
 *
 * Each office admin opens it on their own office. BPLO and the super admin may
 * switch office or view all. The rule that matters is the negative one: an
 * office account must not be able to read another office's figures by editing
 * the query string. That is asserted on the JSON endpoint and on the PDF, the
 * two doors the dashboard has.
 *
 * Accounts are the demo seed's: sanitary@ is CHO, fire@ is BFP, bplo@ is BPLO,
 * admin@ has no department.
 */

/** @return array<string, mixed> the whole response body */
function dashboardAs(string $email, string $query = ''): array
{
    return test()->withHeaders(authAs($email))
        ->getJson('/api/v1/analytics/dashboard'.$query)
        ->assertOk()
        ->json();
}

/* ── office accounts are held to their own office ─────────────────────── */

it('opens an office admin on their own office, with no office menu', function () {
    $body = dashboardAs('sanitary@biztrack.local');

    expect($body['scope']['office'])->toBe('CHO')
        ->and($body['scope']['can_switch'])->toBeFalse()
        ->and($body['scope']['offices'])->toBe([]);
});

it('answers every office admin with their own office', function () {
    $expected = [
        'sanitary@biztrack.local' => 'CHO',
        'fire@biztrack.local' => 'BFP',
        'obo@biztrack.local' => 'OBO',
        'cenro@biztrack.local' => 'CENRO',
        'zoning@biztrack.local' => 'CPDO',
    ];

    foreach ($expected as $email => $office) {
        expect(dashboardAs($email)['scope']['office'])->toBe($office, "{$email} was not scoped to {$office}.");
    }
});

it('refuses an office admin who asks for another office by query string', function () {
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard?office=BFP')
        ->assertForbidden();

    // Case and spacing do not open a side door either.
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard?office=%20bfp%20')
        ->assertForbidden();
});

it('refuses an office admin who asks for every office', function () {
    test()->withHeaders(authAs('fire@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard?office=all')
        ->assertForbidden();
});

it('lets an office admin name their own office', function () {
    expect(dashboardAs('sanitary@biztrack.local', '?office=CHO')['scope']['office'])->toBe('CHO');
});

it('holds the PDF report to the same boundary', function () {
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->get('/api/v1/analytics/dashboard/report?office=BFP')
        ->assertForbidden();

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->get('/api/v1/analytics/dashboard/report?office=all')
        ->assertForbidden();

    $response = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->get('/api/v1/analytics/dashboard/report')
        ->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('analytics-dashboard-cho.pdf');
});

it('serves an office admin the figures computed for their office, not the city', function () {
    $body = dashboardAs('sanitary@biztrack.local');

    $cho = DashboardAnalytics::build(DashboardAnalytics::DEFAULT_WINDOW_MONTHS, 'CHO');
    $city = DashboardAnalytics::build(DashboardAnalytics::DEFAULT_WINDOW_MONTHS);

    expect($body['data']['kpis'])->toEqual($cho['kpis'])
        ->and($body['data']['kpis']['applications_ytd'])->toBeLessThan($city['kpis']['applications_ytd']);

    // Only the office's own visits, and only its own permit type (if the seed
    // has issued any: the column list is read from permits actually on file).
    expect(array_column($body['data']['inspections']['rows'], 'type'))->toBe(['CHO'])
        ->and(array_diff(array_column($body['data']['expiry']['columns'], 'code'), ['SANITARY']))->toBe([]);
});

it('refuses an analytics reader with no office rather than showing them the city', function () {
    $role = Role::where('name', 'sanitary_officer')->firstOrFail();
    $user = User::create([
        'name' => 'No Office',
        'first_name' => 'No',
        'last_name' => 'Office',
        'email' => 'no-office@biztrack.local',
        'password' => Hash::make('biztrack1'),
        'department_id' => null,
        'is_active' => true,
    ]);
    $user->roles()->attach($role->id);

    test()->withHeaders(authAs('no-office@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertForbidden();
});

/* ── what "an office's figures" counts ────────────────────────────────── */

it('counts a filing only for the offices it was routed to', function () {
    $bfp = Department::where('code', 'BFP')->firstOrFail();

    $choBefore = DashboardAnalytics::build(12, 'CHO')['kpis']['applications_this_month'];
    $bfpBefore = DashboardAnalytics::build(12, 'BFP')['kpis']['applications_this_month'];

    // A filing made now and routed to the fire office alone.
    $filing = Application::query()->firstOrFail()->replicate(['tracking_id']);
    $filing->tracking_id = 'BIZ-SCOPE-000001';
    $filing->created_at = now();
    $filing->save();
    ApplicationAssignment::create([
        'application_id' => $filing->id,
        'department_id' => $bfp->id,
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    expect(DashboardAnalytics::build(12, 'BFP')['kpis']['applications_this_month'])->toBe($bfpBefore + 1)
        ->and(DashboardAnalytics::build(12, 'CHO')['kpis']['applications_this_month'])->toBe($choBefore);
});

/* ── BPLO and the super admin may switch ──────────────────────────────── */

it('opens BPLO on BPLO, and lets it switch to any office or to all', function () {
    $body = dashboardAs('bplo@biztrack.local');

    expect($body['scope']['office'])->toBe('BPLO')
        ->and($body['scope']['can_switch'])->toBeTrue()
        ->and(array_column($body['scope']['offices'], 'code'))
        ->toEqualCanonicalizing(['BPLO', 'CHO', 'BFP', 'OBO', 'CENRO', 'CPDO']);

    expect(dashboardAs('bplo@biztrack.local', '?office=BFP')['scope']['office'])->toBe('BFP');

    $all = dashboardAs('bplo@biztrack.local', '?office=all');
    expect($all['scope']['office'])->toBeNull()
        ->and($all['scope']['office_name'])->toBe('All offices')
        ->and($all['data']['kpis'])->toEqual(DashboardAnalytics::build()['kpis']);
});

it('opens the super admin on every office, and lets it switch', function () {
    $body = dashboardAs('admin@biztrack.local');

    expect($body['scope']['office'])->toBeNull()
        ->and($body['scope']['can_switch'])->toBeTrue();

    expect(dashboardAs('admin@biztrack.local', '?office=CENRO')['scope']['office'])->toBe('CENRO');
});

it('says an unknown office is unknown rather than falling back to the city', function () {
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard?office=MARKET')
        ->assertStatus(422);
});

it('still refuses a business owner', function () {
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertForbidden();
});

/* ── the register-wide endpoints ──────────────────────────────────────── */

it('keeps the unscoped summary and CSV export to the two cross-office readers', function () {
    foreach (['sanitary@biztrack.local', 'fire@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))->getJson('/api/v1/analytics/summary')->assertForbidden();
        test()->withHeaders(authAs($email))->get('/api/v1/analytics/export')->assertForbidden();
    }

    foreach (['bplo@biztrack.local', 'admin@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))->getJson('/api/v1/analytics/summary')->assertOk();
    }
});

/* ── precomputation follows the menu ──────────────────────────────────── */

it('precomputes every office at every window the dashboard offers', function () {
    $variants = AnalyticsDatasets::variants(AnalyticsDatasets::DASHBOARD);

    foreach ([3, 6, 12, 24, 36] as $months) {
        expect(in_array(['months' => $months], $variants, true))->toBeTrue("The city at {$months} months is not precomputed.");
        foreach (AnalyticsOffice::codes() as $office) {
            expect(in_array(['months' => $months, 'office' => $office], $variants, true))
                ->toBeTrue("{$office} at {$months} months is not precomputed.");
        }
    }
});

it('keeps the whole-city snapshot key the one stored before offices existed', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk();

    // Served on request here (no snapshot in a fresh test database), and the
    // miss reason proves which key it looked for: a precomputed one.
    expect(dashboardAs('admin@biztrack.local')['meta']['fallback_reason'])->toBe('not_yet_refreshed')
        ->and(dashboardAs('bplo@biztrack.local', '?office=CHO')['meta']['fallback_reason'])->toBe('not_yet_refreshed');
});

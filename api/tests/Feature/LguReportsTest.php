<?php

use App\Models\Department;
use App\Models\OfficeSignatory;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Support\ManilaCalendar;
use Carbon\CarbonImmutable;

/*
 * Report Generation (checklist "Manage Approved Permits – Ken", item 7).
 *
 * Two things are pinned here. The office boundary, which is the dashboard's and
 * must hold on the JSON and on the CSV — the CSV is the one a clerk forwards.
 * And the figures reconciling with the register they claim to count, because a
 * report is a document someone signs.
 *
 * The period is wide on purpose (the three-year maximum): the demo seed's dates
 * move with the clock, and a wide report is one whose totals can be checked against
 * a plain count.
 */

const REPORT_PERIOD = '?from=2024-01-01&to=2026-12-31';

function reportAs(string $email, string $report, string $query = REPORT_PERIOD): array
{
    return test()->withHeaders(authAs($email))
        ->getJson("/api/v1/analytics/reports/{$report}{$query}")
        ->assertOk()
        ->json('data');
}

/* ── the office boundary ──────────────────────────────────────────────── */

it('lists five reports and says whose office they are for', function () {
    $body = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/analytics/reports')
        ->assertOk()
        ->json();

    expect(array_column($body['data'], 'key'))->toBe([
        'permits-issued', 'collections', 'businesses-by-area', 'clearances', 'pending-processing',
    ])
        ->and($body['scope']['office'])->toBe('CHO')
        ->and($body['scope']['can_switch'])->toBeFalse();
});

it('refuses an office admin another office’s report, as JSON and as CSV', function () {
    foreach (['permits-issued', 'collections', 'clearances'] as $report) {
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->getJson("/api/v1/analytics/reports/{$report}".REPORT_PERIOD.'&office=BFP')
            ->assertForbidden();
        test()->withHeaders(authAs('sanitary@biztrack.local'))
            ->get("/api/v1/analytics/reports/{$report}/csv".REPORT_PERIOD.'&office=all')
            ->assertForbidden();
    }
});

it('gives an office admin their own office’s report by default', function () {
    $report = reportAs('fire@biztrack.local', 'clearances');

    expect($report['scope']['office'])->toBe('BFP')
        ->and(array_unique(array_column($report['sections'][0]['rows'], 'office')))->toBe(['BFP']);
});

it('lets BPLO and the super admin choose any office or all', function () {
    expect(reportAs('bplo@biztrack.local', 'clearances', REPORT_PERIOD.'&office=CHO')['scope']['office'])->toBe('CHO')
        ->and(reportAs('bplo@biztrack.local', 'clearances', REPORT_PERIOD.'&office=all')['scope']['office'])->toBeNull()
        ->and(reportAs('admin@biztrack.local', 'clearances')['scope']['office'])->toBeNull();
});

it('refuses a business owner', function () {
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/analytics/reports')
        ->assertForbidden();
});

/* ── the period ───────────────────────────────────────────────────────── */

it('defaults to the current Manila month to date', function () {
    $report = reportAs('bplo@biztrack.local', 'permits-issued', '');

    // Manila's today, not the UTC one: between midnight and 8 am in Malabon
    // the UTC date is still yesterday. AnalyticsManilaDayTest pins the edge.
    expect($report['period'])->toBe([
        'from' => ManilaCalendar::today()->startOfMonth()->toDateString(),
        'to' => ManilaCalendar::today()->toDateString(),
    ]);
});

it('says what is wrong with a period it cannot use', function () {
    $as = fn () => test()->withHeaders(authAs('bplo@biztrack.local'));

    $as()->getJson('/api/v1/analytics/reports/permits-issued?from=2026-09-10&to=2026-09-01')->assertStatus(422);
    $as()->getJson('/api/v1/analytics/reports/permits-issued?from=09/01/2026')->assertStatus(422);
    $as()->getJson('/api/v1/analytics/reports/permits-issued?from=2020-01-01&to=2026-01-01')
        ->assertStatus(422)
        ->assertJsonPath('message', 'A report can cover up to three years. Choose a shorter period.');
});

it('answers an unknown report with a 404', function () {
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/reports/renewal-risk')
        ->assertNotFound();
});

/* ── the figures reconcile with the register ──────────────────────────── */

it('counts every permit issued in the period, and only the office’s own types when scoped', function () {
    $from = CarbonImmutable::parse('2024-01-01')->startOfDay();
    $to = CarbonImmutable::parse('2026-09-30')->endOfDay();
    $query = '?from=2024-01-01&to=2026-09-30';

    $all = reportAs('admin@biztrack.local', 'permits-issued', $query);
    expect($all['sections'][0]['total']['total'])
        ->toBe(Permit::whereBetween('issued_at', [$from, $to])->count())
        // The month table and the type table are one population in two cuts.
        ->and($all['sections'][1]['total']['total'])->toBe($all['sections'][0]['total']['total']);

    $sanitary = PermitType::where('code', 'SANITARY')->value('id');
    $cho = reportAs('sanitary@biztrack.local', 'permits-issued', $query);
    expect($cho['sections'][0]['total']['total'])
        ->toBe(Permit::whereBetween('issued_at', [$from, $to])->where('permit_type_id', $sanitary)->count());
});

it('accounts for every peso collected in the period across all offices', function () {
    $from = CarbonImmutable::parse('2024-01-01')->startOfDay();
    $to = CarbonImmutable::parse('2026-09-30')->endOfDay();

    $report = reportAs('admin@biztrack.local', 'collections', '?from=2024-01-01&to=2026-09-30');
    $collected = (float) Payment::where('status', 'completed')->whereBetween('paid_at', [$from, $to])->sum('amount');

    // Spread over fee lines and rounded per row, so a few centavos may move.
    expect(abs($report['sections'][2]['total']['amount'] - $collected))->toBeLessThan(1.0)
        ->and(abs($report['sections'][0]['total']['amount'] - $report['sections'][1]['total']['amount']))->toBeLessThan(0.05);
});

it('credits an office only with fees billed in its name', function () {
    $report = reportAs('fire@biztrack.local', 'collections');

    expect(array_column($report['sections'][0]['rows'], 'label'))
        ->each->toBe(Department::where('code', 'BFP')->value('name'));
});

it('keeps each business once in the barangay table', function () {
    $report = reportAs('admin@biztrack.local', 'businesses-by-area');

    $issued = Permit::whereBetween('issued_at', [
        CarbonImmutable::parse('2024-01-01'), CarbonImmutable::parse('2026-12-31')->endOfDay(),
    ])->distinct()->count('business_id');

    expect($report['sections'][0]['total']['total'])->toBe($issued);
});

it('keeps pending age buckets adding up to the pending total', function () {
    $report = reportAs('sanitary@biztrack.local', 'pending-processing');
    $pending = $report['sections'][1];

    foreach ($pending['rows'] as $row) {
        expect($row['within_3'] + $row['within_7'] + $row['within_20'] + $row['over_20'])->toBe($row['total']);
    }
});

/* ── the page it prints on ────────────────────────────────────────────── */

it('names who prepared it, and takes the noted-by line from the office’s own signatories', function () {
    $cenro = Department::where('code', 'CENRO')->firstOrFail();
    OfficeSignatory::query()->where('department_id', $cenro->id)->delete();
    OfficeSignatory::create(['department_id' => $cenro->id, 'role' => 'Evaluator', 'name' => 'A. Evaluator', 'sort_order' => 0]);
    OfficeSignatory::create(['department_id' => $cenro->id, 'role' => 'Head of Office', 'name' => 'B. Head', 'sort_order' => 1]);

    $report = reportAs('cenro@biztrack.local', 'clearances');

    expect($report['prepared_by']['name'])->not->toBe('')
        ->and($report['noted_by'])->toBe(['name' => 'B. Head', 'position' => 'Head of Office']);

    // Nobody signs an all-office report on one office's behalf.
    expect(reportAs('admin@biztrack.local', 'clearances')['noted_by'])->toBeNull();
});

it('exports the same figures as CSV, headed with the office and period', function () {
    $response = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->get('/api/v1/analytics/reports/permits-issued/csv?from=2026-01-01&to=2026-09-30')
        ->assertOk();

    expect($response->headers->get('content-disposition'))
        ->toContain('permits-issued-cho-2026-01-01-to-2026-09-30.csv');

    $csv = $response->streamedContent();
    $json = reportAs('sanitary@biztrack.local', 'permits-issued', '?from=2026-01-01&to=2026-09-30');

    expect($csv)->toContain('City of Malabon')
        ->and($csv)->toContain('City Health Office')
        ->and($csv)->toContain('Total,'.$json['sections'][0]['total']['new'].','.$json['sections'][0]['total']['renewal']);
});

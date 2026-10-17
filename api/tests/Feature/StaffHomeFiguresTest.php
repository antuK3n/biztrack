<?php

use App\Models\Application;
use App\Support\DashboardAnalytics;

/*
 * The office admin's home page (18 October 2026): four tiles over the queue
 * and the permits register. Two of them needed something the API did not have.
 *
 *  - "Overdue" opens the queue narrowed to filings past their RA 11032
 *    deadline, so `/assignments` learned `?overdue=1`. The tile's number is
 *    that same request's `meta.total`, so the count and the list it opens
 *    cannot disagree.
 *  - "Fees this month" is the dashboard's Fees Collected figure, served alone
 *    from `analytics/fees-month` so a sign-in does not pull the whole
 *    dashboard. It must be the SAME figure under the SAME office boundary.
 */

/* ── /assignments?overdue=1 ───────────────────────────────────────────── */

it('narrows the queue to filings whose statutory deadline has passed', function () {
    authAs('bplo@biztrack.local');

    $rows = test()->getJson('/api/v1/assignments?per_page=200')->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();

    // Every filing comfortably inside its deadline, then one pushed past it.
    Application::query()->update(['deadline_at' => now()->addDays(10)]);
    $late = (int) $rows[0]['application']['id'];
    Application::whereKey($late)->update(['deadline_at' => now()->subDay()]);

    $overdue = test()->getJson('/api/v1/assignments?overdue=1&per_page=200')->assertOk()->json();

    expect($overdue['meta']['total'])->toBeGreaterThan(0)
        ->and(array_unique(array_column(array_column($overdue['data'], 'application'), 'id')))->toBe([$late]);
});

it('never calls a filing with no deadline overdue', function () {
    authAs('bplo@biztrack.local');

    Application::query()->update(['deadline_at' => null]);

    expect(test()->getJson('/api/v1/assignments?overdue=1')->assertOk()->json('meta.total'))->toBe(0);
});

it('leaves the queue whole when overdue is off or absent', function () {
    authAs('bplo@biztrack.local');

    Application::query()->update(['deadline_at' => now()->subDay()]);
    $all = test()->getJson('/api/v1/assignments')->assertOk()->json('meta.total');

    expect(test()->getJson('/api/v1/assignments?overdue=0')->assertOk()->json('meta.total'))->toBe($all);

    test()->getJson('/api/v1/assignments?overdue=soon')->assertUnprocessable();
});

/* ── analytics/fees-month ─────────────────────────────────────────────── */

it('serves an office admin the dashboard’s own fees figure for their office', function () {
    authAs('sanitary@biztrack.local');

    $body = test()->getJson('/api/v1/analytics/fees-month')->assertOk()->json();
    $panel = DashboardAnalytics::build(DashboardAnalytics::DEFAULT_WINDOW_MONTHS, 'CHO')['fees_collected'];

    expect($body['scope']['office'])->toBe('CHO')
        ->and($body['data']['month_total'])->toEqual($panel['month_total'])
        ->and($body['data']['payments_month'])->toBe($panel['payments_month'])
        ->and($body['data']['month_start'])->toBe($panel['month_start']);
});

it('serves the super admin every office’s fees', function () {
    authAs('admin@biztrack.local');

    $body = test()->getJson('/api/v1/analytics/fees-month')->assertOk()->json();
    $panel = DashboardAnalytics::build(DashboardAnalytics::DEFAULT_WINDOW_MONTHS)['fees_collected'];

    expect($body['scope']['office'])->toBeNull()
        ->and($body['data']['month_total'])->toEqual($panel['month_total']);
});

it('refuses an office admin another office’s fees', function () {
    authAs('sanitary@biztrack.local');
    test()->getJson('/api/v1/analytics/fees-month?office=BFP')->assertForbidden();
    test()->getJson('/api/v1/analytics/fees-month?office=all')->assertForbidden();
});

it('refuses the fees figure to an account without analytics', function () {
    authAs('owner@biztrack.local');
    test()->getJson('/api/v1/analytics/fees-month')->assertForbidden();
});

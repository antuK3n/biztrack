<?php

use App\Enums\PermitStatus;
use App\Models\Business;
use App\Models\Permit;
use App\Support\DashboardAnalytics;
use Carbon\CarbonImmutable;

/*
 * Blacklisting a business closes it on the dashboard's New and Closed
 * Businesses panel.
 *
 * THE GAP THIS EXISTS TO CATCH. `businesses.status` — active / flagged /
 * suspended / blacklisted — has been settable by an admin since the beginning
 * and reached no chart at all. So an admin could strike a business off the
 * register in the morning and find the closures line flat at zero underneath
 * it, because the chart drew only soft-deleted rows and nothing in the product
 * can soft-delete a business.
 *
 * These tests were written against Business Growth Analysis's Closure Trend.
 * That screen was removed (checklist 2026-09-27, item 6) and its closure line
 * moved to the dashboard, so the rules moved with it. The status-summary half
 * (Active / Expired / Inactive / Closed) went with the screen and has no test
 * here any more.
 *
 * WHY DELTAS AND NOT ABSOLUTE COUNTS. The register these run against is the
 * demo seed and its size is not this feature's business. Each test reads the
 * engine before and after the status change, so what is pinned is the movement
 * the change causes.
 *
 * SUSPENSION IS DELIBERATELY EXCLUDED and has a test of its own. A suspension
 * is temporary; counting it as a closure would turn this chart into a chart of
 * sanctions.
 */

/** A live business: registered, not removed, holding a permit valid today. */
function aTradingBusiness(): Business
{
    $businessId = Permit::query()
        ->where('status', PermitStatus::Active->value)
        ->whereDate('valid_until', '>=', CarbonImmutable::now()->toDateString())
        ->value('business_id');

    expect($businessId)->not->toBeNull('The demo seed has no business holding a permit valid today.');

    return Business::whereKey($businessId)->where('status', 'active')->firstOrFail();
}

/** @return array{rows: list<array<string, mixed>>, registered: int, closed: int} */
function movement(): array
{
    return DashboardAnalytics::build()['business_movement'];
}

/** How many closures the panel puts in one month. */
function closuresInMonth(array $movement, string $month): int
{
    $row = collect($movement['rows'])->firstWhere('month', $month);

    return $row === null ? 0 : (int) $row['closed'];
}

it('draws the blacklisting in the month it was recorded, and in the window total', function () {
    $business = aTradingBusiness();
    $blacklistedAt = CarbonImmutable::now()->subMonths(2);
    $month = $blacklistedAt->format('Y-m');

    $before = movement();

    $business->update([
        'status' => Business::STATUS_BLACKLISTED,
        'status_changed_at' => $blacklistedAt,
    ]);

    $after = movement();

    expect(closuresInMonth($after, $month))->toBe(closuresInMonth($before, $month) + 1)
        ->and($after['closed'])->toBe($before['closed'] + 1);
});

it('keeps the closure total equal to the sum of the monthly rows', function () {
    $business = aTradingBusiness();
    $business->update([
        'status' => Business::STATUS_BLACKLISTED,
        'status_changed_at' => CarbonImmutable::now()->subMonth(),
    ]);

    $report = movement();

    // Two figures for one fact on one screen. A reader who adds up the chart
    // must land on the total printed beside it.
    expect(array_sum(array_column($report['rows'], 'closed')))->toBe($report['closed'])
        ->and(array_sum(array_column($report['rows'], 'registered')))->toBe($report['registered']);
});

it('takes the point back off when the blacklisting is lifted', function () {
    $business = aTradingBusiness();
    $blacklistedAt = CarbonImmutable::now()->subMonth();
    $month = $blacklistedAt->format('Y-m');

    $before = movement();

    $business->update(['status' => Business::STATUS_BLACKLISTED, 'status_changed_at' => $blacklistedAt]);

    /*
     * A sanction being lifted takes its point back off the chart, which a
     * removal from the register never does. The panel describes the register
     * as it stands, not everything that ever happened to it.
     */
    $business->update(['status' => 'active', 'status_changed_at' => CarbonImmutable::now()]);

    $after = movement();

    expect(closuresInMonth($after, $month))->toBe(closuresInMonth($before, $month))
        ->and($after['closed'])->toBe($before['closed']);
});

it('does not count a suspended business as closed', function () {
    $business = aTradingBusiness();
    $suspendedAt = CarbonImmutable::now()->subMonth();

    $before = movement();

    $business->update(['status' => 'suspended', 'status_changed_at' => $suspendedAt]);

    $after = movement();

    expect($after['closed'])->toBe($before['closed'])
        ->and(closuresInMonth($after, $suspendedAt->format('Y-m')))
        ->toBe(closuresInMonth($before, $suspendedAt->format('Y-m')));
});

it('leaves an undated blacklisting off the monthly closures', function () {
    $business = aTradingBusiness();

    $before = movement();

    // No status_changed_at: a sanction that predates the column, or one whose
    // audit row recorded no change. There is no month to put it in.
    $business->update(['status' => Business::STATUS_BLACKLISTED, 'status_changed_at' => null]);

    expect(movement()['closed'])->toBe($before['closed']);
});

it('counts a business removed from the register once, not also as blacklisted', function () {
    $business = aTradingBusiness();
    $business->update([
        'status' => Business::STATUS_BLACKLISTED,
        'status_changed_at' => CarbonImmutable::now()->subMonths(2),
    ]);

    $before = movement();

    $business->delete();

    // Removed after being struck off: it closed once, on the day it was removed.
    expect(movement()['closed'])->toBe($before['closed']);
});

it('does not re-date a closure when an admin re-saves the same status', function () {
    $business = aTradingBusiness();
    $blacklistedAt = CarbonImmutable::now()->subMonths(3);
    $business->update(['status' => Business::STATUS_BLACKLISTED, 'status_changed_at' => $blacklistedAt]);

    $admin = authAs('admin@biztrack.local');
    test()->withHeaders($admin)
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'blacklisted',
            'reason' => 'Reviewed, sanction stands',
        ])
        ->assertOk();

    // Re-affirming a blacklisting is not a second closure, and must not drag
    // the existing one into the current month.
    expect($business->fresh()->status_changed_at->toDateTimeString())
        ->toBe($blacklistedAt->toDateTimeString());
});

<?php

use App\Enums\PermitStatus;
use App\Models\Business;
use App\Models\Permit;
use App\Support\BusinessGrowthAnalytics;
use Carbon\CarbonImmutable;

/*
 * Blacklisting a business closes it on the Business Lifecycle screen.
 *
 * THE GAP THIS EXISTS TO CATCH. `businesses.status` — active / flagged /
 * suspended / blacklisted — has been settable by an admin since the beginning
 * and reached no chart at all. It blocked new filings and stopped there. So an
 * admin could strike a business off the register in the morning and find it
 * still counted as Active on the dashboard in the afternoon, with the Business
 * Closure Trend flat at zero underneath it, because that chart drew only
 * soft-deleted rows and nothing in the product can soft-delete a business.
 *
 * Every test below fails on the old code. The one that names the gap most
 * directly is "moves a business out of Active and into Closed": on the old
 * engine a blacklisted business went on being counted from its permits, so the
 * Active count did not move and Closed did not either. The trend test is the
 * other half — it is the one that fails if `status_changed_at` is dropped or
 * the trend goes back to reading `deleted_at` alone.
 *
 * WHY DELTAS AND NOT ABSOLUTE COUNTS. The register these run against is the
 * demo seed and its size is not this feature's business. Each test reads the
 * engine before and after the status change, so what is pinned is the movement
 * the change causes and nothing about how many businesses happen to be seeded.
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

/** @return array<string, int> the status summary, keyed by state. */
function statusCountsFrom(array $report): array
{
    return array_column($report['status_summary'], 'count', 'status');
}

/** How many closures the trend puts in one month. */
function closuresInMonth(array $report, string $month): int
{
    $row = collect($report['closure_trend'])->firstWhere('month', $month);

    return $row === null ? 0 : (int) $row['closures'];
}

it('moves a business out of Active and into Closed when it is blacklisted', function () {
    $business = aTradingBusiness();

    $before = statusCountsFrom(BusinessGrowthAnalytics::build());
    expect($before['active'])->toBeGreaterThan(0);

    $business->update([
        'status' => Business::STATUS_BLACKLISTED,
        'status_changed_at' => CarbonImmutable::now(),
    ]);

    $after = statusCountsFrom(BusinessGrowthAnalytics::build());

    expect($after['closed'])->toBe($before['closed'] + 1)
        ->and($after['active'])->toBe($before['active'] - 1);

    /*
     * Its permit is untouched — nothing revokes one on a blacklisting — so on
     * the old engine this business went on being counted as Active. The total
     * is unchanged because the business is still on the register; it has only
     * moved bucket.
     */
    expect(array_sum($after))->toBe(array_sum($before));
});

it('draws the blacklisting in the month it was recorded, and in the period total', function () {
    $business = aTradingBusiness();
    $blacklistedAt = CarbonImmutable::now()->subMonths(2);
    $month = $blacklistedAt->format('Y-m');

    $before = BusinessGrowthAnalytics::build();

    $business->update([
        'status' => Business::STATUS_BLACKLISTED,
        'status_changed_at' => $blacklistedAt,
    ]);

    $after = BusinessGrowthAnalytics::build();

    expect(closuresInMonth($after, $month))->toBe(closuresInMonth($before, $month) + 1)
        ->and($after['closures'])->toBe($before['closures'] + 1);
});

it('keeps the closure headline equal to the sum of the trend', function () {
    $business = aTradingBusiness();
    $business->update([
        'status' => Business::STATUS_BLACKLISTED,
        'status_changed_at' => CarbonImmutable::now()->subMonth(),
    ]);

    $report = BusinessGrowthAnalytics::build();

    // Two figures for one fact on one screen. A reader who adds up the chart
    // must land on the card above it.
    expect(array_sum(array_column($report['closure_trend'], 'closures')))
        ->toBe($report['closures']);
});

it('puts the business back where it was when the blacklisting is lifted', function () {
    $business = aTradingBusiness();
    $blacklistedAt = CarbonImmutable::now()->subMonth();
    $month = $blacklistedAt->format('Y-m');

    $before = BusinessGrowthAnalytics::build();

    $business->update(['status' => Business::STATUS_BLACKLISTED, 'status_changed_at' => $blacklistedAt]);

    /*
     * A sanction being lifted takes its point back off the chart, which a
     * removal from the register never does. The client was told this reads
     * differently from the closures already drawn and accepted it: the panel
     * describes the register as it stands, not everything that ever happened
     * to it.
     */
    $business->update(['status' => 'active', 'status_changed_at' => CarbonImmutable::now()]);

    $after = BusinessGrowthAnalytics::build();

    expect(statusCountsFrom($after))->toBe(statusCountsFrom($before))
        ->and(closuresInMonth($after, $month))->toBe(closuresInMonth($before, $month))
        ->and($after['closures'])->toBe($before['closures']);
});

it('does not count a suspended business as closed', function () {
    $business = aTradingBusiness();
    $suspendedAt = CarbonImmutable::now()->subMonth();

    $before = BusinessGrowthAnalytics::build();

    $business->update(['status' => 'suspended', 'status_changed_at' => $suspendedAt]);

    $after = BusinessGrowthAnalytics::build();

    // A suspension is temporary and the business is expected back. It reads
    // from its permits exactly as it did before.
    expect(statusCountsFrom($after))->toBe(statusCountsFrom($before))
        ->and($after['closures'])->toBe($before['closures'])
        ->and(closuresInMonth($after, $suspendedAt->format('Y-m')))
        ->toBe(closuresInMonth($before, $suspendedAt->format('Y-m')));
});

it('counts an undated blacklisting as closed but leaves it off the trend', function () {
    $business = aTradingBusiness();

    $before = BusinessGrowthAnalytics::build();

    // No status_changed_at: a sanction that predates the column, or one whose
    // audit row recorded no change. There is no month to put it in.
    $business->update(['status' => Business::STATUS_BLACKLISTED, 'status_changed_at' => null]);

    $after = BusinessGrowthAnalytics::build();

    expect(statusCountsFrom($after)['closed'])->toBe(statusCountsFrom($before)['closed'] + 1)
        ->and($after['closures'])->toBe($before['closures']);
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

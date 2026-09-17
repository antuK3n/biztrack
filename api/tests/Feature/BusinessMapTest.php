<?php

use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;

/*
 * GET /admin/business-map — every business at its pin, with the state of its
 * Mayor's Permit (issue #104).
 *
 * The rules pinned here are the ones a reader of this map has to be able to
 * trust, and each of them was a decision that could plausibly have gone the
 * other way.
 */

/** The permit type the map answers about, looked up the way the controller does. */
function businessPermitTypeId(): int
{
    return (int) PermitType::where('code', 'BUSINESS')->value('id');
}

/**
 * A Mayor's Permit on a business, minted directly.
 *
 * There is no Permit factory in this repo and adding one for this file would be
 * a new fixture for every other suite to keep working; the tests that need a
 * permit all build one the same way. `application_id` is a NOT NULL foreign key
 * and the map never reads it, so any existing filing satisfies it.
 */
function mapPermit(Business $business, PermitStatus $status, string $from, string $until): Permit
{
    return Permit::create([
        'permit_number' => 'MAP-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'application_id' => Application::firstOrFail()->id,
        'business_id' => $business->id,
        'permit_type_id' => businessPermitTypeId(),
        'status' => $status->value,
        'valid_from' => $from,
        'valid_until' => $until,
    ]);
}

it('is refused to an office that may read permits but not manage users', function () {
    /*
     * The gate, stated as a test because the permission is a stand-in and reads
     * like the wrong one.
     *
     * BPLO holds `permit.view_all`, which is the permission whose NAME fits
     * this screen, and must still be refused: the map plots every business in
     * the city at once, and a city-wide read is the cross-office view
     * ApplicationVisibility exists to refuse everyone but the super admin
     * (AGENTS.md §10). If someone ever "corrects" the middleware to the
     * better-reading permission, this is what says no.
     */
    $bplo = authAs('bplo@biztrack.local');

    test()->withHeaders($bplo)->getJson('/api/v1/admin/business-map')->assertForbidden();
});

it('gives the super admin every business that carries a pin', function () {
    $admin = authAs('admin@biztrack.local');

    $body = test()->withHeaders($admin)
        ->getJson('/api/v1/admin/business-map')
        ->assertOk()
        ->json();

    $plottable = Business::query()
        ->whereHas('address', fn ($a) => $a->whereNotNull('latitude')->whereNotNull('longitude'))
        ->count();

    expect($body['meta']['plotted'])->toBe($plottable)
        ->and($body['data'])->toHaveCount($plottable);
});

it('counts the businesses it could not plot instead of dropping them quietly', function () {
    /*
     * A map that silently omits part of the register is worse than a table,
     * because it reads as complete. `businesses_total` and `unmapped` are the
     * two numbers that let a reader check what is missing, so they have to
     * reconcile — not merely be present.
     */
    $admin = authAs('admin@biztrack.local');

    $meta = test()->withHeaders($admin)
        ->getJson('/api/v1/admin/business-map')
        ->assertOk()
        ->json('meta');

    expect($meta['plotted'] + $meta['unmapped'])->toBe($meta['businesses_total']);
});

it('calls a permit lapsed when its term has run out, whatever the status column says', function () {
    /*
     * The bug this screen exists not to have.
     *
     * `biztrack:scan-permits` flips a past-due permit to `expired`, and nothing
     * in this repo schedules it — so the live register holds permits carrying
     * status `active` whose `valid_until` passed months ago (149 of them on 17
     * September 2026). Trusting the column would paint those businesses as
     * trading legally.
     *
     * Written as a regression: the permit below is exactly that row, and the
     * cheap implementation (`where status = active`) answers 'active' for it.
     */
    $business = Business::query()
        ->whereHas('address', fn ($a) => $a->whereNotNull('latitude')->whereNotNull('longitude'))
        ->firstOrFail();

    Permit::where('business_id', $business->id)->delete();
    mapPermit(
        $business,
        PermitStatus::Active,
        now()->subYears(2)->toDateString(),
        now()->subMonths(6)->toDateString(),
    );

    $admin = authAs('admin@biztrack.local');

    $row = collect(
        test()->withHeaders($admin)->getJson('/api/v1/admin/business-map')->assertOk()->json('data')
    )->firstWhere('id', $business->id);

    expect($row['state'])->toBe('lapsed');
});

it('separates a business that never held a permit from one whose permit lapsed', function () {
    /*
     * Three states, not two. Folding "never held one" into "lapsed" would tell
     * a BPLO clerk a certificate ran out when none was ever issued, and those
     * are different jobs — a renewal to chase versus a first filing that never
     * finished.
     */
    $business = Business::query()
        ->whereHas('address', fn ($a) => $a->whereNotNull('latitude')->whereNotNull('longitude'))
        ->firstOrFail();

    Permit::where('business_id', $business->id)->delete();

    $admin = authAs('admin@biztrack.local');

    $row = collect(
        test()->withHeaders($admin)->getJson('/api/v1/admin/business-map')->assertOk()->json('data')
    )->firstWhere('id', $business->id);

    expect($row['state'])->toBe('none')
        ->and($row['permit_number'])->toBeNull()
        ->and($row['valid_until'])->toBeNull();
});

it('reports the live permit when a business holds both a superseded one and its replacement', function () {
    /*
     * Renewing early legitimately leaves a business holding two Mayor's
     * Permits: the superseded one, still inside its printed term, and the
     * replacement. The map must answer with the one that governs today.
     *
     * Ordering by `valid_until` is what makes that true; ordering by id or by
     * `issued_at` would also pass on tidy data and fail on a permit issued out
     * of sequence, which is what a paper-to-system backfill looks like.
     */
    $business = Business::query()
        ->whereHas('address', fn ($a) => $a->whereNotNull('latitude')->whereNotNull('longitude'))
        ->firstOrFail();

    Permit::where('business_id', $business->id)->delete();
    mapPermit(
        $business,
        PermitStatus::Superseded,
        now()->subMonths(11)->toDateString(),
        now()->addMonth()->toDateString(),
    );
    $replacement = mapPermit(
        $business,
        PermitStatus::Active,
        now()->toDateString(),
        now()->addYear()->toDateString(),
    );

    $admin = authAs('admin@biztrack.local');

    $row = collect(
        test()->withHeaders($admin)->getJson('/api/v1/admin/business-map')->assertOk()->json('data')
    )->firstWhere('id', $business->id);

    expect($row['state'])->toBe('active')
        ->and($row['permit_number'])->toBe($replacement->permit_number);
});

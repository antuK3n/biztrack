<?php

use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;

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

it('is BPLO’s and the super admin’s, and refused to every other office', function () {
    /*
     * The gate, stated as a test because the permission is a stand-in.
     *
     * Checklist item 16 put the map on BPLO's Permits page, so BPLO is let in
     * now — it was refused while the gate was `user.manage`. The gate is
     * `application.view_any_office`, the permission that lifts the office
     * boundary, and the five clearance offices do not hold it. They DO hold
     * `permit.view_all`, the permission whose name fits this screen; if
     * someone ever "corrects" the middleware to it, the refusals below are
     * what say no — a city-wide plot is the cross-office read
     * ApplicationVisibility exists to refuse them (AGENTS.md §10).
     */
    foreach (['bplo@biztrack.local', 'admin@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))->getJson('/api/v1/admin/business-map')->assertOk();
    }

    foreach ([
        'sanitary@biztrack.local',
        'fire@biztrack.local',
        'zoning@biztrack.local',
        'obo@biztrack.local',
        'cenro@biztrack.local',
        'owner@biztrack.local',
    ] as $email) {
        test()->withHeaders(authAs($email))->getJson('/api/v1/admin/business-map')->assertForbidden();
    }
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

it('calls a permit expired when its term has run out, whatever the status column says', function () {
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
     * It was called 'lapsed' until the map grew to five states.
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

    expect($row['state'])->toBe('expired');
});

it('separates a business that never held a permit from one whose permit expired', function () {
    /*
     * Its own state. Folding "never held one" into "expired" would tell
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

it('says revoked and suspended rather than folding them into expired', function () {
    /*
     * Checklist item 16 asks for five states, because each is a different job:
     * a revoked permit is enforcement, a suspended one is a clearance to settle
     * or a suspension to lift, an expired one is a renewal to chase. A map that
     * said "lapsed" for all three sent all three to the same desk.
     *
     * A suspension counts only inside its term — past it the permit is simply
     * expired — and a revocation counts whatever the dates say.
     */
    // The seeded register holds only a couple of businesses, so three are
    // registered here and pinned — anywhere in Malabon will do; the state is
    // what is tested.
    $owner = authAs('owner@biztrack.local');
    $pinned = collect(range(1, 3))->map(function (int $i) use ($owner) {
        $id = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
            'name' => "Map State Store {$i}",
            'registration_type' => 'DTI',
            'registration_number' => 'DTI-'.random_int(100000, 999999),
            'tin' => '123-456-789-000',
            'address' => ['line1' => "{$i} Map Street", 'barangay_id' => Barangay::first()->id],
            'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
        ])->assertCreated()->json('data.id');

        $business = Business::with('address')->findOrFail($id);
        $business->address->update(['latitude' => 14.66, 'longitude' => 120.95]);

        return $business;
    });

    [$revoked, $suspended, $oldSuspension] = $pinned->all();

    foreach ($pinned as $b) {
        Permit::where('business_id', $b->id)->delete();
    }

    mapPermit($revoked, PermitStatus::Revoked, now()->subMonth()->toDateString(), now()->addYear()->toDateString());
    mapPermit($suspended, PermitStatus::Suspended, now()->subMonth()->toDateString(), now()->addYear()->toDateString());
    mapPermit($oldSuspension, PermitStatus::Suspended, now()->subYears(2)->toDateString(), now()->subYear()->toDateString());

    $body = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/admin/business-map')
        ->assertOk()
        ->json();

    $rows = collect($body['data'])->keyBy('id');

    expect($rows[$revoked->id]['state'])->toBe('revoked')
        ->and($rows[$suspended->id]['state'])->toBe('suspended')
        ->and($rows[$oldSuspension->id]['state'])->toBe('expired')
        // The popup opens the certificate, so the id travels with the number.
        ->and($rows[$revoked->id]['permit_id'])->not->toBeNull();

    // The legend's counts are the five states and nothing else, and they add up.
    expect(array_keys($body['meta']['counts']))->toEqualCanonicalizing(['active', 'expired', 'suspended', 'revoked', 'none'])
        ->and(array_sum($body['meta']['counts']))->toBe($body['meta']['plotted'])
        ->and($body['meta']['truncated'])->toBeFalse();
});

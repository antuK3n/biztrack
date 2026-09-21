<?php

use App\Models\Application;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\UnbilledPermitFee;
use App\Models\User;

/*
 * What a business owes in deferred permit fees is visible to the admin.
 *
 * Client's decision, 17 September 2026, on a business that never renews:
 * *"They wait indefinitely, and are visible."* No penalty, no lapsing permit —
 * but nobody has to remember it either, which is the half that needed building.
 *
 * `DeferredPermitFeeTest` owns the ledger's behaviour: recording, claiming and
 * collecting. This file owns whether anyone can SEE it.
 */

/** An unbilled fee against the seeded owner's business. */
function deferredFeeFor(string $code, float $amount, ?int $claimedBy = null): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $type = PermitType::where('code', $code)->firstOrFail();

    $incurredOn = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'approved',
    ]);

    $fee = UnbilledPermitFee::create([
        'business_id' => $business->id,
        'application_id' => $incurredOn->id,
        'permit_type_id' => $type->id,
        'amount' => $amount,
        'incurred_at' => now()->subMonths(3),
        'billed_on_application_id' => $claimedBy,
    ]);

    return [$business, $fee];
}

/*
 * ── The money assertions below use `toEqual`, not `toBe` ──────────────────
 *
 * JSON has one number type. `round(2100.00, 2)` encodes as `2100`, so a strict
 * `toBe(2100.0)` fails on int-versus-float — *"Failed asserting that 2100 is
 * identical to 2100.0"* — which says nothing about permits or fees and sends
 * the next reader looking for a rounding bug that is not there.
 *
 * Loose equality is the right strictness for a figure that has crossed the
 * wire. Where the TYPE matters it is asserted separately: `items` is checked
 * with `toBe([])` because an empty array and a null are genuinely different
 * answers there.
 */

/** This business's row from the admin roster. */
function adminBusinessRow(int $businessId): array
{
    $rows = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/businesses?per_page=100')
        ->assertOk()
        ->json('data');

    return collect($rows)->firstWhere('id', $businessId) ?? [];
}

it('shows the admin what a business owes, and what for', function () {
    [$business] = deferredFeeFor('SANITARY', 1200.00);
    deferredFeeFor('FSIC', 900.00);

    $row = adminBusinessRow($business->id);

    expect($row['unbilled_fees']['total'])->toEqual(2100.0);

    /*
     * Itemised, not just totalled. "₱2,100" is not something an officer can
     * raise with an owner on the phone; "the Sanitary Permit from June and the
     * FSIC from August" is — which is the whole reason the items come down with
     * the row rather than behind a second request.
     */
    $names = collect($row['unbilled_fees']['items'])->pluck('permit_code')->sort()->values()->all();
    expect($names)->toBe(['FSIC', 'SANITARY']);
});

it('reports zero rather than nothing for a business with no deferred fees', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $row = adminBusinessRow($business->id);

    /*
     * A zero and an absent key are different facts, and the screen tells them
     * apart: absent means an older payload that cannot answer, zero means
     * nothing is owed. Omitting the key here would make every business look
     * un-answerable.
     */
    expect($row)->toHaveKey('unbilled_fees');
    expect($row['unbilled_fees']['total'])->toEqual(0.0);
    expect($row['unbilled_fees']['items'])->toBe([]);
});

it('still counts a fee that is sitting on an unpaid bill, and says so', function () {
    /*
     * Claimed is not collected. A fee on a January renewal the applicant has
     * been shown and not settled is money the LGU has not received, so it stays
     * in the arrears total — but an officer about to chase it needs to know a
     * renewal is already in flight carrying it.
     */
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $january = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'pending_payment',
    ]);

    deferredFeeFor('SANITARY', 1200.00, $january->id);

    $row = adminBusinessRow($business->id);

    expect($row['unbilled_fees']['total'])->toEqual(1200.0);
    expect($row['unbilled_fees']['items'][0]['on_a_bill'])->toBeTrue();
});

it('drops a fee out of the arrears once it has been paid', function () {
    [$business, $fee] = deferredFeeFor('SANITARY', 1200.00);

    expect(adminBusinessRow($business->id)['unbilled_fees']['total'])->toEqual(1200.0);

    $fee->update(['billed_at' => now()]);

    expect(adminBusinessRow($business->id)['unbilled_fees']['total'])->toEqual(0.0);
});

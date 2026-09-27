<?php

use App\Models\Business;

/*
 * The super admin's business roster (Business Owner Status) and retired
 * businesses — checklist item 21: "a filter to show retired business records,
 * hidden by default".
 *
 * Retired means removed from the register: `Business` soft-deletes, so a
 * retired row keeps its data and its certificates but drops out of the default
 * scope. The roster already left them out; this pins that as the default and
 * adds the way to ask for them.
 */

function rosterIds(array $query = []): array
{
    $qs = http_build_query(array_merge(['per_page' => 100], $query));

    return collect(
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson('/api/v1/admin/businesses?'.$qs)
            ->assertOk()
            ->json('data')
    )->pluck('retired_at', 'id')->all();
}

it('leaves retired businesses off the roster unless they are asked for', function () {
    $retired = Business::firstOrFail();
    $retired->delete();

    expect(rosterIds())->not->toHaveKey($retired->id);

    $only = rosterIds(['status' => 'retired']);
    expect($only)->toHaveKey($retired->id)
        ->and($only[$retired->id])->not->toBeNull();

    // Nothing live leaks into the retired list.
    foreach (array_keys($only) as $id) {
        expect(Business::withTrashed()->find($id)->trashed())->toBeTrue();
    }
});

it('marks a live business as not retired', function () {
    foreach (rosterIds() as $retiredAt) {
        expect($retiredAt)->toBeNull();
    }
});

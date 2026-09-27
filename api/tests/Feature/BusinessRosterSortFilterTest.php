<?php

use App\Models\Business;
use App\Models\UnbilledPermitFee;
use App\Models\User;

/*
 * ── Ordering and narrowing the business roster ─────────────────────────────
 *
 * It came back newest-registration-first and only that, with one status
 * filter. An admin owed money by somebody had no way to ask which businesses
 * owe the most, and one holding a name had to page 705 rows to find it
 * [client, 27 September 2026: *"sa owner status page, pakilagyan ng mga pwede
 * pang ifilter at isort"*].
 *
 * The sorts go through a whitelist. The test below that proves an unknown one
 * is refused is the one that matters most here: `orderBy($request->input())`
 * hands a column name from the internet to the query planner.
 */

function roster(array $params = []): array
{
    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/businesses?'.http_build_query($params + ['per_page' => 200]))
        ->assertOk()
        ->json('data');
}

/**
 * Put some money on the board.
 *
 * The seeded register carries no deferred fees at all, so the tests about them
 * were passing over an empty set — "every owing business owes more than zero"
 * is true of no businesses, and `max()` of an empty list is what caught it.
 * Each of these builds the state it is about.
 *
 * Several fees on one business on purpose: that is the shape a join would have
 * multiplied into three rows of a twenty-row page.
 */
function oweMoney(int $businessId, array $amounts): void
{
    $permitTypeId = \App\Models\PermitType::value('id');
    // The fee is incurred BY a filing — the column is NOT NULL — so it has to
    // hang off one of this business's own applications.
    $applicationId = \App\Models\Application::where('business_id', $businessId)->value('id')
        ?? \App\Models\Application::value('id');

    foreach ($amounts as $amount) {
        UnbilledPermitFee::create([
            'business_id' => $businessId,
            'application_id' => $applicationId,
            'permit_type_id' => $permitTypeId,
            'amount' => $amount,
            'incurred_at' => now()->subDays(10),
            'description' => 'Roster sort fixture',
        ]);
    }
}

it('orders by business name, both ways, and means it', function () {
    $up = collect(roster(['sort' => 'name', 'dir' => 'asc']))->pluck('name')->all();
    $down = collect(roster(['sort' => 'name', 'dir' => 'desc']))->pluck('name')->all();

    $sorted = $up;
    usort($sorted, fn ($a, $b) => strcasecmp($a, $b));

    expect($up)->toBe($sorted)
        ->and($down)->toBe(array_reverse($sorted));
});

it('orders by what is owed, largest first', function () {
    $ids = Business::orderBy('id')->limit(3)->pluck('id');
    oweMoney($ids[0], [500.00]);
    oweMoney($ids[1], [1200.50, 300.00]);

    $rows = roster(['sort' => 'fees']);
    $totals = collect($rows)->map(fn ($r) => $r['unbilled_fees']['total'] ?? 0.0)->all();

    $descending = $totals;
    rsort($descending);

    expect($totals)->toBe($descending);
    // And it is not a list of zeroes, which would make the assertion vacuous.
    expect(max($totals))->toBeGreaterThan(0.0);
});

it('orders by owner name without multiplying the rows', function () {
    /*
     * The cross-table sorts are correlated subqueries, not joins. A join onto
     * `unbilled_permit_fees` would give a business with three unpaid fees three
     * rows of a twenty-row page — and the paginator would count them.
     */
    $plain = collect(roster())->pluck('id');
    $sorted = collect(roster(['sort' => 'owner']))->pluck('id');

    expect($sorted)->toHaveCount($plain->count())
        ->and($sorted->unique())->toHaveCount($sorted->count());

    $names = collect(roster(['sort' => 'owner']))->map(fn ($r) => $r['owner']['name'] ?? '')->all();
    $expected = $names;
    usort($expected, fn ($a, $b) => strcasecmp($a, $b));
    expect($names)->toBe($expected);
});

it('refuses a sort it does not recognise', function () {
    // The whole point of the whitelist. A column name from the query string
    // must never reach the planner.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/businesses?sort=password')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/businesses?sort=name&dir=;drop')
        ->assertStatus(422)
        ->assertJsonValidationErrors('dir');
});

it('still comes back newest first when nothing is asked', function () {
    // The order this list has always had. Adding sorts must not quietly
    // reorder every screen that never asked for one.
    $dates = collect(roster())->pluck('created_at')->all();
    $descending = $dates;
    rsort($descending);

    expect($dates)->toBe($descending);
});

it('narrows to the businesses caught by their owner’s blacklisting', function () {
    $owner = User::create([
        'name' => 'Barred Roster Owner',
        'first_name' => 'Barred',
        'last_name' => 'Owner',
        'gender' => 'M',
        'email' => 'barred.roster@example.com',
        'mobile_number' => '09171239999',
        'password' => 'Biztrack-Test1!',
        'is_active' => true,
        'data_privacy_consent_at' => now(),
        'email_verified_at' => now(),
    ]);
    $owner->roles()->sync(\App\Models\Role::where('name', 'business_owner')->pluck('id'));

    foreach (['Barred One', 'Barred Two'] as $name) {
        Business::create([
            'owner_user_id' => $owner->id,
            'name' => $name,
            'registration_type' => 'sole',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/businesses/'.$owner->businesses()->first()->id.'/status', [
            'status' => 'blacklisted',
            'reason' => 'Roster filter test.',
        ])->assertOk();

    $barred = collect(roster(['owner_blacklisted' => 1]));
    $clear = collect(roster(['owner_blacklisted' => 0]));

    expect($barred->pluck('name'))->toContain('Barred One', 'Barred Two');
    foreach ($barred as $row) {
        expect($row['owner']['blacklisted'])->toBeTrue();
    }
    foreach ($clear as $row) {
        expect($row['owner']['blacklisted'] ?? false)->toBeFalse();
    }
    // The two sets together are the whole roster, with nothing counted twice.
    expect($barred->count() + $clear->count())->toBe(collect(roster())->count());
});

it('narrows to what is owed, and to what is clear', function () {
    oweMoney(Business::orderBy('id')->value('id'), [750.00]);

    $owing = collect(roster(['fees' => 'owing']));
    $clear = collect(roster(['fees' => 'clear']));

    expect($owing)->not->toBeEmpty();
    foreach ($owing as $row) {
        expect($row['unbilled_fees']['total'])->toBeGreaterThan(0.0);
    }
    foreach ($clear as $row) {
        // Loosely: the API rounds the sum, and JSON-encodes a rounded zero as
        // the integer 0 rather than 0.0. The fact under test is "nothing
        // owed", not the encoder's choice of type.
        expect((float) $row['unbilled_fees']['total'])->toEqual(0.0);
    }
    expect($owing->count() + $clear->count())->toBe(collect(roster())->count());
});

it('separates a registration with no paperwork behind it', function () {
    $never = Business::create([
        'owner_user_id' => User::where('email', 'owner@biztrack.local')->value('id'),
        'name' => 'Registered But Never Filed',
        'registration_type' => 'sole',
        'status' => 'active',
        'is_active' => true,
    ]);

    $unfiled = collect(roster(['filed' => 'never']));
    $filed = collect(roster(['filed' => 'yes']));

    expect($unfiled->pluck('id'))->toContain($never->id);
    foreach ($unfiled as $row) {
        expect($row['applications_count'])->toBe(0);
    }
    foreach ($filed as $row) {
        expect($row['applications_count'])->toBeGreaterThan(0);
    }
});

it('narrows to a span of registration dates, inclusive at both ends', function () {
    /*
     * The inclusive end is the point. `created_at <= '2026-09-27'` excludes
     * everything registered ON the 27th, because midnight is the earliest
     * moment of that day and every row in it is later — so a reader asking for
     * "up to today" gets nothing from today, silently.
     */
    $business = Business::orderBy('id')->firstOrFail();
    $business->forceFill(['created_at' => '2026-03-15 14:30:00'])->save();

    $onTheDay = collect(roster([
        'registered_from' => '2026-03-15',
        'registered_to' => '2026-03-15',
    ]));

    expect($onTheDay->pluck('id'))->toContain($business->id);

    // And a span that ends the day before does not.
    $before = collect(roster([
        'registered_from' => '2026-03-01',
        'registered_to' => '2026-03-14',
    ]));

    expect($before->pluck('id'))->not->toContain($business->id);
});

it('refuses a range that ends before it starts', function () {
    // Not a filter that returns nothing — a question that cannot be answered,
    // said as such, so the reader fixes the dates rather than the register.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/businesses?registered_from=2026-06-01&registered_to=2026-01-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('registered_to');
});

it('applies a filter and a sort together rather than one of the two', function () {
    // The combination is the ordinary case — "who owes the most, of the ones
    // still trading" — and it is where a naive implementation drops one.
    $rows = roster(['status' => 'active', 'sort' => 'fees', 'dir' => 'desc']);

    $totals = collect($rows)->map(fn ($r) => $r['unbilled_fees']['total'] ?? 0.0)->all();
    $descending = $totals;
    rsort($descending);

    expect($totals)->toBe($descending);
    foreach ($rows as $row) {
        expect($row['status'])->toBe('active');
    }
});

it('does not repeat a row across pages when sorting by a subquery', function () {
    /*
     * Two businesses owing nothing tie on the sort key, and without a stable
     * tiebreak SQLite is free to order them differently on each query — so a
     * row on page one comes back again on page two and another is never seen
     * at all. `id` is the tiebreak; this is what proves it is doing its job.
     */
    $seen = collect();
    for ($page = 1; $page <= 3; $page++) {
        $seen = $seen->merge(
            collect(roster(['sort' => 'fees', 'per_page' => 10, 'page' => $page]))->pluck('id')
        );
    }

    expect($seen->unique())->toHaveCount($seen->count());
});

it('keeps a fee-sorted page the right size, despite the several fees a row may hold', function () {
    // A business with three unpaid fees must be one row, not three. A join
    // would have made it three and the paginator would have counted them.
    $business = Business::orderBy('id')->firstOrFail();
    oweMoney($business->id, [100.00, 200.00, 300.00]);

    expect(UnbilledPermitFee::where('business_id', $business->id)->count())->toBe(3);

    $rows = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/businesses?sort=fees&per_page=10')
        ->assertOk();

    /*
     * Against the whole roster, not against a hard 10: the seeded register
     * holds fewer than a page. What must hold is that the business owing three
     * fees appears ONCE — a join would have given it three of the page's rows,
     * and the paginator would have counted them.
     */
    $rows_ = collect($rows->json('data'));
    expect($rows_)->toHaveCount(min(10, Business::count()));
    expect($rows_->pluck('id')->unique())->toHaveCount($rows_->count());
    expect($rows_->where('id', $business->id))->toHaveCount(1);
});

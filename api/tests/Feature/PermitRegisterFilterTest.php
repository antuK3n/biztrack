<?php

use App\Enums\PermitStatus;
use App\Models\Permit;
use App\Models\User;

/*
 * `q` and `status` on GET /permits — issue #103's admin permit table.
 *
 * The register holds thousands of issued certificates, so the table that lists
 * them is unusable without a search. The risk a search adds is that it becomes
 * a second, wider way in: every assertion below is really one question — does
 * narrowing the set ever widen who may see it?
 *
 * PermitOfficeScopingTest already proves the unfiltered list, the record and
 * the PDF all hold the office boundary. This file proves the query string
 * cannot reach around it.
 */

it('finds a permit by its number, and only that permit', function () {
    $permit = Permit::firstOrFail();
    $admin = authAs('admin@biztrack.local');

    $rows = test()->withHeaders($admin)
        ->getJson('/api/v1/permits?q='.$permit->permit_number)
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('permit_number')->all())->toContain($permit->permit_number);
    // Permit numbers are unique and the match is a LIKE, so a longer number
    // could in principle contain a shorter one. The assertion that matters is
    // that the search narrowed at all.
    expect(count($rows))->toBeLessThan(Permit::count());
});

it('finds a permit by the business name and by the filing tracking ID', function () {
    $permit = Permit::query()
        ->whereHas('business')
        ->whereHas('application')
        ->with(['business', 'application'])
        ->firstOrFail();
    $admin = authAs('admin@biztrack.local');

    foreach ([$permit->business->name, $permit->application->tracking_id] as $term) {
        $ids = collect(
            test()->withHeaders($admin)
                ->getJson('/api/v1/permits?per_page=200&q='.urlencode($term))
                ->assertOk()
                ->json('data')
        )->pluck('id');

        // One value only: Pest's `toContain` reads every argument as another
        // value that must be present, so a message passed here is asserted as
        // a row of the result set.
        expect($ids->all())->toContain($permit->id);
    }
});

it('returns only permits in the status asked for', function () {
    $admin = authAs('admin@biztrack.local');

    $response = test()->withHeaders($admin)
        ->getJson('/api/v1/permits?per_page=200&status=active')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('status')->unique()->all())->toBe(['active']);
    expect($response->json('meta.total'))
        ->toBe(Permit::where('status', PermitStatus::Active)->count());
});

it('refuses a status that is not a permit status', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/permits?status=approved')
        ->assertStatus(422);
});

/*
 * The one that would be a leak.
 *
 * A search is applied INSIDE the reader's scope, never instead of it. If the
 * filters were applied to a fresh query — or before scopeToReader — an office
 * reviewer could type another office's permit number and be handed the row.
 */
it('will not let a search reach a permit the office may not read', function () {
    $officer = User::where('email', 'cenro@biztrack.local')->firstOrFail();

    $permit = Permit::whereHas('application', fn ($a) => $a
        ->whereDoesntHave('assignments', fn ($x) => $x->where('department_id', $officer->department_id))
        ->where('applicant_user_id', '!=', $officer->id))
        ->firstOrFail();

    $rows = test()->withHeaders(authAs('cenro@biztrack.local'))
        ->getJson('/api/v1/permits?q='.$permit->permit_number)
        ->assertOk()
        ->json('data');

    expect(collect($rows)->pluck('id'))->not->toContain($permit->id);
});

it('will not let a status filter widen an owner past their own permits', function () {
    $ownerId = User::where('email', 'owner@biztrack.local')->value('id');
    $headers = authAs('owner@biztrack.local');

    $ids = collect(
        test()->withHeaders($headers)
            ->getJson('/api/v1/permits?per_page=200&status=active')
            ->assertOk()
            ->json('data')
    )->pluck('id');

    $mine = Permit::where('status', PermitStatus::Active)
        ->whereHas('business', fn ($b) => $b->where('owner_user_id', $ownerId))
        ->pluck('id');

    expect($ids->sort()->values()->all())->toBe($mine->sort()->values()->all());
});

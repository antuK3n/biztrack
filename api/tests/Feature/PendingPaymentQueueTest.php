<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\PermitType;

/*
 * The Pending Payment stage of the officer queue.
 *
 * The client: "unpaid applications are still not reflected in the tracking of
 * applications (in the admin side)". Eight filings sat in `pending_payment` and
 * the Application Verification screen had nowhere to put any of them.
 *
 * The cause is structural, not a missing status. Both existing tabs read
 * `/assignments`, and an unpaid filing has no assignment: WorkflowService::submit
 * takes a draft through `submitted` to `pending_payment`, and the only caller of
 * routeToDepartments is `onPaymentCompleted`. Routing is deliberately withheld
 * until the money lands, because `assigned_at` starts the service-time clock
 * ProcessingTimeAnalytics and StaffingSimulation measure and it must not start
 * inside somebody's unfinished draft. So the queue's status filter already named
 * `pending_payment` and could never have matched a row.
 *
 * These tests hold both halves of that: the absence on the assignment feed is
 * real and expected, and `/applications` is the feed that can answer for the
 * stage — with an exact server-side total, a server-side search, and the office
 * boundary of ApplicationVisibility still closed.
 */

/** A freshly filed application, sitting unpaid exactly as the wizard leaves it. */
function unpaidFiling(): Application
{
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $businessPermit = PermitType::where('code', 'BUSINESS')->firstOrFail();

    $draft = test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPermit->id],
        ])
        ->assertCreated()
        ->json('data');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$draft['id']}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', ApplicationStatus::PendingPayment->value);

    return Application::findOrFail($draft['id']);
}

it('leaves a submitted-but-unpaid filing with no assignment at all', function () {
    $filing = unpaidFiling();

    /*
     * The premise of everything below. If this ever starts failing because
     * submit began routing, the Pending Payment tab can go back to the
     * assignment feed — and if it fails because routing moved but the tab did
     * not, the tab will be showing a stage the register no longer has.
     */
    expect($filing->assignments()->count())->toBe(0);

    $admin = authAs('admin@biztrack.local');

    // Not on the assignment feed under its own status, and not under any status:
    // there is no row to filter, so no filter could have produced one.
    foreach (['pending_payment', 'submitted,pending_payment', ''] as $filter) {
        $rows = test()->withHeaders($admin)
            ->getJson('/api/v1/assignments?per_page=200&application_status='.$filter)
            ->assertOk()
            ->json('data');

        $ids = array_column(array_column($rows, 'application'), 'id');
        expect($ids)->not->toContain($filing->id, "assignment feed surfaced it for '{$filter}'");
    }
});

it('shows an unpaid filing to the admin queue, with an exact server-side total', function () {
    $filing = unpaidFiling();
    $admin = authAs('admin@biztrack.local');

    $body = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=submitted,pending_payment&per_page=200')
        ->assertOk()
        ->json();

    expect(array_column($body['data'], 'id'))->toContain($filing->id);

    /*
     * The total is the stage's, counted in SQL — not the length of the page the
     * browser happens to be holding. The queue prints this number beside the
     * rows, and a total assembled from a page is the failure the two older tabs
     * were built to avoid (AssignmentController::index).
     */
    $stage = Application::whereIn('status', [
        ApplicationStatus::Submitted, ApplicationStatus::PendingPayment,
    ])->count();

    expect($body['meta']['total'])->toBe($stage)
        ->and($body['meta']['total'])->toBeGreaterThanOrEqual(1);

    // Every row really is pre-payment: the tab cannot quietly widen into review
    // work the way a browser-side split would.
    foreach ($body['data'] as $row) {
        expect($row['status'])->toBeIn(['submitted', 'pending_payment']);
    }

    // And a page of one still reports the whole stage, which is the property the
    // "Showing 1 of 9" line on the screen depends on.
    $firstPage = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=submitted,pending_payment&per_page=1')
        ->assertOk()
        ->json('meta');

    expect($firstPage['total'])->toBe($stage)
        ->and($firstPage['per_page'])->toBe(1);
});

it('never lets the stage filter include a draft', function () {
    $admin = authAs('admin@biztrack.local');

    // An unfiled draft is not an officer's work, and the register holds plenty
    // of them — 37 on the live database. The stage is asked for by name, so a
    // draft can only arrive here if somebody widens the list.
    $rows = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=submitted,pending_payment&per_page=200')
        ->assertOk()
        ->json('data');

    foreach ($rows as $row) {
        expect($row['status'])->not->toBe('draft');
    }
});

it('finds an unpaid filing by business name on the server, not in the page', function () {
    $filing = unpaidFiling();
    $admin = authAs('admin@biztrack.local');

    /*
     * The screenshot said "Showing 0 of the 13 loaded" while searching a
     * business the register plainly holds. A search that only reads the page in
     * hand tells an officer a filing does not exist; `q` is two LIKEs in SQL and
     * answers over the whole scoped set, so a one-row page still finds it.
     */
    $body = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=submitted,pending_payment&per_page=1&q=Sari-Sari')
        ->assertOk()
        ->json();

    expect(array_column($body['data'], 'id'))->toContain($filing->id)
        ->and($body['meta']['total'])->toBe(count($body['data']));

    // The term narrows the total as well as the rows — otherwise the count
    // beside a search would describe a different list from the one on screen.
    $unsearched = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=submitted,pending_payment&per_page=1')
        ->assertOk()
        ->json('meta.total');

    expect($body['meta']['total'])->toBeLessThanOrEqual($unsearched);
});

it('keeps the office boundary closed over a filing no office has been routed', function () {
    $filing = unpaidFiling();

    /*
     * ApplicationVisibility scopes an office reviewer to the filings it holds an
     * assignment on. An unpaid filing has none, so a sanitary officer sees
     * nothing here — the boundary failing closed, and the right answer: until
     * the fees are settled the filing has not been routed anywhere, so there is
     * no office whose remit it is in. Widening this by reading the requested
     * permit types would hand every office a filing it has not been given, which
     * is the leak items 56 and 111 closed.
     */
    $rows = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson('/api/v1/applications?status=submitted,pending_payment&per_page=200')
        ->assertOk()
        ->json('data');

    expect(array_column($rows, 'id'))->not->toContain($filing->id);

    // BPLO is who the stage belongs to when the filing belongs to nobody: it
    // issues the Tax Order of Payment and is the one office role seeded with
    // `application.view_any_office` (RbacSeeder).
    $bplo = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/applications?status=submitted,pending_payment&per_page=200')
        ->assertOk()
        ->json('data');

    expect(array_column($bplo, 'id'))->toContain($filing->id);
});

it('reads a misspelt status as nothing, not as the whole register', function () {
    unpaidFiling();
    $admin = authAs('admin@biztrack.local');

    /*
     * The comma-split is deliberately stricter than the identical-looking filter
     * on AssignmentController, which drops unknown values and falls back to an
     * unfiltered queue. That feed is already narrowed to one office; this one is
     * every filing in the city, and a typo that widens it to all of them is a
     * leak wearing a filter's clothes.
     */
    $bogus = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=pending_paymnet')
        ->assertOk()
        ->json('meta.total');

    expect($bogus)->toBe(0);

    // A known status beside an unknown one still answers for the known one, so
    // one stale name in the tab config narrows the stage rather than emptying it.
    $mixed = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=pending_payment,not_a_status')
        ->assertOk()
        ->json('meta.total');

    expect($mixed)->toBe(Application::where('status', ApplicationStatus::PendingPayment)->count())
        ->and($mixed)->toBeGreaterThan(0);
});

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
 * The cause was structural, and the structure changed on 6 September 2026.
 * It used to be that an unpaid filing had NO assignment at all: routing happened
 * on payment, so no filter on the assignment feed could ever have surfaced one.
 * `submit()` now routes to BPLO immediately, so a filing awaiting payment does
 * have an assignment — BPLO's, already `completed`, because BPLO approving the
 * form is what raised the bill.
 *
 * The conclusion survives the premise, for a better reason. This stage is
 * waiting on the APPLICANT, not on an office: nothing about it is open in
 * anybody's queue, so there is no office feed it belongs on. `/applications` is
 * the feed that can answer for it — with an exact server-side total, a
 * server-side search, and the office boundary of ApplicationVisibility still
 * closed.
 *
 * The stage is `pending_payment` ALONE. `submitted` used to ride along here and
 * no longer exists; the status that replaced it, `for_approval`, is a real BPLO
 * review stage with its own tab and its own open assignment, so folding it in
 * would put work an office owes into the tab for work the applicant owes.
 * `QueuePage.tsx` names the same single status, deliberately.
 *
 * WHO CAN ACTUALLY REACH THIS TAB, WHICH IS NO LONGER WHAT IT WAS
 * ---------------------------------------------------------------
 * The tab was built for "BPLO + the super admin", and it is gated on
 * `application.view_any_office` — which those two hold and no other office
 * does. The API half of that is unchanged and is still asserted below: the
 * super admin reads the stage from `/applications` perfectly well.
 *
 * The SCREEN half is not. Pending Payment is a tab on QueuePage, which lives at
 * `/staff/queue` behind `RequirePermission permission="application.review"` —
 * and `application.review` is one of the four the client took off the super
 * admin ("remove Messages, Track, Inspections, and Other Requirements"). So the
 * super admin can no longer open the screen the tab is on, and in practice the
 * stage is BPLO's alone.
 *
 * Left as it is on purpose. Nothing is broken — no 500, no leak, and the office
 * that issues the Tax Order of Payment is the office chasing it — but it is a
 * narrowing that fell out of an unrelated request rather than one anybody asked
 * for, and whether the super admin should get another route to the stage is a
 * product decision. Recorded here so it is found deliberately rather than
 * rediscovered as a bug report.
 */

/**
 * A filing sitting on the bill, exactly as BPLO's approval leaves it.
 *
 * Two calls now, where the wizard used to reach this in one. Submission lands
 * on `for_approval`; it is BPLO accepting the form that raises the Tax Order of
 * Payment and puts the filing here, and `ApplicationStatus::isBillable()`
 * refuses money before that. Driving only the first half would leave every
 * assertion below describing BPLO's review tab rather than this one.
 */
function unpaidFiling(): Application
{
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $businessPermit = PermitType::where('code', 'BUSINESS')->firstOrFail();

    $draft = test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPermit->id],
        ])
        ->assertCreated()
        ->json('data');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$draft['id']}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', ApplicationStatus::ForApproval->value);

    return bploApprovesForm($draft['id']);
}

it('leaves a filing awaiting payment out of every office’s open queue', function () {
    $filing = unpaidFiling();

    expect($filing->status)->toBe(ApplicationStatus::PendingPayment);

    /*
     * The premise of everything below, and it is no longer "no assignment at
     * all". Submission routes BPLO, so there is exactly one — BPLO's — and
     * `approveMainForm()` completed it on the way in. What is true is that
     * nothing is OPEN: the ball is with the applicant, and no office is owed
     * anything until the money lands.
     *
     * If this ever starts failing because an assignment is open again, the
     * Pending Payment tab can go back to the assignment feed — and if it fails
     * because routing moved but the tab did not, the tab will be showing a stage
     * the register no longer has.
     */
    expect($filing->assignments()->count())->toBe(1)
        ->and($filing->assignments()->where('status', 'completed')->count())->toBe(1);

    /*
     * The assignment feed is read as BPLO, not as the super admin. It is
     * `application.review`, which came off the super admin with the Track rail
     * entry the client asked to remove, and BPLO is the reader that sees every
     * office's assignments anyway (`application.view_any_office`) — so this
     * still asks the strongest available version of the question: the filing is
     * absent from the whole open queue, not merely from one office's slice.
     *
     * Note the tab itself is unaffected. Pending Payment reads `/applications`,
     * which the super admin keeps.
     */
    $bplo = authAs('bplo@biztrack.local');

    foreach (['pending', 'in_progress', 'returned', 'pending,in_progress,returned'] as $filter) {
        $rows = test()->withHeaders($bplo)
            ->getJson('/api/v1/assignments?per_page=200&status='.$filter)
            ->assertOk()
            ->json('data');

        $ids = array_column(array_column($rows, 'application'), 'id');
        expect($ids)->not->toContain($filing->id, "open assignment feed surfaced it for '{$filter}'");
    }
});

// "Admin queue" here means the endpoint, not the screen — see the note at the
// top of this file about the super admin no longer reaching /staff/queue.
it('shows an unpaid filing to the admin queue, with an exact server-side total', function () {
    $filing = unpaidFiling();
    $admin = authAs('admin@biztrack.local');

    $body = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=pending_payment&per_page=200')
        ->assertOk()
        ->json();

    expect(array_column($body['data'], 'id'))->toContain($filing->id);

    /*
     * The total is the stage's, counted in SQL — not the length of the page the
     * browser happens to be holding. The queue prints this number beside the
     * rows, and a total assembled from a page is the failure the two older tabs
     * were built to avoid (AssignmentController::index).
     */
    $stage = Application::where('status', ApplicationStatus::PendingPayment)->count();

    expect($body['meta']['total'])->toBe($stage)
        ->and($body['meta']['total'])->toBeGreaterThanOrEqual(1);

    // Every row really is on the bill: the tab cannot quietly widen into BPLO's
    // review work the way a browser-side split would.
    foreach ($body['data'] as $row) {
        expect($row['status'])->toBe('pending_payment');
    }

    // And a page of one still reports the whole stage, which is the property the
    // "Showing 1 of 9" line on the screen depends on.
    $firstPage = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=pending_payment&per_page=1')
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
        ->getJson('/api/v1/applications?status=pending_payment&per_page=200')
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
        ->getJson('/api/v1/applications?status=pending_payment&per_page=1&q=Sari-Sari')
        ->assertOk()
        ->json();

    expect(array_column($body['data'], 'id'))->toContain($filing->id)
        ->and($body['meta']['total'])->toBe(count($body['data']));

    // The term narrows the total as well as the rows — otherwise the count
    // beside a search would describe a different list from the one on screen.
    $unsearched = test()->withHeaders($admin)
        ->getJson('/api/v1/applications?status=pending_payment&per_page=1')
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
        ->getJson('/api/v1/applications?status=pending_payment&per_page=200')
        ->assertOk()
        ->json('data');

    expect(array_column($rows, 'id'))->not->toContain($filing->id);

    // BPLO is who the stage belongs to when the filing belongs to nobody: it
    // issues the Tax Order of Payment and is the one office role seeded with
    // `application.view_any_office` (RbacSeeder).
    $bplo = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/applications?status=pending_payment&per_page=200')
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

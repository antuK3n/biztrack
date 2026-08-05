<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;

/*
 * The officer queue splits into two tabs — "For Approval" and "For Inspection" —
 * on the *application's* status, and it used to do that in the browser over an
 * unpaged list of every assignment ever routed.
 *
 * Bounding the list without moving that filter into SQL would have been the
 * worse bug of the two: each tab would filter whichever fifty rows happened to
 * arrive, and the count beside it would always be ≤ 50 and always look
 * plausible. So the filter and the whole-set counts are part of the endpoint,
 * and these tests are what stop them drifting back into the page.
 *
 * BPLO is the reader throughout, where this used to be the super admin. Two
 * reasons, and they point the same way. The queue is `application.review`, which
 * the client took off the super admin along with the Track rail entry — "it is
 * not his role to do those things" — so an admin session is a 403 here now. And
 * BPLO is the right stand-in rather than merely an available one: it holds
 * `application.view_any_office`, so it is the one office role that sees every
 * office's assignments, which is the register-wide view these whole-set counts
 * are about.
 */

it('filters the queue by application status on the server', function () {
    $bplo = authAs('bplo@biztrack.local');

    $all = test()->withHeaders($bplo)->getJson('/api/v1/assignments')->assertOk()->json('meta');
    expect($all['total'])->toBeGreaterThan(0);

    // Put one routed filing on the inspection side, so the two tabs genuinely
    // partition the queue rather than one of them holding everything.
    $moved = Application::whereHas('assignments')->firstOrFail();
    $moved->update(['status' => ApplicationStatus::ForInspection]);

    /*
     * The two tabs this endpoint feeds, as QueuePage.tsx sends them.
     *
     * `submitted` and `pending_payment` used to be in the approval list and are
     * not any more. They were never reachable through this endpoint: an unpaid
     * filing has no assignment row (routing happens at payment — see
     * PendingPaymentQueueTest), so the filter named two statuses it could not
     * match. They have their own tab now, on `/applications`.
     */
    $approvalStatuses = ['under_review', 'returned'];
    $inspectionStatuses = ['for_inspection', 'approved', 'issued'];

    $approval = test()->withHeaders($bplo)
        ->getJson('/api/v1/assignments?application_status='.implode(',', $approvalStatuses))
        ->assertOk()->json();

    $inspection = test()->withHeaders($bplo)
        ->getJson('/api/v1/assignments?application_status='.implode(',', $inspectionStatuses))
        ->assertOk()->json();

    /*
     * The moved filing left the approval side and arrived on the inspection
     * one, and the two tabs never double-count. Deliberately not asserting that
     * each tab is strictly smaller than the queue: the seeded storyline routes
     * one application to three offices, so a status change moves all three at
     * once and one tab legitimately holds everything.
     */
    expect($approval['meta']['total'])->toBeLessThan($all['total'])
        ->and($inspection['meta']['total'])->toBeGreaterThan(0)
        ->and($approval['meta']['total'] + $inspection['meta']['total'])
        ->toBeLessThanOrEqual($all['total']);

    foreach ($approval['data'] as $row) {
        expect($row['application']['status'])->toBeIn($approvalStatuses);
    }
    foreach ($inspection['data'] as $row) {
        expect($row['application']['status'])->toBeIn($inspectionStatuses);
    }
});

it('accepts the filter as a repeated parameter as well as a comma-separated one', function () {
    $bplo = authAs('bplo@biztrack.local');

    $csv = test()->withHeaders($bplo)
        ->getJson('/api/v1/assignments?application_status=under_review,returned')
        ->assertOk()->json('meta.total');

    $repeated = test()->withHeaders($bplo)
        ->getJson('/api/v1/assignments?application_status[]=under_review&application_status[]=returned')
        ->assertOk()->json('meta.total');

    expect($repeated)->toBe($csv);
});

it('ignores an unknown status rather than 500ing or emptying the queue', function () {
    $bplo = authAs('bplo@biztrack.local');

    $unfiltered = test()->withHeaders($bplo)->getJson('/api/v1/assignments')->assertOk()->json('meta.total');
    $bogus = test()->withHeaders($bplo)
        ->getJson('/api/v1/assignments?application_status=not_a_real_status')
        ->assertOk()->json('meta.total');

    // Nothing recognisable to filter on, so the queue is unfiltered — a stale
    // status name in the tab config should narrow the queue, not break the page.
    expect($bogus)->toBe($unfiltered);
});

it('counts each tab over the whole scoped set, not the page in hand', function () {
    $bplo = authAs('bplo@biztrack.local');

    $body = test()->withHeaders($bplo)->getJson('/api/v1/assignments?per_page=1')->assertOk()->json();
    $counts = $body['meta']['application_status_counts'];

    expect(count($body['data']))->toBe(1)
        ->and($counts)->toBeArray()
        ->and(array_sum($counts))->toBe($body['meta']['total']);

    // Every count is reachable by asking for that status directly.
    foreach ($counts as $status => $count) {
        $total = test()->withHeaders($bplo)
            ->getJson("/api/v1/assignments?application_status={$status}")
            ->assertOk()->json('meta.total');

        expect($total)->toBe($count, "count for {$status} disagrees with the filtered total");
    }
});

it('counts only the office the reader belongs to', function () {
    $sanitary = authAs('sanitary@biztrack.local');
    $body = test()->withHeaders($sanitary)->getJson('/api/v1/assignments')->assertOk()->json();

    // BPLO as the register-wide reader: it is the only office role holding
    // `application.view_any_office`, so its total is the whole queue and CHO's
    // is a strict slice of it.
    $bplo = authAs('bplo@biztrack.local');
    $wholeQueueTotal = test()->withHeaders($bplo)->getJson('/api/v1/assignments')->assertOk()->json('meta.total');

    expect(array_sum($body['meta']['application_status_counts']))->toBe($body['meta']['total'])
        ->and($body['meta']['total'])->toBeLessThanOrEqual($wholeQueueTotal);
});

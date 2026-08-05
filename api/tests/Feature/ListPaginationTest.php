<?php

use App\Enums\InspectionStatus;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Inspection;
use App\Models\User;

/*
 * Every list endpoint is bounded, ordered, and honest about it.
 *
 * `/inspections` was the one that got noticed: 2,850 rows and 1.8 MB on a
 * single request, rendered as one list, and the browser went down. It answered
 * 200 throughout, which is why it survived so long. Twelve of the fourteen
 * lists had exactly that shape.
 *
 * These tests pin the three things that were wrong, because each of them can
 * regress independently:
 *
 *  1. the row count is bounded, and `per_page` cannot be argued out of it;
 *  2. `data` is still a plain array, so unwrapping callers keep working;
 *  3. the order puts the useful rows first — `/inspections` was ascending by
 *     `scheduled_at`, so page one opened on 2023.
 */

/**
 * Every paginated list, as [label, uri, account].
 *
 * The account is whoever is ALLOWED to read that list, not a convenience. Nine
 * of these used to say `admin@` because the super admin could open everything;
 * it no longer can. The client took four activities off the role — "In the
 * super admin's account (admin@), remove Messages, Track, Inspections, and
 * Other Requirements. It is not his role to do those things." — and the four
 * permissions behind them are exactly what gates four of these endpoints:
 *
 *   /assignments      application.review    -> BPLO
 *   /message-threads  message.participate   -> BPLO
 *   /requests         request.create        -> BPLO
 *   /inspections      inspection.manage     -> an inspecting office (CHO here)
 *
 * The admin keeps the rest because oversight is reading: `application.view_all`
 * plus `application.view_any_office` for /applications, `permit.view_all` for
 * /permits, and its own admin screens. Anything that reverts here would be a
 * 403, so this table doubles as a record of who owns each list.
 */
function paginatedLists(): array
{
    return [
        ['applications', '/api/v1/applications', 'admin@biztrack.local'],
        ['assignments', '/api/v1/assignments', 'bplo@biztrack.local'],
        ['permits', '/api/v1/permits', 'admin@biztrack.local'],
        ['inspections', '/api/v1/inspections', 'sanitary@biztrack.local'],
        ['requests', '/api/v1/requests', 'bplo@biztrack.local'],
        ['message-threads', '/api/v1/message-threads', 'bplo@biztrack.local'],
        ['admin/users', '/api/v1/admin/users', 'admin@biztrack.local'],
        ['admin/businesses', '/api/v1/admin/businesses', 'admin@biztrack.local'],
        ['admin/audit-logs', '/api/v1/admin/audit-logs', 'admin@biztrack.local'],
        ['notifications', '/api/v1/notifications', 'owner@biztrack.local'],
        ['businesses', '/api/v1/businesses', 'owner@biztrack.local'],
        ['payments', '/api/v1/payments', 'owner@biztrack.local'],
    ];
}

it('returns a plain data array plus page meta on every list endpoint', function () {
    foreach (paginatedLists() as [$label, $uri, $email]) {
        $body = test()->withHeaders(authAs($email))->getJson($uri)->assertOk()->json();

        expect($body['data'])->toBeArray("{$label}: data must stay a plain array")
            ->and($body['data'])->not->toHaveKey('current_page', "{$label}: data must not become a paginator object")
            ->and($body['meta'])->toHaveKeys(
                ['current_page', 'last_page', 'per_page', 'total'],
                "{$label}: page meta is missing",
            );
    }
});

it('never returns more than the ceiling however per_page is asked', function () {
    // 0 and -1 both become "no limit" in SQLite when passed straight through,
    // which is the full-table read the pagination exists to prevent.
    foreach (['0', '-1', '999999', '2147483647'] as $perPage) {
        foreach (paginatedLists() as [$label, $uri, $email]) {
            $body = test()->withHeaders(authAs($email))
                ->getJson("{$uri}?per_page={$perPage}")
                ->assertOk()
                ->json();

            expect(count($body['data']))->toBeLessThanOrEqual(
                200,
                "{$label}: per_page={$perPage} returned ".count($body['data']).' rows',
            )->and($body['meta']['per_page'])->toBeGreaterThanOrEqual(1);
        }
    }
});

it('bounds the audit trail, which was the one list already calling paginate()', function () {
    /*
     * `->paginate((int) $request->query('per_page', 25))` reads as bounded and
     * is not. On the live register `?per_page=999999` was obeyed and returned
     * 5.1 MB — every action every user has ever taken, on one GET.
     */
    foreach (range(1, 210) as $i) {
        AuditLog::create(['action' => "probe.{$i}", 'ip_address' => '127.0.0.1']);
    }

    $token = authAs('admin@biztrack.local');
    $rows = test()->withHeaders($token)->getJson('/api/v1/admin/audit-logs?per_page=999999')
        ->assertOk()->json('data');

    expect(count($rows))->toBeLessThanOrEqual(200);
});

it('splits pages without overlapping or dropping rows', function () {
    $token = authAs('admin@biztrack.local');

    $first = test()->withHeaders($token)->getJson('/api/v1/applications?per_page=5&page=1')->assertOk()->json();
    $second = test()->withHeaders($token)->getJson('/api/v1/applications?per_page=5&page=2')->assertOk()->json();

    $a = array_column($first['data'], 'id');
    $b = array_column($second['data'], 'id');

    expect(array_intersect($a, $b))->toBeEmpty('page 2 repeated rows from page 1')
        ->and($first['meta']['total'])->toBe($second['meta']['total'])
        ->and($first['meta']['per_page'])->toBe(5);
});

it('opens each list on the rows that are worth seeing first', function () {
    /*
     * Three accounts rather than one, for the reason set out on
     * paginatedLists(): the queue belongs to BPLO and the inspection list to an
     * inspecting office, and the super admin is on neither since the client took
     * Track and Inspections off that role.
     */
    $admin = authAs('admin@biztrack.local');

    // Applications: newest filing first.
    $created = array_column(
        test()->withHeaders($admin)->getJson('/api/v1/applications')->assertOk()->json('data'),
        'created_at',
    );
    $sorted = $created;
    rsort($sorted);
    expect($created)->toBe($sorted, 'applications are not newest-first');

    // Assignments: newest routing first — an officer wants the work that just
    // arrived, not the first thing the office was ever sent.
    $bplo = authAs('bplo@biztrack.local');
    $assigned = array_filter(array_column(
        test()->withHeaders($bplo)->getJson('/api/v1/assignments')->assertOk()->json('data'),
        'assigned_at',
    ));
    // An empty list would satisfy the sort trivially, which is how this test
    // would quietly stop testing anything if the account lost its scope.
    expect($assigned)->not->toBeEmpty('BPLO has no assignments to order');
    $sortedAssigned = $assigned;
    rsort($sortedAssigned);
    expect(array_values($assigned))->toBe(array_values($sortedAssigned), 'assignments are not newest-first');

    /*
     * Inspections: the visit just done or about to be, not one from 2023 — the
     * ordering bug that took the page down in the first place.
     *
     * The rows are made here because the demo storyline seeds none, so this
     * assertion had been reading an empty array and sorting it successfully
     * ever since it was written. Deliberately out of chronological order, and
     * deliberately spanning years, so that ascending-by-scheduled_at would put
     * 2023 first and fail rather than pass on an absence of data.
     */
    $sanitaryDepartmentId = Department::where('code', 'CHO')->value('id');
    $applicationId = Application::query()->value('id');
    foreach (['2023-02-14', '2026-05-06', '2024-11-20'] as $day) {
        Inspection::create([
            'application_id' => $applicationId,
            'department_id' => $sanitaryDepartmentId,
            'status' => InspectionStatus::Scheduled,
            'scheduled_at' => $day.' 09:00:00',
        ]);
    }

    $sanitary = authAs('sanitary@biztrack.local');
    $scheduled = array_filter(array_column(
        test()->withHeaders($sanitary)->getJson('/api/v1/inspections')->assertOk()->json('data'),
        'scheduled_at',
    ));
    expect($scheduled)->toHaveCount(3, 'CHO cannot see the visits booked to it');
    $sortedScheduled = $scheduled;
    rsort($sortedScheduled);
    expect(array_values($scheduled))->toBe(array_values($sortedScheduled), 'inspections are not newest-first');
});

it('counts unread notifications across every page, not just the one returned', function () {
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->notifications()->delete();

    // More unread than fit on a page: counting the loaded rows would report 3.
    foreach (range(1, 60) as $i) {
        AppNotification::create([
            'user_id' => $user->id,
            'type' => 'status_change',
            'title' => "Notice {$i}",
            'body' => 'Body',
            'link' => '/applications/1',
        ]);
    }

    $body = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/notifications?per_page=3')
        ->assertOk()->json();

    expect(count($body['data']))->toBe(3)
        ->and($body['meta']['total'])->toBe(60)
        ->and($body['meta']['unread'])->toBe(60);
});

it('rejects a page size that is not a number rather than silently unbounding it', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/applications?per_page=abc')
        ->assertStatus(422);
});

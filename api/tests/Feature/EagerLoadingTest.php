<?php

use App\Models\Application;
use App\Models\Department;
use App\Models\Inspection;
use Illuminate\Support\Facades\DB;

/*
 * Query counts that must not scale with row counts.
 *
 * The eager loads and the resources drifted apart. `InspectionResource` reads
 * its `application` through `whenLoaded('application')` in a ternary — and
 * whenLoaded with one argument returns a MissingValue *object* when the relation
 * is absent, which is truthy. So the "loaded" branch ran every time and lazily
 * fetched a row. Invisible on `/inspections`, where the relation really is eager
 * loaded; one query per inspection everywhere the resource is nested without it,
 * which is every application detail view and every officer review page.
 *
 * Asserting the shape of the eager-load array would test the fix. Counting
 * queries tests the bug.
 */

/** One more scheduled inspection on a filing. */
function seedInspection(int $applicationId, int $dayOffset = 0): Inspection
{
    return Inspection::create([
        'application_id' => $applicationId,
        'department_id' => Department::firstOrFail()->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays($dayOffset),
    ]);
}

/** Run a request and return how many queries it took. */
function queryCountFor(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('does not spend a query per inspection on the application detail view', function () {
    $application = Application::whereHas('assignments')->firstOrFail();
    // One inspection before the baseline: with none at all Laravel skips the
    // nested eager loads entirely, so the baseline would not be comparable.
    seedInspection($application->id);

    $baseline = queryCountFor(function () use ($application) {
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertOk();
    });

    // Ten more inspections on the same filing. With the resource lazy-loading,
    // this costs ten more queries; with the relation eager-loaded, none.
    foreach (range(1, 10) as $i) {
        seedInspection($application->id, $i);
    }

    $after = queryCountFor(function () use ($application) {
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson("/api/v1/applications/{$application->id}")
            ->assertOk();
    });

    expect($after)->toBeLessThanOrEqual(
        $baseline + 1,
        'ten more inspections cost '.($after - $baseline).' more queries — the relation is lazy-loading',
    );
});

it('does not spend a query per inspection on the officer review page', function () {
    $application = Application::whereHas('assignments')->firstOrFail();
    // One inspection before the baseline: with none at all Laravel skips the
    // nested eager loads entirely, so the baseline would not be comparable.
    seedInspection($application->id);
    $assignmentId = $application->assignments()->value('id');

    $baseline = queryCountFor(function () use ($assignmentId) {
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson("/api/v1/assignments/{$assignmentId}")
            ->assertOk();
    });

    foreach (range(1, 10) as $i) {
        seedInspection($application->id, $i);
    }

    $after = queryCountFor(function () use ($assignmentId) {
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson("/api/v1/assignments/{$assignmentId}")
            ->assertOk();
    });

    expect($after)->toBeLessThanOrEqual($baseline + 1);
});

it('keeps every list endpoint flat as the page grows', function () {
    $lists = [
        '/api/v1/applications',
        '/api/v1/assignments',
        '/api/v1/permits',
        '/api/v1/inspections',
        '/api/v1/requests',
        '/api/v1/message-threads',
        '/api/v1/admin/users',
        '/api/v1/admin/businesses',
        '/api/v1/admin/audit-logs',
    ];

    foreach ($lists as $uri) {
        $small = queryCountFor(function () use ($uri) {
            test()->withHeaders(authAs('admin@biztrack.local'))->getJson("{$uri}?per_page=1")->assertOk();
        });
        $large = queryCountFor(function () use ($uri) {
            test()->withHeaders(authAs('admin@biztrack.local'))->getJson("{$uri}?per_page=200")->assertOk();
        });

        expect($large)->toBeLessThanOrEqual(
            $small + 1,
            "{$uri}: {$small} queries at per_page=1, {$large} at per_page=200 — something is per-row",
        );
    }
});

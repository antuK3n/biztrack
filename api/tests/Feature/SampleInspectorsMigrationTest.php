<?php

use App\Models\Barangay;
use App\Models\Department;
use App\Models\Inspector;
use App\Models\PermitType;
use App\Support\InspectorSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Migration 2026_11_24_000100: sample inspectors in the pool of every office
 * that books a site visit [Rupert: "please populate the inspector pool with
 * dummy data"], each office covering all 21 barangays, without touching the
 * inspectors an office already keeps.
 */

function runSampleInspectorsMigration(): void
{
    $migration = require database_path('migrations/2026_11_24_000100_sample_inspectors_for_the_offices_that_inspect.php');
    $migration->up();
}

/** @return list<int> department ids, read off the permit types the way the booking does. */
function officesThatInspect(): array
{
    return PermitType::where('requires_inspection', true)
        ->pluck('issuing_department_id')->unique()->values()->all();
}

/** Every inspector and the barangays they cover, for comparing a pool before and after. */
function poolSnapshot(): array
{
    return Inspector::query()->orderBy('id')->get()->map(fn (Inspector $i) => [
        'row' => DB::table('inspectors')->where('id', $i->id)->first(),
        'barangays' => DB::table('inspector_barangay')->where('inspector_id', $i->id)->orderBy('barangay_id')->pluck('barangay_id')->all(),
    ])->all();
}

it('gives every office that inspects a pool covering every barangay, and BPLO none', function () {
    runSampleInspectorsMigration();

    $offices = officesThatInspect();
    $codes = Department::whereIn('id', $offices)->pluck('code')->sort()->values()->all();
    expect($codes)->toBe(['BFP', 'CENRO', 'CHO', 'CPDO', 'OBO']);

    $everyBarangay = Barangay::orderBy('id')->pluck('id')->all();
    expect($everyBarangay)->toHaveCount(21);

    foreach ($offices as $departmentId) {
        $pool = Inspector::where('department_id', $departmentId)->where('is_active', true)->with('barangays')->get();
        $code = Department::whereKey($departmentId)->value('code');

        expect($pool->count())->toBeGreaterThanOrEqual(2, "{$code} has too few inspectors");

        $covered = $pool->flatMap(fn (Inspector $i) => $i->barangays->pluck('id'))->unique()->sort()->values()->all();
        expect($covered)->toBe($everyBarangay, "{$code} leaves a barangay uncovered");

        // Saved in the shape the Inspectors screen saves and the API validates.
        foreach ($pool as $inspector) {
            expect($inspector->works_days)->toBeArray()
                ->and(array_intersect([1, 2, 3, 4, 5], $inspector->works_days))->toHaveCount(5)
                ->and(collect($inspector->works_days)->every(fn ($d) => is_int($d) && $d >= 1 && $d <= 7))->toBeTrue()
                ->and($inspector->max_visits_per_day)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(20)
                ->and($inspector->mobile_number)->toBeNull();
        }
    }

    expect(Inspector::where('department_id', Department::where('code', 'BPLO')->value('id'))->count())->toBe(0);
});

it('suggests an inspector who covers the barangay, for every office and every barangay', function () {
    runSampleInspectorsMigration();

    // A Wednesday ahead, so the day is a working day whatever today is.
    $day = CarbonImmutable::now(config('app.timezone'))->addWeek()->next(CarbonImmutable::WEDNESDAY)->setTime(10, 0);

    foreach (officesThatInspect() as $departmentId) {
        foreach (Barangay::pluck('name', 'id') as $barangayId => $name) {
            $answer = InspectorSuggestion::forVisit($departmentId, $barangayId, $day);

            expect($answer['suggested'])->not->toBeNull("nobody suggested for {$name}")
                ->and($answer['suggested']['covers_barangay'])->toBeTrue("the suggestion for {$name} does not cover it");
        }
    }
});

it('adds nothing the second time it runs', function () {
    runSampleInspectorsMigration();
    $inspectors = DB::table('inspectors')->count();
    $areas = DB::table('inspector_barangay')->count();
    expect($inspectors)->toBe(15);

    runSampleInspectorsMigration();

    expect(DB::table('inspectors')->count())->toBe($inspectors)
        ->and(DB::table('inspector_barangay')->count())->toBe($areas);
});

it('leaves the inspectors an office already keeps exactly as they were', function () {
    $cho = Department::where('code', 'CHO')->value('id');
    $cenro = Department::where('code', 'CENRO')->value('id');
    $ids = fn (array $names) => Barangay::whereIn('name', $names)->pluck('id');

    // Live's shape on 24 November 2026: two at CHO, one at CENRO, part of the city covered.
    $own = Inspector::create(['department_id' => $cho, 'name' => 'An office inspector', 'works_days' => [2, 4], 'max_visits_per_day' => 2]);
    $own->barangays()->sync($ids(['Acacia', 'Tonsuya']));
    $away = Inspector::create(['department_id' => $cho, 'name' => 'Another, deactivated', 'is_active' => false]);
    $away->barangays()->sync($ids(['Longos']));
    // Same name as a sample, different everything else: the office's row wins and is not doubled.
    $same = Inspector::create(['department_id' => $cenro, 'name' => 'Analyn P. Torralba', 'works_days' => [1], 'max_visits_per_day' => 1, 'mobile_number' => '09170000000']);
    $same->barangays()->sync($ids(['Muzon']));

    $before = poolSnapshot();

    runSampleInspectorsMigration();

    $after = collect(poolSnapshot())->keyBy(fn (array $i) => $i['row']->id);
    foreach ($before as $existing) {
        expect($after[$existing['row']->id])->toEqual($existing);
    }

    expect(Inspector::where('department_id', $cenro)->where('name', 'Analyn P. Torralba')->count())->toBe(1)
        ->and(Inspector::where('department_id', $cho)->count())->toBe(4)
        ->and(Inspector::where('department_id', $cenro)->count())->toBe(3);
});

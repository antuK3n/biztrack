<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Inspection;
use App\Models\Inspector;
use App\Models\PermitType;
use App\Support\BarangayAreas;
use App\Support\InspectorSuggestion;
use Carbon\CarbonImmutable;

/*
 * An office's pool of inspectors and the suggestion at booking [client, 27
 * October 2026]: who covers which barangays, who is free on a day, and who a
 * visit should go to.
 */

function cho(): Department
{
    return Department::where('code', 'CHO')->firstOrFail();
}

function poolInspector(string $name, array $barangayNames = [], array $attrs = []): Inspector
{
    $inspector = Inspector::create(['department_id' => cho()->id, 'name' => $name] + $attrs);
    $inspector->barangays()->sync(Barangay::whereIn('name', $barangayNames)->pluck('id'));

    return $inspector;
}

/** A Wednesday ahead, so every test is on a working day whatever today is. */
function nextWednesday(): CarbonImmutable
{
    return CarbonImmutable::now(config('app.timezone'))->addWeek()->next(CarbonImmutable::WEDNESDAY)->setTime(10, 0);
}

it('lets an office add, edit, divide and remove its own inspectors, and nobody else', function () {
    $h = authAs('sanitary@biztrack.local');
    $acacia = Barangay::where('name', 'Acacia')->value('id');

    $created = test()->withHeaders($h)->postJson('/api/v1/inspectors', [
        'name' => 'Rico Dela Peña', 'mobile_number' => '09170000011', 'max_visits_per_day' => 3,
        'works_days' => [1, 2, 3], 'barangay_ids' => [$acacia],
    ])->assertCreated()->json('data');

    expect($created['works_days'])->toBe([1, 2, 3])
        ->and(collect($created['barangays'])->pluck('name')->all())->toBe(['Acacia']);

    test()->withHeaders($h)->putJson("/api/v1/inspectors/{$created['id']}", ['name' => 'Rico D. Peña', 'max_visits_per_day' => 5])
        ->assertOk()->assertJsonPath('data.max_visits_per_day', 5);

    // Another office neither reads nor edits this pool.
    test()->withHeaders(authAs('fire@biztrack.local'))->getJson('/api/v1/inspectors')
        ->assertOk()->assertJsonPath('meta.department.code', 'BFP')->assertJsonCount(0, 'data');
    test()->withHeaders(authAs('fire@biztrack.local'))->putJson("/api/v1/inspectors/{$created['id']}", ['name' => 'X'])
        ->assertForbidden();

    // Neither the super admin nor BPLO holds `inspection.manage`: no pool for them.
    test()->withHeaders(authAs('admin@biztrack.local'))->getJson('/api/v1/inspectors')->assertForbidden();
    test()->withHeaders(authAs('bplo@biztrack.local'))->getJson('/api/v1/inspectors')->assertForbidden();

    // authAs() also signs the test client in as its last caller; back to the health office.
    test()->withHeaders(authAs('sanitary@biztrack.local'))->deleteJson("/api/v1/inspectors/{$created['id']}")->assertOk();
    expect(Inspector::count())->toBe(0);
});

it('divides every barangay among the active inspectors, neighbours together and evenly', function () {
    $a = poolInspector('A');
    $b = poolInspector('B');
    $c = poolInspector('C');
    poolInspector('Away', [], ['is_active' => false]);

    $areas = BarangayAreas::divide([$a->id, $b->id, $c->id]);
    $all = collect($areas)->flatten();

    expect($all->count())->toBe(Barangay::count())
        ->and($all->unique()->count())->toBe(Barangay::count())
        ->and(collect($areas)->map(fn ($ids) => count($ids))->max() - collect($areas)->map(fn ($ids) => count($ids))->min())->toBeLessThanOrEqual(1);

    // Each group is in one piece: every barangay is closer to one of its own than the groups' centres are apart.
    $centres = BarangayAreas::centres();
    foreach ($areas as $ids) {
        $c0 = [array_sum(array_map(fn ($i) => $centres[$i][0], $ids)) / count($ids), array_sum(array_map(fn ($i) => $centres[$i][1], $ids)) / count($ids)];
        $spread = max(array_map(fn ($i) => sqrt(BarangayAreas::dist2($c0, $centres[$i])), $ids));
        expect($spread)->toBeLessThan(3000.0);
    }

    $h = authAs('sanitary@biztrack.local');
    $proposal = test()->withHeaders($h)->postJson('/api/v1/inspectors/suggest-areas')->assertOk()->json('data');
    expect(collect($proposal)->pluck('name')->all())->toBe(['A', 'B', 'C']);

    test()->withHeaders($h)->postJson('/api/v1/inspectors/apply-areas', [
        'areas' => collect($proposal)->map(fn ($p) => ['id' => $p['id'], 'barangay_ids' => $p['barangay_ids']])->all(),
    ])->assertOk();
    expect($a->fresh()->barangays()->count() + $b->fresh()->barangays()->count() + $c->fresh()->barangays()->count())
        ->toBe(Barangay::count());
});

it('suggests the inspector who covers the barangay, is working, not away, and has room', function () {
    $acacia = Barangay::where('name', 'Acacia')->value('id');
    $day = nextWednesday();

    $covers = poolInspector('Covers It', ['Acacia', 'Tugatog']);
    $near = poolInspector('Nearby', ['Tinajeros']);
    $offDay = poolInspector('Off Wednesdays', ['Acacia'], ['works_days' => [1, 2, 4, 5]]);
    $away = poolInspector('On Leave', ['Acacia']);
    $away->leaves()->create(['starts_on' => $day->toDateString(), 'ends_on' => $day->addDay()->toDateString(), 'reason' => 'Training']);

    $answer = InspectorSuggestion::forVisit(cho()->id, $acacia, $day);
    expect($answer['suggested']['name'])->toBe('Covers It')
        ->and($answer['suggested']['why'])->toContain('Covers Acacia')
        ->and(collect($answer['candidates'])->firstWhere('name', 'Off Wednesdays')['available'])->toBeFalse()
        ->and(collect($answer['candidates'])->firstWhere('name', 'On Leave')['why'])->toContain('Training')
        ->and(collect($answer['candidates'])->firstWhere('name', 'Nearby')['why'])->toContain('Nearest area');

    // Full that day: the next in line takes it.
    $covers->update(['max_visits_per_day' => 1]);
    Inspection::create([
        'application_id' => \App\Models\Application::value('id'),
        'department_id' => cho()->id,
        'inspector_id' => $covers->id,
        'inspector_name' => $covers->name,
        'status' => 'scheduled',
        'scheduled_at' => $day->setTime(9, 0),
    ]);
    $answer = InspectorSuggestion::forVisit(cho()->id, $acacia, $day);
    expect($answer['suggested']['name'])->toBe('Nearby')
        ->and(collect($answer['candidates'])->firstWhere('name', 'Covers It')['why'])->toContain('Full that day');

    // Nobody free: the next working day somebody is.
    $near->update(['is_active' => false]);
    $offDay->update(['is_active' => false]);
    $answer = InspectorSuggestion::forVisit(cho()->id, $acacia, $day);
    expect($answer['suggested'])->toBeNull()
        ->and($answer['next_available']['date'])->toBe($day->addDay()->toDateString())
        ->and($answer['next_available']['inspector']['name'])->toBe('Covers It');
});

/** A filing whose Sanitary Permit is for inspection: the office approved the paperwork, nothing is booked. */
function filingForSanitaryInspection(): Application
{
    $owner = authAs('owner@biztrack.local');
    $appId = requirementFilingForInbox();
    attachRequiredDocuments($appId);
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/clearances/SANITARY/apply")->assertSuccessful();
    satisfyChecklist(Application::findOrFail($appId), PermitType::where('code', 'SANITARY')->firstOrFail());
    test()->withHeaders($owner)->putJson("/api/v1/applications/{$appId}/office-forms/SANITARY", [
        'form_data' => ['sanitary_classification' => 'Food Establishment'],
        'submit' => true,
    ])->assertSuccessful();
    $assignment = Application::findOrFail($appId)->assignments()->whereRelation('department', 'code', 'CHO')->firstOrFail();
    test()->withHeaders(authAs('sanitary@biztrack.local'))->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();

    return Application::findOrFail($appId);
}

it('books a visit to a pool inspector and writes the name beside the link', function () {
    $app = filingForSanitaryInspection();
    $appId = $app->id;
    $barangay = $app->business->address->barangay;
    $rico = poolInspector('Rico Dela Peña', [$barangay->name]);
    $other = Inspector::create(['department_id' => Department::where('code', 'BFP')->value('id'), 'name' => 'Fire Guy']);
    $h = authAs('sanitary@biztrack.local');

    $day = nextWednesday();
    $suggestion = test()->withHeaders($h)
        ->getJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection-suggestion?date=".$day->toDateString())
        ->assertOk()->json('data');
    expect($suggestion['suggested']['id'])->toBe($rico->id)
        ->and($suggestion['barangay']['name'])->toBe($barangay->name);

    // Another office's inspector is refused; the office's own is booked and named.
    test()->withHeaders($h)->postJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection", [
        'scheduled_at' => $day->toIso8601String(), 'inspector_id' => $other->id,
    ])->assertStatus(422);

    $visit = test()->withHeaders($h)->postJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection", [
        'scheduled_at' => $day->toIso8601String(), 'inspector_id' => $rico->id,
    ])->assertCreated()->json('data');
    expect($visit['inspector_id'])->toBe($rico->id)
        ->and($visit['inspector_name'])->toBe('Rico Dela Peña');

    // The booked visit now counts against Rico's day.
    $again = test()->withHeaders($h)
        ->getJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection-suggestion?date=".$day->toDateString())
        ->assertOk()->json('data');
    expect(collect($again['candidates'])->firstWhere('id', $rico->id)['booked_that_day'])->toBe(1);

    // Typing a different name by hand lets go of the pool link.
    test()->withHeaders($h)->patchJson("/api/v1/inspections/{$visit['id']}/inspector", ['inspector_name' => 'Somebody Else'])
        ->assertOk()->assertJsonPath('data.inspector_id', null)->assertJsonPath('data.inspector_name', 'Somebody Else');
});

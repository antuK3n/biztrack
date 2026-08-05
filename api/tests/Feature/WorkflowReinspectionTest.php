<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Inspection;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Support\Collection;

/**
 * Re-inspection after a failed visit.
 *
 * The failure this covers was not that a re-inspection went wrong — it was that
 * there was no such thing. `recordInspection()` returned early on a failure
 * under a comment saying the department may schedule a re-inspection, and
 * nothing in the system could: the filing stayed `for_inspection` with every
 * visit conducted, which is also the state in which the officer's screen hides
 * its controls. Six live filings were stranded there.
 *
 * So these tests are mostly about the two things that make the fix real rather
 * than plausible: the failed visit is still on the record afterwards, and the
 * passing re-inspection is actually able to approve the filing over it.
 */
$deptEmail = [
    'BPLO' => 'bplo@biztrack.local',
    'CHO' => 'sanitary@biztrack.local',
    'BFP' => 'fire@biztrack.local',
];

/**
 * Drive a filing to `for_inspection` with one visit per inspecting office.
 *
 * The long way round — business, application, submit, pay, three approvals —
 * rather than inserting rows, because the state under test is the one the
 * workflow builds, and a hand-built filing would not prove the scheduler had
 * been through it.
 *
 * @return array{0: int, 1: Collection<int, Inspection>}
 */
function filingAwaitingInspection(array $deptEmail, string $name): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Re-inspect Ave.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $typeIds = PermitType::whereIn('code', ['BUSINESS', 'SANITARY', 'FSIC'])->pluck('id')->all();
    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => $typeIds,
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    foreach (ApplicationAssignment::where('application_id', $appId)->with('department')->get() as $assignment) {
        $officer = authAs($deptEmail[$assignment->department->code]);
        test()->withHeaders($officer)
            ->postJson("/api/v1/assignments/{$assignment->id}/approve", ['remarks' => 'ok'])
            ->assertOk();
    }

    expect(Application::find($appId)->status->value)->toBe('for_inspection');

    return [$appId, Inspection::where('application_id', $appId)->with('department')->get()];
}

it('schedules a re-inspection from a failed visit and keeps the failure on the record', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Extinguisher Diner');

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", [
            'result' => 'failed',
            'findings' => 'no extinguisher',
        ])->assertOk();

    // The whole point of the bug: the filing is still waiting, and before this
    // change nothing could move it.
    expect(Application::find($appId)->status->value)->toBe('for_inspection');

    $when = now()->addWeekdays(5)->startOfHour();
    $created = test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", ['scheduled_at' => $when->toIso8601String()])
        ->assertCreated()
        ->json('data');

    // A NEW row, for the same office, waiting to be conducted.
    expect($created['id'])->not->toBe($fire->id);
    expect($created['status'])->toBe('scheduled');
    expect($created['result'])->toBeNull();
    expect($created['department']['code'])->toBe('BFP');

    // And the failure is still there, untouched — not rescheduled, not reused.
    $failed = Inspection::find($fire->id);
    expect($failed->result->value)->toBe('failed');
    expect($failed->findings)->toBe('no extinguisher');
    expect($failed->conducted_at)->not->toBeNull();
    expect(Inspection::where('application_id', $appId)->where('department_id', $fire->department_id)->count())->toBe(2);

    // The applicant is still told the same thing they were told before.
    $ownerHeaders = authAs('owner@biztrack.local');
    expect(
        test()->withHeaders($ownerHeaders)->getJson("/api/v1/applications/{$appId}")->json('data.status')
    )->toBe('for_inspection');
});

it('approves and issues when the re-inspection passes, over the kept failure', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Second Chance Grill');

    // The other office passes first, so the only thing standing between this
    // filing and its permits is the failed visit and its replacement.
    $sanitary = $visits->firstWhere('department.code', 'CHO');
    test()->withHeaders(authAs($deptEmail['CHO']))
        ->postJson("/api/v1/inspections/{$sanitary->id}/conduct", ['result' => 'passed'])
        ->assertOk();

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    $reinspectionId = test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(5)->toIso8601String(),
        ])->assertCreated()->json('data.id');

    // Still for inspection with the booking open — nothing auto-approves, and an
    // office with an open visit is not an office that has passed.
    expect(Application::find($appId)->status->value)->toBe('for_inspection');
    expect(Permit::where('application_id', $appId)->count())->toBe(0);

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$reinspectionId}/conduct", ['result' => 'passed', 'findings' => 'extinguisher installed'])
        ->assertOk();

    // This is the assertion the old `allPassed` over EVERY row could not pass:
    // the failed visit is still in the table and the filing is approved anyway.
    $app = Application::find($appId);
    expect($app->status->value)->toBe('approved');
    expect(Permit::where('application_id', $appId)->count())->toBe(3);

    expect(Inspection::find($fire->id)->result->value)->toBe('failed');
    expect(Inspection::where('application_id', $appId)->count())->toBe(3);
});

it('refuses a re-inspection on a visit that passed', function () use ($deptEmail) {
    [, $visits] = filingAwaitingInspection($deptEmail, 'Spotless Bakery');

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'passed'])
        ->assertOk();

    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(5)->toIso8601String(),
        ])->assertStatus(422);

    // Nothing was booked behind the refusal.
    expect(Inspection::where('application_id', $fire->application_id)
        ->where('department_id', $fire->department_id)->count())->toBe(1);
});

it('refuses a second re-inspection booked from a superseded failure', function () use ($deptEmail) {
    [, $visits] = filingAwaitingInspection($deptEmail, 'Twice Shy Cafe');

    $fire = $visits->firstWhere('department.code', 'BFP');
    $officer = authAs($deptEmail['BFP']);
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'blocked exit'])
        ->assertOk();

    $second = test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(5)->toIso8601String(),
        ])->assertCreated()->json('data.id');

    // The first failure is now history. Booking from it again would leave the
    // office with two open visits, neither aware of the other.
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(9)->toIso8601String(),
        ])->assertStatus(422);

    // The current visit may fail again and be re-inspected again, though —
    // failing twice is not a reason to strand the filing a second time.
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$second}/conduct", ['result' => 'failed', 'findings' => 'still blocked'])
        ->assertOk();
    test()->withHeaders($officer)
        ->postJson("/api/v1/inspections/{$second}/reinspect", [
            'scheduled_at' => now()->addWeekdays(12)->toIso8601String(),
        ])->assertCreated();

    expect(Inspection::where('application_id', $fire->application_id)
        ->where('department_id', $fire->department_id)->count())->toBe(3);
});

it('refuses a re-inspection once the filing has been decided', function () use ($deptEmail) {
    [$appId, $visits] = filingAwaitingInspection($deptEmail, 'Closed Book Store');

    $fire = $visits->firstWhere('department.code', 'BFP');
    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    // BPLO ends the filing (the one office that may).
    test()->withHeaders(authAs($deptEmail['BPLO']))
        ->postJson("/api/v1/applications/{$appId}/reject", ['reason' => 'Premises unsafe.'])
        ->assertOk();

    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", [
            'scheduled_at' => now()->addWeekdays(5)->toIso8601String(),
        ])->assertStatus(422);
});

/*
 * The re-inspection belongs to the office that failed the visit — the super
 * admin does not book it.
 *
 * This test used to assert the opposite, and said so: "the stranded filings are
 * opened by an admin, not by the office that failed them, so this is the path
 * that actually unsticks them". That was true of the six stranded filings
 * because at the time the admin was the only account that could reach an
 * inspection at all — OBO, CENRO, CPDO and the Market Office had no
 * `inspection.manage`, so a rescue by the responsible office was not on offer
 * and the admin was standing in for it.
 *
 * Both halves of that have since been fixed, in opposite directions. Every
 * clearance office now holds `inspection.manage` (RbacSeeder), so each one can
 * rebook its own failed visit — which is what the test above this one proves,
 * through the same endpoint, for the same failure. And the super admin lost it,
 * at the client's request: "In the super admin's account (admin@), remove
 * Messages, Track, Inspections, and Other Requirements. It is not his role to
 * do those things."
 *
 * So the rescue path did not disappear, it moved to the office that owns the
 * premises it is about. Asserting the 403 is what keeps the two facts from
 * drifting apart: if the admin ever answers 201 here again, either the client's
 * separation was undone or an office lost the permission and the admin is
 * covering for it once more.
 */
it('refuses the super admin a re-inspection: it belongs to the office that failed the visit', function () use ($deptEmail) {
    [, $visits] = filingAwaitingInspection($deptEmail, 'Admin Rescue Mart');

    $fire = $visits->firstWhere('department.code', 'BFP');
    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/conduct", ['result' => 'failed', 'findings' => 'no extinguisher'])
        ->assertOk();

    $payload = ['scheduled_at' => now()->addWeekdays(5)->toIso8601String()];

    // 403 from the route's `permission:inspection.manage` gate, before the
    // controller is reached — the admin cannot see the visit either.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", $payload)
        ->assertStatus(403);
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/inspections/{$fire->id}")
        ->assertStatus(403);

    // And the filing is not stranded by that: BFP books its own replacement.
    test()->withHeaders(authAs($deptEmail['BFP']))
        ->postJson("/api/v1/inspections/{$fire->id}/reinspect", $payload)
        ->assertCreated();
});

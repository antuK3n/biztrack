<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\Inspection;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Support\Facades\DB;

// Visits are booked on a weekday in office hours (manage item 4).
beforeEach(fn () => duringOfficeHours());

/*
 * The inspector is a name the office types, for the record.
 *
 * The client, 5 October 2026, on the For Inspection card that named an account
 * with Assign/Unassign beside it: *"since an inspector can have no account in
 * the system, would it be better if the admin just type the name of the
 * inspector assigned? The officer in charge is still the one to approve or
 * reject the inspection, but he/she must still be able to put the inspector
 * name just for the record. The field must be editable."*
 *
 * It replaced `POST inspections/{id}/claim|release` (4 October 2026). What
 * held under that design and still holds is asserted here again: the office's
 * officer records the result, and another office cannot touch the visit.
 */

/**
 * A paid filing whose sanitary permit is at its visit, booked for today.
 *
 * One clearance only — SANITARY, so CHO — because every case below is about
 * one office's visit. The steps are the 6 September procedure's, as in
 * InspectionOfficeScopingTest: BPLO approves the form, the owner pays, opens
 * the permit and submits its sheet, CHO approves the paperwork and books.
 *
 * @return array{app: Application, visit: Inspection}
 */
function filingWithSanitaryVisitBooked(?string $scheduledAt = null): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Inspector Name '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Record Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 200000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'SANITARY'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/clearances/SANITARY/apply")
        ->assertSuccessful();
    satisfyChecklist(Application::findOrFail($appId), PermitType::where('code', 'SANITARY')->firstOrFail());
    test()->withHeaders($owner)
        ->putJson("/api/v1/applications/{$appId}/office-forms/SANITARY", [
            'form_data' => ['sanitary_classification' => 'Food Establishment'],
            'submit' => true,
        ])->assertSuccessful();

    $app = Application::findOrFail($appId);
    $assignment = $app->assignments()->whereRelation('department', 'code', 'CHO')->firstOrFail();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/assignments/{$assignment->id}/approve")->assertOk();
    test()->postJson("/api/v1/applications/{$appId}/permits/SANITARY/inspection", [
        'scheduled_at' => $scheduledAt ?? now()->toDateString(),
    ])->assertCreated();

    return ['app' => $app->fresh(), 'visit' => $app->inspections()->firstOrFail()];
}

function namingAudits(Inspection $visit)
{
    return AuditLog::where('action', 'inspection.inspector_named')
        ->where('auditable_type', Inspection::class)
        ->where('auditable_id', $visit->id)
        ->orderBy('id')
        ->get();
}

it('opens a visit with no inspector named and no account attached', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();

    expect($visit->inspector_name)->toBeNull()
        ->and($visit->inspector_user_id)->toBeNull();
});

it('lets the office type the inspector’s name, and audits it from and to', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();

    authAs('sanitary@biztrack.local');
    $named = test()->patchJson("/api/v1/inspections/{$visit->id}/inspector", [
        'inspector_name' => '  Carlos Dizon ',
    ])->assertOk()->json('data');

    expect($named['inspector_name'])->toBe('Carlos Dizon')
        ->and($named['can_name_inspector'])->toBeTrue()
        ->and($visit->fresh()->inspector_name)->toBe('Carlos Dizon')
        // A name is not an account: nothing writes the old column.
        ->and($visit->fresh()->inspector_user_id)->toBeNull();

    $audit = namingAudits($visit)->sole();
    expect($audit->changes)->toBe(['from' => null, 'to' => 'Carlos Dizon']);
});

it('lets the name be changed and cleared, auditing each change and not a resave', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();

    authAs('sanitary@biztrack.local');
    $url = "/api/v1/inspections/{$visit->id}/inspector";
    test()->patchJson($url, ['inspector_name' => 'Carlos Dizon'])->assertOk();
    test()->patchJson($url, ['inspector_name' => 'Carlos Dizon'])->assertOk();
    test()->patchJson($url, ['inspector_name' => 'Maria Reyes'])->assertOk();
    test()->patchJson($url, ['inspector_name' => '   '])->assertOk();
    test()->patchJson($url, ['inspector_name' => null])->assertOk();

    expect($visit->fresh()->inspector_name)->toBeNull()
        ->and(namingAudits($visit)->pluck('changes')->all())->toBe([
            ['from' => null, 'to' => 'Carlos Dizon'],
            ['from' => 'Carlos Dizon', 'to' => 'Maria Reyes'],
            ['from' => 'Maria Reyes', 'to' => null],
        ]);
});

it('refuses a name longer than 120 characters, and a request that omits the field', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();

    authAs('sanitary@biztrack.local');
    test()->patchJson("/api/v1/inspections/{$visit->id}/inspector", [
        'inspector_name' => str_repeat('a', 121),
    ])->assertUnprocessable()->assertJsonValidationErrors('inspector_name');
    test()->patchJson("/api/v1/inspections/{$visit->id}/inspector", [])
        ->assertUnprocessable()->assertJsonValidationErrors('inspector_name');
});

it('lets the officer in charge record the result with no inspector named', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/inspections/{$visit->id}/conduct", ['result' => 'passed'])->assertOk();

    expect($visit->fresh()->result?->value)->toBe('passed')
        // conduct() used to adopt whoever pressed it as the inspector.
        ->and($visit->fresh()->inspector_user_id)->toBeNull()
        ->and($visit->fresh()->inspector_name)->toBeNull();
});

it('refuses another office, and the applicant, the inspector’s name', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();

    authAs('fire@biztrack.local');
    test()->patchJson("/api/v1/inspections/{$visit->id}/inspector", ['inspector_name' => 'Somebody'])
        ->assertForbidden();

    authAs('owner@biztrack.local');
    test()->patchJson("/api/v1/inspections/{$visit->id}/inspector", ['inspector_name' => 'Somebody'])
        ->assertForbidden();

    expect($visit->fresh()->inspector_name)->toBeNull();
});

it('keeps the name editable after a failed visit, and closes it once the permit is issued', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/inspections/{$visit->id}/conduct", [
        'result' => 'failed', 'findings' => 'No running water.',
    ])->assertOk();

    // A failure leaves the clearance open: the record can still be put right.
    test()->patchJson("/api/v1/inspections/{$visit->id}/inspector", ['inspector_name' => 'Carlos Dizon'])
        ->assertOk();

    $again = test()->postJson("/api/v1/inspections/{$visit->id}/reinspect", [
        'scheduled_at' => now()->toDateString(),
    ])->assertCreated()->json('data');
    test()->postJson("/api/v1/inspections/{$again['id']}/conduct", ['result' => 'passed'])->assertOk();

    // Passed: the permit is issued and the clearance closed, on both visits.
    foreach ([$visit->id, $again['id']] as $id) {
        test()->patchJson("/api/v1/inspections/{$id}/inspector", ['inspector_name' => 'Maria Reyes'])
            ->assertStatus(422)->assertJsonPath('message', 'This clearance is closed.');
    }

    expect($visit->fresh()->inspector_name)->toBe('Carlos Dizon')
        ->and(test()->getJson("/api/v1/inspections/{$visit->id}")->json('data.can_name_inspector'))->toBeFalse();
});

it('offers the office’s own last 20 names, newest first, once each', function () {
    ['app' => $app, 'visit' => $visit] = filingWithSanitaryVisitBooked();

    $cho = Department::where('code', 'CHO')->value('id');
    $bfp = Department::where('code', 'BFP')->value('id');

    // 22 names on CHO visits, a day apart, the newest last; and one repeat.
    foreach (range(1, 22) as $n) {
        $row = Inspection::create([
            'application_id' => $app->id, 'department_id' => $cho, 'status' => 'completed',
        ]);
        $row->forceFill(['inspector_name' => "CHO Inspector {$n}", 'updated_at' => now()->subDays(30 - $n)])->save();
    }
    $visit->forceFill(['inspector_name' => 'CHO Inspector 3', 'updated_at' => now()])->save();
    // Another office's name must not appear in CHO's list.
    Inspection::create(['application_id' => $app->id, 'department_id' => $bfp, 'status' => 'completed'])
        ->forceFill(['inspector_name' => 'BFP Inspector'])->save();

    authAs('sanitary@biztrack.local');
    $names = test()->getJson('/api/v1/inspections/inspector-names')->assertOk()->json('data');

    expect($names)->toHaveCount(20)
        ->and($names[0])->toBe('CHO Inspector 3')
        ->and($names[1])->toBe('CHO Inspector 22')
        ->and(array_count_values($names)['CHO Inspector 3'])->toBe(1)
        ->and($names)->not->toContain('BFP Inspector')
        ->and($names)->not->toContain('CHO Inspector 1');

    authAs('fire@biztrack.local');
    expect(test()->getJson('/api/v1/inspections/inspector-names')->json('data'))->toBe(['BFP Inspector']);
});

it('puts the typed name on the office’s queue row, and no claim flags', function () {
    ['app' => $app, 'visit' => $visit] = filingWithSanitaryVisitBooked();

    authAs('sanitary@biztrack.local');
    test()->patchJson("/api/v1/inspections/{$visit->id}/inspector", ['inspector_name' => 'Carlos Dizon'])
        ->assertOk();

    $row = collect(test()->getJson('/api/v1/assignments?clearance_status=for_inspection&per_page=100')
        ->assertOk()->json('data'))
        ->firstWhere('application.id', $app->id);

    expect($row['inspection'])->toBe([
        'id' => $visit->id,
        'status' => $row['inspection']['status'],
        'scheduled_at' => $row['inspection']['scheduled_at'],
        'inspector_name' => 'Carlos Dizon',
        'can_name_inspector' => true,
    ]);
});

it('no longer has a claim or release on a visit', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/inspections/{$visit->id}/claim")->assertNotFound();
    test()->postJson("/api/v1/inspections/{$visit->id}/release")->assertNotFound();
});

/*
 * ── The booked time is stored on the app's clock ─────────────────────────
 *
 * Not about the inspector, but the same visit and the same fixture. The
 * browser sends `toISOString()` (UTC, "…Z"), and the three booking paths
 * stored it in that zone, so 06:28 Manila landed as 22:28 the day before
 * while every other column is Manila (5 October 2026). Each path is asked
 * once, with the instant spelled in UTC.
 */

/**
 * The next weekday at 08:28 in Manila, as the browser would send it: UTC with
 * a Z (00:28). It was tomorrow at 06:28, which crossed the date in UTC; a
 * visit is now booked on a weekday from 8:00 AM (manage item 4), and an
 * eight-hour shift is still the bug if it comes back.
 */
function nextWeekdayAt0828AsUtc(): array
{
    $manila = now('Asia/Manila')->addWeekday()->setTime(8, 28);

    return [$manila->copy()->utc()->toISOString(), $manila->format('Y-m-d H:i:s')];
}

function storedScheduledAt(int $id): string
{
    return (string) DB::table('inspections')->where('id', $id)->value('scheduled_at');
}

it('stores a first booking sent in UTC as Manila time', function () {
    [$utc, $manila] = nextWeekdayAt0828AsUtc();
    ['visit' => $visit] = filingWithSanitaryVisitBooked($utc);

    expect(storedScheduledAt($visit->id))->toBe($manila);
});

it('stores a rescheduled time sent in UTC as Manila time', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();
    [$utc, $manila] = nextWeekdayAt0828AsUtc();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/inspections/{$visit->id}/reschedule", ['scheduled_at' => $utc])->assertOk();

    expect(storedScheduledAt($visit->id))->toBe($manila);
});

it('stores a re-inspection time sent in UTC as Manila time', function () {
    ['visit' => $visit] = filingWithSanitaryVisitBooked();
    [$utc, $manila] = nextWeekdayAt0828AsUtc();

    authAs('sanitary@biztrack.local');
    test()->postJson("/api/v1/inspections/{$visit->id}/conduct", ['result' => 'failed'])->assertOk();
    $again = test()->postJson("/api/v1/inspections/{$visit->id}/reinspect", ['scheduled_at' => $utc])
        ->assertCreated()->json('data.id');

    expect(storedScheduledAt($again))->toBe($manila);
});

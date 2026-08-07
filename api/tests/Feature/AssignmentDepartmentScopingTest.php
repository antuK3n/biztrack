<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * An assignment is reviewed by its own department, and by nothing else (INS-7).
 *
 * `AssignmentController::authorizeDepartment` used to open with
 * `if ($user->hasRole('admin')) return;` — an exemption granted by NAME, to
 * every action on the controller, approve and return included. It was dead code
 * when it was found: the whole review group sits behind
 * `permission:application.review`, and the super admin no longer holds that, so
 * the route gate answered 403 before the controller ran.
 *
 * Dead is not the same as harmless. What the line actually said was "a role
 * called admin may sign off any office's review", and the only thing making that
 * false was the absence of one seeder row. These cases pin the rule against the
 * grant rather than against the accident: a reviewer who really does hold
 * `application.review` AND the `admin` role is still refused another office's
 * assignment. That is the exact configuration the old line was waiting for, and
 * the one no existing test could construct — WorkflowReinspectionTest.php:258
 * says so in its own comment, that its 403 comes from the route gate and never
 * reaches the department rule it appears to be about.
 *
 * The carve-out below is deliberate and is the reason the exemption was narrowed
 * rather than deleted outright: naming who handles a case is not deciding it.
 */

/** A paid, routed filing carrying BUSINESS + SANITARY (BPLO + CHO). */
function scopedAssignmentFiling(string $name): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name.' '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '3 Scoped Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'SANITARY'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])->assertCreated();

    return $appId;
}

/** CHO's assignment on this filing. */
function choAssignmentId(int $applicationId): int
{
    return ApplicationAssignment::where('application_id', $applicationId)
        ->whereHas('department', fn ($d) => $d->where('code', 'CHO'))
        ->value('id');
}

/**
 * The account the old exemption was waiting for: the `admin` role plus a role
 * that really carries `application.review`, so the route gate lets them through
 * and authorizeDepartment is the only thing left standing.
 */
function reviewingAdmin(?string $departmentCode = null): User
{
    $user = User::create([
        'name' => 'Reviewing Admin',
        'first_name' => 'Reviewing',
        'last_name' => 'Admin',
        'gender' => 'F',
        'email' => 'reviewing.admin.'.random_int(10000, 99999).'@biztrack.local',
        'mobile_number' => '09170000001',
        'password' => 'biztrack1',
        'department_id' => $departmentCode
            ? Department::where('code', $departmentCode)->value('id')
            : null,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::whereIn('name', ['admin', 'bplo_staff'])->pluck('id'));

    return $user->fresh();
}

it('refuses a reviewer with the admin role another department’s assignment', function (string $action, array $body) {
    $appId = scopedAssignmentFiling('Admin Exemption Cafe');
    $assignmentId = choAssignmentId($appId);

    $admin = reviewingAdmin();
    expect($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->hasPermission('application.review'))->toBeTrue();

    app('auth')->forgetGuards();
    Sanctum::actingAs($admin);

    test()->postJson("/api/v1/assignments/{$assignmentId}/{$action}", $body)->assertForbidden();

    // Nothing moved: the refusal is before the write, not after it.
    expect(ApplicationAssignment::find($assignmentId)->status->value)->toBe('pending');
})->with([
    'approve' => ['approve', ['remarks' => 'Cleared.']],
    'return' => ['return', ['remarks' => 'Fix the water certificate.']],
]);

it('refuses a reviewer with the admin role another department’s assignment on read too', function () {
    // `show` is what the officer's browser loads, and it carries the whole
    // filing — the office forms included. An exemption on the read side is a
    // confidentiality question, not just an authorisation one.
    $appId = scopedAssignmentFiling('Admin Exemption Read Cafe');

    app('auth')->forgetGuards();
    Sanctum::actingAs(reviewingAdmin());

    test()->getJson('/api/v1/assignments/'.choAssignmentId($appId))->assertForbidden();
});

it('still lets the office that owns the assignment act on it', function () {
    // The narrowing must not touch the office whose work this is.
    $appId = scopedAssignmentFiling('Own Office Cafe');

    // Confirmed on receipt: the approval gate wants a name on the processing
    // category, and a 422 from it would look exactly like the 403 this case is
    // meant to prove does not happen.
    classifyAsOfficer(Application::findOrFail($appId));

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson('/api/v1/assignments/'.choAssignmentId($appId).'/approve', ['remarks' => 'Cleared.'])
        ->assertOk();
});

it('still lets a city-wide coordinator name the officer in charge of any office', function () {
    /*
     * The one action that legitimately crosses offices, and the reason the
     * exemption was narrowed to a carve-out at the call site instead of deleted.
     * `oic.assign` is the super admin's today, they have no department of their
     * own, and reassigning is administration rather than a decision on the
     * filing.
     */
    $appId = scopedAssignmentFiling('Coordinator Cafe');
    $assignmentId = choAssignmentId($appId);
    $choOfficer = User::where('department_id', Department::where('code', 'CHO')->value('id'))->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/assignments/{$assignmentId}/assign", ['officer_user_id' => $choOfficer->id])
        ->assertOk();
});

it('refuses an OIC who belongs to one office the queue of another', function () {
    /*
     * The future the carve-out is written against, stated now so it cannot be
     * widened by accident. assign()'s own docblock says the department check
     * exists for "the day the permission is given to an office's OIC" — such an
     * OIC has a department, so they fall through to the strict check.
     *
     * Built the same way as the admin case: a real `oic.assign` holder who is
     * also a member of an office.
     */
    $appId = scopedAssignmentFiling('Office OIC Cafe');
    $bfpOfficer = User::where('department_id', Department::where('code', 'BFP')->value('id'))->firstOrFail();

    $officeOic = reviewingAdmin('BFP');
    expect($officeOic->hasPermission('oic.assign'))->toBeTrue()
        ->and($officeOic->department_id)->not->toBeNull();

    app('auth')->forgetGuards();
    Sanctum::actingAs($officeOic);

    test()->postJson('/api/v1/assignments/'.choAssignmentId($appId).'/assign', [
        'officer_user_id' => $bfpOfficer->id,
    ])->assertForbidden();
});

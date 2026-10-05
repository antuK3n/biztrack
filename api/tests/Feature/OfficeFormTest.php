<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationOfficeForm;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Department;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Support\SanitaryPrefill;
use App\Support\SheetRequirements;

/*
 * Per-office forms: the sheet never asks for what the system already knows
 * (tester items 10/11/23) and issuance dates belong to the office (item 16).
 */

/** A fresh application for owner@ with the given permit types. */
function officeFormApp(
    array $codes,
    ApplicationType $type = ApplicationType::New,
    ApplicationStatus $status = ApplicationStatus::Draft,
    $submittedAt = null,
): Application {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => $type,
        'status' => $status,
        'submitted_at' => $submittedAt,
    ]);
    $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id'));

    return $app;
}

function savedForm(Application $app, string $code): array
{
    $permitTypeId = PermitType::where('code', $code)->value('id');

    return ApplicationOfficeForm::where('application_id', $app->id)
        ->where('permit_type_id', $permitTypeId)
        ->value('form_data') ?? [];
}

/* ── Items 10 & 23: the derived "Certificate Applied For" ──────────────── */

it('derives the FSIC certificate applied for instead of asking the applicant', function () {
    $app = officeFormApp(['FSIC']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => ['authorized_representative' => 'Ana Cruz'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.certificate_applied_for', 'FSIC for Business Permit (New Business)');

    expect(savedForm($app, 'FSIC')['authorized_representative'])->toBe('Ana Cruz');
});

it('does not trust a client-supplied certificate applied for', function () {
    // A renewal without an occupancy permit can only be the renewal certificate.
    $app = officeFormApp(['FSIC'], ApplicationType::Renewal);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => ['certificate_applied_for' => 'FSIC for Certificate of Occupancy'],
        ])
        ->assertOk()
        ->assertJsonPath(
            'data.form_data.certificate_applied_for',
            'FSIC for Business Permit (Renewal of Business)'
        );

    expect(savedForm($app, 'FSIC')['certificate_applied_for'])
        ->toBe('FSIC for Business Permit (Renewal of Business)');
});

it('stays the Business Permit certificate even with an Occupancy Permit on the same filing', function () {
    /*
     * Until 5 October 2026 this derived "FSIC for Certificate of Occupancy"
     * and the BFP checklist asked for OBO's papers before OBO had issued any.
     * Client: *"Make them separate."* The two sheets no longer read each
     * other: no occupancy certificate, no shared answers, no marker.
     */
    $app = officeFormApp(['FSIC', 'OCCUPANCY']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => ['occupancy_type' => 'Mercantile', 'building_storeys' => '2'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.certificate_applied_for', 'FSIC for Business Permit (New Business)')
        ->assertJsonPath('data.form_data.occupancy_type', 'Mercantile')
        ->assertJsonMissingPath('data.form_data.occupancy_shared_source');

    $codes = collect(SheetRequirements::for($app->fresh(), 'FSIC'))->pluck('code')->all();
    expect($codes)->toContain('FSIC_REQ_VALID_COO')
        ->and($codes)->not->toContain('FSIC_REQ_OBO_ENDORSEMENT');
});

it('derives the sanitary and CEC application types from the application record', function () {
    $app = officeFormApp(['SANITARY', 'CEC'], ApplicationType::Renewal);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
            'form_data' => ['application_type' => 'New', 'sanitary_classification' => 'Food Establishment'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.application_type', 'Renewal');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/CEC", [
            'form_data' => ['application_type' => 'Initial Application'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.application_type', 'Renewal of CEC');
});

/*
 * ── One question, asked once ──────────────────────────────────────────────
 *
 * The health certificate fee (Sec. 4D.02) is ₱50 per employee per year against
 * `fee_profile.employees`, charged only when the applicant says their staff
 * need certificates. The sanitary sheet then asked for the same headcount a
 * second time in free text that nothing read — so the office could be shown one
 * number and the applicant billed on another, for the same fee, on the same
 * filing.
 */

it('carries the health-certificate headcount from the profile instead of re-asking', function () {
    $app = officeFormApp(['SANITARY']);
    $app->update(['fee_profile' => [
        'employees' => 7,
        'flags' => ['employees_need_health_certificates'],
    ]]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
            // The old free-text answer, still posted by a stale client. It must
            // not win: the fee is computed on the profile, not on this box.
            'form_data' => ['workers_requiring_health_certs' => '2'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.workers_requiring_health_certs', '7');

    expect(savedForm($app, 'SANITARY')['workers_requiring_health_certs'])->toBe('7');
});

it('says None when no employee needs a health certificate', function () {
    // Unflagged means the fee is not charged at all, so the honest answer is
    // "none" — a blank box reads as an unanswered question.
    $app = officeFormApp(['SANITARY']);
    $app->update(['fee_profile' => ['employees' => 7, 'flags' => []]]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", ['form_data' => []])
        ->assertOk()
        ->assertJsonPath('data.form_data.workers_requiring_health_certs', 'None');
});

it('leaves the headcount blank when the profile is flagged but has no number', function () {
    // Half-filled profile: better an empty box the office can send back than a
    // zero it would act on.
    $app = officeFormApp(['SANITARY']);
    $app->update(['fee_profile' => ['flags' => ['employees_need_health_certificates']]]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", ['form_data' => []])
        ->assertOk()
        ->assertJsonPath('data.form_data.workers_requiring_health_certs', '');
});

it('leaves the occupancy full/partial choice to the applicant', function () {
    $app = officeFormApp(['OCCUPANCY']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['application_type' => 'Partial'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.application_type', 'Partial');
});

it('lists derived answers for a form the applicant has not saved yet', function () {
    $app = officeFormApp(['FSIC']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/office-forms")
        ->assertOk()
        ->assertJsonPath('data.0.permit_type_code', 'FSIC')
        ->assertJsonPath('data.0.form_data.certificate_applied_for', 'FSIC for Business Permit (New Business)');
});

/* ── Item 11: the application date comes from submitted_at ─────────────── */

it('auto-fills the application date from submitted_at', function () {
    $submittedAt = now()->subDays(4);
    $app = officeFormApp(['SANITARY'], ApplicationType::New, ApplicationStatus::Returned, $submittedAt);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
            'form_data' => ['application_date' => '1999-01-01'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.application_date', $submittedAt->toDateString());
});

/* ── Item 16: issuance dates are the office's, not the applicant's ─────── */

it('ignores issuance dates sent by the applicant', function () {
    $app = officeFormApp(['OCCUPANCY']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'BP-001', 'building_permit_date' => '2026-01-05'],
        ])
        ->assertOk();

    expect(savedForm($app, 'OCCUPANCY'))
        ->toHaveKey('building_permit_no')
        ->not->toHaveKey('building_permit_date');
});

it('lets a reviewing officer record the issuance dates', function () {
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::Approved, now()->subDay());
    ApplicationOfficeForm::create([
        'application_id' => $app->id,
        'permit_type_id' => PermitType::where('code', 'OCCUPANCY')->value('id'),
        'form_data' => ['application_type' => 'Full', 'building_permit_no' => 'BP-001'],
    ]);

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_date' => '2026-01-05', 'fsec_date' => '2026-01-06'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.building_permit_date', '2026-01-05')
        ->assertJsonPath('data.form_data.fsec_date', '2026-01-06');

    // The applicant's own answers survive an officer write.
    expect(savedForm($app, 'OCCUPANCY'))
        ->toMatchArray(['application_type' => 'Full', 'building_permit_no' => 'BP-001']);
});

it('does not let ANOTHER office overwrite the applicant answers', function () {
    /*
     * BPLO does not issue the Occupancy Permit — the Office of the Building
     * Official does — so this is a reviewer from outside the sheet's office,
     * and it keeps the old rule: the issuance dates and nothing else. The
     * test two below is the office that DOES issue it.
     */
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::Approved, now()->subDay());
    ApplicationOfficeForm::create([
        'application_id' => $app->id,
        'permit_type_id' => PermitType::where('code', 'OCCUPANCY')->value('id'),
        'form_data' => ['building_permit_no' => 'BP-001'],
    ]);

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'TAMPERED', 'fsec_date' => '2026-01-06'],
        ])
        ->assertOk();

    expect(savedForm($app, 'OCCUPANCY')['building_permit_no'])->toBe('BP-001');
});

it('lets the office that issues the permit correct the applicant’s answers, and records each change', function () {
    /*
     * Client, 4 October 2026: *"edit mode for the admin side still does not
     * work. I can't edit fields. PLEASE FIX FOR ALL ADMINS/OFFICES."* Edit
     * mode had only ever opened the For Office Use fields; the decision taken
     * was that an office may correct the one sheet it issues the permit for.
     *
     * Two things are pinned. The write lands — and the audit row names the
     * key, what it said and what it says now, so a sheet an office changed
     * reads as one an office changed. `department_id` is on the row because
     * the reader of an audit log asks "which office", not "which user id".
     */
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::Approved, now()->subDay());
    ApplicationOfficeForm::create([
        'application_id' => $app->id,
        'permit_type_id' => PermitType::where('code', 'OCCUPANCY')->value('id'),
        'form_data' => ['building_permit_no' => 'BP-001', 'building_units' => '3'],
    ]);
    /*
     * Routed, as `startClearance` routes it: visibility asks whether the
     * office holds a review on the filing before the write rule is ever
     * reached, and an office with no assignment is a stranger to it.
     */
    ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => Department::where('code', 'OBO')->value('id'),
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    $this->withHeaders(authAs('obo@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'BP-001-A', 'building_units' => '3'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.building_permit_no', 'BP-001-A');

    expect(savedForm($app, 'OCCUPANCY')['building_permit_no'])->toBe('BP-001-A');

    $entry = AuditLog::where('action', 'office_form.corrected_by_office')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->changes['department_id'])->toBe(User::where('email', 'obo@biztrack.local')->value('department_id'))
        ->and($entry->changes['fields'])->toBe([
            'building_permit_no' => ['from' => 'BP-001', 'to' => 'BP-001-A'],
        ]);
});

/** An OBO filing with a sheet on it and OBO's review routed, held by `$holder`. */
function oboSheetHeldBy(?User $holder): Application
{
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::Approved, now()->subDay());
    ApplicationOfficeForm::create([
        'application_id' => $app->id,
        'permit_type_id' => PermitType::where('code', 'OCCUPANCY')->value('id'),
        'form_data' => ['building_permit_no' => 'BP-001'],
    ]);
    ApplicationAssignment::create([
        'application_id' => $app->id,
        'department_id' => Department::where('code', 'OBO')->value('id'),
        'officer_user_id' => $holder?->id,
        'status' => 'pending',
        'assigned_at' => now(),
    ]);

    return $app;
}

it('refuses the correction to an officer of the office who does not hold the case', function () {
    /*
     * Ken, 5 October 2026 (office-review-obo 3): only the officer holding the
     * case may correct the answers. A colleague refused Approve on it could
     * still rewrite them. The issuance dates are not answers, so they stay open.
     */
    $colleague = User::create([
        'name' => 'Other Obo',
        'first_name' => 'Other',
        'last_name' => 'Obo',
        'gender' => 'F',
        'email' => 'other.obo@biztrack.local',
        'mobile_number' => '09170000003',
        'password' => 'biztrack1',
        'department_id' => Department::where('code', 'OBO')->value('id'),
        'is_active' => true,
    ]);
    $app = oboSheetHeldBy($colleague);
    $obo = authAs('obo@biztrack.local');

    $this->withHeaders($obo)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'BP-001-A'],
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'This filing is with another officer. Only the Super Administrator can move it.');

    $this->withHeaders($obo)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'BP-001', 'fsec_date' => '2026-01-06'],
        ])
        ->assertOk();

    expect(savedForm($app, 'OCCUPANCY')['building_permit_no'])->toBe('BP-001')
        ->and(savedForm($app, 'OCCUPANCY')['fsec_date'])->toBe('2026-01-06');
});

it('refuses the correction once the office has issued the permit', function () {
    // The holder too: the certificate was issued on the answers as they stand.
    $app = oboSheetHeldBy(User::where('email', 'obo@biztrack.local')->firstOrFail());
    $app->permitTypes()->updateExistingPivot(
        PermitType::where('code', 'OCCUPANCY')->value('id'),
        ['status' => ClearanceStatus::Approved->value],
    );

    $this->withHeaders(authAs('obo@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'BP-001-A'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This permit has been issued, so the answers on its sheet can no longer be changed.');

    expect(savedForm($app, 'OCCUPANCY')['building_permit_no'])->toBe('BP-001')
        ->and(AuditLog::where('action', 'office_form.corrected_by_office')->count())->toBe(0);
});

it('refuses the super admin the write outright', function () {
    /*
     * The gate is the department that ISSUES the permit, strictly. The super
     * admin reads every office's sheet and belongs to none, and reading
     * everything must not become editing everything. In practice the admin
     * never reaches the write rule: `application.review` is not among its
     * permissions, so the request is refused at the door. Asserted as the
     * 403 it is rather than as a silent strip, because a test that passed
     * either way would not notice the door moving.
     */
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::Approved, now()->subDay());
    ApplicationOfficeForm::create([
        'application_id' => $app->id,
        'permit_type_id' => PermitType::where('code', 'OCCUPANCY')->value('id'),
        'form_data' => ['building_permit_no' => 'BP-001'],
    ]);

    $this->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['building_permit_no' => 'TAMPERED'],
        ])
        ->assertForbidden();

    expect(savedForm($app, 'OCCUPANCY')['building_permit_no'])->toBe('BP-001')
        ->and(AuditLog::where('action', 'office_form.corrected_by_office')->count())->toBe(0);
});

it('rejects an issuance date in the future', function () {
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::Approved, now()->subDay());

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['fsec_date' => now()->addWeek()->toDateString()],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('form_data.fsec_date');
});

/* ── Item 9 regression: the owner birthday must stay a past date ───────── */

it('still rejects a future owner birthday', function () {
    $app = officeFormApp(['CEC']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/CEC", [
            'form_data' => ['owner_birthday' => now()->addYear()->toDateString()],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('form_data.owner_birthday');
});

/* ── Authorization: the route middleware was removed, so guard it here ──── */

it('refuses an office-form write from someone who is neither owner nor reviewer', function () {
    // The PUT route used to carry permission:application.create, which locked
    // officers out of recording issuance dates. Authorization now lives in the
    // controller, so this is the regression guard for that move.
    $app = officeFormApp(['FSIC']);

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => ['fsic_remarks' => 'not mine to write'],
        ])
        ->assertForbidden();

    expect(savedForm($app, 'FSIC'))->toBe([]);
});

it('refuses an office-form read from an unrelated applicant', function () {
    $app = officeFormApp(['FSIC']);

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/applications/{$app->id}/office-forms")
        ->assertForbidden();
});

it('refuses an office-form write from a guest', function () {
    $app = officeFormApp(['FSIC']);

    $this->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
        'form_data' => ['fsic_remarks' => 'anonymous'],
    ])->assertUnauthorized();
});

/*
 * REWRITTEN, because its premise was backwards.
 *
 * It said a submitted APPLICATION closed the office sheets — and if that were
 * true no applicant could ever fill one in. The five clearances are reached
 * after the filing has been submitted, accepted by BPLO and paid for
 * (docs/clearances-after-payment.md), so `approved` is precisely
 * the state these sheets are written in. The old case only passed because
 * `ownerMayEdit` looked for an assignment on the issuing office and this
 * hand-built fixture never had one; it was asserting the absence of a routing
 * step, not a rule about editing.
 *
 * The line is drawn on the PERMIT now (`OfficeFormController::ownerMayEdit`,
 * 9 September 2026). The sheet is the applicant's while their clearance is
 * `not_started` — applied for, opened, still being filled in — and while it is
 * `returned`, where an office has handed it back for exactly that purpose. It
 * stops being theirs from `for_approval` onward: "We do not promote any editing
 * of forms once submitted" (client), and an office may be reading it at that
 * moment. Asserted per permit rather than per filing because the five move
 * independently.
 */
it('stops the applicant editing an office form once its own clearance has been submitted', function () {
    $app = officeFormApp(['FSIC'], ApplicationType::New, ApplicationStatus::Approved, now());
    $fsicId = PermitType::where('code', 'FSIC')->value('id');

    // The permit's status is the only thing moving here. Driving it through the
    // workflow would need the filing built and paid for twice over, and what is
    // under test is the gate rather than how a permit arrives at each state.
    $permitAt = fn (ClearanceStatus $status) => $app->permitTypes()
        ->updateExistingPivot($fsicId, ['status' => $status->value]);

    $write = fn (string $remarks) => test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => ['fsic_remarks' => $remarks],
        ]);

    // Applied for and open: this is the clearance stage, and it is the window
    // the whole rework exists to keep open.
    $permitAt(ClearanceStatus::NotStarted);
    $write('still mine to write')->assertOk();

    // Handed in. BFP has it.
    $permitAt(ClearanceStatus::ForApproval);
    $write('too late')->assertStatus(422);

    // And past it — the office has accepted the paperwork and booked a visit
    // against these answers.
    $permitAt(ClearanceStatus::ForInspection);
    $write('too late')->assertStatus(422);

    // Returned: the office asked for a correction, which is the clearest
    // possible case for the sheet being editable again.
    $permitAt(ClearanceStatus::Returned);
    $write('fixed as BFP asked')->assertOk();

    // Neither refusal wrote anything, and the last permitted write did.
    expect(savedForm($app, 'FSIC')['fsic_remarks'])->toBe('fixed as BFP asked');
});

/*
 * ── The owner's address, from the account ───────────────────────────────────
 *
 * Client, 5 October 2026: *"Check the business permit application if there is
 * no owner's address to derive from … If none, derive this from the user's
 * address, but still editable."* The business permit holds none, so the sheet
 * is OFFERED the account's home address on the `prefill` channel — never
 * written into `form_data` — and `prefill_from` says it came from the account.
 */
it('offers the account’s home address to the sheets that ask the owner’s address', function () {
    User::where('email', 'owner@biztrack.local')->update(homeAddress());
    $app = officeFormApp(['OCCUPANCY', 'CEC', 'FSIC']);

    $forms = collect(
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$app->id}/office-forms")
            ->assertOk()
            ->json('data')
    )->keyBy('permit_type_code');

    expect($forms['OCCUPANCY']['prefill']['owner_address'])->toBe('12 Gen. Luna St., Longos, Malabon, Metro Manila 1472')
        ->and($forms['OCCUPANCY']['prefill_from']['owner_address'])->toBe('account')
        ->and($forms['CEC']['prefill']['owner_address'])->toBe('12 Gen. Luna St., Longos, Malabon, Metro Manila 1472')
        // Offered, not applied: the stored sheet does not have it until the applicant saves.
        ->and($forms['OCCUPANCY']['form_data'])->not->toHaveKey('owner_address')
        // The BFP sheet prints no box for it.
        ->and((array) ($forms['FSIC']['prefill'] ?? []))->not->toHaveKey('owner_address');
});

it('stops offering the address once the applicant has written one', function () {
    User::where('email', 'owner@biztrack.local')->update(homeAddress());
    $app = officeFormApp(['OCCUPANCY']);
    ApplicationOfficeForm::create([
        'application_id' => $app->id,
        'permit_type_id' => PermitType::where('code', 'OCCUPANCY')->value('id'),
        'form_data' => ['owner_address' => '7 Somewhere Else, Tonsuya, Malabon'],
    ]);

    $form = collect(
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$app->id}/office-forms")
            ->assertOk()
            ->json('data')
    )->firstWhere('permit_type_code', 'OCCUPANCY');

    expect((array) ($form['prefill'] ?? []))->not->toHaveKey('owner_address')
        ->and($form['form_data']['owner_address'])->toBe('7 Somewhere Else, Tonsuya, Malabon');
});

it('offers nothing when the account has no home address', function () {
    User::where('email', 'owner@biztrack.local')->update([
        'home_street' => null, 'home_barangay' => null, 'home_city' => null, 'home_province' => null,
    ]);
    $app = officeFormApp(['OCCUPANCY']);

    $form = collect(
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$app->id}/office-forms")
            ->assertOk()
            ->json('data')
    )->firstWhere('permit_type_code', 'OCCUPANCY');

    expect((array) ($form['prefill'] ?? []))->not->toHaveKey('owner_address');
});

/*
 * ── The Sanitary sheet, drawn from the standard PD 856 application ──────────
 *
 * Client, 5 October 2026: *"We don't have a paper copy of the sanitary permit
 * … make the fields yourself … If something needs auto-filling, do so."* and,
 * on seeing the boxes: *"If fields are auto-filled, show the recorded
 * information."* The headcount and floor area are the Business & Tax
 * Profile's and are derived; the classification is suggested from the line of
 * business on the prefill channel and stays the applicant's to change.
 */
it('fills the Sanitary sheet’s headcount and floor area from the fee profile', function () {
    $app = officeFormApp(['SANITARY']);
    $app->update(['fee_profile' => [
        'employees' => 2, 'male_employees' => 1, 'female_employees' => 1, 'floor_area_sqm' => 41,
        'flags' => [],
    ]]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
            'form_data' => ['sanitary_classification' => 'Food Establishment', 'employees_total' => '99'],
        ])
        ->assertOk()
        ->assertJsonPath('data.form_data.employees_male', '1')
        ->assertJsonPath('data.form_data.employees_female', '1')
        // Derived wins over a typed figure: the profile is what the fee was priced on.
        ->assertJsonPath('data.form_data.employees_total', '2')
        ->assertJsonPath('data.form_data.total_floor_area_sqm', '41');
});

it('suggests the sanitary classification from the line of business, as an offer', function () {
    $bar = PsicCode::where('category', 'bar_nightclub')->firstOrFail();
    $app = officeFormApp(['SANITARY']);
    $app->update(['fee_profile' => ['lines' => [['psic_code_id' => $bar->id]], 'flags' => []]]);

    $form = collect(
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$app->id}/office-forms")
            ->assertOk()
            ->json('data')
    )->firstWhere('permit_type_code', 'SANITARY');

    expect($form['prefill']['sanitary_classification'])->toBe('Food Establishment')
        ->and($form['prefill_from']['sanitary_classification'])->toBe('application')
        // Offered, not applied.
        ->and($form['form_data'])->not->toHaveKey('sanitary_classification');

    // And not once the applicant has chosen.
    ApplicationOfficeForm::create([
        'application_id' => $app->id,
        'permit_type_id' => PermitType::where('code', 'SANITARY')->value('id'),
        'form_data' => ['sanitary_classification' => 'Non-Food Establishment'],
    ]);
    $form = collect(
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$app->id}/office-forms")
            ->assertOk()
            ->json('data')
    )->firstWhere('permit_type_code', 'SANITARY');
    expect((array) ($form['prefill'] ?? []))->not->toHaveKey('sanitary_classification');
});

it('sorts the sanitary classes the way a health officer would', function () {
    expect(SanitaryPrefill::classify(['manufacturer', 'restaurant']))->toBe('Food Establishment')
        ->and(SanitaryPrefill::classify(['manufacturer']))->toBe('Industrial')
        ->and(SanitaryPrefill::classify(['retailer', 'barber_shop']))->toBe('Personal / Public Service')
        ->and(SanitaryPrefill::classify(['retailer']))->toBe('Non-Food Establishment');
});

it('refuses a future pest-control date and a negative toilet count on the Sanitary sheet', function () {
    $app = officeFormApp(['SANITARY']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/SANITARY", [
            'form_data' => ['pest_control_last_date' => now()->addDay()->toDateString(), 'toilets_count' => '-1'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['form_data.pest_control_last_date', 'form_data.toilets_count']);
});

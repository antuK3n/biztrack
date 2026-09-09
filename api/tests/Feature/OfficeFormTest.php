<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationOfficeForm;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\User;

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

it('derives the occupancy certificate when an occupancy permit is applied for', function () {
    $app = officeFormApp(['FSIC', 'OCCUPANCY']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", ['form_data' => []])
        ->assertOk()
        ->assertJsonPath('data.form_data.certificate_applied_for', 'FSIC for Certificate of Occupancy');
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
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::AwaitingOtherPermits, now()->subDay());
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

it('does not let an officer overwrite the applicant answers', function () {
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::AwaitingOtherPermits, now()->subDay());
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

it('rejects an issuance date in the future', function () {
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::AwaitingOtherPermits, now()->subDay());

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
 * (docs/clearances-after-payment.md), so `awaiting_other_permits` is precisely
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
    $app = officeFormApp(['FSIC'], ApplicationType::New, ApplicationStatus::AwaitingOtherPermits, now());
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

<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
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
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::UnderReview, now()->subDay());
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
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::UnderReview, now()->subDay());
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
    $app = officeFormApp(['OCCUPANCY'], ApplicationType::New, ApplicationStatus::UnderReview, now()->subDay());

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

it('stops the applicant editing office forms once the application is submitted', function () {
    $app = officeFormApp(['FSIC'], ApplicationType::New, ApplicationStatus::UnderReview, now());

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => ['fsic_remarks' => 'too late'],
        ])
        ->assertStatus(422);
});

<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationOfficeForm;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\FsicRequirements;
use App\Support\OccupancyRequirements;
use App\Support\OfficeFormAnswers;
use App\Support\OfficerEditRules;

/*
 * The OBO checklist's two wrong rows, reported by the tester on
 * 5 October 2026: an affidavit that applies only when the professional
 * changed was demanded of every filing, and "Local / Zoning Clearance —
 * From your Business Permit application" showed the location sketch.
 */

function occupancyChecklistRows(): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => ApplicationType::New,
        'status' => ApplicationStatus::Draft,
    ]);
    $app->permitTypes()->sync(PermitType::whereIn('code', ['BUSINESS', 'OCCUPANCY'])->pluck('id'));

    return collect(OccupancyRequirements::forApplication($app))->keyBy('key')->all();
}

it('does not block on the change-of-professional affidavit, which the paper makes conditional', function () {
    $row = occupancyChecklistRows()['CHANGE_OF_PROFESSIONAL'];

    expect($row['blocking'])->toBeFalse()
        ->and($row['label'])->toContain('(if ');
});

it('does not pass the location sketch off as the zoning clearance', function () {
    $row = occupancyChecklistRows()['ZONING_CLEARANCE'];

    expect($row['source'])->toBe('upload')
        ->and($row['carried_from'])->toBeNull()
        ->and($row['carried_document_ids'])->toBe([])
        // CPDO issues it on this filing; the sheet does not wait on a copy.
        ->and($row['blocking'])->toBeFalse()
        ->and($row['note'])->toContain('Zoning Clearance issued on this filing');
});

it('still blocks on the sealed Certificate of Completion and the owner ID', function () {
    $rows = occupancyChecklistRows();

    expect($rows['COMPLETION']['blocking'])->toBeTrue()
        ->and($rows['OWNER_ID']['blocking'])->toBeTrue()
        ->and($rows['PLANS']['blocking'])->toBeTrue();
});

/*
 * ── Five required uploads, not ten (client, 10 November 2026) ──────────────
 */

it('requires only the five uploads the client named, and leaves the rest optional', function () {
    $blocking = collect(occupancyChecklistRows())
        ->filter(fn (array $row) => $row['blocking'] === true)
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($blocking)->toBe(['BUILDING_PERMIT', 'COMPLETION', 'FIRE_SAFETY_CHECKLIST', 'OWNER_ID', 'PLANS']);
});

it('fills the zoning clearance row from the clearance issued on the filing, with nothing to upload', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => ApplicationType::New,
        'status' => ApplicationStatus::Draft,
    ]);
    $app->permitTypes()->sync(PermitType::whereIn('code', ['BUSINESS', 'OCCUPANCY', 'ZONING'])->pluck('id'));

    $zoning = Permit::create([
        'permit_number' => 'LC-TEST-'.random_int(100000, 999999),
        'application_id' => $app->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::where('code', 'ZONING')->value('id'),
        'status' => PermitStatus::Active,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);

    $row = collect(OccupancyRequirements::forApplication($app))->firstWhere('key', 'ZONING_CLEARANCE');

    expect($row['satisfied'])->toBeTrue()
        ->and($row['code'])->toBeNull()
        ->and($row['reference'])->toBe($zoning->permit_number);
});

it('does not require a Certificate of Occupancy on the FSIC sheet when the Occupancy Permit is on the same filing', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $make = function (array $codes) use ($owner, $business) {
        $app = Application::create([
            'business_id' => $business->id,
            'applicant_user_id' => $owner->id,
            'application_type' => ApplicationType::New,
            'status' => ApplicationStatus::Draft,
        ]);
        $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id'));

        return collect(FsicRequirements::forApplication($app))->firstWhere('key', 'VALID_COO');
    };

    expect($make(['BUSINESS', 'FSIC', 'OCCUPANCY'])['blocking'])->toBeFalse()
        ->and($make(['BUSINESS', 'FSIC'])['blocking'])->toBeTrue();
});

it('ticks Documents Submitted from the uploads, not from what the owner sends', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => ApplicationType::New,
        'status' => ApplicationStatus::Draft,
    ]);
    $app->permitTypes()->sync(PermitType::whereIn('code', ['BUSINESS', 'OCCUPANCY'])->pluck('id'));
    ApplicationDocument::create([
        'application_id' => $app->id,
        'document_type_id' => OccupancyRequirements::documentType('OCC_REQ_CONSTRUCTION_LOGBOOK')->id,
        'original_filename' => 'logbook.pdf',
        'stored_path' => 'private/documents/test/logbook.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
    ]);

    $answers = OfficeFormAnswers::derive($app, 'OCCUPANCY', ['completion_documents' => 'Certificate of Completion']);
    expect($answers['completion_documents'])->toBe('Daily Construction Works Logbook');

    $withOther = OfficeFormAnswers::derive($app, 'OCCUPANCY', ['completion_other_document' => 'Soil test']);
    expect($withOther['completion_documents'])->toBe('Daily Construction Works Logbook, Others');
});

it('keeps OBO’s Others entry off the owner’s save', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => ApplicationType::New,
        'status' => ApplicationStatus::Draft,
    ]);
    $app->permitTypes()->sync(PermitType::whereIn('code', ['BUSINESS', 'OCCUPANCY'])->pluck('id'));

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$app->id}/office-forms/OCCUPANCY", [
            'form_data' => ['completion_submitted_by' => 'Engr. A. Cruz', 'completion_other_document' => 'Mine'],
        ])
        ->assertOk();

    $saved = ApplicationOfficeForm::where('application_id', $app->id)->value('form_data');
    expect($saved['completion_submitted_by'])->toBe('Engr. A. Cruz')
        ->and($saved)->not->toHaveKey('completion_other_document')
        ->and(OfficerEditRules::sheetGroup('OCCUPANCY', 'completion_other_document'))->toBe('office_use')
        ->and(OfficerEditRules::sheetGroup('OCCUPANCY', 'completion_submitted_by'))->toBe('clerical')
        ->and(OfficerEditRules::sheetGroup('OCCUPANCY', 'completion_professional'))->toBe('clerical');
});

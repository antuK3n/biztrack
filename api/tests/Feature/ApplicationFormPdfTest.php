<?php

use App\Models\Application;
use App\Models\ApplicationAmendment;
use App\Models\ApplicationOfficeForm;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\BusinessLine;
use App\Models\BusinessOwner;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use Smalot\PdfParser\Parser;

/*
 * The filing printed on the office's own paper form (client, 19 October 2026):
 * "a downloadable application form in the admin side when viewing the
 * filing … the printed format should be the same as the paper-based forms."
 *
 * Two doors — GET /applications/{id}/form-pdf (FO-001 / FO-002 / FO-003 by
 * filing type) and GET /applications/{id}/office-forms/{code}/pdf (one
 * office's sheet). Reviewing officers only, office-scoped the way the review
 * sheet is, every download in the audit log.
 */

/**
 * A submitted filing carrying `$codes`, routed to BPLO and to each code's
 * issuing office, with an office sheet saved for every code in `$sheets`.
 * Built straight on the models: what is under test is the printing, not the
 * filing flow, and the flow has suites of its own.
 *
 * @param  list<string>  $codes
 * @param  array<string, array<string, mixed>>  $sheets
 */
function printableFiling(string $type = 'new', array $codes = [], array $sheets = []): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Paper Form Bakery',
        'trade_name' => 'PF Breads',
        'registration_type' => 'sole_proprietorship',
        'form_of_organization' => 'sole_proprietorship',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'ban' => 'BP-2026-9'.random_int(100, 999),
        'status' => 'active',
        'is_active' => true,
    ]);
    BusinessAddress::forceCreate([
        'business_id' => $business->id,
        'house_bldg_no' => '14',
        'street' => 'Gov. Pascual Ave.',
        'line1' => '14 Gov. Pascual Ave.',
        'barangay_id' => Barangay::first()->id,
        'city' => 'Malabon',
        'province' => 'Metro Manila',
        'postal_code' => '1470',
        'mobile_number' => '+639171234567',
        'email' => 'bakery@example.test',
        'address_type' => 'business_location',
    ]);
    BusinessOwner::create([
        'business_id' => $business->id, 'surname' => 'Santos', 'given_name' => 'Maria',
        'middle_name' => 'Reyes', 'gender' => 'F', 'is_primary' => true,
    ]);
    $psic = PsicCode::first();
    BusinessLine::create(['business_id' => $business->id, 'psic_code_id' => $psic->id, 'capitalization' => 150000]);

    $application = Application::create([
        'tracking_id' => 'BIZ-2026-9'.random_int(1000, 9999),
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => $type,
        'title' => $business->name,
        'status' => 'for_approval',
        'submitted_at' => now(),
        'data_privacy_consent' => true,
        'payment_mode' => 'annual',
        'has_amendments' => $type === 'amendment',
        'fee_profile' => [
            'floor_area_sqm' => 48, 'employees' => 5, 'male_employees' => 2, 'female_employees' => 3,
            'employees_in_lgu' => 4, 'capitalization' => 150000,
            'lines' => [['psic_code_id' => $psic->id, 'gross_sales' => 820000]],
        ],
    ]);

    $types = PermitType::whereIn('code', array_merge(['BUSINESS'], $codes))->get();
    foreach ($types as $permitType) {
        $application->permitTypes()->attach($permitType->id, ['status' => 'for_approval']);
    }
    assignOffice($application->id, 'BPLO');
    foreach ($types->where('code', '!=', 'BUSINESS') as $permitType) {
        assignOffice($application->id, $permitType->department->code);
    }
    foreach ($sheets as $code => $data) {
        ApplicationOfficeForm::create([
            'application_id' => $application->id,
            'permit_type_id' => $types->firstWhere('code', $code)->id,
            'form_data' => $data,
        ]);
    }

    return $application->fresh();
}

function pdfText(string $bytes): string
{
    return (new Parser)->parseContent($bytes)->getText();
}

it('prints a new filing on MCG-BPLO-FO-001 with the applicant answers filled in', function () {
    $app = printableFiling('new');

    authAs('bplo@biztrack.local');
    $res = $this->get("/api/v1/applications/{$app->id}/form-pdf")->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($res->getContent(), 0, 5))->toBe('%PDF-')
        ->and($res->headers->get('Content-Disposition'))->toContain('mcg-bplo-fo-001-'.$app->tracking_id);

    $text = pdfText($res->getContent());
    expect($text)->toContain('APPLICATION FOR NEW BUSINESS PERMIT')
        ->and($text)->toContain('MCG-BPLO-FO-001')
        ->and($text)->toContain('Paper Form Bakery')
        ->and($text)->toContain('ANNEX 1');
});

it('prints a renewal on the renewal paper, MCG-BPLO-FO-002', function () {
    $app = printableFiling('renewal');

    authAs('bplo@biztrack.local');
    $text = pdfText($this->get("/api/v1/applications/{$app->id}/form-pdf")->assertOk()->getContent());

    expect($text)->toContain('APPLICATION FOR RENEWAL OF BUSINESS PERMIT')
        ->and($text)->toContain('MCG-BPLO-FO-002')
        ->and($text)->toContain('Gross Sales/Receipts')
        ->and($text)->toContain('820,000.00');
});

it('prints an amendment on the BPLO Amendment Form with the requested change on its line', function () {
    $app = printableFiling('amendment');
    ApplicationAmendment::create(['application_id' => $app->id, 'field' => 'trade_name', 'old_value' => 'PF Breads', 'new_value' => 'Malabon Pandesal']);

    authAs('bplo@biztrack.local');
    $res = $this->get("/api/v1/applications/{$app->id}/form-pdf")->assertOk();
    $text = pdfText($res->getContent());

    expect($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($text)->toContain('AMENDMENT FORM')
        ->and($text)->toContain('CHANGE OF TRADE NAME')
        ->and($text)->toContain('Malabon Pandesal');
});

it('prints each office sheet the applicant filled in', function (string $code, array $data, string $heading) {
    $app = printableFiling('new', [$code], [$code => $data]);

    authAs('bplo@biztrack.local');
    $res = $this->get("/api/v1/applications/{$app->id}/office-forms/{$code}/pdf")->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(pdfText($res->getContent()))->toContain($heading);
})->with([
    'zoning' => ['ZONING', ['zoning_project_description' => 'Bakery and cafe'], 'APPLICATION FOR LOCATIONAL CLEARANCE'],
    'fsic' => ['FSIC', ['authorized_representative' => 'Jose Rizal'], 'FIRE SAFETY INSPECTION CERTIFICATE APPLICATION FORM'],
    'sanitary' => ['SANITARY', ['sanitary_classification' => 'Food Establishment', 'water_source' => 'Deep Well'], 'APPLICATION FOR SANITARY PERMIT TO OPERATE'],
    'cenro' => ['CEC', ['owner_address' => '3 Rizal St.', 'certified' => 'yes'], 'CERTIFICATE OF ENVIRONMENTAL CLEARANCE'],
    'occupancy' => ['OCCUPANCY', ['application_type' => 'Full', 'building_permit_no' => 'BP-77'], 'UNIFIED APPLICATION FORM FOR CERTIFICATE OF OCCUPANCY'],
]);

it('lets a clearance office print the business permit form and its own sheet, never another office\'s', function () {
    $app = printableFiling('new', ['SANITARY', 'FSIC'], [
        'SANITARY' => ['sanitary_classification' => 'Food Establishment'],
        'FSIC' => ['authorized_representative' => 'Jose Rizal'],
    ]);

    authAs('sanitary@biztrack.local');
    $this->get("/api/v1/applications/{$app->id}/form-pdf")->assertOk();
    $this->get("/api/v1/applications/{$app->id}/office-forms/SANITARY/pdf")->assertOk();
    $this->get("/api/v1/applications/{$app->id}/office-forms/FSIC/pdf")->assertForbidden();
});

it('refuses an office the filing was never routed to', function () {
    $app = printableFiling('new', ['SANITARY'], ['SANITARY' => ['water_source' => 'Deep Well']]);

    authAs('fire@biztrack.local');
    $this->get("/api/v1/applications/{$app->id}/form-pdf")->assertForbidden();
    $this->get("/api/v1/applications/{$app->id}/office-forms/SANITARY/pdf")->assertForbidden();
});

it('refuses the owner, who was not given this download', function () {
    $app = printableFiling('new', ['SANITARY'], ['SANITARY' => ['water_source' => 'Deep Well']]);

    authAs('owner@biztrack.local');
    $this->get("/api/v1/applications/{$app->id}/form-pdf")->assertForbidden();
    $this->get("/api/v1/applications/{$app->id}/office-forms/SANITARY/pdf")->assertForbidden();
});

it('answers 404 for a sheet the applicant has not filled in, or a clearance the filing does not carry', function () {
    $app = printableFiling('new', ['ZONING']);

    authAs('bplo@biztrack.local');
    $this->get("/api/v1/applications/{$app->id}/office-forms/ZONING/pdf")->assertNotFound();
    $this->get("/api/v1/applications/{$app->id}/office-forms/FSIC/pdf")->assertNotFound();
    $this->get("/api/v1/applications/{$app->id}/office-forms/BUSINESS/pdf")->assertNotFound();
});

it('refuses a draft, which is not a filed application yet', function () {
    $app = printableFiling('new');
    $app->update(['status' => 'draft']);

    authAs('bplo@biztrack.local');
    $this->get("/api/v1/applications/{$app->id}/form-pdf")->assertStatus(422);
});

it('writes every download to the audit log, naming the form', function () {
    $app = printableFiling('new', ['ZONING'], ['ZONING' => ['zoning_project_description' => 'Bakery']]);

    authAs('bplo@biztrack.local');
    $this->get("/api/v1/applications/{$app->id}/form-pdf")->assertOk();
    $this->get("/api/v1/applications/{$app->id}/office-forms/ZONING/pdf")->assertOk();

    $rows = AuditLog::where('action', 'application.form_downloaded')
        ->where('auditable_type', Application::class)
        ->where('auditable_id', $app->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->changes['form'])->toBe('MCG-BPLO-FO-001')
        ->and($rows[1]->changes['form'])->toBe('ZONING')
        ->and($rows[0]->user_id)->toBe(User::where('email', 'bplo@biztrack.local')->value('id'));
});

it('writes no audit row for a refused download', function () {
    $app = printableFiling('new');

    authAs('owner@biztrack.local');
    $this->get("/api/v1/applications/{$app->id}/form-pdf")->assertForbidden();

    expect(AuditLog::where('action', 'application.form_downloaded')->count())->toBe(0);
});

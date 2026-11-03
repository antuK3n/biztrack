<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationOfficeForm;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Department;
use App\Models\OfficeSignatory;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Support\Numbering;
use App\Support\PermitFace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/*
 * Checklist item 79 — the Profile page lists approved permits, View shows the
 * permit as its paper counterpart, and it saves as PDF.
 *
 * The certificate face needs fields PermitResource never carried (owner,
 * address, line of business, signature block), so `GET /permits/{id}` answers
 * them under `certificate` and `pdf` renders the same array. These tests hold
 * the two together: the screen and the download must not be able to disagree
 * about what the certificate says.
 *
 * The signature-block assertions exist because names on LGU forms are
 * admin-edited rows, never literals — see the create_office_signatories_table
 * migration. A test that only checked "a name is printed" would pass just as
 * happily against a hardcoded one, so these check that the printed name is the
 * row's, and that editing the row changes the output.
 */

/**
 * A new filing, paid, so its Business Permit exists and was signed by somebody.
 *
 * `PermitReleasedAtPaymentTest::paidNewFiling` does the same thing and more,
 * but a helper declared in a test file only exists once that file has loaded —
 * running this file alone found it undefined. The sequence is four calls, so
 * it is repeated here rather than promoted into Pest.php for one caller.
 */
function certificateFiling(): Application
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Signature Block '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Signatory Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 500000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);
    test()->withHeaders($owner)
        ->postJson("/api/v1/applications/{$appId}/pay", ['method' => 'gcash'])
        ->assertCreated();

    return Application::findOrFail($appId);
}

/** The seeded owner's first permit, with its issuing office. */
function ownersPermit(): Permit
{
    return Permit::whereHas('business', fn ($q) => $q->whereHas(
        'owner', fn ($o) => $o->where('email', 'owner@biztrack.local')
    ))->with('permitType.department')->firstOrFail();
}

it('answers the certificate fields the permit view prints', function () {
    $permit = ownersPermit();
    authAs('owner@biztrack.local');

    $cert = $this->getJson("/api/v1/permits/{$permit->id}")
        ->assertOk()
        // The list-row shape is still there; the certificate rides alongside it.
        ->assertJsonPath('data.permit_number', $permit->permit_number)
        ->json('data.certificate');

    expect($cert['owner_name'])->toBe($permit->business->owner->fullName())
        ->and($cert['business_name'])->toBe($permit->business->name)
        ->and($cert['permit_number'])->toBe($permit->permit_number)
        ->and($cert['verify_url'])->toContain($permit->permit_number)
        ->and($cert)->toHaveKeys([
            'trade_name', 'address', 'barangay', 'line_of_business',
            'valid_from', 'valid_until', 'tracking_id', 'signatories',
        ]);
});

it('prints the office signatory on file rather than a name in the template', function () {
    $permit = ownersPermit();

    /*
     * A role the OFFICE owns.
     *
     * This read 'Officer-in-Charge' until the certificate started carrying its
     * own, frozen at issue [client, 1 October 2026]. That role is no longer
     * the office's to fill — it names whoever signed this particular permit —
     * and the collision rule below asserts which of the two wins. The point of
     * this test is unchanged: an office's names are rows, not literals, and
     * editing the row changes what prints.
     */
    $signatory = OfficeSignatory::updateOrCreate(
        ['department_id' => $permit->permitType->issuing_department_id, 'role' => 'Division Chief'],
        ['name' => 'Aurora S. Bautista', 'sort_order' => 0, 'is_active' => true],
    );

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($cert['signatories'])->toContain(['role' => 'Division Chief', 'name' => 'Aurora S. Bautista']);

    authAs('owner@biztrack.local');
    $text = (new Parser)
        ->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent())
        ->getText();
    expect($text)->toContain('Aurora S. Bautista');

    // The officeholder rotates. Nothing but the row changes, and the next
    // download says the new name — which is the whole point of the table.
    $signatory->update(['name' => 'Ramon T. Villafuerte']);

    authAs('owner@biztrack.local');
    $text = (new Parser)
        ->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent())
        ->getText();
    expect($text)->toContain('Ramon T. Villafuerte')
        ->and($text)->not->toContain('Aurora S. Bautista');
});

/*
 * ── The Mayor and the officer in charge ──────────────────────────────────────
 *
 * *"Paki bago na rin ang generated na permits, include na rin don name of
 * mayor (Jeannie Sandoval) and officer in charge, sa lahat na yan ng permits"*
 * [client, 1 October 2026].
 *
 * The Mayor's NAME is data: the BPLO's `office_signatories` row with the role
 * "City Mayor" (PermitFace::mayorName), so a new mayor is one edit, not a code
 * change. The officer in charge is per PERMIT — the person holding the issuing
 * office's assignment on the filing. Both are frozen onto `issued_details` at
 * issue beside the business face, and these tests pin the things that can go
 * wrong with that: the wrong name, no name, and a name that keeps changing.
 */

it('takes the Mayor from the signatories, and keeps an issued permit’s Mayor when the row changes', function () {
    $row = OfficeSignatory::query()
        ->where('role', 'City Mayor')
        ->whereHas('department', fn ($q) => $q->where('code', 'BPLO'))
        ->firstOrFail();
    expect($row->name)->toBe('Hon. Jeannie Sandoval')
        ->and(PermitFace::mayorName())->toBe('Hon. Jeannie Sandoval');

    $permit = ownersPermit();
    $permit->update(['issued_details' => array_merge(
        $permit->issued_details ?? [],
        PermitFace::captureSignatories(null),
    )]);

    $this->artisan('biztrack:signatory', ['office' => 'BPLO', 'role' => 'City Mayor', 'name' => 'Hon. Next Mayor'])
        ->assertSuccessful();

    expect(PermitFace::mayorName())->toBe('Hon. Next Mayor')
        ->and(PermitFace::captureSignatories(null)['mayor_name'])->toBe('Hon. Next Mayor');

    // The permit already signed keeps the Mayor who signed it.
    authAs('owner@biztrack.local');
    $roles = array_column(
        $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate.signatories'),
        'name',
        'role',
    );
    expect($roles['City Mayor'])->toBe('Hon. Jeannie Sandoval');
    expect(AuditLog::where('action', 'signatory.updated')->count())->toBe(1);
});

it('prints the Mayor and the filing’s officer in charge on every permit', function () {
    $permit = ownersPermit();
    authAs('owner@biztrack.local');

    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    $roles = array_column($cert['signatories'], 'name', 'role');

    expect($roles['City Mayor'])->toBe('Hon. Jeannie Sandoval');

    /*
     * Whoever holds the issuing office's assignment — asserted against the
     * register rather than against a name typed here, so the test still means
     * something when the seeded officer changes.
     *
     * Not `issued_by_user_id`: the Business Permit is released at payment, so
     * that column holds the APPLICANT on exactly the certificate the client
     * most wanted a signature on. A test comparing against it would have
     * passed while the permit printed its owner as its officer.
     */
    $expected = ApplicationAssignment::where('application_id', $permit->application_id)
        ->where('department_id', $permit->permitType->issuing_department_id)
        ->whereNotNull('officer_user_id')
        ->latest('id')
        ->first()?->officer?->fullName();

    expect($roles)->toHaveKey('Officer-in-Charge')
        ->and($roles['Officer-in-Charge'])->toBe($expected)
        ->and($roles['Officer-in-Charge'])->not->toBe($permit->business->owner->fullName());
});

it('freezes the signatories at issue, so a reassignment cannot re-sign an old permit', function () {
    /*
     * The same argument as the business face, applied to the people. A permit
     * says who granted it; reassigning the caseload afterwards changes who
     * handles the NEXT one, and must not rewrite a certificate already in the
     * applicant's hands.
     */
    $app = certificateFiling();
    $permit = Permit::where('application_id', $app->id)
        ->whereHas('permitType', fn ($q) => $q->where('code', PermitType::OUTCOME_CODE))
        ->firstOrFail();

    $signedBy = $permit->issued_details['officer_in_charge'] ?? null;
    expect($signedBy)->not->toBeNull()
        ->and($permit->issued_details['mayor_name'])->toBe('Hon. Jeannie Sandoval');

    // Hand the office's assignment to somebody else, after the fact.
    ApplicationAssignment::where('application_id', $app->id)
        ->where('department_id', $permit->permitType->issuing_department_id)
        ->update(['officer_user_id' => User::where('email', 'sanitary@biztrack.local')->firstOrFail()->id]);

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect(array_column($cert['signatories'], 'name', 'role')['Officer-in-Charge'])->toBe($signedBy);
});

it('prints a ruled line, not a guess, when no office has taken the filing', function () {
    /*
     * A permit with nobody assigned to its issuing office has no officer to
     * name. Null travels all the way to the view, which draws the line without
     * a name over it — the same thing the paper does when it is waiting for a
     * wet signature.
     */
    $permit = ownersPermit();
    $permit->update([
        'issued_details' => array_merge($permit->issued_details ?? [], [
            'mayor_name' => 'Hon. Jeannie Sandoval',
            'officer_in_charge' => null,
        ]),
    ]);
    ApplicationAssignment::where('application_id', $permit->application_id)->delete();

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    $roles = array_column($cert['signatories'], 'name', 'role');
    expect($roles)->toHaveKey('Officer-in-Charge')
        ->and($roles['Officer-in-Charge'])->toBeNull()
        ->and($roles['City Mayor'])->toBe('Hon. Jeannie Sandoval');
});

it('does not print the role twice when an office configures the same caption', function () {
    /*
     * The two sources meet here. `office_signatories` can hold any role an
     * office types, including one the certificate already carries, and the
     * frozen name wins: it says who signed THIS permit, where the row says who
     * holds the post today. Without the rule the sheet grows a second
     * Officer-in-Charge line naming a different person.
     */
    $permit = ownersPermit();

    OfficeSignatory::updateOrCreate(
        ['department_id' => $permit->permitType->issuing_department_id, 'role' => 'Officer-in-Charge'],
        ['name' => 'Someone Else Entirely', 'sort_order' => 0, 'is_active' => true],
    );

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    $captions = array_column($cert['signatories'], 'role');
    expect(array_count_values($captions)['Officer-in-Charge'])->toBe(1)
        ->and(array_column($cert['signatories'], 'name'))->not->toContain('Someone Else Entirely');
});

/*
 * Read as an admin, not the owner. Once the business is soft-deleted the
 * owner's own route to the permit closes with it — `index` scopes through
 * `whereHas('business')` and `authorizeView` matches on `business->owner_user_id`
 * — so the permit simply stops being listed for them, consistently. The reader
 * who still reaches it is the register-wide one, and that is where a null
 * business would otherwise crash the render.
 */
it('renders a certificate whose business was removed from the register', function () {
    $permit = ownersPermit();

    // Business soft-deletes; its issued permits stay on the register. This is
    // the shape that crashed three officer screens (RemovedBusinessRendering).
    Business::findOrFail($permit->business_id)->delete();

    authAs('admin@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")
        ->assertOk()
        ->json('data.certificate');

    // Null, not a stand-in name: "removed" has to stay tellable from "named".
    expect($cert['business_name'])->toBeNull()
        ->and($cert['owner_name'])->toBeNull()
        ->and($cert['permit_number'])->toBe($permit->permit_number);

    authAs('admin@biztrack.local');
    $bytes = $this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent();
    $text = (new Parser)->parseContent($bytes)->getText();

    expect(substr($bytes, 0, 5))->toBe('%PDF-')
        ->and($text)->toContain('Business removed from register');
});

/*
 * ── The BFP's Fire Safety Inspection Certificate ────────────────────────────
 *
 * Its own sheet, laid out from the issued BFP-QSF-FSED-005 the client sent
 * [4 October 2026]: portrait Letter, the FSIC NO. being this system's permit
 * number, the Fire Code fee line, and two signatures — Recommend Approval by
 * the Chief, Fire Safety Enforcement Section, and Approved by the City Fire
 * Marshal — with no Mayor on it.
 */
function fsicPermit(): Permit
{
    $seed = ownersPermit();

    return Permit::create([
        'permit_number' => 'FSIC-TEST-'.random_int(100000, 999999),
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => PermitType::where('code', 'FSIC')->value('id'),
        'status' => $seed->status,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);
}

it('draws the FSIC as the BFP issues it, signed by the fire office and not the Mayor', function () {
    $permit = fsicPermit();
    $bfp = PermitType::where('code', 'FSIC')->value('issuing_department_id');
    OfficeSignatory::updateOrCreate(
        ['department_id' => $bfp, 'role' => 'City Fire Marshal'],
        ['name' => 'Eduardo R. Lacson', 'sort_order' => 1, 'is_active' => true],
    );

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($cert['is_fsic'])->toBeTrue()
        // Always the Business Permit box [client, 5 October 2026].
        ->and($cert['fsic_purpose'])->toBe('business')
        ->and($cert['fsic_valid_for'])->toBe('Issuance of FSIC for Business Permit only')
        ->and(array_column($cert['signatories'], 'role'))
        ->toBe(['Chief, Fire Safety Enforcement Section', 'City Fire Marshal'])
        ->and($cert['signatories'][1]['name'])->toBe('Eduardo R. Lacson')
        // No name on file for the Chief: a ruled line, not a guess.
        ->and($cert['signatories'][0]['name'])->toBeNull();

    authAs('owner@biztrack.local');
    $pdf = (new Parser)->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent());
    $text = $pdf->getText();

    expect($pdf->getPages())->toHaveCount(1)
        ->and($text)->toContain('FIRE SAFETY INSPECTION CERTIFICATE')
        ->and($text)->toContain($permit->permit_number)
        // (The justified paragraphs space their words apart, which the parser drops.)
        ->and($text)->toContain('TO WHOM IT MAY CONCERN')
        ->and($text)->toContain('Fire Code Fees')
        ->and($text)->toContain('EDUARDO R. LACSON')
        ->and($text)->not->toContain('City Mayor');

    // Portrait US Letter, as the Bureau's sheet is.
    $box = $pdf->getPages()[0]->getDetails()['MediaBox'];
    expect([(int) round($box[2]), (int) round($box[3])])->toBe([612, 792]);
});

/*
 * ── The CPDO's Zoning Clearance ─────────────────────────────────────────────
 *
 * Laid out from the issued sheet [client, 5 October 2026]: For Business
 * Permit, the ZONING PERMIT NO. being this system's permit number, the
 * decision and the four conditions, and one signature — the City Planning &
 * Development Coordinator / Zoning Administrator — with no Mayor on it.
 */
it('draws the Zoning Clearance as the CPDO issues it, signed by its administrator', function () {
    $seed = ownersPermit();
    $permit = Permit::create([
        'permit_number' => 'LC-TEST-'.random_int(100000, 999999),
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => PermitType::where('code', 'ZONING')->value('id'),
        'status' => $seed->status,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);
    OfficeSignatory::updateOrCreate(
        ['department_id' => PermitType::where('code', 'ZONING')->value('issuing_department_id'), 'role' => 'Zoning Administrator'],
        ['name' => 'Roberto F. Santos', 'sort_order' => 1, 'is_active' => true],
    );

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($cert['is_zoning'])->toBeTrue()
        ->and($cert['signatories'])->toBe([[
            'role' => "City Planning & Dev't Coordinator / Zoning Administrator",
            'name' => 'Roberto F. Santos',
        ]]);

    authAs('owner@biztrack.local');
    $pdf = (new Parser)->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent());
    $text = $pdf->getText();

    expect($pdf->getPages())->toHaveCount(1)
        ->and($text)->toContain('ZONING CLEARANCE')
        ->and($text)->toContain('(For Business Permit)')
        // The Business Account Number, not the BIZ- tracking ID [client, 5 October 2026].
        ->and($text)->toContain((string) $permit->business->ban)
        ->and($text)->not->toContain((string) $permit->application->tracking_id)
        ->and($text)->toContain($permit->permit_number)
        ->and($text)->toContain('ZONING CLEARANCE GRANTED')
        ->and($text)->toContain('ROBERTO F. SANTOS')
        ->and($text)->not->toContain('City Mayor');

    $box = $pdf->getPages()[0]->getDetails()['MediaBox'];
    expect([(int) round($box[2]), (int) round($box[3])])->toBe([612, 792]);
});

/*
 * ── The CHO's Sanitary Permit to Operate ────────────────────────────────────
 *
 * Laid out from the issued sheet [client, 5 October 2026]: issued to the
 * registered name, the SANITARY PERMIT NO. being this system's permit
 * number, the date of expiration, the non-transferable clause, and two
 * signatures — Recommending Approval by the Sanitary Inspector, Approved by
 * the City Health Officer — with no Mayor on it.
 */
it('draws the Sanitary Permit to Operate as the CHO issues it', function () {
    $seed = ownersPermit();
    $permit = Permit::create([
        'permit_number' => 'HC-TEST-'.random_int(100000, 999999),
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => PermitType::where('code', 'SANITARY')->value('id'),
        'status' => $seed->status,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);
    OfficeSignatory::updateOrCreate(
        ['department_id' => PermitType::where('code', 'SANITARY')->value('issuing_department_id'), 'role' => 'City Health Officer'],
        ['name' => 'Maria L. Reyes, MD', 'sort_order' => 1, 'is_active' => true],
    );

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($cert['is_sanitary'])->toBeTrue()
        ->and(array_column($cert['signatories'], 'role'))->toBe(['Sanitary Inspector', 'City Health Officer'])
        ->and(array_column($cert['signatories'], 'action'))->toBe(['Recommending Approval', 'Approved'])
        ->and($cert['signatories'][1]['name'])->toBe('Maria L. Reyes, MD')
        ->and($cert['signatories'][0]['name'])->toBeNull();

    authAs('owner@biztrack.local');
    $pdf = (new Parser)->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent());
    $text = $pdf->getText();

    expect($pdf->getPages())->toHaveCount(1)
        ->and($text)->toContain('SANITARY PERMIT TO OPERATE')
        ->and($text)->toContain($permit->permit_number)
        ->and($text)->toContain('Date of Expiration')
        ->and($text)->toContain('MARIA L. REYES, MD')
        ->and($text)->not->toContain('City Mayor');

    $box = $pdf->getPages()[0]->getDetails()['MediaBox'];
    expect([(int) round($box[2]), (int) round($box[3])])->toBe([612, 792]);
});

/*
 * ── Malabon's Certificate of Occupancy (NBC Form B-13) ──────────────────────
 *
 * The City's own two-page spread [client, 5 October 2026], on landscape
 * Letter: No. is the permit number, the building permit number and date are
 * the OBO's own entries, the Locational/Zoning line carries the Zoning
 * Clearance issued on the same filing, and the Building Official signs.
 */
it('draws the Certificate of Occupancy on Malabon’s NBC Form B-13', function () {
    $seed = ownersPermit();
    $occupancy = PermitType::where('code', 'OCCUPANCY')->firstOrFail();
    $permit = Permit::create([
        'permit_number' => 'OP-TEST-'.random_int(100000, 999999),
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => $occupancy->id,
        'status' => $seed->status,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);
    $zoning = Permit::create([
        'permit_number' => 'LC-TEST-'.random_int(100000, 999999),
        'application_id' => $seed->application_id,
        'business_id' => $seed->business_id,
        'permit_type_id' => PermitType::where('code', 'ZONING')->value('id'),
        'status' => $seed->status,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now()->addMinute(),
    ]);
    ApplicationOfficeForm::updateOrCreate(
        ['application_id' => $seed->application_id, 'permit_type_id' => $occupancy->id],
        ['form_data' => ['building_permit_no' => 'BP-MLB-2024-0318', 'building_permit_date' => '2024-03-18', 'project_name' => 'Two-storey Commercial Building']],
    );
    OfficeSignatory::updateOrCreate(
        ['department_id' => $occupancy->issuing_department_id, 'role' => 'Building Official'],
        ['name' => 'Engr. Carlos M. Dela Paz', 'sort_order' => 1, 'is_active' => true],
    );

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($cert['is_occupancy'])->toBeTrue()
        ->and($cert['occ_building_permit_no'])->toBe('BP-MLB-2024-0318')
        ->and($cert['occ_building_permit_date'])->toBe('March 18, 2024')
        ->and($cert['occ_project'])->toBe('Two-storey Commercial Building')
        ->and($cert['occ_zoning_no'])->toBe($zoning->permit_number)
        ->and($cert['signatories'])->toBe([['role' => 'Building Official', 'name' => 'Engr. Carlos M. Dela Paz']]);

    authAs('owner@biztrack.local');
    $pdf = (new Parser)->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent());
    $text = $pdf->getText();

    expect($pdf->getPages())->toHaveCount(1)
        ->and($text)->toContain('CERTIFICATE OF OCCUPANCY')
        ->and($text)->toContain('NBC FORM NO. B-13')
        ->and($text)->toContain($permit->permit_number)
        ->and($text)->toContain('BP-MLB-2024-0318')
        ->and($text)->toContain($zoning->permit_number)
        ->and($text)->toContain('ENGR. CARLOS M. DELA PAZ')
        ->and($text)->not->toContain('City Mayor');

    // Landscape US Letter: the two pages side by side.
    $box = $pdf->getPages()[0]->getDetails()['MediaBox'];
    expect([(int) round($box[2]), (int) round($box[3])])->toBe([792, 612]);
});

/*
 * ── The Official Receipt on the paper ───────────────────────────────────────
 *
 * Four sheets print an OR line with a date beside it: the Mayor's Permit, the
 * CENRO certificate, the FSIC and the Certificate of Occupancy. On 6 October
 * 2026 the line was made always blank ("The system should not be able to
 * generate an OR"), because the only number BizTrack then held was its own
 * payment reference, and printing it made the system the issuer of an OR.
 *
 * Mark as paid now records the OR the City Treasurer actually issued, so a
 * counter payment prints it and an online payment, which has none, leaves the
 * line blank (Ken, 3 November 2026). The test that held the old rule ("leaves
 * the OR number blank, because only the City Treasurer issues an Official
 * Receipt") was rewritten into the online case below.
 */

/** A new filing BPLO marked paid at the counter under `$or`, dated `$orDate`; answers its Mayor's Permit. */
function counterPaidMayorsPermit(string $or, string $orDate): Permit
{
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $owner = authAs('owner@biztrack.local');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', PermitType::OUTCOME_CODE)->value('id')],
    ])->assertCreated()->json('data.id');

    attachRequiredDocuments($appId);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
    bploApprovesForm($appId);

    // BPLO takes the filing, the owner sends the receipt, BPLO marks it paid against it.
    authAs('bplo@biztrack.local');
    $held = ApplicationAssignment::where('application_id', $appId)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();
    test()->postJson("/api/v1/assignments/{$held->id}/claim")->assertOk();

    $receipt = ['or_number' => $or, 'or_date' => $orDate];
    authAs('owner@biztrack.local');
    test()->post("/api/v1/applications/{$appId}/payment-proofs", $receipt, ['Accept' => 'application/json'])
        ->assertCreated();
    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/applications/{$appId}/counter-payment", $receipt)->assertCreated();

    // A new filing's Mayor's Permit is released at payment.
    return Permit::where('application_id', $appId)
        ->whereHas('permitType', fn ($t) => $t->where('code', PermitType::OUTCOME_CODE))
        ->firstOrFail();
}

/** Another of the filing's certificates, as its office would issue it. */
function counterPaidSibling(Permit $mayors, string $code): Permit
{
    return Permit::create([
        'permit_number' => $code.'-OR-'.random_int(100000, 999999),
        'application_id' => $mayors->application_id,
        'business_id' => $mayors->business_id,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => $mayors->status,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);
}

it('prints the OR and the date on the receipt on the Mayor’s Permit of a filing paid at the counter', function () {
    Storage::fake('local');
    $orDate = now()->subDays(3)->toDateString();
    $permit = counterPaidMayorsPermit('7031415', $orDate);
    $onReceipt = Carbon::parse($orDate)->format('F j, Y');

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    // The date on the receipt, which BPLO typed in; not the day it pressed Mark as paid.
    expect($cert['or_number'])->toBe('7031415')
        ->and($cert['date_paid'])->toBe($onReceipt)
        ->and($onReceipt)->not->toBe(now()->format('F j, Y'));

    authAs('owner@biztrack.local');
    $text = (new Parser)->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent())->getText();

    expect($text)->toContain('7031415')
        ->and($text)->toContain($onReceipt);
});

it('prints the same OR on the filing’s CENRO certificate, FSIC and Certificate of Occupancy', function () {
    Storage::fake('local');
    $orDate = now()->subDay()->toDateString();
    $mayors = counterPaidMayorsPermit('7031416', $orDate);
    $onReceipt = Carbon::parse($orDate)->format('F j, Y');

    foreach (['CEC', 'FSIC', 'OCCUPANCY'] as $code) {
        $permit = counterPaidSibling($mayors, $code);

        authAs('owner@biztrack.local');
        $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');
        expect($cert['or_number'])->toBe('7031416', $code)
            ->and($cert['date_paid'])->toBe($onReceipt, $code);

        authAs('owner@biztrack.local');
        $text = (new Parser)->parseContent($this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk()->getContent())->getText();
        expect($text)->toContain('7031416');
    }
});

it('leaves the OR blank on a permit paid online, which no Official Receipt was issued for', function () {
    Storage::fake('local');
    $filing = certificateFiling();
    $permit = Permit::where('application_id', $filing->id)
        ->whereHas('permitType', fn ($t) => $t->where('code', PermitType::OUTCOME_CODE))
        ->firstOrFail();
    $paid = Payment::where('application_id', $filing->id)->sole();

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($paid->method)->toBe(PaymentMethod::Gcash)
        ->and($cert)->toHaveKey('or_number')
        ->and($cert['or_number'])->toBeNull()
        // The line beside it still says when the money landed.
        ->and($cert['date_paid'])->toBe($paid->paid_at->format('F j, Y'));
});

it('prints the OR of the payment that settled the bill, not an online order that went through after it', function () {
    Storage::fake('local');
    $orDate = now()->subDays(2)->toDateString();
    $permit = counterPaidMayorsPermit('7031417', $orDate);
    $counter = Payment::where('application_id', $permit->application_id)->sole();

    /*
     * The KwikPay order Mark as paid set aside, paid by the owner anyway an
     * hour later: `catchDoublePayment` books it Completed and flags it for a
     * refund. It paid for nothing the bill still owed.
     */
    Payment::create([
        'application_id' => $permit->application_id,
        'fee_assessment_id' => $counter->fee_assessment_id,
        'reference_number' => Numbering::paymentReference(),
        'amount' => $counter->amount,
        'method' => PaymentMethod::Qrph,
        'status' => PaymentStatus::Completed,
        'gateway' => Payment::GATEWAY_KWIKPAY,
        'paid_at' => now()->addHour(),
        'abandoned_at' => now(),
        'refund_review_at' => now()->addHour(),
    ]);

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($cert['or_number'])->toBe('7031417')
        ->and($cert['date_paid'])->toBe(Carbon::parse($orDate)->format('F j, Y'))
        ->and($cert['amount_paid'])->toBe('₱'.number_format((float) $counter->amount, 2));
});

it('prints the first OR alone when a later counter payment settled a balance on the same bill', function () {
    Storage::fake('local');
    $firstDate = now()->subDays(5)->toDateString();
    $permit = counterPaidMayorsPermit('7031418', $firstDate);
    $first = Payment::where('application_id', $permit->application_id)->sole();

    /*
     * Nothing raises a balance after payment today — one bill covers every
     * permit on the filing — but the old two-payment design did, and the
     * payments ledger allows it. The later receipt paid for fees added after
     * the Mayor's Permit was released under the first.
     */
    Payment::create([
        'application_id' => $permit->application_id,
        'fee_assessment_id' => $first->fee_assessment_id,
        'reference_number' => Numbering::paymentReference(),
        'amount' => 500,
        'method' => PaymentMethod::Counter,
        'status' => PaymentStatus::Completed,
        'paid_at' => now()->addDay(),
        'or_number' => '7031420',
        'or_date' => now()->subDay()->toDateString(),
    ]);

    authAs('owner@biztrack.local');
    $cert = $this->getJson("/api/v1/permits/{$permit->id}")->assertOk()->json('data.certificate');

    expect($cert['or_number'])->toBe('7031418')
        ->and($cert['date_paid'])->toBe(Carbon::parse($firstDate)->format('F j, Y'))
        ->and($cert['amount_paid'])->toBe('₱'.number_format((float) $first->amount, 2));
});

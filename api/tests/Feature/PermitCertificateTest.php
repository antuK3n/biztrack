<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\OfficeSignatory;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Support\PermitFace;
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

<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationPermitType;
use App\Models\Business;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\HeldPermits;
use App\Support\ZoningRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * MCG-CPDD-FO-003 v1.2's CHECKLIST OF REQUIREMENTS.
 *
 * The paper's list branches on how the applicant holds the site, and that
 * branch is the whole reason this is tested rather than read: an owner shown
 * the lease rows is being asked for a contract that does not exist, and a
 * lessee shown the title rows is being asked for a deed that is not theirs.
 * Both are unclearable, and both look like an ordinary missing document.
 *
 * The second thing under test is the collision that nearly shipped. Checklist
 * uploads and the "certificate you already hold" mechanism both attach files to
 * one filing under one clearance, and `HeldPermits` deletes every other row for
 * its permit on each upload. Had the checklist set `permit_type_id` too, each
 * new attachment would have silently deleted the applicant's certificate off
 * disk. The last test here is what stops that being reintroduced.
 */

/** A paid filing carrying the zoning clearance, its sheet not yet submitted. */
function zoningFiling(bool $rented): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();
    $business->update([
        'is_rented' => $rented,
        'lessor_name' => $rented ? 'Acme Realty Corp.' : null,
        'lessor_address' => $rented ? '10 Gov. Pascual Ave., Malabon City' : null,
    ]);

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);

    $workflow = app(WorkflowService::class);
    $workflow->submit($app);
    $app->refresh();
    classifyAsOfficer($app);
    $workflow->approveMainForm($app->fresh());
    $app->refresh();
    $workflow->transition($app, ApplicationStatus::AwaitingOtherPermits, 'Paid.');

    $workflow->startClearance(
        $app->fresh(),
        PermitType::where('code', 'ZONING')->firstOrFail(),
        ApplicationPermitType::MODE_APPLY,
    );

    return $app->fresh();
}

/** The labels of the rows the checklist offers, in order. */
function checklistLabels(Application $app): array
{
    return array_column(ZoningRequirements::forApplication($app), 'label');
}

it('asks an owner for the title, tax declaration and RPT clearance', function () {
    $labels = checklistLabels(zoningFiling(rented: false));

    expect($labels)->toContain('Transfer Certificate of Title (TCT)')
        ->toContain('Tax Declaration')
        ->toContain('Real Property Tax Clearance')
        // The paper asks these two "if the property is rented", and it is not.
        ->not->toContain('Contract of Lease')
        ->not->toContain('Consent from the Lot Owner');
});

it('asks a lessee for the lease and the lot owner’s consent instead', function () {
    $labels = checklistLabels(zoningFiling(rented: true));

    expect($labels)->toContain('Contract of Lease')
        ->toContain('Consent from the Lot Owner')
        ->not->toContain('Transfer Certificate of Title (TCT)')
        ->not->toContain('Tax Declaration')
        ->not->toContain('Real Property Tax Clearance');
});

it('carries the sketch and the authorisation from BPLO rather than asking again', function () {
    /*
     * MCG-BPLO-FO-001's documentary requirements list asks for both — item 5
     * "Sketch and photos of location of business" and item 6 the SPA — so they
     * are collected there and this sheet marks them carried. Before 16
     * September 2026 the sketch was an upload slot HERE and the BPLO list did
     * not ask at all, which meant CPDD held a sketch BPLO had never seen.
     *
     * Pinned because a carried row is a CLAIM about another screen: it renders
     * "attached with your business permit documents" and offers no upload. If
     * BPLO's list stops asking, these two rows become unsatisfiable with no
     * way to fix them — which is exactly what happened to the title and lease
     * rows when LEASE_TITLE was detached, and went unnoticed because nothing
     * failed loudly.
     */
    $app = zoningFiling(rented: false);

    $row = fn (string $key) => collect(ZoningRequirements::forApplication($app->fresh()))
        ->firstWhere('key', $key);

    foreach (['SKETCH', 'AUTHORIZATION', 'DTI_SEC', 'TCT'] as $key) {
        $found = $row($key);
        if ($found === null) {
            continue; // AUTHORIZATION only appears when a representative is named
        }
        expect($found['source'])->toBe('carried')
            ->and($found['code'])->toBeNull();
    }

    // And the slots that genuinely have nowhere else to come from still take a
    // file, so the sheet is not left asking for nothing.
    expect(ZoningRequirements::accepts('ZONING_REQ_TAX_DECLARATION'))->toBeTrue()
        ->and(ZoningRequirements::accepts('ZONING_REQ_DECLARATION'))->toBeTrue()
        // No longer a slot here — BPLO collects it.
        ->and(ZoningRequirements::accepts('ZONING_REQ_SKETCH'))->toBeFalse();
});

it('asks everyone for the sketch, the notarised declaration and the registration', function () {
    foreach ([true, false] as $rented) {
        $labels = checklistLabels(zoningFiling($rented));

        expect($labels)->toContain('Sketch of the Location')
            ->toContain('Applicant Declaration, notarised')
            ->toContain('DTI / SEC Articles of Incorporation')
            ->toContain('Completely filled-up application form');
    }
});

it('makes the notarised declaration the one row that blocks a submission', function () {
    /*
     * The client, 17 September 2026: *"I wonder how I was able to submit the
     * Locational Clearance without submitting the Applicant Declaration."*
     *
     * They could because this checklist was a counter list with no gate in it,
     * which is right for every row but one. The paper marks this row alone in
     * capitals — MUST BE NOTARIZED PRIOR TO SUBMISSION OF APPLICATION — and
     * the flag is asserted HERE rather than in the browser because both the
     * applicant's submit button and CPDD's review screen read it. A rule
     * private to one of two consumers is the defect this class exists to avoid.
     *
     * Asserted as "exactly one", not "this one is true". A later row that
     * quietly arrives blocking would shut the submit button on a document the
     * applicant is still chasing from another office, and that failure looks
     * from the outside like the form being broken.
     */
    foreach ([true, false] as $rented) {
        $rows = ZoningRequirements::forApplication(zoningFiling($rented));

        $blocking = collect($rows)->filter(fn (array $row) => $row['blocking'] ?? false);

        expect($blocking->pluck('label')->all())->toBe(['Applicant Declaration, notarised']);

        // And it is a row a file can actually be put into, or the gate would be
        // one the applicant has no way to clear.
        expect($blocking->first()['source'])->toBe('upload');
        expect($blocking->first()['code'])->not->toBeNull();
        expect($blocking->first()['satisfied'])->toBeFalse();
    }
});

it('asks for an authorization letter only when a representative is named', function () {
    /*
     * Written on the FSIC sheet, not the zoning one, and that is the point.
     * Item IX is asked once: the BFP sheet owns the question whenever the
     * filing carries FSIC, and the zoning sheet carries the answer read-only.
     * A checklist reading the zoning payload raw would see the empty string
     * `derive` left there and never ask for the letter — so this drives it the
     * way a real filing does.
     */
    $app = zoningFiling(rented: false);
    $owner = authAs('owner@biztrack.local');

    expect(checklistLabels($app))->not->toContain('Authorization Letter');

    $this->withHeaders($owner)
        ->putJson("/api/v1/applications/{$app->id}/office-forms/FSIC", [
            'form_data' => ['authorized_representative' => 'Ana R. Bautista'],
        ])->assertOk();

    expect(checklistLabels($app->fresh()))->toContain('Authorization Letter');
});

it('counts a business-permit attachment as the row it already answers', function () {
    $app = zoningFiling(rented: true);

    $row = fn (string $key) => collect(ZoningRequirements::forApplication($app->fresh()))
        ->firstWhere('key', $key);

    // Nothing attached at step 4 yet, so the carried rows are outstanding —
    // and they are carried rather than uploadable either way.
    expect($row('DTI_SEC')['source'])->toBe('carried')
        ->and($row('DTI_SEC')['satisfied'])->toBeFalse()
        ->and($row('DTI_SEC')['code'])->toBeNull();

    ApplicationDocument::create([
        'application_id' => $app->id,
        'document_type_id' => DocumentType::where('code', 'DTI_SEC_CDA')->value('id'),
        'original_filename' => 'dti-certificate.pdf',
        'stored_path' => 'private/documents/x/dti.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
    ]);

    expect($row('DTI_SEC')['satisfied'])->toBeTrue()
        ->and($row('DTI_SEC')['document']['filename'])->toBe('dti-certificate.pdf');
});

it('takes a file into a checklist slot and gives it back on the sheet', function () {
    Storage::fake('local');
    $app = zoningFiling(rented: false);
    $owner = authAs('owner@biztrack.local');

    $response = $this->withHeaders($owner)->post(
        "/api/v1/applications/{$app->id}/office-forms/ZONING/requirements/ZONING_REQ_TAX_DECLARATION",
        ['file' => UploadedFile::fake()->create('tax-declaration.pdf', 40, 'application/pdf')],
    )->assertCreated();

    $row = collect($response->json('data.requirements'))->firstWhere('key', 'TAX_DECLARATION');
    expect($row['satisfied'])->toBeTrue()
        ->and($row['document']['filename'])->toBe('tax-declaration.pdf');

    // And it is on the filing as an ordinary document, so the office's own
    // attachment list carries it without knowing this checklist exists.
    $document = ApplicationDocument::where('application_id', $app->id)
        ->whereHas('documentType', fn ($q) => $q->where('code', 'ZONING_REQ_TAX_DECLARATION'))
        ->firstOrFail();
    Storage::disk('local')->assertExists($document->stored_path);

    $this->withHeaders($owner)->deleteJson(
        "/api/v1/applications/{$app->id}/office-forms/ZONING/requirements/ZONING_REQ_TAX_DECLARATION",
    )->assertOk();

    expect(ApplicationDocument::whereKey($document->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($document->stored_path);
});

it('hands over Section X as a printable page, on the zoning sheet only', function () {
    $app = zoningFiling(rented: false);
    $owner = authAs('owner@biztrack.local');

    $response = $this->withHeaders($owner)
        ->get("/api/v1/applications/{$app->id}/office-forms/ZONING/declaration")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');

    /*
     * The sworn text, verbatim off MCG-CPDD-FO-003 v1.2. A template whose
     * wording has drifted from the controlled document is worse than no
     * template: the applicant pays a notary to swear to the wrong words, and
     * finds out at the counter. dompdf's stream is compressed, so this asserts
     * on the rendered view rather than the bytes.
     */
    $html = view('pdf.zoning-declaration', [
        'tracking_id' => $app->tracking_id,
        'business_name' => 'Dela Cruz Hardware',
    ])->render();

    expect($html)
        ->toContain('That I have caused the')
        ->toContain('are true and correct to')
        ->toContain('Subscribed and sworn before me this')
        ->toContain('Signature over Printed Name')
        ->toContain('Position/Title')
        ->toContain('Book No.')
        // The paper's own typo. Correcting it would make our page disagree with
        // the LGU's controlled document — see the note in the view.
        ->toContain('share said date to the national government')
        // The two identification lines, above the sworn text and outside it.
        ->toContain($app->tracking_id)
        ->toContain('Dela Cruz Hardware');

    /*
     * And nothing else. The client stripped the masthead, the document-control
     * table, the section number and the how-to box on 9 September 2026 —
     * *"there are unnecessary stuf that doesn't need to be there"* — and the
     * failure mode if they creep back is not a broken page but a fragment
     * wearing the letterhead of a controlled document it is not.
     */
    expect($html)
        ->not->toContain('X. APPLICANT DECLARATION')
        ->not->toContain('CITY PLANNING AND DEVELOPMENT DEPARTMENT')
        ->not->toContain('Document ID')
        ->not->toContain('HOW TO USE THIS PAGE')
        ->not->toContain('controlled document');

    $this->withHeaders($owner)
        ->getJson("/api/v1/applications/{$app->id}/office-forms/CEC/declaration")
        ->assertNotFound();
});

it('refuses a slot the paper does not have, and a sheet with no checklist', function () {
    $app = zoningFiling(rented: false);
    $owner = authAs('owner@biztrack.local');

    $this->withHeaders($owner)->post(
        "/api/v1/applications/{$app->id}/office-forms/ZONING/requirements/ZONING_REQ_PASSPORT",
        ['file' => UploadedFile::fake()->create('anything.pdf', 10, 'application/pdf')],
    )->assertNotFound();

    $this->withHeaders($owner)->post(
        "/api/v1/applications/{$app->id}/office-forms/CEC/requirements/ZONING_REQ_TAX_DECLARATION",
        ['file' => UploadedFile::fake()->create('anything.pdf', 10, 'application/pdf')],
    )->assertNotFound();
});

it('does not let a checklist upload delete the certificate the applicant holds', function () {
    /*
     * The collision `HeldPermits` was rewritten to prevent. Both files hang off
     * the same filing and the same clearance; only one of them is a held copy,
     * and `forgetAllExcept` must not reach past its own kind.
     */
    Storage::fake('local');
    $app = zoningFiling(rented: false);
    $owner = authAs('owner@biztrack.local');
    $zoning = PermitType::where('code', 'ZONING')->firstOrFail();

    $held = HeldPermits::store(
        $app,
        $zoning,
        UploadedFile::fake()->create('existing-clearance.pdf', 20, 'application/pdf'),
    );

    $this->withHeaders($owner)->post(
        "/api/v1/applications/{$app->id}/office-forms/ZONING/requirements/ZONING_REQ_TAX_DECLARATION",
        ['file' => UploadedFile::fake()->create('tax-declaration.pdf', 40, 'application/pdf')],
    )->assertCreated();

    expect(ApplicationDocument::whereKey($held->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists($held->fresh()->stored_path);
    expect(HeldPermits::find($app->fresh(), $zoning)?->id)->toBe($held->id);
});

<?php

use App\Models\Business;
use App\Models\DocumentType;
use App\Models\PermitType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The shapes the apply wizard reads back when an applicant reopens a saved
 * draft.
 *
 * Reopening a draft for RENTED premises used to blank the whole form: the
 * wizard's amount formatter was handed `monthly_rental` as a JSON number,
 * threw "raw.replace is not a function" partway through restoring, and left
 * the wizard holding the draft's ids with none of its answers — which autosave
 * then wrote back over the real business. The applicant's work survived only
 * because `name` is required server-side and the write 422'd.
 *
 * The client no longer cares which JSON type an amount arrives as, but the
 * field it crashed on is worth pinning: a draft has to come back carrying
 * everything it was saved with, whatever the premises arrangement.
 */

function rentedDraftBusiness(): Business
{
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();

    $business->update([
        'is_rented' => true,
        'lessor_name' => 'Aling Nena',
        'lessor_address' => '5 Bonifacio St',
        'lessor_contact' => '09181112222',
        'monthly_rental' => 15000,
    ]);

    return $business->fresh();
}

it('returns every rented-premises field when a draft is reopened', function () {
    $business = rentedDraftBusiness();
    $businessPt = PermitType::where('code', 'BUSINESS')->firstOrFail();

    $draft = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPt->id],
        ])
        ->assertCreated()
        ->json('data');

    $reopened = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$draft['id']}")
        ->assertOk()
        ->json('data.business');

    // The rental is what the formatter choked on. It has to be there, and it
    // has to still be 15000 however it is encoded.
    expect($reopened['monthly_rental'])->not->toBeNull();
    expect((float) $reopened['monthly_rental'])->toBe(15000.0);

    /*
     * And it is a decimal STRING, which is what web/src/lib/types.ts has always
     * declared. It used not to be: the column is `numeric` with no cast, so the
     * value came back as an int for a whole amount and a float otherwise — one
     * field changing JSON type according to what someone had typed. TypeScript
     * promised a string, the wire delivered an int, `.replace()` threw
     * mid-restore, and autosave then wrote the blanked form back over the saved
     * draft. It survived only because `name` is required server-side.
     *
     * The cast on Business::$casts is what makes this stable. Assert the type
     * and not merely the value: a regression here is invisible to a (float)
     * comparison and takes the draft down again.
     */
    expect($reopened['monthly_rental'])->toBeString();
    expect($reopened['monthly_rental'])->toBe('15000.00');

    expect($reopened['is_rented'])->toBeTrue();
    expect($reopened['lessor_name'])->toBe('Aling Nena');
    expect($reopened['lessor_address'])->toBe('5 Bonifacio St');
    expect($reopened['lessor_contact'])->toBe('09181112222');
});

it('keeps the permit selection on a reopened draft so its office form still appears', function () {
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $businessPt = PermitType::where('code', 'BUSINESS')->firstOrFail();
    $sanitaryPt = PermitType::where('code', 'SANITARY')->firstOrFail();

    $draft = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPt->id, $sanitaryPt->id],
        ])
        ->assertCreated()
        ->json('data');

    $codes = collect(
        $this->withHeaders(authAs('owner@biztrack.local'))
            ->getJson("/api/v1/applications/{$draft['id']}")
            ->assertOk()
            ->json('data.permit_types')
    )->pluck('code');

    // The wizard rebuilds its section map from these; losing SANITARY here is
    // what dropped the Sanitary Permit Form step on reopen.
    expect($codes)->toContain('BUSINESS');
    expect($codes)->toContain('SANITARY');
});

it('restores the free-text line of business typed against "Other (not listed)"', function () {
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $otherPsic = \App\Models\PsicCode::where('code', '00000')->firstOrFail();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/businesses/{$business->id}", [
            'name' => $business->name,
            'registration_type' => 'sole_proprietorship',
            'registration_number' => 'DTI-2026-0001',
            'tin' => '123456789',
            'is_rented' => false,
            'address' => [
                'line1' => '12 Rizal Ave',
                'barangay_id' => $business->address->barangay_id,
            ],
            'lines' => [[
                'psic_code_id' => $otherPsic->id,
                'capitalization' => '250000',
                'line_of_business' => 'bamboo furniture weaving',
            ]],
        ])
        ->assertOk();

    $lines = $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/businesses/{$business->id}")
        ->assertOk()
        ->json('data.lines');

    expect($lines)->toHaveCount(1);
    expect($lines[0]['line_of_business'])->toBe('bamboo furniture weaving');
    expect((float) $lines[0]['capitalization'])->toBe(250000.0);
});

/*
 * The limits the wizard now mirrors before it sends a file. If these move, the
 * browser-side check silently starts lying to applicants about what it will
 * accept, so they are worth stating in one place.
 */

it('rejects an attachment over the documented 10MB limit', function () {
    Storage::fake('local');

    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $businessPt = PermitType::where('code', 'BUSINESS')->firstOrFail();
    $docType = DocumentType::firstOrFail();

    $draft = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPt->id],
        ])
        ->assertCreated()
        ->json('data');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$draft['id']}/documents", [
            'document_type_id' => $docType->id,
            // 10240 KB is the limit; one kilobyte past it must fail.
            'file' => UploadedFile::fake()->create('scan.pdf', 10241, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'The file may not be larger than 10MB.');
});

it('accepts a PDF at the documented limit and refuses a type it cannot read', function () {
    Storage::fake('local');

    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $businessPt = PermitType::where('code', 'BUSINESS')->firstOrFail();
    $docType = DocumentType::firstOrFail();

    $draft = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPt->id],
        ])
        ->assertCreated()
        ->json('data');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$draft['id']}/documents", [
            'document_type_id' => $docType->id,
            'file' => UploadedFile::fake()->create('scan.pdf', 10240, 'application/pdf'),
        ])
        ->assertCreated();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$draft['id']}/documents", [
            'document_type_id' => $docType->id,
            'file' => UploadedFile::fake()->create('installer.exe', 10, 'application/octet-stream'),
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'Upload a PDF, JPG, or PNG file.');
});

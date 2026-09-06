<?php

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Barangay;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\PsicCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Item 59 — "the user should be allowed to upload a file instead of submitting
 * a whole new application for a certain permit … assuming he/she has that
 * certain permit already."
 *
 * Submitting a certificate is the opposite of applying for one: the copy is
 * recorded against the application under a document type that names the permit,
 * so the reviewing office reads it with the rest of the file, and the clearance
 * is simply not in permit_type_ids — which is what spares the applicant that
 * office's form, its assignment, and its fee.
 */

/** A fresh draft owned by `owner@biztrack.local`, applying for BUSINESS only. */
function heldPermitDraft(string $name = 'Held Permit Store'): int
{
    authAs('owner@biztrack.local');

    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => $name,
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-59001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '9 Held St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    return test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');
}

function sanitaryType(): PermitType
{
    return PermitType::where('code', 'SANITARY')->firstOrFail();
}

beforeEach(function () {
    // Keep uploads out of the developer's real storage directory.
    Storage::fake('local');
});

it('records a permit the applicant already holds against the application', function () {
    $appId = heldPermitDraft();
    $sanitary = sanitaryType();

    $doc = $this->postJson("/api/v1/applications/{$appId}/documents", [
        'permit_type_id' => $sanitary->id,
        'file' => UploadedFile::fake()->create('sanitary-permit.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json('data');

    $row = ApplicationDocument::findOrFail($doc['id']);
    expect($row->permit_type_id)->toBe($sanitary->id)
        ->and($row->application_id)->toBe($appId);

    // The document type names the permit, so an officer scanning the
    // attachment list never has to work out what it is doing there.
    expect($doc['document_type']['code'])->toBe('HELD_SANITARY')
        ->and($doc['document_type']['name'])->toContain($sanitary->name)
        ->and($doc['document_type']['name'])->toContain('already held');
});

it('shows the submitted certificate to the reviewing officer', function () {
    $appId = heldPermitDraft();

    $this->postJson("/api/v1/applications/{$appId}/documents", [
        'permit_type_id' => sanitaryType()->id,
        'file' => UploadedFile::fake()->create('sanitary-permit.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    authAs('bplo@biztrack.local');
    $names = collect($this->getJson("/api/v1/applications/{$appId}")->assertOk()->json('data.documents'))
        ->pluck('document_type.name');

    expect($names)->toContain(sanitaryType()->name.' (already held)');
});

it('does not add the clearance to what is being applied for', function () {
    $appId = heldPermitDraft();

    $this->postJson("/api/v1/applications/{$appId}/documents", [
        'permit_type_id' => sanitaryType()->id,
        'file' => UploadedFile::fake()->create('sanitary-permit.pdf', 20, 'application/pdf'),
    ])->assertCreated();

    $codes = Application::findOrFail($appId)->permitTypes->pluck('code');

    // No sanitary assignment, no sanitary form, no sanitary fee: nobody has
    // been asked to issue one.
    expect($codes)->toContain('BUSINESS')->and($codes)->not->toContain('SANITARY');
});

it('replaces an earlier certificate for the same clearance', function () {
    $appId = heldPermitDraft();
    $sanitary = sanitaryType();

    $first = $this->postJson("/api/v1/applications/{$appId}/documents", [
        'permit_type_id' => $sanitary->id,
        'file' => UploadedFile::fake()->create('old-sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json('data');

    $second = $this->postJson("/api/v1/applications/{$appId}/documents", [
        'permit_type_id' => $sanitary->id,
        'file' => UploadedFile::fake()->create('new-sanitary.pdf', 20, 'application/pdf'),
    ])->assertCreated()->json('data');

    $held = ApplicationDocument::where('application_id', $appId)
        ->where('permit_type_id', $sanitary->id)
        ->get();

    expect($held)->toHaveCount(1)
        ->and($held->first()->id)->toBe($second['id'])
        ->and(ApplicationDocument::find($first['id']))->toBeNull();
});

it('refuses a certificate for the permit the application is asking for', function () {
    $appId = heldPermitDraft();

    $this->postJson("/api/v1/applications/{$appId}/documents", [
        'permit_type_id' => PermitType::where('code', 'BUSINESS')->firstOrFail()->id,
        'file' => UploadedFile::fake()->create('mayors-permit.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);
});

it('still requires a document type for an ordinary requirement', function () {
    $appId = heldPermitDraft();

    $this->postJson("/api/v1/applications/{$appId}/documents", [
        'file' => UploadedFile::fake()->create('mystery.pdf', 20, 'application/pdf'),
    ])->assertStatus(422)->assertJsonValidationErrors('document_type_id');
});

it('refuses a certificate once the application has left the applicant', function () {
    // app2 (RxCare Pharmacy) is under review and belongs to juan.
    $app = Application::where('status', 'under_review')->firstOrFail();

    authAs($app->applicant->email);
    $this->postJson("/api/v1/applications/{$app->id}/documents", [
        'permit_type_id' => sanitaryType()->id,
        'file' => UploadedFile::fake()->create('sanitary-permit.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);
});

it('will not let one applicant attach a permit to another applicant’s application', function () {
    $appId = heldPermitDraft();

    // A different business owner, holding a perfectly valid session.
    authAs('juan@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/documents", [
        'permit_type_id' => sanitaryType()->id,
        'file' => UploadedFile::fake()->create('sanitary-permit.pdf', 20, 'application/pdf'),
    ])->assertForbidden();

    expect(ApplicationDocument::where('application_id', $appId)->count())->toBe(0);
});

it('will not let an officer attach a permit to an applicant’s application', function () {
    $appId = heldPermitDraft();

    authAs('bplo@biztrack.local');
    $this->postJson("/api/v1/applications/{$appId}/documents", [
        'permit_type_id' => sanitaryType()->id,
        'file' => UploadedFile::fake()->create('sanitary-permit.pdf', 20, 'application/pdf'),
    ])->assertStatus(403);
});

it('leaves ordinary requirements unlinked to any permit', function () {
    $appId = heldPermitDraft();

    $doc = $this->postJson("/api/v1/applications/{$appId}/documents", [
        'document_type_id' => DocumentType::where('code', 'BRGY_CLEARANCE')->firstOrFail()->id,
        'file' => UploadedFile::fake()->create('barangay.pdf', 12, 'application/pdf'),
    ])->assertCreated()->json('data');

    expect(ApplicationDocument::findOrFail($doc['id'])->permit_type_id)->toBeNull();
});

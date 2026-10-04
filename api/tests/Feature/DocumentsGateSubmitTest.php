<?php

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Barangay;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Support\RequiredDocuments;

/*
 * A filing cannot be submitted without its required Documentary Requirements.
 *
 * Browser testing, 5 October 2026: the wizard gated its Documents step, and
 * the API took a NEW application with no documents at all, and an amendment
 * with no affidavit. The gate reads the business permit's own requirement
 * rows the way the wizard does (`App\Support\RequiredDocuments`), so these
 * pin the list as well as the refusal.
 */

function gateDraft(string $type = 'new', array $business = []): int
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', array_merge([
        'name' => 'Documents Gate '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '4 Paper Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 150000]],
    ], $business))->assertCreated()->json('data.id');

    return test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => $type,
        'permit_type_ids' => PermitType::where('code', PermitType::OUTCOME_CODE)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');
}

it('refuses a new application with no documents, naming what is missing', function () {
    $appId = gateDraft();

    $message = test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors('documents')
        ->json('errors.documents.0');

    expect($message)->toContain(DocumentType::where('code', 'DTI_SEC_CDA')->value('name'))
        ->and($message)->toContain(DocumentType::where('code', 'LOCATION_SKETCH')->value('name'))
        ->and(Application::findOrFail($appId)->status->value)->toBe('draft');
});

it('asks a new application for exactly what the wizard asks', function () {
    // Owned premises, no incentive: registration, sketch, and the title.
    $codes = RequiredDocuments::requiredFor(Application::findOrFail(gateDraft()))->pluck('code')->sort()->values()->all();
    expect($codes)->toBe(['DTI_SEC_CDA', 'LAND_TITLE', 'LOCATION_SKETCH']);
})->skip(fn () => DocumentType::where('code', 'LAND_TITLE')->doesntExist(), 'reference rows not seeded');

it('never requires an optional row', function () {
    // Other Requirements and the SPA are offered, never demanded.
    $codes = RequiredDocuments::requiredFor(Application::findOrFail(gateDraft()))->pluck('code')->all();
    expect($codes)->not->toContain('OTHER')->and($codes)->not->toContain('SPA_AUTHORIZATION');
});

it('submits once the required documents are uploaded', function () {
    $appId = gateDraft();
    attachRequiredDocuments($appId);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertOk();
});

it('refuses a filing missing just one of them', function () {
    $appId = gateDraft();
    attachRequiredDocuments($appId);
    $sketch = DocumentType::where('code', 'LOCATION_SKETCH')->value('id');
    ApplicationDocument::where('application_id', $appId)->where('document_type_id', $sketch)->delete();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->assertJsonPath('errors.documents.0', 'Upload: '.DocumentType::find($sketch)->name.'.');
});

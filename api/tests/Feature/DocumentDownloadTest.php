<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Item 55: viewing or downloading a submitted requirement opened a JSON
 * response in a new tab. The UI was pointing a plain link at the API, so the
 * browser sent no bearer token and rendered the 401 envelope. The endpoint
 * itself has to stay strict — the file is only for the applicant who uploaded
 * it and for officers who may read every application.
 */

/** Upload one PDF requirement to a fresh draft owned by `owner@biztrack.local`. */
function uploadedRequirement(): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Document Test Store',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-55123',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Document St.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    $document = test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/documents", [
        'document_type_id' => DocumentType::first()->id,
        'file' => UploadedFile::fake()->create('barangay-clearance.pdf', 12, 'application/pdf'),
    ])->assertCreated()->json('data');

    return ['application_id' => $appId, 'document' => $document];
}

beforeEach(function () {
    // Keep uploads out of the developer's real storage directory.
    Storage::fake('local');
});

it('streams the file itself to the applicant who uploaded it', function () {
    ['document' => $document] = uploadedRequirement();

    authAs('owner@biztrack.local');
    $res = $this->get("/api/v1/documents/{$document['id']}/download")->assertOk();

    expect($res->headers->get('Content-Disposition'))->toContain('barangay-clearance.pdf')
        // Not the JSON envelope the browser used to show in a new tab.
        ->and($res->headers->get('Content-Type'))->not->toContain('application/json');
});

it('refuses a document that belongs to another applicant', function () {
    ['document' => $document] = uploadedRequirement();

    authAs('juan@biztrack.local');
    $this->getJson("/api/v1/documents/{$document['id']}/download")
        ->assertForbidden()
        ->assertJsonPath('message', 'You may not access this document.');
});

it('lets an officer with application.view_all read a submitted requirement', function () {
    ['document' => $document] = uploadedRequirement();

    expect(User::where('email', 'bplo@biztrack.local')->firstOrFail()
        ->hasPermission('application.view_all'))->toBeTrue();

    authAs('bplo@biztrack.local');
    $res = $this->get("/api/v1/documents/{$document['id']}/download")->assertOk();

    expect($res->headers->get('Content-Disposition'))->toContain('barangay-clearance.pdf');
});

it('rejects an anonymous download', function () {
    ['document' => $document] = uploadedRequirement();

    app('auth')->forgetGuards();
    $this->getJson("/api/v1/documents/{$document['id']}/download")->assertUnauthorized();
});

it('reports a missing file as 404 rather than streaming nothing', function () {
    ['document' => $document] = uploadedRequirement();

    Storage::disk('local')->deleteDirectory('private/documents');

    authAs('owner@biztrack.local');
    $this->getJson("/api/v1/documents/{$document['id']}/download")
        ->assertNotFound()
        ->assertJsonPath('message', 'File not found.');
});

it('keeps message attachments behind the same participant check', function () {
    ['application_id' => $appId] = uploadedRequirement();

    $owner = authAs('owner@biztrack.local');
    Application::findOrFail($appId)->update(['status' => 'submitted']);

    $attachmentId = test()->withHeaders($owner)->post("/api/v1/applications/{$appId}/messages", [
        'body' => 'Here is the signed copy.',
        'attachment' => UploadedFile::fake()->create('signed.pdf', 8, 'application/pdf'),
    ])->assertCreated()->json('data.attachments.0.id');

    authAs('juan@biztrack.local');
    $this->getJson("/api/v1/message-attachments/{$attachmentId}/download")->assertForbidden();

    authAs('bplo@biztrack.local');
    $res = $this->get("/api/v1/message-attachments/{$attachmentId}/download")->assertOk();
    expect($res->headers->get('Content-Disposition'))->toContain('signed.pdf');
});

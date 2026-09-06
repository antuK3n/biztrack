<?php

use App\Models\Application;
use App\Models\ApplicationDocument;
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

/**
 * The bytes every fixture in this file uploads.
 *
 * `UploadedFile::fake()->create($name, $kilobytes)` only *reports* a size — the
 * temp file it hands the controller is empty, so every download assertion in
 * this file used to be checking that zero bytes came back with the right
 * headers. That passes just as happily when the stream is broken, which is
 * precisely the gap item 96 fell through. Real content, so "what came back is
 * what went in" is a claim with something in it.
 */
const FIXTURE_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

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
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    $document = test()->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/documents", [
        'document_type_id' => DocumentType::first()->id,
        'file' => UploadedFile::fake()->createWithContent('barangay-clearance.pdf', FIXTURE_PDF),
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

/*
 * Item 96 reopened item 55 ("it cannot be viewed or downloaded now"), and the
 * reason the old test could not have caught a return is that it only ever
 * asserted what the response is NOT. "Content-Type is not application/json"
 * passes for an empty body, for a truncated stream, and for a text/plain error
 * page — every shape of "the officer opened it and got something that was not
 * the document".
 *
 * So this asserts what the response IS, byte for byte: the declared type is the
 * file's own, the disposition tells the browser to save rather than render in
 * place, and the body that comes back is exactly the body that went in. A PNG
 * is used deliberately — it is binary, so a stream that has been decoded,
 * re-encoded or cut short cannot compare equal by accident, which a fake PDF of
 * repeated filler bytes might.
 */
it('returns the uploaded bytes, typed and dispositioned as a file', function () {
    $owner = authAs('owner@biztrack.local');

    $businessId = $this->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Byte For Byte Store',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-96001',
        'tin' => '123-456-789-000',
        'address' => ['line1' => '96 Download Rd.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = $this->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    // A real 1×1 PNG rather than UploadedFile::fake()->image(), so the bytes
    // are fixed and this test does not depend on GD being installed.
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
    $upload = UploadedFile::fake()->createWithContent('valid-id.png', $png);

    $documentId = $this->withHeaders($owner)->postJson("/api/v1/applications/{$appId}/documents", [
        'document_type_id' => DocumentType::where('code', 'VALID_ID')->firstOrFail()->id,
        'file' => $upload,
    ])->assertCreated()->json('data.id');

    $res = $this->get("/api/v1/documents/{$documentId}/download")->assertOk();

    expect($res->headers->get('Content-Type'))->toBe('image/png')
        ->and($res->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($res->headers->get('Content-Disposition'))->toContain('valid-id.png')
        ->and($res->streamedContent())->toBe($png);
});

/*
 * The same assertion from the officer's seat. Item 55 was reported by an
 * officer and fixed only on the officer's screen; item 96 was reported from the
 * applicant's. Neither seat is the whole feature, so both are pinned here — a
 * download that works for one and not the other is still a filing nobody can
 * review.
 */
it('returns the same bytes to a reviewing officer', function () {
    ['document' => $document] = uploadedRequirement();

    authAs('bplo@biztrack.local');
    $res = $this->get("/api/v1/documents/{$document['id']}/download")->assertOk();

    // Read once and keep it: the response is a stream, so a second
    // streamedContent() call reads an already-drained handle and returns ''.
    $body = $res->streamedContent();
    $onDisk = Storage::disk('local')->get(
        ApplicationDocument::findOrFail($document['id'])->stored_path
    );

    expect($res->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($body)->not->toBe('')
        ->and($body)->toBe($onDisk);
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

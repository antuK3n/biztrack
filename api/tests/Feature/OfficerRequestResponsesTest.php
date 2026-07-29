<?php

use App\Models\DocumentType;
use App\Models\OfficerRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Tester item 13 — "Other Requirements" must accept MANY applicant responses.
 * The seeded storyline gives juan@ a pending BPLO request on RxCare Pharmacy.
 */

/** The seeded pending request ("Updated locational clearance") on juan@'s app. */
function seededRequest(): OfficerRequest
{
    return OfficerRequest::whereHas('application', fn ($q) => $q->whereHas(
        'business',
        fn ($b) => $b->where('name', 'RxCare Pharmacy')
    ))->firstOrFail();
}

it('keeps every applicant response and returns them oldest first', function () {
    $req = seededRequest();

    foreach (['First upload is coming.', 'Here is the clearance.', 'And the barangay endorsement.'] as $body) {
        $this->withHeaders(authAs('juan@biztrack.local'))
            ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => $body])
            ->assertOk();
    }

    $res = $this->withHeaders(authAs('juan@biztrack.local'))
        ->getJson('/api/v1/requests')
        ->assertOk();

    $row = collect($res->json('data'))->firstWhere('id', $req->id);

    expect(array_column($row['responses'], 'body'))->toBe([
        'First upload is coming.',
        'Here is the clearance.',
        'And the barangay endorsement.',
    ]);
    // Legacy single-response fields still mirror the latest reply.
    expect($row['response_body'])->toBe('And the barangay endorsement.');
    expect($row['status'])->toBe('submitted');
    expect($req->fresh()->responses)->toHaveCount(3);
});

it('records the author and timestamp on each response', function () {
    $req = seededRequest();

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => 'Attached.'])
        ->assertOk()
        ->assertJsonPath('data.responses.0.author.name', 'Juan Ramos')
        ->assertJsonPath('data.responses.0.body', 'Attached.');

    expect($req->fresh()->responses->first()->created_at)->not->toBeNull();
});

it('shows the officer every response on the request', function () {
    $req = seededRequest();

    foreach (['One.', 'Two.'] as $body) {
        $this->withHeaders(authAs('juan@biztrack.local'))
            ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => $body])
            ->assertOk();
    }

    $res = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/requests')
        ->assertOk();

    $row = collect($res->json('data'))->firstWhere('id', $req->id);

    expect($row)->not->toBeNull();
    expect(array_column($row['responses'], 'body'))->toBe(['One.', 'Two.']);
});

it('attaches an uploaded document to the response that carried it', function () {
    Storage::fake('local');
    $req = seededRequest();
    $typeId = DocumentType::query()->value('id');

    // Text-only first, then a reply with a file.
    $this->withHeaders(authAs('juan@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => 'Scanning it now.'])
        ->assertOk();

    $res = $this->withHeaders(authAs('juan@biztrack.local'))
        ->post("/api/v1/requests/{$req->id}/respond", [
            'body' => 'Here is the scan.',
            'document' => UploadedFile::fake()->create('clearance.pdf', 40, 'application/pdf'),
            'document_type_id' => $typeId,
        ])
        ->assertOk();

    expect($res->json('data.responses.0.document'))->toBeNull();
    expect($res->json('data.responses.1.document.filename'))->toBe('clearance.pdf');
});

it('files an upload with no chosen type under "Other Requirements"', function () {
    Storage::fake('local');
    $req = seededRequest();

    // The Respond form has no type picker, so document_type_id is never sent.
    $res = $this->withHeaders(authAs('juan@biztrack.local'))
        ->post("/api/v1/requests/{$req->id}/respond", [
            'document' => UploadedFile::fake()->create('endorsement.pdf', 20, 'application/pdf'),
        ])
        ->assertOk();

    expect($res->json('data.responses.0.document.filename'))->toBe('endorsement.pdf');
    expect($req->fresh()->responses->first()->document->documentType->code)->toBe('OTHER');
});

it('refuses a response from an applicant who does not own the application', function () {
    $req = seededRequest();

    // owner@ is an applicant with request.respond, but on a different application.
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => 'Not mine.'])
        ->assertForbidden();

    expect($req->fresh()->responses)->toHaveCount(0);
});

it('stops accepting responses once the officer closes the request', function () {
    $req = seededRequest();

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => 'Done.'])
        ->assertOk();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/close", ['outcome' => 'fulfilled'])
        ->assertOk();

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/respond", ['body' => 'One more thing.'])
        ->assertStatus(422);

    expect($req->fresh()->responses)->toHaveCount(1);
});

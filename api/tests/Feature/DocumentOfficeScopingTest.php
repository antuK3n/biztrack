<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Item 56, the part the queue scoping left open.
 *
 * Once offices were narrowed to the filings they are routed to, the document
 * download was still asking the old question — "does this reader hold
 * application.view_all?" — which every office reviewer does. A sanitary
 * officer could pull any attachment off any application by its id, which is
 * the leak the scoping exists to close. The download now asks the same
 * question the rest of the application does.
 */

/**
 * One requirement on RxCare's paid, in-flight filing (routed to BPLO, CHO, BFP).
 *
 * `under_review` is not an application status any more — the work it described
 * belongs to one permit and lives on `application_permit_types.status`
 * (docs/application-flow-2026-09.md). The seeded RxCare filing is at
 * `awaiting_other_permits`, which is the same stage of the same story: paid,
 * with its offices working. Nothing about the document boundary under test
 * depends on which name that stage has.
 */
function scopedDocument(): array
{
    $app = Application::where('status', 'awaiting_other_permits')
        ->whereHas('business', fn ($b) => $b->where('name', 'RxCare Pharmacy'))
        ->firstOrFail();

    authAs($app->applicant->email);
    $doc = test()->postJson("/api/v1/applications/{$app->id}/documents", [
        'document_type_id' => DocumentType::where('code', 'BRGY_CLEARANCE')->firstOrFail()->id,
        'file' => UploadedFile::fake()->create('barangay-clearance.pdf', 14, 'application/pdf'),
    ])->assertCreated()->json('data');

    return ['application' => $app, 'document' => $doc];
}

beforeEach(function () {
    Storage::fake('local');
});

it('lets the applicant download their own attachment', function () {
    ['application' => $app, 'document' => $doc] = scopedDocument();

    authAs($app->applicant->email);
    $res = $this->get("/api/v1/documents/{$doc['id']}/download")->assertOk();

    expect($res->headers->get('Content-Disposition'))->toContain('barangay-clearance.pdf');
});

it('lets an office that holds an assignment on the filing download it', function () {
    ['application' => $app, 'document' => $doc] = scopedDocument();

    // The City Health Office is routed on this application.
    $cho = Department::where('code', 'CHO')->firstOrFail();
    expect(
        ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $cho->id)
            ->exists()
    )->toBeTrue();

    authAs('sanitary@biztrack.local');
    $this->get("/api/v1/documents/{$doc['id']}/download")->assertOk();
});

it('refuses an office the filing never reached', function () {
    ['application' => $app, 'document' => $doc] = scopedDocument();

    $cenro = Department::where('code', 'CENRO')->firstOrFail();
    expect(
        ApplicationAssignment::where('application_id', $app->id)
            ->where('department_id', $cenro->id)
            ->exists()
    )->toBeFalse();

    // A real reviewer with a real session, guessing at a document id.
    authAs('cenro@biztrack.local');
    $this->get("/api/v1/documents/{$doc['id']}/download")
        // 403, not a 500 and not a stream: the reader exists, the answer is no.
        ->assertStatus(403);
});

it('still lets BPLO and the administrator read every office', function () {
    ['document' => $doc] = scopedDocument();

    authAs('bplo@biztrack.local');
    $this->get("/api/v1/documents/{$doc['id']}/download")->assertOk();

    authAs('admin@biztrack.local');
    $this->get("/api/v1/documents/{$doc['id']}/download")->assertOk();
});

it('refuses an unrelated applicant', function () {
    ['document' => $doc] = scopedDocument();

    authAs('owner@biztrack.local');
    $this->get("/api/v1/documents/{$doc['id']}/download")->assertStatus(403);
});

it('refuses a reviewer with no department at all', function () {
    ['document' => $doc] = scopedDocument();

    // Strip the office off a scoped reviewer: the boundary has to fail closed.
    User::where('email', 'sanitary@biztrack.local')->update(['department_id' => null]);

    authAs('sanitary@biztrack.local');
    $this->get("/api/v1/documents/{$doc['id']}/download")->assertStatus(403);
});

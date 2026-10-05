<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationDocument;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\FsicRequirements;
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
 * `approved`, which is the same stage of the same story: paid,
 * with its offices working. Nothing about the document boundary under test
 * depends on which name that stage has.
 */
function scopedDocument(): array
{
    $app = Application::where('status', 'approved')
        ->whereHas('business', fn ($b) => $b->where('name', 'RxCare Pharmacy'))
        ->firstOrFail();

    /*
     * Written straight to the register rather than uploaded: the filing is
     * past submit, and the upload door is closed there unless the filing is
     * returned (DocumentUploadWindowTest). What is under test is the
     * download, which does not care how the row got there.
     */
    $path = "private/documents/{$app->id}/barangay-clearance.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 barangay clearance');
    $doc = ApplicationDocument::create([
        'application_id' => $app->id,
        'document_type_id' => DocumentType::where('code', 'BRGY_CLEARANCE')->firstOrFail()->id,
        'original_filename' => 'barangay-clearance.pdf',
        'stored_path' => $path,
        'mime_type' => 'application/pdf',
        'size_bytes' => 27,
    ])->toArray();

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

/*
 * Checklist, Manage Applications 3: "each office should only view the
 * application form for their office only".
 *
 * An upload into an office sheet's own checklist — BFP's FSIC_REQ_* rows here —
 * carries no `permit_type_id`, so the list filter and the download both let it
 * through to every office routed to the filing. The CHO officer on a filing
 * BFP also works was handed BFP's files, and could download each.
 */

/** A file in BFP's FSIC checklist on RxCare's filing, which CHO and BFP both work. */
function fsicChecklistFile(): array
{
    ['application' => $app] = scopedDocument();

    $path = "private/documents/{$app->id}/fsmr.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 fire safety maintenance report');
    $doc = ApplicationDocument::create([
        'application_id' => $app->id,
        'document_type_id' => FsicRequirements::documentType(FsicRequirements::CODE_PREFIX.'FSMR')->id,
        'original_filename' => 'fsmr.pdf',
        'stored_path' => $path,
        'mime_type' => 'application/pdf',
        'size_bytes' => 39,
    ]);

    return ['application' => $app, 'document' => $doc];
}

/** The ids of the attachments GET /applications/{id} hands this reader. */
function listedDocumentIds(string $email, Application $app): array
{
    return collect(test()->withHeaders(authAs($email))
        ->getJson("/api/v1/applications/{$app->id}")->assertOk()->json('data.documents'))
        ->pluck('id')->all();
}

it('does not list one office’s checklist file to another office on the same filing', function () {
    ['application' => $app, 'document' => $doc] = fsicChecklistFile();
    $shared = ApplicationDocument::where('application_id', $app->id)
        ->whereHas('documentType', fn ($t) => $t->where('code', 'BRGY_CLEARANCE'))
        ->value('id');

    // CHO keeps the shared barangay clearance and loses BFP's file.
    expect(listedDocumentIds('sanitary@biztrack.local', $app))
        ->toContain($shared)
        ->not->toContain($doc->id);

    // BFP, whose checklist it is, keeps it — and so do BPLO, the super admin
    // and the applicant who uploaded it.
    foreach (['fire@biztrack.local', 'bplo@biztrack.local', 'admin@biztrack.local', $app->applicant->email] as $reader) {
        expect(listedDocumentIds($reader, $app))->toContain($doc->id, $shared);
    }
});

it('refuses the download of one office’s checklist file to another office', function () {
    ['application' => $app, 'document' => $doc] = fsicChecklistFile();

    authAs('sanitary@biztrack.local');
    $this->get("/api/v1/documents/{$doc->id}/download")
        ->assertForbidden()
        ->assertJsonPath('message', 'You may not access this document.');

    foreach (['fire@biztrack.local', 'bplo@biztrack.local', 'admin@biztrack.local', $app->applicant->email] as $reader) {
        authAs($reader);
        $this->get("/api/v1/documents/{$doc->id}/download")->assertOk();
    }
});

<?php

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Business;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\SheetRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * One requirement does not keep the same bytes twice.
 *
 * ── What produced this ──────────────────────────────────────────────────────
 *
 * The client, 30 September 2026, answering a return: they uploaded
 * `17_Robert_Hooke_Engineer.jpg` and the officer's Section C showed it as the
 * current copy AND as the newest of four earlier copies — same name, same
 * 549 KB, same date. *"This is redundant."*
 *
 * The grouping was correct. The register genuinely held two rows, id 64 at
 * 10:32:49 and id 66 at 10:37:47, byte for byte the same file: the applicant
 * uploaded it twice, five minutes apart, almost certainly because the
 * correction box gave one small line of feedback and they could not tell the
 * first had worked.
 *
 * ── Why the rule is "same as the NEWEST", not "seen before" ────────────────
 *
 * A-then-B-then-A is a real statement: the applicant has gone back to the
 * first version, and an officer reading the history should see that. Only an
 * immediate repeat carries no information, because there is no difference
 * between the two rows for anyone to act on.
 *
 * `file_hash` had been on the table since the beginning and nothing wrote it.
 * It also had to be added to `$fillable` before it would persist — the same
 * silent drop that left `returned_state` null for a day.
 */

/** A draft the applicant may still attach documents to. */
function draftForUploads(): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    return Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);
}

it('keeps one copy when the same file is sent twice to a requirement', function () {
    Storage::fake('local');
    $app = draftForUploads();
    $typeId = DocumentType::where('code', 'DTI_SEC_CDA')->value('id');
    $owner = authAs('owner@biztrack.local');

    $send = fn () => test()->withHeaders($owner)->post("/api/v1/applications/{$app->id}/documents", [
        'document_type_id' => $typeId,
        /*
         * Identical CONTENT both times. `fake()->create` reports a size but
         * writes no bytes, so two 'different' files by that helper hash the
         * same — which is a fixture artifact and would make this case pass
         * for the wrong reason.
         */
        'file' => UploadedFile::fake()->createWithContent('registration.pdf', 'the same scan'),
    ]);

    $first = $send()->assertCreated()->json('data.id');
    $second = $send()->assertCreated()->json('data.id');

    /* The second press answers with the copy already held, not a new row. */
    expect($second)->toBe($first)
        ->and(ApplicationDocument::where('application_id', $app->id)
            ->where('document_type_id', $typeId)
            ->count())->toBe(1);
});

it('keeps both when the second file is different', function () {
    Storage::fake('local');
    $app = draftForUploads();
    $typeId = DocumentType::where('code', 'DTI_SEC_CDA')->value('id');
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->post("/api/v1/applications/{$app->id}/documents", [
        'document_type_id' => $typeId,
        'file' => UploadedFile::fake()->createWithContent('first.pdf', 'the first scan'),
    ])->assertCreated();

    test()->withHeaders($owner)->post("/api/v1/applications/{$app->id}/documents", [
        'document_type_id' => $typeId,
        /* Genuinely different bytes — a real resubmission. */
        'file' => UploadedFile::fake()->createWithContent('second.pdf', 'a better scan'),
    ])->assertCreated();

    expect(ApplicationDocument::where('application_id', $app->id)
        ->where('document_type_id', $typeId)
        ->count())->toBe(2);
});

it('records the hash, so the next upload has something to compare with', function () {
    /*
     * The column existed from the start and nothing wrote it. Worth its own
     * case: an unfillable key is dropped by `create()` in silence, and the
     * whole rule above then compares null against a hash and never matches.
     */
    Storage::fake('local');
    $app = draftForUploads();
    $typeId = DocumentType::where('code', 'DTI_SEC_CDA')->value('id');
    $owner = authAs('owner@biztrack.local');

    test()->withHeaders($owner)->post("/api/v1/applications/{$app->id}/documents", [
        'document_type_id' => $typeId,
        'file' => UploadedFile::fake()->createWithContent('registration.pdf', 'a scan'),
    ])->assertCreated();

    expect(ApplicationDocument::where('application_id', $app->id)->value('file_hash'))
        ->toBeString()
        ->toHaveLength(64);
});

it('does the same on an office checklist slot', function () {
    Storage::fake('local');
    $appId = scopedAssignmentFiling('Duplicate Upload Co');
    $app = Application::findOrFail($appId);
    $type = PermitType::where('code', 'ZONING')->firstOrFail();
    app(WorkflowService::class)->startClearance($app->fresh(), $type, 'apply');

    $owner = authAs('owner@biztrack.local');
    $send = fn () => test()->withHeaders($owner)->post(
        "/api/v1/applications/{$appId}/office-forms/ZONING/requirements/ZONING_REQ_TAX_DECLARATION",
        ['file' => UploadedFile::fake()->createWithContent('tax.pdf', 'the tax declaration')],
    );

    $send()->assertSuccessful();
    $send()->assertSuccessful();

    $typeId = SheetRequirements::documentType('ZONING', 'ZONING_REQ_TAX_DECLARATION')->id;
    expect(ApplicationDocument::where('application_id', $appId)
        ->where('document_type_id', $typeId)
        ->count())->toBe(1);
});

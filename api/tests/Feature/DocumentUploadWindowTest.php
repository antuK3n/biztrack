<?php

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Barangay;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * When the owner may add a file to a filing's requirements.
 *
 * DocumentController::store checked ownership only, so a new requirement was
 * accepted while BPLO read the filing and again after it was Completed - a
 * file landing under a review that had already been made, or after the
 * decision [Ken, 5 October 2026]. It is open while the filing is a draft, and
 * after submit only when the filing, or one of its clearances, has been
 * returned to the owner for correction.
 */

function uploadWindowPost(int $appId, string $content)
{
    authAs('owner@biztrack.local');

    return test()->post("/api/v1/applications/{$appId}/documents", [
        'document_type_id' => DocumentType::where('code', 'DTI_SEC_CDA')->value('id'),
        'file' => UploadedFile::fake()->createWithContent('late.pdf', $content),
    ], ['Accept' => 'application/json']);
}

it('refuses a new requirement while BPLO reads the filing, and after it is completed', function () {
    Storage::fake('local');
    $app = filingWithoutTin('123-456-789-000');

    uploadWindowPost($app->id, 'added while BPLO reads')
        ->assertStatus(422)
        ->assertJsonPath('message', 'You can change this once the filing is returned to you.');

    Application::whereKey($app->id)->update(['status' => 'approved', 'decided_at' => now()]);
    uploadWindowPost($app->id, 'added after completion')->assertStatus(422);

    expect(ApplicationDocument::where('application_id', $app->id)->where('original_filename', 'late.pdf')->exists())
        ->toBeFalse();
});

it('takes a new requirement once BPLO returns the filing', function () {
    Storage::fake('local');
    $app = filingReturnedAbout('DTI_SEC_CDA');

    uploadWindowPost($app->id, 'the corrected certificate')->assertCreated();
});

it('takes a new requirement while one of the filing\'s clearances is returned', function () {
    Storage::fake('local');
    $app = Application::findOrFail(scopedAssignmentFiling('Upload Window Clearance'));
    $row = $app->permitTypes()->where('code', 'SANITARY')->firstOrFail()->pivot;

    // Gathering, sanitary handed in: nothing has been asked for yet.
    uploadWindowPost($app->id, 'unasked')->assertStatus(422);

    app(WorkflowService::class)->returnClearance($row, 'Attach the DTI certificate.', 'DTI_SEC_CDA');
    uploadWindowPost($app->id, 'asked for by the office')->assertCreated();
});

it('takes a requirement on a draft, as it always has', function () {
    Storage::fake('local');
    authAs('owner@biztrack.local');
    $businessId = test()->postJson('/api/v1/businesses', [
        'name' => 'Upload Window Draft '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(10000, 99999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '4 Draft Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');
    $draftId = test()->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    uploadWindowPost($draftId, 'on the draft')->assertCreated();
});

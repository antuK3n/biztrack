<?php

use App\Enums\OfficerRequestStatus;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\OfficerRequest;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * ── A system requirement is answered by a FIELD ──────────────────────────────
 *
 * Client, 5 October 2026: *"Remember the sending of TIN and DENR in Other
 * Requirements? Can you make them show a field instead of a 'Response'
 * thingy? So that submitting them will transport them directly to the
 * database (just like a field in the Return function)."*
 *
 * Picker answers: the TIN is "saved to the business right away when the
 * applicant submits; BPLO can still send it back if wrong"; a DENR permit is
 * one upload slot on that one requirement. `POST /requests/{id}/answer` is
 * that field's door, and it opens for those two kinds only.
 */

/** The TIN requirement BPLO's approval raised on a filing with no TIN. */
function tinRequirementRaised(): array
{
    $app = filingWithoutTin();
    bploApprovesForm($app);
    $req = OfficerRequest::where('application_id', $app->id)
        ->where('system_key', WorkflowService::TIN_REQUIREMENT_KEY)
        ->firstOrFail();

    return [$app, $req];
}

/** A DENR follow-up as `raiseDenrRequirements` writes it. */
function denrRequirementOn(Application $app, string $code = 'WDP'): OfficerRequest
{
    return OfficerRequest::create([
        'application_id' => $app->id,
        'requested_by_user_id' => null,
        'department_id' => Department::where('code', 'CENRO')->value('id'),
        'request_type' => 'document',
        'system_key' => 'denr.'.$code,
        'status' => OfficerRequestStatus::Pending,
        'due_date' => now()->addMonths(6),
        'title' => "DENR {$code} — Waste Water Discharge Permit",
    ]);
}

it('writes the TIN to the business the moment the applicant submits it', function () {
    [$app, $req] = tinRequirementRaised();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", ['tin' => '123456789000'])
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.answer_field.kind', 'tin');

    // On the register now, not after review — and shaped like the form's.
    expect($app->fresh()->business->tin)->toBe('123-456-789-000');

    // The office reads what was sent, as the requirement's submission.
    $fresh = $req->fresh();
    expect($fresh->status)->toBe(OfficerRequestStatus::Submitted)
        ->and($fresh->responses()->latest('id')->first()->body)->toBe('123-456-789-000');

    $audit = AuditLog::where('action', 'business.tin_supplied')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->changes)->toHaveKey('from', null)
        ->and($audit->changes['to'])->toBe('123-456-789-000')
        ->and($audit->changes['officer_request_id'])->toBe($req->id);
});

it('refuses a TIN that is not one, in one line, and stores nothing', function () {
    [$app, $req] = tinRequirementRaised();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", ['tin' => '12345'])
        ->assertStatus(422)
        ->assertJsonPath('errors.tin.0', 'Enter a valid TIN.');

    expect($app->fresh()->business->tin)->toBeNull()
        ->and($req->fresh()->status)->toBe(OfficerRequestStatus::Pending);
});

it('lets only the applicant on the filing answer it', function () {
    [$app, $req] = tinRequirementRaised();

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", ['tin' => '123-456-789-000'])
        ->assertForbidden();

    expect($app->fresh()->business->tin)->toBeNull();
});

it('refuses an answer once the requirement is closed', function () {
    [$app, $req] = tinRequirementRaised();
    $req->update(['status' => OfficerRequestStatus::Fulfilled]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", ['tin' => '123-456-789-000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($app->fresh()->business->tin)->toBeNull();
});

it('closes on approval without checking or writing the TIN a second time', function () {
    [$app, $req] = tinRequirementRaised();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", ['tin' => '123-456-789-000'])
        ->assertOk();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/close", ['outcome' => 'fulfilled'])
        ->assertOk();

    expect($req->fresh()->status)->toBe(OfficerRequestStatus::Fulfilled)
        ->and($app->fresh()->business->tin)->toBe('123-456-789-000')
        // The answer already recorded it; approval is not a second write.
        ->and(AuditLog::where('action', 'business.tin_recorded')->count())->toBe(0);
});

it('keeps the stored TIN when BPLO sends it back, and the next answer replaces it', function () {
    [$app, $req] = tinRequirementRaised();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", ['tin' => '123-456-789-000'])
        ->assertOk();

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/close", [
            'outcome' => 'needs_resubmission',
            'remarks' => 'That is the old branch code.',
        ])
        ->assertOk();

    expect($req->fresh()->status)->toBe(OfficerRequestStatus::NeedsResubmission)
        ->and($req->fresh()->status->acceptsResponse())->toBeTrue()
        ->and($app->fresh()->business->tin)->toBe('123-456-789-000');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", ['tin' => '123-456-789-001'])
        ->assertOk();

    expect($app->fresh()->business->tin)->toBe('123-456-789-001')
        ->and($req->fresh()->status)->toBe(OfficerRequestStatus::Submitted);
});

it('files a DENR upload under that permit’s own document type and links it', function () {
    Storage::fake('local');
    $app = filingWithoutTin('123-456-789-000');
    $req = denrRequirementOn($app);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", [
            // Real bytes: an empty fake proves nothing about what was stored.
            'document' => UploadedFile::fake()->createWithContent('wdp.pdf', "%PDF-1.4\n% WDP scan\n"),
        ])
        ->assertOk()
        ->assertJsonPath('data.answer_field.kind', 'document')
        ->assertJsonPath('data.answer_field.label', 'Waste Water Discharge Permit');

    $fresh = $req->fresh();
    $doc = ApplicationDocument::findOrFail($fresh->application_document_id);

    expect($fresh->status)->toBe(OfficerRequestStatus::Submitted)
        ->and($fresh->file_name)->toBe('wdp.pdf')
        ->and($doc->application_id)->toBe($app->id)
        ->and($doc->documentType->code)->toBe('DENR_WDP')
        ->and($fresh->responses()->latest('id')->first()->application_document_id)->toBe($doc->id);
    Storage::disk('local')->assertExists($doc->stored_path);
    expect(Storage::disk('local')->get($doc->stored_path))->toContain('WDP scan');
});

it('refuses the field on a requirement an officer wrote', function () {
    $app = filingWithoutTin('123-456-789-000');
    $req = OfficerRequest::create([
        'application_id' => $app->id,
        'requested_by_user_id' => null,
        'department_id' => Department::where('code', 'CHO')->value('id'),
        'request_type' => 'document',
        'status' => OfficerRequestStatus::Pending,
        // Titled like a DENR row on purpose: the key decides, never the prose.
        'title' => 'DENR WDP — Waste Water Discharge Permit',
    ]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/requests/{$req->id}/answer", ['tin' => '123-456-789-000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['request']);

    expect($req->fresh()->status)->toBe(OfficerRequestStatus::Pending);
});

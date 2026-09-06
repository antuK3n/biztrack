<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Business;
use App\Models\DocumentType;
use App\Models\PermitType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * What an applicant may do to a draft while filling it in (tester checklist
 * round 2, items 36, 39 and 47): name it, be stopped from declaring nonsense
 * in the tax profile, and take an attachment back off.
 */

/** Nena owns the sari-sari store; she is the applicant in every case below. */
function nenaStore(): Business
{
    return Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
}

function newDraft(array $overrides = []): array
{
    $business = nenaStore();
    $businessPt = PermitType::where('code', 'BUSINESS')->firstOrFail();

    return test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => [$businessPt->id],
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data');
}

/* ── Item 36 · naming a draft ───────────────────────────────────────────── */

it('stores the title an applicant gives a draft and returns it everywhere', function () {
    $draft = newDraft(['title' => 'Second branch, Longos']);

    expect($draft['title'])->toBe('Second branch, Longos');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/applications/{$draft['id']}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Second branch, Longos');

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/applications?status=draft')
        ->assertOk()
        ->assertJsonFragment(['title' => 'Second branch, Longos']);
});

it('renames a draft and treats a blank title as no title', function () {
    $draft = newDraft();
    expect($draft['title'])->toBeNull();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$draft['id']}", ['title' => '  Renewal for the carinderia  '])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renewal for the carinderia');

    // Clearing the box falls back to the business name, so it must store null.
    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$draft['id']}", ['title' => '   '])
        ->assertOk()
        ->assertJsonPath('data.title', null);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$draft['id']}", ['title' => str_repeat('x', 121)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');
});

it('will not let one applicant rename another applicant’s draft', function () {
    $draft = newDraft();

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->putJson("/api/v1/applications/{$draft['id']}", ['title' => 'Mine now'])
        ->assertForbidden();

    expect(Application::find($draft['id'])->title)->toBeNull();
});

/* ── Item 39 · the fee profile is numbers, not free text ────────────────── */

it('rejects a fee profile that is not made of sane numbers', function () {
    $draft = newDraft();
    $put = fn (array $profile) => $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$draft['id']}", ['fee_profile' => $profile]);

    $put(['lines' => [['category' => 'retailer', 'gross_sales' => 'plenty']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee_profile.lines.0.gross_sales');

    $put(['lines' => [['category' => 'retailer', 'capitalization' => -1]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee_profile.lines.0.capitalization');

    $put(['employees' => -3])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee_profile.employees');

    $put(['employees' => 4.5])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee_profile.employees');

    $put(['floor_area_sqm' => 'big'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee_profile.floor_area_sqm');

    // A trillion pesos of gross sales is a typo, not a declaration.
    $put(['lines' => [['category' => 'retailer', 'gross_sales' => 1_000_000_000_000]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee_profile.lines.0.gross_sales');

    $put(['employees' => 3, 'employees_in_lgu' => 5])
        ->assertStatus(422)
        ->assertJsonValidationErrors('fee_profile.employees_in_lgu');
});

it('accepts a complete, sane fee profile', function () {
    $draft = newDraft();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/applications/{$draft['id']}", [
            'fee_profile' => [
                'lines' => [['category' => 'retailer', 'capitalization' => 1000000]],
                'business_structure' => 'sole_proprietorship',
                'floor_area_sqm' => 45,
                'employees' => 3,
                'employees_in_lgu' => 3,
                'flags' => ['has_signage'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.fee_profile.employees', 3);
});

/* ── Item 47 · removing an attachment ───────────────────────────────────── */

function uploadDoc(int $applicationId, string $as = 'owner@biztrack.local'): array
{
    $type = DocumentType::query()->firstOrFail();

    return test()->withHeaders(authAs($as))
        ->postJson("/api/v1/applications/{$applicationId}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('barangay-clearance.pdf', 12, 'application/pdf'),
        ])
        ->assertCreated()
        ->json('data');
}

it('removes an uploaded document and the stored file with it', function () {
    Storage::fake('local');
    $draft = newDraft();
    $doc = uploadDoc($draft['id']);
    $path = ApplicationDocument::findOrFail($doc['id'])->stored_path;
    Storage::disk('local')->assertExists($path);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$draft['id']}/documents/{$doc['id']}")
        ->assertOk()
        ->assertJsonPath('data.id', $doc['id']);

    expect(ApplicationDocument::find($doc['id']))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('will not let one applicant delete another applicant’s document', function () {
    Storage::fake('local');
    $draft = newDraft();
    $doc = uploadDoc($draft['id']);

    $this->withHeaders(authAs('juan@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$draft['id']}/documents/{$doc['id']}")
        ->assertForbidden();

    expect(ApplicationDocument::find($doc['id']))->not->toBeNull();
    Storage::disk('local')->assertExists(ApplicationDocument::find($doc['id'])->stored_path);
});

it('will not remove a document from an application already under review', function () {
    Storage::fake('local');
    $draft = newDraft();
    $doc = uploadDoc($draft['id']);
    Application::whereKey($draft['id'])->update(['status' => ApplicationStatus::UnderReview]);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$draft['id']}/documents/{$doc['id']}")
        ->assertStatus(422);

    expect(ApplicationDocument::find($doc['id']))->not->toBeNull();
});

it('will not remove a document that belongs to a different application', function () {
    Storage::fake('local');
    $first = newDraft();
    $second = newDraft();
    $doc = uploadDoc($second['id']);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$first['id']}/documents/{$doc['id']}")
        ->assertNotFound();

    expect(ApplicationDocument::find($doc['id']))->not->toBeNull();
});

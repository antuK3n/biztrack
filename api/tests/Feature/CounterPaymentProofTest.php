<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PermitType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The owner's proof of a payment made at the counter (thesis panel revisions,
 * matrix item 4): "The applicant can now upload a photo of the receipt as
 * proof of payment, or enter the OR number and the date it was paid."
 *
 * Ken's rules: at least one of (OR number + date paid) or the photo; both
 * allowed; the date paid cannot be in the future; the photo takes what a
 * requirement takes (PDF, JPG or PNG, up to 10 MB); sending it does NOT mark
 * the bill paid; sending again before BPLO confirms replaces the pending proof
 * and keeps the old one; every submission is audited.
 */

/** A real one-page PDF, so a stored file holds bytes a test can compare. */
const OTC_PROOF_PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

beforeEach(function () {
    Storage::fake('local');
});

/** A filing of the demo owner's that BPLO has approved: Pending Payment. */
function otcProofFiling(): Application
{
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $owner = authAs('owner@biztrack.local');

    $draft = test()->withHeaders($owner)
        ->postJson('/api/v1/applications', [
            'business_id' => $business->id,
            'data_privacy_consent' => true,
            'application_type' => 'new',
            'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
        ])
        ->assertCreated()
        ->json('data');

    attachRequiredDocuments($draft['id']);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$draft['id']}/submit")->assertOk();

    return bploApprovesForm($draft['id']);
}

function otcSendProof(Application $filing, array $fields)
{
    authAs('owner@biztrack.local');

    return test()->post("/api/v1/applications/{$filing->id}/payment-proofs", $fields, ['Accept' => 'application/json']);
}

it('takes the OR number and the date paid, and marks nothing paid', function () {
    $filing = otcProofFiling();
    $ownerId = User::where('email', 'owner@biztrack.local')->value('id');

    $res = otcSendProof($filing, ['or_number' => '7654321', 'or_date' => now()->subDay()->toDateString()])
        ->assertCreated()
        ->assertJsonPath('data.or_number', '7654321')
        ->assertJsonPath('data.or_date', now()->subDay()->toDateString())
        ->assertJsonPath('data.has_photo', false);

    $proof = PaymentProof::findOrFail($res->json('data.id'));
    expect($proof->application_id)->toBe($filing->id)
        ->and($proof->submitted_by_user_id)->toBe($ownerId)
        ->and($proof->payment_id)->toBeNull()
        ->and($proof->fee_assessment_id)->toBe($filing->feeAssessment->id);

    // Proof is not payment: the bill is still owed and nothing was recorded.
    expect($filing->fresh()->status)->toBe(ApplicationStatus::PendingPayment)
        ->and(Payment::where('application_id', $filing->id)->count())->toBe(0);

    // Audited: who, which filing, which fields, no file.
    $audit = AuditLog::where('action', 'payment_proof.submitted')->where('auditable_id', $proof->id)->firstOrFail();
    expect($audit->user_id)->toBe($ownerId)
        ->and($audit->changes['application_id'])->toBe($filing->id)
        ->and($audit->changes['tracking_id'])->toBe($filing->tracking_id)
        ->and($audit->changes['or_number'])->toBe('7654321')
        ->and($audit->changes['or_date'])->toBe(now()->subDay()->toDateString())
        ->and($audit->changes['file'])->toBeNull();
});

it('takes a photo of the receipt alone, keeping the file privately', function () {
    $filing = otcProofFiling();

    $res = otcSendProof($filing, [
        'photo' => UploadedFile::fake()->createWithContent('receipt.pdf', OTC_PROOF_PDF),
    ])->assertCreated()
        ->assertJsonPath('data.has_photo', true)
        ->assertJsonPath('data.photo_name', 'receipt.pdf')
        ->assertJsonPath('data.or_number', null);

    $proof = PaymentProof::findOrFail($res->json('data.id'));
    expect($proof->file_path)->toStartWith("private/payment-proofs/{$filing->id}/")
        ->and(Storage::disk('local')->get($proof->file_path))->toBe(OTC_PROOF_PDF);

    $audit = AuditLog::where('action', 'payment_proof.submitted')->where('auditable_id', $proof->id)->firstOrFail();
    expect($audit->changes['file'])->toBe('receipt.pdf');
});

it('takes both at once', function () {
    $filing = otcProofFiling();

    otcSendProof($filing, [
        'or_number' => '7654322',
        'or_date' => now()->toDateString(),
        'photo' => UploadedFile::fake()->image('receipt.jpg'),
    ])->assertCreated()
        ->assertJsonPath('data.or_number', '7654322')
        ->assertJsonPath('data.has_photo', true);
});

it('refuses a proof with neither the OR nor a photo, with one plain sentence', function () {
    $filing = otcProofFiling();

    otcSendProof($filing, [])
        ->assertStatus(422)
        ->assertJsonPath('errors.or_number.0', 'Enter the OR number and the date paid, or attach a photo of the receipt.');

    expect(PaymentProof::count())->toBe(0);
});

it('refuses half of the OR pair, even beside a photo', function () {
    $filing = otcProofFiling();

    otcSendProof($filing, ['or_number' => '7654323'])
        ->assertStatus(422)
        ->assertJsonPath('errors.or_date.0', 'Enter the date paid.');

    otcSendProof($filing, [
        'or_date' => now()->toDateString(),
        'photo' => UploadedFile::fake()->image('receipt.png'),
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.or_number.0', 'Enter the OR number.');

    expect(PaymentProof::count())->toBe(0);
});

it('refuses a date paid in the future', function () {
    $filing = otcProofFiling();

    otcSendProof($filing, ['or_number' => '7654324', 'or_date' => now()->addDay()->toDateString()])
        ->assertStatus(422)
        ->assertJsonPath('errors.or_date.0', 'The date paid cannot be in the future.');
});

it('takes the files a requirement takes and no others', function () {
    $filing = otcProofFiling();

    otcSendProof($filing, ['photo' => UploadedFile::fake()->createWithContent('receipt.txt', 'not a receipt')])
        ->assertStatus(422)
        ->assertJsonPath('errors.photo.0', 'Upload a PDF, JPG, or PNG file.');

    otcSendProof($filing, ['photo' => UploadedFile::fake()->create('receipt.pdf', 10241, 'application/pdf')])
        ->assertStatus(422)
        ->assertJsonPath('errors.photo.0', 'The file may not be larger than 10MB.');

    otcSendProof($filing, ['photo' => UploadedFile::fake()->create('receipt.pdf', 10240, 'application/pdf')])
        ->assertCreated();
});

it('replaces the pending proof on a second send, keeping the first with its file and its audit', function () {
    $filing = otcProofFiling();

    $first = otcSendProof($filing, [
        'or_number' => '1111111',
        'or_date' => now()->subDays(2)->toDateString(),
        'photo' => UploadedFile::fake()->createWithContent('first.pdf', OTC_PROOF_PDF),
    ])->assertCreated()->json('data.id');

    $second = otcSendProof($filing, ['or_number' => '2222222', 'or_date' => now()->subDay()->toDateString()])
        ->assertCreated()
        ->json('data.id');

    $old = PaymentProof::findOrFail($first);
    expect($old->superseded_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists($old->file_path))->toBeTrue()
        ->and(AuditLog::where('action', 'payment_proof.submitted')->where('auditable_id', $first)->exists())->toBeTrue();

    expect(PaymentProof::pendingFor($filing)?->id)->toBe($second);
    expect(AuditLog::where('action', 'payment_proof.submitted')->where('auditable_id', $second)->firstOrFail()->changes['replaces_proof_id'])
        ->toBe($first);

    // Both readers see the newest one as the proof waiting on BPLO.
    authAs('owner@biztrack.local');
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof")
        ->assertOk()
        ->assertJsonPath('data.id', $second)
        ->assertJsonPath('data.or_number', '2222222');

    authAs('bplo@biztrack.local');
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof")
        ->assertOk()
        ->assertJsonPath('data.id', $second);
});

it('refuses proof for a filing BPLO has not billed, in the Pay page’s own words', function () {
    $business = Business::where('name', "Nena's Sari-Sari Store")->firstOrFail();
    $owner = authAs('owner@biztrack.local');
    $draft = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $business->id,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ])->assertCreated()->json('data');
    attachRequiredDocuments($draft['id']);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$draft['id']}/submit")->assertOk();

    otcSendProof(Application::findOrFail($draft['id']), ['or_number' => '7654325', 'or_date' => now()->toDateString()])
        ->assertStatus(422)
        ->assertJsonPath('errors.status.0', 'BPLO has not approved this application yet, so there is nothing to pay.');
});

it('lets the owner, BPLO and the super admin read the proof and its photo, and no clearance office', function () {
    $filing = otcProofFiling();
    $id = otcSendProof($filing, ['photo' => UploadedFile::fake()->createWithContent('receipt.pdf', OTC_PROOF_PDF)])
        ->assertCreated()
        ->json('data.id');

    foreach (['owner@biztrack.local', 'bplo@biztrack.local', 'admin@biztrack.local'] as $reader) {
        authAs($reader);
        test()->get("/api/v1/payment-proofs/{$id}/file")->assertOk();
        test()->getJson("/api/v1/applications/{$filing->id}/payment-proof")->assertOk()->assertJsonPath('data.id', $id);
    }

    authAs('sanitary@biztrack.local');
    test()->getJson("/api/v1/payment-proofs/{$id}/file")->assertForbidden();
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof")->assertForbidden();
});

it('answers no pending proof once nothing has been sent', function () {
    $filing = otcProofFiling();

    authAs('owner@biztrack.local');
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof")
        ->assertOk()
        ->assertJsonPath('data', null);
});

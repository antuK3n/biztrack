<?php

use App\Enums\ApplicationStatus;
use App\Jobs\SendOwnerUpdateEmail;
use App\Jobs\SendSms;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Department;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PermitType;
use App\Models\User;
use App\Support\DownloadLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/*
 * The owner's proof of a payment made at the counter (thesis panel revisions,
 * matrix item 4): "The applicant can now upload a photo of the receipt as
 * proof of payment, or enter the OR number and the date it was paid."
 *
 * Ken's rules: at least one of (OR number + date paid) or the photo; both
 * allowed; the date paid cannot be in the future; the photo takes what a
 * requirement takes (PDF, JPG or PNG, up to 10 MB); sending it does NOT mark
 * the bill paid; every submission is audited. One receipt waits for BPLO at a
 * time (client, 10 November 2026): a second send while one is pending is
 * refused, and the owner may send again only once BPLO rejects it.
 *
 * And BPLO hears of it in the bell, "Proof of payment received" (the tests at
 * the end): the officer holding the filing at BPLO, or every BPLO officer when
 * nobody does. Never the owner, a clearance office or the super admin.
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

it('refuses a second proof while the first is with BPLO, keeping the first as it was', function () {
    $filing = otcProofFiling();

    $first = otcSendProof($filing, [
        'or_number' => '1111111',
        'or_date' => now()->subDays(2)->toDateString(),
        'photo' => UploadedFile::fake()->createWithContent('first.pdf', OTC_PROOF_PDF),
    ])->assertCreated()->json('data.id');
    $filesBefore = Storage::disk('local')->allFiles();

    // Refused whatever it holds: an OR, a photo, or nothing at all.
    foreach ([
        ['or_number' => '2222222', 'or_date' => now()->subDay()->toDateString()],
        ['photo' => UploadedFile::fake()->createWithContent('second.pdf', OTC_PROOF_PDF)],
        [],
    ] as $fields) {
        otcSendProof($filing, $fields)
            ->assertStatus(422)
            ->assertJsonPath('errors.proof.0', 'Your receipt is already with BPLO.');
    }

    // Nothing kept: no row, no file, no audit; the first is untouched.
    expect(PaymentProof::count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe($filesBefore)
        ->and(AuditLog::where('action', 'payment_proof.submitted')->count())->toBe(1);
    $kept = PaymentProof::findOrFail($first);
    expect($kept->superseded_at)->toBeNull()
        ->and($kept->or_number)->toBe('1111111');

    // Both readers still see the first as the proof waiting on BPLO.
    foreach (['owner@biztrack.local', 'bplo@biztrack.local'] as $reader) {
        authAs($reader);
        test()->getJson("/api/v1/applications/{$filing->id}/payment-proof")
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.or_number', '1111111');
    }
});

it('reads the newest as the one waiting when a filing already holds several pending proofs', function () {
    $filing = otcProofFiling();
    $older = otcSendProof($filing, ['or_number' => '1111113', 'or_date' => now()->subDays(2)->toDateString()])
        ->assertCreated()->json('data.id');

    // A second pending row, as the old replace-on-send era could leave behind.
    $newer = PaymentProof::create([
        'application_id' => $filing->id,
        'fee_assessment_id' => $filing->feeAssessment->id,
        'submitted_by_user_id' => User::where('email', 'owner@biztrack.local')->value('id'),
        'or_number' => '2222224',
        'or_date' => now()->subDay()->toDateString(),
        'submitted_at' => now()->addSecond(),
    ])->id;

    expect($older)->not->toBe($newer)
        ->and(PaymentProof::pendingFor($filing)?->id)->toBe($newer)
        ->and(PaymentProof::latestFor($filing)?->id)->toBe($newer);

    otcSendProof($filing, ['or_number' => '3333333', 'or_date' => now()->toDateString()])
        ->assertStatus(422)
        ->assertJsonPath('errors.proof.0', 'Your receipt is already with BPLO.');
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

it('logs the photo of the receipt once when it is served, and not when it is refused', function () {
    $filing = otcProofFiling();
    $id = otcSendProof($filing, ['photo' => UploadedFile::fake()->createWithContent('receipt.pdf', OTC_PROOF_PDF)])
        ->assertCreated()
        ->json('data.id');

    authAs('sanitary@biztrack.local');
    test()->getJson("/api/v1/payment-proofs/{$id}/file")->assertForbidden();
    expect(AuditLog::where('action', DownloadLog::ACTION)->count())->toBe(0);

    authAs('bplo@biztrack.local');
    test()->get("/api/v1/payment-proofs/{$id}/file")->assertOk();

    $rows = AuditLog::where('action', DownloadLog::ACTION)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->user_id)->toBe(User::where('email', 'bplo@biztrack.local')->value('id'))
        ->and($rows->first()->changes)->toBe(['kind' => 'payment_proof', 'file' => 'receipt.pdf'])
        ->and($rows->first()->auditable_type)->toBe(PaymentProof::class)
        ->and($rows->first()->auditable_id)->toBe($id);
});

it('answers no pending proof once nothing has been sent', function () {
    $filing = otcProofFiling();

    authAs('owner@biztrack.local');
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof")
        ->assertOk()
        ->assertJsonPath('data', null);
});

/*
 * ── BPLO hears of it ─────────────────────────────────────────────────────────
 *
 * Revision matrix item 4, follow-up: until now nobody was told, and the officer
 * found the proof only by opening Mark as paid. Ken's rule is the one "New
 * application" uses for BPLO (WorkflowService::tellOffice): the officer holding
 * the filing at BPLO, or every active BPLO officer when nobody holds it. The
 * words are his: the title as given, the body built like "New application"'s.
 */

/** Every "Proof of payment received" notice written, oldest first. */
function otcProofNotices(?User $reader = null)
{
    return AppNotification::where('title', 'Proof of payment received')
        ->when($reader !== null, fn ($q) => $q->where('user_id', $reader->id))
        ->orderBy('id')
        ->get();
}

/** A second account at BPLO, with the seeded officer's roles. */
function otcBploSeat(string $email, bool $active = true): User
{
    $bplo = User::where('email', 'bplo@biztrack.local')->firstOrFail();

    $seat = User::create([
        'name' => "Seat {$email}", 'first_name' => 'Seat', 'last_name' => 'Account', 'gender' => 'F',
        'email' => $email, 'password' => 'biztrack1',
        'department_id' => Department::where('code', 'BPLO')->value('id'),
        'is_active' => $active,
    ]);
    $seat->roles()->sync($bplo->roles()->pluck('roles.id'));

    return $seat;
}

/** Put BPLO's case on this filing in an officer's hands, or in nobody's. */
function otcHeldBy(Application $filing, ?User $officer): void
{
    $review = $filing->bploAssignment()->firstOrFail();
    $review->update(['officer_user_id' => $officer?->id]);
}

it('tells the BPLO officer holding the filing, and nobody else, that proof of payment came in', function () {
    $filing = otcProofFiling();
    $holder = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    $colleague = otcBploSeat('bplo-colleague@biztrack.local');
    otcHeldBy($filing, $holder);

    Bus::fake([SendOwnerUpdateEmail::class, SendSms::class]);
    otcSendProof($filing, ['or_number' => '7654330', 'or_date' => now()->toDateString()])->assertCreated();

    // One notice, to the holder, in Ken's words.
    $notices = otcProofNotices();
    expect($notices)->toHaveCount(1);
    $notice = $notices->first();
    expect($notice->user_id)->toBe($holder->id)
        ->and($notice->title)->toBe('Proof of payment received')
        ->and($notice->body)->toBe("{$filing->tracking_id} · Nena's Sari-Sari Store sent proof of payment.")
        ->and($notice->link)->toBe('/staff/queue')
        ->and($notice->type)->toBe('status_change')
        ->and($notice->read_at)->toBeNull();

    // Not a colleague at BPLO while it is held, not the owner, not a clearance
    // office, not the super admin.
    foreach ([$colleague, ...User::whereIn('email', [
        'owner@biztrack.local', 'sanitary@biztrack.local', 'admin@biztrack.local',
    ])->get()] as $other) {
        expect(otcProofNotices($other))->toHaveCount(0);
    }

    // In the bell only, like "New application": no e-mail, no text.
    Bus::assertNotDispatched(SendOwnerUpdateEmail::class);
    Bus::assertNotDispatched(SendSms::class);
});

it('tells every active BPLO officer when nobody holds the filing', function () {
    $filing = otcProofFiling();
    $colleague = otcBploSeat('bplo-colleague@biztrack.local');
    $away = otcBploSeat('bplo-away@biztrack.local', active: false);
    otcHeldBy($filing, null);

    otcSendProof($filing, ['photo' => UploadedFile::fake()->createWithContent('receipt.pdf', OTC_PROOF_PDF)])
        ->assertCreated();

    // The same people "New application" reaches at BPLO: its active accounts
    // that can open the queue.
    $bploOfficers = User::where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->where('is_active', true)
        ->get()
        ->filter(fn (User $u) => $u->hasPermission('application.review'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
    expect($bploOfficers)->toContain(User::where('email', 'bplo@biztrack.local')->value('id'))
        ->toContain($colleague->id);

    $told = otcProofNotices();
    expect($told->pluck('user_id')->sort()->values()->all())->toBe($bploOfficers);
    foreach ($told as $notice) {
        expect($notice->body)->toBe("{$filing->tracking_id} · Nena's Sari-Sari Store sent proof of payment.")
            ->and($notice->link)->toBe('/staff/queue');
    }

    // Not an inactive BPLO account, the owner, a clearance office or the super admin.
    foreach ([$away, ...User::whereIn('email', [
        'owner@biztrack.local', 'sanitary@biztrack.local', 'admin@biztrack.local',
    ])->get()] as $other) {
        expect(otcProofNotices($other))->toHaveCount(0);
    }
});

it('tells the holder once when the owner sends again before BPLO acts, and again after a rejection', function () {
    $filing = otcProofFiling();
    $holder = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    otcHeldBy($filing, $holder);

    $first = otcSendProof($filing, ['or_number' => '1111112', 'or_date' => now()->subDay()->toDateString()])
        ->assertCreated()->json('data.id');
    otcSendProof($filing, ['or_number' => '2222223', 'or_date' => now()->toDateString()])
        ->assertStatus(422)
        ->assertJsonPath('errors.proof.0', 'Your receipt is already with BPLO.');

    expect(otcProofNotices($holder))->toHaveCount(1)
        ->and(otcProofNotices())->toHaveCount(1);

    // BPLO rejects it: the owner may send a new one, and the holder hears of it.
    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/payment-proofs/{$first}/reject", ['reason' => 'The photo is blurred.'])->assertOk();

    $second = otcSendProof($filing, ['or_number' => '2222223', 'or_date' => now()->toDateString()])
        ->assertCreated()->json('data.id');

    expect(PaymentProof::pendingFor($filing)?->id)->toBe($second)
        ->and(otcProofNotices($holder))->toHaveCount(2)
        ->and(otcProofNotices())->toHaveCount(2);
});

it('tells nobody when the proof is refused', function () {
    $filing = otcProofFiling();
    otcHeldBy($filing, null);

    // Refused on what was sent: neither the OR nor a photo, and half the OR.
    otcSendProof($filing, [])->assertStatus(422);
    otcSendProof($filing, ['or_number' => '7654331'])->assertStatus(422);
    otcSendProof($filing, ['or_number' => '7654332', 'or_date' => now()->addDay()->toDateString()])->assertStatus(422);

    // Refused because nothing is owed: a filing BPLO has not billed.
    $owner = authAs('owner@biztrack.local');
    $draft = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => Business::where('name', "Nena's Sari-Sari Store")->value('id'),
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ])->assertCreated()->json('data');
    attachRequiredDocuments($draft['id']);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$draft['id']}/submit")->assertOk();
    otcSendProof(Application::findOrFail($draft['id']), ['or_number' => '7654333', 'or_date' => now()->toDateString()])
        ->assertStatus(422);

    expect(PaymentProof::count())->toBe(0)
        ->and(otcProofNotices())->toHaveCount(0);
});

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
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * BPLO reviews the owner's counter receipt (Ken, 3 November 2026).
 *
 * Mark as paid waits for the owner's receipt (CounterPaymentTest, at the end),
 * and the BPLO officer holding the filing may reject the receipt instead, with
 * a reason. The rules pinned here:
 *
 *  - the holder alone: another BPLO officer, the super admin, a clearance
 *    office and the owner are refused with Mark as paid's own sentence;
 *  - a reason is required, at most 500 characters;
 *  - only the proof that is waiting can be rejected;
 *  - the row and its file are kept, and the rejection is audited;
 *  - the owner is told, in the bell and by e-mail, "Receipt not accepted",
 *    and nobody else is;
 *  - the Pending Payment card's data: the pending receipt's OR, and nothing
 *    once it is rejected; the owner's Pay page reads the rejection and why;
 *  - the owner sends a new receipt, BPLO hears of it again, and Mark as paid
 *    takes that one.
 */

const RCPT_HOLDER_ONLY = 'Only the officer handling this application can mark it as paid.';

const RCPT_REASON = 'The OR number does not match the receipt in the photo.';

const RCPT_PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

beforeEach(function () {
    Storage::fake('local');
});

/** A filing of the demo owner's on the bill, held at BPLO by the demo officer. */
function rcptFiling(): Application
{
    $owner = authAs('owner@biztrack.local');
    $draft = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => Business::where('name', "Nena's Sari-Sari Store")->value('id'),
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => [PermitType::where('code', 'BUSINESS')->value('id')],
    ])->assertCreated()->json('data');
    attachRequiredDocuments($draft['id']);
    test()->withHeaders($owner)->postJson("/api/v1/applications/{$draft['id']}/submit")->assertOk();

    $filing = bploApprovesForm($draft['id']);
    $filing->bploAssignment()->firstOrFail()->update([
        'officer_user_id' => User::where('email', 'bplo@biztrack.local')->value('id'),
    ]);

    return $filing->fresh();
}

/** The owner sends a receipt from the Pay page; answers its id. */
function rcptSend(Application $filing, array $fields = []): int
{
    authAs('owner@biztrack.local');

    return test()->post(
        "/api/v1/applications/{$filing->id}/payment-proofs",
        $fields ?: ['or_number' => '5100001', 'or_date' => now()->subDay()->toDateString()],
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.id');
}

/** Reject a receipt as whoever is signed in. */
function rcptReject(int $proofId, array $body = ['reason' => RCPT_REASON])
{
    return test()->postJson("/api/v1/payment-proofs/{$proofId}/reject", $body);
}

/** A second BPLO officer, so "another BPLO officer" is a real account. */
function rcptColleague(): User
{
    $user = User::create([
        'name' => 'Receipt Colleague', 'first_name' => 'Receipt', 'last_name' => 'Colleague', 'gender' => 'F',
        'email' => 'receipt.colleague@biztrack.local', 'password' => 'biztrack1',
        'department_id' => Department::where('code', 'BPLO')->value('id'),
        'is_active' => true, 'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', 'bplo_staff')->pluck('id'));

    return $user->fresh();
}

/** The Manage Applications list row for this filing, as the signed-in reader gets it. */
function rcptListRow(Application $filing): ?array
{
    return collect(test()->getJson('/api/v1/applications?status=pending_payment&per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $filing->id);
}

/* ── Who may reject ─────────────────────────────────────────────────────── */

it('lets only the BPLO officer holding the filing reject the receipt', function () {
    $filing = rcptFiling();
    $id = rcptSend($filing);
    $colleague = rcptColleague();

    // Another BPLO officer.
    app('auth')->forgetGuards();
    Sanctum::actingAs($colleague);
    rcptReject($id)->assertForbidden()->assertJsonPath('message', RCPT_HOLDER_ONLY);

    // The super admin, a clearance office and the owner.
    foreach (['admin@biztrack.local', 'sanitary@biztrack.local', 'owner@biztrack.local'] as $email) {
        authAs($email);
        rcptReject($id)->assertForbidden()->assertJsonPath('message', RCPT_HOLDER_ONLY);
    }

    // Nobody holding it: taken first, as Mark as paid is.
    $filing->bploAssignment()->firstOrFail()->update(['officer_user_id' => null]);
    authAs('bplo@biztrack.local');
    rcptReject($id)->assertForbidden()->assertJsonPath('message', RCPT_HOLDER_ONLY);

    $proof = PaymentProof::findOrFail($id);
    expect($proof->rejected_at)->toBeNull()
        ->and(AuditLog::where('action', 'payment_proof.rejected')->exists())->toBeFalse()
        ->and(AppNotification::where('title', 'Receipt not accepted')->exists())->toBeFalse();

    // The holder may.
    $filing->bploAssignment()->firstOrFail()->update(['officer_user_id' => User::where('email', 'bplo@biztrack.local')->value('id')]);
    rcptReject($id)->assertOk()->assertJsonPath('data.rejection_reason', RCPT_REASON);
});

/* ── The reason ─────────────────────────────────────────────────────────── */

it('requires a reason, of at most 500 characters', function () {
    $filing = rcptFiling();
    $id = rcptSend($filing);
    authAs('bplo@biztrack.local');

    rcptReject($id, [])->assertStatus(422)->assertJsonPath('errors.reason.0', 'Enter a reason.');
    rcptReject($id, ['reason' => '   '])->assertStatus(422)->assertJsonPath('errors.reason.0', 'Enter a reason.');
    rcptReject($id, ['reason' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('reason');

    expect(PaymentProof::findOrFail($id)->rejected_at)->toBeNull();

    rcptReject($id, ['reason' => str_repeat('x', 500)])->assertOk();
});

/* ── Which receipt ──────────────────────────────────────────────────────── */

it('rejects only the receipt that is waiting: not a replaced one, a rejected one or one already paid against', function () {
    $filing = rcptFiling();
    $replaced = rcptSend($filing, ['or_number' => '5100002', 'or_date' => now()->subDays(2)->toDateString()]);
    $rejected = rcptSend($filing, ['or_number' => '5100003', 'or_date' => now()->subDay()->toDateString()]);
    expect(PaymentProof::findOrFail($replaced)->superseded_at)->not->toBeNull();

    authAs('bplo@biztrack.local');
    $gone = 'This receipt is no longer waiting for BPLO.';

    // Replaced by the owner's newer one.
    rcptReject($replaced)->assertStatus(422)->assertJsonPath('errors.proof.0', $gone);
    expect(PaymentProof::findOrFail($replaced)->rejected_at)->toBeNull();

    // Already rejected.
    rcptReject($rejected)->assertOk();
    rcptReject($rejected, ['reason' => 'Again.'])->assertStatus(422)->assertJsonPath('errors.proof.0', $gone);
    expect(PaymentProof::findOrFail($rejected)->rejection_reason)->toBe(RCPT_REASON);

    // Marked paid against it.
    $paid = rcptSend($filing, ['or_number' => '5100004', 'or_date' => now()->toDateString()]);
    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/applications/{$filing->id}/counter-payment", ['or_number' => '5100004', 'or_date' => now()->toDateString()])
        ->assertCreated();
    rcptReject($paid)->assertStatus(422)->assertJsonPath('errors.proof.0', $gone);
    expect(PaymentProof::findOrFail($paid)->rejected_at)->toBeNull()
        ->and(PaymentProof::findOrFail($paid)->payment_id)->not->toBeNull();
});

/* ── What it does ───────────────────────────────────────────────────────── */

it('keeps the rejected receipt and its file, and audits who, which filing, which OR and why', function () {
    $filing = rcptFiling();
    $id = rcptSend($filing, [
        'or_number' => '5100005',
        'or_date' => now()->subDay()->toDateString(),
        'photo' => UploadedFile::fake()->createWithContent('receipt.pdf', RCPT_PDF),
    ]);
    $officer = User::where('email', 'bplo@biztrack.local')->firstOrFail();

    authAs('bplo@biztrack.local');
    rcptReject($id, ['reason' => '  '.RCPT_REASON.'  '])
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.rejection_reason', RCPT_REASON);

    $proof = PaymentProof::findOrFail($id);
    expect($proof->rejected_at)->not->toBeNull()
        ->and($proof->rejected_by_user_id)->toBe($officer->id)
        ->and($proof->rejection_reason)->toBe(RCPT_REASON)
        ->and($proof->superseded_at)->toBeNull()
        ->and($proof->payment_id)->toBeNull()
        // Kept, file and all.
        ->and(Storage::disk('local')->get($proof->file_path))->toBe(RCPT_PDF);

    $audit = AuditLog::where('action', 'payment_proof.rejected')->sole();
    expect($audit->user_id)->toBe($officer->id)
        ->and($audit->auditable_type)->toBe(PaymentProof::class)
        ->and($audit->auditable_id)->toBe($id)
        ->and($audit->changes)->toBe([
            'application_id' => $filing->id,
            'tracking_id' => $filing->tracking_id,
            'or_number' => '5100005',
            'reason' => RCPT_REASON,
        ]);

    // No receipt is waiting, so the bill stays unpaid and nothing was recorded.
    expect(PaymentProof::pendingFor($filing))->toBeNull()
        ->and($filing->fresh()->status)->toBe(ApplicationStatus::PendingPayment)
        ->and(Payment::where('application_id', $filing->id)->count())->toBe(0);
});

it('tells the owner in the bell and by e-mail, in Ken’s words, with a link to the Pay page', function () {
    $filing = rcptFiling();
    $id = rcptSend($filing);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    Bus::fake([SendOwnerUpdateEmail::class, SendSms::class]);
    authAs('bplo@biztrack.local');
    rcptReject($id)->assertOk();

    $notice = AppNotification::where('title', 'Receipt not accepted')->sole();
    $body = "{$filing->tracking_id} · Nena's Sari-Sari Store: ".RCPT_REASON;
    expect($notice->user_id)->toBe($owner->id)
        ->and($notice->body)->toBe($body)
        ->and($notice->link)->toBe("/applications/{$filing->id}/pay")
        ->and($notice->read_at)->toBeNull();

    Bus::assertDispatched(SendOwnerUpdateEmail::class, fn (SendOwnerUpdateEmail $mail) => $mail->owner->is($owner)
        && $mail->title === 'Receipt not accepted'
        && $mail->body === $body
        && $mail->link === "/applications/{$filing->id}/pay"
        && $mail->reference === $filing->tracking_id
        && $mail->businessName === "Nena's Sari-Sari Store");
    Bus::assertDispatchedTimes(SendOwnerUpdateEmail::class, 1);
    // No text: those are for the key moments.
    Bus::assertNotDispatched(SendSms::class);
});

it('tells nobody but the owner', function () {
    $filing = rcptFiling();
    $id = rcptSend($filing);
    rcptColleague();
    $before = AppNotification::max('id') ?? 0;

    authAs('bplo@biztrack.local');
    rcptReject($id)->assertOk();

    $written = AppNotification::where('id', '>', $before)->get();
    expect($written)->toHaveCount(1)
        ->and($written->first()->user_id)->toBe(User::where('email', 'owner@biztrack.local')->value('id'));
});

it('tells nobody when the rejection is refused', function () {
    $filing = rcptFiling();
    $id = rcptSend($filing);
    $before = AppNotification::max('id') ?? 0;

    authAs('bplo@biztrack.local');
    rcptReject($id, [])->assertStatus(422);
    authAs('admin@biztrack.local');
    rcptReject($id)->assertForbidden();

    expect(AppNotification::where('id', '>', $before)->count())->toBe(0);
});

/* ── What the screens read ──────────────────────────────────────────────── */

it('gives the Pending Payment card the waiting receipt’s OR, and nothing once it is rejected', function () {
    $filing = rcptFiling();

    // Nothing sent yet: the key is there, and empty.
    authAs('bplo@biztrack.local');
    expect(rcptListRow($filing))->toHaveKey('pending_proof')
        ->and(rcptListRow($filing)['pending_proof'])->toBeNull();

    $id = rcptSend($filing, ['or_number' => '5100006', 'or_date' => now()->subDay()->toDateString()]);

    // BPLO and the super admin read it.
    foreach (['bplo@biztrack.local', 'admin@biztrack.local'] as $reader) {
        authAs($reader);
        expect(rcptListRow($filing)['pending_proof'])->toBe(['id' => $id, 'or_number' => '5100006']);
    }

    // The owner's own list carries no such thing.
    authAs('owner@biztrack.local');
    $own = collect(test()->getJson('/api/v1/applications?per_page=200')->assertOk()->json('data'))->firstWhere('id', $filing->id);
    expect($own)->not->toHaveKey('pending_proof');

    // Rejected: back to nothing.
    authAs('bplo@biztrack.local');
    rcptReject($id)->assertOk();
    expect(rcptListRow($filing)['pending_proof'])->toBeNull();
});

it('gives the card a photo sent without an OR as a receipt with no OR number', function () {
    $filing = rcptFiling();
    $id = rcptSend($filing, ['photo' => UploadedFile::fake()->createWithContent('receipt.pdf', RCPT_PDF)]);

    authAs('bplo@biztrack.local');
    expect(rcptListRow($filing)['pending_proof'])->toBe(['id' => $id, 'or_number' => null]);
});

it('shows the owner their rejected receipt with the reason, while BPLO’s pending read shows none', function () {
    $filing = rcptFiling();

    // Nothing sent: nothing to show.
    authAs('owner@biztrack.local');
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof/latest")->assertOk()->assertJsonPath('data', null);

    $id = rcptSend($filing, ['or_number' => '5100007', 'or_date' => now()->subDay()->toDateString()]);
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof/latest")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.rejected_at', null)
        ->assertJsonPath('data.rejection_reason', null);

    authAs('bplo@biztrack.local');
    rcptReject($id)->assertOk();

    authAs('owner@biztrack.local');
    $latest = test()->getJson("/api/v1/applications/{$filing->id}/payment-proof/latest")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.or_number', '5100007')
        ->assertJsonPath('data.or_date', now()->subDay()->toDateString())
        ->assertJsonPath('data.rejection_reason', RCPT_REASON);
    expect($latest->json('data.rejected_at'))->not->toBeNull();

    // What Mark as paid and Reject receipt read: nothing is waiting.
    foreach (['owner@biztrack.local', 'bplo@biztrack.local'] as $reader) {
        authAs($reader);
        test()->getJson("/api/v1/applications/{$filing->id}/payment-proof")->assertOk()->assertJsonPath('data', null);
    }

    // The same readers as the pending read, and no clearance office.
    authAs('sanitary@biztrack.local');
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof/latest")->assertForbidden();
});

/* ── And again ──────────────────────────────────────────────────────────── */

it('takes a new receipt after a rejection, tells BPLO again, and marks the bill paid against the new one', function () {
    $filing = rcptFiling();
    $holder = User::where('email', 'bplo@biztrack.local')->firstOrFail();
    $first = rcptSend($filing, ['or_number' => '5100008', 'or_date' => now()->subDay()->toDateString()]);

    authAs('bplo@biztrack.local');
    rcptReject($first)->assertOk();

    $told = AppNotification::where('title', 'Proof of payment received')->where('user_id', $holder->id)->count();
    $second = rcptSend($filing, ['or_number' => '5100009', 'or_date' => now()->toDateString()]);

    // BPLO hears of the new one, in the words it always has.
    $notices = AppNotification::where('title', 'Proof of payment received')->where('user_id', $holder->id)->orderBy('id')->get();
    expect($notices)->toHaveCount($told + 1)
        ->and($notices->last()->body)->toBe("{$filing->tracking_id} · Nena's Sari-Sari Store sent proof of payment.");

    // The new one is waiting; the rejected one stays rejected, not replaced.
    expect(PaymentProof::pendingFor($filing)?->id)->toBe($second)
        ->and(PaymentProof::findOrFail($first)->superseded_at)->toBeNull()
        ->and(PaymentProof::findOrFail($first)->rejected_at)->not->toBeNull();

    // The owner's Pay page reads the new one, with no rejection on it.
    authAs('owner@biztrack.local');
    test()->getJson("/api/v1/applications/{$filing->id}/payment-proof/latest")
        ->assertOk()
        ->assertJsonPath('data.id', $second)
        ->assertJsonPath('data.rejection_reason', null);

    // And the card names it.
    authAs('bplo@biztrack.local');
    expect(rcptListRow($filing)['pending_proof'])->toBe(['id' => $second, 'or_number' => '5100009']);

    // Mark as paid takes it, and links that one alone.
    test()->postJson("/api/v1/applications/{$filing->id}/counter-payment", ['or_number' => '5100009', 'or_date' => now()->toDateString()])
        ->assertCreated();
    $payment = Payment::where('application_id', $filing->id)->where('method', 'counter')->sole();
    expect($payment->proofs()->pluck('id')->all())->toBe([$second])
        ->and(PaymentProof::findOrFail($first)->payment_id)->toBeNull();
});

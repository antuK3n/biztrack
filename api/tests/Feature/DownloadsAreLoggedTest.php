<?php

use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageThread;
use App\Models\OfficerRequest;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\User;
use App\Support\DownloadLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/*
 * Every file served to a signed-in reader is on the audit trail (thesis panel
 * revision matrix, item 2: "Every download is logged with the user, the file,
 * and the date and time").
 *
 * One test per family of endpoint. Each asks first as somebody who is refused
 * and expects no row — a row written before the refusal would record a
 * download nobody received — then as somebody who is served, and expects
 * exactly one row naming that reader, the file as their browser saved it, the
 * record it belongs to and a time.
 */

beforeEach(function () {
    Storage::fake('local');
});

/** Every download row written so far. */
function dlRows(): Collection
{
    return AuditLog::where('action', DownloadLog::ACTION)->orderBy('id')->get();
}

/** Exactly one download row, by this reader, of this file, on this record. */
function dlAssertOne(string $email, string $kind, string $file, ?Model $subject): void
{
    $rows = dlRows();
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row->user_id)->toBe(User::where('email', $email)->value('id'))
        ->and($row->changes)->toBe(['kind' => $kind, 'file' => $file])
        ->and($row->auditable_type)->toBe($subject === null ? null : $subject::class)
        ->and($row->auditable_id)->toBe($subject?->getKey())
        ->and($row->created_at)->not->toBeNull();
}

function dlUserId(string $email): int
{
    return User::where('email', $email)->value('id');
}

/** A filing the seeded account filed itself. */
function dlFilingOf(string $email): Application
{
    return Application::where('applicant_user_id', dlUserId($email))->orderBy('id')->firstOrFail();
}

it('logs an uploaded document once when it is served, and not when it is refused or missing', function () {
    $document = ApplicationDocument::create([
        'application_id' => dlFilingOf('owner@biztrack.local')->id,
        'document_type_id' => DocumentType::where('code', 'VALID_ID')->value('id'),
        // Not plain ASCII, so the name logged has to be the real one and not
        // the stand-in the download header carries beside it.
        'original_filename' => 'Valid ID – Dela Cruz.pdf',
        'stored_path' => 'private/documents/dl/valid-id.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 16,
    ]);

    authAs('juan@biztrack.local');
    $this->get("/api/v1/documents/{$document->id}/download")->assertForbidden();

    // Allowed, but the stored copy is gone.
    authAs('owner@biztrack.local');
    $this->get("/api/v1/documents/{$document->id}/download")->assertNotFound();
    expect(dlRows())->toBeEmpty();

    Storage::disk('local')->put($document->stored_path, '%PDF-1.4 fixture');
    $this->get("/api/v1/documents/{$document->id}/download")->assertOk();

    dlAssertOne('owner@biztrack.local', 'document', 'Valid ID – Dela Cruz.pdf', $document);
});

it('logs a message attachment on a filing once when it is served', function () {
    $juan = User::where('email', 'juan@biztrack.local')->firstOrFail();
    $thread = MessageThread::whereHas('application', fn ($q) => $q->where('applicant_user_id', $juan->id))
        ->orderBy('id')->firstOrFail();
    $message = Message::create(['thread_id' => $thread->id, 'sender_user_id' => $juan->id, 'body' => 'The lease.']);
    $attachment = MessageAttachment::create([
        'message_id' => $message->id,
        'original_filename' => 'lease.pdf',
        'stored_path' => 'private/messages/dl/lease.pdf',
        'mime' => 'application/pdf',
        'size' => 16,
    ]);
    Storage::disk('local')->put($attachment->stored_path, '%PDF-1.4 fixture');

    authAs('owner@biztrack.local');
    $this->get("/api/v1/message-attachments/{$attachment->id}/download")->assertForbidden();
    expect(dlRows())->toBeEmpty();

    authAs('juan@biztrack.local');
    $this->get("/api/v1/message-attachments/{$attachment->id}/download")->assertOk();

    dlAssertOne('juan@biztrack.local', 'message_attachment', 'lease.pdf', $attachment);
});

// The enquiry branch returns from its own line, so it is pinned on its own.
it('logs a message attachment on an enquiry once when it is served', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $thread = MessageThread::create(['application_id' => null, 'user_id' => $owner->id]);
    $message = Message::create(['thread_id' => $thread->id, 'sender_user_id' => $owner->id, 'body' => 'Is this enough?']);
    $attachment = MessageAttachment::create([
        'message_id' => $message->id,
        'original_filename' => 'floor-plan.png',
        'stored_path' => 'private/messages/general/dl/floor-plan.png',
        'mime' => 'image/png',
        'size' => 16,
    ]);
    Storage::disk('local')->put($attachment->stored_path, 'not really a png');

    authAs('juan@biztrack.local');
    $this->get("/api/v1/message-attachments/{$attachment->id}/download")->assertForbidden();
    expect(dlRows())->toBeEmpty();

    authAs('owner@biztrack.local');
    $this->get("/api/v1/message-attachments/{$attachment->id}/download")->assertOk();

    dlAssertOne('owner@biztrack.local', 'message_attachment', 'floor-plan.png', $attachment);
});

it('logs an office’s reference file once when it is served', function () {
    // The seeded requirement BPLO raised on Juan's filing, given a blank form.
    $requirement = OfficerRequest::whereHas('application', fn ($q) => $q->where('applicant_user_id', dlUserId('juan@biztrack.local')))
        ->orderBy('id')->firstOrFail();
    $requirement->update([
        'reference_path' => 'private/requirement-references/dl/blank-form.pdf',
        'reference_name' => 'blank-form.pdf',
    ]);
    Storage::disk('local')->put($requirement->reference_path, '%PDF-1.4 fixture');

    authAs('owner@biztrack.local');
    $this->get("/api/v1/requests/{$requirement->id}/reference")->assertForbidden();
    expect(dlRows())->toBeEmpty();

    authAs('juan@biztrack.local');
    $this->get("/api/v1/requests/{$requirement->id}/reference")->assertOk();

    dlAssertOne('juan@biztrack.local', 'requirement_reference', 'blank-form.pdf', $requirement);
});

it('logs a payment’s invoice once when it is served', function () {
    $payment = Payment::where('status', PaymentStatus::Completed)
        ->whereHas('application', fn ($q) => $q->where('applicant_user_id', dlUserId('owner@biztrack.local')))
        ->orderBy('id')->firstOrFail();

    authAs('juan@biztrack.local');
    $this->get("/api/v1/payments/{$payment->id}/receipt")->assertForbidden();
    expect(dlRows())->toBeEmpty();

    authAs('owner@biztrack.local');
    $this->get("/api/v1/payments/{$payment->id}/receipt")->assertOk();

    dlAssertOne('owner@biztrack.local', 'invoice', "invoice-{$payment->reference_number}.pdf", $payment);
});

it('logs a permit certificate once when it is served', function () {
    $permit = Permit::whereHas('business', fn ($q) => $q->where('owner_user_id', dlUserId('owner@biztrack.local')))
        ->orderBy('id')->firstOrFail();

    authAs('juan@biztrack.local');
    $this->get("/api/v1/permits/{$permit->id}/pdf")->assertForbidden();
    expect(dlRows())->toBeEmpty();

    authAs('owner@biztrack.local');
    $this->get("/api/v1/permits/{$permit->id}/pdf")->assertOk();

    dlAssertOne('owner@biztrack.local', 'permit', "permit-{$permit->permit_number}.pdf", $permit);
});

it('logs a sworn declaration page once when it is served', function () {
    $filing = dlFilingOf('owner@biztrack.local');

    authAs('juan@biztrack.local');
    $this->get("/api/v1/applications/{$filing->id}/office-forms/ZONING/declaration")->assertForbidden();

    // A sheet with no declaration on its paper.
    authAs('owner@biztrack.local');
    $this->get("/api/v1/applications/{$filing->id}/office-forms/CEC/declaration")->assertNotFound();
    expect(dlRows())->toBeEmpty();

    $this->get("/api/v1/applications/{$filing->id}/office-forms/ZONING/declaration")->assertOk();

    dlAssertOne(
        'owner@biztrack.local',
        'declaration',
        "locational-clearance-declaration-{$filing->tracking_id}.pdf",
        $filing,
    );
});

/*
 * The reports, the exports and the import template are drawn from the whole
 * register or from nothing in it, so they carry no record: the row says who
 * and which file, and its subject is empty.
 */
it('logs a report, export or template once when it is served', function (string $url, string $refused, string $served, string $kind, string $file) {
    authAs($refused);
    $this->get($url)->assertForbidden();
    expect(dlRows())->toBeEmpty();

    authAs($served);
    $this->get($url)->assertOk();

    dlAssertOne($served, $kind, $file, null);
})->with([
    'the analytics summary CSV' => [
        '/api/v1/analytics/export',
        'sanitary@biztrack.local', 'bplo@biztrack.local',
        'analytics_export', 'analytics-summary.csv',
    ],
    'the dashboard PDF' => [
        '/api/v1/analytics/dashboard/report?office=BFP',
        'sanitary@biztrack.local', 'fire@biztrack.local',
        'dashboard_report', 'analytics-dashboard-bfp.pdf',
    ],
    'the processing time PDF' => [
        '/api/v1/analytics/processing-time/report',
        'bplo@biztrack.local', 'admin@biztrack.local',
        'processing_time_report', 'processing-time-monitoring.pdf',
    ],
    'a report CSV' => [
        '/api/v1/analytics/reports/permits-issued/csv?from=2026-01-01&to=2026-09-30&office=BFP',
        'sanitary@biztrack.local', 'fire@biztrack.local',
        'lgu_report', 'permits-issued-bfp-2026-01-01-to-2026-09-30.csv',
    ],
    'the import template' => [
        '/api/v1/admin/legacy-imports/template',
        'bplo@biztrack.local', 'admin@biztrack.local',
        'import_template', 'biztrack-legacy-import-template.csv',
    ],
]);

it('lists a download on the Audit Logs feed under its own action', function () {
    authAs('admin@biztrack.local');
    $this->get('/api/v1/admin/legacy-imports/template')->assertOk();

    $this->getJson('/api/v1/admin/audit-logs?action='.DownloadLog::ACTION)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'file.downloaded')
        ->assertJsonPath('data.0.user.name', User::where('email', 'admin@biztrack.local')->value('name'))
        ->assertJsonPath('data.0.changes.file', 'biztrack-legacy-import-template.csv');
});

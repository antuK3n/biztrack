<?php

use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Department;
use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\PermitType;
use App\Support\DownloadLog;
use App\Support\PaymentAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * Audit Logs' "Payments" filter, and the receipt photos on its rows.
 *
 * The filter is asked of the server (`group=payments`) so it pages and sorts
 * like All actions, Removed and Downloaded a file. Each row also names the
 * receipt files it is about — id, type and name, never the stored path — so
 * Details can fetch the pictures; listing the rows fetches none of them and
 * writes no `file.downloaded` row.
 */

beforeEach(function () {
    Storage::fake('local');
});

/** One audit row, written as Audit::log would write it. */
function apRow(string $action, array $changes = [], ?string $type = null, ?int $id = null): AuditLog
{
    return AuditLog::create([
        'action' => $action,
        'auditable_type' => $type,
        'auditable_id' => $id,
        'changes' => $changes ?: null,
    ]);
}

/** A receipt on some seeded filing; with a file on the disk unless told otherwise. */
function apProof(?string $name = 'receipt.jpg', ?string $mime = 'image/jpeg', bool $onDisk = true): PaymentProof
{
    $path = $name === null ? null : 'private/payment-proofs/ap/'.uniqid().'-'.$name;
    if ($path !== null && $onDisk) {
        Storage::disk('local')->put($path, 'bytes');
    }

    return PaymentProof::create([
        'application_id' => Application::query()->value('id'),
        'or_number' => '7000001',
        'file_path' => $path,
        'original_filename' => $name,
        'mime_type' => $name === null ? null : $mime,
        'submitted_at' => now(),
    ]);
}

/** The super admin reads the trail. */
function apGet(string $query): array
{
    authAs('admin@biztrack.local');

    return test()->getJson('/api/v1/admin/audit-logs?'.$query)->assertOk()->json();
}

/** One row of the answer, by its id. */
function apRowOf(array $body, AuditLog $log): array
{
    $row = collect($body['data'])->firstWhere('id', $log->id);
    expect($row)->not->toBeNull();

    return $row;
}

/** A filing of the demo owner's that BPLO has approved and the demo BPLO officer holds. */
function apBilledFiling(): Application
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

    $assignment = ApplicationAssignment::where('application_id', $filing->id)
        ->where('department_id', Department::where('code', 'BPLO')->value('id'))
        ->firstOrFail();
    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/assignments/{$assignment->id}/claim")->assertOk();

    return $filing->fresh();
}

/** The owner sends a photo of the receipt from the Pay page; answers the proof. */
function apOwnerSendsPhoto(Application $filing, string $name = 'receipt.png'): PaymentProof
{
    authAs('owner@biztrack.local');
    $id = test()->post("/api/v1/applications/{$filing->id}/payment-proofs", [
        'or_number' => '7100001',
        'or_date' => now()->toDateString(),
        'photo' => UploadedFile::fake()->image($name),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    return PaymentProof::findOrFail($id);
}

it('lists every payment action under Payments and nothing else', function () {
    $proof = apProof();
    $mine = [
        apRow('payment.completed', ['amount' => '500.00'], Payment::class, 1),
        apRow('payment.abandoned', ['reason' => 'counter_payment'], Payment::class, 2),
        apRow('payment.flagged', ['reason' => 'double'], Payment::class, 3),
        apRow('payment.callback_refused', ['why' => 'signature']),
        apRow('payment_proof.submitted', ['file' => 'receipt.jpg'], PaymentProof::class, $proof->id),
        apRow('payment_proof.rejected', ['reason' => 'blurred'], PaymentProof::class, $proof->id),
        apRow('payment_gateway.switched', ['from' => 'simulated', 'to' => 'kwikpay']),
        apRow('debug.payments', ['before' => [], 'after' => []]),
    ];
    // Near misses: a receipt being opened, a fee billed later, the trail's ordinary traffic.
    $others = [
        apRow(DownloadLog::ACTION, ['kind' => 'payment_proof', 'file' => 'receipt.jpg'], PaymentProof::class, $proof->id),
        apRow(DownloadLog::ACTION, ['kind' => 'invoice', 'file' => 'invoice.pdf'], Payment::class, 1),
        apRow('permit_fee.deferred', ['amount' => 100]),
        apRow('application.submitted'),
        apRow('user.logged_in'),
        apRow('debug.filings'),
    ];

    $body = apGet('group=payments&per_page=200');
    $ids = collect($body['data'])->pluck('id');

    expect(collect($body['data'])->pluck('action')->every(fn ($a) => in_array($a, PaymentAudit::ACTIONS, true)))->toBeTrue()
        ->and($ids)->toContain(...collect($mine)->pluck('id'))
        ->and($ids->intersect(collect($others)->pluck('id')))->toBeEmpty()
        // Every payment row in the register, the seeded storyline's included.
        ->and($body['meta']['total'])->toBe(AuditLog::whereIn('action', PaymentAudit::ACTIONS)->count());
});

it('names every payment action the app writes, so a new one cannot drop out of the filter', function () {
    $written = collect(File::allFiles(app_path()))
        ->flatMap(function ($file) {
            preg_match_all("/Audit::log\\(\\s*'(payment[a-z_]*\\.[a-z_]+)'/", $file->getContents(), $m);

            return $m[1];
        })
        ->unique()
        ->values();

    // The grep found something, or this test asserts nothing.
    expect($written)->toContain('payment.completed', 'payment_proof.submitted', 'payment_gateway.switched');
    expect($written->diff(PaymentAudit::ACTIONS)->values()->all())->toBe([]);
});

it('pages the Payments filter newest first, like every other filter', function () {
    $made = collect(range(1, 5))->map(function (int $i) {
        $row = apRow('payment.completed', ['amount' => "{$i}00.00"], Payment::class, $i);
        $row->forceFill(['created_at' => now()->addMinutes($i)])->save();

        return $row;
    });
    apRow('user.logged_in')->forceFill(['created_at' => now()->addHour()])->save();

    $expected = AuditLog::whereIn('action', PaymentAudit::ACTIONS)
        ->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

    $seen = [];
    $lastPage = (int) ceil(count($expected) / 2);
    foreach (range(1, $lastPage) as $page) {
        $body = apGet("group=payments&per_page=2&page={$page}");
        expect($body['meta'])->toMatchArray([
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => 2,
            'total' => count($expected),
        ]);
        $seen = [...$seen, ...collect($body['data'])->pluck('id')->all()];
    }

    // The five just written are the newest, so they open page 1, newest first.
    expect(array_slice($seen, 0, 5))->toBe($made->reverse()->pluck('id')->values()->all())
        ->and($seen)->toBe($expected);
});

it('refuses a group it does not know', function () {
    authAs('admin@biztrack.local');
    test()->getJson('/api/v1/admin/audit-logs?group=everything')->assertStatus(422);
});

it('names no receipt on a payment that had none, one, or two', function () {
    $owners = apProof('owner-receipt.jpg', 'image/jpeg');
    $officers = apProof('or-7000001.pdf', 'application/pdf');

    $online = apRow('payment.completed', ['amount' => '500.00', 'gateway' => 'kwikpay'], Payment::class, 1);
    $none = apRow('payment.completed', ['amount' => '500.00', 'via' => 'counter', 'proof_ids' => []], Payment::class, 2);
    $one = apRow('payment.completed', ['amount' => '500.00', 'via' => 'counter', 'proof_ids' => [$owners->id]], Payment::class, 3);
    $two = apRow('payment.completed', ['amount' => '500.00', 'via' => 'counter', 'proof_ids' => [$owners->id, $officers->id]], Payment::class, 4);

    $body = apGet('group=payments&per_page=200');

    expect(apRowOf($body, $online)['receipts'])->toBe([])
        ->and(apRowOf($body, $none)['receipts'])->toBe([])
        ->and(apRowOf($body, $one)['receipts'])->toBe([
            ['id' => $owners->id, 'mime_type' => 'image/jpeg', 'file_name' => 'owner-receipt.jpg'],
        ])
        // In the order the payment names them: the owner's, then the officer's.
        ->and(apRowOf($body, $two)['receipts'])->toBe([
            ['id' => $owners->id, 'mime_type' => 'image/jpeg', 'file_name' => 'owner-receipt.jpg'],
            ['id' => $officers->id, 'mime_type' => 'application/pdf', 'file_name' => 'or-7000001.pdf'],
        ]);
});

it('names the proof itself on a submitted or a rejected receipt', function () {
    $proof = apProof('receipt.png', 'image/png');
    $submitted = apRow('payment_proof.submitted', ['file' => 'receipt.png'], PaymentProof::class, $proof->id);
    $rejected = apRow('payment_proof.rejected', ['reason' => 'blurred'], PaymentProof::class, $proof->id);

    $body = apGet('group=payments&per_page=200');
    $expected = [['id' => $proof->id, 'mime_type' => 'image/png', 'file_name' => 'receipt.png']];

    expect(apRowOf($body, $submitted)['receipts'])->toBe($expected)
        ->and(apRowOf($body, $rejected)['receipts'])->toBe($expected);
});

it('names no receipt that has no file, or whose file has gone', function () {
    $orOnly = apProof(null);
    $gone = apProof('gone.jpg', 'image/jpeg', onDisk: false);
    $kept = apProof('kept.jpg', 'image/jpeg');

    $paid = apRow('payment.completed', ['proof_ids' => [$orOnly->id, $gone->id, $kept->id]], Payment::class, 1);
    $sentWithoutPhoto = apRow('payment_proof.submitted', ['file' => null], PaymentProof::class, $orOnly->id);
    $sentAndLost = apRow('payment_proof.submitted', ['file' => 'gone.jpg'], PaymentProof::class, $gone->id);
    $deletedProof = apRow('payment_proof.rejected', ['reason' => 'x'], PaymentProof::class, 999999);

    $body = apGet('group=payments&per_page=200');

    expect(collect(apRowOf($body, $paid)['receipts'])->pluck('id')->all())->toBe([$kept->id])
        ->and(apRowOf($body, $sentWithoutPhoto)['receipts'])->toBe([])
        ->and(apRowOf($body, $sentAndLost)['receipts'])->toBe([])
        ->and(apRowOf($body, $deletedProof)['receipts'])->toBe([]);
});

it('never gives the screen a stored path, and fetches no file to list the rows', function () {
    $proof = apProof('receipt.jpg');
    apRow('payment.completed', ['proof_ids' => [$proof->id]], Payment::class, 1);
    $downloads = AuditLog::where('action', DownloadLog::ACTION)->count();

    authAs('admin@biztrack.local');
    $raw = test()->getJson('/api/v1/admin/audit-logs?group=payments&per_page=200')->assertOk()->getContent();
    test()->getJson('/api/v1/admin/audit-logs?per_page=200')->assertOk();

    expect($raw)->not->toContain('private/payment-proofs')
        ->and($raw)->not->toContain('file_path')
        ->and(AuditLog::where('action', DownloadLog::ACTION)->count())->toBe($downloads);
});

it('carries the real counter payment’s two receipts, and the owner’s on its submitted and rejected rows', function () {
    $filing = apBilledFiling();

    // The owner sends a photo; BPLO rejects it; the owner sends another.
    $first = apOwnerSendsPhoto($filing, 'first.png');
    authAs('bplo@biztrack.local');
    test()->postJson("/api/v1/payment-proofs/{$first->id}/reject", ['reason' => 'The photo is blurred.'])->assertOk();
    $second = apOwnerSendsPhoto($filing, 'second.png');

    // BPLO marks it paid, attaching the officer's own photo of the OR.
    authAs('bplo@biztrack.local');
    test()->post("/api/v1/applications/{$filing->id}/counter-payment", [
        'or_number' => '7100001',
        'or_date' => now()->toDateString(),
        'photo' => UploadedFile::fake()->createWithContent('or-7100001.pdf', "%PDF-1.4\n%%EOF\n"),
    ], ['Accept' => 'application/json'])->assertCreated();

    $payment = Payment::where('application_id', $filing->id)->where('method', 'counter')->sole();
    $officers = PaymentProof::where('payment_id', $payment->id)->where('id', '!=', $second->id)->sole();

    $body = apGet('group=payments&per_page=200');
    $row = fn (string $action, string $type, int $id) => apRowOf(
        $body,
        AuditLog::where('action', $action)->where('auditable_type', $type)->where('auditable_id', $id)->sole(),
    );

    expect($row('payment.completed', Payment::class, $payment->id)['receipts'])->toBe([
        ['id' => $second->id, 'mime_type' => 'image/png', 'file_name' => 'second.png'],
        ['id' => $officers->id, 'mime_type' => 'application/pdf', 'file_name' => 'or-7100001.pdf'],
    ])
        ->and($row('payment_proof.submitted', PaymentProof::class, $first->id)['receipts'])->toBe([
            ['id' => $first->id, 'mime_type' => 'image/png', 'file_name' => 'first.png'],
        ])
        ->and($row('payment_proof.rejected', PaymentProof::class, $first->id)['receipts'])->toBe([
            ['id' => $first->id, 'mime_type' => 'image/png', 'file_name' => 'first.png'],
        ])
        ->and($row('payment_proof.submitted', PaymentProof::class, $second->id)['receipts'])->toBe([
            ['id' => $second->id, 'mime_type' => 'image/png', 'file_name' => 'second.png'],
        ]);
});

it('refuses the Payments filter to everyone but the super admin, as the rest of the trail is', function (string $email) {
    authAs($email);
    test()->getJson('/api/v1/admin/audit-logs?group=payments')->assertForbidden();
})->with([
    'BPLO' => 'bplo@biztrack.local',
    'a clearance office' => 'sanitary@biztrack.local',
    'the owner' => 'owner@biztrack.local',
]);

it('refuses the Payments filter to a visitor who is not signed in', function () {
    test()->getJson('/api/v1/admin/audit-logs?group=payments')->assertUnauthorized();
});

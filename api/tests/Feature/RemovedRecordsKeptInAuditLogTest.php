<?php

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\DocumentType;
use App\Models\PermitType;
use App\Models\User;

/*
 * Audit Log 1 — keep removed records (Ken's checklist, 27 September 2026).
 *
 * Every delete or retire copies the record, as it stood, into the audit row's
 * `snapshot`, and the super admin's audit screen can ask for "Removed" only.
 * Before this a removal wrote an action name and at most a couple of fields —
 * `document.removed` carried nothing — so what was deleted had no answer once
 * the row was gone.
 */

function removalRow(string $action, string $type, int $id): AuditLog
{
    return AuditLog::where('action', $action)
        ->where('auditable_type', $type)
        ->where('auditable_id', $id)
        ->latest('id')
        ->firstOrFail();
}

function ownersDraft(): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);
    $app->permitTypes()->attach(PermitType::where('code', 'BUSINESS')->value('id'), ['status' => 'not_started']);

    return $app;
}

function draftDocument(Application $app): ApplicationDocument
{
    return ApplicationDocument::create([
        'application_id' => $app->id,
        'document_type_id' => DocumentType::where('code', 'DTI_SEC_CDA')->value('id'),
        'original_filename' => 'dti-certificate.pdf',
        'stored_path' => 'private/documents/none/dti.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
    ]);
}

it('keeps a deleted draft, with its documents and permit types, in the audit log', function () {
    $app = ownersDraft();
    draftDocument($app);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}")->assertOk();

    $snapshot = removalRow('application.draft_deleted', Application::class, $app->id)->snapshot;

    expect($snapshot['id'])->toBe($app->id)
        ->and($snapshot['status'])->toBe('draft')
        ->and($snapshot['business_id'])->toBe($app->business_id)
        ->and($snapshot['documents'])->toHaveCount(1)
        ->and($snapshot['documents'][0]['original_filename'])->toBe('dti-certificate.pdf')
        ->and(collect($snapshot['permit_types'] ?? $snapshot['permitTypes'])->pluck('code')->all())->toBe(['BUSINESS']);
});

it('keeps a removed document row in the audit log', function () {
    $app = ownersDraft();
    $doc = draftDocument($app);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}/documents/{$doc->id}")->assertOk();

    expect(ApplicationDocument::find($doc->id))->toBeNull();

    $snapshot = removalRow('document.removed', ApplicationDocument::class, $doc->id)->snapshot;
    expect($snapshot)->toMatchArray([
        'id' => $doc->id,
        'application_id' => $app->id,
        'original_filename' => 'dti-certificate.pdf',
        'size_bytes' => 2048,
    ]);
});

it('keeps a deactivated account as it stood, roles included, and never its password', function () {
    $officer = User::where('email', 'fire@biztrack.local')->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/users/{$officer->id}/toggle-active")->assertOk();

    $snapshot = removalRow('user.toggle_active', User::class, $officer->id)->snapshot;

    // The state BEFORE the retire — the copy is of the account that was taken
    // out of use, not of the switched-off one.
    expect($snapshot['is_active'])->toBeTrue()
        ->and($snapshot['email'])->toBe('fire@biztrack.local')
        ->and(collect($snapshot['roles'])->pluck('name')->all())->toContain('fire_inspector')
        ->and($snapshot)->not->toHaveKey('password')
        ->and($snapshot)->not->toHaveKey('remember_token');
});

it('writes no snapshot when an account is reactivated, because nothing was removed', function () {
    $inactive = User::where('email', 'inactive@biztrack.local')->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/users/{$inactive->id}/toggle-active")->assertOk();

    expect(removalRow('user.toggle_active', User::class, $inactive->id)->snapshot)->toBeNull();
});

it('keeps a blacklisted business, with its address, as it stood before the blacklisting', function () {
    $business = Business::where('status', 'active')->whereHas('address')->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'blacklisted',
            'reason' => 'Operating without a fire safety certificate.',
        ])->assertOk();

    $snapshot = removalRow('business.status_changed', Business::class, $business->id)->snapshot;

    expect($snapshot['status'])->toBe('active')
        ->and($snapshot['name'])->toBe($business->name)
        ->and($snapshot['address']['barangay_id'])->toBe($business->address->barangay_id);

    // The certificates the blacklisting suspended are each kept as they stood.
    $suspended = AuditLog::where('action', 'permit.suspended')->get()
        ->filter(fn (AuditLog $row) => ($row->changes['business_id'] ?? null) === $business->id);
    foreach ($suspended as $row) {
        expect($row->snapshot['status'])->toBe('active')
            ->and($row->snapshot['business_id'])->toBe($business->id);
    }
});

it('does not treat flagging a business as a removal', function () {
    $business = Business::where('status', 'active')->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'flagged',
            'reason' => 'Watch for renewal.',
        ])->assertOk();

    expect(removalRow('business.status_changed', Business::class, $business->id)->snapshot)->toBeNull();
});

it('lets the audit screen ask for removals only, and hands back the snapshot', function () {
    $app = ownersDraft();
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}")->assertOk();

    // And one ordinary change that removes nothing, which the filter must drop.
    $flagged = Business::where('status', 'active')->firstOrFail();
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$flagged->id}/status", ['status' => 'flagged', 'reason' => 'Watch.'])
        ->assertOk();

    $rows = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/audit-logs?removed=1&per_page=200')
        ->assertOk()
        ->json('data');

    expect($rows)->not->toBeEmpty()
        ->and(collect($rows)->every(fn ($r) => is_array($r['snapshot'])))->toBeTrue()
        ->and(collect($rows)->firstWhere('auditable_id', $app->id)['snapshot']['id'])->toBe($app->id);

    expect(collect($rows)->pluck('auditable_id')->all())->not->toContain($flagged->id);

    // The unfiltered trail still carries it.
    $all = test()->getJson('/api/v1/admin/audit-logs?per_page=200')->assertOk()->json('data');
    expect(collect($all)->contains(fn ($r) => $r['action'] === 'business.status_changed' && $r['snapshot'] === null))->toBeTrue();
});

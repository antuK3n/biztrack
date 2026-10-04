<?php

use App\Enums\PermitStatus;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * POST /permits/{permit}/revoke — taking a permit away (checklist item 23).
 *
 * The rules here are the ones question A26 said had to be settled before a
 * Revoke button could exist: who may do it, that a reason is recorded, that the
 * owner is told, and that the public verify page stops vouching for the paper.
 */

/**
 * A business owned by the seeded owner, holding one live certificate.
 *
 * Built through the API for the business and the filing, then the permit
 * minted directly — the same shape SuspensionReachTest uses, under its own
 * name because Pest helpers are global.
 */
function revocablePermit(string $code = PermitType::OUTCOME_CODE, PermitStatus $status = PermitStatus::Active): Permit
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Revocable Store '.random_int(10000, 99999),
        'trade_name' => 'Revocable Trade',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '7 Revocation Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', $code)->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    return Permit::create([
        'permit_number' => 'RVK-'.random_int(100000, 999999),
        'application_id' => $appId,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => $status,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);
}

it('lets BPLO revoke a permit, recording when and why', function () {
    $permit = revocablePermit();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Operating a business not covered by the permit.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('data.revoked_reason', 'Operating a business not covered by the permit.');

    $permit->refresh();
    expect($permit->status)->toBe(PermitStatus::Revoked)
        ->and($permit->revoked_at)->not->toBeNull()
        ->and($permit->revoked_reason)->toBe('Operating a business not covered by the permit.');
});

it('refuses the super admin, who oversees the register and does not act on it', function () {
    // Client, 4 October 2026: "paki tanggal ang revoke sa super admin".
    $permit = revocablePermit();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Closure order from the Mayor.'])
        ->assertForbidden();

    expect($permit->fresh()->status)->toBe(PermitStatus::Active);
});

it('refuses BPLO on another office’s permit — it revokes the Mayor’s Permit only', function () {
    // "dapat sa bplo ayon lang kaya nyang i revoke".
    foreach (['SANITARY', 'FSIC'] as $code) {
        $permit = revocablePermit($code);

        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Not BPLO’s to take back.'])
            ->assertForbidden();

        expect($permit->fresh()->status)->toBe(PermitStatus::Active);
    }
});

it('lets an office revoke the certificate it issued', function () {
    // Client, 4 October 2026: "yung mga kanya kanya nilang permit pwede nilang irevoke".
    $permit = revocablePermit('SANITARY');

    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Failed the sanitary re-inspection.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');
});

it('refuses every office but the issuer, and the owner', function () {
    /*
     * "yung cert na nirerelease ng office na yon sya lang pwede mag revoke".
     * The other offices can SEE this permit's row where they read it, so the
     * refusal has to come from the server, not from the row being out of
     * reach. The owner is here because the one person who must never be able
     * to touch a revocation is its subject.
     */
    $permit = revocablePermit('SANITARY');

    foreach ([
        'fire@biztrack.local',
        'zoning@biztrack.local',
        'obo@biztrack.local',
        'cenro@biztrack.local',
        'owner@biztrack.local',
    ] as $email) {
        test()->withHeaders(authAs($email))
            ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Trying it on.'])
            ->assertForbidden();
    }

    expect($permit->fresh()->status)->toBe(PermitStatus::Active);
});

it('refuses a revocation without a reason', function () {
    $permit = revocablePermit();

    foreach (['', '   '] as $blank) {
        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => $blank])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    expect($permit->fresh()->status)->toBe(PermitStatus::Active);
});

it('revokes a suspended permit, and nothing that has already stopped being valid', function () {
    $suspended = revocablePermit(status: PermitStatus::Suspended);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$suspended->id}/revoke", ['reason' => 'Refused clearance never settled.'])
        ->assertOk();
    expect($suspended->fresh()->status)->toBe(PermitStatus::Revoked);

    foreach ([PermitStatus::Expired, PermitStatus::Superseded] as $status) {
        $permit = revocablePermit(status: $status);

        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Too late.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permit');

        expect($permit->fresh()->status)->toBe($status);
    }
});

it('keeps the first revocation when it is submitted twice', function () {
    /*
     * A double click must not overwrite the reason and the date the owner
     * was first told.
     */
    $permit = revocablePermit();
    $bplo = authAs('bplo@biztrack.local');

    test()->withHeaders($bplo)
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'First reason.'])
        ->assertOk();

    test()->withHeaders($bplo)
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Second reason.'])
        ->assertStatus(422);

    expect($permit->fresh()->revoked_reason)->toBe('First reason.');
});

it('writes an audit entry naming the officer, the permit and the reason', function () {
    $permit = revocablePermit();
    $bplo = User::where('email', 'bplo@biztrack.local')->firstOrFail();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Fraudulent documents.'])
        ->assertOk();

    $entry = AuditLog::where('action', 'permit.revoked')->latest('id')->firstOrFail();

    expect($entry->user_id)->toBe($bplo->id)
        ->and((int) $entry->auditable_id)->toBe($permit->id)
        ->and($entry->changes['reason'])->toBe('Fraudulent documents.')
        ->and($entry->changes['permit_number'])->toBe($permit->permit_number)
        ->and($entry->changes['from'])->toBe('active');
});

/*
 * Audit Log 1 keeps a copy of every record taken out of use. Revoking arrived on
 * a separate branch from that rule, and suspension (which can be undone) already
 * kept its copy, so the one retirement that cannot be undone was the one that
 * did not.
 */
it('keeps the permit as it stood before the revocation in the audit log', function () {
    $permit = revocablePermit();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Fraudulent documents.'])
        ->assertOk();

    $entry = AuditLog::where('action', 'permit.revoked')->latest('id')->firstOrFail();

    expect($entry->snapshot)->not->toBeNull()
        ->and($entry->snapshot['status'])->toBe('active')
        ->and($entry->snapshot['permit_number'])->toBe($permit->permit_number)
        ->and($entry->snapshot['revoked_at'])->toBeNull();
});

it('tells the owner in-app, with the permit number and the reason', function () {
    $permit = revocablePermit();
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Fraudulent documents.'])
        ->assertOk();

    $notice = AppNotification::where('user_id', $owner->id)->latest('id')->firstOrFail();

    expect($notice->title)->toContain('revoked')
        // Its own type, so the owner's screen can raise the modal for it.
        ->and($notice->type)->toBe('permit_revoked')
        ->and($notice->body)->toContain($permit->permit_number)
        // Names the office that revoked it — BPLO, for the Mayor's Permit.
        ->and($notice->body)->toContain($permit->permitType->department->name)
        ->and($notice->body)->toContain('Fraudulent documents.')
        ->and($notice->link)->toBe('/permits');
});

it('makes the public verify page say Revoked, with the date and without the reason', function () {
    $permit = revocablePermit();

    test()->getJson("/api/v1/verify/{$permit->permit_number}")
        ->assertOk()
        ->assertJsonPath('data.is_valid', true);

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Private reason for the owner.'])
        ->assertOk();

    $body = test()->getJson("/api/v1/verify/{$permit->permit_number}")
        ->assertOk()
        ->assertJsonPath('data.is_valid', false)
        ->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('data.status_label', 'Revoked')
        ->assertJsonPath('data.revoked_at', now()->toDateString())
        ->getContent();

    // The reason is between the City and the owner (A26, point 4).
    expect($body)->not->toContain('Private reason for the owner.');
});

it('shows a revoked permit on the register with both revocation columns filled', function () {
    $permit = revocablePermit();

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/revoke", ['reason' => 'Closure order.'])
        ->assertOk();

    $row = collect(
        test()->withHeaders(authAs('bplo@biztrack.local'))
            ->getJson('/api/v1/permits?detail=1&status=revoked&per_page=50')
            ->assertOk()
            ->json('data')
    )->firstWhere('id', $permit->id);

    expect($row)->not->toBeNull()
        ->and($row['status_label'])->toBe('Revoked')
        ->and($row['revoked_at'])->not->toBeNull()
        ->and($row['revoked_reason'])->toBe('Closure order.');
});

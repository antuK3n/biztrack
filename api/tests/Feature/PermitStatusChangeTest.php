<?php

use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\PermitStatus;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationDocument;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\DocumentType;
use App\Models\Inspection;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * Change status and View status history [client, 5 October 2026].
 *
 *   BPLO, on a Mayor's Permit   Active · Suspended · Retired · Revoked
 *   A clearance office          Active · Rejected
 *
 * A clearance set to Rejected suspends the Mayor's Permit at once, and while
 * it stands BPLO cannot change that permit at all; setting it back to Active
 * restores the Mayor's Permit. Only the issuing office changes a permit, and
 * the super admin, who may also revoke any (Ken, 5 October 2026). Every change
 * tells the owner.
 */

/**
 * A filing holding an active Mayor's Permit and an active Sanitary Permit.
 *
 * @return array{business: Permit, sanitary: Permit}
 */
function statusPair(): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Status Store '.random_int(10000, 99999),
        'trade_name' => 'Status Trade',
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Status Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::whereIn('code', ['BUSINESS', 'SANITARY'])->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    // Routed to the health office, as a filing carrying its permit is.
    ApplicationAssignment::firstOrCreate([
        'application_id' => $appId,
        'department_id' => PermitType::where('code', 'SANITARY')->value('issuing_department_id'),
    ]);

    $make = fn (string $code, string $prefix) => Permit::create([
        'permit_number' => $prefix.'-'.random_int(100000, 999999),
        'application_id' => $appId,
        'business_id' => $businessId,
        'permit_type_id' => PermitType::where('code', $code)->value('id'),
        'status' => PermitStatus::Active,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);

    return ['business' => $make('BUSINESS', 'MPS'), 'sanitary' => $make('SANITARY', 'HCS')];
}

function setStatus(string $as, Permit $permit, string $status, string $reason = 'For the record.')
{
    return test()->withHeaders(authAs($as))
        ->postJson("/api/v1/permits/{$permit->id}/status", ['status' => $status, 'reason' => $reason]);
}

it('offers BPLO the four Mayor’s Permit statuses, and an office only Active and Rejected', function () {
    ['business' => $mp, 'sanitary' => $hc] = statusPair();

    $bplo = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/permits/{$mp->id}/status-options")->assertOk()->json('data');
    expect($bplo['can_change'])->toBeTrue()
        ->and(array_column($bplo['options'], 'label'))->toBe(['Suspended', 'Retired', 'Revoked']);

    $cho = test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->getJson("/api/v1/permits/{$hc->id}/status-options")->assertOk()->json('data');
    expect($cho['can_change'])->toBeTrue()
        ->and(array_column($cho['options'], 'label'))->toBe(['Rejected']);
});

it('lets BPLO suspend the Mayor’s Permit, tells the owner, and records it in the history', function () {
    ['business' => $mp] = statusPair();

    setStatus('bplo@biztrack.local', $mp, 'suspended', 'Inspection found violations.')->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    expect($mp->fresh()->suspended_cause)->toBe('manual');

    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $notice = AppNotification::where('user_id', $owner->id)->latest('id')->firstOrFail();
    expect($notice->type)->toBe('permit_suspended')
        ->and($notice->body)->toContain('Inspection found violations.');

    $history = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/permits/{$mp->id}/history")->assertOk()->json('data');
    expect($history[0]['to'])->toBe('suspended')
        ->and($history[0]['reason'])->toBe('Inspection found violations.')
        ->and(end($history)['label'])->toBe('Issued');
});

it('makes Retired final', function () {
    ['business' => $mp] = statusPair();

    setStatus('bplo@biztrack.local', $mp, 'retired', 'Business closed.')->assertOk();
    expect($mp->fresh()->status)->toBe(PermitStatus::Retired);

    setStatus('bplo@biztrack.local', $mp, 'active', 'Reopened.')->assertUnprocessable();
    expect($mp->fresh()->status)->toBe(PermitStatus::Retired);
});

it('suspends the Mayor’s Permit when an office rejects its permit, and locks it for BPLO', function () {
    ['business' => $mp, 'sanitary' => $hc] = statusPair();

    setStatus('sanitary@biztrack.local', $hc, 'rejected', 'Failed re-inspection.')->assertOk();

    expect($hc->fresh()->status)->toBe(PermitStatus::Rejected)
        ->and($mp->fresh()->status)->toBe(PermitStatus::Suspended)
        ->and($mp->fresh()->suspended_cause)->toBe('refusal');

    // BPLO is told why, and cannot change it.
    $options = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/permits/{$mp->id}/status-options")->assertOk()->json('data');
    expect($options['locked'])->not->toBeEmpty()
        ->and($options['locked'][0])->toContain($hc->permit_number);

    setStatus('bplo@biztrack.local', $mp, 'active', 'Trying anyway.')->assertUnprocessable();
    setStatus('bplo@biztrack.local', $mp, 'revoked', 'Trying anyway.')->assertUnprocessable();
    expect($mp->fresh()->status)->toBe(PermitStatus::Suspended);

    // The owner hears about both.
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $types = AppNotification::where('user_id', $owner->id)->latest('id')->take(2)->pluck('type')->all();
    expect($types)->toContain('permit_rejected')->toContain('permit_suspended');
});

it('restores the Mayor’s Permit when the office sets its permit back to Active', function () {
    ['business' => $mp, 'sanitary' => $hc] = statusPair();

    setStatus('sanitary@biztrack.local', $hc, 'rejected', 'Failed re-inspection.')->assertOk();
    setStatus('sanitary@biztrack.local', $hc, 'active', 'Passed the follow-up inspection.')->assertOk();

    expect($hc->fresh()->status)->toBe(PermitStatus::Active)
        ->and($mp->fresh()->status)->toBe(PermitStatus::Active);
});

it('never lifts BPLO’s own suspension when an office re-approves', function () {
    ['business' => $mp, 'sanitary' => $hc] = statusPair();

    setStatus('bplo@biztrack.local', $mp, 'suspended', 'Closure order pending.')->assertOk();
    setStatus('sanitary@biztrack.local', $hc, 'rejected', 'Failed re-inspection.')->assertOk();
    setStatus('sanitary@biztrack.local', $hc, 'active', 'Passed.')->assertOk();

    expect($mp->fresh()->status)->toBe(PermitStatus::Suspended);
});

it('keeps each office to its own vocabulary and its own certificates', function () {
    ['business' => $mp, 'sanitary' => $hc] = statusPair();

    // An office cannot suspend or retire, even its own permit.
    setStatus('sanitary@biztrack.local', $hc, 'suspended')->assertUnprocessable();
    setStatus('sanitary@biztrack.local', $hc, 'retired')->assertUnprocessable();
    // BPLO cannot reject a Mayor's Permit.
    setStatus('bplo@biztrack.local', $mp, 'rejected')->assertUnprocessable();

    // Nobody changes another office's permit.
    setStatus('bplo@biztrack.local', $hc, 'rejected')->assertForbidden();
    setStatus('fire@biztrack.local', $hc, 'rejected')->assertForbidden();
    setStatus('owner@biztrack.local', $mp, 'suspended')->assertForbidden();

    expect($mp->fresh()->status)->toBe(PermitStatus::Active)
        ->and($hc->fresh()->status)->toBe(PermitStatus::Active);
});

it('lets the super admin change any office’s permit with that office’s choices, revoking only the Mayor’s Permit', function () {
    // Client, 5 October 2026: the super admin has BPLO's choices on a Mayor's
    // Permit, Revoked included; Ken, same day: only a Mayor's Permit is revoked.
    ['business' => $mp, 'sanitary' => $hc] = statusPair();

    $admin = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/permits/{$mp->id}/status-options")->assertOk()->json('data');
    expect($admin['can_change'])->toBeTrue()
        ->and(array_column($admin['options'], 'label'))->toBe(['Suspended', 'Retired', 'Revoked']);

    $clearance = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/permits/{$hc->id}/status-options")->assertOk()->json('data');
    expect(array_column($clearance['options'], 'label'))->toBe(['Rejected']);

    setStatus('admin@biztrack.local', $mp, 'suspended', 'Violations found.')->assertOk();
    expect($mp->fresh()->status)->toBe(PermitStatus::Suspended);

    setStatus('admin@biztrack.local', $mp, 'revoked', 'Closure order.')->assertOk();
    expect($mp->fresh()->status)->toBe(PermitStatus::Revoked)
        ->and($mp->fresh()->revoked_reason)->toBe('Closure order.');
});

it('asks for a reason', function () {
    ['business' => $mp] = statusPair();

    setStatus('bplo@biztrack.local', $mp, 'suspended', '')->assertUnprocessable()->assertJsonValidationErrors('reason');
});

it('tells the public verify page a Rejected or Retired permit is not valid', function () {
    ['business' => $mp, 'sanitary' => $hc] = statusPair();

    setStatus('sanitary@biztrack.local', $hc, 'rejected', 'Failed re-inspection.')->assertOk();

    test()->getJson("/api/v1/verify/{$hc->permit_number}")
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.is_valid', false);
});

it('gives the owner the requirements submitted for their permit, as the office sees them', function () {
    ['business' => $mp] = statusPair();
    $doc = ApplicationDocument::create([
        'application_id' => $mp->application_id,
        // A requirement the Mayor's Permit reads: one it lists, or one no permit type claims.
        'document_type_id' => DocumentType::whereHas('permitTypes', fn ($q) => $q->where('code', 'BUSINESS'))->value('id')
            ?? DocumentType::whereDoesntHave('permitTypes')->where('code', 'not like', '%_REQ_%')->value('id'),
        'original_filename' => 'id.png',
        'stored_path' => 'private/documents/x/id.png',
        'mime_type' => 'image/png',
        'size_bytes' => 10,
    ]);

    $owner = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/permits/{$mp->id}/requirements")->assertOk()->json('data');
    $office = collect(test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/permits?detail=1&per_page=100&q='.$mp->permit_number)->assertOk()->json('data'))
        ->firstWhere('id', $mp->id)['documents'];

    expect(collect($owner)->pluck('id')->all())->toBe(collect($office)->pluck('id')->all())
        ->and(collect($owner)->pluck('id'))->toContain($doc->id);

    // Nobody else's owner reads it.
    test()->withHeaders(authAs('juan@biztrack.local'))
        ->getJson("/api/v1/permits/{$mp->id}/requirements")->assertForbidden();
});

it('counts each permit’s requirements in the owner’s list, matching the list itself', function () {
    // Client, 5 October 2026: My Permits shows "Requirements submitted (N)" only where N > 0.
    ['business' => $mp] = statusPair();

    $rows = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/permits?with_requirements=1&per_page=200')->assertOk()->json('data'));
    $row = $rows->firstWhere('id', $mp->id);

    $list = test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson("/api/v1/permits/{$mp->id}/requirements")->assertOk()->json('data');

    expect($row)->toHaveKey('requirements_count')
        ->and($row['requirements_count'])->toBe(count($list));

    // Not asked for, not sent — a missing count must not read as zero.
    $plain = collect(test()->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/permits?per_page=200')->assertOk()->json('data'))->firstWhere('id', $mp->id);
    expect($plain)->not->toHaveKey('requirements_count');
});

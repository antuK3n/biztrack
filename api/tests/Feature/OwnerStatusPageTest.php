<?php

use App\Enums\PermitStatus;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * Business Owner Status, one row per owner [client, 5 October 2026].
 *
 * The owner's status is Active or Blacklisted. Blacklisting suspends every
 * business they hold (and the certificates with them), and while it stands no
 * business or permit of theirs can be changed. Reinstating returns exactly the
 * businesses the blacklisting suspended. The owner is told each time.
 */

/** A business of the seeded owner, holding one active Mayor's Permit. */
function ownedBusinessWithPermit(string $name): array
{
    $owner = authAs('owner@biztrack.local');

    $id = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => $name.' '.random_int(10000, 99999),
        'registration_type' => 'DTI',
        'registration_number' => 'DTI-'.random_int(100000, 999999),
        'tin' => '123-456-789-000',
        'address' => ['line1' => '3 Owner Street', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 250000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $id,
        'data_privacy_consent' => true,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    $permit = Permit::create([
        'permit_number' => 'MPO-'.random_int(100000, 999999),
        'application_id' => $appId,
        'business_id' => $id,
        'permit_type_id' => PermitType::where('code', 'BUSINESS')->value('id'),
        'status' => PermitStatus::Active,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addYear()->toDateString(),
        'issued_at' => now(),
    ]);

    return [Business::findOrFail($id), $permit];
}

function ownerStatus(User $owner, string $status, string $reason = 'For the record.')
{
    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/owners/{$owner->id}/status", ['status' => $status, 'reason' => $reason]);
}

it('lists owners, each with their businesses and their status', function () {
    [$business] = ownedBusinessWithPermit('Roster Store');
    $owner = $business->owner;

    $rows = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/owners?per_page=100&q='.urlencode($owner->name))
        ->assertOk()
        ->json('data');

    $row = collect($rows)->firstWhere('id', $owner->id);
    expect($row['status'])->toBe('active')
        ->and(collect($row['businesses'])->pluck('id'))->toContain($business->id);
});

it('blacklisting an owner suspends every business and permit they hold, and tells them', function () {
    [$a, $permitA] = ownedBusinessWithPermit('Blacklist One');
    [$b, $permitB] = ownedBusinessWithPermit('Blacklist Two');
    $owner = $a->owner;

    ownerStatus($owner, 'blacklisted', 'Falsified documents.')->assertOk()
        ->assertJsonPath('data.status', 'blacklisted');

    expect($owner->fresh()->isBlacklisted())->toBeTrue()
        ->and($a->fresh()->status)->toBe('suspended')
        ->and($b->fresh()->status)->toBe('suspended')
        ->and($permitA->fresh()->status)->toBe(PermitStatus::Suspended)
        ->and($permitB->fresh()->status)->toBe(PermitStatus::Suspended);

    $notice = AppNotification::where('user_id', $owner->id)->latest('id')->firstOrFail();
    expect($notice->title)->toBe('Your account has been blacklisted')
        ->and($notice->body)->toContain('Falsified documents.');

    // The owner's sign-in carries the blacklisting, which raises their modal.
    $me = test()->withHeaders(authAs('owner@biztrack.local'))->getJson('/api/v1/auth/me')->json();
    expect(data_get($me, 'data.restriction.kind') ?? data_get($me, 'restriction.kind'))->toBe('blacklisted');
});

it('locks the businesses and their permits while the owner is blacklisted', function () {
    [$business, $permit] = ownedBusinessWithPermit('Locked Store');
    ownerStatus($business->owner, 'blacklisted', 'Falsified documents.')->assertOk();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", ['status' => 'active', 'reason' => 'Trying.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $options = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson("/api/v1/permits/{$permit->id}/status-options")->assertOk()->json('data');
    expect($options['locked'][0])->toContain('blacklisted');

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/permits/{$permit->id}/status", ['status' => 'active', 'reason' => 'Trying.'])
        ->assertUnprocessable();

    expect($business->fresh()->status)->toBe('suspended');
});

it('reinstating the owner returns the businesses the blacklisting suspended, and no others', function () {
    [$a, $permitA] = ownedBusinessWithPermit('Reinstate One');
    [$b] = ownedBusinessWithPermit('Reinstate Two');
    $owner = $a->owner;

    // Suspended for its own reasons BEFORE the blacklisting.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$b->id}/status", ['status' => 'suspended', 'reason' => 'Failed inspection.'])
        ->assertOk();

    ownerStatus($owner, 'blacklisted', 'Falsified documents.')->assertOk();
    ownerStatus($owner, 'active', 'Documents verified as genuine.')->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($owner->fresh()->isBlacklisted())->toBeFalse()
        ->and($a->fresh()->status)->toBe('active')
        ->and($permitA->fresh()->status)->toBe(PermitStatus::Active)
        // Its own suspension stands.
        ->and($b->fresh()->status)->toBe('suspended');

    $notice = AppNotification::where('user_id', $owner->id)->latest('id')->firstOrFail();
    expect($notice->title)->toBe('Your account has been restored');

    // Reinstating twice is refused: there is nothing left to lift.
    ownerStatus($owner, 'active', 'Again.')->assertUnprocessable();
});

it('keeps the owner’s status history, newest first', function () {
    [$business] = ownedBusinessWithPermit('History Store');
    $owner = $business->owner;

    ownerStatus($owner, 'blacklisted', 'Falsified documents.')->assertOk();
    ownerStatus($owner, 'active', 'Documents verified as genuine.')->assertOk();

    $history = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/admin/owners/{$owner->id}/history")->assertOk()->json('data');

    expect($history[0]['to'])->toBe('active')
        ->and($history[0]['reason'])->toBe('Documents verified as genuine.')
        ->and($history[1]['to'])->toBe('blacklisted')
        ->and(end($history)['label'])->toBe('Account created');
});

it('takes only Active or Blacklisted, with a reason, from the super admin', function () {
    [$business] = ownedBusinessWithPermit('Choice Store');
    $owner = $business->owner;

    ownerStatus($owner, 'suspended')->assertUnprocessable();
    ownerStatus($owner, 'blacklisted', '')->assertUnprocessable()->assertJsonValidationErrors('reason');

    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/admin/owners/{$owner->id}/status", ['status' => 'blacklisted', 'reason' => 'Not BPLO’s call.'])
        ->assertForbidden();

    expect($owner->fresh()->isBlacklisted())->toBeFalse();
});

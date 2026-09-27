<?php

use App\Models\Business;
use App\Models\User;

/*
 * ── A blacklisting is of the owner ─────────────────────────────────────────
 *
 * It used to be a status on ONE business. An owner barred for falsified
 * documents could file for their other two the same afternoon, and register a
 * fourth that evening, because `isBlockedFromApplying` read one business's own
 * column and nothing else. The sanction was a door locked on a building with
 * three other doors [client, 27 September 2026: *"once na naka blacklist,
 * mismong owner na tlga yan, bale lahat lahat ng business nya ay blacklisted
 * na at kung blacklist O SUSPENDED di na sya makkapag apply o renew"*].
 *
 * Suspended and Flagged stay where they were, deliberately: a suspension is
 * about a premises that failed an inspection and a flag is a note to watch
 * one. Those are facts about a shopfront, and the tests below hold them to
 * that — a cascade that swept them up too would be a bug with a bigger blast
 * radius than the one it replaced.
 */

/** An owner with three businesses, which is the shape the bug needed. */
function ownerWithThree(): User
{
    $owner = User::create([
        'name' => 'Three Shops Reyes',
        'first_name' => 'Three',
        'last_name' => 'Reyes',
        'gender' => 'F',
        'email' => 'three.shops@example.com',
        'mobile_number' => '09171230000',
        'password' => 'Biztrack-Test1!',
        'is_active' => true,
        'data_privacy_consent_at' => now(),
        'email_verified_at' => now(),
    ]);
    $owner->roles()->sync(\App\Models\Role::where('name', 'business_owner')->pluck('id'));

    foreach (['Reyes Sari-Sari', 'Reyes Hardware', 'Reyes Canteen'] as $name) {
        Business::create([
            'owner_user_id' => $owner->id,
            'name' => $name,
            'registration_type' => 'sole',
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    return $owner->refresh();
}

function blacklist(Business $business, string $reason = 'Falsified sanitary clearance.'): \Illuminate\Testing\TestResponse
{
    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'blacklisted',
            'reason' => $reason,
        ]);
}

it('bars the owner and every business they hold, not just the one clicked', function () {
    $owner = ownerWithThree();
    $clicked = $owner->businesses()->first();

    $data = blacklist($clicked)->assertOk()->json('data');

    // The person.
    expect($owner->refresh()->isBlacklisted())->toBeTrue()
        ->and($owner->blacklist_reason)->toBe('Falsified sanitary clearance.');

    // And all three shopfronts, so every screen that reads a business's own
    // status — the roster, the certificate, the QR check — keeps working.
    expect($owner->businesses()->pluck('status')->unique()->all())->toBe(['blacklisted']);

    // The reply says what else moved, so the screen need not reload and count.
    expect($data['owner_blacklisted'])->toBeTrue()
        ->and($data['others_blacklisted'])->toBe(2);
});

it('refuses a filing for any of them, naming the owner as the cause', function () {
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    foreach ($owner->businesses()->get() as $business) {
        expect($business->isBlockedFromApplying())->toBeTrue("{$business->name} could still file");
        // The message has to say which finding it is. "This business can't
        // file" sends somebody to enquire about one shop when the finding is
        // against them.
        expect($business->filingBlockReason())->toContain('This account is blacklisted')
            ->and($business->filingBlockReason())->toContain('City BPLO');
    }
});

it('bars a business registered AFTER the blacklisting, which no cascade could reach', function () {
    /*
     * The hole that moving the sanction onto the person exists to close. A new
     * business starts life `active` like every other, so its own column says
     * nothing; the bar is read off the owner.
     */
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    $fresh = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Reyes Water Refilling',
        'registration_type' => 'sole',
        'status' => 'active',
        'is_active' => true,
    ]);

    expect($fresh->status)->toBe('active')
        ->and($fresh->isBlockedFromApplying())->toBeTrue();
});

it('leaves a suspension where it is — on the one premises that earned it', function () {
    /*
     * The mirror case, and the one a careless cascade would break. Suspending
     * the canteen for a failed inspection must not touch the hardware store.
     */
    $owner = ownerWithThree();
    [$first, $second] = $owner->businesses()->get()->all();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$first->id}/status", [
            'status' => 'suspended',
            'reason' => 'Failed sanitary inspection.',
        ])->assertOk();

    expect($owner->refresh()->isBlacklisted())->toBeFalse()
        ->and($first->refresh()->status)->toBe('suspended')
        ->and($second->refresh()->status)->toBe('active')
        ->and($second->isBlockedFromApplying())->toBeFalse();
});

it('lifts the bar from the person and the businesses it swept up', function () {
    $owner = ownerWithThree();
    $clicked = $owner->businesses()->first();
    blacklist($clicked)->assertOk();

    $data = test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$clicked->id}/status", [
            'status' => 'active',
            'reason' => 'Documents verified on appeal.',
        ])->assertOk()->json('data');

    expect($owner->refresh()->isBlacklisted())->toBeFalse()
        ->and($owner->blacklist_reason)->toBeNull()
        ->and($owner->businesses()->pluck('status')->unique()->all())->toBe(['active'])
        ->and($data['others_restored'])->toBe(2);

    foreach ($owner->businesses()->get() as $business) {
        expect($business->isBlockedFromApplying())->toBeFalse();
    }
});

it('does not lift the bar by moving one business to Flagged', function () {
    /*
     * Half-measures. Setting a blacklisted business to Flagged is not an admin
     * saying the finding no longer stands, and leaving the owner barred while
     * one row reads Flagged would be a roster disagreeing with the endpoint.
     * Only Active lifts it.
     */
    $owner = ownerWithThree();
    $clicked = $owner->businesses()->first();
    blacklist($clicked)->assertOk();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$clicked->id}/status", [
            'status' => 'flagged',
            'reason' => 'Under review.',
        ])->assertOk();

    expect($owner->refresh()->isBlacklisted())->toBeTrue()
        ->and($clicked->refresh()->isBlockedFromApplying())->toBeTrue();
});

it('does not re-date a blacklisting that is already in force', function () {
    // The roster lets an admin re-save the status a business already has, and
    // the QA sweeps in the audit log did exactly that. A second save is not a
    // second sanction.
    $owner = ownerWithThree();
    $clicked = $owner->businesses()->first();
    blacklist($clicked)->assertOk();

    $when = $owner->refresh()->blacklisted_at;

    $again = blacklist($clicked, 'Same finding, saved twice.')->assertOk()->json('data');

    expect($owner->refresh()->blacklisted_at->equalTo($when))->toBeTrue()
        ->and($owner->blacklist_reason)->toBe('Falsified sanitary clearance.')
        ->and($again['others_blacklisted'])->toBe(0);
});

it('tells the owner it is their account, and how many businesses it covers', function () {
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    $notice = $owner->notifications()->latest('id')->first();

    expect($notice)->not->toBeNull()
        ->and($notice->title)->toBe('Your account has been blacklisted')
        // The count, because "this business is blacklisted" would leave
        // somebody with three shops to discover the other two by being refused.
        ->and($notice->body)->toContain('all 3 of its businesses')
        ->and($notice->body)->toContain('message the City BPLO')
        ->and($notice->body)->toContain('Falsified sanitary clearance.');
});

it('lists the barred people, with everything they own', function () {
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    /*
     * Registered after the bar: still barred, but its own column reads Active,
     * and the register says which so nobody has to wonder.
     *
     * Moved forward in time on purpose. Both timestamps are `now()` and the
     * comparison is a strict one, so a business created in the same SECOND as
     * the blacklisting would read as not-after — true of a test that runs in
     * three milliseconds and of nothing in the register.
     */
    $this->travel(1)->days();

    Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Reyes Water Refilling',
        'registration_type' => 'sole',
        'status' => 'active',
        'is_active' => true,
    ]);

    $row = collect(
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson('/api/v1/admin/blacklisted-owners')
            ->assertOk()
            ->json('data')
    )->firstWhere('id', $owner->id);

    expect($row)->not->toBeNull()
        ->and($row['reason'])->toBe('Falsified sanitary clearance.')
        ->and($row['blacklisted_by'])->not->toBeNull()
        ->and($row['businesses'])->toHaveCount(4);

    $late = collect($row['businesses'])->firstWhere('name', 'Reyes Water Refilling');
    expect($late['registered_after'])->toBeTrue()
        ->and($late['status'])->toBe('active');

    $swept = collect($row['businesses'])->firstWhere('name', 'Reyes Hardware');
    expect($swept['registered_after'])->toBeFalse()
        ->and($swept['status'])->toBe('blacklisted');
});

it('drops the owner off the barred register once reinstated', function () {
    $owner = ownerWithThree();
    $clicked = $owner->businesses()->first();
    blacklist($clicked)->assertOk();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$clicked->id}/status", [
            'status' => 'active',
            'reason' => 'Documents verified on appeal.',
        ])->assertOk();

    $ids = collect(
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson('/api/v1/admin/blacklisted-owners')
            ->assertOk()
            ->json('data')
    )->pluck('id');

    expect($ids)->not->toContain($owner->id);
});

it('marks the roster row so three shops of one owner read as one sanction', function () {
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    $rows = collect(
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->getJson('/api/v1/admin/businesses?per_page=200')
            ->assertOk()
            ->json('data')
    )->where('owner.id', $owner->id);

    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row['owner']['blacklisted'])->toBeTrue()
            ->and($row['status'])->toBe('blacklisted');
    }
});

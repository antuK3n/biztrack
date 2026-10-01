<?php

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\TestResponse;

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
    $owner->roles()->sync(Role::where('name', 'business_owner')->pluck('id'));

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

function blacklist(Business $business, string $reason = 'Falsified sanitary clearance.'): TestResponse
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

/*
 * ── The way back is one act ────────────────────────────────────────────────
 *
 * The sanctions card used to offer Change Status per business, and that was
 * incoherent: a blacklisting falls on the PERSON and reaches everything they
 * own, so releasing one shopfront while the others stayed barred left a
 * register contradicting itself — the owner blacklisted, one of their
 * businesses reading Active [client, 28 September 2026: *"hindi pwedeng
 * isahang business lang ang mamomodify mo tas yung iba naka tag pa rin sa
 * blacklisted"*].
 *
 * It goes on as one act. It comes off as one.
 */

function releaseOwner(int $ownerId, string $status, string $reason = 'Compliance restored.'): TestResponse
{
    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/owners/{$ownerId}/lift-blacklist", [
            'status' => $status,
            'reason' => $reason,
        ]);
}

it('lifts the bar from the person and moves every business together', function () {
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    expect($owner->refresh()->isBlacklisted())->toBeTrue();

    $data = releaseOwner($owner->id, 'active')->assertOk()->json('data');

    expect($owner->refresh()->isBlacklisted())->toBeFalse()
        ->and($owner->businesses()->pluck('status')->unique()->all())->toBe(['active'])
        ->and($data['businesses_moved'])->toBe(3);

    foreach ($owner->businesses()->get() as $business) {
        expect($business->isBlockedFromApplying())->toBeFalse();
    }
});

it('can release them to Flagged, which blocks nothing', function () {
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    releaseOwner($owner->id, 'flagged')->assertOk();

    expect($owner->refresh()->isBlacklisted())->toBeFalse()
        ->and($owner->businesses()->pluck('status')->unique()->all())->toBe(['flagged']);

    // Flagged is a watch marker, never a sanction — `isBlockedFromApplying`
    // ignores it, and the permits come back with the release.
    foreach ($owner->businesses()->get() as $business) {
        expect($business->isBlockedFromApplying())->toBeFalse();
    }
});

it('can release them to Suspended, which keeps them barred but not blacklisted', function () {
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    releaseOwner($owner->id, 'suspended')->assertOk();

    expect($owner->refresh()->isBlacklisted())->toBeFalse()
        ->and($owner->businesses()->pluck('status')->unique()->all())->toBe(['suspended']);

    // Still barred, for the business's own reason rather than the person's.
    foreach ($owner->businesses()->get() as $business) {
        expect($business->isBlockedFromApplying())->toBeTrue();
    }
});

it('takes a business registered after the bar with it', function () {
    /*
     * The cascade never touched it — it started life Active — so releasing
     * only what the cascade barred would leave exactly the contradiction this
     * endpoint exists to end.
     */
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    $this->travel(1)->days();
    Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Reyes Water Refilling',
        'registration_type' => 'sole',
        'status' => 'active',
        'is_active' => true,
    ]);

    $data = releaseOwner($owner->id, 'flagged')->assertOk()->json('data');

    expect($data['businesses_moved'])->toBe(4)
        ->and($owner->businesses()->pluck('status')->unique()->all())->toBe(['flagged']);
});

it('will not take Blacklisted as a destination, because they already are', function () {
    // A control whose only effect is to re-date a sanction already in force.
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    releaseOwner($owner->id, 'blacklisted')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect($owner->refresh()->isBlacklisted())->toBeTrue();
});

it('refuses to release somebody who is not blacklisted', function () {
    $owner = ownerWithThree();

    releaseOwner($owner->id, 'active')->assertStatus(422);
});

it('tells the owner once, not once per business', function () {
    /*
     * The bar was on them, so its lifting is one piece of news. Four notices
     * saying the same thing about four businesses is how somebody learns to
     * swipe this app's messages away.
     */
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    $before = $owner->notifications()->count();
    releaseOwner($owner->id, 'active')->assertOk();

    expect($owner->notifications()->count())->toBe($before + 1);

    $newest = $owner->notifications()->latest('id')->first();
    expect($newest->body)->toContain('All 3 of your businesses');
});

it('records the release against every business it moved', function () {
    // An audit trail that recorded the decision and not its reach is one
    // nobody could reconstruct the register from.
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    releaseOwner($owner->id, 'active', 'Documents verified on appeal.')->assertOk();

    foreach ($owner->businesses()->get() as $business) {
        $entry = AuditLog::where('action', 'business.status_changed')
            ->where('auditable_id', $business->id)
            ->latest('id')
            ->first();

        expect($entry->changes['to'])->toBe('active')
            ->and($entry->changes['reason'])->toContain('Owner released from blacklist')
            ->and($entry->changes['released_with_owner'])->toBe($owner->id);
    }
});

it('keeps the release away from everyone but the super admin', function () {
    $owner = ownerWithThree();
    blacklist($owner->businesses()->first())->assertOk();

    foreach (['bplo@biztrack.local', 'sanitary@biztrack.local', 'owner@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))
            ->postJson("/api/v1/admin/owners/{$owner->id}/lift-blacklist", [
                'status' => 'active',
                'reason' => 'Trying it on.',
            ])
            ->assertForbidden();
    }

    expect($owner->refresh()->isBlacklisted())->toBeTrue();
});

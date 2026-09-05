<?php

use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;

/*
 * Manage Business Owner Status, on the super admin's Owner Status screen.
 *
 * The status itself already saved and was already audited. What it did not do
 * was REACH anyone:
 *
 *  - the owner was never told. Suspending or blacklisting a business is the
 *    heaviest thing this system does to a citizen, and it happened entirely
 *    behind their back: a column moved, an audit row was written, and the owner
 *    found out the next time they tried to file. The reason was already
 *    required at the point of the change and went nowhere near them.
 *
 *  - the block only covered CREATING a filing. Drafts autosave and sit for
 *    weeks, so every draft a business already had sailed past a suspension
 *    imposed after the draft was started — the filing landed in an office
 *    queue, was worked, and the suspension never came up.
 *
 *  - the audit trail could not be asked about one business, so "Status History"
 *    scanned the 200 newest rows of the whole trail and reported what it found
 *    there. The newest rows are overwhelmingly sign-ins, so a business
 *    blacklisted last month showed an empty history.
 */

/** A business owned by owner@biztrack.local, plus a draft filing on it. */
function ownedBusinessWithDraft(string $registrationNumber): array
{
    $owner = authAs('owner@biztrack.local');

    $businessId = test()->withHeaders($owner)->postJson('/api/v1/businesses', [
        'name' => 'Status Test Store',
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '4 Status Rd.', 'barangay_id' => Barangay::first()->id],
        'lines' => [['psic_code_id' => PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');

    $appId = test()->withHeaders($owner)->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'application_type' => 'new',
        'permit_type_ids' => PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    return [$businessId, $appId];
}

it('tells the owner when their business is suspended, and says why', function () {
    [$businessId] = ownedBusinessWithDraft('DTI-92001');
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $before = AppNotification::where('user_id', $owner->id)->count();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/status", [
            'status' => 'suspended',
            'reason' => 'Verified complaints from the public · Repeated sanitation findings',
        ])->assertOk();

    $notification = AppNotification::where('user_id', $owner->id)->latest('id')->first();

    expect(AppNotification::where('user_id', $owner->id)->count())->toBe($before + 1)
        ->and($notification->title)->toBe('Business account Suspended')
        // The admin's own words, verbatim — an appeal has to be against what
        // was actually recorded, not a paraphrase of it.
        ->and($notification->body)->toContain('Repeated sanitation findings')
        ->and($notification->body)->toContain('Status Test Store')
        // And what it means for them, which is the part they have to act on.
        ->and($notification->body)->toContain('cannot be filed');
});

it('says something different when a business is restored', function () {
    [$businessId] = ownedBusinessWithDraft('DTI-92002');
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $admin = authAs('admin@biztrack.local');

    test()->withHeaders($admin)->postJson("/api/v1/admin/businesses/{$businessId}/status", [
        'status' => 'suspended', 'reason' => 'Pending inspection.',
    ])->assertOk();

    test()->withHeaders($admin)->postJson("/api/v1/admin/businesses/{$businessId}/status", [
        'status' => 'active', 'reason' => 'Compliance restored',
    ])->assertOk();

    // Good news must not read like a second warning.
    $notification = AppNotification::where('user_id', $owner->id)->latest('id')->first();
    expect($notification->title)->toBe('Business account restored')
        ->and($notification->body)->toContain('can file applications');
});

it('does not re-alarm the owner when the status is re-saved unchanged', function () {
    [$businessId] = ownedBusinessWithDraft('DTI-92003');
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $admin = authAs('admin@biztrack.local');

    test()->withHeaders($admin)->postJson("/api/v1/admin/businesses/{$businessId}/status", [
        'status' => 'flagged', 'reason' => 'Under review.',
    ])->assertOk();

    $after = AppNotification::where('user_id', $owner->id)->count();

    // The roster lets an admin re-save the status a business already has, and
    // the QA sweeps in the audit log did exactly that. "Your business is now
    // Flagged", weeks later, is a false alarm to the person least able to check.
    test()->withHeaders($admin)->postJson("/api/v1/admin/businesses/{$businessId}/status", [
        'status' => 'flagged', 'reason' => 'Still under review.',
    ])->assertOk();

    expect(AppNotification::where('user_id', $owner->id)->count())->toBe($after);

    // The audit row is still written either way — "an admin looked at this and
    // left it alone, for this reason" is a fact worth keeping.
    $logs = test()->withHeaders($admin)
        ->getJson("/api/v1/admin/audit-logs?auditable_type=Business&auditable_id={$businessId}&action=status")
        ->assertOk()->json('data');
    expect(collect($logs)->pluck('changes.reason'))->toContain('Still under review.');
});

it('stops a draft started before the suspension from being submitted after it', function () {
    [$businessId, $appId] = ownedBusinessWithDraft('DTI-92004');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/status", [
            'status' => 'suspended', 'reason' => 'Non-payment of assessed fees',
        ])->assertOk();

    /*
     * The draft predates the suspension, so the create-time gate never saw it.
     * Without a gate at submit this filing reached an office queue and was
     * worked as though the business were in good standing.
     */
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors('business_id');

    expect(Application::find($appId)->status->value)->toBe('draft');
});

it('lets the filing through again once the business is restored', function () {
    [$businessId, $appId] = ownedBusinessWithDraft('DTI-92005');

    /*
     * authAs() is called inline at every switch rather than held in a variable.
     * It returns an empty header array and does its work as a side effect on the
     * guard, so a cached `$admin` reuses whoever authenticated LAST — here the
     * owner, two lines up, who has no `owner.manage_status` and gets a 403 that
     * looks like the endpoint refusing the restore.
     */
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/status", [
            'status' => 'blacklisted', 'reason' => 'Falsified / misrepresented documents',
        ])->assertOk();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertStatus(422);

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/status", [
            'status' => 'active', 'reason' => 'Documents verified on appeal.',
        ])->assertOk();

    // The block is a state, not a mark on the filing.
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
});

it('leaves a flagged business able to file', function () {
    [$businessId, $appId] = ownedBusinessWithDraft('DTI-92006');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/status", [
            'status' => 'flagged', 'reason' => 'Watchlisted for the next renewal.',
        ])->assertOk();

    // Flagged is a note to the LGU, not a penalty on the citizen — only
    // suspended and blacklisted bar a filing (Business::isBlockedFromApplying).
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$appId}/submit")->assertOk();
});

it('answers the whole history of one business, not the newest page of everything', function () {
    [$businessId] = ownedBusinessWithDraft('DTI-92007');
    [$otherId] = ownedBusinessWithDraft('DTI-92008');
    $admin = authAs('admin@biztrack.local');

    foreach ([['flagged', 'First look.'], ['suspended', 'Escalated.'], ['active', 'Resolved.']] as [$status, $reason]) {
        test()->withHeaders($admin)->postJson("/api/v1/admin/businesses/{$businessId}/status", [
            'status' => $status, 'reason' => $reason,
        ])->assertOk();
    }
    test()->withHeaders($admin)->postJson("/api/v1/admin/businesses/{$otherId}/status", [
        'status' => 'blacklisted', 'reason' => 'A different business entirely.',
    ])->assertOk();

    /*
     * Bury the changes under newer, unrelated audit rows. The screen used to
     * read the 200 newest entries of the whole trail and sift them in the
     * browser, so this is the shape that defeated it — and sign-ins, which
     * dominate the newest rows, are exactly what these stand in for.
     */
    for ($i = 0; $i < 30; $i++) {
        test()->withHeaders($admin)->getJson('/api/v1/admin/users')->assertOk();
    }

    $history = collect(test()->withHeaders($admin)
        ->getJson("/api/v1/admin/audit-logs?auditable_type=Business&auditable_id={$businessId}&action=status")
        ->assertOk()->json('data'));

    expect($history)->toHaveCount(3)
        ->and($history->pluck('changes.to')->sort()->values()->all())
        ->toBe(['active', 'flagged', 'suspended'])
        // Strictly this business. The other one's blacklisting is newer and
        // would have been the first thing an unfiltered scan returned.
        ->and($history->pluck('auditable_id')->unique()->all())->toBe([$businessId]);
});

it('does not let the type filter reach outside the model namespace', function () {
    $businessId = Business::value('id');

    // A caller sending separators gets them stripped rather than resolved —
    // the filter names a model, it does not compose a class path.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/audit-logs?auditable_type=..%2F..%2FUser')
        ->assertOk()
        ->assertJsonPath('data', []);

    expect($businessId)->not->toBeNull();
});

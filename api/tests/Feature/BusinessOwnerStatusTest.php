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
        'trade_name' => 'Test Trade Name',
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
        /*
         * RA 10173 consent, ticked while the draft is still fileable.
         *
         * Two gates run at submit and consent is the FIRST of them, so a draft
         * without it is refused for a reason that has nothing to do with the
         * owner's status. That matters more here than anywhere else in this
         * suite: the tests below prove that a SUSPENSION stops the filing, and
         * a fixture missing the tick would have them pass on
         * `data_privacy_consent` while the block itself went unexercised — a
         * green test asserting the wrong refusal.
         *
         * Ticked at create rather than at submit because the draft is meant to
         * be complete; the only thing wrong with it is the business's standing.
         */
        'data_privacy_consent' => true,
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
        ->and($notification->body)->toContain('cannot be filed')
        /*
         * A route that exists in web/src/App.tsx. This file's service opens
         * with the note that `/track/{id}` and `/review/{id}` never did, and
         * every notification carrying one bounced the reader to the sign-in
         * redirect. `/businesses` — the obvious guess, and the one this was
         * written with — is not a route either. `/dashboard` is, and it already
         * raises AccountRestrictedModal for suspended and blacklisted, so the
         * link lands on the explanation.
         */
        ->and($notification->link)->toBe('/dashboard');
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

/*
 * The Owner Status table has to tell two businesses apart.
 *
 * Six rows, two owners, and names that share a word: this is the screen where
 * an admin suspends somebody's livelihood, and "which of these is the one the
 * complaint is about" must not be answered by the name alone.
 *
 * ── The number is a FILING's, and it is minted at SUBMIT ────────────────────
 *
 * `BIZ-2026-…` comes from `Numbering::trackingId()` inside
 * `WorkflowService::submit`, once per application and never rewritten. So a
 * draft has no number at all, every renewal and amendment takes a new one, and
 * a business that has filed three times holds three.
 *
 * Both halves of that matter here. The row shows the LATEST filing, and it says
 * how many there are, because a bare number on a business with three filings
 * reads as the business's own — which is the one thing it is not.
 *
 * These tests SUBMIT. An earlier pair did not, and passed while asserting
 * `null === null`: a created application has no tracking id, so the assertion
 * was comparing two empty values and calling it agreement.
 */
function numberedBusiness(string $name, string $registrationNumber): int
{
    return test()->withHeaders(authAs('owner@biztrack.local'))->postJson('/api/v1/businesses', [
        'name' => $name,
        'trade_name' => 'Test Trade Name',
        'registration_type' => 'DTI',
        'registration_number' => $registrationNumber,
        'tin' => '123-456-789-000',
        'address' => ['line1' => '5 Number Street', 'barangay_id' => \App\Models\Barangay::first()->id],
        'lines' => [['psic_code_id' => \App\Models\PsicCode::first()->id, 'capitalization' => 100000]],
    ])->assertCreated()->json('data.id');
}

/** A submitted filing on that business, which is what earns a tracking id. */
function filedOn(int $businessId, string $type): array
{
    $id = test()->withHeaders(authAs('owner@biztrack.local'))->postJson('/api/v1/applications', [
        'business_id' => $businessId,
        'data_privacy_consent' => true,
        'application_type' => $type,
        'permit_type_ids' => \App\Models\PermitType::where('code', 'BUSINESS')->pluck('id')->all(),
    ])->assertCreated()->json('data.id');

    return test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$id}/submit")->assertOk()->json('data');
}

/** The Owner Status row for one business. */
function ownerStatusRow(int $businessId): ?array
{
    return collect(test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/admin/businesses?per_page=200')->assertOk()->json('data'))
        ->firstWhere('id', $businessId);
}

it('names each business by its latest filing, and says when there are more', function () {
    $businessId = numberedBusiness('Numbered Store', 'DTI-778899');

    // A business exists from the moment it is created; one that has never
    // filed genuinely has no number, and the key is present so the screen can
    // say so rather than printing `undefined`.
    expect(ownerStatusRow($businessId)['tracking_id'])->toBeNull()
        ->and(ownerStatusRow($businessId)['applications_count'])->toBe(0);

    $first = filedOn($businessId, 'new');
    expect($first['tracking_id'])->toStartWith('BIZ-');
    expect(ownerStatusRow($businessId)['tracking_id'])->toBe($first['tracking_id'])
        ->and(ownerStatusRow($businessId)['applications_count'])->toBe(1);

    // A second `new`, not an amendment: submitting an amendment demands the
    // detail of what is being amended, and this test is about the NUMBER a
    // second filing earns, not about amendment validation.
    $second = filedOn($businessId, 'new');
    expect($second['tracking_id'])->not->toBe($first['tracking_id']);

    // The NEWEST, and the count that stops it reading as the only one.
    expect(ownerStatusRow($businessId)['tracking_id'])->toBe($second['tracking_id'])
        ->and(ownerStatusRow($businessId)['applications_count'])->toBe(2);
});

it('reads "latest" from the calendar, not from the insertion order', function () {
    /*
     * The client caught this by asking how BIZ-2026-00001 "became"
     * BIZ-2026-00003.
     *
     * It had not: the business holds both. But the row picked the newest by ID,
     * and an id is the order rows went INTO the table, not the order the
     * filings happened. On the tester register Nena's Sari-Sari Store carries a
     * renewal submitted 2026-08-13 as id 1 and the original new filing
     * submitted 2025-10-02 as id 3 — so the row showed the 2025 one and called
     * it the latest.
     *
     * `submitted_at` is the date the register itself keeps, falling back to
     * `created_at` for a draft that has never been handed in.
     */
    $businessId = numberedBusiness('Out Of Order Store', 'DTI-990011');

    $older = filedOn($businessId, 'new');
    $newer = filedOn($businessId, 'new');

    // The one inserted FIRST is the one submitted LAST — the shape the seeded
    // register happens to have, and the shape that broke the column.
    \App\Models\Application::whereKey($older['id'])->update(['submitted_at' => now()]);
    \App\Models\Application::whereKey($newer['id'])->update(['submitted_at' => now()->subYear()]);

    expect(ownerStatusRow($businessId)['tracking_id'])->toBe($older['tracking_id']);
});

/*
 * ── Transfer of ownership (MCG-BPLO-FO-003 section II) ───────────────────
 *
 * The half of an approved CHANGE OF OWNERSHIP that a person has to do. The
 * applicant states a NAME and attaches the Deed of Transfer; the permit prints
 * the ACCOUNT holder's name, so BPLO names the account having read the deed.
 *
 * Before 21 September 2026 there was no way to: `owner_user_id` was written in
 * exactly one place, from the session, when a business was first registered.
 */

it('moves a business to another owner account', function () {
    [$businessId] = ownedBusinessWithDraft('DTI-TRANSFER-1');

    $from = Business::findOrFail($businessId)->owner_user_id;
    $to = User::where('email', 'juan@biztrack.local')->firstOrFail();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/owner", [
            'owner_email' => 'juan@biztrack.local',
            'reason' => 'Deed of Sale attached to the amendment.',
        ])
        ->assertOk()
        ->assertJsonPath('data.owner_user_id', $to->id);

    expect(Business::findOrFail($businessId)->owner_user_id)->toBe($to->id)
        ->and($to->id)->not->toBe($from);

    // Both sides are told: one has gained a business, the other has lost one.
    expect(AppNotification::where('user_id', $to->id)->where('type', 'business')->exists())
        ->toBeTrue()
        ->and(AppNotification::where('user_id', $from)->where('type', 'business')->exists())
        ->toBeTrue();
});

it('refuses a transfer to an address no account uses', function () {
    /*
     * The commonest dead end, and the reason the message carries the next
     * step: BPLO cannot create an account for the buyer, so "invalid email"
     * would leave the officer with nothing to tell them.
     */
    [$businessId] = ownedBusinessWithDraft('DTI-TRANSFER-2');
    $before = Business::findOrFail($businessId)->owner_user_id;

    $message = test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/owner", [
            'owner_email' => 'nobody@example.com',
            'reason' => 'Deed of Sale attached.',
        ])
        ->assertStatus(422)
        ->json('errors.owner_email.0');

    expect($message)->toContain('register')
        ->and(Business::findOrFail($businessId)->owner_user_id)->toBe($before);
});

it('refuses a transfer that moves nothing, and one to a deactivated account', function () {
    /*
     * "Transferred" printed over a no-op is the defect the Reassign dialog was
     * fixed for on 10 September 2026, one office over. And a deactivated
     * account cannot file, so transferring to one strands the business: nobody
     * could renew it and the next January would pass in silence.
     */
    [$businessId] = ownedBusinessWithDraft('DTI-TRANSFER-3');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/owner", [
            'owner_email' => 'owner@biztrack.local',
            'reason' => 'No change.',
        ])
        ->assertStatus(422);

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/owner", [
            'owner_email' => 'inactive@biztrack.local',
            'reason' => 'Deed of Sale attached.',
        ])
        ->assertStatus(422);

    expect(Business::findOrFail($businessId)->owner->email)->toBe('owner@biztrack.local');
});

it('refuses an owner transferring their own business away', function () {
    // The register's own fact about a business, set by an admin. An owner who
    // could do this could also hand a blacklisted business to a clean account.
    [$businessId] = ownedBusinessWithDraft('DTI-TRANSFER-4');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/admin/businesses/{$businessId}/owner", [
            'owner_email' => 'juan@biztrack.local',
            'reason' => 'Trying it on.',
        ])
        ->assertForbidden();
});

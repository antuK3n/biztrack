<?php

use App\Models\Business;
use App\Models\User;

/*
 * ── A barred account reaches its messages and its notices, and nothing else ──
 *
 * "Sa owner status naman, once business suspended or account blacklisted, pag
 * open na pag open pa lang ng account ng business owner na yon may bubungad na
 * agad na modal for warning, at magdidirect sa kanya sa specific na chat sa
 * BPLO pag business is suspended — sa business na acc nya, diba may kanya
 * kanyang convo kada business — tas pag account is blacklisted ma-direct naman
 * dapat sa general inquiry ng BPLO. Note na bawal nya na ma-access ang iba pa
 * sa system, kundi messages part na lang at pag view ng notif. The rest — ang
 * mga application, renew, amend at marami pang iba — ay di accessible, dapat
 * maayos muna yung pagka suspend o blacklisted nya" [client, 30 September 2026].
 *
 * The modal and the hidden navigation are the screen's half and are asserted in
 * e2e/account-restriction.spec.ts. This file holds the half that is actually a
 * lock: a browser is the reader's own, so a restriction only a screen enforces
 * is a suggestion.
 *
 * Only a blacklisting since 5 October 2026. Ken: *"business suspension should
 * never affect the entirety of the account."* A suspended business holds its
 * own filings and nothing else; the owner keeps the account.
 */

function restrictedOwner(): User
{
    return User::where('email', 'owner@biztrack.local')->firstOrFail();
}

/** Bar the account outright. */
function blacklistOwner(): User
{
    $owner = restrictedOwner();
    $owner->forceFill([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Expired lease contract',
    ])->save();

    return $owner->fresh();
}

/** Suspend one of the owner's businesses, and answer with it. */
function suspendOneBusiness(): Business
{
    $business = restrictedOwner()->businesses()->firstOrFail();
    $business->forceFill(['status' => 'suspended'])->save();

    return $business->fresh();
}

function meAsOwner(): array
{
    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->json('data');
}

// ─────────────────────────────────────────────────────────────────────────
// What the session says.
// ─────────────────────────────────────────────────────────────────────────

it('says nothing about an account that is not barred', function () {
    expect(meAsOwner()['restriction'])->toBeNull();
});

it('never bars an officer', function () {
    $token = loginToken('bplo@biztrack.local');
    app('auth')->forgetGuards();

    $me = test()->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->json('data');

    /*
     * A blacklisting is a finding against a business owner. An officer holds
     * no businesses and can hold no finding, and AccountRestriction::for()
     * answers without a query for them rather than asking six times a screen
     * to learn it.
     */
    expect($me['restriction'])->toBeNull();
});

it('names a blacklisting and sends it to the general enquiry', function () {
    blacklistOwner();

    $restriction = meAsOwner()['restriction'];

    expect($restriction['kind'])->toBe('blacklisted')
        // A blacklisting is against the PERSON, so it names no business and
        // quotes no BAN — that would invite a call about one shopfront and an
        // answer that the finding is not against it.
        ->and($restriction['business_name'])->toBeNull()
        ->and($restriction['reference_id'])->toBeNull()
        ->and($restriction['covers'])->toBeGreaterThan(0)
        // Null application: the conversation that needs no filing behind it.
        ->and($restriction['conversation']['application_id'])->toBeNull();
});

it('does not restrict the account while one of its businesses is suspended', function () {
    suspendOneBusiness();

    expect(meAsOwner()['restriction'])->toBeNull();
});

it('reports the blacklisting when both findings stand', function () {
    suspendOneBusiness();
    blacklistOwner();

    // The suspension adds nothing to it: the blacklisting is the finding.
    expect(meAsOwner()['restriction']['kind'])->toBe('blacklisted');
});

// ─────────────────────────────────────────────────────────────────────────
// What it refuses, and what it must not.
// ─────────────────────────────────────────────────────────────────────────

/** @return array{0: string, 1: callable} */
dataset('barred endpoints', [
    'the business register' => ['get', '/api/v1/businesses'],
    'filing something new' => ['post', '/api/v1/applications'],
    'the filings list' => ['get', '/api/v1/applications'],
    'the permits list' => ['get', '/api/v1/permits'],
    'changing their own details' => ['put', '/api/v1/auth/profile'],
]);

it('refuses a blacklisted account the rest of the system', function (string $verb, string $path) {
    blacklistOwner();

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->json(strtoupper($verb), $path)->assertForbidden();
})->with('barred endpoints');

it('leaves a suspended business’s owner the rest of the system', function (string $verb, string $path) {
    /*
     * The client asked on 30 September 2026 for a suspension to bar the whole
     * account; Ken took that back on 5 October: *"business suspension should
     * never affect the entirety of the account."* Each of these answers as it
     * would for any owner — a write sent empty is a 422, never the bar's 403.
     */
    suspendOneBusiness();

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    expect(test()->withToken($token)->json(strtoupper($verb), $path)->status())->not->toBe(403);
})->with('barred endpoints');

it('leaves the messages and the notices open', function () {
    blacklistOwner();

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    /*
     * The half that has to stay open. Every refusal above tells the reader to
     * message the City BPLO; refusing that too would leave them told an appeal
     * exists and given no way to make it, which is worse than no instruction.
     */
    test()->withToken($token)->getJson('/api/v1/message-threads')->assertOk();
    test()->withToken($token)->getJson('/api/v1/general-messages')->assertOk();
    test()->withToken($token)->getJson('/api/v1/notifications')->assertOk();
    test()->withToken($token)->getJson('/api/v1/unread-summary')->assertOk();

    // And they can actually write, not merely read.
    test()->withToken($token)
        ->postJson('/api/v1/general-messages', ['body' => 'What is needed to have this lifted?'])
        ->assertCreated();
});

it('leaves the session itself reachable', function () {
    blacklistOwner();

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    /*
     * `me` carries the restriction, so barring it would lock away the
     * explanation along with everything else — the screen would have nothing
     * to raise its warning from.
     */
    test()->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    /*
     * Not barred, rather than found: the seeded account carries no photograph,
     * so showPhoto answers 404 whatever its standing. What matters here is
     * that the shell's avatar is not answered with a 403 — the restriction
     * must not break the chrome around the two screens it leaves open.
     */
    test()->withToken($token)
        ->getJson('/api/v1/auth/profile/photo')
        ->assertStatus(404);
});

it('does not bar an account over a permit it can settle itself', function () {
    /*
     * A certificate suspended because another office refused a clearance is
     * NOT one of these findings. It has a fix the owner can make themselves,
     * two screens away, and `isBlockedFromApplying()` already stops the filing
     * it should stop. Locking the account for it would shut the door the owner
     * is meant to walk out through.
     */
    expect(meAsOwner()['restriction'])->toBeNull();

    $token = loginToken('owner@biztrack.local');
    app('auth')->forgetGuards();

    test()->withToken($token)->getJson('/api/v1/applications')->assertOk();
});

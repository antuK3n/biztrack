<?php

use App\Models\User;

/*
 * Business owners and LGU staff sign in through separate doors. The split is
 * enforced server-side, so a leaked staff credential is useless at the public
 * sign-in even though both pages post to the same endpoint.
 */

it('lets a business owner in through the public portal', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertOk()->assertJsonPath('data.user.email', 'owner@biztrack.local');
});

it('lets an officer in through the staff portal', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertOk()->assertJsonPath('data.user.email', 'bplo@biztrack.local');
});

/*
 * These three used to assert the opposite of what they assert now.
 *
 * The refusal named the other door and shipped a `portal` field so the sign-in
 * page could offer a "Go there now" link. The client asked for that to stop —
 * a refused sign-in should not invite anyone to the other site — so the tests
 * that pinned the hint now pin its absence, which is the part that can regress
 * silently: a helpful `portal` key could be added back tomorrow and nothing
 * else in the suite would notice.
 *
 * The identical wording in both directions is the second half of it. Two
 * different sentences told an unauthenticated visitor which KIND of account an
 * address belongs to, on the strength of a password that then went unused.
 */
it('turns staff away from the public sign-in without naming the other door', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])
        ->assertStatus(409)
        ->assertJsonMissingPath('portal')
        ->assertJsonPath('message', 'This account cannot sign in here.');
});

it('turns the super admin away from the public sign-in', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])
        ->assertStatus(409)
        ->assertJsonMissingPath('portal');
});

it('turns a business owner away from the staff sign-in', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])
        ->assertStatus(409)
        ->assertJsonMissingPath('portal');
});

it('answers both wrong doors with the same sentence', function () {
    // The disclosure this closes: a different message per direction is a
    // readable signal about an account the caller has not authenticated as.
    $staffOnPublic = $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertStatus(409)->json('message');

    $ownerOnStaff = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertStatus(409)->json('message');

    expect($ownerOnStaff)->toBe($staffOnPublic);
});

it('defaults to the public portal when none is given', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
    ])->assertStatus(409);
});

it('issues no token when the wrong door is used', function () {
    $before = User::where('email', 'bplo@biztrack.local')->firstOrFail()->tokens()->count();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertStatus(409);

    $after = User::where('email', 'bplo@biztrack.local')->firstOrFail()->tokens()->count();
    expect($after)->toBe($before);
});

it('checks the password before revealing which portal an account belongs to', function () {
    // A wrong password must look the same whichever door is tried, otherwise
    // the 409 becomes a staff-account oracle.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'not-the-password',
        'portal' => 'public',
    ])->assertStatus(422);
});

it('records the portal in the token name', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertOk();

    expect(User::where('email', 'bplo@biztrack.local')->firstOrFail()->tokens()->latest('id')->first()->name)
        ->toBe('web:staff');
});

it('rejects an unknown portal value', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'superuser',
    ])->assertStatus(422);
});

it('answers an unauthenticated API request with 401 JSON, not a 500', function () {
    // Laravel would otherwise try to redirect the guest to a named 'login'
    // route this API-only app does not have.
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->get('/api/v1/auth/me', ['Accept' => 'text/html'])->assertUnauthorized();
});

it('sends hardening headers on every API response', function () {
    $res = $this->getJson('/api/v1/auth/me');

    expect($res->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($res->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($res->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
});

it('does not pin HSTS over plain http', function () {
    // A dev request must never teach the browser that localhost is HTTPS-only.
    expect($this->getJson('/api/v1/auth/me')->headers->get('Strict-Transport-Security'))->toBeNull();
});

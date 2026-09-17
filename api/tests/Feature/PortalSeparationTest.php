<?php

use App\Models\User;

/*
 * Three doors — citizen, staff, super admin — and an account belongs to exactly
 * one. The split is enforced server-side, so a leaked credential is useless at
 * the other two sign-ins even though all three pages post to one endpoint.
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
 * ── What the citizen door may say, and what it may not [item #63] ───────────
 *
 * These assertions have been rewritten twice, and the history is the argument.
 *
 * First the refusal NAMED the other portal and shipped a `portal` field so the
 * page could offer a "Go there now" link. The client asked for that to stop.
 * Then both directions were given one shared sentence, so the wording no
 * longer said which kind of account had been typed.
 *
 * Neither went far enough. The STATUS still differed — 409 for a staff or admin
 * credential against 422 for a wrong password — so a stranger on the
 * business-owner page learned from the status code alone that an address
 * belongs to City Hall, and a script over a list of addresses reads 409 as
 * "keep this one". The side effects gave the same answer: the old wrong-door
 * branch cleared the rate limiter and left `failed_login_attempts` untouched,
 * while a bad password hit both.
 *
 * So the rule these now pin is stronger and much simpler to state: AT THE
 * CITIZEN DOOR, A WRONG-DOOR ATTEMPT IS INDISTINGUISHABLE FROM A WRONG
 * PASSWORD. Same status, same sentence, same limiter hit, same attempt count.
 *
 * The staff and admin doors keep the 409. Someone standing at /staff/login
 * already knows a staff portal exists, so there is no secret for the status to
 * leak, and an officer who tried the wrong one of the two LGU doors is owed a
 * refusal they can tell apart from a typo.
 */
it('answers a staff credential at the citizen door exactly as it answers a wrong password', function () {
    $wrongDoor = $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ]);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'not-the-password',
        'portal' => 'public',
    ]);

    $wrongDoor->assertStatus(422)->assertJsonMissingPath('portal');
    expect($wrongDoor->status())->toBe($wrongPassword->status())
        ->and($wrongDoor->json('message'))->toBe($wrongPassword->json('message'))
        ->and($wrongDoor->json('message'))->not->toMatch('/staff|portal|admin|officer/i');
});

it('answers the super admin at the citizen door exactly as it answers a wrong password', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])
        ->assertStatus(422)
        ->assertJsonMissingPath('portal')
        ->assertJsonPath('message', 'Invalid credentials.');
});

it('counts a wrong-door attempt at the citizen door against the account, as a wrong password would', function () {
    /*
     * The oracle this closes is not in the body. The old branch cleared the
     * limiter and skipped the counter, so a staff address was the one that
     * never accumulated a lockout — readable by a client that ignores the
     * response entirely.
     */
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertStatus(422);

    expect(User::where('email', 'bplo@biztrack.local')->firstOrFail()->failed_login_attempts)->toBe(1);
});

it('turns a business owner away from the staff sign-in', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])
        ->assertStatus(409)
        ->assertJsonMissingPath('portal')
        ->assertJsonPath('message', 'This account cannot sign in here.');
});

/*
 * ── The third door [item #107] ──────────────────────────────────────────────
 *
 * `admin` used to sit in AuthController::STAFF_ROLES, so the super admin signed
 * in at /staff/login alongside all six offices. It has its own door now, and
 * these pin all three directions of the wrong-door check rather than the pair
 * the old two-portal version could express.
 */
it('lets the super admin in through the admin portal', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'admin',
    ])->assertOk()->assertJsonPath('data.user.email', 'admin@biztrack.local');
});

it('turns the super admin away from the staff sign-in', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertStatus(409);
});

it('turns an officer away from the admin sign-in', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'admin',
    ])->assertStatus(409);
});

it('turns a business owner away from the admin sign-in', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'admin',
    ])->assertStatus(409);
});

it('records the admin portal in the token name', function () {
    // The web app keys its stored token by portal, so an admin session minted
    // under the staff name would be indistinguishable from an officer's when
    // the two are revoked separately.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'admin',
    ])->assertOk();

    expect(User::where('email', 'admin@biztrack.local')->firstOrFail()->tokens()->latest('id')->first()->name)
        ->toBe('web:admin');
});

it('answers both LGU doors with the same sentence', function () {
    // A different message per direction is a readable signal about an account
    // the caller has not authenticated as.
    $ownerOnStaff = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertStatus(409)->json('message');

    $ownerOnAdmin = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'admin',
    ])->assertStatus(409)->json('message');

    expect($ownerOnAdmin)->toBe($ownerOnStaff);
});

it('defaults to the public portal when none is given', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
    ])->assertOk();

    // Staff at the citizen door: refused as a wrong password would be.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
    ])->assertStatus(422);
});

it('issues no token when the wrong door is used', function () {
    $before = User::where('email', 'bplo@biztrack.local')->firstOrFail()->tokens()->count();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])->assertStatus(422);

    $after = User::where('email', 'bplo@biztrack.local')->firstOrFail()->tokens()->count();
    expect($after)->toBe($before);
});

it('checks the password before revealing which portal an account belongs to', function () {
    // The wrong-door check runs only once the password is right. Reaching it on
    // a bad password would answer 409 for a staff address and 422 for anyone
    // else, which is the staff-account oracle with no password needed at all.
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

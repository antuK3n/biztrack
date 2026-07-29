<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Feature tests hit the full app + a fresh, seeded database.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Log in a seeded demo account and return its bearer token.
 *
 * Staff and business owners sign in through separate portals, so the portal is
 * inferred from the account's roles unless a caller pins it deliberately (which
 * is how the wrong-door rejection gets tested).
 */
function loginToken(string $email, string $password = 'biztrack1', ?string $portal = null): string
{
    $res = test()->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => $password,
        'portal' => $portal ?? portalFor($email),
    ]);
    $res->assertOk();

    return $res->json('data.token');
}

/** Which sign-in door a seeded account belongs to. */
function portalFor(string $email): string
{
    $user = User::where('email', $email)->first();

    return $user && $user->roles->pluck('name')->contains(fn ($r) => $r !== 'business_owner')
        ? 'staff'
        : 'public';
}

/**
 * Authenticate the test client as a seeded demo account via Sanctum.
 * Returns an empty header array so existing `withHeaders(authAs(...))` calls
 * keep working; the guard is what actually carries the identity, and it is
 * reset on each call so switching accounts mid-test is reliable.
 */
function authAs(string $email, string $password = 'biztrack1'): array
{
    $user = User::where('email', $email)->firstOrFail();

    // Reset any previously-resolved guard user, then act as this one so that
    // switching accounts mid-test is reliable.
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);

    return [];
}

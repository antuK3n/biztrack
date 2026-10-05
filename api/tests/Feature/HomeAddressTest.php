<?php

use App\Models\Barangay;
use App\Models\Department;
use App\Models\User;

/*
 * The business owner's home address [checklist 2026-09-28, Register 2 — "Make
 * sure that profile details are complete (like home details)"].
 *
 * Registration requires it of owners; the super admin's staff form never asks
 * for it; the profile form lets an owner who registered before it existed add
 * it, and refuses to let one already given be emptied or left half-written.
 * Filing is NOT gated on it (docs/questions-for-malabon.md, A27) — the payload
 * says an owner owes one, and the web app prompts.
 *
 * Since Register 3 ("remove unnecessary registration details (city/
 * municipality, province, zip code)") the owner gives a street and one of
 * Malabon's barangays; the server writes Malabon and Metro Manila itself.
 * `homeAddress()` still carries a city and province, which makes every payload
 * built from it an older form's — and those must keep working.
 */

function ownerRegistration(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Rosa',
        'last_name' => 'Manalo',
        'gender' => 'F',
        'email' => 'rosa.manalo@example.test',
        'mobile_number' => '09171234567',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'data_privacy_consent' => true,
    ], homeAddress(), $overrides);
}

/** The seeded owner as someone who registered before the address was asked. */
function ownerWithoutHomeAddress(): User
{
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill([
        'home_street' => null, 'home_barangay' => null, 'home_city' => null,
        'home_province' => null, 'home_postal_code' => null,
    ])->save();

    return $user;
}

it('refuses an owner registration that leaves out the home address', function () {
    $payload = ownerRegistration();
    unset($payload['home_street'], $payload['home_barangay'], $payload['home_city'], $payload['home_province'], $payload['home_postal_code']);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['home_street', 'home_barangay'])
        // ZIP is optional, and city and province are not asked at all.
        ->assertJsonMissingValidationErrors(['home_postal_code', 'home_city', 'home_province'])
        ->assertJsonPath('errors.home_barangay.0', 'Enter your barangay.');

    expect(User::where('email', 'rosa.manalo@example.test')->exists())->toBeFalse();
});

it('refuses an owner registration with any one part of the address blank', function (string $part) {
    $this->postJson('/api/v1/auth/register', ownerRegistration([$part => '']))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$part]);
})->with(['home_street', 'home_barangay']);

it('registers an owner from a barangay of Malabon, and writes the city and province itself', function () {
    $payload = ownerRegistration(['home_barangay' => 'Tonsuya', 'home_postal_code' => null]);
    unset($payload['home_city'], $payload['home_province']);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertCreated()
        ->assertJsonPath('data.user.home_barangay', 'Tonsuya')
        ->assertJsonPath('data.user.home_city', 'Malabon')
        ->assertJsonPath('data.user.home_province', 'Metro Manila')
        ->assertJsonPath('data.user.home_postal_code', null)
        ->assertJsonPath('data.user.home_address_missing', false);

    $user = User::where('email', 'rosa.manalo@example.test')->firstOrFail();
    expect($user->home_city)->toBe('Malabon')
        ->and($user->home_province)->toBe('Metro Manila')
        ->and($user->hasHomeAddress())->toBeTrue();
});

it('refuses a home barangay that is not one of Malabon\'s', function () {
    $this->postJson('/api/v1/auth/register', ownerRegistration(['home_barangay' => 'San Roque']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['home_barangay'])
        ->assertJsonPath('errors.home_barangay.0', 'Enter your barangay.');

    expect(User::where('email', 'rosa.manalo@example.test')->exists())->toBeFalse();
});

it('ignores a city and province an older sign-up form still sends', function () {
    $this->postJson('/api/v1/auth/register', ownerRegistration([
        'home_city' => 'Navotas',
        'home_province' => 'Bulacan',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.user.home_city', 'Malabon')
        ->assertJsonPath('data.user.home_province', 'Metro Manila');
});

it('lists Malabon\'s barangays to a visitor with no account, for the sign-up form', function () {
    $list = $this->getJson('/api/v1/barangays')->assertOk()->json('data');

    expect($list)->toHaveCount(Barangay::count())
        ->and(collect($list)->pluck('name'))->toContain('Longos', 'Tonsuya')
        // Names for a dropdown, not the wizard's zoning payload.
        ->and(array_keys($list[0]))->toBe(['id', 'name']);
});

it('refuses a ZIP code that is not four digits', function (string $zip) {
    $this->postJson('/api/v1/auth/register', ownerRegistration(homeAddress(['home_postal_code' => $zip])))
        ->assertStatus(422)
        ->assertJsonPath('errors.home_postal_code.0', 'A ZIP code is 4 digits.');
})->with(['147', '14720', 'ABCD']);

it('lets the super admin create a staff account without a home address', function () {
    $created = $this->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', [
            'first_name' => 'Teodora',
            'last_name' => 'Sison',
            'gender' => 'F',
            'email' => 'new.bplo@biztrack.local',
            'mobile_number' => '09171234567',
            // Staff passwords need mixed case, a number and a symbol (StaffCredentials).
            'password' => 'Biztrack-2026!',
            'role' => 'bplo_staff',
            'department_id' => Department::where('code', 'BPLO')->value('id'),
        ])->assertCreated()->json('data');

    // The admin payload never carries a home address (see the listings test below).
    expect($created)->not->toHaveKey('home_street')
        ->and(User::where('email', 'new.bplo@biztrack.local')->firstOrFail()->hasHomeAddress())->toBeFalse();

    // And a staff account is never told it owes one.
    $this->app['auth']->forgetGuards();
    $token = loginToken('new.bplo@biztrack.local', 'Biztrack-2026!', 'staff');
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.home_address_missing', false);
});

it('tells an owner who registered before the address was asked that they owe one', function () {
    ownerWithoutHomeAddress();
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.home_street', null)
        ->assertJsonPath('data.home_address_missing', true);
});

it('returns the stored home address on the me endpoint', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.home_street', '12 Gen. Luna St.')
        ->assertJsonPath('data.home_barangay', 'Longos')
        ->assertJsonPath('data.home_city', 'Malabon')
        ->assertJsonPath('data.home_province', 'Metro Manila')
        ->assertJsonPath('data.home_postal_code', '1472')
        ->assertJsonPath('data.home_address_missing', false);
});

it('completes a missing home address from the profile form', function () {
    $user = ownerWithoutHomeAddress();
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Nena',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
        'home_street' => 'Blk 4 Lot 12, Sampaguita St.',
        'home_barangay' => 'Tonsuya',
    ])
        ->assertOk()
        ->assertJsonPath('data.home_street', 'Blk 4 Lot 12, Sampaguita St.')
        ->assertJsonPath('data.home_barangay', 'Tonsuya')
        ->assertJsonPath('data.home_city', 'Malabon')
        ->assertJsonPath('data.home_province', 'Metro Manila')
        ->assertJsonPath('data.home_address_missing', false);

    expect($user->fresh()->hasHomeAddress())->toBeTrue()
        ->and($user->fresh()->home_barangay)->toBe('Tonsuya');
});

it('refuses half an address on the profile form', function () {
    $user = ownerWithoutHomeAddress();
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Nena',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
        'home_street' => '12 Gen. Luna St.',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['home_barangay'])
        ->assertJsonMissingValidationErrors(['home_city', 'home_province'])
        ->assertJsonPath('errors.home_barangay.0', 'Enter your barangay.');

    expect($user->fresh()->home_street)->toBeNull();
});

it('refuses a barangay outside Malabon on the profile form', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Nena',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
        'home_street' => '12 Gen. Luna St.',
        'home_barangay' => 'San Roque',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['home_barangay']);

    expect(User::where('email', 'owner@biztrack.local')->firstOrFail()->home_barangay)->toBe('Longos');
});

it('keeps an older owner\'s address outside Malabon until they choose a barangay of Malabon', function () {
    // Registered when the address was free text (the seeded Juan is one).
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(homeAddress([
        'home_street' => '7 M. Naval St.', 'home_barangay' => 'San Roque', 'home_city' => 'Navotas',
    ]))->save();
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();
    $edit = fn (string $barangay) => $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Nena',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
        'home_street' => '7 M. Naval St.',
        'home_barangay' => $barangay,
    ]);

    // A name edit sends the stored barangay back unchanged; it is not refused.
    $edit('San Roque')->assertOk()
        ->assertJsonPath('data.home_barangay', 'San Roque')
        ->assertJsonPath('data.home_city', 'Navotas')
        ->assertJsonPath('data.home_address_missing', false);

    $edit('Tonsuya')->assertOk()
        ->assertJsonPath('data.home_barangay', 'Tonsuya')
        ->assertJsonPath('data.home_city', 'Malabon')
        ->assertJsonPath('data.home_province', 'Metro Manila');
});

it('does not tell an owner they owe a city or province, which the form no longer asks', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(['home_city' => null, 'home_province' => null])->save();
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.home_address_missing', false);
});

it('refuses to blank an address already given', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Nena',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
        'home_street' => '',
        'home_barangay' => '',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['home_street', 'home_barangay']);

    expect(User::where('email', 'owner@biztrack.local')->firstOrFail()->home_street)->toBe('12 Gen. Luna St.');
});

it('keeps the stored address when the profile form does not send it', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    // A staff account's form, or any caller that predates the address.
    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Nena',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
    ])->assertOk()->assertJsonPath('data.home_street', '12 Gen. Luna St.');
});

it('clears an optional ZIP code sent empty, and keeps the rest', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Nena',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
        ...homeAddress(['home_postal_code' => '']),
    ])
        ->assertOk()
        ->assertJsonPath('data.home_postal_code', null)
        ->assertJsonPath('data.home_address_missing', false);
});

/*
 * The address is the owner's own business. The super admin's user listings
 * share UserResource with /auth/me, so a field added there reaches every
 * account screen at City Hall; it belongs on the owner's own payload only.
 */
it('shows an owner their home address, and keeps it out of the super admin user listings', function () {
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $owner->forceFill(homeAddress())->save();

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.home_street', '12 Gen. Luna St.');

    $admin = authAs('admin@biztrack.local');

    $listing = $this->withHeaders($admin)
        ->getJson('/api/v1/admin/users?search='.urlencode($owner->email))
        ->assertOk()
        ->json('data');
    expect($listing)->not->toBeEmpty();
    foreach ($listing as $row) {
        expect($row)->not->toHaveKeys(['home_street', 'home_barangay', 'home_city', 'home_province', 'home_postal_code']);
    }

});

it('refuses a sign-up whose contact number is not an 11-digit 09 mobile number', function (string $mobile) {
    // Sign-up only checked the length (max 20), so any text was stored.
    $this->postJson('/api/v1/auth/register', ownerRegistration(['mobile_number' => $mobile]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mobile_number'])
        ->assertJsonPath('errors.mobile_number.0', 'A mobile number is 11 digits and starts with 09, as in 09171234567.');

    expect(User::where('email', 'rosa.manalo@example.test')->exists())->toBeFalse();
})->with(['abcdefghijk', '0917123456', '091712345678', '19171234567', '0917-123-4567', '99999999999999999999']);

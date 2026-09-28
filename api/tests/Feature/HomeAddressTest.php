<?php

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
        ->assertJsonValidationErrors(['home_street', 'home_barangay', 'home_city', 'home_province'])
        // ZIP is the one optional part.
        ->assertJsonMissingValidationErrors(['home_postal_code'])
        ->assertJsonPath('errors.home_barangay.0', 'Enter your barangay.');

    expect(User::where('email', 'rosa.manalo@example.test')->exists())->toBeFalse();
});

it('refuses an owner registration with any one part of the address blank', function (string $part) {
    $this->postJson('/api/v1/auth/register', ownerRegistration([$part => '']))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$part]);
})->with(['home_street', 'home_barangay', 'home_city', 'home_province']);

it('registers an owner who lives outside Malabon, with a barangay that is not on the city list', function () {
    $response = $this->postJson('/api/v1/auth/register', ownerRegistration(homeAddress([
        'home_street' => '7 M. Naval St.',
        'home_barangay' => 'San Roque',
        'home_city' => 'Navotas',
        'home_postal_code' => null,
    ])))->assertCreated();

    $response->assertJsonPath('data.user.home_street', '7 M. Naval St.')
        ->assertJsonPath('data.user.home_barangay', 'San Roque')
        ->assertJsonPath('data.user.home_city', 'Navotas')
        ->assertJsonPath('data.user.home_province', 'Metro Manila')
        ->assertJsonPath('data.user.home_postal_code', null)
        ->assertJsonPath('data.user.home_address_missing', false);

    $user = User::where('email', 'rosa.manalo@example.test')->firstOrFail();
    expect($user->home_city)->toBe('Navotas')
        ->and($user->hasHomeAddress())->toBeTrue();
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
            'password' => 'biztrack1',
            'role' => 'bplo_staff',
            'department_id' => Department::where('code', 'BPLO')->value('id'),
        ])->assertCreated()->json('data');

    expect($created['home_street'])->toBeNull()
        ->and(User::where('email', 'new.bplo@biztrack.local')->firstOrFail()->hasHomeAddress())->toBeFalse();

    // And a staff account is never told it owes one.
    $this->app['auth']->forgetGuards();
    $token = loginToken('new.bplo@biztrack.local');
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
        ...homeAddress(['home_street' => 'Blk 4 Lot 12, Sampaguita St.', 'home_barangay' => 'Tonsuya']),
    ])
        ->assertOk()
        ->assertJsonPath('data.home_street', 'Blk 4 Lot 12, Sampaguita St.')
        ->assertJsonPath('data.home_barangay', 'Tonsuya')
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
        ->assertJsonValidationErrors(['home_barangay', 'home_city', 'home_province'])
        ->assertJsonPath('errors.home_city.0', 'Enter your city or municipality.');

    expect($user->fresh()->home_street)->toBeNull();
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
        'home_city' => '',
        'home_province' => '',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['home_street', 'home_barangay', 'home_city', 'home_province']);

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

<?php

use App\Models\User;

it('updates the signed-in user profile', function () {
    $token = loginToken('owner@biztrack.local');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Juana',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
    ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Juana')
        ->assertJsonPath('data.last_name', 'Dela Cruz')
        ->assertJsonPath('data.mobile_number', '09171234567');

    $user = User::where('email', 'owner@biztrack.local')->first();
    expect($user->first_name)->toBe('Juana')
        ->and($user->name)->toBe('Juana Dela Cruz');
});

/*
 * Checklist item 74 — "Edit Profile's field information is incomplete."
 *
 * The Profile screen prints User::fullName(), which is first + middle + last +
 * suffix, but the modal only ever sent first, last and mobile. The three tests
 * below pin the whole round trip: the extra parts persist, they can be cleared
 * once set, and a field the form does not send is left alone rather than wiped.
 */

it('persists middle name, suffix and gender from the profile form', function () {
    $token = loginToken('owner@biztrack.local');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Juana',
        'middle_name' => 'Reyes',
        'last_name' => 'Dela Cruz',
        'suffix' => 'Jr.',
        'gender' => 'F',
        'mobile_number' => '09171234567',
    ])
        ->assertOk()
        ->assertJsonPath('data.middle_name', 'Reyes')
        ->assertJsonPath('data.suffix', 'Jr.')
        ->assertJsonPath('data.gender', 'F');

    $user = User::where('email', 'owner@biztrack.local')->first();
    expect($user->middle_name)->toBe('Reyes')
        ->and($user->suffix)->toBe('Jr.')
        ->and($user->gender)->toBe('F')
        // The name the Profile screen actually renders.
        ->and($user->fullName())->toBe('Juana Reyes Dela Cruz Jr.');
});

it('clears a middle name and suffix when the form sends them empty', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $base = [
        'first_name' => 'Juana',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
    ];

    $this->withToken($token)
        ->putJson('/api/v1/auth/profile', $base + ['middle_name' => 'Reyes', 'suffix' => 'Jr.'])
        ->assertOk();

    // Empty string, as the modal sends it when the applicant deletes the text.
    // This is the case `?? $user->middle_name` could not express: once a middle
    // name was saved there was no payload that would take it back off.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)
        ->putJson('/api/v1/auth/profile', $base + ['middle_name' => '', 'suffix' => ''])
        ->assertOk()
        ->assertJsonPath('data.middle_name', null)
        ->assertJsonPath('data.suffix', null);

    $user = User::where('email', 'owner@biztrack.local')->first();
    expect($user->middle_name)->toBeNull()
        ->and($user->suffix)->toBeNull()
        ->and($user->fullName())->toBe('Juana Dela Cruz');
});

it('leaves a profile field alone when the form does not send it', function () {
    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $user->forceFill(['middle_name' => 'Reyes', 'gender' => 'F'])->save();

    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    // An older client, or any caller that posts only the three original fields.
    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Juana',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
    ])->assertOk();

    expect($user->fresh()->middle_name)->toBe('Reyes')
        ->and($user->fresh()->gender)->toBe('F');
});

it('rejects a gender outside the values registration accepts', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Juana',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
        'gender' => 'X',
    ])->assertStatus(422)->assertJsonValidationErrors('gender');
});

it('rejects a profile update without a valid token', function () {
    $this->putJson('/api/v1/auth/profile', [
        'first_name' => 'Juana',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
    ])->assertUnauthorized();
});

it('rejects a password change with the wrong current password', function () {
    $token = loginToken('owner@biztrack.local');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/password', [
        'current_password' => 'not-the-password',
        'password' => 'brand-new-pass1',
        'password_confirmation' => 'brand-new-pass1',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');
});

it('changes the password and revokes every other token', function () {
    $tokenA = loginToken('owner@biztrack.local'); // "other device"
    $tokenB = loginToken('owner@biztrack.local'); // device making the change

    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)->putJson('/api/v1/auth/password', [
        'current_password' => 'biztrack1',
        'password' => 'brand-new-pass1',
        'password_confirmation' => 'brand-new-pass1',
    ])->assertOk();

    // The other device's token is revoked; the current one survives.
    $this->app['auth']->forgetGuards();
    $this->withToken($tokenA)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->withToken($tokenB)->getJson('/api/v1/auth/me')->assertOk();

    // The new password is the one that signs in now.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'brand-new-pass1',
    ])->assertOk();
});

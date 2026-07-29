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

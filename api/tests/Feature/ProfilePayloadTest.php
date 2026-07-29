<?php

use App\Models\User;

/*
 * The Profile screen (web/src/pages/ProfilePage.tsx) reads the signed-in user
 * straight out of the auth store, so every payload that fills that store has to
 * carry the same fields — including the join date it shows as "member since".
 */

it('includes the join date when signing in', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'public',
    ])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'owner@biztrack.local')
        ->assertJsonStructure(['data' => ['user' => ['created_at']]]);
});

it('includes the join date on the me endpoint', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $response = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $user = User::where('email', 'owner@biztrack.local')->firstOrFail();
    expect($response->json('data.created_at'))->toBe($user->created_at->toISOString());
});

it('includes the join date after a profile update', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Juana',
        'last_name' => 'Dela Cruz',
        'mobile_number' => '09171234567',
    ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Juana')
        ->assertJsonStructure(['data' => ['created_at']]);
});

it('includes the join date when registering', function () {
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Bagong',
        'last_name' => 'Negosyante',
        'gender' => 'F',
        'email' => 'bagong.negosyante@example.com',
        'mobile_number' => '09171234567',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'data_privacy_consent' => true,
    ])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['user' => ['created_at']]]);
});

it('carries the department for a staff account so the profile can name their office', function () {
    $token = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();

    $response = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('data.department.name'))->not->toBeNull()
        ->and($response->json('data.roles'))->toContain('bplo_staff')
        ->and($response->json('data.created_at'))->not->toBeNull();
});

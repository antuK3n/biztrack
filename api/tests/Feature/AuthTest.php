<?php

use App\Models\User;

it('logs in a valid business owner', function () {
    $res = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'biztrack1',
    ]);

    $res->assertOk()
        ->assertJsonPath('data.user.email', 'owner@biztrack.local')
        ->assertJsonStructure(['data' => ['token', 'user' => ['roles', 'permissions']]]);

    // last_login_at is stamped (paper Table 35).
    expect(User::where('email', 'owner@biztrack.local')->first()->last_login_at)->not->toBeNull();
});

it('rejects a bad password with 422 and counts the attempt', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@biztrack.local',
        'password' => 'wrong-password',
    ])->assertStatus(422);

    expect(User::where('email', 'owner@biztrack.local')->first()->failed_login_attempts)->toBe(1);
});

it('blocks a deactivated account with 403', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@biztrack.local',
        'password' => 'biztrack1',
    ])->assertStatus(403);
});

<?php

use App\Models\User;

/*
 * Item 74: staff had no avatar menu in the rail, so Settings was unreachable
 * and an officer could not change their own password. The rail now gives every
 * signed-in user the same account menu; these tests pin the endpoints behind it
 * so a staff account keeps working end to end.
 */

it('lets a staff account change its own password and sign in with the new one', function () {
    $token = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/password', [
        'current_password' => 'biztrack1',
        'password' => 'officer-pass-2',
        'password_confirmation' => 'officer-pass-2',
    ])->assertOk();

    // Staff sign in through their own door; the new password is the live one.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'officer-pass-2',
        'portal' => 'staff',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bplo@biztrack.local',
        'password' => 'biztrack1',
        'portal' => 'staff',
    ])->assertStatus(422);
});

it('lets a staff account edit its own profile details', function () {
    $token = loginToken('zoning@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->putJson('/api/v1/auth/profile', [
        'first_name' => 'Zena',
        'last_name' => 'Zoning',
        'mobile_number' => '09171234567',
    ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Zena');

    // The office name is what the Profile screen shows a staff user; keep it.
    $user = User::where('email', 'zoning@biztrack.local')->firstOrFail();
    expect($user->department?->name)->not->toBeNull();
});

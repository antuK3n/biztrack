<?php

use App\Models\Department;
use App\Models\User;

/*
 * An e-mail address is the same address in any capitals.
 *
 * Every writer already stored it lowercased, and sign-in looks it up
 * lowercased, but the uniqueness check ran on the address as typed. So
 * "Owner@BizTrack.local", typed when owner@biztrack.local existed, passed
 * validation, was lowercased on the way in, and hit the unique index: a 500
 * "Server Error" instead of the sentence saying the address is taken
 * (scenario run, 5 October 2026: owner-register row 2, admin-officers
 * row 11). The address is now trimmed and lowercased before it is checked.
 */
function emailCasePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'gender' => 'F',
        'email' => 'ana.reyes@example.test',
        'mobile_number' => '09171234567',
        'password' => 'biztrack1',
        'password_confirmation' => 'biztrack1',
        'data_privacy_consent' => true,
    ], homeAddress(), $overrides);
}

function emailCaseOfficer(string $email): array
{
    return [
        'first_name' => 'Case',
        'last_name' => 'Officer',
        'gender' => 'F',
        'email' => $email,
        'mobile_number' => '09171234567',
        'password' => 'Biztrack-Test1!',
        'roles' => ['bplo_staff'],
        'department_id' => Department::where('code', 'BPLO')->value('id'),
    ];
}

it('refuses a sign-up with a registered e-mail typed in other capitals, with the same sentence', function () {
    $before = User::count();

    $this->postJson('/api/v1/auth/register', emailCasePayload(['email' => ' Owner@BizTrack.local ']))
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'This email is already registered. Try signing in instead.');

    expect(User::count())->toBe($before);
});

it('stores a new sign-up’s e-mail lowercased', function () {
    $this->postJson('/api/v1/auth/register', emailCasePayload(['email' => 'Ana.Reyes@Example.TEST']))
        ->assertCreated();

    expect(User::where('email', 'ana.reyes@example.test')->exists())->toBeTrue();
});

it('refuses a staff account whose e-mail differs from an existing one only by capitals', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', emailCaseOfficer('Sanitary@BizTrack.local'))
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'This email is already registered.');
});

it('refuses a staff edit onto another account’s e-mail in other capitals, and keeps the account’s own', function () {
    $admin = authAs('admin@biztrack.local');
    $id = test()->withHeaders($admin)
        ->postJson('/api/v1/admin/users', emailCaseOfficer('case.officer@biztrack.local'))
        ->assertCreated()->json('data.id');

    test()->withHeaders($admin)
        ->putJson("/api/v1/admin/users/{$id}", ['email' => 'Sanitary@BizTrack.local'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'This email is already registered.');

    // Its own address in other capitals is still its own.
    test()->withHeaders($admin)
        ->putJson("/api/v1/admin/users/{$id}", ['email' => 'Case.Officer@BizTrack.local'])
        ->assertOk();
    expect(User::findOrFail($id)->email)->toBe('case.officer@biztrack.local');
});

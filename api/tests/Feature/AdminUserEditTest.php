<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * Editing a user must not take their password away.
 *
 * The update rule is `'password' => ['nullable', PasswordRule::min(8)]` and the
 * validated array went straight into fill(). An edit form that always posts its
 * password field — empty, because the admin came to fix a surname — sends
 * `password: ""`, ConvertEmptyStringsToNull turns that into null, and the
 * `hashed` cast passes null through untouched.
 *
 * Measured before the fix: `PUT /admin/users/{id}` with an empty password
 * answers **500**, a NOT NULL constraint violation on users.password, and the
 * surname change is lost with it. Where such a column is nullable the same
 * write succeeds and is worse — a 200 that has locked the account out of every
 * password it will ever be given.
 */

/** A throwaway officer to edit, so the demo storyline stays intact. */
function editableOfficer(): User
{
    $user = User::create([
        'name' => 'Edit Target',
        'first_name' => 'Edit',
        'last_name' => 'Target',
        'gender' => 'F',
        'email' => 'edit.target@biztrack.local',
        'mobile_number' => '09170000000',
        'password' => 'biztrack1',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->roles()->sync(Role::where('name', 'bplo_staff')->pluck('id'));

    return $user;
}

it('leaves the password alone when the edit form posts an empty one', function () {
    $user = editableOfficer();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$user->id}", [
            'last_name' => 'Corrected',
            'password' => '',
        ])
        ->assertOk();

    $user->refresh();

    expect($user->last_name)->toBe('Corrected')
        ->and($user->password)->not->toBeNull('the password column was wiped')
        ->and(Hash::check('biztrack1', $user->password))->toBeTrue('the account can no longer sign in');
});

it('leaves the password alone when the field is posted as null', function () {
    $user = editableOfficer();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$user->id}", [
            'mobile_number' => '09171111111',
            'password' => null,
        ])
        ->assertOk();

    $user->refresh();

    expect(Hash::check('biztrack1', $user->password))->toBeTrue();
});

it('still changes the password when one is actually typed', function () {
    $user = editableOfficer();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$user->id}", ['password' => 'newpassword9'])
        ->assertOk();

    $user->refresh();

    expect(Hash::check('newpassword9', $user->password))->toBeTrue()
        ->and(Hash::check('biztrack1', $user->password))->toBeFalse();
});

it('narrows the staff directory to one office when asked', function () {
    $sanitary = User::where('email', 'sanitary@biztrack.local')->firstOrFail();

    $rows = test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson("/api/v1/admin/users?department_id={$sanitary->department_id}")
        ->assertOk()->json('data');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row['department']['id'])->toBe($sanitary->department_id);
    }
});

<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * biztrack:create-super-admin makes the one account that can sign in on a fresh
 * production register. The seeded test database already has a super admin
 * (admin@biztrack.local), which is exactly the case it must refuse.
 */

it('refuses while a super admin exists, because there is only ever one', function () {
    $this->artisan('biztrack:create-super-admin')
        ->expectsOutputToContain('A super admin already exists')
        ->assertFailed();
});

it('creates the super admin from the prompts, with the password only ever hashed', function () {
    Role::where('name', 'admin')->first()->users()->detach();

    $this->artisan('biztrack:create-super-admin')
        ->expectsQuestion('First name', 'Ana')
        ->expectsQuestion('Last name', 'Reyes')
        ->expectsQuestion('E-mail (used to sign in)', 'Ana.Reyes@Example.com')
        ->expectsQuestion('Password (not shown as you type)', 'correct horse 2026')
        ->expectsQuestion('Password again', 'correct horse 2026')
        ->expectsOutputToContain('Super admin created: ana.reyes@example.com')
        ->assertSuccessful();

    $user = User::where('email', 'ana.reyes@example.com')->firstOrFail();
    expect($user->roles->pluck('name')->all())->toBe(['admin'])
        ->and($user->is_active)->toBeTrue()
        ->and($user->password)->not->toBe('correct horse 2026')
        ->and(Hash::check('correct horse 2026', $user->password))->toBeTrue();
});

it('refuses a short password and creates nothing', function () {
    Role::where('name', 'admin')->first()->users()->detach();

    $this->artisan('biztrack:create-super-admin')
        ->expectsQuestion('First name', 'Ana')
        ->expectsQuestion('Last name', 'Reyes')
        ->expectsQuestion('E-mail (used to sign in)', 'ana@example.com')
        ->expectsQuestion('Password (not shown as you type)', 'short1')
        ->expectsQuestion('Password again', 'short1')
        ->assertFailed();

    expect(User::where('email', 'ana@example.com')->exists())->toBeFalse();
});

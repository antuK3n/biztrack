<?php

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * Editing a user must not take their password away.
 *
 * The update rule is `nullable` (see `StaffCredentials::passwordRules`) and the
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

/**
 * A throwaway officer to edit, so the demo storyline stays intact.
 *
 * The office is part of the fixture, not decoration. An officer with no
 * department signs in to an empty queue — AssignmentController sends a
 * departmentless non-admin down `whereRaw('1=0')` — and the admin endpoint now
 * refuses to leave an account in that state. This fixture used to build one,
 * which is precisely the shape the guard exists to prevent; the assertions
 * below are about passwords and are untouched.
 */
function editableOfficer(): User
{
    $user = User::create([
        'name' => 'Edit Target',
        'first_name' => 'Edit',
        'last_name' => 'Target',
        'gender' => 'F',
        'email' => 'edit.target@biztrack.local',
        'mobile_number' => '09170000000',
        'password' => 'Biztrack-Test1!',
        'department_id' => Department::where('code', 'BPLO')->value('id'),
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
        ->and(Hash::check('Biztrack-Test1!', $user->password))->toBeTrue('the account can no longer sign in');
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

    expect(Hash::check('Biztrack-Test1!', $user->password))->toBeTrue();
});

it('still changes the password when one is actually typed', function () {
    $user = editableOfficer();

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$user->id}", ['password' => 'Newpassword-2026!'])
        ->assertOk();

    $user->refresh();

    expect(Hash::check('Newpassword-2026!', $user->password))->toBeTrue()
        ->and(Hash::check('Biztrack-Test1!', $user->password))->toBeFalse();
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

/*
 * ── What a staff password and a mobile number have to be ───────────────
 *
 * This screen mints every officer account in the city, and until now it asked
 * for eight characters of anything and a mobile number of at most twenty
 * characters of anything at all. "password" passed. So did "0917", which is
 * why the register holds numbers of nine, ten and twelve digits — each one a
 * person nobody can reach, entered through a form that said it was fine
 * [client, 27 September 2026: *"magkaroon ng validations requirement para sa
 * security ... ganon din sa mobile number"*].
 *
 * The rules live in `StaffCredentials` so that the three endpoints taking
 * these two fields cannot drift apart again.
 */

it('refuses a password too weak for an account that approves permits', function () {
    $user = editableOfficer();

    foreach ([
        'Ab1!' => 'four characters is not six',
        'sample1!' => 'no capital letter',
        'SamplePass!' => 'no digit',
        'SamplePass1' => 'no symbol',
    ] as $password => $why) {
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->putJson("/api/v1/admin/users/{$user->id}", ['password' => $password])
            ->assertStatus(422, "accepted a password with {$why}")
            ->assertJsonValidationErrors('password');
    }

    // And the account still has the one it started with — a refused write must
    // not be a half-done one.
    expect(Hash::check('Biztrack-Test1!', $user->refresh()->password))->toBeTrue();
});

it('accepts a short password that carries all four kinds', function () {
    /*
     * Six characters is the floor [client, 28 September 2026], and the four
     * kinds are what stop "password" and "123456" — the failures actually seen
     * in the field. Anything longer passes too; this pins the floor itself, so
     * a later tightening cannot happen by accident.
     */
    $user = editableOfficer();

    // Exactly six: a capital, a small letter, a digit and a symbol.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$user->id}", ['password' => 'Ab1!cd'])
        ->assertOk();

    expect(Hash::check('Ab1!cd', $user->refresh()->password))->toBeTrue();

    // And five of the same kinds is one too few.
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$user->id}", ['password' => 'Ab1!c'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('signs out everyone holding a session when the password is changed', function () {
    /*
     * The reason an admin resets somebody's password is usually that the wrong
     * person has it. Sanctum tokens do not depend on the password, so without
     * this the reset changes nothing for the person it was aimed at: they keep
     * the session they already had.
     */
    $user = editableOfficer();
    $user->createToken('phone')->plainTextToken;
    $user->createToken('desktop')->plainTextToken;

    expect($user->tokens()->count())->toBe(2);

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$user->id}", ['password' => 'Malabon-City-2026!'])
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('leaves the sessions alone when the edit was not about the password', function () {
    // Fixing a surname must not sign somebody out of the queue they are working.
    $user = editableOfficer();
    $user->createToken('desktop');

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson("/api/v1/admin/users/{$user->id}", ['last_name' => 'Corrected'])
        ->assertOk();

    expect($user->tokens()->count())->toBe(1);
});

it('refuses a mobile number nobody could be reached on', function () {
    $user = editableOfficer();

    foreach (['0917123456', '091712345646', '091234567', '9171234567', 'not a number'] as $number) {
        test()->withHeaders(authAs('admin@biztrack.local'))
            ->putJson("/api/v1/admin/users/{$user->id}", ['mobile_number' => $number])
            ->assertStatus(422, "accepted {$number}")
            ->assertJsonValidationErrors('mobile_number');
    }
});

it('says how to fix a mobile number rather than that it is invalid', function () {
    /*
     * "The mobile number format is invalid" tells somebody staring at a field
     * they have already checked twice precisely nothing. WCAG 3.3.3 asks for a
     * suggestion, so the message carries the shape and an example.
     */
    $message = test()->withHeaders(authAs('admin@biztrack.local'))
        ->putJson('/api/v1/admin/users/'.editableOfficer()->id, ['mobile_number' => '0917'])
        ->assertStatus(422)
        ->json('errors.mobile_number.0');

    expect($message)->toContain('11 digits')
        ->and($message)->toContain('09171234567');
});

it('takes the punctuation out of an 09 number, and nothing else', function () {
    /*
     * `0917-123-4567` is `09171234567` written with punctuation, and refusing
     * it teaches nobody anything — it just makes the admin retype what they
     * already had right.
     */
    foreach (['0917-123-4567', '0917 123 4567', '(0917) 123 4567'] as $typed) {
        $user = editableOfficer();

        test()->withHeaders(authAs('admin@biztrack.local'))
            ->putJson("/api/v1/admin/users/{$user->id}", ['mobile_number' => $typed])
            ->assertOk("refused {$typed}");

        expect($user->refresh()->mobile_number)->toBe('09171234567');

        $user->forceDelete();
    }
});

it('no longer takes +63, because one shape is what the counter teaches', function () {
    /*
     * It used to convert `+639171234567` into `09171234567`. The client asked
     * for that to go [28 September 2026: *"i want 09 at 11 digits lang"*], and
     * they are right that one shape is easier to teach than two that look
     * different and mean the same.
     *
     * Refused rather than silently rewritten: quietly turning it into a number
     * the admin did not type is the method deciding what they meant.
     */
    foreach (['+639171234567', '639171234567', '+63 917 123 4567'] as $typed) {
        $user = editableOfficer();

        test()->withHeaders(authAs('admin@biztrack.local'))
            ->putJson("/api/v1/admin/users/{$user->id}", ['mobile_number' => $typed])
            ->assertStatus(422, "accepted {$typed}")
            ->assertJsonValidationErrors('mobile_number');

        // And the stored number is untouched by the refusal.
        expect($user->refresh()->mobile_number)->toBe('09170000000');

        $user->forceDelete();
    }
});

it('holds a new account to the same two rules', function () {
    // Creating and editing must not disagree about what a password is, or the
    // weak ones simply move to whichever door is still open.
    $payload = [
        'first_name' => 'Weakly',
        'last_name' => 'Secured',
        'gender' => 'M',
        'email' => 'weakly.secured@biztrack.local',
        'mobile_number' => '0917',
        'password' => 'password',
        'roles' => ['bplo_staff'],
        'department_id' => Department::where('code', 'BPLO')->value('id'),
    ];

    test()->withHeaders(authAs('admin@biztrack.local'))
        ->postJson('/api/v1/admin/users', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['password', 'mobile_number']);

    expect(User::where('email', 'weakly.secured@biztrack.local')->exists())->toBeFalse();
});

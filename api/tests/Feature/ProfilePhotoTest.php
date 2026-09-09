<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * "Edit Profile Picture" on the Edit Profile modal (PDF p12) was a control that
 * did nothing: there was no column to write to and no route to call, so every
 * avatar in the app was the same gray glyph. These tests pin the round trip it
 * now performs.
 *
 * ## Why the fixtures carry real bytes
 *
 * `UploadedFile::fake()->create()` writes an EMPTY file. A stored-bytes
 * assertion against one compares zero to zero and would pass against a route
 * that saved nothing — the trap that let a checklist item read "passed" for
 * weeks. `->image()` writes real pixels but needs the GD extension, which this
 * PHP does not load, so the helpers below hand over genuine PNG and PDF bytes
 * instead. That also means `mimes:` is deciding on the file's actual magic
 * bytes, which is what it will be doing in production.
 */

/** A real 1x1 PNG, optionally padded to a given total size. */
function photoBytes(int $padToBytes = 0): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );

    // Trailing bytes after IEND do not disturb the magic-byte sniff at the
    // head of the file, so this stays a PNG while crossing the size limit.
    return $padToBytes > strlen($png)
        ? $png.str_repeat("\0", $padToBytes - strlen($png))
        : $png;
}

function fakePhoto(string $name = 'me.png', int $padToBytes = 0): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, photoBytes($padToBytes));
}

beforeEach(function () {
    Storage::fake('local');
});

it('stores a profile photo and reports that the account has one', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto()])
        ->assertOk()
        ->assertJsonPath('data.has_photo', true);

    $user = User::where('email', 'owner@biztrack.local')->first();

    expect($user->avatar_path)->not->toBeNull()
        ->and($user->avatar_path)->toStartWith("private/avatars/{$user->id}/");

    Storage::disk('local')->assertExists($user->avatar_path);
    // Non-zero, and byte-identical to what was sent: proof the route stored the
    // upload rather than touching an empty file into place.
    expect(Storage::disk('local')->get($user->avatar_path))->toBe(photoBytes());
});

it('never exposes where the photo is stored', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $body = $this->withToken($token)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto()])
        ->assertOk()
        ->json('data');

    // has_photo is a yes/no. A client that learned the path could ask for
    // another account's file by editing it.
    expect($body)->toHaveKey('has_photo')
        ->and($body)->not->toHaveKey('avatar_path');
});

it('deletes the photo it replaces rather than leaving the file behind', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto('first.png')])
        ->assertOk();
    $first = User::where('email', 'owner@biztrack.local')->first()->avatar_path;

    $this->withToken($token)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto('second.png')])
        ->assertOk();
    $second = User::where('email', 'owner@biztrack.local')->first()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);
});

it('serves the signed-in user their own photo', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto()])
        ->assertOk();

    $response = $this->withToken($token)->get('/api/v1/auth/profile/photo');

    $response->assertOk();
    expect($response->streamedContent())->toBe(photoBytes());
});

it('answers 404 for an account that has set no photo', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/v1/auth/profile/photo')
        ->assertNotFound();
});

it('keeps each account to its own photo', function () {
    $ownerToken = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($ownerToken)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto('owner.png')])
        ->assertOk();

    $staffToken = loginToken('bplo@biztrack.local');
    $this->app['auth']->forgetGuards();
    $this->withToken($staffToken)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto('bplo.png')])
        ->assertOk();

    $owner = User::where('email', 'owner@biztrack.local')->first();
    $staff = User::where('email', 'bplo@biztrack.local')->first();

    // The route carries no user id, so the paths cannot collide and no request
    // can name the other account's file.
    expect($owner->avatar_path)->not->toBe($staff->avatar_path)
        ->and($owner->avatar_path)->toStartWith("private/avatars/{$owner->id}/")
        ->and($staff->avatar_path)->toStartWith("private/avatars/{$staff->id}/");
});

it('removes a photo and deletes its file', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto()])
        ->assertOk();
    $path = User::where('email', 'owner@biztrack.local')->first()->avatar_path;

    $this->withToken($token)
        ->deleteJson('/api/v1/auth/profile/photo')
        ->assertOk()
        ->assertJsonPath('data.has_photo', false);

    expect(User::where('email', 'owner@biztrack.local')->first()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('refuses a PDF as a profile photo', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    // A PDF is a sensible permit scan and a nonsensical avatar — an <img>
    // cannot render one, so it is refused here rather than stored and shown
    // as a broken image. Real PDF bytes, so `mimes:` is rejecting it on what
    // the file is rather than on the file being empty.
    $pdf = UploadedFile::fake()->createWithContent(
        'scan.pdf',
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
    );

    $this->withToken($token)
        ->postJson('/api/v1/auth/profile/photo', ['photo' => $pdf])
        ->assertStatus(422)
        ->assertJsonValidationErrors('photo');

    expect(User::where('email', 'owner@biztrack.local')->first()->avatar_path)->toBeNull();
});

it('refuses an image over the 5 MB limit', function () {
    $token = loginToken('owner@biztrack.local');
    $this->app['auth']->forgetGuards();

    // Still a genuine PNG, just past the cap — so the refusal is the size rule
    // firing, not the mime rule catching an empty file.
    $this->withToken($token)
        ->postJson('/api/v1/auth/profile/photo', [
            'photo' => fakePhoto('huge.png', 6 * 1024 * 1024),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('photo');

    expect(User::where('email', 'owner@biztrack.local')->first()->avatar_path)->toBeNull();
});

it('refuses a photo request that carries no token', function () {
    $this->postJson('/api/v1/auth/profile/photo', ['photo' => fakePhoto()])
        ->assertUnauthorized();

    $this->getJson('/api/v1/auth/profile/photo')->assertUnauthorized();
    $this->deleteJson('/api/v1/auth/profile/photo')->assertUnauthorized();
});

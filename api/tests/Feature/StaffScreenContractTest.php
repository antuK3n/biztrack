<?php

use App\Models\AuditLog;

/*
 * The contracts the staff screens read to decide what to render.
 *
 * Each of these was being assumed rather than checked, and each assumption had a
 * visible bug behind it. They live together because they are one idea: a screen
 * may only assert what the API actually tells it.
 */

/*
 * The review sheet shows Reject only to an officer who holds
 * application.reject. It used to show the button to everyone, so a sanitary or
 * fire reviewer could open the composer, write a reason, confirm, and be told
 * "You do not have permission to perform this action" — with the reason already
 * typed and the filing untouched.
 *
 * RejectAuthorizationTest already proves the endpoint refuses those offices.
 * What the *screen* depends on is that /auth/me reports the permission
 * truthfully, because that is the only thing it can gate on.
 */
it('reports application.reject through /auth/me exactly where the endpoint allows it', function (string $email, bool $expected) {
    $headers = authAs($email);

    $permissions = test()->withHeaders($headers)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->json('data.permissions');

    expect(in_array('application.reject', $permissions, true))->toBe($expected);
})->with([
    ['admin@biztrack.local', true],
    ['bplo@biztrack.local', true],
    ['sanitary@biztrack.local', false],
    ['fire@biztrack.local', false],
]);

/*
 * Hiding Reject must not have left those offices without recourse — they keep
 * Return, which is already pinned by RejectAuthorizationTest ("still lets a
 * non-BPLO office return its own assignment"). Not restated here.
 */

/*
 * The audit trail does not always know who acted: `user_id` is null on rows the
 * actor was never recorded for, which on the demo register is 28% of them and
 * every one a `user.logged_in`. The screen used to print "System" for all of
 * them, naming a machine for something a person did. It now says the actor is
 * not recorded. This pins that null is a real, reachable state — so the screen
 * is right to have a branch for it rather than assuming a name.
 */
it('allows an audit entry with no recorded actor', function () {
    $admin = authAs('admin@biztrack.local');

    AuditLog::query()->delete();
    AuditLog::create([
        'user_id' => null,
        'action' => 'user.logged_in',
        'auditable_type' => App\Models\User::class,
        'auditable_id' => 1,
        'changes' => null,
    ]);

    $row = test()->withHeaders($admin)
        ->getJson('/api/v1/admin/audit-logs')
        ->assertOk()
        ->json('data.0');

    expect($row['user'])->toBeNull();
});

/*
 * Both admin panels that read the audit trail — officer Details and the Owner
 * Status history — scan a fixed number of pages because there is no per-target
 * filter. Their wording quotes the number of entries they looked at, so the page
 * size has to be knowable and small. If this default changes, those captions go
 * with it.
 */
it('pages the audit trail at 25 by default', function () {
    $admin = authAs('admin@biztrack.local');

    $meta = test()->withHeaders($admin)
        ->getJson('/api/v1/admin/audit-logs')
        ->assertOk()
        ->json('meta');

    expect($meta['per_page'])->toBe(25)
        ->and($meta)->toHaveKeys(['current_page', 'last_page', 'total']);
});

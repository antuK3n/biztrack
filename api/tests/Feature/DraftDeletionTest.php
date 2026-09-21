<?php

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PermitType;
use App\Models\User;
use App\Support\DashboardAnalytics;

/*
 * Deleting a draft.
 *
 * Client, 18 September 2026, on the Drafts page: *"does it have delete function
 * for it? If not, please put and a confirmation modal too."*
 *
 * It did not. The page has carried a trash button in its header since it was
 * drawn from the PDF mockup, with no `onClick` at all — a control that
 * advertised the feature and did nothing — and there was no endpoint behind it
 * either. `cancel` existed, but that is a different act: it marks a filing
 * Cancelled and leaves it in the register, which is right for something an
 * office has seen and wrong for a form that was never submitted.
 *
 * What these tests pin is mostly the BOUNDARY, because that is where a delete
 * endpoint does damage: whose drafts, which statuses, and whether the row is
 * really recoverable rather than recoverable-in-name.
 */

/** A draft owned by `owner@biztrack.local`. */
function deletableDraft(string $status = 'draft'): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => $status,
    ]);

    $app->permitTypes()->attach(
        PermitType::where('code', 'BUSINESS')->value('id'),
        ['status' => 'not_started'],
    );

    return $app;
}

it('deletes a draft and takes it out of the drafts list', function () {
    $app = deletableDraft();
    $headers = authAs('owner@biztrack.local');

    // Present before, so the disappearance below means something.
    $before = test()->withHeaders($headers)
        ->getJson('/api/v1/applications?status=draft')->assertOk()->json('data');
    expect(collect($before)->pluck('id'))->toContain($app->id);

    test()->withHeaders($headers)
        ->deleteJson("/api/v1/applications/{$app->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $app->id);

    $after = test()->withHeaders($headers)
        ->getJson('/api/v1/applications?status=draft')->assertOk()->json('data');
    expect(collect($after)->pluck('id'))->not->toContain($app->id);
});

it('soft-deletes, so the row is genuinely recoverable', function () {
    /*
     * The distinction worth asserting: gone from every reader, still on disk.
     * A hard delete would also have taken the pivot rows, the documents and the
     * status history with it, and "undo" would have been a lie.
     */
    $app = deletableDraft();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}")->assertOk();

    expect(Application::find($app->id))->toBeNull()
        ->and(Application::withTrashed()->find($app->id))->not->toBeNull()
        ->and(Application::withTrashed()->find($app->id)->deleted_at)->not->toBeNull();

    // The permits the draft asked for are still attached to the trashed row,
    // which is what makes a restore whole rather than a stub.
    expect(Application::withTrashed()->find($app->id)->permitTypes()->count())->toBe(1);
});

it('refuses somebody else’s draft', function () {
    $app = deletableDraft();

    // A different applicant entirely. 403 and the draft survives — the second
    // half matters, because a refusal that had already deleted the row would
    // still read as a refusal.
    test()->withHeaders(authAs('juan@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}")
        ->assertStatus(403);

    expect(Application::find($app->id))->not->toBeNull();
});

it('refuses a filing that has been submitted', function () {
    /*
     * Once BPLO has it, the record of the asking belongs to the register and
     * `cancel` is the verb. The message says so rather than just refusing,
     * because an applicant who wants rid of a filing needs to be told what to
     * press instead.
     */
    $app = deletableDraft('for_approval');

    $message = test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}")
        ->assertStatus(422)
        ->json('message');

    expect($message)->toContain('cancelled')
        ->and(Application::find($app->id))->not->toBeNull();
});

it('refuses a RETURNED filing, unlike removing one of its documents', function () {
    /*
     * The one boundary that is genuinely a judgement rather than an obvious
     * rule, so it is pinned. `destroyDocument` accepts Draft OR Returned —
     * editing a returned filing is the point of returning it. Deleting the
     * filing is not editing it: it would erase BPLO's decision to send it back
     * and the remarks the applicant is meant to be acting on.
     */
    $app = deletableDraft('returned');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}")
        ->assertStatus(422);

    expect(Application::find($app->id))->not->toBeNull();
});

it('writes an audit row naming what was deleted', function () {
    // Written before the delete, so it describes a row that still exists.
    $app = deletableDraft();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}")->assertOk();

    $entry = AuditLog::where('action', 'application.draft_deleted')
        ->where('auditable_id', $app->id)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull();
});

it('keeps a deleted draft out of the dashboard counts', function () {
    /*
     * The raw analytics queries do not go through Eloquent, so the soft-delete
     * scope does not protect them — they carry `whereNull('deleted_at')` by
     * hand. That was true before this endpoint existed and nothing had ever
     * exercised it, because nothing had ever soft-deleted an application
     * (measured: 0 trashed rows). Now something does.
     */
    $ytd = fn () => DashboardAnalytics::build()['kpis']['applications_ytd'];

    $before = $ytd();
    $app = deletableDraft();
    // Counted while it exists, so the drop below is this draft and not drift.
    expect($ytd())->toBe($before + 1);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/applications/{$app->id}")->assertOk();

    expect($ytd())->toBe($before);
});

<?php

use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\AppNotification;
use App\Models\OfficerRequest;

/*
 * Text the validators accept is text the database keeps.
 *
 * SQLite ignores a varchar's length, so none of these could fail on dev or
 * demo. PostgreSQL enforces it and refuses the whole write, which turned an
 * officer's ordinary rejection into a 500 on the production database. The
 * lengths below are the validators' own maximums, so these pass or fail on
 * the column, never on the input (migration 2026_09_30_000100).
 */

it('keeps a thousand-character rejection reason whole in the timeline and the applicant’s notice', function () {
    // An OPEN filing — see RejectAuthorizationTest::firstOpenApplication.
    $app = Application::whereIn('status', ['for_approval', 'pending_payment', 'approved'])
        ->notDecided()->whereNotNull('applicant_user_id')->firstOrFail();
    $reason = str_repeat('The lease on file names a different lot. ', 25);
    $reason = substr($reason, 0, 1000);

    $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/reject", ['reason' => $reason])
        ->assertOk();

    $note = ApplicationStatusHistory::where('application_id', $app->id)
        ->where('to_status', 'rejected')->latest('id')->value('note');
    $notice = AppNotification::where('user_id', $app->applicant_user_id)
        ->where('title', 'Application rejected')->latest('id')->value('body');

    expect($note)->toBe($reason)
        ->and($notice)->toContain($reason);
});

it('keeps a meeting link longer than 255 characters whole', function () {
    $app = Application::whereIn('status', ['for_approval', 'pending_payment', 'approved'])->notDecided()->firstOrFail();
    // A Teams invitation is this long in practice; the validator allows 500.
    $link = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_'.str_repeat('Tm90QVJlYWxNZWV0aW5n', 20);
    expect(strlen($link))->toBeGreaterThan(255)->toBeLessThanOrEqual(500);

    $id = $this->withHeaders(authAs('bplo@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/requests", [
            'request_type' => 'meeting',
            'title' => 'Clarification call',
            'meeting_scheduled_at' => now()->addDays(2)->toIso8601String(),
            'meeting_link' => $link,
        ])
        ->assertCreated()
        ->json('data.id');

    expect(OfficerRequest::find($id)->meeting_link)->toBe($link);
});

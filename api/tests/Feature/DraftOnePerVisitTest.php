<?php

use App\Models\User;
use App\Models\WizardDraft;
use Illuminate\Testing\TestResponse;

/*
 * One opening of the wizard is one unfinished filing (Ken, 6 October 2026:
 * "multiple drafts are being created"). Production held six scratch rows for
 * one tester in seven minutes, two begun in the same second: the first save
 * was still on its way when the next left, and each began its own row. The
 * wizard names its visit, and a repeat of that name writes to the row the
 * first one began.
 */
function ownerId(): int
{
    return User::where('email', 'owner@biztrack.local')->value('id');
}

function beginDraft(?string $visit, array $payload = ['v' => 1]): TestResponse
{
    return test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/wizard-drafts', array_filter([
            'application_type' => 'new',
            'title' => 'New Business Permit',
            'payload' => $payload,
            'visit' => $visit,
        ], fn ($v) => $v !== null));
}

beforeEach(function () {
    WizardDraft::where('user_id', ownerId())->delete();
});

it('writes a second create from the same visit to the row the first one began', function () {
    $first = beginDraft('visit-a', ['v' => 1, 'title' => 'first'])->assertCreated()->json('data.id');
    $again = beginDraft('visit-a', ['v' => 1, 'title' => 'second'])->assertOk()->json('data.id');

    expect($again)->toBe($first)
        ->and(WizardDraft::where('user_id', ownerId())->count())->toBe(1)
        ->and(WizardDraft::find($first)->payload['title'])->toBe('second')
        ->and(WizardDraft::find($first)->title)->toBe('New Business Permit');
});

it('gives two visits two drafts', function () {
    beginDraft('visit-a')->assertCreated();
    beginDraft('visit-b')->assertCreated();

    expect(WizardDraft::where('user_id', ownerId())->pluck('title')->sort()->values()->all())
        ->toBe(['New Business Permit', 'New Business Permit (2)']);
});

it('begins a new row when the visit’s draft has been thrown away', function () {
    $first = beginDraft('visit-a')->assertCreated()->json('data.id');
    test()->withHeaders(authAs('owner@biztrack.local'))
        ->deleteJson("/api/v1/wizard-drafts/{$first}")
        ->assertNoContent();

    $next = beginDraft('visit-a')->assertCreated()->json('data.id');

    expect($next)->not->toBe($first)
        ->and(WizardDraft::where('user_id', ownerId())->count())->toBe(1);
});

it('still creates a row per request when no visit is named', function () {
    beginDraft(null)->assertCreated();
    beginDraft(null)->assertCreated();

    expect(WizardDraft::where('user_id', ownerId())->count())->toBe(2);
});

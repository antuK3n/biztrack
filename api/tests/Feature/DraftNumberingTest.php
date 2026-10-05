<?php

use App\Models\User;
use App\Models\WizardDraft;

/*
 * Client, 5 October 2026: "The numbering in the names is kinda off" — and,
 * asked how to number, "Keep numbers, count only what you can see." The
 * number is chosen against the drafts on the Drafts page; a freed number is
 * reused; the wizard saving its base name back keeps the draft's own number.
 */
function draftAs(string $title): int
{
    return test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson('/api/v1/wizard-drafts', ['application_type' => 'new', 'title' => $title, 'payload' => ['v' => 1]])
        ->assertCreated()
        ->json('data.id');
}

beforeEach(function () {
    WizardDraft::where('user_id', User::where('email', 'owner@biztrack.local')->value('id'))->delete();
});

it('numbers a repeat from (2) and reuses a number freed by a deletion', function () {
    $first = draftAs('QA Shop');
    $second = draftAs('QA Shop');
    $third = draftAs('QA Shop');
    expect(WizardDraft::find($second)->title)->toBe('QA Shop (2)')
        ->and(WizardDraft::find($third)->title)->toBe('QA Shop (3)');

    WizardDraft::find($second)->delete();
    $fourth = draftAs('QA Shop');
    expect(WizardDraft::find($fourth)->title)->toBe('QA Shop (2)')
        ->and(WizardDraft::find($first)->title)->toBe('QA Shop');
});

it('keeps a draft’s own number when the wizard saves the base name back', function () {
    draftAs('QA Shop');
    $second = draftAs('QA Shop');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/wizard-drafts/{$second}", ['title' => 'QA Shop', 'payload' => ['v' => 1]])
        ->assertOk();

    expect(WizardDraft::find($second)->title)->toBe('QA Shop (2)');
});

it('numbers a rename into a name another draft already holds', function () {
    draftAs('QA Shop');
    $other = draftAs('QA Bakery');

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->putJson("/api/v1/wizard-drafts/{$other}", ['title' => 'QA Shop'])
        ->assertOk();

    expect(WizardDraft::find($other)->title)->toBe('QA Shop (2)');
});

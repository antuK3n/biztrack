<?php

use App\Models\WizardDraft;

/*
 * A new draft is given a name nothing else is using.
 *
 * ── What this is for ────────────────────────────────────────────────────────
 *
 * The Drafts page counted repeats as it drew them, so the number lived on one
 * screen and nowhere else: the wizard's title box said "New Business Permit"
 * while the card beside it said "New Business Permit (8)", and neither the
 * applicant nor the office could say which was the filing's real name
 * (client, 2 October 2026).
 *
 * The number is part of the name now, chosen here because only the server can
 * choose it — a browser would have to read every sibling first, and two tabs
 * opened together would still both decide "(8)" was free.
 *
 * The convention is Google Drive's, which the client named: the first keeps
 * the bare name, the next is (2), and a deletion leaves a gap rather than
 * renaming a draft somebody is looking at.
 */

function postDraft(array $headers, ?string $title): array
{
    return test()->withHeaders($headers)
        ->postJson('/api/v1/wizard-drafts', [
            'application_type' => 'new',
            'title' => $title,
            'payload' => ['v' => 1, 'form' => []],
        ])
        ->assertCreated()
        ->json('data');
}

it('leaves the first of a name alone', function () {
    /*
     * A person with one draft must never see a number. Numbering from (1)
     * would put a count on every title in the system to serve the minority
     * that repeat.
     */
    $owner = authAs('owner@biztrack.local');

    expect(postDraft($owner, 'New Business Permit')['title'])
        ->toBe('New Business Permit');
});

it('numbers the repeats from two, in order', function () {
    $owner = authAs('owner@biztrack.local');

    $titles = collect(range(1, 3))
        ->map(fn () => postDraft($owner, 'New Business Permit')['title'])
        ->all();

    expect($titles)->toBe([
        'New Business Permit',
        'New Business Permit (2)',
        'New Business Permit (3)',
    ]);
});

it('leaves a gap when one is deleted, rather than renaming the others', function () {
    /*
     * The accepted cost of storing the number, and the reason it is accepted:
     * closing the gap would rename a draft the applicant has been calling (3)
     * while they are looking at it. Every file manager behaves this way.
     */
    $owner = authAs('owner@biztrack.local');

    postDraft($owner, 'Renewal');
    $second = postDraft($owner, 'Renewal');
    $third = postDraft($owner, 'Renewal');

    expect($third['title'])->toBe('Renewal (3)');

    test()->withHeaders($owner)
        ->deleteJson("/api/v1/wizard-drafts/{$second['id']}")
        ->assertNoContent();

    /* The survivor keeps its name ... */
    expect(WizardDraft::find($third['id'])->title)->toBe('Renewal (3)');

    /* ... and the freed number is offered to the next one. */
    expect(postDraft($owner, 'Renewal')['title'])->toBe('Renewal (2)');
});

it('counts only this owner’s drafts', function () {
    /*
     * Two businesses may each hold a draft called "New Business Permit" and
     * neither is a repeat of the other. Scoping it wrongly would number one
     * applicant's filings after a stranger's.
     */
    /*
     * `authAs` switches the acting user GLOBALLY and returns no headers, so
     * it is called immediately before each post. Resolving both up front
     * leaves the second account acting for both, and the test then proves
     * nothing about scoping — which is how the first draft of it failed.
     */
    $owner = authAs('owner@biztrack.local');
    postDraft($owner, 'New Business Permit');

    /* Another APPLICANT: an office account cannot hold a wizard draft. */
    $other = authAs('juan@biztrack.local');

    expect(postDraft($other, 'New Business Permit')['title'])
        ->toBe('New Business Permit');
});

it('does not invent a name for a draft that was given none', function () {
    /*
     * An absent title is not a clash to resolve. The row keeps its null and
     * the card falls back to its own wording — writing "(2)" onto nothing
     * would manufacture a name the applicant never chose.
     */
    $owner = authAs('owner@biztrack.local');

    postDraft($owner, null);

    expect(postDraft($owner, null)['title'])->toBeNull();
});

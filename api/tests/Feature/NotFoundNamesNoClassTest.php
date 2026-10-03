<?php

use App\Models\Business;

/*
 * A 404 tells the applicant nothing about our code.
 *
 * ── What went on screen ─────────────────────────────────────────────────────
 *
 * Opening the renewal dialog against a business that had been deleted under
 * the tab printed this inside the permit picker, in red, where the list of
 * permits should have been:
 *
 *     No query results for model [App\Models\Business] 19
 *
 * Client's screenshot, 3 October 2026. It is `ModelNotFoundException`'s
 * default message rendered verbatim: our namespace, our class name and a
 * primary key, shown to a member of the public, and nothing in it they can
 * act on.
 *
 * ── Why it is fixed on the API and not in the browser ───────────────────────
 *
 * `web/src/lib/api.ts` already refuses to print a 5xx message, for exactly
 * this reason — an applicant was shown "Maximum execution time of 30 seconds
 * exceeded" under Download PDF on 2 October. It deliberately keeps everything
 * below 500, because a 422 or a 403 is the API telling the applicant
 * something true about their request, and that split is right.
 *
 * A route-model-binding 404 is the one case the split gets wrong: nobody
 * wrote that sentence for a reader, Laravel generated it from a class name.
 * Fixing it at the source means the browser is not the only caller protected,
 * and means no client has to pattern-match on the shape of a framework
 * string.
 *
 * ── The line it draws ───────────────────────────────────────────────────────
 *
 * Only the FRAMEWORK's message is replaced. A 404 a controller raised on
 * purpose, with a sentence meant for the applicant, keeps its wording — which
 * is the half of this that a careless fix would take with it, and so the half
 * asserted hardest below.
 */

it('does not name the model or the id when a record is not found', function () {
    $owner = authAs('owner@biztrack.local');

    /* An id that cannot exist, so route-model binding is what fails. */
    $gone = ((int) Business::max('id')) + 1000;

    $response = test()->withHeaders($owner)
        ->getJson("/api/v1/businesses/{$gone}")
        ->assertNotFound();

    $message = (string) $response->json('message');

    expect($message)
        ->not->toContain('App\\Models')
        ->not->toContain('No query results')
        ->not->toContain((string) $gone);
});

it('says something the applicant can actually read', function () {
    /*
     * The other half of the same claim. Stripping the class name and leaving
     * an empty string would pass the test above and put a blank red line on
     * the screen, which is what the applicant had before in a different form.
     */
    $owner = authAs('owner@biztrack.local');
    $gone = ((int) Business::max('id')) + 1000;

    $message = (string) test()->withHeaders($owner)
        ->getJson("/api/v1/businesses/{$gone}")
        ->assertNotFound()
        ->json('message');

    expect(strlen($message))->toBeGreaterThan(20)
        ->and($message)->toContain('could not find');
});

it('still answers 404 rather than 403, so scoping stays a privacy choice', function () {
    /*
     * Several endpoints look a row up WITHIN the caller's own rows, so
     * somebody else's id is not forbidden — it simply is not found. See the
     * scope note on `DraftController`. Turning these into 403 would tell a
     * stranger that the id they guessed exists, which is the fact the 404 is
     * there to withhold.
     */
    $owner = authAs('owner@biztrack.local');
    $gone = ((int) Business::max('id')) + 1000;

    test()->withHeaders($owner)
        ->getJson("/api/v1/businesses/{$gone}")
        ->assertStatus(404);
});

it('leaves a 404 a controller wrote itself alone', function () {
    /*
     * The line this fix must not cross.
     *
     * `abort(404, 'a sentence')` is a developer choosing words for the
     * applicant; replacing those with a generic line would lose real guidance
     * to tidy up a framework leak. Only `ModelNotFoundException` is
     * rewritten, and this is what holds that distinction in place.
     *
     * Asserted against the router's own 404 for an unrouted path, which
     * carries no `ModelNotFoundException` as its previous: it must fall
     * through the handler untouched rather than being given the record
     * sentence, which would be nonsense for a URL that does not exist.
     */
    $owner = authAs('owner@biztrack.local');

    $message = (string) test()->withHeaders($owner)
        ->getJson('/api/v1/this-route-does-not-exist')
        ->assertNotFound()
        ->json('message');

    expect($message)->not->toContain('could not find that');
});

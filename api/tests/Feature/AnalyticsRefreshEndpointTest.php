<?php

use App\Models\AnalyticsSnapshot;
use Illuminate\Testing\TestResponse;

/*
 * The manual refresh endpoint behind the "Refresh now" button.
 *
 * ── WHAT THIS TEST USED TO GUARD, AND WHAT IS LEFT OF IT ────────────────────
 *
 * It used to insist that three failure modes stayed distinguishable, because a
 * refresh went out over HTTP to an R (plumber) service on another port: "R is
 * switched off" (409), "R did not answer" (503) and "R answered and computed
 * nothing" (502). A caller that could not tell them apart could not report the
 * fault honestly, and the fix differed in each case.
 *
 * R has been removed. Two of those three cannot happen any more — there is no
 * service to be unreachable and no flag that can switch the statistics off — so
 * the 409 and 503 cases are gone with them, and so is the Http::fake() scaffolding
 * that stood in for the service. The endpoint now computes in-process.
 *
 * What survives unchanged is the permission contract, which never had anything to
 * do with R, and which is the part of this file that was actually protecting a
 * user. Added in its place: that a refresh is safe to run twice, which used to be
 * implicit in "R recomputes everything" and is now a property of this codebase's
 * own write path.
 */

function refreshAs(string $email): TestResponse
{
    return test()->withHeaders(authAs($email))->postJson('/api/v1/analytics/refresh');
}

it('refuses the refresh to anyone without analytics.view', function () {
    // Not a read: it recomputes and overwrites every stored figure set, so it
    // sits on the same permission as the figures it rewrites. BPLO holds that
    // permission (checklist item 78) and so is not asserted here — the "Refresh
    // now" button sits on the screens it was given, and a button that 403s would
    // be worse than no button.
    refreshAs('sanitary@biztrack.local')->assertForbidden();
    refreshAs('owner@biztrack.local')->assertForbidden();

    /*
     * The super admin is refused too, which is the one that needs saying. It
     * holds `analytics.processing_time` only, and refresh recomputes every
     * dataset — dashboard, renewal risk and business growth among them. Letting
     * it through would mean the office that cannot read three of these screens
     * can still make the app rewrite their snapshots. Processing Time is
     * refreshed by the nightly run like everything else; nobody loses a figure
     * over this.
     */
    refreshAs('admin@biztrack.local')->assertForbidden();
});

it('refuses the refresh to a caller with no session', function () {
    // Its own case rather than a third assertion above: reusing the same test
    // instance after authenticated calls carries the previous token, so the
    // unauthenticated path has to be exercised on a clean request.
    test()->postJson('/api/v1/analytics/refresh')->assertUnauthorized();
});

it('recomputes the snapshots and reports what it did', function () {
    $response = refreshAs('bplo@biztrack.local')->assertOk();

    expect($response->json('data.refreshed'))->toBeGreaterThan(0);
    expect($response->json('data.failed'))->toBe(0);

    /*
     * Kept on the response and kept constant. The engine is no longer a variable
     * — there is one implementation and it ships with the code that reads it —
     * but the client reads this shape, and the client asked for what used to
     * read "R 4.6.1" to read "BizTrack" rather than to vanish.
     */
    expect($response->json('data.engine'))->toBe('BizTrack');
    expect($response->json('data.engine_version'))->toBeNull();

    expect(AnalyticsSnapshot::count())->toBeGreaterThan(0);
    expect(AnalyticsSnapshot::where('source', 'local')->count())
        ->toBe(AnalyticsSnapshot::count());
});

it('is safe to run twice, overwriting rather than accumulating', function () {
    /*
     * The nightly schedule and the button hit the same code, so a refresh that
     * appended instead of replacing would grow the table without bound and leave
     * a read to guess which row was current. Snapshots are written by key for
     * exactly that reason. Nothing is ever deleted, either — a dataset that fails
     * keeps whatever it had.
     */
    refreshAs('bplo@biztrack.local')->assertOk();

    $keys = AnalyticsSnapshot::orderBy('key')->pluck('key')->all();
    $count = count($keys);
    expect($count)->toBeGreaterThan(0);

    refreshAs('bplo@biztrack.local')->assertOk();

    expect(AnalyticsSnapshot::count())->toBe($count);
    expect(AnalyticsSnapshot::orderBy('key')->pluck('key')->all())->toBe($keys);
});

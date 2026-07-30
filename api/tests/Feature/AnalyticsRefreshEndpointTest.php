<?php

use App\Models\AnalyticsSnapshot;
use App\Services\RAnalytics;
use Illuminate\Support\Facades\Http;

/*
 * The manual refresh endpoint behind the "Refresh now" button.
 *
 * What matters here is not the happy path — it is that the three failure modes
 * stay distinguishable. The screens fall back to locally computed figures when a
 * refresh fails, and a caller that cannot tell "R is off" from "R did not answer"
 * from "R answered and computed nothing" has no way to report that honestly.
 */

function refreshAs(string $email): \Illuminate\Testing\TestResponse
{
    return test()->withHeaders(authAs($email))->postJson('/api/v1/analytics/refresh');
}

it('refuses the refresh to anyone without analytics.view', function () {
    // Not a read: it pushes the whole register to an external service, so it sits
    // on the same super-admin permission as the figures it recomputes.
    refreshAs('bplo@biztrack.local')->assertForbidden();
    refreshAs('owner@biztrack.local')->assertForbidden();
});

it('refuses the refresh to a caller with no session', function () {
    // Its own case rather than a third assertion above: reusing the same test
    // instance after authenticated calls carries the previous token, so the
    // unauthenticated path has to be exercised on a clean request.
    test()->postJson('/api/v1/analytics/refresh')->assertUnauthorized();
});

it('recomputes snapshots from R and reports what it did', function () {
    Http::fake([
        '*/health' => Http::response(['status' => 'ok', 'r_version' => '4.6.1']),
        '*' => Http::response(['generated_at' => now()->toISOString()]),
    ]);

    $response = refreshAs('admin@biztrack.local')->assertOk();

    expect($response->json('data.refreshed'))->toBeGreaterThan(0);
    expect($response->json('data.failed'))->toBe(0);
    expect($response->json('data.engine_version'))->toBe('4.6.1');
    expect($response->json('data.message'))->toContain('R 4.6.1');

    // Persisted, and attributed to R rather than to the local engine.
    expect(AnalyticsSnapshot::where('source', 'r')->count())->toBeGreaterThan(0);
});

it('reports the service being unreachable without touching existing snapshots', function () {
    // A snapshot already on record must survive a failed refresh: a figure that
    // says how old it is beats no figure at all.
    /*
     * One stub with a flag rather than two Http::fake() calls. Repeated fakes
     * append stubs and the first match wins, so a later '*' => 500 never gets
     * reached — the earlier success stub keeps answering /health and the refresh
     * reports a healthy service. That cost a confused debugging pass.
     */
    $down = false;
    Http::fake(function () use (&$down) {
        return $down
            ? Http::response('', 500)
            : Http::response(['status' => 'ok', 'r_version' => '4.6.1', 'generated_at' => now()->toISOString()]);
    });

    refreshAs('admin@biztrack.local')->assertOk();
    $before = AnalyticsSnapshot::count();
    expect($before)->toBeGreaterThan(0);

    $down = true;

    refreshAs('admin@biztrack.local')
        ->assertStatus(503)
        ->assertJsonPath('refreshed', 0)
        ->assertJsonFragment(['message' => 'The R statistics service did not answer. The screens keep serving the last figures and say how old they are.']);

    expect(AnalyticsSnapshot::count())->toBe($before);
});

it('says so when R is switched off rather than reporting a successful refresh', function () {
    config()->set('analytics.r.enabled', false);
    app()->forgetInstance(RAnalytics::class);

    refreshAs('admin@biztrack.local')
        ->assertStatus(409)
        ->assertJsonPath('refreshed', 0);
});

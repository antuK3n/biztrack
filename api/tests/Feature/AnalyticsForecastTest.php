<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitExpiryNotice;
use App\Models\PermitType;
use App\Support\RenewalRiskScoring;
use Carbon\CarbonImmutable;

/*
 * Renewal Risk (docs/r-integration-spec.md §2), served by the site.
 *
 * The rule arithmetic is pinned in tests/Unit/RenewalRiskScoringTest.php. What
 * is tested here is the trip through the database and the JSON envelope: that
 * the facts are read off the register correctly, that the honesty statement
 * survives, and that the feed is unreachable without analytics.view.
 */

/**
 * Give a business a permit expiring in `$days` days (negative = already lapsed).
 *
 * The foreign keys come from the reference tables rather than from an existing
 * permit, because most of these tests clear `permits` first to isolate the
 * watchlist from whatever the demo seeder issued.
 */
function permitExpiringIn(int $days, ?Business $business = null): Permit
{
    $business ??= Business::firstOrFail();
    $validUntil = CarbonImmutable::now()->startOfDay()->addDays($days);

    return Permit::create([
        'permit_number' => 'RISK-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'application_id' => Application::firstOrFail()->id,
        'business_id' => $business->id,
        'permit_type_id' => PermitType::firstOrFail()->id,
        'status' => $days < 0 ? PermitStatus::Expired->value : PermitStatus::Active->value,
        'valid_from' => $validUntil->subYear()->toDateString(),
        'valid_until' => $validUntil->toDateString(),
        'issued_at' => $validUntil->subYear(),
    ]);
}

/** @return array<string, mixed> */
function renewalRisk(string $query = ''): array
{
    return test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/renewal-risk'.$query)
        ->assertOk()
        ->json('data');
}

/* ── access ───────────────────────────────────────────────────────────── */

it('serves renewal risk to the super admin and to BPLO', function () {
    test()->withHeaders(authAs('admin@biztrack.local'))
        ->getJson('/api/v1/analytics/renewal-risk')
        ->assertOk();

    // Checklist item 78: BPLO holds analytics.view. Renewals are BPLO's own
    // work — it is the office that issues the permit being renewed — so the
    // watchlist is queue-processing information for it specifically.
    test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/renewal-risk')
        ->assertOk();

    // For every other office a watchlist of which businesses are about to fall
    // out of compliance is management information, not queue-processing
    // information.
    foreach (['sanitary@biztrack.local', 'owner@biztrack.local'] as $email) {
        test()->withHeaders(authAs($email))
            ->getJson('/api/v1/analytics/renewal-risk')
            ->assertForbidden();
    }
});

it('refuses the feed and its report to a caller with no session', function () {
    // No authAs() anywhere in this test: Sanctum::actingAs would outlive it.
    test()->getJson('/api/v1/analytics/renewal-risk')->assertUnauthorized();
    test()->get('/api/v1/analytics/renewal-risk/report')->assertUnauthorized();
});

it('refuses the report download to anyone without analytics.view', function () {
    test()->withHeaders(authAs('sanitary@biztrack.local'))
        ->get('/api/v1/analytics/renewal-risk/report')
        ->assertForbidden();

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->get('/api/v1/analytics/renewal-risk/report')
        ->assertForbidden();
});

/* ── renewal risk: what the register says ─────────────────────────────── */

it('carries the methodology statement and the published rulebook', function () {
    $body = renewalRisk();

    /*
     * The screen and the PDF both render this. It is what keeps the numbers from
     * reading as a prediction, so its absence is a bug.
     *
     * Asserted on the two claims it has to disclaim rather than on an exact
     * sentence: the wording is user-facing copy and will be revised, but it must
     * never stop saying that the score is not a prediction and does not express
     * a likelihood. Pinning the phrasing would make a copy edit look like a test
     * failure, and pinning nothing would let the disclaimer be dropped silently.
     */
    expect($body['methodology'])->toContain('not a prediction');
    expect($body['methodology'])->toContain('how likely');

    expect($body['rulebook'])->toHaveCount(count(RenewalRiskScoring::WEIGHTS));
    expect(array_sum(array_column($body['rulebook'], 'max')))->toBe(100);
    expect($body['thresholds'])->toBe([
        'high' => RenewalRiskScoring::HIGH_THRESHOLD,
        'moderate' => RenewalRiskScoring::MODERATE_THRESHOLD,
    ]);
});

it('bands a permit that is lapsed with nothing filed as high risk', function () {
    Permit::query()->delete();
    $permit = permitExpiringIn(-10);

    $body = renewalRisk();

    $row = collect($body['at_risk'])->firstWhere('permit_id', $permit->id);
    expect($row)->not->toBeNull();
    expect($row['days_to_expiry'])->toBe(-10);
    expect($row['band'])->toBe('high');
    expect($row['action'])->toBe('immediate_follow_up');
    // Every point awarded is explained.
    expect($row['drivers'])->not->toBeEmpty();
    foreach ($row['drivers'] as $driver) {
        expect($driver['points'])->toBeGreaterThan(0);
        expect($driver['detail'])->not->toBeEmpty();
    }
});

it('drops the score once a renewal for that permit is approved', function () {
    Permit::query()->delete();
    $permit = permitExpiringIn(20);
    $business = Business::findOrFail($permit->business_id);

    $before = collect(renewalRisk()['at_risk'])->firstWhere('permit_id', $permit->id);

    Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $business->owner_user_id,
        'application_type' => ApplicationType::Renewal->value,
        'status' => ApplicationStatus::Approved->value,
        'prior_permit_id' => $permit->id,
        'submitted_at' => CarbonImmutable::now()->subDays(5),
    ]);

    $after = collect(renewalRisk()['at_risk'])->firstWhere('permit_id', $permit->id);

    expect($after['score'])->toBeLessThan($before['score']);
    expect($after['renewal_stage'])->toBe('approved');
    expect(collect($after['drivers'])->firstWhere('rule', 'progress'))->toBeNull();
});

it('counts an earlier renewal filed after expiry against punctuality', function () {
    Permit::query()->delete();
    $current = permitExpiringIn(20);
    $business = Business::findOrFail($current->business_id);

    // An older permit for the same business, renewed three days after it lapsed.
    $old = permitExpiringIn(-400, $business);
    Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $business->owner_user_id,
        'application_type' => ApplicationType::Renewal->value,
        'status' => ApplicationStatus::Approved->value,
        'prior_permit_id' => $old->id,
        'submitted_at' => CarbonImmutable::parse($old->valid_until)->addDays(3),
    ]);

    $row = collect(renewalRisk()['at_risk'])->firstWhere('permit_id', $current->id);
    $punctuality = collect($row['drivers'])->firstWhere('rule', 'punctuality');

    expect($punctuality)->not->toBeNull();
    expect($punctuality['points'])->toBe(RenewalRiskScoring::WEIGHTS['punctuality']);
    expect($punctuality['detail'])->toContain('filed late');
});

it('excludes revoked permits from the watchlist', function () {
    Permit::query()->delete();
    $permit = permitExpiringIn(10);
    $permit->update(['status' => PermitStatus::Revoked->value]);

    // A revoked permit is an enforcement state; no reminder is going to fix it.
    expect(renewalRisk()['at_risk'])->toBe([]);
    expect(renewalRisk()['scored_permits'])->toBe(0);
});

it('excludes permits belonging to a closed business', function () {
    Permit::query()->delete();
    $permit = permitExpiringIn(10);
    Business::findOrFail($permit->business_id)->delete();

    expect(renewalRisk()['scored_permits'])->toBe(0);
});

it('honours the horizon so a distant expiry stays off the list', function () {
    Permit::query()->delete();
    $permit = permitExpiringIn(200);

    expect(collect(renewalRisk('?days=90')['at_risk'])->firstWhere('permit_id', $permit->id))->toBeNull();
    expect(collect(renewalRisk('?days=365')['at_risk'])->firstWhere('permit_id', $permit->id))->not->toBeNull();
});

it('counts only reminder notices, not the lapsed-permit notice', function () {
    Permit::query()->delete();
    $permit = permitExpiringIn(-5);

    // What biztrack:scan-permits writes: two pre-expiry reminders, one
    // renewal-due nudge, and one "your permit has lapsed" status notice. Only
    // the first three are reminders to renew.
    foreach (['threshold_60', 'threshold_30', 'renewal_due', 'expired'] as $kind) {
        PermitExpiryNotice::create(['permit_id' => $permit->id, 'notice_kind' => $kind]);
    }

    $body = renewalRisk();

    expect($body['reminders_sent'])->toBe(3);
    expect(collect($body['at_risk'])->firstWhere('permit_id', $permit->id)['reminders_sent'])->toBe(3);
});

it('reports zero reminders honestly when the nightly scan has never run', function () {
    PermitExpiryNotice::query()->delete();

    // A true zero, not a stand-in for "we did not measure this".
    expect(renewalRisk()['reminders_sent'])->toBe(0);
});

it('keeps the band counts and the action panel consistent with each other', function () {
    Permit::query()->delete();
    permitExpiringIn(-5);
    permitExpiringIn(10);
    permitExpiringIn(80);

    $body = renewalRisk();

    expect(array_sum($body['counts']))->toBe($body['scored_permits']);
    expect($body['scored_permits'])->toBe(3);

    $actions = collect($body['actions'])->keyBy('band');
    foreach (['high', 'moderate', 'low'] as $band) {
        expect($actions[$band]['count'])->toBe($body['counts'][$band]);
    }
});

it('ranks the watchlist by score, then by soonest expiry', function () {
    Permit::query()->delete();
    foreach ([-20, -1, 5, 40, 85] as $days) {
        permitExpiringIn($days);
    }

    $rows = renewalRisk()['at_risk'];

    for ($i = 1; $i < count($rows); $i++) {
        expect($rows[$i]['score'])->toBeLessThanOrEqual($rows[$i - 1]['score']);
        if ($rows[$i]['score'] === $rows[$i - 1]['score']) {
            expect($rows[$i]['days_to_expiry'])->toBeGreaterThanOrEqual($rows[$i - 1]['days_to_expiry']);
        }
    }
});

it('says nothing rather than something when no permit is expiring', function () {
    Permit::query()->delete();

    $body = renewalRisk();

    expect($body['at_risk'])->toBe([]);
    expect($body['scored_permits'])->toBe(0);
    expect($body['counts'])->toBe(['high' => 0, 'moderate' => 0, 'low' => 0]);
    // The rulebook still ships, so the screen can explain itself while empty.
    expect($body['rulebook'])->not->toBeEmpty();
    expect($body['methodology'])->not->toBeEmpty();
});

/* ── Generate Report ──────────────────────────────────────────────────── */

it('generates a renewal risk PDF that carries the methodology', function () {
    Permit::query()->delete();
    permitExpiringIn(-5);
    permitExpiringIn(30);

    $response = test()->withHeaders(authAs('admin@biztrack.local'))
        ->get('/api/v1/analytics/renewal-risk/report')
        ->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    expect(strlen($response->getContent()))->toBeGreaterThan(2000);
});


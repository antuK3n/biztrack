<?php

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Support\RenewalRiskAnalytics;
use App\Support\RenewalRiskScoring;
use Carbon\CarbonImmutable;

/*
 * The Permit Lifecycle panel: "Permits Approaching Expiry" after the client
 * moved it off the Analytics Dashboard and replaced its 30 / 60 / 90 day column
 * with four named states.
 *
 * THE AXIS CHANGED, and that is what most of this file is about. The old bands
 * were three cumulative time windows and a permit sat in all of the ones it fit.
 * The new states mix time with the STATE of the paperwork — "Pending Renewal" is
 * about whether a submitted renewal exists, not about the date — and they must
 * partition the watchlist. So the properties worth paying for here are:
 *
 *  - every permit lands in exactly ONE state, and the four totals add up to the
 *    permits the panel beside them says were scored. A panel whose counts do not
 *    reconcile with the panel above it teaches an officer to trust neither.
 *  - the precedence is the stated one. Overdue beats a renewal in the queue, a
 *    submitted renewal beats the date, and an approved renewal is never "no
 *    renewal filed yet" however close the expiry is.
 *  - the thirty-day mark is the SAME thirty days the score's progress rule uses.
 *    Two numbers that happen to agree today are two numbers that disagree later.
 *
 * Read as BPLO throughout: `analytics.view` is BPLO's and the super admin does
 * not hold it.
 */

/**
 * Give a business a permit expiring in `$days` days (negative = already lapsed).
 *
 * Named apart from riskPermit()/permitExpiringIn() in the neighbouring files on
 * purpose — Pest shares helper functions across the whole suite, so a duplicate
 * name is a fatal error the day the second file loads.
 */
function lifecyclePermit(int $days, ?PermitType $type = null): Permit
{
    static $serial = 0;

    $business = Business::whereNotNull('owner_user_id')->firstOrFail();
    $validUntil = CarbonImmutable::now()->startOfDay()->addDays($days);

    return Permit::create([
        // Counted rather than randomised: permit_number is unique and this
        // helper is called six times in one test.
        'permit_number' => 'LCY-'.str_pad((string) ++$serial, 6, '0', STR_PAD_LEFT),
        'application_id' => Application::firstOrFail()->id,
        'business_id' => $business->id,
        'permit_type_id' => ($type ?? PermitType::firstOrFail())->id,
        'status' => $days < 0 ? PermitStatus::Expired->value : PermitStatus::Active->value,
        'valid_from' => $validUntil->subYear()->toDateString(),
        'valid_until' => $validUntil->toDateString(),
        'issued_at' => $validUntil->subYear(),
    ]);
}

/** File a renewal against `$permit` and leave it at `$status`. */
function lifecycleRenewal(Permit $permit, ApplicationStatus $status): Application
{
    $business = Business::findOrFail($permit->business_id);

    return Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $business->owner_user_id,
        'application_type' => ApplicationType::Renewal->value,
        'status' => $status->value,
        'prior_permit_id' => $permit->id,
        // A draft is the one filing with no submitted_at, which is exactly the
        // distinction "Pending Renewal" turns on.
        'submitted_at' => $status === ApplicationStatus::Draft ? null : CarbonImmutable::now()->subDays(3),
    ]);
}

/** @return array<string, mixed> */
function lifecycleFeed(string $query = ''): array
{
    return test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/renewal-risk'.$query)
        ->assertOk()
        ->json('data');
}

/** The state one permit landed in, read off the served payload's row totals. */
function lifecycleStateOf(Permit $permit): string
{
    Permit::whereKeyNot($permit->id)->delete();

    $rows = collect(lifecycleFeed()['lifecycle']['rows'])->where('total', '>', 0);

    expect($rows)->toHaveCount(1, 'One permit must produce exactly one non-empty state.');

    return $rows->first()['state'];
}

/* ── mutually exclusive and total ──────────────────────────────────────── */

it('puts every watchlisted permit in exactly one state, summing to the permits scored', function () {
    Permit::query()->delete();

    // One of each state, deliberately including the two that collide: a lapsed
    // permit WITH a renewal under review, and a far-off permit WITH one.
    lifecyclePermit(200);                                              // active
    lifecyclePermit(10);                                               // near expiry
    lifecycleRenewal(lifecyclePermit(200), ApplicationStatus::UnderReview);  // pending
    lifecycleRenewal(lifecyclePermit(10), ApplicationStatus::Submitted);     // pending
    lifecyclePermit(-5);                                               // overdue
    lifecycleRenewal(lifecyclePermit(-5), ApplicationStatus::UnderReview);   // overdue wins

    $data = lifecycleFeed();
    $lifecycle = $data['lifecycle'];

    // TOTAL: nothing on the watchlist fell through the four states.
    expect(array_sum(array_column($lifecycle['rows'], 'total')))->toBe(6);

    /*
     * And the count reconciles with the panel above it. `scored_permits` is what
     * the three risk-level cards are out of; if these two ever disagree, one of
     * the two panels is describing a different set of permits from the one the
     * table is drawn from, and there is no way for a reader to tell which.
     */
    expect($lifecycle['total'])->toBe($data['scored_permits']);
    expect(array_sum(array_column($lifecycle['rows'], 'total')))->toBe($data['scored_permits']);

    // EXCLUSIVE: the per-type columns are a second partition of the same rows,
    // so each row's columns must add up to its own total and no further.
    foreach ($lifecycle['rows'] as $row) {
        expect(array_sum(array_values($row['counts'])))->toBe(
            $row['total'],
            "The permit-type columns for [{$row['state']}] do not add up to its total.",
        );
    }

    expect(array_column($lifecycle['rows'], 'state'))
        ->toBe(['active', 'near_expiry', 'pending_renewal', 'overdue']);
});

it('keeps the four counts against the same population when a barangay is chosen', function () {
    $all = lifecycleFeed();
    $barangay = $all['barangays'][0] ?? null;

    expect($barangay)->not->toBeNull('The seeded register has no barangay on the watchlist to filter by.');

    $filtered = lifecycleFeed('?barangay='.urlencode($barangay));

    /*
     * Barangay is the POPULATION filter — applied before the counting — so the
     * lifecycle split has to narrow with it. A snapshot served by R carries no
     * filters at all, which is why the controller passes the barangay down
     * rather than reading it back off the payload; get that wrong and this panel
     * silently reports the whole city under a screen labelled with one barangay.
     */
    expect($filtered['lifecycle']['total'])->toBe($filtered['scored_permits']);
    expect($filtered['lifecycle']['total'])->toBeLessThanOrEqual($all['lifecycle']['total']);
    expect(array_sum(array_column($filtered['lifecycle']['rows'], 'total')))
        ->toBe($filtered['scored_permits']);
});

/* ── the precedence, stated one rule at a time ─────────────────────────── */

it('counts a lapsed permit as overdue even with a renewal under review', function () {
    Permit::query()->delete();
    $permit = lifecyclePermit(-5);
    lifecycleRenewal($permit, ApplicationStatus::UnderReview);

    /*
     * The decision the brief asked to be written down. The permit has lapsed:
     * the business is trading without cover TODAY, and a filing in the queue
     * does not restore it. Ranking the renewal higher would file the most
     * serious row on the register under the calmest heading.
     */
    expect(lifecycleStateOf($permit))->toBe('overdue');
});

it('counts a submitted renewal as pending whatever the expiry date', function () {
    Permit::query()->delete();

    // Two hundred days out is the case that proves the axis changed: under the
    // old 30/60/90 banding this permit was not on the table at all.
    $far = lifecyclePermit(200);
    lifecycleRenewal($far, ApplicationStatus::UnderReview);
    expect(lifecycleStateOf($far))->toBe('pending_renewal');

    Permit::query()->delete();

    $near = lifecyclePermit(3);
    lifecycleRenewal($near, ApplicationStatus::Submitted);
    expect(lifecycleStateOf($near))->toBe('pending_renewal');
});

it('treats a renewal returned for corrections as still pending', function () {
    Permit::query()->delete();
    $permit = lifecyclePermit(10);
    lifecycleRenewal($permit, ApplicationStatus::Returned);

    // Submitted, reviewed, sent back — still an open filing with no decision on
    // it. The chase is "finish what you started", not "you have not started".
    expect(lifecycleStateOf($permit))->toBe('pending_renewal');
});

it('does not treat a draft renewal as pending, because the LGU never received it', function () {
    Permit::query()->delete();
    $permit = lifecyclePermit(10);
    lifecycleRenewal($permit, ApplicationStatus::Draft);

    /*
     * The single most consequential call in this panel. A draft has no
     * `submitted_at`; nobody at the LGU has anything to decide. Counting it as
     * Pending Renewal would mark the permit as handled on the strength of a form
     * that has never arrived, and this is precisely the business that most needs
     * ringing. The scorer already reads it the same way — a draft costs 20 of
     * the 25 progress points against 25 for nothing filed at all.
     */
    expect(lifecycleStateOf($permit))->toBe('near_expiry');
});

it('never calls an approved renewal "near expiry"', function () {
    Permit::query()->delete();
    $permit = lifecyclePermit(3);
    lifecycleRenewal($permit, ApplicationStatus::Approved);

    // "Near Expiry" means running out with nothing filed. This one was filed and
    // granted: the replacement permit exists and there is nobody to chase.
    expect(lifecycleStateOf($permit))->toBe('active');
});

it('counts a rejected renewal as nothing filed', function () {
    Permit::query()->delete();
    $permit = lifecyclePermit(10);
    lifecycleRenewal($permit, ApplicationStatus::Rejected);

    // A decision was made and it was no. There is nothing pending and the
    // business has to refile — the same position as never having filed.
    expect(lifecycleStateOf($permit))->toBe('near_expiry');
});

/* ── the threshold is the score's threshold, not a second one ──────────── */

it('starts Near Expiry on the same day the score starts counting a missing renewal', function () {
    $days = RenewalRiskScoring::RENEWAL_DUE_WITHIN_DAYS;

    expect($days)->toBe(30, 'The reminder cadence is 30/15/7/1; the first mark is the band boundary.');

    Permit::query()->delete();
    expect(lifecycleStateOf(lifecyclePermit($days)))->toBe('near_expiry');

    Permit::query()->delete();
    expect(lifecycleStateOf(lifecyclePermit($days + 1)))->toBe('active');

    Permit::query()->delete();
    // Zero is expiry day itself: still in force, so not yet overdue.
    expect(lifecycleStateOf(lifecyclePermit(0)))->toBe('near_expiry');

    expect(lifecycleFeed()['lifecycle']['near_expiry_days'])->toBe($days);
});

/* ── no stage the register can produce falls through ───────────────────── */

it('resolves every renewal stage the scorer knows into one of the four states', function () {
    $states = array_column(RenewalRiskAnalytics::LIFECYCLE_STATES, 'state');

    /*
     * The stage vocabulary belongs to RenewalRiskAnalytics::stageFor() and the
     * scorer's own points table, and there is exactly one of it — the lifecycle
     * column reuses it rather than inventing a second reading of "a renewal is
     * in progress". A stage added there and forgotten here would silently fall
     * to the date-only branches, so the whole vocabulary is walked.
     */
    foreach (array_keys(RenewalRiskScoring::parameters()['progress_points']) as $stage) {
        foreach ([-1, 0, 30, 31, 400] as $days) {
            $resolved = RenewalRiskAnalytics::lifecycleState($days, (string) $stage);

            // in_array rather than toContain(): toContain reads a second
            // argument as another needle, not as a failure message.
            expect(in_array($resolved, $states, true))->toBeTrue(
                "Stage [{$stage}] at {$days} days resolves to [{$resolved}], which the panel cannot draw.",
            );
        }
    }
});

/* ── the panel moved: gone from one screen, present on the other ───────── */

it('no longer explains an expiry panel on the dashboard, and explains this one instead', function () {
    $dashboard = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/dashboard')
        ->assertOk();

    /*
     * The panel is gone from the dashboard screen, so its definition went with
     * it — a popover explaining a table nobody can see is the stalest kind.
     *
     * The payload KEY is a different matter and is still there on purpose:
     * r/R/service.R computes `expiry`, AnalyticsParityTest reads both engines'
     * key sets in both directions, and removing it from PHP alone would fail as
     * "present in R, absent from PHP". Asserted rather than merely commented, so
     * a well-meant cleanup of the dead key breaks here instead of in parity.
     */
    expect($dashboard->json('meta.definitions'))->not->toHaveKey('expiry');
    expect($dashboard->json('data'))->toHaveKey('expiry');

    $renewal = test()->withHeaders(authAs('bplo@biztrack.local'))
        ->getJson('/api/v1/analytics/renewal-risk')
        ->assertOk();

    expect($renewal->json('meta.definitions'))->toHaveKey('lifecycle');
    expect($renewal->json('meta.definitions'))->toHaveKey('lifecycle.pending_renewal');
    expect($renewal->json('data.lifecycle.rows'))->toHaveCount(4);
});

it('heads the columns with permit type codes drawn from the watchlist itself', function () {
    Permit::query()->delete();

    $types = PermitType::orderBy('id')->take(2)->get();
    expect($types)->toHaveCount(2, 'Two permit types are needed to prove the columns are not hardcoded.');

    lifecyclePermit(10, $types[0]);
    lifecyclePermit(10, $types[1]);

    $lifecycle = lifecycleFeed()['lifecycle'];

    /*
     * Only the types actually on the watchlist, so a column of zeros for a
     * permit this LGU has never issued cannot appear — and sorted by code, so
     * the columns do not reshuffle between refreshes.
     */
    $expected = [$types[0]->code, $types[1]->code];
    sort($expected);

    expect(array_column($lifecycle['columns'], 'code'))->toBe($expected);

    $nearExpiry = collect($lifecycle['rows'])->firstWhere('state', 'near_expiry');
    expect($nearExpiry['counts'][$types[0]->code])->toBe(1);
    expect($nearExpiry['counts'][$types[1]->code])->toBe(1);
});

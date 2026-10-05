<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\UnbilledPermitFee;
use App\Services\FeeCalculator;
use App\Services\WorkflowService;
use Carbon\CarbonImmutable;

/*
 * A renewal filed after the permit expired carries the ordinance's penalty.
 *
 * ── What this guards ────────────────────────────────────────────────────────
 *
 * `FeeCalculator::latePenalty()` has implemented Secs. 8A.04 and 8A.05 — 25%
 * surcharge once, 2% a month on fee-plus-surcharge, interest capped at 36
 * months — since the revenue code was transcribed, and NOTHING CALLED IT. The
 * rule was in the database, in the calculator and in a unit test, and no bill
 * BizTrack has ever issued carried a peso of it. These cases are what make
 * that wiring load-bearing.
 *
 * Four decisions of 1 October 2026 are pinned here, because each is a place
 * where a plausible alternative would quietly change what the city collects:
 *
 *  1. THE CLOCK RUNS TO FILING, NOT TO ISSUE. The office's own queue must not
 *     add 2% a month to the applicant's bill.
 *  2. AND IT FREEZES THERE. The wait until January is the city's collection
 *     scheme, not the applicant's delay.
 *  3. THE BUSINESS PERMIT IS LATE FROM 21 JANUARY — the day after the
 *     penalty-free window that follows its 31 December expiry
 *     (`RenewalSeason::penaltyFreeUntil`; Ken, 5 October 2026).
 *  4. ONE DEFAULT, ONE PENALTY. A deferred fee that already carries a frozen
 *     penalty is not surcharged again by the January bill that collects it.
 */

/** The permit a renewal will be late against, expiring on a chosen day. */
function expiredPermitFor(string $code, string $validUntil): Permit
{
    $app = Application::whereNotNull('business_id')->firstOrFail();
    $type = PermitType::where('code', $code)->firstOrFail();

    return Permit::create([
        'permit_number' => 'MCB-LATE-'.$code.'-'.uniqid(),
        'application_id' => $app->id,
        'business_id' => $app->business_id,
        'permit_type_id' => $type->id,
        'status' => 'active',
        'valid_from' => CarbonImmutable::parse($validUntil)->subYear()->toDateString(),
        'valid_until' => $validUntil,
        'issued_at' => CarbonImmutable::parse($validUntil)->subYear(),
    ]);
}

it('charges nothing when the renewal beats the expiry date', function () {
    /*
     * The ordinary case, and the one that must not regress: renewing early is
     * what the LGU wants, and `issuePermitFor` already rewards it by
     * continuing the unexpired term. A surcharge here would punish it.
     *
     * Asked of `latePenaltyFor`, NOT of `latePenalty`. The calculator applies
     * the 25%% unconditionally — it answers "price this lateness", and zero
     * months still carries the one-time surcharge. Deciding whether a filing
     * is late AT ALL is this method's job, and the first draft of this test
     * asked the wrong one of the two and failed.
     */
    $prior = expiredPermitFor('SANITARY', now()->addMonth()->toDateString());

    $app = Application::create([
        'tracking_id' => 'BIZ-EARLY-'.uniqid(),
        'business_id' => $prior->business_id,
        'applicant_user_id' => Application::findOrFail($prior->application_id)->applicant_user_id,
        'application_type' => 'renewal',
        'status' => ApplicationStatus::Draft,
        'submitted_at' => now(),
    ]);

    $method = new ReflectionMethod(WorkflowService::class, 'latePenaltyFor');
    $penalty = $method->invoke(app(WorkflowService::class), $app, $prior, 1100.0);

    expect($penalty['surcharge'])->toBe(0.0)
        ->and($penalty['interest'])->toBe(0.0)
        ->and($penalty['months_counted'])->toBe(0);
});

it('applies 25% and 2% a month exactly as the ordinance states it', function () {
    /*
     * Sec. 8A.04: 25% of the amount due, once. Sec. 8A.05: 2% per month on the
     * amount INCLUDING that surcharge — not on the bare fee, which is the
     * arithmetic slip this pins.
     *
     *   fee        1,100.00
     *   surcharge    275.00   (25%)
     *   interest      82.50   (2% x 3 months x 1,375.00)
     *   total      1,457.50
     */
    $penalty = app(FeeCalculator::class)->latePenalty(1100.0, 3);

    expect($penalty['surcharge'])->toBe(275.0)
        ->and($penalty['interest'])->toBe(82.5)
        ->and($penalty['months_counted'])->toBe(3)
        ->and($penalty['total'])->toBe(1457.5);
});

it('caps the interest at 36 months however late the filing is', function () {
    /*
     * Sec. 8A.05 caps total interest at 36 months / 72%. A business five years
     * late must not be billed 120% interest, and the cap is the kind of rule
     * that is easy to leave out and impossible to notice until someone is
     * handed the bill.
     */
    $five = app(FeeCalculator::class)->latePenalty(1000.0, 60);
    $three = app(FeeCalculator::class)->latePenalty(1000.0, 36);

    expect($five['months_counted'])->toBe(36)
        ->and($five['interest'])->toBe($three['interest'])
        ->and($five['interest'])->toBe(900.0);
});

it('freezes a late clearance renewal’s penalty onto its deferred row', function () {
    /*
     * The whole feature in one case. A sanitary permit that lapsed, renewed
     * months later, is still issued unbilled (the deferral is unchanged —
     * client: *"Yes, still defer"*) but the row it writes now carries the
     * penalty, priced at the filing date and stored.
     */
    $prior = expiredPermitFor('SANITARY', now()->subMonths(3)->toDateString());

    $app = Application::create([
        'tracking_id' => 'BIZ-LATE-'.uniqid(),
        'business_id' => $prior->business_id,
        'applicant_user_id' => Application::findOrFail($prior->application_id)->applicant_user_id,
        'application_type' => 'renewal',
        'status' => ApplicationStatus::Draft,
        'prior_permit_id' => $prior->id,
        'submitted_at' => now(),
    ]);
    $app->permitTypes()->attach($prior->permit_type_id);
    /*
     * `priorPermits()` is a many-to-many through `application_prior_permits`,
     * and `priorPermitFor` reads THAT, not the `prior_permit_id` column. The
     * first draft set only the column and the penalty came back zero — the
     * lookup found no prior permit to be late against.
     */
    $app->priorPermits()->attach($prior->id);

    /* Priced, deferred and penalised in one call. */
    app(WorkflowService::class)->assessFees($app->fresh());

    $reflect = new ReflectionMethod(WorkflowService::class, 'recordDeferredFee');
    $reflect->invoke(app(WorkflowService::class), $app->fresh(), $prior->permitType);

    $row = UnbilledPermitFee::where('application_id', $app->id)->first();

    expect($row)->not->toBeNull()
        ->and($row->months_late)->toBeGreaterThanOrEqual(3)
        ->and((float) $row->surcharge)->toBe(round((float) $row->amount * 0.25, 2))
        ->and((float) $row->interest)->toBeGreaterThan(0.0);
});

it('does not surcharge a deferred fee twice on the bill that collects it', function () {
    /*
     * Decision 4, and the one a reasonable implementation gets wrong. The
     * January bill computes its own lateness BEFORE sweeping, so a deferred
     * row arrives with the penalty it froze in June and is not surcharged
     * again for the January bill also being late. Two penalties for one
     * default is the failure; this is the case that notices it.
     */
    $business = Application::whereNotNull('business_id')->firstOrFail();

    $deferred = UnbilledPermitFee::create([
        'business_id' => $business->business_id,
        'application_id' => $business->id,
        'permit_type_id' => PermitType::where('code', 'SANITARY')->firstOrFail()->id,
        'amount' => 1000.00,
        'surcharge' => 250.00,
        'interest' => 75.00,
        'months_late' => 3,
        'incurred_at' => now()->subMonths(4),
    ]);

    $january = Application::create([
        'tracking_id' => 'BIZ-JAN-'.uniqid(),
        'business_id' => $business->business_id,
        'applicant_user_id' => $business->applicant_user_id,
        'application_type' => 'renewal',
        'status' => ApplicationStatus::Draft,
        'submitted_at' => now(),
    ]);
    $january->permitTypes()->attach(
        PermitType::where('code', PermitType::OUTCOME_CODE)->firstOrFail()->id
    );

    $assessment = app(WorkflowService::class)->assessFees($january->fresh());
    $labels = collect($assessment->line_items)->pluck('label');

    /* The frozen penalty appears once, as its own line, at its stored value. */
    $penaltyLines = collect($assessment->line_items)
        ->filter(fn ($i) => str_contains($i['label'], 'Late surcharge and interest'));

    expect($penaltyLines)->toHaveCount(1)
        ->and(round((float) $penaltyLines->first()['amount'], 2))->toBe(325.0)
        ->and($deferred->fresh()->surcharge)->toBe('250.00');

    /* And the stored figures were not rewritten by the assessment. */
    expect($labels->filter(fn ($l) => str_contains($l, 'unbilled until now')))->toHaveCount(1);
});

<?php

use App\Enums\ApplicationStatus;
use App\Enums\InspectionResult;
use App\Models\Application;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\UnbilledPermitFee;
use App\Models\User;
use App\Services\WorkflowService;

/*
 * A clearance renewed outside the season is issued now and billed in January.
 *
 * Client's rule, 17 September 2026: *"The payment for each permit will also
 * happen ONLY WHEN a business permit was renewed on January. So for example, if
 * I renew my sanitary permit, its payment will only reflect once I renew my
 * business permit for the next renewal season."*
 *
 * The example is the test. A sanitary renewal in June is approved, the permit is
 * issued, nothing is charged; the January business-permit renewal's Tax Order of
 * Payment carries that June fee.
 *
 * ── What makes this worth its own file ────────────────────────────────────
 *
 * Nothing in this system could previously issue a permit that had not been paid
 * for. The bill gated the clearance stage and `approveOverall` minted
 * certificates only on a settled filing, so "issued and unbilled" is a state
 * the register has never held — which means the sweep has no existing test to
 * lean on and every part of it is new: recording, claiming, and collecting.
 */

/** A business holding a live permit of each code, from one prior filing. */
function deferBusinessHolding(array $codes): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    $permits = [];
    foreach ($codes as $i => $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        $permits[$code] = Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $business->id,
            'permit_type_id' => $type->id,
            'permit_number' => 'DEFER-'.$code.'-'.str_pad((string) (Permit::max('id') + $i + 1), 6, '0', STR_PAD_LEFT),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays(30 + ($i * 30)),
            'status' => 'active',
        ]);
    }

    return [$business, $permits];
}

/** A submitted renewal over exactly `$codes`, against those prior permits. */
function deferRenewal(array $codes, array $permits, Business $business): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $permits[$codes[0]]->id,
        'complexity' => 'complex',
        'complexity_set_by_user_id' => $owner->id,
    ]);
    $app->priorPermits()->sync(collect($permits)->only($codes)->pluck('id')->all());
    $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id')->all());

    app(WorkflowService::class)->submit($app->fresh());

    return $app->fresh();
}

/**
 * Take a clearance-only renewal all the way to an issued permit.
 *
 * Driven through the service rather than the HTTP endpoints: the route from
 * office approval to a passed inspection is four requests per permit and is
 * OfficeFormTest's and WorkflowHappyPathTest's subject. What this file is about
 * begins the moment the certificate exists.
 */
function issueClearanceOnRenewal(Application $app, string $code): void
{
    $workflow = app(WorkflowService::class);
    $row = $workflow->pivotFor($app->fresh(), $code);

    $workflow->startClearance($app->fresh(), $row->permitType, 'apply');
    $workflow->submitClearanceForm($app->fresh(), $row->permitType);
    $workflow->approveClearance($workflow->pivotFor($app->fresh(), $code), 'Paperwork fine.');

    $visit = $workflow->scheduleClearanceInspection(
        $workflow->pivotFor($app->fresh(), $code),
        now()->addDays(3),
    );
    $workflow->recordInspection($visit, InspectionResult::Passed, 'Compliant.');
}

it('issues a clearance renewed out of season and charges nothing for it', function () {
    [$business, $permits] = deferBusinessHolding(['SANITARY']);
    $app = deferRenewal(['SANITARY'], $permits, $business);

    issueClearanceOnRenewal($app, 'SANITARY');

    // The permit is real.
    expect(Permit::where('application_id', $app->id)->count())->toBe(1);

    // And the fee is a receivable on the BUSINESS, not a bill on this filing.
    $fees = UnbilledPermitFee::where('business_id', $business->id)->get();
    expect($fees)->toHaveCount(1);
    expect($fees->first()->billed_at)->toBeNull();
    expect($fees->first()->billed_on_application_id)->toBeNull();
    expect((float) $fees->first()->amount)->toBeGreaterThan(0.0);
});

it('puts the deferred fee on the next business permit renewal’s bill', function () {
    [$business, $permits] = deferBusinessHolding(['SANITARY', 'BUSINESS']);

    // June: the sanitary permit alone.
    $june = deferRenewal(['SANITARY'], $permits, $business);
    issueClearanceOnRenewal($june, 'SANITARY');
    $deferred = (float) UnbilledPermitFee::where('business_id', $business->id)->sum('amount');
    expect($deferred)->toBeGreaterThan(0.0);

    // January: the business permit. Its assessment sweeps the June fee in.
    $january = deferRenewal(['BUSINESS'], $permits, $business);

    $assessment = $january->feeAssessment()->firstOrFail();
    $labels = collect($assessment->line_items)->pluck('label')->implode(' | ');

    expect($labels)->toContain('unbilled until now');
    expect(UnbilledPermitFee::where('billed_on_application_id', $january->id)->count())->toBe(1);

    /*
     * Claimed, not yet collected. The applicant has been SHOWN the fee; they
     * have not paid it. Keeping those apart is what stops a second renewal
     * filed before payment from sweeping the same fee again.
     */
    expect(UnbilledPermitFee::where('business_id', $business->id)->outstanding()->count())->toBe(1);
});

it('marks a deferred fee collected only when the January bill is paid', function () {
    [$business, $permits] = deferBusinessHolding(['SANITARY', 'BUSINESS']);
    $june = deferRenewal(['SANITARY'], $permits, $business);
    issueClearanceOnRenewal($june, 'SANITARY');

    $january = deferRenewal(['BUSINESS'], $permits, $business);
    $workflow = app(WorkflowService::class);
    $workflow->approveMainForm($january->fresh());
    expect($january->fresh()->status)->toBe(ApplicationStatus::PendingPayment);

    $assessment = $january->feeAssessment()->firstOrFail();
    $workflow->onPaymentCompleted(Payment::create([
        'application_id' => $january->id,
        'fee_assessment_id' => $assessment->id,
        'amount' => $assessment->total_amount,
        'method' => 'gcash',
        'status' => 'completed',
        'reference' => 'DEFER-JAN-1',
        'paid_at' => now(),
    ]));

    expect(UnbilledPermitFee::where('business_id', $business->id)->outstanding()->count())->toBe(0);
    expect(UnbilledPermitFee::where('business_id', $business->id)->first()->billed_at)->not->toBeNull();
});

it('does not defer anything on a renewal that carries the business permit', function () {
    /*
     * The January renewal is the bill. Recording a receivable for its own
     * clearances would put a fee on the very assessment that just collected it
     * — so the condition is the permit set, not the calendar.
     */
    [$business, $permits] = deferBusinessHolding(['BUSINESS', 'SANITARY']);
    $app = deferRenewal(['BUSINESS', 'SANITARY'], $permits, $business);

    /*
     * This filing IS billed, so it has to be paid before its clearance stage
     * opens — `defersPayment()` is false for it, and that is the property under
     * test from the other side. The first draft of this test skipped the
     * payment and was refused with "The other permits open once this
     * application is paid", which was the product being right.
     */
    $workflow = app(WorkflowService::class);
    $workflow->approveMainForm($app->fresh());
    $assessment = $app->feeAssessment()->firstOrFail();
    $workflow->onPaymentCompleted(Payment::create([
        'application_id' => $app->id,
        'fee_assessment_id' => $assessment->id,
        'amount' => $assessment->total_amount,
        'method' => 'gcash',
        'status' => 'completed',
        'reference' => 'DEFER-NONE-1',
        'paid_at' => now(),
    ]));

    issueClearanceOnRenewal($app, 'SANITARY');

    expect(UnbilledPermitFee::where('business_id', $business->id)->count())->toBe(0);
});

it('bills every clearance on a multi-permit renewal, and stacks what it billed', function () {
    /*
     * ── One zero-value rule used to zero the whole bill ──────────────────────
     *
     * Measured 19 September 2026, before the fix, on a Sanitary + FSIC
     * renewal: the bill came to ₱0.00 and ₱1,460 was stacked against it.
     *
     * Two faults, one cause. `assessFees`'s flat fallback was all-or-nothing
     * (`if ($items === [])`), so the single rule that matched — the FSIC Fire
     * Code fee, computing ZERO — made `$items` non-empty and suppressed the
     * fallback for the Sanitary Permit too. And `deferredAmountFor` recomputed
     * from the flat schedule rather than reading the bill, so the two numbers
     * were never obliged to agree.
     *
     * What this pins is that they agree, which is the property that matters:
     * whatever the applicant was shown is what January collects. The exact
     * figures come from the flat schedule on `permit_types` and are asserted
     * loosely on purpose — they are not the ordinance, and pinning them here
     * would turn a placeholder into a fixture nobody dares change.
     */
    // Built inline rather than through another file's helper, so this test
    // runs on its own as well as inside the suite.
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);
    foreach (['SANITARY', 'FSIC'] as $code) {
        Permit::create([
            'application_id' => $priorApp->id,
            'business_id' => $business->id,
            'permit_type_id' => PermitType::where('code', $code)->value('id'),
            'permit_number' => 'GAP-'.$code.'-'.random_int(10000, 99999),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays(30),
            'status' => 'active',
        ]);
    }

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'data_privacy_consent' => true,
    ]);
    foreach (['SANITARY', 'FSIC'] as $code) {
        $app->priorPermits()->attach(
            Permit::where('business_id', $business->id)
                ->whereHas('permitType', fn ($q) => $q->where('code', $code))
                ->value('id')
        );
    }
    $app->update(['prior_permit_id' => $app->priorPermits()->value('permits.id')]);

    test()->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/submit")->assertOk();

    $assessment = Application::findOrFail($app->id)->feeAssessment;

    // Not ₱0: both clearances are on the bill.
    expect((float) $assessment->total_amount)->toBeGreaterThan(0.0);

    $byPermit = collect($assessment->line_items)
        ->flatMap(fn ($i) => (array) ($i['permit_codes'] ?? []))
        ->unique();

    expect($byPermit)->toContain('SANITARY')
        ->and($byPermit)->toContain('FSIC');

    // And the bill adds up to its own lines — the deferred row reads these, so
    // a total that disagreed with them would be collected wrong in January.
    expect(round(collect($assessment->line_items)->sum(fn ($i) => (float) $i['amount']), 2))
        ->toBe(round((float) $assessment->total_amount, 2));
});

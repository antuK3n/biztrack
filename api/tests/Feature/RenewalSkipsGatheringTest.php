<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationPermitType;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\RenewalSeason;
use Carbon\CarbonImmutable;

/*
 * A business permit renewal never waits for the other permits.
 *
 * ── The decision, and why it needed its own file ──────────────────────────
 *
 * Client, 17 September 2026: *"For the business permit, there should no longer
 * be Awaiting Other Permits status because the applicant may already have valid
 * other permit that he/she can submit in the Upload/Submit button for the LGU
 * Clearances section."*
 *
 * Three things have to hold together for that to be true, and each of them is a
 * separate mechanism that could regress on its own:
 *
 *  1. `onPaymentCompleted` routes a renewal to ForFinalApproval, not to
 *     AwaitingOtherPermits — which also needs `ApplicationStatus::allowedNext`
 *     to admit that edge.
 *  2. `outstandingClearances` counts an UPLOADED copy on a renewal as
 *     satisfied, so BPLO is actually allowed to approve.
 *  3. Approving issues the BUSINESS permit and does not mint a second copy of
 *     a clearance the applicant already holds.
 *
 * `PartialRenewalTest` owns "a renewal keeps the permits that were ticked".
 * This file owns what happens to such a filing after the money lands.
 */

/** A business holding a live permit of every code in `$codes`. */
function renewalBusinessHolding(array $codes): array
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    /*
     * The filing those prior permits came from. `permits.application_id` is NOT
     * NULL — every certificate the register holds was issued by some filing —
     * so a prior permit needs a prior application to have been issued by, even
     * in a fixture. Same shape as `PartialRenewalTest::businessHoldingPermits`.
     */
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
            'permit_number' => 'SKIP-'.$code.'-'.str_pad((string) (Permit::max('id') + $i + 1), 6, '0', STR_PAD_LEFT),
            'issued_at' => now()->subYear(),
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addDays(30 + ($i * 30)),
            'status' => 'active',
        ]);
    }

    return [$business, $permits];
}

/**
 * A renewal of the business permit, paid for, with the five clearances handed
 * in as uploaded copies rather than applied for.
 */
function paidBusinessRenewal(): Application
{
    $codes = ['BUSINESS', 'SANITARY', 'FSIC', 'OCCUPANCY', 'CEC', 'ZONING'];
    [$business, $permits] = renewalBusinessHolding($codes);
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'renewal',
        'status' => 'draft',
        'prior_permit_id' => $permits['BUSINESS']->id,
        'complexity' => 'complex',
        'complexity_set_by_user_id' => $owner->id,
    ]);
    $app->priorPermits()->sync(collect($permits)->pluck('id')->all());
    $app->permitTypes()->sync(PermitType::whereIn('code', $codes)->pluck('id')->all());

    $workflow = app(WorkflowService::class);
    $workflow->submit($app->fresh());

    /*
     * The five clearances arrive as UPLOADS. Set on the pivot directly rather
     * than through `submitHeld`, which also stores a file: this file is about
     * what the mode MEANS to the flow, and a multipart upload per permit would
     * make it about storage.
     */
    foreach (['SANITARY', 'FSIC', 'OCCUPANCY', 'CEC', 'ZONING'] as $code) {
        $type = PermitType::where('code', $code)->firstOrFail();
        ApplicationPermitType::where('application_id', $app->id)
            ->where('permit_type_id', $type->id)
            ->update(['mode' => ApplicationPermitType::MODE_UPLOAD]);
    }

    $workflow->approveMainForm($app->fresh());
    expect($app->fresh()->status)->toBe(ApplicationStatus::PendingPayment);

    return $app->fresh();
}

/**
 * Settle a filing's Tax Order of Payment.
 *
 * `payments.fee_assessment_id` is NOT NULL — a payment here is always AGAINST
 * an assessment and never a loose amount — so this pays the assessment
 * `assessFees` raised at submission rather than inventing a figure.
 */
function settle(Application $app, string $reference): Payment
{
    $assessment = $app->feeAssessment()->firstOrFail();

    return Payment::create([
        'application_id' => $app->id,
        'fee_assessment_id' => $assessment->id,
        'amount' => $assessment->total_amount,
        'method' => 'gcash',
        'status' => 'completed',
        'reference' => $reference,
        'paid_at' => now(),
    ]);
}

it('sends a paid business permit renewal straight to final approval', function () {
    $app = paidBusinessRenewal();

    app(WorkflowService::class)->onPaymentCompleted(settle($app, 'RENEW-SKIP-1'));

    /*
     * The whole decision, in one assertion. A new filing lands on
     * AwaitingOtherPermits here and has five permits to obtain; this one has
     * them already and goes to the only thing left, which is BPLO reading them.
     */
    expect($app->fresh()->status)->toBe(ApplicationStatus::ForFinalApproval);

    // And it never touched the gathering stage on the way — the history is the
    // record, because a status the filing passed through for a millisecond is
    // still a status an officer's queue could have caught it in.
    $visited = $app->fresh()->statusHistory()->pluck('to_status')->all();
    expect($visited)->not->toContain(ApplicationStatus::AwaitingOtherPermits->value);
});

it('lets BPLO approve a renewal whose clearances were uploaded, not applied for', function () {
    $app = paidBusinessRenewal();
    $workflow = app(WorkflowService::class);
    $workflow->onPaymentCompleted(settle($app, 'RENEW-SKIP-2'));

    /*
     * Every clearance is still `not_started` — no office was routed, no form
     * filled, no inspection booked, because on a renewal none of that happens.
     * Before `outstandingClearances` learned to read `mode`, this was exactly
     * the state in which BPLO was refused with "These permits are not approved
     * yet", naming all five, with nothing anyone could do about it.
     */
    $statuses = $app->fresh()->permitTypes
        ->filter(fn (PermitType $pt) => $pt->isRequiredClearance())
        ->map(fn (PermitType $pt) => $pt->pivot->status)
        ->unique()
        ->values();
    expect($statuses->all())->toBe([ClearanceStatus::NotStarted]);

    $workflow->approveOverall($app->fresh(), 'Copies checked.');

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

it('issues the business permit and no second copy of a clearance already held', function () {
    $app = paidBusinessRenewal();
    $workflow = app(WorkflowService::class);
    $workflow->onPaymentCompleted(settle($app, 'RENEW-SKIP-3'));
    $workflow->approveOverall($app->fresh(), 'Copies checked.');

    /*
     * ONE new certificate, and it is the business permit.
     *
     * The applicant already holds a valid sanitary permit — they uploaded it —
     * so minting a second would leave the business with two live certificates
     * of one type, which is the state `issuePermitFor`'s supersede logic exists
     * to prevent and which an inspector verifying the premises could be handed
     * either of.
     */
    $issued = Permit::where('application_id', $app->id)->get();
    expect($issued)->toHaveCount(1);
    expect($issued->first()->permitType->code)->toBe(PermitType::OUTCOME_CODE);

    /*
     * And it ends on 20 January, whatever today is — the other half of the
     * 17 September decision. `RenewalSeason` owns the date; this asserts the
     * business permit is the permit type that reads it.
     */
    expect($issued->first()->valid_until->toDateString())->toBe(
        RenewalSeason::endOfTermFor(
            CarbonImmutable::parse($issued->first()->valid_from),
        )->toDateString(),
    );
});

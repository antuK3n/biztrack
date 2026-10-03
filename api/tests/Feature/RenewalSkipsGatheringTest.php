<?php

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\RenewalSeason;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

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
 * ── The promise survived its own reasoning ───────────────────────────────
 *
 * That Upload/Submit button is gone from renewals (client, 3 October 2026:
 * *"an admin verifying an uploaded other permit will be useless if the system
 * already tells them whether they are still valid or not"*), and with it the
 * mechanism this file was written around: uploads counting as satisfied, and
 * BPLO reading them at For Final Approval.
 *
 * The OUTCOME the client asked for still holds, by a better route. A renewal
 * carries only the permits the applicant ticked, so a still-valid clearance is
 * not on the filing at all — there is nothing to upload and nothing to wait
 * for. The January filing they described renews the Mayor's Permit by itself
 * and closes the moment the money lands.
 *
 * What is NEW, and is the honest other half: a renewal that ticks a clearance
 * because it IS expiring now waits for that office to issue it. It has to —
 * nobody has approved anything yet — and the client's own correction of
 * 18 September is the authority for the distinction: a permit still valid is
 * not renewed, one that is expiring is.
 *
 * Three mechanisms, each able to regress alone:
 *
 *  1. `onPaymentCompleted` sends a renewal down the new filing's path, and
 *     straight to Approved when it carries nothing else — which also needs
 *     `ApplicationStatus::allowedNext` to admit that edge.
 *  2. The Mayor's Permit is released at payment, as it is for a new filing.
 *  3. A renewal that does carry a clearance waits for it, and no upload can
 *     stand in for the office's approval.
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
 * A renewal, paid for, carrying exactly the permits it says it carries.
 *
 * `$codes` is the whole variable under test in this file: the January filing
 * renews BUSINESS alone, and the awkward one renews a clearance alongside it.
 */
function paidBusinessRenewal(array $codes = ['BUSINESS']): Application
{
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

it('closes a renewal that carries nothing but the business permit', function () {
    /*
     * The client's 17 September filing, and the common one in January: renew
     * the Mayor's Permit, nothing else. There is no office to hear from, so
     * the money landing is the last event in its life.
     */
    $app = paidBusinessRenewal();

    app(WorkflowService::class)->onPaymentCompleted(settle($app, 'RENEW-SKIP-1'));

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);

    /*
     * And it never touched the gathering stage on the way — the history is the
     * record, because a status the filing passed through for a millisecond is
     * still a status an officer's queue could have caught it in.
     *
     * This is why `onPaymentCompleted` asks what is outstanding BEFORE it
     * announces where the filing is going, rather than parking it and tidying
     * up afterwards.
     */
    $visited = $app->fresh()->statusHistory()->pluck('to_status')->all();
    expect($visited)->not->toContain(ApplicationStatus::AwaitingOtherPermits->value)
        ->and($visited)->not->toContain(ApplicationStatus::ForFinalApproval->value);
});

it('releases the renewed business permit the moment the money lands', function () {
    /*
     * The 24 September rule — *"after payment, business permit is already
     * released"* — reaching renewals on 3 October, because they now take the
     * new filing's path out of payment. A business renewing in January holds
     * its permit from the day it pays rather than from the day BPLO gets to it.
     */
    $app = paidBusinessRenewal();
    app(WorkflowService::class)->onPaymentCompleted(settle($app, 'RENEW-SKIP-2'));

    $issued = Permit::where('application_id', $app->id)->get();
    expect($issued)->toHaveCount(1)
        ->and($issued->first()->permitType->code)->toBe(PermitType::OUTCOME_CODE);

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

it('waits for the office of a clearance the renewal actually renews', function () {
    /*
     * The honest other half, and the case the upload used to paper over.
     *
     * A renewal that TICKS the Sanitary Permit is saying it is expiring, so
     * CHO has to issue it — nobody has approved anything, and a copy of last
     * year's is not an approval. Before 3 October the uploaded mode counted as
     * satisfied and BPLO could close the filing over five clearances that no
     * office had touched; this is that hole, asserted shut.
     *
     * The client's own correction of 18 September 2026 is the authority for
     * the distinction: a permit still valid is not renewed and is not on the
     * filing; one that is expiring is renewed, and is.
     */
    $app = paidBusinessRenewal(['BUSINESS', 'SANITARY']);
    $workflow = app(WorkflowService::class);
    $workflow->onPaymentCompleted(settle($app, 'RENEW-SKIP-3'));

    expect($app->fresh()->status)->toBe(ApplicationStatus::AwaitingOtherPermits);

    /* And BPLO is refused while CHO has not issued it. */
    expect(fn () => $workflow->approveOverall($app->fresh(), 'Closing early.'))
        ->toThrow(ValidationException::class);

    /*
     * The business permit is still released, though — the wait is for the
     * clearance, not for the permit the applicant paid for.
     */
    expect(
        Permit::where('application_id', $app->id)
            ->whereRelation('permitType', 'code', PermitType::OUTCOME_CODE)
            ->exists()
    )->toBeTrue();
});

it('closes the renewal once that office has issued its permit', function () {
    /*
     * The inverse, so the test above cannot be passed by a filing that simply
     * never closes.
     */
    $app = paidBusinessRenewal(['BUSINESS', 'SANITARY']);
    $workflow = app(WorkflowService::class);
    $workflow->onPaymentCompleted(settle($app, 'RENEW-SKIP-4'));

    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();
    $app->permitTypes()->updateExistingPivot($sanitary->id, [
        'status' => ClearanceStatus::Approved->value,
    ]);

    $workflow->refreshReadiness($app->fresh()->load('permitTypes'));

    expect($app->fresh()->status)->toBe(ApplicationStatus::Approved);
});

<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\FeeAssessment;
use App\Models\Payment;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\PermitFees;

/*
 * WHEN money may be taken, which is a question this endpoint could not answer.
 *
 * The client's rule, stated twice and in these words the second time: "after
 * submission, the business owner will wait for the approval of BPLO then the
 * payment will go AFTER". The 6 September flow puts ForApproval between the
 * draft and the bill (docs/application-flow-2026-09.md).
 *
 * ── The defect these tests were written against ───────────────────────────
 *
 * `PaymentController::pay` refused exactly two things — a CLOSED filing and one
 * with NOTHING OUTSTANDING — and had no branch for a filing that had not been
 * billed yet. It did not need one while submission led straight to
 * PendingPayment, because there was then no status in between for a payment to
 * arrive in.
 *
 * ApplyWizard drove straight into the new gap: one press called
 * `applications.submit()` and then `payments.pay()`. At ForApproval the filing
 * is not closed and its balance is the whole unpaid assessment, so both
 * refusals passed and the charge went through. `onPaymentCompleted` then
 * returned early — it moves nothing unless the status is PendingPayment — so
 * the money was taken, the filing did not move, and BPLO's approval afterwards
 * dropped it into PendingPayment to ask for a bill already settled.
 *
 * Both halves are fixed: `ApplicationStatus::isBillable()` is the new lower
 * bound, and the wizard no longer pays. These tests hold the API half, because
 * that is the half that actually took the money — a UI that stops calling an
 * endpoint is a decision one edit away from being reversed.
 */

/** A filing sitting wherever the caller wants it, owned by the demo applicant. */
function filingAt(ApplicationStatus $status): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'draft',
    ]);

    if ($status === ApplicationStatus::Draft) {
        return $app->fresh();
    }

    app(WorkflowService::class)->submit($app);
    $app->refresh();

    if ($status === ApplicationStatus::ForApproval) {
        return $app;
    }

    classifyAsOfficer($app);
    app(WorkflowService::class)->approveMainForm($app->fresh());

    return $app->fresh();
}

it('refuses payment on a filing BPLO has not approved yet', function () {
    $app = filingAt(ApplicationStatus::ForApproval);
    expect($app->status)->toBe(ApplicationStatus::ForApproval);

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash']);

    $res->assertStatus(422);
    // The applicant is told what they are waiting for, not merely refused.
    expect($res->json('errors.status.0'))->toContain('BPLO has not approved');

    // No money, and — the part the old code got wrong — no movement either.
    expect(Payment::where('application_id', $app->id)->count())->toBe(0);
    expect($app->fresh()->status)->toBe(ApplicationStatus::ForApproval);
});

it('refuses payment on a draft, and raises no Tax Order of Payment doing so', function () {
    $app = filingAt(ApplicationStatus::Draft);

    $res = $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash']);

    $res->assertStatus(422);
    expect(Payment::where('application_id', $app->id)->count())->toBe(0);

    /*
     * The guard sits ABOVE `assessFees`, and this is what that ordering buys.
     * `pay()` falls back to assessing when no assessment exists, so a guard
     * placed after it would have billed a draft merely because somebody posted
     * to the endpoint — a Tax Order of Payment the LGU never issued, on a
     * filing the applicant has not submitted.
     */
    expect(FeeAssessment::where('application_id', $app->id)->count())->toBe(0);
});

it('refuses payment on a filing BPLO sent back', function () {
    $app = filingAt(ApplicationStatus::ForApproval);
    app(WorkflowService::class)->returnMainForm($app, 'Business name does not match the DTI record.');

    expect($app->fresh()->status)->toBe(ApplicationStatus::Returned);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(422);

    expect(Payment::where('application_id', $app->id)->count())->toBe(0);
});

it('takes the payment once BPLO has approved the form, and opens the other permits', function () {
    $app = filingAt(ApplicationStatus::PendingPayment);
    expect($app->status)->toBe(ApplicationStatus::PendingPayment);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(201);

    expect(Payment::where('application_id', $app->id)->count())->toBe(1);

    // The whole point of the ordering: paying is what unlocks the other five.
    $app->refresh();
    expect($app->status)->toBe(ApplicationStatus::AwaitingOtherPermits);
    expect($app->status->isPaid())->toBeTrue();
});

it('bills every required permit in the one Tax Order of Payment', function () {
    /*
     * The other half of the client's answer — "the bill will charge all
     * regardless if the applicant selects upload or apply" — and the reason the
     * running-balance copy came off four screens. `submit()` attaches the
     * required permits BEFORE it assesses, so the single bill prices all of
     * them; nothing the applicant does in the clearance stage adds to it.
     */
    $app = filingAt(ApplicationStatus::PendingPayment);

    $this->withHeaders(authAs('owner@biztrack.local'))
        ->postJson("/api/v1/applications/{$app->id}/pay", ['method' => 'gcash'])
        ->assertStatus(201);

    $balance = app(PermitFees::class)::balance($app->fresh());
    expect((float) $balance['balance_due'])->toBe(0.0);
});

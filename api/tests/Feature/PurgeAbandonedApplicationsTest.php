<?php

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Business;
use App\Models\Payment;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Support\Carbon;

/*
 * Filings the applicant stopped working on are removed; everything else stays.
 *
 * ── What this is ───────────────────────────────────────────────────────────
 *
 * The client asked, on 30 September 2026, for an abandoned filing's record to
 * be removed after a set number of days. I advised against deleting records
 * and was overruled; these cases pin the two things that make the version
 * built survivable, and they are the two a later "tidy-up" would undo.
 *
 *  1. It is a SOFT delete. The row keeps its payments, its audit trail and
 *     its officer decisions, and `restore()` brings back one removed by
 *     mistake. If a change ever makes this a hard delete, the second case
 *     below fails.
 *
 *  2. It only touches filings waiting on the APPLICANT. A filing at For
 *     Approval is waiting on BPLO, and removing it would punish a citizen for
 *     the city's backlog — on the one clock RA 11032 runs against the city.
 */

/** A filing in `$status`, last touched `$daysAgo` days ago. */
function staleFiling(ApplicationStatus $status, int $daysAgo): Application
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();
    $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

    $app = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => $status->value,
    ]);

    /*
     * Written straight to the column: `touch()` and `save()` both refresh
     * `updated_at`, which is the one field under test.
     */
    Application::withoutTimestamps(fn () => $app->forceFill([
        'updated_at' => Carbon::now()->subDays($daysAgo),
    ])->save());

    return $app->fresh();
}

it('removes a filing the applicant has not touched for the window', function () {
    $app = staleFiling(ApplicationStatus::PendingPayment, 45);

    $this->artisan('applications:purge-abandoned')->assertSuccessful();

    expect(Application::find($app->id))->toBeNull();
});

it('keeps the row, its trail and the ability to bring it back', function () {
    /*
     * The whole reason this was buildable. A removed filing leaves every list
     * and stays in the table: a dispute six months later can still be
     * answered, and a filing removed by mistake comes back.
     */
    $app = staleFiling(ApplicationStatus::PendingPayment, 45);

    $this->artisan('applications:purge-abandoned')->assertSuccessful();

    $removed = Application::withTrashed()->find($app->id);
    expect($removed)->not->toBeNull()
        ->and($removed->deleted_at)->not->toBeNull();

    $removed->restore();
    expect(Application::find($app->id))->not->toBeNull();
});

it('leaves a filing that is waiting on BPLO, however long it has sat', function () {
    /*
     * The case that makes this rule fair. An applicant who filed and waited
     * has done everything asked of them; the delay is the office's, and it is
     * measured against the city under RA 11032 rather than against them.
     */
    $app = staleFiling(ApplicationStatus::ForApproval, 400);

    $this->artisan('applications:purge-abandoned')->assertSuccessful();

    expect(Application::find($app->id))->not->toBeNull();
});

it('leaves one at the clearance stage, where the wait may be an office’s', function () {
    /*
     * `AwaitingOtherPermits` is mixed: the applicant applies for each
     * clearance and the offices then hold them for days. From the outside the
     * last five days may have been CENRO's, so the filing is not the
     * applicant's to lose.
     */
    $app = staleFiling(ApplicationStatus::Approved, 400);

    $this->artisan('applications:purge-abandoned')->assertSuccessful();

    expect(Application::find($app->id))->not->toBeNull();
});

it('leaves one whose KwikPay payment is still open, and removes one whose payment failed', function () {
    /*
     * An open order can still be paid, and the money needs a filing to land
     * on. The purge removed a Pending Payment filing while its KwikPay order
     * was open, and the owner's payment then settled onto a removed filing
     * (scenario run, expiry-and-lapse 32, system-scheduler 29).
     */
    $open = staleFiling(ApplicationStatus::PendingPayment, 45);
    $failed = staleFiling(ApplicationStatus::PendingPayment, 45);

    foreach ([[$open, PaymentStatus::Pending], [$failed, PaymentStatus::Failed]] as [$app, $status]) {
        $ref = 'PAY-PURGE-'.$app->id;
        Payment::create([
            'application_id' => $app->id,
            'fee_assessment_id' => app(WorkflowService::class)->assessFees($app)->id,
            'reference_number' => $ref,
            'amount' => 100,
            'method' => PaymentMethod::Gcash,
            'status' => $status,
            'gateway' => Payment::GATEWAY_KWIKPAY,
            'gateway_order_id' => $ref.'-ORDER',
        ]);
        Application::withoutTimestamps(fn () => $app->fresh()->forceFill([
            'updated_at' => Carbon::now()->subDays(45),
        ])->save());
    }

    $this->artisan('applications:purge-abandoned')->assertSuccessful();

    expect(Application::find($open->id))->not->toBeNull()
        ->and(Application::find($failed->id))->toBeNull();
});

it('leaves one touched inside the window', function () {
    $app = staleFiling(ApplicationStatus::PendingPayment, 5);

    $this->artisan('applications:purge-abandoned')->assertSuccessful();

    expect(Application::find($app->id))->not->toBeNull();
});

it('removes nothing on a dry run', function () {
    /*
     * The flag the first several real runs should use. A rule that removes
     * records earns being watched before it is trusted.
     */
    $app = staleFiling(ApplicationStatus::PendingPayment, 45);

    $this->artisan('applications:purge-abandoned', ['--dry-run' => true])->assertSuccessful();

    expect(Application::find($app->id))->not->toBeNull();
});

it('takes a window from the caller', function () {
    $app = staleFiling(ApplicationStatus::Returned, 10);

    $this->artisan('applications:purge-abandoned', ['--days' => 60])->assertSuccessful();
    expect(Application::find($app->id))->not->toBeNull();

    $this->artisan('applications:purge-abandoned', ['--days' => 7])->assertSuccessful();
    expect(Application::find($app->id))->toBeNull();
});

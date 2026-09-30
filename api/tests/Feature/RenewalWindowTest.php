<?php

use App\Models\Application;
use App\Models\Permit;
use App\Models\PermitType;
use App\Support\RenewalWindow;
use Carbon\CarbonImmutable;

/*
 * How early, and how late, a clearance may be renewed.
 *
 * ── Why this exists before the rule does ────────────────────────────────────
 *
 * Searched 1 October 2026: NOTHING in this codebase bounded a renewal at
 * either end. `ScanPermits::THRESHOLDS` sends reminders 30/15/7/1 days before
 * expiry — a notification schedule, not a policy — and
 * `BusinessController::renewablePermits` offers every active and expired
 * permit with no date filter. So a sanitary permit could be renewed eleven
 * months early, or resurrected years after it lapsed, and nothing objected.
 *
 * That is a default nobody chose. The LGU has not been asked yet, so both
 * bounds ship OFF and the first case below is the one that matters most: with
 * the settings at their defaults, this refuses nothing. A window switched on
 * by accident would reject filings the city currently accepts, on a number
 * we invented.
 *
 * The last case pins the exemption that is easy to lose: the business permit
 * is anchored to 20 January by `RenewalSeason` and the client ruled out a
 * filing lock on it — *"don't add a lock in our system yet for this."*
 */

/** A permit of `$code` whose term ends on `$validUntil`. */
function windowPermit(string $code, string $validUntil): Permit
{
    $type = PermitType::where('code', $code)->firstOrFail();
    $app = Application::whereNotNull('business_id')->firstOrFail();

    $permit = Permit::create([
        'permit_number' => 'MCB-WIN-'.$code.'-'.uniqid(),
        'application_id' => $app->id,
        'business_id' => $app->business_id,
        'permit_type_id' => $type->id,
        'status' => 'active',
        'valid_from' => CarbonImmutable::parse($validUntil)->subYear()->toDateString(),
        'valid_until' => $validUntil,
        'issued_at' => CarbonImmutable::parse($validUntil)->subYear(),
    ]);

    return $permit->load('permitType');
}

it('ships with no early bound and a 36-month late bound', function () {
    /*
     * The shipped defaults, asserted against config rather than against the
     * class, so an env override in a deployment cannot silently move the
     * rule the tests below are written about.
     *
     * No early bound: a 30-day one was tried and taken out the same day
     * because it refused a staggered subset renewal the system is built to
     * accept. 36 months is the Sec. 8A.05 interest cap — see
     * config/biztrack.php for both decisions and why the obvious "one lapsed
     * term" answer was rejected once it turned out renewals are inspected.
     */
    expect(config('biztrack.renewal_window.opens_days_before'))->toBeNull()
        ->and(config('biztrack.renewal_window.closes_months_after'))->toBe(36);
});

it('treats a null bound as no bound, so either can be switched off', function () {
    /*
     * The escape hatch. Either bound can be set to null and must then mean
     * no bound at all — that is how the rule is switched off in a hurry if
     * the LGU says the window is wrong, without a deploy.
     *
     * Eleven months early and three years lapsed: the two filings the absence
     * of a rule currently allows, and both must still pass.
     */
    config(['biztrack.renewal_window.opens_days_before' => null]);
    config(['biztrack.renewal_window.closes_months_after' => null]);

    $veryEarly = windowPermit('SANITARY', now()->addMonths(11)->toDateString());
    $longLapsed = windowPermit('FSIC', now()->subYears(3)->toDateString());

    expect(RenewalWindow::refusalFor($veryEarly))->toBeNull()
        ->and(RenewalWindow::refusalFor($longLapsed))->toBeNull();
});

it('refuses a renewal filed before the window opens, and names the date', function () {
    config(['biztrack.renewal_window.opens_days_before' => 30]);

    $tooEarly = windowPermit('SANITARY', now()->addMonths(6)->toDateString());
    $inWindow = windowPermit('SANITARY', now()->addDays(10)->toDateString());

    $refusal = RenewalWindow::refusalFor($tooEarly);

    /*
     * The date is in the message on purpose. "Outside the renewal window" is
     * a refusal the applicant cannot act on; a date is one they can diarise.
     */
    expect($refusal)->toContain('can be renewed from')
        ->and($refusal)->toContain(
            CarbonImmutable::parse($tooEarly->valid_until)->subDays(30)->format('j F Y')
        )
        ->and(RenewalWindow::refusalFor($inWindow))->toBeNull();
});

it('refuses a permit lapsed past the cutoff, and sends them to a new application', function () {
    config(['biztrack.renewal_window.closes_months_after' => 12]);

    $justLapsed = windowPermit('SANITARY', now()->subMonths(2)->toDateString());
    $longDead = windowPermit('SANITARY', now()->subMonths(18)->toDateString());

    /*
     * The two refusals say different things because they ARE different news:
     * too early is a wait, too late is a different form. One shared
     * "outside the window" would leave both applicants guessing which.
     */
    expect(RenewalWindow::refusalFor($justLapsed))->toBeNull()
        ->and(RenewalWindow::refusalFor($longDead))->toContain('File a New Application instead');
});

it('still allows a late renewal inside the cutoff, so the surcharge can bite', function () {
    /*
     * The window and the penalty must not collide. A permit three months
     * lapsed is late — `latePenaltyFor` will charge Secs. 8A.04/8A.05 on it —
     * but it is still renewable, and a cutoff that refused it would mean the
     * surcharge could never apply to anything.
     */
    config(['biztrack.renewal_window.closes_months_after' => 12]);

    $late = windowPermit('CEC', now()->subMonths(3)->toDateString());

    expect(RenewalWindow::refusalFor($late))->toBeNull();
});

it('never binds the business permit, whatever the window is set to', function () {
    /*
     * `RenewalSeason` anchors every business permit to 20 January and the
     * client was explicit that no filing lock goes with it. A renewal filed in
     * June is accepted and runs to the next 20 January. If this ever starts
     * refusing, a decision made on purpose has been reversed by accident.
     */
    config(['biztrack.renewal_window.opens_days_before' => 30]);
    config(['biztrack.renewal_window.closes_months_after' => 1]);

    $earlyBusiness = windowPermit('BUSINESS', now()->addMonths(10)->toDateString());
    $lapsedBusiness = windowPermit('BUSINESS', now()->subYears(2)->toDateString());

    expect(RenewalWindow::refusalFor($earlyBusiness))->toBeNull()
        ->and(RenewalWindow::refusalFor($lapsedBusiness))->toBeNull();
});

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

it('ships with a 30-day early bound and a 36-month late bound', function () {
    /*
     * The shipped defaults, asserted against config rather than against the
     * class, so an env override in a deployment cannot silently move the
     * rule the tests below are written about.
     *
     * The early bound was null until 3 October 2026. A 30-day one had been
     * tried on 1 October and taken out the same day, because it refused a
     * staggered SUBSET renewal — six clearances with six expiry dates filed
     * as one application — which the system was then built to accept.
     *
     * That shape no longer exists. The client ruled on 3 October that a
     * renewal carries the business permit or ONE other permit
     * (`App\Support\RenewalScope`), so those six are six filings whatever
     * this value is and the window costs nothing it was not already
     * costing. What it buys is the picker telling the truth: *"valid
     * permits should NOT BE RENEWED until their renewal time window is
     * open."*
     *
     * 30 rather than any other number because `ScanPermits::THRESHOLDS`
     * sends its first reminder at 30 days, so the day the applicant is told
     * to renew is the day they first can.
     *
     * 36 months is unchanged and is the Sec. 8A.05 interest cap — see
     * config/biztrack.php for why the obvious "one lapsed term" answer was
     * rejected once it turned out renewals are inspected.
     */
    expect(config('biztrack.renewal_window.opens_days_before'))->toBe(30)
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
     *
     * The sentence around it was cut on 3 October 2026 — it named the permit
     * and restated its expiry, both of which the picker row already prints,
     * and the client was reading five of them stacked: *"so much texts
     * appear."* The DATE is what is asserted here, because the date is the
     * part that was never decoration.
     */
    expect($refusal)->toContain('Renewable from')
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
        ->and(RenewalWindow::refusalFor($longDead))->toContain('File a New Application');
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

it('binds the business permit by its January season, not by the day count', function () {
    /*
     * This asserted the opposite until 3 October 2026, and said why:
     * `RenewalSeason` anchors every business permit to 20 January and the
     * client was explicit on 1 October that no filing lock went with it —
     * *"don't add a lock in our system yet for this."*
     *
     * They reversed it: *"make the Mayor's Permit renewable for JANUARY
     * ONLY. Make it January 1 to 20 and 21 onwards will cause an additional
     * charge to the payment."* So the business permit is bound now — but by
     * a DATE and not by the day count the clearances use, which is the
     * distinction this test exists to hold.
     *
     * `opens_days_before` is set to something absurd here on purpose. If the
     * business permit ever starts reading it, a filing in its own January
     * would be refused for being 300 days early — which is the accident
     * this guards against, and the mirror of the one the old version did.
     */
    config(['biztrack.renewal_window.opens_days_before' => 300]);
    config(['biztrack.renewal_window.closes_months_after' => 36]);

    /* A term ending on the next 20 January, which is the only shape one has. */
    $season = CarbonImmutable::create(CarbonImmutable::now()->year + 1, 1, 20);
    $business = windowPermit('BUSINESS', $season->toDateString());

    /* Inside its January: open, and the 300-day count is not consulted. */
    expect(RenewalWindow::refusalFor($business, $season->startOfMonth()))->toBeNull()
        ->and(RenewalWindow::refusalFor($business, $season))->toBeNull();

    /*
     * Before it: refused, and told the date rather than a number of days.
     *
     * 31 December, not the 19th. The first draft of this used
     * `$season->subDay()` and failed — the day before the 20th is the
     * 19th, which is INSIDE the season and rightly allowed. The window
     * opens on the 1st, so the last refused day is the 31st of December
     * before it.
     */
    expect(RenewalWindow::refusalFor($business, $season->startOfMonth()->subDay()))
        ->toContain('1 January');

    /* After it: still open, because 21 January is a charge and not a bar. */
    expect(RenewalWindow::refusalFor($business, $season->addDay()))->toBeNull();
});

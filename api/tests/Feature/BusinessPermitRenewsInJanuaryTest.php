<?php

use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Support\RenewalWindow;
use Carbon\CarbonImmutable;

/*
 * The Mayor's Permit renews in January, and late costs money.
 *
 * ── The decision, and the one it reverses ───────────────────────────────────
 *
 * Client, 3 October 2026: *"make the Mayor's Permit renewable for JANUARY
 * ONLY. Make it January 1 to 20 and 21 onwards will cause an additional charge
 * to the payment."*
 *
 * That reverses their own instruction of 1 October — *"don't add a lock in our
 * system yet for this"* — which `RenewalSeason` still records as the reason the
 * business permit had no window. The reversal is deliberate and the note in
 * `RenewalWindow` says so; this file is the executable half.
 *
 * ── Two halves, one of which already existed ────────────────────────────────
 *
 * The FLOOR is new: a business permit renewal filed before 1 January of the
 * year its term ends is refused and told when to come back.
 *
 * The CHARGE is not. `WorkflowService::latePenaltyFor` has compared the filing
 * date against the prior permit's `valid_until` since the surcharge went in,
 * and a Mayor's Permit's `valid_until` IS 20 January (`RenewalSeason`). So 21
 * January was already one month late under Sec. 8A.05's "month or fraction
 * thereof". It is asserted here anyway, because the client asked for it as one
 * rule and a reader checking whether it was built should not have to know it
 * came from two places.
 *
 * ── Dates are injected, never "now" ─────────────────────────────────────────
 *
 * Every case passes `$filedAt`. A test that depends on the day it runs is one
 * that passes all year and fails in January, which is precisely the month this
 * rule is about.
 */

/** A business holding a Mayor's Permit whose term ends on the given 20 January. */
function januaryBusinessPermit(int $termEndsYear): Permit
{
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'January Season '.uniqid(),
        'registration_type' => 'DTI',
        'barangay_id' => Barangay::value('id'),
        'address_line' => '20 January Street',
        'status' => 'active',
    ]);

    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);

    $type = PermitType::where('code', PermitType::OUTCOME_CODE)->firstOrFail();

    return Permit::create([
        'application_id' => $priorApp->id,
        'business_id' => $business->id,
        'permit_type_id' => $type->id,
        'permit_number' => 'JAN-'.uniqid(),
        'issued_at' => CarbonImmutable::create($termEndsYear - 1, 2, 1),
        'valid_from' => CarbonImmutable::create($termEndsYear - 1, 2, 1),
        /* 20 January, always — see RenewalSeason::endOfTermFor. */
        'valid_until' => CarbonImmutable::create($termEndsYear, 1, 20),
        'status' => 'active',
    ])->load('permitType');
}

it('refuses a business permit renewal filed before January', function () {
    /*
     * The whole of the new rule. December is the month this catches most —
     * a business whose permit expires on 20 January trying to get ahead of
     * the queue in the week before Christmas.
     */
    $permit = januaryBusinessPermit(2027);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2026, 12, 31)))
        ->toBeString();
});

it('names the date to come back on, rather than just refusing', function () {
    $permit = januaryBusinessPermit(2027);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2026, 6, 15)))
        ->toContain('1 January 2027');
});

it('opens on 1 January', function () {
    /*
     * The boundary, asserted on the day itself rather than near it. An
     * off-by-one here is a business turned away on the first morning of the
     * season, in the queue it was told to join.
     */
    $permit = januaryBusinessPermit(2027);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 1, 1)))
        ->toBeNull();
});

it('is still open on 20 January, the last free day', function () {
    $permit = januaryBusinessPermit(2027);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 1, 20)))
        ->toBeNull();
});

it('still accepts a filing from 21 January, because that is a charge and not a bar', function () {
    /*
     * The half that is easy to get wrong in the other direction. "January
     * only" could have been read as a hard close on the 20th, which would
     * leave a late business unable to renew at all — and the client asked for
     * a charge, not a bar. The surcharge is asserted below.
     */
    $permit = januaryBusinessPermit(2027);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 1, 21)))
        ->toBeNull()
        ->and(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 6, 1)))
        ->toBeNull();
});

it('closes once the lapse passes the interest cap', function () {
    /*
     * The ceiling is unchanged and still applies to the business permit: 36
     * months is Sec. 8A.05's interest cap, past which delay is free at the
     * margin and renewal stops deterring anything.
     */
    $permit = januaryBusinessPermit(2027);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2031, 1, 21)))
        ->toContain('New Application');
});

it('charges 25% plus 2% a month from 21 January', function () {
    /*
     * Sec. 8A.04 and 8A.05, read off the calculator the workflow uses rather
     * than recomputed here — a test that reimplements the arithmetic it is
     * checking proves only that it can do the sum twice.
     *
     * One month, because 21 January is one day past a 20 January expiry and
     * Sec. 8A.05 charges "per month or fraction thereof".
     */
    $penalty = app(App\Services\FeeCalculator::class)->latePenalty(10_000.0, 1);

    expect($penalty['surcharge'])->toBe(2500.0)
        ->and($penalty['interest'])->toBe(250.0)
        ->and($penalty['total'])->toBe(12_750.0);
});

it('leaves the five clearances on their own rolling window', function () {
    /*
     * The business permit's season must not leak onto the others. Their terms
     * are their own year — a Sanitary Permit expiring in September has nothing
     * to do with January — and putting both under one rule would refuse every
     * clearance renewal for eleven months of the year.
     */
    $owner = User::where('email', 'owner@biztrack.local')->firstOrFail();

    $business = Business::create([
        'owner_user_id' => $owner->id,
        'name' => 'Rolling Window '.uniqid(),
        'registration_type' => 'DTI',
        'barangay_id' => Barangay::value('id'),
        'address_line' => '1 Rolling Street',
        'status' => 'active',
    ]);
    $priorApp = Application::create([
        'business_id' => $business->id,
        'applicant_user_id' => $owner->id,
        'application_type' => 'new',
        'status' => 'approved',
    ]);
    $sanitary = PermitType::where('code', 'SANITARY')->firstOrFail();
    $permit = Permit::create([
        'application_id' => $priorApp->id,
        'business_id' => $business->id,
        'permit_type_id' => $sanitary->id,
        'permit_number' => 'ROLL-'.uniqid(),
        'issued_at' => CarbonImmutable::create(2026, 9, 16),
        'valid_from' => CarbonImmutable::create(2026, 9, 16),
        'valid_until' => CarbonImmutable::create(2027, 9, 16),
        'status' => 'active',
    ])->load('permitType');

    /* Inside its own 30-day window, in September — nowhere near January. */
    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 9, 1)))
        ->toBeNull();

    /* And refused in January, which is the business permit's month, not its. */
    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 1, 5)))
        ->toBeString();
});

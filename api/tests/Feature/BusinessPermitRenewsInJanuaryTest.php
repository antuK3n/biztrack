<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Services\FeeCalculator;
use App\Services\WorkflowService;
use App\Support\RenewalSeason;
use App\Support\RenewalWindow;
use Carbon\CarbonImmutable;

/*
 * The Mayor's Permit expires on 31 December, renews in January, and late
 * costs money.
 *
 * ── The decisions ───────────────────────────────────────────────────────────
 *
 * Ken, 5 October 2026, from Malabon Revenue Code Ch. III art. A (e): the
 * permit *"expires on the thirty-first (31st) of December following date of
 * issuance … renewed within the first twenty (20) days of January"*. So the
 * term ends on 31 December, and 1 to 20 January is the window to renew
 * WITHOUT penalty; the surcharge starts on 21 January. Until then the term
 * itself ran to 20 January (`RenewalSeason` records that and why it went).
 *
 * Client, 3 October 2026: *"make the Mayor's Permit renewable for JANUARY
 * ONLY. Make it January 1 to 20 and 21 onwards will cause an additional charge
 * to the payment."* — the floor below, kept, and the same 21 January charge.
 *
 * ── Dates are injected, never "now" ─────────────────────────────────────────
 *
 * Every case passes `$filedAt` or a submission date. A test that depends on
 * the day it runs is one that passes all year and fails in January, which is
 * precisely the month this rule is about.
 */

/** A business holding a Mayor's Permit whose term ends on 31 December of the given year. */
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
        'issued_at' => CarbonImmutable::create($termEndsYear, 2, 1),
        'valid_from' => CarbonImmutable::create($termEndsYear, 2, 1),
        /* 31 December, always — see RenewalSeason::endOfTermFor. */
        'valid_until' => CarbonImmutable::create($termEndsYear, 12, 31),
        'status' => 'active',
    ])->load('permitType');
}

it('refuses a business permit renewal filed before January', function () {
    /*
     * December is the month this catches most — a business whose permit
     * expires on 31 December trying to get ahead of the queue in the week
     * before Christmas.
     */
    $permit = januaryBusinessPermit(2026);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2026, 12, 31)))
        ->toBeString()
        ->and(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2026, 6, 15)))
        ->toBeString();
});

it('names the date to come back on, rather than just refusing', function () {
    $permit = januaryBusinessPermit(2026);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2026, 6, 15)))
        ->toContain('1 January 2027');
});

it('opens on 1 January', function () {
    /*
     * The boundary, asserted on the day itself rather than near it. An
     * off-by-one here is a business turned away on the first morning of the
     * season, in the queue it was told to join.
     */
    $permit = januaryBusinessPermit(2026);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 1, 1)))
        ->toBeNull();
});

it('is still open on 20 January, the last free day', function () {
    $permit = januaryBusinessPermit(2026);

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
    $permit = januaryBusinessPermit(2026);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 1, 21)))
        ->toBeNull()
        ->and(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2027, 6, 1)))
        ->toBeNull();
});

it('closes once the lapse passes the interest cap', function () {
    /*
     * The ceiling still applies to the business permit: 36 months is Sec.
     * 8A.05's interest cap, past which delay is free at the margin and
     * renewal stops deterring anything. Counted from 20 January, as the
     * interest is.
     */
    $permit = januaryBusinessPermit(2026);

    expect(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2030, 1, 20)))
        ->toBeNull()
        ->and(RenewalWindow::refusalFor($permit, CarbonImmutable::create(2030, 1, 21)))
        ->toContain('New Application');
});

it('charges 25% plus 2% a month from 21 January', function () {
    /*
     * Sec. 8A.04 and 8A.05, read off the calculator the workflow uses rather
     * than recomputed here — a test that reimplements the arithmetic it is
     * checking proves only that it can do the sum twice.
     *
     * One month, because 21 January is one day past the 20 January end of
     * the free window and Sec. 8A.05 charges "per month or fraction thereof".
     */
    $penalty = app(FeeCalculator::class)->latePenalty(10_000.0, 1);

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

/** A renewal of `$prior` submitted on the given day. */
function januaryRenewalFiledOn(Permit $prior, CarbonImmutable $filed): Application
{
    $app = Application::create([
        'tracking_id' => 'BIZ-JAN-'.uniqid(),
        'business_id' => $prior->business_id,
        'applicant_user_id' => $prior->business->owner_user_id,
        'application_type' => 'renewal',
        'status' => ApplicationStatus::Draft,
        'prior_permit_id' => $prior->id,
        'submitted_at' => $filed,
    ]);

    return $app;
}

function januaryPenalty(Permit $prior, CarbonImmutable $filed): array
{
    $method = new ReflectionMethod(WorkflowService::class, 'latePenaltyFor');

    return $method->invoke(app(WorkflowService::class), januaryRenewalFiledOn($prior, $filed), $prior, 10_000.0);
}

it('charges nothing for a renewal filed on 15 January, after the 31 December expiry', function () {
    /*
     * The case the 31 December expiry must not break: the permit lapsed at
     * the turn of the year, and the first twenty days of January are the
     * ordinance's window to renew without penalty.
     */
    $penalty = januaryPenalty(januaryBusinessPermit(2026), CarbonImmutable::create(2027, 1, 15, 10));

    expect($penalty['surcharge'])->toBe(0.0)
        ->and($penalty['interest'])->toBe(0.0)
        ->and($penalty['months_counted'])->toBe(0);
});

it('charges nothing on 20 January, and a month from 21 January', function () {
    $permit = januaryBusinessPermit(2026);

    expect(januaryPenalty($permit, CarbonImmutable::create(2027, 1, 20, 16))['surcharge'])->toBe(0.0);

    $late = januaryPenalty($permit, CarbonImmutable::create(2027, 1, 21, 9));
    expect($late['surcharge'])->toBe(2500.0)
        ->and($late['months_counted'])->toBe(1);
});

it('charges 25% plus 2% for one month on a renewal filed on 15 February', function () {
    /*
     * Counted from 20 January, not from the 31 December expiry: 21 January
     * to 15 February is less than a month, which Sec. 8A.05's "month or
     * fraction thereof" rounds up to one. Counted from 31 December it would
     * have been two.
     *
     *   fee        10,000.00
     *   surcharge   2,500.00   (25%)
     *   interest      250.00   (2% x 1 month x 12,500.00)
     */
    $penalty = januaryPenalty(januaryBusinessPermit(2026), CarbonImmutable::create(2027, 2, 15, 10));

    expect($penalty['surcharge'])->toBe(2500.0)
        ->and($penalty['months_counted'])->toBe(1)
        ->and($penalty['interest'])->toBe(250.0)
        ->and($penalty['total'])->toBe(12_750.0);
});

it('issues a business permit to 31 December of the year it is issued', function () {
    expect(RenewalSeason::endOfTermFor(CarbonImmutable::create(2026, 6, 15))->toDateString())->toBe('2026-12-31')
        ->and(RenewalSeason::endOfTermFor(CarbonImmutable::create(2026, 12, 1))->toDateString())->toBe('2026-12-31')
        ->and(RenewalSeason::endOfTermFor(CarbonImmutable::create(2027, 1, 15))->toDateString())->toBe('2027-12-31');
});

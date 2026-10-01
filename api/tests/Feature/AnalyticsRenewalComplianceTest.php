<?php

use App\Support\DashboardAnalytics;
use Carbon\CarbonImmutable;

/*
 * Renewal compliance matches a renewal to a permit by business and permit
 * type, not only by `prior_permit_id`.
 *
 * A renewal filing names one prior permit — the business permit — and the
 * clearances riding on it had no link of their own, so the five clearance
 * offices' dashboards read "Renewal compliance" blank however their
 * businesses renewed.
 */

it('credits a clearance office with the renewals filed for its permit type', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 04:00:00', 'UTC'));
    $office = anaOffice();
    $code = $office['permit_code'];

    $dueLastYear = fn (int $business) => anaPermit($business, $code, [
        'issued_at' => '2026-01-02 01:00:00',
        'valid_from' => '2026-01-02',
        'valid_until' => '2026-12-31',
        'status' => 'expired',
    ]);
    $renewal = function (int $business, string $submittedAt) use ($code): void {
        $filing = anaFiling($business, ['application_type' => 'renewal', 'status' => 'approved', 'submitted_at' => $submittedAt]);
        anaCarries($filing, $code);
    };

    // Renewed in December, before the 31st: on time. No prior_permit_id at all.
    $onTime = anaBusiness();
    $dueLastYear($onTime);
    $renewal($onTime, '2026-12-20 02:00:00');

    // Renewed in January, after it ran out: matched, but late.
    $late = anaBusiness();
    $dueLastYear($late);
    $renewal($late, '2027-01-12 02:00:00');

    // Never renewed.
    $dueLastYear(anaBusiness());

    $renewalCompliance = collect(DashboardAnalytics::build(12, $office['code'])['compliance'])->firstWhere('indicator', 'renewal');

    expect($renewalCompliance['denominator'])->toBe(3)
        ->and($renewalCompliance['numerator'])->toBe(1)
        ->and($renewalCompliance['rate'])->toEqual(33.3)
        ->and($renewalCompliance['unavailable_reason'])->toBeNull();
});

it('does not count the filing that produced a permit as that permit’s renewal', function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 04:00:00', 'UTC'));
    $office = anaOffice();
    $business = anaBusiness();

    // Renewed for 2026 on 2 January, the permit's own first day; then let lapse.
    $filing = anaFiling($business, ['application_type' => 'renewal', 'status' => 'approved', 'submitted_at' => '2026-01-02 01:00:00']);
    anaCarries($filing, $office['permit_code']);
    anaPermit($business, $office['permit_code'], [
        'application_id' => $filing,
        'issued_at' => '2026-01-02 03:00:00',
        'valid_from' => '2026-01-02',
        'valid_until' => '2026-12-31',
        'status' => 'expired',
    ]);

    $renewalCompliance = collect(DashboardAnalytics::build(12, $office['code'])['compliance'])->firstWhere('indicator', 'renewal');

    expect($renewalCompliance['denominator'])->toBe(1)
        ->and($renewalCompliance['numerator'])->toBe(0)
        // Nothing renews it, so there is nothing to compute a rate from.
        ->and($renewalCompliance['rate'])->toBeNull();
});

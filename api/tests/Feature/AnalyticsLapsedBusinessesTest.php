<?php

use App\Support\DashboardAnalytics;
use Carbon\CarbonImmutable;

/*
 * "Already expired" counts businesses that have let a permit lapse, not every
 * expired permit row on the register.
 *
 * A business that renews every year holds one expired permit per past year
 * and was counted once for each, while trading on a valid one. BPLO's row
 * read 767 against 147 businesses actually lapsed.
 */

it('counts a lapsed business once per permit type, and a renewed one not at all', function () {
    $this->travelTo(CarbonImmutable::parse('2027-06-15 04:00:00', 'UTC'));
    $office = anaOffice();
    $permit = fn (int $business, string $until, string $status = 'expired') => anaPermit($business, $office['permit_code'], [
        'issued_at' => CarbonImmutable::parse($until)->subYear()->addDay()->toDateTimeString(),
        'valid_until' => $until,
        'status' => $status,
    ]);

    // Renewed: last year's permit ran out, this year's is in force.
    $renewed = anaBusiness();
    $permit($renewed, '2026-12-31');
    $permit($renewed, '2027-12-31', 'active');

    // Lapsed for two years running: one lapsed business, not two rows.
    $lapsed = anaBusiness();
    $permit($lapsed, '2025-12-31');
    $permit($lapsed, '2026-12-31');

    // Its latest permit was revoked: stopped, not lapsed.
    $revoked = anaBusiness();
    $permit($revoked, '2025-12-31');
    $permit($revoked, '2026-12-31', 'revoked');

    // The nightly scan has not flipped it yet: still `active`, date passed.
    $unflipped = anaBusiness();
    $permit($unflipped, '2027-06-01', 'active');

    $expired = collect(DashboardAnalytics::build(12, $office['code'])['expiry']['rows'])->firstWhere('window', 'expired');

    expect($expired['total'])->toBe(2)
        ->and($expired['counts'][$office['permit_code']])->toBe(2)
        ->and($expired['businesses'])->toBe(2);
});

it('says how many businesses a row is about when one business lapses on two types', function () {
    $this->travelTo(CarbonImmutable::parse('2027-06-15 04:00:00', 'UTC'));
    $before = collect(DashboardAnalytics::build()['expiry']['rows'])->firstWhere('window', 'expired');

    $business = anaBusiness();
    foreach (['SANITARY', 'FSIC'] as $code) {
        anaPermit($business, $code, ['issued_at' => '2026-01-02 01:00:00', 'valid_until' => '2026-12-31', 'status' => 'expired']);
    }

    $after = collect(DashboardAnalytics::build()['expiry']['rows'])->firstWhere('window', 'expired');

    expect($after['total'] - $before['total'])->toBe(2)
        ->and($after['businesses'] - $before['businesses'])->toBe(1);
});

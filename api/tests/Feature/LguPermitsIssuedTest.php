<?php

use App\Support\LguReports;
use Carbon\CarbonImmutable;

/*
 * "Permits Issued — New and Renewal", and the two reports that class permits
 * the same way (businesses by area, clearances).
 *
 *  - For every office, the month table is business permits: the five
 *    clearances were being added into the same New and Renewal cells.
 *  - New or renewal is what the business already held, not the form the
 *    permit came in on.
 *  - Permits brought over from the old register have no filing and were
 *    dropped by an inner join; they are counted.
 *
 * March 2031 holds nothing the demo seed writes, so each report holds only
 * the permits written here.
 */

function issuedReport(string $key = 'permits-issued'): array
{
    return LguReports::build($key, CarbonImmutable::parse('2031-03-01'), CarbonImmutable::parse('2031-03-31'), null);
}

/** A permit released in March 2031 from a filing of the given kind, or from none. */
function issuedPermit(int $business, string $code, ?string $filing, string $issuedAt = '2031-03-10 02:00:00'): int
{
    return anaPermit($business, $code, [
        'application_id' => $filing === null ? null : anaFiling($business, ['application_type' => $filing, 'status' => 'approved']),
        'issued_at' => $issuedAt,
    ]);
}

it('counts business permits alone in the all-offices month table, and every type in its own row', function () {
    $business = anaBusiness();
    issuedPermit($business, 'BUSINESS', 'new');
    issuedPermit($business, 'SANITARY', 'new');
    issuedPermit($business, 'FSIC', 'new');

    [$byMonth, $byType] = issuedReport()['sections'];
    $types = collect($byType['rows'])->keyBy('label');

    expect($byMonth['heading'])->toBe('Business permits by month')
        ->and($byMonth['total']['new'])->toBe(1)
        ->and($byMonth['total']['total'])->toBe(1)
        ->and($byType['total']['total'])->toBe(3)
        ->and($types->every(fn (array $row) => $row['new'] === 1 && $row['total'] === 1))->toBeTrue();
});

it('classes a permit as a renewal when the business already held that type', function () {
    // Held a business permit from the old register since 2030, then filed as
    // "new" in 2031: the City kept a permit holder, it did not gain one.
    $held = anaBusiness();
    anaPermit($held, 'BUSINESS', ['application_id' => null, 'issued_at' => '2030-01-10 02:00:00', 'valid_until' => '2030-12-31']);
    issuedPermit($held, 'BUSINESS', 'new');

    // A renewal of a permit the City issued on paper: no earlier permit on
    // the register, and still a renewal.
    issuedPermit(anaBusiness(), 'BUSINESS', 'renewal');

    // Two in the period for one business: the first is new, the second is not.
    $twice = anaBusiness();
    issuedPermit($twice, 'BUSINESS', 'new', '2031-03-03 02:00:00');
    issuedPermit($twice, 'BUSINESS', 'new', '2031-03-24 02:00:00');

    $total = issuedReport()['sections'][0]['total'];

    expect($total['new'])->toBe(1)
        ->and($total['renewal'])->toBe(3);
});

it('counts a permit brought over from the old register', function () {
    $business = anaBusiness();
    issuedPermit($business, 'BUSINESS', null);
    issuedPermit($business, 'SANITARY', null);

    $issued = issuedReport()['sections'][0]['total'];
    $byArea = issuedReport('businesses-by-area')['sections'][0]['total'];
    $clearances = collect(issuedReport('clearances')['sections'][0]['rows'])->keyBy('label');

    expect($issued['new'])->toBe(1)
        ->and($byArea['new'])->toBe(1)
        ->and($clearances->sum('total'))->toBe(2);
});

it('counts a business in the area table as renewing when it held permits before the period', function () {
    $business = anaBusiness();
    anaPermit($business, 'BUSINESS', ['issued_at' => '2030-02-01 02:00:00', 'valid_until' => '2030-12-31']);
    issuedPermit($business, 'BUSINESS', 'new');

    $total = issuedReport('businesses-by-area')['sections'][0]['total'];

    expect($total['new'])->toBe(0)
        ->and($total['renewal'])->toBe(1);
});

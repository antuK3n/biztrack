<?php

use App\Support\DashboardAnalytics;
use Carbon\CarbonImmutable;

/*
 * Average Processing Time by Department.
 *
 *  - BPLO's recorded review is the whole filing (its row is stamped again at
 *    the final approval), so it is set aside, as Office Performance sets it
 *    aside, and the panel says why.
 *  - The slowest department is chosen only among offices with enough
 *    reviews to be drawn; the threshold is decided here and sent with it.
 *
 * March 2031 holds nothing the demo seed writes.
 */

/** One finished review: Monday 08:00 Manila to $days office days later. */
function departmentReview(string $office, int $days): void
{
    $filing = anaFiling(anaBusiness(), ['status' => 'approved', 'submitted_at' => '2031-03-03 00:00:00']);
    anaAssignment($filing, $office, [
        'assigned_at' => '2031-03-03 00:00:00',
        'completed_at' => CarbonImmutable::parse('2031-03-03 00:00:00', 'UTC')->addDays($days)->toDateTimeString(),
    ]);
}

it('names the slowest department only among those with enough reviews to draw, and leaves BPLO out', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));

    departmentReview('CHO', 4);           // slowest, but one review: not drawn
    foreach ([1, 1, 2] as $days) {
        departmentReview('BFP', $days);   // three reviews: drawn
    }
    foreach ([4, 4, 4] as $days) {
        departmentReview('BPLO', $days);  // the whole filing, not BPLO's step
    }

    $stages = DashboardAnalytics::build()['stages'];
    $rows = collect($stages['rows'])->keyBy('code');

    expect($stages['min_reviews'])->toBe(3)
        ->and($rows->has('BPLO'))->toBeFalse()
        ->and(array_column($stages['excluded'], 'code'))->toBe(['BPLO'])
        ->and($rows['CHO']['drawn'])->toBeFalse()
        ->and($rows['BFP']['drawn'])->toBeTrue()
        ->and($stages['bottleneck']['code'])->toBe('BFP')
        ->and($stages['reviews'])->toBe(4);
});

it('says why BPLO’s own view of the panel is empty', function () {
    $this->travelTo(CarbonImmutable::parse('2031-03-28 04:00:00', 'UTC'));
    foreach ([1, 2, 3] as $days) {
        departmentReview('BPLO', $days);
    }

    $stages = DashboardAnalytics::build(12, 'BPLO')['stages'];

    expect($stages['rows'])->toBe([])
        ->and($stages['bottleneck'])->toBeNull()
        ->and($stages['excluded'][0]['reason'])->toContain('final approval');
});

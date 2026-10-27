<?php

use App\Support\OccupancyChecklist;

/*
 * NBC Form B-13's "Verified & complied" lines depend on the building
 * [client, 28 October 2026]: the always-needed eight and Accessibility, the
 * three that turn on size and use, and "Others" for this business.
 */

$evaluators = ['Architectural' => 'Arch. A', 'Mechanical' => 'Engr. M', 'Electronics' => 'Engr. E', 'Interior Design' => 'ID I'];

it('marks a small store N/A on the lines it does not need', function () use ($evaluators) {
    $c = OccupancyChecklist::for(18.0, 1, 'Retail sale in non-specialized stores (sari-sari store)', null, $evaluators);

    expect($c['lines']['Architectural'])->toBe('Arch. A')
        ->and($c['lines']['Accessibility'])->toBeNull() // applies; no evaluator on file
        ->and($c['lines']['Mechanical'])->toBe('N/A')
        ->and($c['lines']['Electronics'])->toBe('N/A')
        ->and($c['lines']['Interior Design'])->toBe('N/A')
        ->and($c['others'])->toBe('N/A')
        ->and($c['use'])->toBe('Mercantile (Store)');
});

it('gives a food business its kitchen works and LPG', function () use ($evaluators) {
    $c = OccupancyChecklist::for(40.0, 1, 'Carinderia and food service', null, $evaluators);

    expect($c['lines']['Mechanical'])->toBe('Engr. M')
        ->and($c['others'])->toBe('LPG Installation')
        ->and($c['use'])->toBe('Food Service');
});

it('needs every line and a sprinkler system on a large store', function () use ($evaluators) {
    $c = OccupancyChecklist::for(2400.0, 2, 'Supermarket and grocery retail', null, $evaluators);

    expect(collect($c['lines'])->contains('N/A'))->toBeFalse()
        ->and($c['others'])->toBe('Fire Sprinkler System');
});

it('leaves a size-dependent line blank rather than N/A when the size is unknown', function () use ($evaluators) {
    $c = OccupancyChecklist::for(null, null, 'Hardware and construction supply', null, $evaluators);

    expect($c['lines']['Electronics'])->toBeNull()
        ->and($c['lines']['Interior Design'])->toBeNull()
        ->and($c['others'])->toBeNull();
});

it('keeps the use the applicant declared when it says more than "Commercial"', function () use ($evaluators) {
    expect(OccupancyChecklist::for(50.0, 1, 'Bakery', 'Mixed Use — Store and Residence', $evaluators)['use'])
        ->toBe('Mixed Use — Store and Residence');
});

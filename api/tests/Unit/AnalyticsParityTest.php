<?php

use App\Services\RAnalytics;
use App\Support\BusinessGrowthAnalytics;
use App\Support\DashboardAnalytics;
use App\Support\ProcessingTimeAnalytics;
use App\Support\RenewalRiskAnalytics;

const PARITY_TOLERANCE = 1e-6;

function parityDatasets(): array
{
    return [
        ['processing-time', ProcessingTimeAnalytics::compute(...), ProcessingTimeAnalytics::R_ENDPOINT],
        ['renewal-risk', RenewalRiskAnalytics::compute(...), RenewalRiskAnalytics::R_ENDPOINT],
        ['dashboard', DashboardAnalytics::compute(...), DashboardAnalytics::R_ENDPOINT],
        ['growth-lifecycle', BusinessGrowthAnalytics::compute(...), BusinessGrowthAnalytics::R_ENDPOINT],
    ];
}

function parityFixture(string $name): array
{
    $path = __DIR__."/../fixtures/analytics/{$name}";
    expect(file_exists($path))->toBeTrue("Missing analytics fixture: {$name}");

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function parityDiff(string $path, mixed $expected, mixed $actual): array
{
    if (is_array($expected) && is_array($actual)) {
        $diffs = [];
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            if (! array_key_exists($key, $expected)) {
                $diffs[] = "{$path}.{$key}: absent from R, present in PHP";

                continue;
            }
            if (! array_key_exists($key, $actual)) {
                $diffs[] = "{$path}.{$key}: present in R, absent from PHP";

                continue;
            }
            $diffs = [...$diffs, ...parityDiff("{$path}.{$key}", $expected[$key], $actual[$key])];
        }

        return $diffs;
    }

    if (is_array($expected) !== is_array($actual)) {
        return ["{$path}: R gave ".gettype($expected).', PHP gave '.gettype($actual)];
    }

    if (is_numeric($expected) && is_numeric($actual)) {
        return abs((float) $expected - (float) $actual) > PARITY_TOLERANCE
            ? ["{$path}: R={$expected} PHP={$actual}"]
            : [];
    }

    return $expected === $actual
        ? []
        : ["{$path}: R=".json_encode($expected).' PHP='.json_encode($actual)];
}

it('computes the same statistics as R for every fixture', function () {
    foreach (parityDatasets() as [$name, $compute, $_endpoint]) {
        $dataset = parityFixture("{$name}.dataset.json");
        $fromR = parityFixture("{$name}.r-output.json");

        $diffs = parityDiff($name, $fromR, $compute($dataset));

        expect($diffs)->toBe([], sprintf(
            "The PHP port disagrees with R on %s:\n  %s",
            $name,
            implode("\n  ", array_slice($diffs, 0, 20)),
        ));
    }
});

it('covers charted, thin, unchartable and flagged departments', function () {
    $report = parityFixture('processing-time.r-output.json');

    expect(array_column($report['departments'], 'code'))->toBe(['BPLO', 'CHO']);

    $thin = array_column($report['thin'], 'reason', 'code');
    expect($thin)->toHaveKeys(['BFP', 'ZON']);
    expect($thin['BFP'])->toContain('completed reviews');

    expect($thin['ZON'])->toContain('did not vary');

    $byCode = array_column($report['departments'], null, 'code');
    expect($byCode['BPLO']['status'])->toBe('inside');
    expect($byCode['BPLO']['flagged'])->toBe([]);
    expect($byCode['BPLO']['sigma'])->toBeGreaterThan(0.0);

    expect($byCode['CHO']['status'])->toBe('outside');
    expect(count($byCode['CHO']['flagged']))->toBeGreaterThan(0);
    expect($byCode['CHO']['trend']['direction'])->toBe('rising');
    expect($byCode['CHO']['trend']['drift_flagged'])->toBeTrue();
});

it('covers every renewal-risk band and compares every scored permit', function () {
    $report = parityFixture('renewal-risk.r-output.json');

    expect(count($report['at_risk']))->toBe($report['scored_permits']);

    foreach (['high', 'moderate', 'low'] as $band) {
        expect($report['counts'][$band])->toBeGreaterThan(0, "No {$band}-band permit in the fixture.");
    }

    $byBusiness = array_column($report['at_risk'], null, 'business');

    $notDue = collect($byBusiness['Not yet due']['drivers'])->firstWhere('rule', 'progress');
    expect($notDue)->toBeNull('A permit not yet due must not score on renewal progress.');

    $punctuality = collect($byBusiness['Punctuality 1/8']['drivers'])->firstWhere('rule', 'punctuality');
    expect($punctuality['points'])->toBe(2);

    expect($byBusiness['Unknown stage']['renewal_stage'])->toBe('cancelled');
    expect(collect($byBusiness['Unknown stage']['drivers'])->firstWhere('rule', 'progress')['points'])->toBe(25);

    expect($report['methodology'])->toContain('not a probability');
});

it('covers the dashboard branches where a null and a zero are different things', function () {
    $report = parityFixture('dashboard.r-output.json');

    $tiers = array_column($report['processing_tiers'], null, 'tier');


    expect($tiers['simple']['breaching'])->toBeTrue();
    expect($tiers['complex']['breaching'])->toBeFalse();


    expect($tiers['highly_technical']['observations'])->toBe(0);
    expect($tiers['highly_technical']['mean_working_days'])->toBeNull();
    expect($tiers['highly_technical']['breaching'])->toBeFalse();

    expect($tiers['simple']['mean_working_days'])->toEqual(4.2);
    expect($tiers['complex']['mean_working_days'])->toEqual(6.5);


    expect($tiers['simple']['within_statutory'])->toBeLessThan($tiers['simple']['within_recorded_deadline']);
    expect($tiers['simple']['recorded_deadline_working_days'])
        ->toBeGreaterThan($tiers['simple']['statutory_working_days']);

    expect($report['decisions']['decisioned'])->toBe(12);
    expect($report['decisions']['approval_rate'])->toEqual(66.7);

    $compliance = array_column($report['compliance'], null, 'indicator');

    expect($compliance['permit_validity']['rate'])->toBeNull();
    expect($compliance['permit_validity']['unavailable_reason'])->toBeNull();


    
    expect($compliance['renewal']['rate'])->toBeNull();
    expect($compliance['renewal']['unavailable_reason'])->toContain('gap in the register');
    expect($compliance['renewal']['denominator'])->toBeGreaterThan(0);

    $windows = array_column($report['expiry']['rows'], null, 'window');
    expect($windows['next_30d']['total'])->toBeLessThan($windows['next_60d']['total']);
    expect($windows['next_60d']['total'])->toBeLessThan($windows['next_90d']['total']);

    expect($windows['expired']['total'])->toBe(4);


    $barangays = array_column($report['top_barangays']['rows'], 'barangay');
    expect(array_slice($barangays, 0, 3))->toBe(['Longos', 'Acacia', 'Bulacan']);


    expect($report['organization_forms']['recorded'])->toBe(20);
    expect($report['organization_forms']['unrecorded'])->toBe(5);
    expect(array_sum(array_column($report['organization_forms']['rows'], 'share')))->toEqual(100.0);


    $inspections = array_column($report['inspections']['rows'], null, 'type');
    expect($inspections['CPDO']['scheduled'])->toBeGreaterThan(0);
    expect($inspections['CPDO']['completed'])->toBe(0);
    expect($inspections['CPDO']['pass_rate'])->toBeNull();
    expect($inspections['CHO']['pass_rate'])->toEqual(87.5);


    expect($report['officer_activity']['median_response_hours'])->toEqual(2.8);


    
    expect($report['map']['points'][0]['latitude'])->toEqual(14.662398);


    expect($report['map']['points'][3]['barangay'])->toBeNull();
    expect($report['map']['plotted'])->toBe(4);
    expect(array_sum(array_column($report['map']['by_barangay'], 'businesses')))->toBe(3);
});

it('covers cohort survival including a cohort with nothing yet to measure', function () {
    $survival = parityFixture('growth-lifecycle.r-output.json')['cohort_survival'];

    $cohorts = array_column($survival['cohorts'], null, 'cohort');



    expect($cohorts['2023']['points'][0]['survival'])->toEqual(80.0);
    expect($cohorts['2023']['points'][1]['at_risk'])->toBe(6);
    expect($cohorts['2023']['points'][1]['survival'])->toEqual(53.3);

    expect($cohorts['2024']['points'][0]['survival'])->toEqual(75.0);


    
    expect($cohorts['2026']['max_cycle'])->toBe(0);
    expect($cohorts['2026']['survival'])->toBeNull();
    expect($cohorts['2026']['points'])->toBe([]);

    $previous = 100.0;
    foreach ($survival['points'] as $point) {
        expect($point['survival'])->toBeLessThanOrEqual($previous);
        $previous = $point['survival'];
    }

    expect($survival['methodology'])->toContain('not a forecast');
});

it('covers the lifecycle branches that rank by change rather than by size', function () {
    $report = parityFixture('growth-lifecycle.r-output.json');

    expect($report['growth_rate'])->toEqual(25.0);


    
    $barangays = array_column($report['top_barangays'], 'barangay');
    expect(array_slice($barangays, 0, 2))->toBe(['Acacia', 'Bulacan']);
    expect(array_column($report['top_barangays'], 'delta')[0])->toBe(6);

    $flores = collect($report['top_barangays'])->firstWhere('barangay', 'Flores');
    expect($flores['prior'])->toBe(0);
    expect($flores['growth_rate'])->toBeNull();
    expect($flores['delta'])->toBe(4);

    $directions = array_column($report['industry_growth'], 'direction');
    expect($directions)->toContain('growing');
    expect($directions)->toContain('declining');
    expect($directions)->toContain('steady');
});

it('still gets the same statistics back from a live R service', function () {
    $r = app(RAnalytics::class);

    if ($r->health() === null) {
        $this->markTestSkipped(
            'The R service is not running, so R-side drift is unchecked. '
            .'Start it with: cd r && Rscript run_api.R'
        );
    }

    foreach (parityDatasets() as [$name, $_compute, $endpoint]) {
        $dataset = parityFixture("{$name}.dataset.json");
        $golden = parityFixture("{$name}.r-output.json");

        $live = $r->compute($endpoint, $dataset);
        expect($live)->not->toBeNull("R failed on {$name}: ".(string) $r->lastError());

        $diffs = parityDiff($name, $golden, $live);

        expect($diffs)->toBe([], sprintf(
            "The live R service no longer matches the golden output for %s:\n  %s",
            $name,
            implode("\n  ", array_slice($diffs, 0, 20)),
        ));
    }
});

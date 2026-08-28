<?php

use App\Support\BusinessGrowthAnalytics;
use App\Support\DashboardAnalytics;
use App\Support\ProcessingTimeAnalytics;
use App\Support\RenewalRiskAnalytics;

/*
 * ── WHAT THIS FILE USED TO BE, AND WHY IT IS STILL HERE ─────────────────────
 *
 * This was AnalyticsParityTest. Four datasets had two implementations — R was
 * the reference, PHP was the fallback that stood in when R was unreachable — and
 * two implementations drift. So it fed a frozen row set to both and compared the
 * results value for value, in both directions, to a tolerance of 1e-6.
 *
 * R has been removed from BizTrack. There is one implementation now, so there is
 * nothing to compare against and the cross-engine half of the test is dead.
 *
 * Deleting the file outright would have thrown away something it was doing
 * incidentally but uniquely: it is the ONLY test that pins the builders' numeric
 * output on a controlled input. Every other analytics test runs against seeded
 * data whose figures move whenever the seeder does, so none of them can assert an
 * exact value. These fixtures do not move, so this one can — the rounding of a
 * rate, a null that must not become a zero, a share set that must sum to exactly
 * 100, six decimal places of latitude rather than four.
 *
 * So the comparison survived and only its right-hand side changed. The
 * `*.expected.json` files ARE the old `*.r-output.json` files, renamed: they were
 * R's output, this test proved them equal to PHP's output on every run up to the
 * removal, and they are therefore a valid frozen baseline for PHP. What was a
 * parity check is now a golden-master check, and it fails on exactly what it
 * always caught — a builder's arithmetic moving without anyone saying it should.
 *
 * The branch-coverage tests below have been repointed at the COMPUTED output
 * rather than at the fixture file. Under parity the two were interchangeable and
 * reading the file was cheaper; now that the file is a baseline rather than a
 * second opinion, asserting against it would only check the baseline against
 * itself.
 *
 * DROPPED WITH R: one test that pushed these same fixtures to a live plumber
 * service and compared its answers against the golden files, skipping when the
 * service was not running. It guarded drift on the R side of a two-engine
 * system. There is no R side.
 *
 * Regenerating a baseline: see tests/fixtures/analytics/build-fixtures.php.
 */

const GOLDEN_TOLERANCE = 1e-6;

/**
 * The datasets with a frozen input and a frozen expected output.
 *
 * @return list<array{string, callable(array<string, mixed>): array<string, mixed>}>
 */
function goldenDatasets(): array
{
    return [
        ['processing-time', ProcessingTimeAnalytics::compute(...)],
        ['renewal-risk', RenewalRiskAnalytics::compute(...)],
        ['dashboard', DashboardAnalytics::compute(...)],
        ['growth-lifecycle', BusinessGrowthAnalytics::compute(...)],
    ];
}

function goldenFixture(string $name): array
{
    $path = __DIR__."/../fixtures/analytics/{$name}";
    expect(file_exists($path))->toBeTrue("Missing analytics fixture: {$name}");

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/** The builder's output for a fixture dataset. */
function goldenComputed(string $name): array
{
    foreach (goldenDatasets() as [$dataset, $compute]) {
        if ($dataset === $name) {
            return $compute(goldenFixture("{$name}.dataset.json"));
        }
    }

    throw new InvalidArgumentException("No golden dataset [{$name}].");
}

/**
 * Every disagreement between the baseline and the computed output.
 *
 * Reported in full rather than failing on the first, because a rounding change
 * shows up in dozens of places at once and the shape of that list is what tells
 * you which change it was.
 *
 * @return list<string>
 */
function goldenDiff(string $path, mixed $expected, mixed $actual): array
{
    if (is_array($expected) && is_array($actual)) {
        $diffs = [];
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            if (! array_key_exists($key, $expected)) {
                $diffs[] = "{$path}.{$key}: absent from the baseline, present in the output";

                continue;
            }
            if (! array_key_exists($key, $actual)) {
                $diffs[] = "{$path}.{$key}: present in the baseline, absent from the output";

                continue;
            }
            $diffs = [...$diffs, ...goldenDiff("{$path}.{$key}", $expected[$key], $actual[$key])];
        }

        return $diffs;
    }

    if (is_array($expected) !== is_array($actual)) {
        return ["{$path}: baseline gave ".gettype($expected).', output gave '.gettype($actual)];
    }

    if (is_numeric($expected) && is_numeric($actual)) {
        return abs((float) $expected - (float) $actual) > GOLDEN_TOLERANCE
            ? ["{$path}: baseline={$expected} output={$actual}"]
            : [];
    }

    return $expected === $actual
        ? []
        : ["{$path}: baseline=".json_encode($expected).' output='.json_encode($actual)];
}

it('computes the frozen baseline output for every fixture', function () {
    foreach (goldenDatasets() as [$name, $compute]) {
        $dataset = goldenFixture("{$name}.dataset.json");
        $baseline = goldenFixture("{$name}.expected.json");

        $diffs = goldenDiff($name, $baseline, $compute($dataset));

        expect($diffs)->toBe([], sprintf(
            "The %s builder no longer produces its baseline output:\n  %s",
            $name,
            implode("\n  ", array_slice($diffs, 0, 20)),
        ));
    }
});

it('covers charted, thin, unchartable and flagged departments', function () {
    $report = goldenComputed('processing-time');

    expect(array_column($report['departments'], 'code'))->toBe(['BPLO', 'CHO']);

    // A department can fail to be chartable for two different reasons, and the
    // screen distinguishes them: too few completed reviews to fit limits at all,
    // or enough reviews that never varied, which gives a zero-width limit.
    $thin = array_column($report['thin'], 'reason', 'code');
    expect($thin)->toHaveKeys(['BFP', 'ZON']);
    expect($thin['BFP'])->toContain('completed reviews');
    expect($thin['ZON'])->toContain('did not vary');

    $byCode = array_column($report['departments'], null, 'code');
    expect($byCode['BPLO']['status'])->toBe('inside');
    expect($byCode['BPLO']['flagged'])->toBe([]);
    expect($byCode['BPLO']['sigma'])->toBeGreaterThan(0.0);

    // A department outside its limits, carrying the EWMA drift reading — a
    // separate finding from any single week breaching.
    expect($byCode['CHO']['status'])->toBe('outside');
    expect(count($byCode['CHO']['flagged']))->toBeGreaterThan(0);
    expect($byCode['CHO']['trend']['direction'])->toBe('rising');
    expect($byCode['CHO']['trend']['drift_flagged'])->toBeTrue();
});

it('covers every renewal-risk band and scores every permit it lists', function () {
    $report = goldenComputed('renewal-risk');

    expect(count($report['at_risk']))->toBe($report['scored_permits']);

    foreach (['high', 'moderate', 'low'] as $band) {
        expect($report['counts'][$band])->toBeGreaterThan(0, "No {$band}-band permit in the fixture.");
    }

    $byBusiness = array_column($report['at_risk'], null, 'business');

    // A permit not yet due must not be scored on renewal progress: there is
    // nothing it has failed to start.
    $notDue = collect($byBusiness['Not yet due']['drivers'])->firstWhere('rule', 'progress');
    expect($notDue)->toBeNull('A permit not yet due must not score on renewal progress.');

    $punctuality = collect($byBusiness['Punctuality 1/8']['drivers'])->firstWhere('rule', 'punctuality');
    expect($punctuality['points'])->toBe(2);

    expect($byBusiness['Unknown stage']['renewal_stage'])->toBe('cancelled');
    expect(collect($byBusiness['Unknown stage']['drivers'])->firstWhere('rule', 'progress')['points'])->toBe(25);

    // The rule score is a weighted ranking and is never presented as a
    // probability. Only the fitted model may claim that word.
    expect($report['methodology'])->toContain('not a probability');
});

it('covers the dashboard branches where a null and a zero are different things', function () {
    $report = goldenComputed('dashboard');

    $tiers = array_column($report['processing_tiers'], null, 'tier');

    expect($tiers['simple']['breaching'])->toBeTrue();
    expect($tiers['complex']['breaching'])->toBeFalse();

    // A tier with no decided filing has no mean. Reporting 0 working days would
    // read as instant service rather than as no evidence, and it must not count
    // as breaching a limit it has no measurement against.
    expect($tiers['highly_technical']['observations'])->toBe(0);
    expect($tiers['highly_technical']['mean_working_days'])->toBeNull();
    expect($tiers['highly_technical']['breaching'])->toBeFalse();

    expect($tiers['simple']['mean_working_days'])->toEqual(4.2);
    expect($tiers['complex']['mean_working_days'])->toEqual(6.5);

    // A filing is judged against two deadlines — RA 11032's statutory limit and
    // the longer one actually recorded — so the two rates cannot be equal here.
    expect($tiers['simple']['within_statutory'])->toBeLessThan($tiers['simple']['within_recorded_deadline']);
    expect($tiers['simple']['recorded_deadline_working_days'])
        ->toBeGreaterThan($tiers['simple']['statutory_working_days']);

    // 8/12 = 66.666… → 66.7. Pins the rounding, which is the single most likely
    // thing to move silently under a refactor.
    expect($report['decisions']['decisioned'])->toBe(12);
    expect($report['decisions']['approval_rate'])->toEqual(66.7);

    $compliance = array_column($report['compliance'], null, 'indicator');

    // Two different reasons for a null rate: nothing to measure yet (no reason
    // given, because none is needed) versus a denominator the register cannot
    // complete (reason given, and the denominator shown so the gap is visible).
    expect($compliance['permit_validity']['rate'])->toBeNull();
    expect($compliance['permit_validity']['unavailable_reason'])->toBeNull();

    expect($compliance['renewal']['rate'])->toBeNull();
    expect($compliance['renewal']['unavailable_reason'])->toContain('gap in the register');
    expect($compliance['renewal']['denominator'])->toBeGreaterThan(0);

    // Expiry windows nest, so their totals must be non-decreasing.
    $windows = array_column($report['expiry']['rows'], null, 'window');
    expect($windows['next_30d']['total'])->toBeLessThan($windows['next_60d']['total']);
    expect($windows['next_60d']['total'])->toBeLessThan($windows['next_90d']['total']);
    expect($windows['expired']['total'])->toBe(4);

    expect(array_slice(array_column($report['top_barangays']['rows'], 'barangay'), 0, 3))
        ->toBe(['Longos', 'Acacia', 'Bulacan']);

    // Shares are computed over the RECORDED rows only, so they sum to exactly
    // 100 even though a fifth of the businesses have no organisation form.
    expect($report['organization_forms']['recorded'])->toBe(20);
    expect($report['organization_forms']['unrecorded'])->toBe(5);
    expect(array_sum(array_column($report['organization_forms']['rows'], 'share')))->toEqual(100.0);

    // Scheduled but never completed: a pass rate of 0% would be a verdict, and
    // no inspection has returned one.
    $inspections = array_column($report['inspections']['rows'], null, 'type');
    expect($inspections['CPDO']['scheduled'])->toBeGreaterThan(0);
    expect($inspections['CPDO']['completed'])->toBe(0);
    expect($inspections['CPDO']['pass_rate'])->toBeNull();
    expect($inspections['CHO']['pass_rate'])->toEqual(87.5);

    expect($report['officer_activity']['median_response_hours'])->toEqual(2.8);

    // Six decimal places of latitude, not four. Truncating to four moves a pin
    // about 11 metres, which is the width of the street it is meant to be on.
    expect($report['map']['points'][0]['latitude'])->toEqual(14.662398);

    // A mappable point whose barangay is unknown still plots; it just cannot be
    // counted in the per-barangay rollup.
    expect($report['map']['points'][3]['barangay'])->toBeNull();
    expect($report['map']['plotted'])->toBe(4);
    expect(array_sum(array_column($report['map']['by_barangay'], 'businesses')))->toBe(3);
});

it('covers cohort survival including a cohort with nothing yet to measure', function () {
    $survival = goldenComputed('growth-lifecycle')['cohort_survival'];

    $cohorts = array_column($survival['cohorts'], null, 'cohort');

    expect($cohorts['2023']['points'][0]['survival'])->toEqual(80.0);
    expect($cohorts['2023']['points'][1]['at_risk'])->toBe(6);
    expect($cohorts['2023']['points'][1]['survival'])->toEqual(53.3);
    expect($cohorts['2024']['points'][0]['survival'])->toEqual(75.0);

    // A cohort too young to have reached its first renewal has no survival
    // figure at all — not 100%, which would read as everyone having renewed.
    expect($cohorts['2026']['max_cycle'])->toBe(0);
    expect($cohorts['2026']['survival'])->toBeNull();
    expect($cohorts['2026']['points'])->toBe([]);

    // Survival is cumulative and can only fall.
    $previous = 100.0;
    foreach ($survival['points'] as $point) {
        expect($point['survival'])->toBeLessThanOrEqual($previous);
        $previous = $point['survival'];
    }

    expect($survival['methodology'])->toContain('not a forecast');
});

it('covers the lifecycle branches that rank by change rather than by size', function () {
    $report = goldenComputed('growth-lifecycle');

    expect($report['growth_rate'])->toEqual(25.0);

    $barangays = array_column($report['top_barangays'], 'barangay');
    expect(array_slice($barangays, 0, 2))->toBe(['Acacia', 'Bulacan']);
    expect(array_column($report['top_barangays'], 'delta')[0])->toBe(6);

    // Growth from a prior of zero is undefined — not infinite, not 100%. The
    // barangay still ranks on its delta, which is the whole reason this ranking
    // is by change rather than by rate.
    $flores = collect($report['top_barangays'])->firstWhere('barangay', 'Flores');
    expect($flores['prior'])->toBe(0);
    expect($flores['growth_rate'])->toBeNull();
    expect($flores['delta'])->toBe(4);

    $directions = array_column($report['industry_growth'], 'direction');
    expect($directions)->toContain('growing');
    expect($directions)->toContain('declining');
    expect($directions)->toContain('steady');
});

<?php

use App\Services\RAnalytics;
use App\Support\BusinessGrowthAnalytics;
use App\Support\DashboardAnalytics;
use App\Support\ProcessingTimeAnalytics;
use App\Support\RenewalRiskAnalytics;

/*
 * R is the primary statistics engine and the PHP ports in App\Support are its
 * fallback (docs/r-integration-spec.md). Keeping two implementations of the same
 * statistics buys resilience and costs the risk that they drift apart while both
 * look correct — a screen would then show different numbers depending on whether
 * plumber happened to be up, and nothing would report it. This file is the thing
 * that stops that.
 *
 * The design is a golden file per dataset:
 *
 *   *.dataset.json    the row set Laravel pushes — the SAME input both engines see
 *   *.r-output.json   what R returned for it, captured from a live service
 *
 * The first test compares the PHP port against R's captured output and needs no R
 * installed, so it runs everywhere including CI. The second re-POSTs the fixture
 * to a live service and checks R still produces the golden file; it skips loudly
 * when plumber is not running, because a silent skip would let R drift with the
 * suite green.
 *
 * Both tests assert on values this codebase did not produce: the goldens come out
 * of R, over qcc 2.7 — the same version SpcTest's hand-checked constants were read
 * from.
 *
 * REGENERATING the goldens (do it deliberately, and read the diff):
 *
 *   # the dashboard and growth datasets are built by a script; the other two are
 *   # checked in by hand and should be edited, not regenerated
 *   php tests/fixtures/analytics/build-fixtures.php
 *
 *   cd r && Rscript run_api.R &
 *   cd api/tests/fixtures/analytics
 *   for f in processing-time:/spc/processing-time renewal-risk:/renewal-risk \
 *            dashboard:/dashboard growth-lifecycle:/growth/lifecycle; do
 *     name=${f%%:*}; ep=${f##*:}
 *     curl -s -X POST "http://127.0.0.1:8787$ep" -H 'Content-Type: application/json' \
 *       --data-binary @"$name.dataset.json" \
 *       | python3 -c 'import json,sys; json.dump(json.load(sys.stdin), sys.stdout, indent=2); print()' \
 *       > "$name.r-output.json"
 *   done
 *
 * WHAT THE FIXTURES DELIBERATELY COVER. Each was added because it is a branch
 * where two implementations plausibly disagree, and two of them already caught a
 * real divergence:
 *
 *   processing-time
 *     BPLO  charted, in control, real week-to-week variance
 *     CHO   charted, out of control — a 6-week ramp (generate.R's injected
 *           slowdown shape) that trips both the Shewhart limit and EWMA drift
 *     BFP   below the 3-completions-per-week minimum -> "thin", not charted
 *     ZON   enough weeks to chart but ZERO variance in the calibration window, so
 *           sigma is 0 and no limits can be fitted. PHP used to flag every week
 *           here as EWMA drift (spread 0, so any movement "breaches") while R
 *           flagged none. Both now decline to chart it.
 *     Rounding: one BPLO week lands on 2.5625 -> 2.562 half-to-even in R, 2.563
 *           under PHP's default rounding. See App\Support\Rounding.
 *
 *   renewal-risk
 *     Every edge of the expiry ladder (-14, 0, 1, 7, 15, 30, 60, 90, 120 days),
 *     every renewal stage, the not-yet-due gate that keeps the Low band from
 *     emptying, an unknown stage that must fall back to "none", the findings and
 *     fee bands, a null barangay, and two punctuality rows on exact halves
 *     (1 of 8 late = 2.5 points) for the same rounding reason.
 *
 *   dashboard
 *     A tier over its statutory limit, one inside it, and one with NO decided
 *     filing at all — the last must give null means and `breaching: false` rather
 *     than a comparison against null. Two means land on exact halves (4.25, 6.5)
 *     for the rounding reason above. The statutory and the recorded deadline are
 *     deliberately different yardsticks (3 days vs a flat 10) because conflating
 *     them once produced a "99% RA 11032 compliant" figure sitting beside a breach
 *     flag on the same filings. An approval rate whose denominator excludes
 *     pending. Three compliance shapes: computable, empty denominator, and a
 *     numerator the register cannot establish (null WITH a reason, never 0%).
 *     Cumulative expiry windows with a permit in each band. Ranking ties that must
 *     break on name. Organization forms partly recorded, so shares are of the
 *     recorded subset. An inspection type scheduled but never completed, whose pass
 *     rate must be null and not 0%. An even count of officer latencies, so the
 *     median is a midpoint. Six-decimal coordinates, which jsonlite silently
 *     truncated to four until the serializer was told otherwise — about 11 m of
 *     drift, and a real divergence the fixture caught. A map point with no
 *     barangay. Three offices tied on BOTH mean and volume, which is what made the
 *     two engines order the stage table differently until a code tie-break was
 *     added to both.
 *
 *   growth-lifecycle
 *     A Kaplan-Meier curve whose censoring changes the answer: 2 of 10 lapse at
 *     cycle 1 and 2 of the SIX who reached cycle 2 lapse there, so S(2) is 53.3%
 *     and not the 60% a plain ratio over the original 10 would give. A cohort that
 *     has not reached a first renewal at all, which must report null rather than
 *     0% (libel) or 100% (flattery). A barangay with an empty prior period, whose
 *     rate is null while its delta stays a number. Barangay and industry ties that
 *     break on name and PSIC code. All three industry directions.
 *
 * The datasets are generated arithmetically, never with an RNG, so they are
 * byte-stable across machines and R runs.
 */

const PARITY_TOLERANCE = 1e-6;

/** @return list<array{0: string, 1: callable, 2: string}> */
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

/**
 * Every difference between two payloads, as readable paths.
 *
 * Compares structure as well as values: a key present in one and not the other is
 * a difference, and list positions are compared, so a reordering is caught too.
 * Numbers compare within a tolerance because they crossed a JSON boundary; strings
 * and booleans must match exactly, since those are the labels a screen prints.
 *
 * @return list<string>
 */
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

/* ── the PHP fallback reproduces R ────────────────────────────────────────── */

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

/* ── the fixtures actually exercise the interesting branches ──────────────── */

it('covers charted, thin, unchartable and flagged departments', function () {
    $report = parityFixture('processing-time.r-output.json');

    expect(array_column($report['departments'], 'code'))->toBe(['BPLO', 'CHO']);

    $thin = array_column($report['thin'], 'reason', 'code');
    expect($thin)->toHaveKeys(['BFP', 'ZON']);
    expect($thin['BFP'])->toContain('completed reviews');
    // The zero-variance case is the one that already caught a divergence.
    expect($thin['ZON'])->toContain('did not vary');

    $byCode = array_column($report['departments'], null, 'code');
    expect($byCode['BPLO']['status'])->toBe('inside');
    expect($byCode['BPLO']['flagged'])->toBe([]);
    expect($byCode['BPLO']['sigma'])->toBeGreaterThan(0.0);

    // The injected slowdown must be caught by both rules, not just one.
    expect($byCode['CHO']['status'])->toBe('outside');
    expect(count($byCode['CHO']['flagged']))->toBeGreaterThan(0);
    expect($byCode['CHO']['trend']['direction'])->toBe('rising');
    expect($byCode['CHO']['trend']['drift_flagged'])->toBeTrue();
});

it('covers every renewal-risk band and compares every scored permit', function () {
    $report = parityFixture('renewal-risk.r-output.json');

    // No permit may be left out of the row-by-row comparison.
    expect(count($report['at_risk']))->toBe($report['scored_permits']);

    foreach (['high', 'moderate', 'low'] as $band) {
        expect($report['counts'][$band])->toBeGreaterThan(0, "No {$band}-band permit in the fixture.");
    }

    $byBusiness = array_column($report['at_risk'], null, 'business');

    // The gate that stops every permit in the register scoring at least Moderate.
    $notDue = collect($byBusiness['Not yet due']['drivers'])->firstWhere('rule', 'progress');
    expect($notDue)->toBeNull('A permit not yet due must not score on renewal progress.');

    // Half-to-even: 1 late of 8 is 2.5 points, which rounds to 2, not 3.
    $punctuality = collect($byBusiness['Punctuality 1/8']['drivers'])->firstWhere('rule', 'punctuality');
    expect($punctuality['points'])->toBe(2);

    // An unrecognised stage falls back to "none" rather than scoring nothing.
    expect($byBusiness['Unknown stage']['renewal_stage'])->toBe('cancelled');
    expect(collect($byBusiness['Unknown stage']['drivers'])->firstWhere('rule', 'progress')['points'])->toBe(25);

    // The number must never be presented as a probability.
    expect($report['methodology'])->toContain('not a probability');
});

it('covers the dashboard branches where a null and a zero are different things', function () {
    $report = parityFixture('dashboard.r-output.json');

    $tiers = array_column($report['processing_tiers'], null, 'tier');

    // A tier over its statutory limit, and one inside it, so `breaching` is
    // exercised both ways rather than being constant across the fixture.
    expect($tiers['simple']['breaching'])->toBeTrue();
    expect($tiers['complex']['breaching'])->toBeFalse();

    // A tier with NO observations: null means, and breaching false rather than a
    // comparison against null that would quietly become true.
    expect($tiers['highly_technical']['observations'])->toBe(0);
    expect($tiers['highly_technical']['mean_working_days'])->toBeNull();
    expect($tiers['highly_technical']['breaching'])->toBeFalse();

    // Half-to-even: 3+4+5+5 over 4 is 4.25, which rounds to 4.2 and not 4.3.
    expect($tiers['simple']['mean_working_days'])->toEqual(4.2);
    expect($tiers['complex']['mean_working_days'])->toEqual(6.5);

    // The statutory and the recorded deadline are different yardsticks and must
    // not be conflated: the same filings pass one far more often than the other.
    expect($tiers['simple']['within_statutory'])->toBeLessThan($tiers['simple']['within_recorded_deadline']);
    expect($tiers['simple']['recorded_deadline_working_days'])
        ->toBeGreaterThan($tiers['simple']['statutory_working_days']);

    // Approval rate excludes pending: 8 of 12 decisioned is 66.7%, not 8 of 16.
    expect($report['decisions']['decisioned'])->toBe(12);
    expect($report['decisions']['approval_rate'])->toEqual(66.7);

    $compliance = array_column($report['compliance'], null, 'indicator');

    // An empty denominator gives a null rate and NO reason — nothing needs saying.
    expect($compliance['permit_validity']['rate'])->toBeNull();
    expect($compliance['permit_validity']['unavailable_reason'])->toBeNull();

    // A numerator the register cannot establish gives a null rate WITH a reason.
    // This is the case that must never render as 0%, because 0% reads as a
    // compliance failure rather than a missing link in the data.
    expect($compliance['renewal']['rate'])->toBeNull();
    expect($compliance['renewal']['unavailable_reason'])->toContain('gap in the register');
    expect($compliance['renewal']['denominator'])->toBeGreaterThan(0);

    // Cumulative expiry windows: 30d is a subset of 60d is a subset of 90d.
    $windows = array_column($report['expiry']['rows'], null, 'window');
    expect($windows['next_30d']['total'])->toBeLessThan($windows['next_60d']['total']);
    expect($windows['next_60d']['total'])->toBeLessThan($windows['next_90d']['total']);
    // Expired is disjoint from the three forward windows.
    expect($windows['expired']['total'])->toBe(4);

    // Equal counts break on name, so a refresh cannot reshuffle the ranking:
    // Acacia ties Bulacan on 4 and must come first despite being listed second.
    $barangays = array_column($report['top_barangays']['rows'], 'barangay');
    expect(array_slice($barangays, 0, 3))->toBe(['Longos', 'Acacia', 'Bulacan']);

    // Shares are of the RECORDED forms, not the total, or the rows do not sum to
    // 100% when part of the column is blank.
    expect($report['organization_forms']['recorded'])->toBe(20);
    expect($report['organization_forms']['unrecorded'])->toBe(5);
    expect(array_sum(array_column($report['organization_forms']['rows'], 'share')))->toEqual(100.0);

    // Pass rate is over COMPLETED. A type scheduled but never completed has no
    // rate; dividing by scheduled would have reported 0%.
    $inspections = array_column($report['inspections']['rows'], null, 'type');
    expect($inspections['CPDO']['scheduled'])->toBeGreaterThan(0);
    expect($inspections['CPDO']['completed'])->toBe(0);
    expect($inspections['CPDO']['pass_rate'])->toBeNull();
    expect($inspections['CHO']['pass_rate'])->toEqual(87.5);

    // An even number of latencies: the median is the midpoint of the two central
    // values, 2.0 and 3.5, which is 2.75 and rounds to 2.8.
    expect($report['officer_activity']['median_response_hours'])->toEqual(2.8);

    // Six decimal places survive the trip. jsonlite defaults to four, which
    // silently moved every map pin by about eleven metres until the serializer
    // was told otherwise.
    expect($report['map']['points'][0]['latitude'])->toEqual(14.662398);

    // A point with no barangay still counts as plotted but cannot join the
    // per-barangay aggregation.
    expect($report['map']['points'][3]['barangay'])->toBeNull();
    expect($report['map']['plotted'])->toBe(4);
    expect(array_sum(array_column($report['map']['by_barangay'], 'businesses')))->toBe(3);
});

it('covers cohort survival including a cohort with nothing yet to measure', function () {
    $survival = parityFixture('growth-lifecycle.r-output.json')['cohort_survival'];

    $cohorts = array_column($survival['cohorts'], null, 'cohort');

    // Kaplan-Meier by hand: 2 of 10 lapse at cycle 1 so S(1) = 0.8, then 2 of the
    // 6 who reached cycle 2 lapse so S(2) = 0.8 * (1 - 2/6) = 53.3%. Censoring is
    // what makes the second denominator 6 and not 10 — a plain ratio would give a
    // different and wrong answer here, which is the point of the whole measure.
    expect($cohorts['2023']['points'][0]['survival'])->toEqual(80.0);
    expect($cohorts['2023']['points'][1]['at_risk'])->toBe(6);
    expect($cohorts['2023']['points'][1]['survival'])->toEqual(53.3);

    expect($cohorts['2024']['points'][0]['survival'])->toEqual(75.0);

    // The guard the spec asks for: a cohort that has not reached a first renewal
    // has NO survival rate. Not 0%, which would libel businesses that have simply
    // not had a renewal yet, and not 100%, which would flatter them.
    expect($cohorts['2026']['max_cycle'])->toBe(0);
    expect($cohorts['2026']['survival'])->toBeNull();
    expect($cohorts['2026']['points'])->toBe([]);

    // The curve never rises: it is a running product of terms at most 1.
    $previous = 100.0;
    foreach ($survival['points'] as $point) {
        expect($point['survival'])->toBeLessThanOrEqual($previous);
        $previous = $point['survival'];
    }

    // The figure must not be sold as a forecast.
    expect($survival['methodology'])->toContain('not a forecast');
});

it('covers the lifecycle branches that rank by change rather than by size', function () {
    $report = parityFixture('growth-lifecycle.r-output.json');

    expect($report['growth_rate'])->toEqual(25.0);

    // Ranked by the INCREASE, not the volume: Longos has the most registrations
    // but the smallest gain, so it must not lead. Acacia ties Bulacan on +6 and
    // breaks the tie on name despite being listed second in the dataset.
    $barangays = array_column($report['top_barangays'], 'barangay');
    expect(array_slice($barangays, 0, 2))->toBe(['Acacia', 'Bulacan']);
    expect(array_column($report['top_barangays'], 'delta')[0])->toBe(6);

    // An empty prior period leaves the rate null while the delta stays a number.
    $flores = collect($report['top_barangays'])->firstWhere('barangay', 'Flores');
    expect($flores['prior'])->toBe(0);
    expect($flores['growth_rate'])->toBeNull();
    expect($flores['delta'])->toBe(4);

    // All three directions are exercised, so none of them is constant.
    $directions = array_column($report['industry_growth'], 'direction');
    expect($directions)->toContain('growing');
    expect($directions)->toContain('declining');
    expect($directions)->toContain('steady');
});

/* ── R has not drifted from the golden files ──────────────────────────────── */

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

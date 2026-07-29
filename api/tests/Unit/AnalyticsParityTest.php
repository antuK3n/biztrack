<?php

use App\Services\RAnalytics;
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
 *   cd r && Rscript run_api.R &
 *   cd api/tests/fixtures/analytics
 *   for f in processing-time renewal-risk; do
 *     ep=$([ "$f" = processing-time ] && echo /spc/processing-time || echo /renewal-risk)
 *     curl -s -X POST "http://127.0.0.1:8787$ep" -H 'Content-Type: application/json' \
 *       --data-binary @"$f.dataset.json" \
 *       | python3 -c 'import json,sys; json.dump(json.load(sys.stdin), sys.stdout, indent=2); print()' \
 *       > "$f.r-output.json"
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

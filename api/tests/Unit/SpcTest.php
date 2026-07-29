<?php

use App\Support\Spc;

/*
 * The PHP port of r/R/spc.R has to agree with the R it replaces, so every
 * expectation below is an external value — either hand-computed from the
 * formula or read off qcc 2.7 — never a number this implementation produced.
 *
 * qcc reference run (r/ is untouched; this was a throwaway console session):
 *
 *   q <- qcc::qcc(rep(c(2, 3), 6), type = "xbar.one", plot = FALSE)
 *   q$center   # 2.5
 *   q$std.dev  # 0.886524822695036
 *   q$limits   # LCL -0.159574468085107, UCL 5.15957446808511
 *
 *   qcc::ewma(c(1, 1, 1), center = 0, std.dev = 1, plot = FALSE)
 *   $y      # 0.2, 0.36, 0.488
 *   $limits # UCL 0.6, 0.768374908491942, 0.858985448072317
 */

const EPS = 1e-9;

/* ── weekly bucketing ─────────────────────────────────────────────────── */

it('buckets completions into Monday-start ISO weeks by completion date', function () {
    // 2026-01-14 is a Wednesday; its ISO week starts Monday 2026-01-12.
    $reviews = [
        ['department_code' => 'BPLO', 'assigned_at' => '2026-01-12 00:00:00', 'completed_at' => '2026-01-14 00:00:00'],
        ['department_code' => 'BPLO', 'assigned_at' => '2026-01-13 00:00:00', 'completed_at' => '2026-01-17 00:00:00'],
        ['department_code' => 'BPLO', 'assigned_at' => '2026-01-15 00:00:00', 'completed_at' => '2026-01-18 00:00:00'],
    ];

    $weekly = Spc::weeklyTurnaround($reviews);

    expect($weekly)->toHaveCount(1);
    // 2026-01-18 is a Sunday, still inside the week that started 2026-01-12.
    expect($weekly[0]['week_start'])->toBe('2026-01-12');
    expect($weekly[0]['n'])->toBe(3);
    // (2 + 4 + 3) / 3 = 3
    expect($weekly[0]['mean_days'])->toBeGreaterThan(3 - EPS)->toBeLessThan(3 + EPS);
});

it('drops weeks with fewer than three completed reviews', function () {
    $reviews = [
        // Week of 2026-02-02: only two completions, so the mean is not trusted.
        ['department_code' => 'CHO', 'assigned_at' => '2026-02-02 00:00:00', 'completed_at' => '2026-02-03 00:00:00'],
        ['department_code' => 'CHO', 'assigned_at' => '2026-02-02 00:00:00', 'completed_at' => '2026-02-04 00:00:00'],
        // Week of 2026-02-09: three completions, kept.
        ['department_code' => 'CHO', 'assigned_at' => '2026-02-09 00:00:00', 'completed_at' => '2026-02-10 00:00:00'],
        ['department_code' => 'CHO', 'assigned_at' => '2026-02-09 00:00:00', 'completed_at' => '2026-02-11 00:00:00'],
        ['department_code' => 'CHO', 'assigned_at' => '2026-02-09 00:00:00', 'completed_at' => '2026-02-12 00:00:00'],
    ];

    $weekly = Spc::weeklyTurnaround($reviews);

    expect($weekly)->toHaveCount(1);
    expect($weekly[0]['week_start'])->toBe('2026-02-09');
    expect($weekly[0]['n'])->toBe(3);
});

it('ignores reviews that were never assigned or never completed', function () {
    $reviews = [
        ['department_code' => 'BFP', 'assigned_at' => '2026-03-02 00:00:00', 'completed_at' => null],
        ['department_code' => 'BFP', 'assigned_at' => null, 'completed_at' => '2026-03-03 00:00:00'],
        ['department_code' => 'BFP', 'assigned_at' => '2026-03-02 00:00:00', 'completed_at' => '2026-03-04 00:00:00'],
    ];

    expect(Spc::weeklyTurnaround($reviews))->toBe([]);
});

it('measures turnaround in fractional days', function () {
    // 36 hours = 1.5 days, three times over.
    $reviews = array_map(fn (int $day) => [
        'department_code' => 'OBO',
        'assigned_at' => sprintf('2026-04-%02d 06:00:00', $day),
        'completed_at' => sprintf('2026-04-%02d 18:00:00', $day + 1),
    ], [6, 7, 8]);

    $weekly = Spc::weeklyTurnaround($reviews);

    expect($weekly[0]['mean_days'])->toBeGreaterThan(1.5 - EPS)->toBeLessThan(1.5 + EPS);
});

/* ── control limits ───────────────────────────────────────────────────── */

it('matches qcc xbar.one limits for an alternating series', function () {
    // Hand check: mean = 2.5; every moving range is 1 so mean MR = 1;
    // sigma = 1 / 1.128 = 0.8865248226950355; UCL = 2.5 + 3 * sigma.
    $limits = Spc::controlLimits(array_merge(...array_fill(0, 6, [2.0, 3.0])));

    expect($limits['center'])->toBeGreaterThan(2.5 - EPS)->toBeLessThan(2.5 + EPS);
    expect($limits['sigma'])->toBeGreaterThan(0.886524822695036 - EPS)
        ->toBeLessThan(0.886524822695036 + EPS);
    expect($limits['ucl'])->toBeGreaterThan(5.15957446808511 - EPS)
        ->toBeLessThan(5.15957446808511 + EPS);
    // qcc reports LCL -0.159574468085107; spc.R clamps it with max(0, .).
    expect($limits['lcl'])->toBe(0.0);
    expect($limits['calibration_weeks'])->toBe(12);
});

it('divides the summed moving ranges by their own count, not the series length', function () {
    // qcc::qcc(c(1, 2, 4), type = "xbar.one")$std.dev == 1.32978723404255
    // Hand check: MRs are 1 and 2, mean 1.5, 1.5 / 1.128 = 1.3297872340425532.
    $limits = Spc::controlLimits([1.0, 2.0, 4.0]);

    expect($limits['sigma'])->toBeGreaterThan(1.32978723404255 - EPS)
        ->toBeLessThan(1.32978723404255 + EPS);
    // center 7/3 = 2.3333333; UCL = center + 3 * sigma = 6.32269503546099 (qcc).
    expect($limits['ucl'])->toBeGreaterThan(6.32269503546099 - EPS)
        ->toBeLessThan(6.32269503546099 + EPS);
});

it('fits the limits on the first 24 weeks so a late slowdown cannot widen them', function () {
    // 24 calm weeks, then six weeks of escalating turnaround.
    $values = array_merge(
        array_merge(...array_fill(0, 12, [2.0, 3.0])),
        [3.5, 4.0, 4.5, 5.0, 5.5, 6.0],
    );

    $limits = Spc::controlLimits($values);

    expect($limits['calibration_weeks'])->toBe(24);
    // Identical to the calm-only fit: the tail is excluded from calibration.
    expect($limits['ucl'])->toBeGreaterThan(5.15957446808511 - EPS)
        ->toBeLessThan(5.15957446808511 + EPS);
});

it('returns a flat fit when there is only one week to calibrate on', function () {
    $limits = Spc::controlLimits([4.0]);

    expect($limits['center'])->toBe(4.0);
    expect($limits['sigma'])->toBe(0.0);
    expect($limits['ucl'])->toBe(4.0);
    expect($limits['lcl'])->toBe(4.0);
});

/* ── EWMA ─────────────────────────────────────────────────────────────── */

it('matches qcc ewma smoothing and its widening limits', function () {
    // qcc::ewma(c(1,1,1), center = 0, std.dev = 1) — y and UCL, verbatim.
    $ewma = Spc::ewma([1.0, 1.0, 1.0], center: 0.0, stdDev: 1.0);

    foreach ([0.2, 0.36, 0.488] as $i => $expected) {
        expect($ewma['z'][$i])->toBeGreaterThan($expected - EPS)->toBeLessThan($expected + EPS);
    }
    foreach ([0.6, 0.768374908491942, 0.858985448072317] as $i => $expected) {
        expect($ewma['ucl'][$i])->toBeGreaterThan($expected - EPS)->toBeLessThan($expected + EPS);
    }
    expect($ewma['violations'])->toBe([]);
});

it('flags every point when the series jumps clear of the EWMA band', function () {
    // qcc::ewma(c(10,10,10), center = 0, std.dev = 1)$violations == 1 2 3 (1-based).
    $ewma = Spc::ewma([10.0, 10.0, 10.0], center: 0.0, stdDev: 1.0);

    foreach ([2.0, 3.6, 4.88] as $i => $expected) {
        expect($ewma['z'][$i])->toBeGreaterThan($expected - EPS)->toBeLessThan($expected + EPS);
    }
    expect($ewma['violations'])->toBe([0, 1, 2]);
});

it('computes the sample standard deviation with an n-1 denominator', function () {
    // 12 twos and 12 threes: var = 6/23, sd = 0.5107539184552492.
    $sd = Spc::sampleStdDev(array_merge(...array_fill(0, 12, [2.0, 3.0])));

    expect($sd)->toBeGreaterThan(0.5107539184552492 - EPS)
        ->toBeLessThan(0.5107539184552492 + EPS);
    expect(Spc::sampleStdDev([7.0]))->toBe(0.0);
});

/* ── the CHO story: an injected slowdown must be caught ───────────────── */

it('catches an injected slowdown, and the EWMA catches it before the control limit does', function () {
    // Mirrors what generate.R injects into CHO: a calm baseline, then a run of
    // small increases. qcc on this series reports beyond-limit weeks 29 and 30
    // and EWMA violations 27, 28, 29, 30 (1-based) — so weeks 27 and 28 are
    // drift-only, which is the whole point of keeping the EWMA pass.
    $weeks = [];
    $values = array_merge(
        array_merge(...array_fill(0, 12, [2.0, 3.0])),
        [3.5, 4.0, 4.5, 5.0, 5.5, 6.0],
    );
    foreach ($values as $i => $mean) {
        $weeks[] = [
            'week_start' => \Carbon\CarbonImmutable::parse('2026-01-05')->addWeeks($i)->toDateString(),
            'n' => 5,
            'mean_days' => $mean,
        ];
    }

    $result = Spc::analyse($weeks);

    $rules = array_column($result['weeks'], 'rule_hit');
    // Zero-based: qcc's 27,28,29,30 are indices 26,27,28,29.
    expect($rules[26])->toBe('ewma_drift');
    expect($rules[27])->toBe('ewma_drift');
    expect($rules[28])->toBe('beyond_limits+ewma_drift');
    expect($rules[29])->toBe('beyond_limits+ewma_drift');

    $outOfControl = array_keys(array_filter(
        array_column($result['weeks'], 'status'),
        fn (string $s) => $s === 'out_of_control',
    ));
    expect($outOfControl)->toBe([26, 27, 28, 29]);

    // Nothing in the calm stretch is flagged.
    expect(array_slice($rules, 0, 26))->toBe(array_fill(0, 26, null));

    // The weighted-trend bar reads Rising and is pinned at full magnitude.
    expect($result['trend']['direction'])->toBe('rising');
    expect($result['trend']['magnitude'])->toBe(1.0);
    expect($result['trend']['drift_flagged'])->toBeTrue();
});

it('leaves a stable department in control and reads its trend as steady', function () {
    $weeks = [];
    foreach (array_merge(...array_fill(0, 15, [2.0, 3.0])) as $i => $mean) {
        $weeks[] = [
            'week_start' => \Carbon\CarbonImmutable::parse('2026-01-05')->addWeeks($i)->toDateString(),
            'n' => 4,
            'mean_days' => $mean,
        ];
    }

    $result = Spc::analyse($weeks);

    expect(array_column($result['weeks'], 'rule_hit'))->toBe(array_fill(0, 30, null));
    expect($result['trend']['direction'])->toBe('steady');
    expect($result['trend']['drift_flagged'])->toBeFalse();
});

it('reports the signed deviation of each week from the centre line', function () {
    $weeks = [
        ['week_start' => '2026-01-05', 'n' => 3, 'mean_days' => 2.0],
        ['week_start' => '2026-01-12', 'n' => 3, 'mean_days' => 4.0],
    ];

    $result = Spc::analyse($weeks);

    // center = 3; deviations are -1 and +1.
    expect($result['weeks'][0]['deviation_days'])->toBe(-1.0);
    expect($result['weeks'][1]['deviation_days'])->toBe(1.0);
});

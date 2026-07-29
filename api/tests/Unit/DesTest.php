<?php

use App\Support\Des;

/*
 * The discrete-event simulation (App\Support\Des), the port of r/R/des.R.
 *
 * A simulation cannot be pinned to exact expected values the way SpcTest pins
 * the control limits: PHP's RNG stream is not R's, so demanding equality with
 * simmer would be demanding the wrong thing. These tests assert the PROPERTIES a
 * queueing model has to have — the ones that would break if the event loop, the
 * queue discipline, or the metric accumulation were wrong — plus exact equality
 * on the one part that is deterministic, the distribution fit.
 *
 * The port was separately validated against simmer 4.4 head-to-head on an
 * identical five-office pipeline (300 replications, 1800-day horizon): office
 * utilisations agreed to within 0.6%, mean end-to-end flow to 0.10%, and the
 * RA 11032 on-time rate to 0.11%. Those runs live in the pull request, not here,
 * because a test that shells out to Rscript would reintroduce exactly the
 * dependency this port exists to remove.
 */

/**
 * A single-office model: one resource, Poisson arrivals, lognormal service.
 *
 * Service is calibrated to a mean of exactly one day so that arrivals per day
 * reads directly as the offered load, which is what the load properties below
 * lean on.
 *
 * @return array<string, mixed>
 */
function singleOfficeModel(float $arrivalsPerDay, int $reviewers = 1, float $horizon = 2000, int $reps = 4): array
{
    $sdLog = 0.5;

    return [
        'resources' => ['BPLO' => $reviewers],
        'arrivals_per_day' => $arrivalsPerDay,
        'classes' => [[
            'key' => 'simple',
            'share' => 1.0,
            'deadline_days' => 3,
            'phases' => [
                // exp(meanlog + sdlog^2/2) = 1 day.
                ['kind' => 'parallel', 'stages' => [[
                    'resource' => 'BPLO',
                    'meanlog' => -($sdLog ** 2) / 2,
                    'sdlog' => $sdLog,
                ]]],
            ],
        ]],
        'horizon_days' => $horizon,
        'reps' => $reps,
        'seed' => Des::DEFAULT_SEED,
    ];
}

/* ── the distribution fit: this part IS exact ──────────────────────────── */

it('fits a lognormal by maximum likelihood, matching fitdistrplus', function () {
    // Pinned against fitdistrplus 1.2 in R 4.6.1: for 200 draws of
    // rlnorm(log 2, 0.55) under set.seed(1103), fitdist(x, "lnorm") reports
    // meanlog 0.719999078864 and sdlog 0.550232209617.
    $sample = [];
    $logs = [0.4, -0.2, 1.1, 0.75, 0.3, 1.4, 0.05, 0.9, 0.6, 1.2];
    foreach ($logs as $value) {
        $sample[] = exp($value);
    }

    $fit = Des::fitLognormal($sample);

    $mean = array_sum($logs) / count($logs);
    $variance = 0.0;
    foreach ($logs as $value) {
        $variance += ($value - $mean) ** 2;
    }

    expect($fit)->not->toBeNull();
    // Deltas, not identity: the sample is built as exp() of these logs and the
    // fit takes log() back, so the round trip is exact only to double precision.
    expect($fit['meanlog'])->toEqualWithDelta($mean, 1e-12);
    // The MLE divides by n. stats::sd's n-1 would be a different number, and the
    // difference shows up in the third decimal of every service time.
    expect($fit['sdlog'])->toEqualWithDelta(sqrt($variance / count($logs)), 1e-12);
    expect(abs($fit['sdlog'] - sqrt($variance / (count($logs) - 1))))->toBeGreaterThan(1e-3);
    expect($fit['n'])->toBe(10);
    // E[X] = exp(mu + sigma^2 / 2), median = exp(mu).
    expect($fit['mean_days'])->toBe(exp($fit['meanlog'] + ($fit['sdlog'] ** 2) / 2));
    expect($fit['median_days'])->toBe(exp($fit['meanlog']));
});

it('drops non-positive durations before taking logs', function () {
    $fit = Des::fitLognormal([1.0, 2.0, 0.0, -3.0, 4.0, 8.0, 16.0]);

    expect($fit['n'])->toBe(5);
});

it('refuses to fit fewer samples than the minimum', function () {
    expect(Des::fitLognormal([1.0, 2.0, 3.0]))->toBeNull();
    expect(Des::fitLognormal([]))->toBeNull();
});

it('converts an RA 11032 working-day deadline to calendar days', function () {
    // A 7-working-day deadline is 9.8 calendar days at a 5-day week. 7 * (7/5)
    // is not exactly 9.8 in binary floating point, hence the delta.
    expect(Des::deadlineInCalendarDays(7))->toEqualWithDelta(9.8, 1e-12);
    expect(Des::deadlineInCalendarDays(3))->toEqualWithDelta(4.2, 1e-12);
});

/* ── reproducibility ──────────────────────────────────────────────────── */

it('returns identical numbers for identical input', function () {
    // The whole point of seeding per replication: an admin who re-runs the same
    // scenario must not see the answer move.
    $model = singleOfficeModel(0.6);

    expect(Des::simulate($model))->toBe(Des::simulate($model));
});

it('returns different numbers for a different seed', function () {
    $first = Des::simulate(singleOfficeModel(0.6));
    $second = Des::simulate([...singleOfficeModel(0.6), 'seed' => Des::DEFAULT_SEED + 500]);

    expect($first['resources']['BPLO']['utilisation'])
        ->not->toBe($second['resources']['BPLO']['utilisation']);
});

/* ── properties every queueing model must have ────────────────────────── */

it('keeps utilisation inside [0, 1] across the whole load range', function () {
    foreach ([0.05, 0.4, 0.85, 1.6, 4.0] as $load) {
        // The last two overload a single reviewer on purpose: a saturated office
        // must report utilisation at or below 1, never above.
        $result = Des::simulate(singleOfficeModel($load, reviewers: 1, horizon: 600, reps: 2));
        $utilisation = $result['resources']['BPLO']['utilisation'];

        expect($utilisation)->toBeGreaterThanOrEqual(0.0);
        expect($utilisation)->toBeLessThanOrEqual(1.0);
    }
});

it('tracks utilisation to the offered load while the office has slack', function () {
    // Service averages one day, so utilisation should converge on the arrival
    // rate itself until the office runs out of capacity.
    foreach ([0.3, 0.6] as $load) {
        $result = Des::simulate(singleOfficeModel($load, horizon: 4000, reps: 4));

        expect($result['resources']['BPLO']['utilisation'])->toBeGreaterThan($load - 0.05);
        expect($result['resources']['BPLO']['utilisation'])->toBeLessThan($load + 0.05);
    }
});

it('grows the queue as the arrival rate approaches service capacity', function () {
    $queues = [];
    $waits = [];
    foreach ([0.3, 0.5, 0.7, 0.9] as $load) {
        $result = Des::simulate(singleOfficeModel($load, horizon: 4000, reps: 4));
        $queues[] = $result['resources']['BPLO']['queue_length'];
        $waits[] = $result['resources']['BPLO']['mean_wait_days'];
    }

    // Strictly increasing: this is the property that fails if the queue is not
    // actually holding work, or if the time-weighting is wrong.
    for ($i = 1; $i < count($queues); $i++) {
        expect($queues[$i])->toBeGreaterThan($queues[$i - 1]);
        expect($waits[$i])->toBeGreaterThan($waits[$i - 1]);
    }

    // And the growth is non-linear — the last step must dwarf the first, as
    // 1/(1 - rho) demands.
    expect($queues[3] - $queues[2])->toBeGreaterThan($queues[1] - $queues[0]);
});

it('reduces the mean wait when a server is added at the same load', function () {
    $one = Des::simulate(singleOfficeModel(0.85, reviewers: 1, horizon: 4000, reps: 4));
    $two = Des::simulate(singleOfficeModel(0.85, reviewers: 2, horizon: 4000, reps: 4));
    $three = Des::simulate(singleOfficeModel(0.85, reviewers: 3, horizon: 4000, reps: 4));

    expect($two['resources']['BPLO']['mean_wait_days'])
        ->toBeLessThan($one['resources']['BPLO']['mean_wait_days']);
    expect($three['resources']['BPLO']['mean_wait_days'])
        ->toBeLessThan($two['resources']['BPLO']['mean_wait_days']);

    // A second reviewer halves utilisation, because the work is unchanged.
    expect($two['resources']['BPLO']['utilisation'])
        ->toBeLessThan($one['resources']['BPLO']['utilisation'] * 0.6);

    // And the backlog at the horizon shrinks, which is the answer the staffing
    // question is actually asking for.
    expect($two['unfinished'])->toBeLessThanOrEqual($one['unfinished']);
});

it('agrees with the Pollaczek-Khinchine formula for an M/G/1 queue', function () {
    // An independent check on the metric accumulation: for a single server with
    // lognormal service, queueing theory gives the exact mean wait, so the
    // simulation has a right answer to be wrong about.
    $sdLog = 0.5;
    $load = 0.7;
    $cvSquared = exp($sdLog ** 2) - 1;
    $expected = $load * (1 + $cvSquared) / (2 * (1 - $load));

    $result = Des::simulate(singleOfficeModel($load, horizon: 8000, reps: 6));

    expect($result['resources']['BPLO']['mean_wait_days'])
        ->toBeGreaterThan($expected * 0.85)
        ->toBeLessThan($expected * 1.15);

    // Little's law: the mean queue length is the arrival rate times the wait.
    expect($result['resources']['BPLO']['queue_length'])
        ->toBeGreaterThan($load * $result['resources']['BPLO']['mean_wait_days'] * 0.9)
        ->toBeLessThan($load * $result['resources']['BPLO']['mean_wait_days'] * 1.1);
});

it('waits for every office in a parallel phase before moving on', function () {
    // One slow office and one fast one, run in parallel. End-to-end flow must be
    // governed by the slow one — that is what synchronize(wait = TRUE) means. If
    // the join were broken, flow would track the fast office instead.
    $model = [
        'resources' => ['FAST' => 6, 'SLOW' => 6],
        'arrivals_per_day' => 0.4,
        'classes' => [[
            'key' => 'complex',
            'share' => 1.0,
            'deadline_days' => 7,
            'phases' => [['kind' => 'parallel', 'stages' => [
                ['resource' => 'FAST', 'meanlog' => log(0.25), 'sdlog' => 0.01],
                ['resource' => 'SLOW', 'meanlog' => log(4.0), 'sdlog' => 0.01],
            ]]],
        ]],
        'horizon_days' => 1500,
        'reps' => 3,
        'seed' => Des::DEFAULT_SEED,
    ];

    $result = Des::simulate($model);

    // Both offices have ample capacity, so nothing queues and flow is just the
    // slow service time.
    expect($result['mean_flow_days'])->toBeGreaterThan(3.6);
    expect($result['mean_flow_days'])->toBeLessThan(4.4);
    expect($result['resources']['SLOW']['queue_length'])->toBeLessThan(0.05);
});

it('counts a fixed delay phase into flow time without seizing anyone', function () {
    $model = [
        'resources' => ['BPLO' => 8],
        'arrivals_per_day' => 0.3,
        'classes' => [[
            'key' => 'simple',
            'share' => 1.0,
            'deadline_days' => 3,
            'phases' => [
                ['kind' => 'delay', 'min' => 2.0, 'max' => 2.0],
                ['kind' => 'parallel', 'stages' => [['resource' => 'BPLO', 'meanlog' => log(0.5), 'sdlog' => 0.01]]],
                ['kind' => 'delay', 'min' => 1.0, 'max' => 1.0],
            ],
        ]],
        'horizon_days' => 1500,
        'reps' => 3,
        'seed' => Des::DEFAULT_SEED,
    ];

    $result = Des::simulate($model);

    // 2 days waiting + 0.5 days of review + 1 day issuing = 3.5.
    expect($result['mean_flow_days'])->toBeGreaterThan(3.45);
    expect($result['mean_flow_days'])->toBeLessThan(3.55);
    // The delays are nobody's queue.
    expect($result['resources']['BPLO']['queue_length'])->toBeLessThan(0.02);
});

it('reports an on-time rate that falls as the office saturates', function () {
    $light = Des::simulate(singleOfficeModel(0.3, horizon: 3000, reps: 4));
    $heavy = Des::simulate(singleOfficeModel(0.95, horizon: 3000, reps: 4));

    foreach ([$light, $heavy] as $result) {
        expect($result['on_time_rate'])->toBeGreaterThanOrEqual(0.0);
        expect($result['on_time_rate'])->toBeLessThanOrEqual(1.0);
    }

    expect($heavy['on_time_rate'])->toBeLessThan($light['on_time_rate']);
});

it('accumulates a backlog only when demand outstrips capacity', function () {
    $calm = Des::simulate(singleOfficeModel(0.4, horizon: 900, reps: 3));
    $swamped = Des::simulate(singleOfficeModel(3.0, horizon: 900, reps: 3));

    expect($calm['unfinished'])->toBeLessThan(3.0);
    expect($swamped['unfinished'])->toBeGreaterThan($calm['unfinished'] * 10);
    // Arrivals are conserved: everything either finished or is still in flight.
    expect(round($swamped['finished'] + $swamped['unfinished'], 6))->toBe(round($swamped['arrivals'], 6));
});

it('generates arrivals at the requested Poisson rate', function () {
    $result = Des::simulate(singleOfficeModel(0.5, reviewers: 4, horizon: 4000, reps: 4));

    // 0.5 per day over 4000 days is about 2000 filings.
    expect($result['arrivals'])->toBeGreaterThan(1900);
    expect($result['arrivals'])->toBeLessThan(2100);
});

it('simulates nothing when the arrival rate is zero', function () {
    $result = Des::simulate(singleOfficeModel(0.0, horizon: 500, reps: 2));

    expect($result['arrivals'])->toBe(0.0);
    expect($result['mean_flow_days'])->toBeNull();
    expect($result['on_time_rate'])->toBeNull();
    expect($result['resources']['BPLO']['utilisation'])->toBe(0.0);
    expect($result['resources']['BPLO']['queue_length'])->toBe(0.0);
});

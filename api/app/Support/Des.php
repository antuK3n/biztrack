<?php

namespace App\Support;

use Random\Engine\Mt19937;
use Random\IntervalBoundary;
use Random\Randomizer;

/**
 * Discrete-event simulation of the permit pipeline.
 *
 * This was ported to PHP from the project's original R prototype, `r/R/des.R`
 * (Feature 6), which drove a `simmer` model. R is no longer part of the project
 * — this class is the only implementation. The two sections below are kept as
 * the record of what the port decided: they document behaviour this code still
 * has, and the divergence list is why the numbers here are not the numbers the
 * prototype produced.
 *
 * The model answers the staffing question: "what happens to the backlog if I
 * add one reviewer to OBO?" Applications arrive as a Poisson process, seize an
 * office's reviewers, and are held in a FIFO queue when every reviewer is busy.
 * The simulation reports, per office, the time-weighted mean queue length, the
 * mean time an application spent waiting for a free reviewer, and utilisation —
 * plus end-to-end flow time and RA 11032 on-time compliance.
 *
 * WHAT THE PORT KEPT FROM des.R
 *
 *  - Service times are lognormal, fitted per stage by maximum likelihood, the
 *    same estimator `fitdistrplus::fitdist(x, "lnorm")` converges to:
 *    meanlog = mean(log x), sdlog = sqrt(sum((log x - meanlog)^2) / n). Note the
 *    `n` denominator — that is the MLE, not `stats::sd`'s `n - 1`. Verified
 *    against fitdistrplus 1.2: for 200 draws of rlnorm(log 2, 0.55) under
 *    set.seed(1103), fitdist reports meanlog 0.719999078864 and sdlog
 *    0.550232209617, which is exactly the n-denominator closed form (the n-1
 *    form gives sdlog 0.551612970156 — wrong in the third decimal).
 *  - Arrivals are exponential inter-arrival times split into a "complex" stream
 *    (the full pipeline) and a "simple" stream (single-office revalidation),
 *    mirroring `add_generator("complex", ...)` / `add_generator("simple", ...)`.
 *  - Complex applications fan out to every reviewing office in parallel and wait
 *    for all of them — the reference's `clone(3, ...) |> synchronize(wait = TRUE)`. Fixed
 *    intake/payment and issuance delays are uniform, as in the reference.
 *  - Queue length and utilisation are time-weighted means over [0, horizon]: a
 *    state holds from its event until the next one, the trailing interval runs
 *    to the horizon, and the denominator is the whole horizon so idle time
 *    before the first arrival counts as idle (the reference's `.tw_mean`).
 *  - RA 11032 deadlines are counted in working days and the simulation clock is
 *    calendar days, so the deadline is scaled by 7/5 before comparing, and only
 *    arrivals that entered early enough to have finished in time are judged
 *    (the reference's `eligible`).
 *  - One RNG seed per replication (`set.seed(SEED + r)`), so re-running the same
 *    scenario returns the same numbers. Draw-for-draw agreement with the
 *    prototype was never possible — R's Mersenne Twister and simmer's lazy
 *    generator draws are a different stream from PHP's — so the port was
 *    validated statistically against simmer on identical inputs, not by
 *    equality. Reproducibility within this engine is the property that still
 *    holds and the one the screens rely on.
 *
 * DOCUMENTED DIVERGENCES FROM THE REFERENCE
 *
 *  - The reference decided each arrival's deadline from the *scenario name*
 *    (`grepl("^complex", name)`), and every scenario in `run_des` was named
 *    "baseline" / "plus2_bfp_inspectors" / "plus1_cho_reviewer" — so it judged
 *    every arrival, complex ones included, against the 3-working-day simple
 *    deadline. That was a defect, not a modelling choice. Here each arrival is
 *    judged against its own class's deadline.
 *  - The offices are not hard-coded to BPLO/CHO/BFP. The register has seven, and
 *    which ones review a filing is a routing decision, so the pipeline is built
 *    from whatever `StaffingSimulation` finds in the data.
 *  - Applications still in the pipeline when the clock stops are reported as
 *    `unfinished` (the backlog) instead of being silently dropped.
 */
final class Des
{
    /**
     * Base RNG seed, carried over from `SEED` in the prototype's config.R. It is
     * the reason a given scenario reproduces: replication r runs on seed + r.
     */
    public const DEFAULT_SEED = 1103;

    /** Replications averaged per scenario (`DES_REPS`). */
    public const DEFAULT_REPS = 30;

    /** Simulated horizon, in 30-day months (`DES_MONTHS`). */
    public const DEFAULT_MONTHS = 6;

    /**
     * Working days per calendar week, for converting an RA 11032 deadline into
     * the calendar days the simulation clock measures (the reference's
     * `wd_to_cal = 7 / 5`).
     */
    private const WORKING_DAYS_PER_WEEK = 5;

    /** A stage needs this many observed durations before a fit is trustworthy. */
    public const MIN_FIT_SAMPLES = 5;

    /** Guard against a pathological scenario spinning forever. */
    private const MAX_EVENTS = 4_000_000;

    /**
     * Maximum-likelihood lognormal fit, ported from
     * `fitdistrplus::fitdist(x, "lnorm")`.
     *
     * Non-positive durations are dropped the way `des.R` dropped them
     * (`filter(dur > 0)`): the log of zero is not a number, and a review that
     * completed in the same instant it was assigned is a data artefact.
     *
     * @param  list<int|float>  $samples  Observed durations in days.
     * @return array{meanlog: float, sdlog: float, mean_days: float, median_days: float, n: int}|null
     *                                                                                                Null when fewer than MIN_FIT_SAMPLES usable durations survive.
     */
    public static function fitLognormal(array $samples): ?array
    {
        $logs = [];
        foreach ($samples as $sample) {
            $value = (float) $sample;
            if ($value > 0.0) {
                $logs[] = log($value);
            }
        }

        $n = count($logs);
        if ($n < self::MIN_FIT_SAMPLES) {
            return null;
        }

        $meanLog = array_sum($logs) / $n;

        $sumSquares = 0.0;
        foreach ($logs as $value) {
            $sumSquares += ($value - $meanLog) ** 2;
        }
        // MLE: n, not n - 1. fitdistrplus's optimiser converges here.
        $sdLog = sqrt($sumSquares / $n);

        return [
            'meanlog' => $meanLog,
            'sdlog' => $sdLog,
            'mean_days' => exp($meanLog + ($sdLog ** 2) / 2),
            'median_days' => exp($meanLog),
            'n' => $n,
        ];
    }

    /** An RA 11032 working-day deadline in the calendar days the clock counts. */
    public static function deadlineInCalendarDays(float $workingDays): float
    {
        return $workingDays * (7 / self::WORKING_DAYS_PER_WEEK);
    }

    /**
     * Run one staffing scenario and average its metrics over the replications.
     *
     * The model is plain data so it can be built from the register in one place
     * (StaffingSimulation) and asserted on in another (DesTest) without a
     * database. Shape:
     *
     *   resources        array<string, int>  office code => reviewer headcount
     *   arrivals_per_day float               total Poisson rate, all classes
     *   classes          list of:
     *     key            string              'complex' | 'simple'
     *     share          float               fraction of arrivals, summing to 1
     *     deadline_days  float               RA 11032 deadline in WORKING days
     *     phases         list of, in order:
     *       ['kind' => 'delay', 'min' => float, 'max' => float]
     *       ['kind' => 'parallel', 'stages' => list<array{
     *            resource: string, meanlog: float, sdlog: float
     *        }>]                             all stages run at once; the filing
     *                                        leaves the phase when the last one
     *                                        finishes (clone + synchronize)
     *   horizon_days     float
     *   reps             int
     *   seed             int
     *
     * @param  array<string, mixed>  $model
     * @return array{
     *     reps: int,
     *     seed: int,
     *     horizon_days: float,
     *     arrivals: float,
     *     finished: float,
     *     unfinished: float,
     *     mean_flow_days: float|null,
     *     p90_flow_days: float|null,
     *     on_time_rate: float|null,
     *     judged: float,
     *     resources: array<string, array{
     *         capacity: int,
     *         utilisation: float,
     *         queue_length: float,
     *         max_queue: float,
     *         mean_wait_days: float,
     *         served: float
     *     }>
     * }
     */
    public static function simulate(array $model): array
    {
        $reps = max(1, (int) ($model['reps'] ?? self::DEFAULT_REPS));
        $seed = (int) ($model['seed'] ?? self::DEFAULT_SEED);
        $horizon = (float) ($model['horizon_days'] ?? self::DEFAULT_MONTHS * 30);
        /** @var array<string, int> $capacities */
        $capacities = $model['resources'] ?? [];

        $totals = [
            'arrivals' => 0.0,
            'finished' => 0.0,
            'unfinished' => 0.0,
            'judged' => 0.0,
            'on_time' => 0.0,
        ];
        $flowSamples = [];
        $perResource = [];
        foreach ($capacities as $name => $capacity) {
            $perResource[$name] = [
                'capacity' => (int) $capacity,
                'utilisation' => 0.0,
                'queue_length' => 0.0,
                'max_queue' => 0.0,
                'wait_sum' => 0.0,
                'served' => 0.0,
            ];
        }

        for ($rep = 1; $rep <= $reps; $rep++) {
            // set.seed(SEED + r): the replication, not the wall clock, decides
            // the draws, so the same scenario is the same numbers every time.
            $run = self::replicate($model, $horizon, $seed + $rep);

            $totals['arrivals'] += $run['arrivals'];
            $totals['finished'] += $run['finished'];
            $totals['unfinished'] += $run['unfinished'];
            $totals['judged'] += $run['judged'];
            $totals['on_time'] += $run['on_time'];
            foreach ($run['flows'] as $flow) {
                $flowSamples[] = $flow;
            }

            foreach ($run['resources'] as $name => $stats) {
                $perResource[$name]['utilisation'] += $stats['utilisation'];
                $perResource[$name]['queue_length'] += $stats['queue_length'];
                $perResource[$name]['max_queue'] += $stats['max_queue'];
                $perResource[$name]['wait_sum'] += $stats['wait_sum'];
                $perResource[$name]['served'] += $stats['served'];
            }
        }

        $resources = [];
        foreach ($perResource as $name => $stats) {
            $resources[$name] = [
                'capacity' => $stats['capacity'],
                'utilisation' => $stats['utilisation'] / $reps,
                'queue_length' => $stats['queue_length'] / $reps,
                'max_queue' => $stats['max_queue'] / $reps,
                // Pooled over every served request across replications, so a
                // replication that served more filings weighs more — the same
                // pooling the reference's mean over bound-together frames did.
                'mean_wait_days' => $stats['served'] > 0 ? $stats['wait_sum'] / $stats['served'] : 0.0,
                'served' => $stats['served'] / $reps,
            ];
        }

        sort($flowSamples);

        return [
            'reps' => $reps,
            'seed' => $seed,
            'horizon_days' => $horizon,
            'arrivals' => $totals['arrivals'] / $reps,
            'finished' => $totals['finished'] / $reps,
            'unfinished' => $totals['unfinished'] / $reps,
            'mean_flow_days' => $flowSamples === [] ? null : array_sum($flowSamples) / count($flowSamples),
            'p90_flow_days' => self::quantile($flowSamples, 0.9),
            'on_time_rate' => $totals['judged'] > 0 ? $totals['on_time'] / $totals['judged'] : null,
            'judged' => $totals['judged'] / $reps,
            'resources' => $resources,
        ];
    }

    /**
     * One replication of the event loop.
     *
     * @param  array<string, mixed>  $model
     * @return array{
     *     arrivals: int,
     *     finished: int,
     *     unfinished: int,
     *     judged: int,
     *     on_time: int,
     *     flows: list<float>,
     *     resources: array<string, array{utilisation: float, queue_length: float, max_queue: int, wait_sum: float, served: int}>
     * }
     */
    private static function replicate(array $model, float $horizon, int $seed): array
    {
        $rng = new Randomizer(new Mt19937($seed));
        $arrivalsPerDay = max(0.0, (float) ($model['arrivals_per_day'] ?? 0.0));
        /** @var list<array<string, mixed>> $classes */
        $classes = array_values($model['classes'] ?? []);

        /**
         * `queue` is a ring of pending requests addressed by head/tail rather
         * than shifted: array_shift is O(n) and would dominate a saturated
         * office's runtime, and count() cannot be trusted as the depth once
         * served slots have been unset.
         *
         * @var array<string, array{capacity: int, busy: int, queue: array<int, array{entity: int, stage: array<string, mixed>, since: float}>, head: int, tail: int, max_queue: int, wait_sum: float, served: int, log: list<array{0: float, 1: int, 2: int}>}> $resources
         */
        $resources = [];
        foreach (($model['resources'] ?? []) as $name => $capacity) {
            $resources[$name] = [
                'capacity' => max(1, (int) $capacity),
                'busy' => 0,
                'queue' => [],
                'head' => 0,
                'tail' => 0,
                'max_queue' => 0,
                'wait_sum' => 0.0,
                'served' => 0,
                // Seeded at t = 0 so the idle stretch before the first arrival
                // is weighted: the denominator is the full horizon, as in the
                // reference.
                'log' => [[0.0, 0, 0]],
            ];
        }

        $events = new DesEventQueue;
        $sequence = 0;

        // First arrival per class, then each arrival schedules its successor.
        foreach ($classes as $classIndex => $class) {
            $rate = $arrivalsPerDay * (float) ($class['share'] ?? 0.0);
            if ($rate <= 0.0) {
                continue;
            }
            $events->push(self::exponential($rng, $rate), $sequence++, 'arrival', ['class' => $classIndex]);
        }

        /** @var array<int, array{class: int, start: float, phase: int, pending: int}> $entities */
        $entities = [];
        $nextEntityId = 1;
        $arrivals = 0;
        $finished = 0;
        $judged = 0;
        $onTime = 0;
        $flows = [];
        $eventCount = 0;

        while (($event = $events->pop()) !== null) {
            [$now, $type, $payload] = $event;
            if ($now > $horizon || ++$eventCount > self::MAX_EVENTS) {
                break;
            }

            if ($type === 'arrival') {
                $classIndex = $payload['class'];
                $class = $classes[$classIndex];
                $rate = $arrivalsPerDay * (float) ($class['share'] ?? 0.0);
                $events->push($now + self::exponential($rng, $rate), $sequence++, 'arrival', ['class' => $classIndex]);

                $id = $nextEntityId++;
                $entities[$id] = ['class' => $classIndex, 'start' => $now, 'phase' => -1, 'pending' => 0];
                $arrivals++;
                self::advance($id, $now, $rng, $entities, $classes, $resources, $events, $sequence, $finished, $flows, $judged, $onTime, $horizon);

                continue;
            }

            if ($type === 'delay_end') {
                self::advance($payload['entity'], $now, $rng, $entities, $classes, $resources, $events, $sequence, $finished, $flows, $judged, $onTime, $horizon);

                continue;
            }

            // service_end: free the reviewer, admit the next filing in the
            // queue, then see whether the entity's phase is complete.
            $name = $payload['resource'];
            $resource = &$resources[$name];
            $resource['busy']--;

            $queued = null;
            if ($resource['head'] < $resource['tail']) {
                $queued = $resource['queue'][$resource['head']];
                unset($resource['queue'][$resource['head']]);
                $resource['head']++;
                $resource['busy']++;
                $resource['wait_sum'] += $now - $queued['since'];
                $resource['served']++;
            }
            self::log($resource, $now);
            unset($resource);

            if ($queued !== null) {
                $events->push(
                    $now + self::lognormal($rng, $queued['stage']),
                    $sequence++,
                    'service_end',
                    ['entity' => $queued['entity'], 'resource' => $name],
                );
            }

            $id = $payload['entity'];
            $entities[$id]['pending']--;
            if ($entities[$id]['pending'] === 0) {
                self::advance($id, $now, $rng, $entities, $classes, $resources, $events, $sequence, $finished, $flows, $judged, $onTime, $horizon);
            }
        }

        $summary = [];
        foreach ($resources as $name => $resource) {
            $summary[$name] = [
                'utilisation' => self::timeWeightedMean($resource['log'], 1, $horizon) / $resource['capacity'],
                'queue_length' => self::timeWeightedMean($resource['log'], 2, $horizon),
                'max_queue' => $resource['max_queue'],
                'wait_sum' => $resource['wait_sum'],
                'served' => $resource['served'],
            ];
        }

        return [
            'arrivals' => $arrivals,
            'finished' => $finished,
            'unfinished' => $arrivals - $finished,
            'judged' => $judged,
            'on_time' => $onTime,
            'flows' => $flows,
            'resources' => $summary,
        ];
    }

    /**
     * Move an entity into its next phase, or retire it.
     *
     * @param  array<int, array{class: int, start: float, phase: int, pending: int}>  $entities
     * @param  list<array<string, mixed>>  $classes
     * @param  array<string, array<string, mixed>>  $resources
     * @param  list<float>  $flows
     */
    private static function advance(
        int $id,
        float $now,
        Randomizer $rng,
        array &$entities,
        array $classes,
        array &$resources,
        DesEventQueue $events,
        int &$sequence,
        int &$finished,
        array &$flows,
        int &$judged,
        int &$onTime,
        float $horizon,
    ): void {
        $entity = &$entities[$id];
        $class = $classes[$entity['class']];
        /** @var list<array<string, mixed>> $phases */
        $phases = $class['phases'] ?? [];
        $entity['phase']++;

        if ($entity['phase'] >= count($phases)) {
            $flow = $now - $entity['start'];
            $finished++;
            $flows[] = $flow;

            // Only judge filings that entered early enough to have finished in
            // time; the tail of the horizon would otherwise count as late.
            $deadline = self::deadlineInCalendarDays((float) ($class['deadline_days'] ?? 0));
            if ($deadline > 0.0 && $entity['start'] <= $horizon - $deadline) {
                $judged++;
                if ($flow <= $deadline) {
                    $onTime++;
                }
            }

            unset($entities[$id]);

            return;
        }

        $phase = $phases[$entity['phase']];

        if (($phase['kind'] ?? '') === 'delay') {
            $min = (float) ($phase['min'] ?? 0.0);
            $max = (float) ($phase['max'] ?? $min);
            $entity['pending'] = 0;
            $wait = $max > $min ? $rng->getFloat($min, $max) : $min;
            $events->push($now + $wait, $sequence++, 'delay_end', ['entity' => $id]);

            return;
        }

        /** @var list<array<string, mixed>> $stages */
        $stages = $phase['stages'] ?? [];
        if ($stages === []) {
            // Nothing to do in this phase; fall through to the next one.
            unset($entity);
            self::advance($id, $now, $rng, $entities, $classes, $resources, $events, $sequence, $finished, $flows, $judged, $onTime, $horizon);

            return;
        }

        // clone(n, ...) |> synchronize(wait = TRUE): every stage in the phase
        // starts now and the filing waits for the slowest.
        $entity['pending'] = count($stages);
        unset($entity);

        foreach ($stages as $stage) {
            $name = $stage['resource'];
            $resource = &$resources[$name];

            if ($resource['busy'] < $resource['capacity']) {
                $resource['busy']++;
                $resource['served']++;
                self::log($resource, $now);
                unset($resource);
                $events->push($now + self::lognormal($rng, $stage), $sequence++, 'service_end', [
                    'entity' => $id,
                    'resource' => $name,
                ]);

                continue;
            }

            $resource['queue'][$resource['tail']++] = ['entity' => $id, 'stage' => $stage, 'since' => $now];
            $resource['max_queue'] = max($resource['max_queue'], $resource['tail'] - $resource['head']);
            self::log($resource, $now);
            unset($resource);
        }
    }

    /**
     * Record a resource's state change for the time-weighted averages.
     *
     * @param  array<string, mixed>  $resource
     */
    private static function log(array &$resource, float $now): void
    {
        $resource['log'][] = [$now, $resource['busy'], $resource['tail'] - $resource['head']];
    }

    /**
     * Time-weighted mean of a step-valued series — the port of `.tw_mean`.
     *
     * A state holds from its own event until the next one; the last state runs
     * to the horizon; zero-length intervals (several changes in the same
     * instant) are skipped. The divisor is the horizon, not the observed span,
     * so a resource that was never touched averages zero rather than dividing
     * by nothing.
     *
     * @param  list<array{0: float, 1: int, 2: int}>  $log
     * @param  int  $column  1 for busy servers, 2 for queue length.
     */
    private static function timeWeightedMean(array $log, int $column, float $horizon): float
    {
        if ($log === [] || $horizon <= 0.0) {
            return 0.0;
        }

        $area = 0.0;
        $last = count($log) - 1;
        foreach ($log as $i => $row) {
            $until = $i === $last ? $horizon : $log[$i + 1][0];
            $dt = min($until, $horizon) - $row[0];
            if ($dt > 0.0) {
                $area += $row[$column] * $dt;
            }
        }

        return $area / $horizon;
    }

    /** Exponential inter-arrival time — the reference's `rexp(1, rate)`. */
    private static function exponential(Randomizer $rng, float $rate): float
    {
        if ($rate <= 0.0) {
            return INF;
        }

        // 1 - u keeps the draw off log(0) when getFloat returns exactly 0.
        return -log(1.0 - $rng->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen)) / $rate;
    }

    /**
     * Lognormal service time — the reference's `rlnorm(1, meanlog, sdlog)`.
     *
     * @param  array<string, mixed>  $stage
     */
    private static function lognormal(Randomizer $rng, array $stage): float
    {
        $meanLog = (float) ($stage['meanlog'] ?? 0.0);
        $sdLog = (float) ($stage['sdlog'] ?? 0.0);
        if ($sdLog <= 0.0) {
            return exp($meanLog);
        }

        return exp($meanLog + $sdLog * self::standardNormal($rng));
    }

    /** Box-Muller standard normal. */
    private static function standardNormal(Randomizer $rng): float
    {
        $u1 = $rng->getFloat(0.0, 1.0, IntervalBoundary::OpenClosed);
        $u2 = $rng->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen);

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    /**
     * Type-7 quantile over a sorted sample — the interpolation `stats::quantile`
     * defaulted to in the reference, and the one this project's p90 figures have
     * always been computed with.
     *
     * @param  list<float>  $sorted
     */
    private static function quantile(array $sorted, float $p): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return $sorted[0];
        }

        $h = ($n - 1) * $p;
        $lo = (int) floor($h);
        $hi = min($lo + 1, $n - 1);

        return $sorted[$lo] + ($h - $lo) * ($sorted[$hi] - $sorted[$lo]);
    }
}

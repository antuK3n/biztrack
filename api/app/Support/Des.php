<?php

namespace App\Support;

use Random\Engine\Mt19937;
use Random\IntervalBoundary;
use Random\Randomizer;

final class Des
{
    public const DEFAULT_SEED = 1103;

    public const DEFAULT_REPS = 30;

    public const DEFAULT_MONTHS = 6;

    private const WORKING_DAYS_PER_WEEK = 5;

    public const MIN_FIT_SAMPLES = 5;

    private const MAX_EVENTS = 4_000_000;

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

        $sdLog = sqrt($sumSquares / $n);

        return [
            'meanlog' => $meanLog,
            'sdlog' => $sdLog,
            'mean_days' => exp($meanLog + ($sdLog ** 2) / 2),
            'median_days' => exp($meanLog),
            'n' => $n,
        ];
    }

    public static function deadlineInCalendarDays(float $workingDays): float
    {
        return $workingDays * (7 / self::WORKING_DAYS_PER_WEEK);
    }

    public static function simulate(array $model): array
    {
        $reps = max(1, (int) ($model['reps'] ?? self::DEFAULT_REPS));
        $seed = (int) ($model['seed'] ?? self::DEFAULT_SEED);
        $horizon = (float) ($model['horizon_days'] ?? self::DEFAULT_MONTHS * 30);

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

    private static function replicate(array $model, float $horizon, int $seed): array
    {
        $rng = new Randomizer(new Mt19937($seed));
        $arrivalsPerDay = max(0.0, (float) ($model['arrivals_per_day'] ?? 0.0));

        $classes = array_values($model['classes'] ?? []);

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

                'log' => [[0.0, 0, 0]],
            ];
        }

        $events = new DesEventQueue;
        $sequence = 0;

        foreach ($classes as $classIndex => $class) {
            $rate = $arrivalsPerDay * (float) ($class['share'] ?? 0.0);
            if ($rate <= 0.0) {
                continue;
            }
            $events->push(self::exponential($rng, $rate), $sequence++, 'arrival', ['class' => $classIndex]);
        }

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

        $phases = $class['phases'] ?? [];
        $entity['phase']++;

        if ($entity['phase'] >= count($phases)) {
            $flow = $now - $entity['start'];
            $finished++;
            $flows[] = $flow;

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

        $stages = $phase['stages'] ?? [];
        if ($stages === []) {
            unset($entity);
            self::advance($id, $now, $rng, $entities, $classes, $resources, $events, $sequence, $finished, $flows, $judged, $onTime, $horizon);

            return;
        }

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

    private static function log(array &$resource, float $now): void
    {
        $resource['log'][] = [$now, $resource['busy'], $resource['tail'] - $resource['head']];
    }

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

    private static function exponential(Randomizer $rng, float $rate): float
    {
        if ($rate <= 0.0) {
            return INF;
        }

        return -log(1.0 - $rng->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen)) / $rate;
    }

    private static function lognormal(Randomizer $rng, array $stage): float
    {
        $meanLog = (float) ($stage['meanlog'] ?? 0.0);
        $sdLog = (float) ($stage['sdlog'] ?? 0.0);
        if ($sdLog <= 0.0) {
            return exp($meanLog);
        }

        return exp($meanLog + $sdLog * self::standardNormal($rng));
    }

    private static function standardNormal(Randomizer $rng): float
    {
        $u1 = $rng->getFloat(0.0, 1.0, IntervalBoundary::OpenClosed);
        $u2 = $rng->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen);

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

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

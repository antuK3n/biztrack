<?php

namespace App\Support;

use App\Models\AnalyticsSnapshot;
use App\Services\RAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes register rows to R and persists what it computes.
 *
 * This exists so the console command and the HTTP endpoint run the same code.
 * The refresh used to live entirely inside RefreshAnalytics::handle(), tangled
 * with console output, which meant adding a button would have meant a second
 * copy of the loop — and a second place for the two to drift apart. That is the
 * exact failure this codebase already fixed once between the R and PHP engines.
 *
 * Reports results rather than printing them: the command formats them for a
 * terminal, the controller serialises them as JSON.
 */
final class AnalyticsRefresher
{
    /**
     * @param  string|null  $only  Restrict to one dataset name.
     * @return array{
     *   results: list<array{key: string, dataset: string, ok: bool, rows: int, duration_ms: int, error: string|null}>,
     *   succeeded: int, failed: int, engine_version: string|null, disabled: bool, unreachable: bool
     * }
     */
    public static function run(RAnalytics $r, ?string $only = null): array
    {
        $datasets = AnalyticsDatasets::pushable();

        if ($only !== null) {
            $datasets = isset($datasets[$only]) ? [$only => $datasets[$only]] : [];
        }

        $blank = ['results' => [], 'succeeded' => 0, 'failed' => 0, 'engine_version' => null];

        if (! $r->enabled()) {
            return $blank + ['disabled' => true, 'unreachable' => false];
        }

        /*
         * Ask R its version before building any payload. An outage then costs one
         * cheap request instead of a full pass of register queries whose results
         * are thrown away — which matters more here than on the command line,
         * because a user is waiting on the response.
         */
        $health = $r->health();

        if ($health === null) {
            return $blank + ['disabled' => false, 'unreachable' => true];
        }

        $engineVersion = isset($health['r_version']) ? (string) $health['r_version'] : null;

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($datasets as $name => $definition) {
            foreach (AnalyticsDatasets::variants($name) as $params) {
                $key = AnalyticsSnapshot::keyFor($name, $params);

                try {
                    $dataset = ($definition['dataset'])($params);
                } catch (Throwable $e) {
                    Log::warning('analytics refresh: dataset query failed', ['key' => $key, 'error' => $e->getMessage()]);
                    $results[] = self::row($key, $name, false, 0, 0, $e->getMessage());
                    $failed++;

                    continue;
                }

                $startedAt = microtime(true);
                $statistics = $r->compute($definition['endpoint'], $dataset);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                if ($statistics === null) {
                    // The previous snapshot is deliberately left alone: a stale
                    // figure that says how old it is beats no figure at all.
                    $results[] = self::row($key, $name, false, 0, $durationMs, $r->lastError() ?? 'unknown error');
                    $failed++;

                    continue;
                }

                AnalyticsSnapshot::updateOrCreate(
                    ['key' => $key],
                    [
                        'dataset' => $name,
                        'payload' => $statistics,
                        'source' => 'r',
                        'engine_version' => $engineVersion,
                        'duration_ms' => $durationMs,
                        'computed_at' => Carbon::now(),
                    ],
                );

                $results[] = self::row($key, $name, true, self::rowCount($dataset), $durationMs, null);
                $succeeded++;
            }
        }

        return [
            'results' => $results,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'engine_version' => $engineVersion,
            'disabled' => false,
            'unreachable' => false,
        ];
    }

    /**
     * @return array{key: string, dataset: string, ok: bool, rows: int, duration_ms: int, error: string|null}
     */
    private static function row(string $key, string $dataset, bool $ok, int $rows, int $ms, ?string $error): array
    {
        return ['key' => $key, 'dataset' => $dataset, 'ok' => $ok, 'rows' => $rows, 'duration_ms' => $ms, 'error' => $error];
    }

    /** Rows pushed, summed over every list in the payload. */
    private static function rowCount(array $dataset): int
    {
        $count = 0;
        foreach ($dataset as $value) {
            if (is_array($value) && array_is_list($value)) {
                $count += count($value);
            }
        }

        return $count;
    }
}

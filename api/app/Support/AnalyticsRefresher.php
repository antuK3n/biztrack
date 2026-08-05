<?php

namespace App\Support;

use App\Models\AnalyticsSnapshot;
use App\Services\RAnalytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AnalyticsRefresher
{
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

    private static function row(string $key, string $dataset, bool $ok, int $rows, int $ms, ?string $error): array
    {
        return ['key' => $key, 'dataset' => $dataset, 'ok' => $ok, 'rows' => $rows, 'duration_ms' => $ms, 'error' => $error];
    }

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

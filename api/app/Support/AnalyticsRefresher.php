<?php

namespace App\Support;

use App\Models\AnalyticsSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recomputes every precomputed analytics snapshot and persists the result.
 *
 * ## What this used to be
 *
 * This class gathered register rows, POSTed them to an R (plumber) service, and
 * stored what came back. R has been removed from BizTrack; the builders in
 * app/Support that were written as its PHP counterparts are now the only
 * implementation, and this class calls them directly. There is no HTTP on this
 * path any more, no payload to push, no engine version to negotiate and no
 * remote service that can be down.
 *
 * That deletes an entire class of failure. A refresh could previously fail
 * because a service on another port was not running, which had nothing to do
 * with the register and everything to do with the deployment. What is left can
 * only fail the way any other query can fail.
 *
 * ## What this still is
 *
 * The precompute layer itself stays, because it was never a consequence of R
 * being remote. The client chose precomputation over computing on request:
 * `analytics:refresh` runs nightly, writes one snapshot per (dataset, window),
 * and page loads read those snapshots. Recomputing the dashboard costs a few
 * hundred milliseconds — cheap enough that a miss is survivable, expensive
 * enough that paying it on every page load is not what was asked for.
 *
 * This exists as its own class rather than living in RefreshAnalytics::handle()
 * so that the console command and the "Refresh now" button run exactly the same
 * code. A second copy of the loop is a second place for the two to drift apart.
 * It reports results rather than printing them: the command formats them for a
 * terminal, the controller serialises them as JSON.
 */
final class AnalyticsRefresher
{
    /**
     * Recompute and persist. Safe to run repeatedly — each snapshot is written by
     * key, so a second pass overwrites the first rather than accumulating rows.
     *
     * @param  string|null  $only  Restrict to one dataset name.
     * @return array{
     *   results: list<array{key: string, dataset: string, ok: bool, rows: int, duration_ms: int, error: string|null}>,
     *   succeeded: int, failed: int
     * }
     */
    public static function run(?string $only = null): array
    {
        $datasets = AnalyticsDatasets::all();

        if ($only !== null) {
            $datasets = isset($datasets[$only]) ? [$only => $datasets[$only]] : [];
        }

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($datasets as $name => $definition) {
            foreach (AnalyticsDatasets::variants($name) as $params) {
                $key = AnalyticsSnapshot::keyFor($name, $params);

                $startedAt = microtime(true);

                try {
                    $statistics = ($definition['build'])($params);
                } catch (Throwable $e) {
                    /*
                     * Failure is per snapshot, never global, and the previous
                     * snapshot is deliberately left in place: a stale figure that
                     * says how old it is beats a blank screen. One dataset whose
                     * query throws must not cost the other four their refresh.
                     */
                    Log::warning('analytics refresh: dataset failed', ['key' => $key, 'error' => $e->getMessage()]);
                    $results[] = self::row($key, $name, false, 0, 0, $e->getMessage());
                    $failed++;

                    continue;
                }

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                AnalyticsSnapshot::updateOrCreate(
                    ['key' => $key],
                    [
                        'dataset' => $name,
                        'payload' => $statistics,
                        'source' => 'local',
                        'engine_version' => null,
                        'duration_ms' => $durationMs,
                        'computed_at' => Carbon::now(),
                    ],
                );

                $results[] = self::row($key, $name, true, self::rowCount($statistics), $durationMs, null);
                $succeeded++;
            }
        }

        return [
            'results' => $results,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{key: string, dataset: string, ok: bool, rows: int, duration_ms: int, error: string|null}
     */
    private static function row(string $key, string $dataset, bool $ok, int $rows, int $ms, ?string $error): array
    {
        return ['key' => $key, 'dataset' => $dataset, 'ok' => $ok, 'rows' => $rows, 'duration_ms' => $ms, 'error' => $error];
    }

    /**
     * Rows in the computed payload, summed over every list in it.
     *
     * This counted rows PUSHED when there was somewhere to push them; it now
     * counts rows produced. Either way it is the number that makes a dataset
     * quietly growing towards a slow refresh visible in the output.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function rowCount(array $payload): int
    {
        $count = 0;
        foreach ($payload as $value) {
            if (is_array($value) && array_is_list($value)) {
                $count += count($value);
            }
        }

        return $count;
    }
}

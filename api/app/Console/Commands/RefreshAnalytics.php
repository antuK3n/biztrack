<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSnapshot;
use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsRefresher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recompute every analytics snapshot and persist it.
 *
 * Analytics are computed in BATCH rather than per request, so there is no
 * statistics work on a page load:
 *
 *     analytics:refresh
 *         ├─ query the register (one owner of SQL, scoping applied)
 *         ├─ compute the statistics in PHP (app/Support/*Analytics.php)
 *         └─ persist the result
 *
 *     page load ──> read the persisted result  (no computation, fast)
 *
 * Run it nightly next to `biztrack:scan-permits`, and by hand after seeding.
 *
 * This command used to be the R integration: it pushed row sets to a plumber
 * service on another port, and R did the arithmetic. R has been removed. The
 * middle step above is now a function call instead of an HTTP round trip, which
 * is the only thing that changed — the batching, the schedule and the snapshot
 * table are all unaffected, because none of them existed because of R.
 *
 * One snapshot per (dataset, window), because statistics are not sliceable: the
 * control limits fitted on 52 weeks are not the limits for the 26-week view. The
 * windows come from config/analytics.php.
 *
 * Safe to run repeatedly. Snapshots are written by key, so a second pass
 * overwrites the first rather than accumulating rows, and nothing is ever
 * deleted.
 *
 * Failure is per snapshot, never global. A dataset that throws leaves its
 * previous snapshot in place — stale figures with an honest timestamp beat
 * wiping the screen — and the command reports what failed. It exits non-zero
 * only if nothing at all got through, which is the signal a scheduler should
 * alert on.
 */
class RefreshAnalytics extends Command
{
    protected $signature = 'analytics:refresh
                            {--only= : Refresh a single dataset (processing_time, renewal_risk)}
                            {--dry-run : Compute and size the payloads without persisting them}';

    protected $description = 'Recompute the analytics snapshots the screens read.';

    public function handle(): int
    {
        $datasets = AnalyticsDatasets::all();

        if ($only = $this->option('only')) {
            if (! isset($datasets[$only])) {
                $this->components->error(sprintf(
                    'Unknown dataset [%s]. Available: %s.',
                    $only,
                    implode(', ', array_keys($datasets)),
                ));

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($only ?: null);
        }

        $outcome = AnalyticsRefresher::run($only ?: null);

        foreach ($outcome['results'] as $result) {
            if ($result['ok']) {
                $this->components->twoColumnDetail(
                    $result['key'],
                    sprintf('<fg=green>ok</> <fg=gray>%d rows in %dms</>', $result['rows'], $result['duration_ms']),
                );

                continue;
            }

            $this->components->twoColumnDetail($result['key'], '<fg=red>failed</>');
            $this->line('    '.($result['error'] ?? 'unknown error'));
        }

        $this->newLine();

        if ($outcome['failed'] > 0 && $outcome['succeeded'] === 0) {
            $this->components->error(sprintf(
                'All %d snapshot(s) failed. The screens keep their previous figures and say how old they are.',
                $outcome['failed'],
            ));

            return self::FAILURE;
        }

        if ($outcome['failed'] > 0) {
            $this->components->warn(sprintf(
                '%d snapshot(s) refreshed, %d failed.',
                $outcome['succeeded'],
                $outcome['failed'],
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('%d snapshot(s) refreshed.', $outcome['succeeded']));

        return self::SUCCESS;
    }

    /**
     * Compute everything and persist none of it.
     *
     * This used to size the JSON that was about to be POSTed, so a dataset
     * creeping towards the R timeout was visible before it started failing.
     * Nothing is serialised over a wire any more, but the same number still
     * answers a question worth asking — a payload is a row in the snapshot table
     * and eventually a response body — and the timing now measures the actual
     * computation rather than a round trip. It is also the only way to exercise
     * every builder without touching the database.
     */
    private function dryRun(?string $only): int
    {
        $datasets = AnalyticsDatasets::all();

        if ($only !== null) {
            $datasets = [$only => $datasets[$only]];
        }

        $failed = 0;

        foreach ($datasets as $name => $definition) {
            foreach (AnalyticsDatasets::variants($name) as $params) {
                $key = AnalyticsSnapshot::keyFor($name, $params);

                $startedAt = microtime(true);

                try {
                    $payload = ($definition['build'])($params);
                } catch (Throwable $e) {
                    $this->components->twoColumnDetail($key, '<fg=red>failed</>');
                    $this->line("    {$e->getMessage()}");
                    $failed++;

                    continue;
                }

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $this->components->twoColumnDetail(
                    $key,
                    sprintf('<fg=gray>%s in %dms</>', self::size($payload), $durationMs),
                );
            }
        }

        $this->newLine();
        $this->components->info('Dry run: nothing was persisted.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Rough payload size, so a dataset growing towards a slow refresh is visible. */
    private static function size(array $payload): string
    {
        $rows = 0;
        foreach ($payload as $value) {
            if (is_array($value) && array_is_list($value)) {
                $rows += count($value);
            }
        }

        $bytes = strlen((string) json_encode($payload));

        return sprintf('%d rows, %s', $rows, self::humanBytes($bytes));
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).'MB'
            : ($bytes >= 1024 ? round($bytes / 1024).'KB' : $bytes.'B');
    }
}

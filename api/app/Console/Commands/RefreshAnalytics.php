<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSnapshot;
use App\Services\RAnalytics;
use App\Support\AnalyticsDatasets;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Push register rows to the R statistics service and persist what comes back.
 *
 * This command *is* the integration. R stays a separate program and remains the
 * statistics engine; analytics are computed in batch rather than per request, so
 * there is no live R call on a page load:
 *
 *     analytics:refresh
 *         ├─ Laravel queries the register (one owner of SQL, scoping applied)
 *         ├─ POSTs the row sets to plumber
 *         ├─ R computes the statistics
 *         └─ Laravel persists the result
 *
 *     page load ──> Laravel reads the persisted result  (no R involved, fast)
 *
 * Run it nightly next to `biztrack:scan-permits`, and by hand after seeding.
 *
 * One snapshot per (dataset, window), because statistics are not sliceable: the
 * control limits fitted on 52 weeks are not the limits for the 26-week view. The
 * windows come from config/analytics.php.
 *
 * Failure is per snapshot, never global. A dataset R chokes on leaves its
 * previous snapshot in place — stale figures with an honest timestamp beat
 * wiping the screen — and the command reports what failed. It exits non-zero
 * only if nothing at all got through, which is the signal a scheduler should
 * alert on.
 */
class RefreshAnalytics extends Command
{
    protected $signature = 'analytics:refresh
                            {--only= : Refresh a single dataset (processing_time, renewal_risk)}
                            {--dry-run : Build and size the payloads without calling R}';

    protected $description = 'Push register rows to the R statistics service and persist the computed analytics.';

    /** Read off /health once and recorded on every snapshot this pass writes. */
    private ?string $engineVersion = null;

    public function handle(RAnalytics $r): int
    {
        $datasets = AnalyticsDatasets::pushable();

        if ($only = $this->option('only')) {
            if (! isset($datasets[$only])) {
                $this->components->error(sprintf(
                    'Dataset [%s] is not one R computes. Pushable: %s.',
                    $only,
                    implode(', ', array_keys($datasets)) ?: 'none',
                ));

                return self::FAILURE;
            }

            $datasets = [$only => $datasets[$only]];
        }

        if ($datasets === []) {
            $this->components->warn('No datasets are wired to R yet — nothing to refresh.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->checkService($r)) {
            return self::FAILURE;
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($datasets as $name => $definition) {
            foreach (AnalyticsDatasets::variants($name) as $params) {
                $key = AnalyticsSnapshot::keyFor($name, $params);

                try {
                    $dataset = ($definition['dataset'])($params);
                } catch (Throwable $e) {
                    $this->components->twoColumnDetail($key, '<fg=red>query failed</>');
                    $this->line("    {$e->getMessage()}");
                    $failed++;

                    continue;
                }

                if ($dryRun) {
                    $this->components->twoColumnDetail(
                        $key,
                        sprintf('<fg=gray>%s → %s</>', self::size($dataset), $definition['endpoint']),
                    );

                    continue;
                }

                $startedAt = microtime(true);
                $statistics = $r->compute($definition['endpoint'], $dataset);
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                if ($statistics === null) {
                    // The previous snapshot, if any, is deliberately left alone.
                    $this->components->twoColumnDetail($key, '<fg=red>R failed</>');
                    $this->line('    '.($r->lastError() ?? 'unknown error'));
                    $failed++;

                    continue;
                }

                AnalyticsSnapshot::updateOrCreate(
                    ['key' => $key],
                    [
                        'dataset' => $name,
                        'payload' => $statistics,
                        'source' => 'r',
                        'engine_version' => $this->engineVersion,
                        'duration_ms' => $durationMs,
                        'computed_at' => CarbonImmutable::now(),
                    ],
                );

                $this->components->twoColumnDetail(
                    $key,
                    sprintf('<fg=green>ok</> <fg=gray>%s in %dms</>', self::size($dataset), $durationMs),
                );
                $succeeded++;
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->components->info('Dry run: nothing was pushed or persisted.');

            return self::SUCCESS;
        }

        $this->newLine();

        if ($failed > 0 && $succeeded === 0) {
            $this->components->error("All {$failed} snapshot(s) failed. Analytics screens will fall back to local computation and say so.");

            return self::FAILURE;
        }

        if ($failed > 0) {
            $this->components->warn("{$succeeded} snapshot(s) refreshed, {$failed} failed.");

            return self::SUCCESS;
        }

        $this->components->info("{$succeeded} snapshot(s) refreshed from R.");

        return self::SUCCESS;
    }

    /**
     * Confirm plumber is up before building payloads, so an outage costs one
     * request instead of a full pass of register queries.
     */
    private function checkService(RAnalytics $r): bool
    {
        if (! $r->enabled()) {
            $this->components->error('R analytics is disabled. Set R_ANALYTICS_ENABLED=true to refresh.');

            return false;
        }

        $health = $r->health();

        if ($health === null) {
            $this->components->error('The R statistics service is not reachable at '.config('analytics.r.base_url').'.');
            $this->line('    '.($r->lastError() ?? 'unknown error'));
            $this->line('    Start it with: <fg=cyan>cd r && Rscript run_api.R</>');

            return false;
        }

        $this->engineVersion = isset($health['r_version']) ? (string) $health['r_version'] : null;

        $this->components->info(sprintf(
            'R %s at %s',
            $this->engineVersion ?? 'service',
            config('analytics.r.base_url'),
        ));

        return true;
    }

    /** Rough payload size, so a dataset creeping towards the timeout is visible. */
    private static function size(array $dataset): string
    {
        $rows = 0;
        foreach ($dataset as $value) {
            if (is_array($value) && array_is_list($value)) {
                $rows += count($value);
            }
        }

        $bytes = strlen((string) json_encode($dataset));

        return sprintf('%d rows, %s', $rows, self::humanBytes($bytes));
    }

    private static function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).'MB'
            : ($bytes >= 1024 ? round($bytes / 1024).'KB' : $bytes.'B');
    }
}

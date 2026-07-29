<?php

namespace App\Support;

use App\Models\AnalyticsSnapshot;
use App\Services\RAnalytics;
use Carbon\CarbonImmutable;


/**
 * Serves an analytics dataset: the statistics R computed if we have them, the
 * PHP port if we do not — and always says which one it was.
 *
 * The batch architecture (docs/r-integration-spec.md) means a read never calls
 * R. `analytics:refresh` pushes rows to the R service and persists what comes
 * back; this class reads that. Two consequences the whole feature is built
 * around, neither of which is papered over here:
 *
 *  - **Figures are as fresh as the last refresh.** `meta.computed_at` is
 *    mandatory on every response and every screen displays it. A tester who
 *    files an application and does not see it in the dashboard has found the
 *    designed behaviour, and the timestamp on screen is what tells them so.
 *  - **R being down does not break analytics.** It only stops them getting
 *    newer. Existing snapshots keep serving; a dataset that has none falls back
 *    to the PHP port.
 *
 * The fallback is never silent. When PHP computed the numbers, `meta.source` is
 * 'local' and `meta.notice` carries a sentence the UI shows. Presenting fallback
 * output as R output would make the two implementations' drift invisible, which
 * is precisely the risk the fallback introduces — and the reason the parity test
 * against shared fixtures exists.
 */
final class AnalyticsResolver
{
    /**
     * @param  array<string, int|string>  $params  parameters that identify the snapshot
     * @param  callable(): array<string, mixed>  $local  the PHP port, called only on a miss
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public static function resolve(string $dataset, array $params, callable $local): array
    {
        $key = AnalyticsSnapshot::keyFor($dataset, $params);
        $snapshot = AnalyticsSnapshot::where('key', $key)->first();

        if ($snapshot !== null) {
            return [
                'data' => $snapshot->payload,
                'meta' => [
                    'source' => 'r',
                    'engine' => 'R',
                    'engine_version' => $snapshot->engine_version,
                    'computed_at' => $snapshot->computed_at->toISOString(),
                    'stale' => $snapshot->isStale(),
                    'stale_after_hours' => (int) config('analytics.stale_after_hours'),
                    'fallback_reason' => null,
                    'notice' => null,
                ],
            ];
        }

        $reason = self::missReason($dataset, $params);

        return [
            'data' => $local(),
            'meta' => [
                'source' => 'local',
                'engine' => 'PHP',
                'engine_version' => PHP_VERSION,
                // Locally computed figures are current by construction: they were
                // computed to answer this request.
                'computed_at' => CarbonImmutable::now()->toISOString(),
                'stale' => false,
                'stale_after_hours' => (int) config('analytics.stale_after_hours'),
                'fallback_reason' => $reason,
                'notice' => self::noticeFor($reason),
            ],
        ];
    }

    /**
     * Why there was no snapshot. The three cases call for three different
     * actions, so the response distinguishes them instead of shrugging.
     *
     * @param  array<string, int|string>  $params
     */
    private static function missReason(string $dataset, array $params): string
    {
        // Nothing to refresh: R has no endpoint for this dataset yet, so it will
        // never have a snapshot. Telling the reader to run the refresh here would
        // send them after a fix that does not exist.
        if (AnalyticsDatasets::get($dataset)['endpoint'] === null) {
            return 'no_r_endpoint';
        }

        if (! app(RAnalytics::class)->enabled()) {
            return 'r_disabled';
        }

        // A window nobody asked to precompute is a configuration answer
        // (config/analytics.php), not an outage.
        return self::isPrecomputedVariant($dataset, $params)
            ? 'not_yet_refreshed'
            : 'window_not_precomputed';
    }

    /** @param array<string, int|string> $params */
    private static function isPrecomputedVariant(string $dataset, array $params): bool
    {
        $wanted = AnalyticsSnapshot::keyFor($dataset, $params);

        foreach ((array) config("analytics.variants.{$dataset}", []) as $variant) {
            if (AnalyticsSnapshot::keyFor($dataset, $variant) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /** The sentence the screen shows. Each one names what would fix it. */
    private static function noticeFor(string $reason): string
    {
        return match ($reason) {
            'no_r_endpoint' => 'This view is not computed in R yet, so these figures come from the local implementation.',
            'r_disabled' => 'The R statistics service is switched off for this environment.',
            'window_not_precomputed' => 'This window is not one of the precomputed windows, so the R service has no result for it.',
            default => 'The R statistics service has no result for this view yet — run the analytics refresh.',
        };
    }
}

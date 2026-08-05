<?php

namespace App\Support;

use App\Models\AnalyticsSnapshot;
use App\Services\RAnalytics;
use Carbon\CarbonImmutable;

final class AnalyticsResolver
{
    public static function resolve(string $dataset, array $params, callable $local): array
    {
        $key = AnalyticsSnapshot::keyFor($dataset, $params);
        $snapshot = AnalyticsSnapshot::where('key', $key)->first();

        $definitions = AnalyticsDefinitions::for($dataset);

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
                    'definitions' => $definitions,
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

                'computed_at' => CarbonImmutable::now()->toISOString(),
                'stale' => false,
                'stale_after_hours' => (int) config('analytics.stale_after_hours'),
                'fallback_reason' => $reason,
                'notice' => self::noticeFor($reason),
                'definitions' => $definitions,
            ],
        ];
    }

    private static function missReason(string $dataset, array $params): string
    {
        if (AnalyticsDatasets::get($dataset)['endpoint'] === null) {
            return 'no_r_endpoint';
        }

        if (! app(RAnalytics::class)->enabled()) {
            return 'r_disabled';
        }

        return self::isPrecomputedVariant($dataset, $params)
            ? 'not_yet_refreshed'
            : 'window_not_precomputed';
    }

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

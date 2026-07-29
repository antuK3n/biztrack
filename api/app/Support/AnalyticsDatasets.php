<?php

namespace App\Support;

/**
 * The registry of analytics datasets: for each one, how to gather its rows, which
 * R endpoint computes them, and how to compute them locally if R cannot.
 *
 * One place, because three callers need to agree on the same list and would
 * otherwise drift: `analytics:refresh` walks it to push, AnalyticsController
 * reads through it to serve, and the parity test walks it to compare engines.
 *
 * A dataset with `endpoint => null` is one Laravel computes and R does not yet.
 * That is a real state, not a gap to hide: the resolver labels those responses
 * "computed locally" like any other fallback, and the screen says so. Giving it a
 * null instead of leaving it out of the registry is what keeps it visible.
 */
final class AnalyticsDatasets
{
    public const PROCESSING_TIME = 'processing_time';

    public const RENEWAL_RISK = 'renewal_risk';

    public const BUSINESS_GROWTH = 'business_growth';

    /**
     * @return array<string, array{
     *     label: string,
     *     endpoint: string|null,
     *     dataset: callable(array<string, int>): array<string, mixed>,
     *     local: callable(array<string, int>): array<string, mixed>,
     *     defaults: array<string, int>
     * }>
     */
    public static function all(): array
    {
        return [
            self::PROCESSING_TIME => [
                'label' => 'Permit Processing Time Monitoring',
                'endpoint' => ProcessingTimeAnalytics::R_ENDPOINT,
                'dataset' => static fn (array $p): array => ProcessingTimeAnalytics::dataset(
                    $p['weeks'] ?? ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS,
                ),
                'local' => static fn (array $p): array => ProcessingTimeAnalytics::build(
                    $p['weeks'] ?? ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS,
                ),
                'defaults' => ['weeks' => ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS],
            ],

            self::RENEWAL_RISK => [
                'label' => 'Renewal Risk Prediction',
                'endpoint' => RenewalRiskAnalytics::R_ENDPOINT,
                'dataset' => static fn (array $p): array => RenewalRiskAnalytics::dataset(
                    $p['days'] ?? RenewalRiskAnalytics::DEFAULT_HORIZON_DAYS,
                    $p['limit'] ?? RenewalRiskAnalytics::DEFAULT_LIMIT,
                ),
                'local' => static fn (array $p): array => RenewalRiskAnalytics::build(
                    $p['days'] ?? RenewalRiskAnalytics::DEFAULT_HORIZON_DAYS,
                    $p['limit'] ?? RenewalRiskAnalytics::DEFAULT_LIMIT,
                ),
                'defaults' => [
                    'days' => RenewalRiskAnalytics::DEFAULT_HORIZON_DAYS,
                    'limit' => RenewalRiskAnalytics::DEFAULT_LIMIT,
                ],
            ],

            self::BUSINESS_GROWTH => [
                'label' => 'Business Lifecycle Monitoring',
                // No R endpoint yet: the cohort-survival measure needs R's
                // `survival` package and the rest of this screen is SQL
                // aggregation R adds nothing to. Until it lands the screen is
                // served locally and labelled as such.
                'endpoint' => null,
                'dataset' => static fn (array $p): array => [],
                'local' => static fn (array $p): array => BusinessGrowthAnalytics::build(
                    $p['months'] ?? BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS,
                ),
                'defaults' => ['months' => BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS],
            ],
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     endpoint: string|null,
     *     dataset: callable(array<string, int>): array<string, mixed>,
     *     local: callable(array<string, int>): array<string, mixed>,
     *     defaults: array<string, int>
     * }
     */
    public static function get(string $dataset): array
    {
        $all = self::all();

        if (! isset($all[$dataset])) {
            throw new \InvalidArgumentException("Unknown analytics dataset [{$dataset}].");
        }

        return $all[$dataset];
    }

    /** Datasets R can actually compute — what `analytics:refresh` iterates. */
    public static function pushable(): array
    {
        return array_filter(self::all(), static fn (array $d): bool => $d['endpoint'] !== null);
    }

    /**
     * The parameter combinations `analytics:refresh` precomputes for a dataset.
     *
     * @return list<array<string, int>>
     */
    public static function variants(string $dataset): array
    {
        $variants = (array) config("analytics.variants.{$dataset}", []);

        return $variants === [] ? [self::get($dataset)['defaults']] : array_values($variants);
    }
}

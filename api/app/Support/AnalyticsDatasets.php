<?php

namespace App\Support;

final class AnalyticsDatasets
{
    public const DASHBOARD = 'dashboard';

    public const PROCESSING_TIME = 'processing_time';

    public const RENEWAL_RISK = 'renewal_risk';

    public const BUSINESS_GROWTH = 'business_growth';

    public static function all(): array
    {
        return [
            self::DASHBOARD => [
                'label' => 'Analytics Dashboard',
                'endpoint' => DashboardAnalytics::R_ENDPOINT,
                'dataset' => static fn (array $p): array => DashboardAnalytics::dataset(
                    $p['months'] ?? DashboardAnalytics::DEFAULT_WINDOW_MONTHS,
                ),
                'local' => static fn (array $p): array => DashboardAnalytics::build(
                    $p['months'] ?? DashboardAnalytics::DEFAULT_WINDOW_MONTHS,
                ),
                'defaults' => ['months' => DashboardAnalytics::DEFAULT_WINDOW_MONTHS],
            ],

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
                /*
                 * "Business Growth Analysis" is the spec's §4 heading and the
                 * client's explicit instruction: 'Proper follow terms (e.g.,
                 * "Lifecycle" should be "Business Growth Analysis")'.
                 *
                 * This label said "Business Lifecycle Monitoring", taken from
                 * mockup 122 on the reasoning that the mockup was the newer
                 * document. That reasoning simply expired — the spec carrying
                 * "Business Growth Analysis" is newer still, and the client
                 * settled it directly. The constant stays BUSINESS_GROWTH.
                 *
                 * This label is not decoration: an e2e test asserts the screen's
                 * h1 matches what this sends back, because a half-applied rename
                 * is how a screen and its own payload drift apart.
                 */
                'label' => 'Business Growth Analysis',
                'endpoint' => BusinessGrowthAnalytics::R_ENDPOINT,
                'dataset' => static fn (array $p): array => BusinessGrowthAnalytics::dataset(
                    $p['months'] ?? BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS,
                ),
                'local' => static fn (array $p): array => BusinessGrowthAnalytics::build(
                    $p['months'] ?? BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS,
                ),
                'defaults' => ['months' => BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS],
            ],
        ];
    }

    public static function get(string $dataset): array
    {
        $all = self::all();

        if (! isset($all[$dataset])) {
            throw new \InvalidArgumentException("Unknown analytics dataset [{$dataset}].");
        }

        return $all[$dataset];
    }

    public static function pushable(): array
    {
        return array_filter(self::all(), static fn (array $d): bool => $d['endpoint'] !== null);
    }

    public static function variants(string $dataset): array
    {
        $variants = (array) config("analytics.variants.{$dataset}", []);

        return $variants === [] ? [self::get($dataset)['defaults']] : array_values($variants);
    }
}

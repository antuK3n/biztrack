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
    public const DASHBOARD = 'dashboard';

    public const PROCESSING_TIME = 'processing_time';

    public const RENEWAL_RISK = 'renewal_risk';

    public const BUSINESS_GROWTH = 'business_growth';

    /**
     * The fitted companion to RENEWAL_RISK, kept as its own dataset.
     *
     * Not extra keys on the renewal-risk payload, for the three reasons set out
     * in RenewalModelAnalytics' docblock — the short one being that
     * AnalyticsParityTest compares that payload against R's key for key in both
     * directions, and this is the one dataset R computes that PHP deliberately
     * does not port.
     */
    public const RENEWAL_MODEL = 'renewal_model';

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

            self::RENEWAL_MODEL => [
                'label' => 'Renewal Risk — fitted model',
                'endpoint' => RenewalModelAnalytics::R_ENDPOINT,
                'dataset' => static fn (array $p): array => RenewalModelAnalytics::dataset(
                    $p['days'] ?? RenewalModelAnalytics::DEFAULT_HORIZON_DAYS,
                    $p['limit'] ?? RenewalModelAnalytics::DEFAULT_LIMIT,
                ),
                /*
                 * The only `local` in this registry that does not compute the
                 * statistics, because there is no honest way for it to. Fitting a
                 * generalised linear model a second time in PHP so the two copies
                 * can disagree is not a fallback, and reporting the rule score
                 * under a probability heading when R is down would be the one
                 * outright lie this feature is capable of telling. It returns the
                 * same keys with `available => false` and a reason instead.
                 */
                'local' => static fn (array $p): array => RenewalModelAnalytics::build(
                    $p['days'] ?? RenewalModelAnalytics::DEFAULT_HORIZON_DAYS,
                    $p['limit'] ?? RenewalModelAnalytics::DEFAULT_LIMIT,
                ),
                'defaults' => [
                    'days' => RenewalModelAnalytics::DEFAULT_HORIZON_DAYS,
                    'limit' => RenewalModelAnalytics::DEFAULT_LIMIT,
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

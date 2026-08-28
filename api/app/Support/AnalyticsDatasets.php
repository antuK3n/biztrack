<?php

namespace App\Support;

/**
 * The registry of analytics datasets: for each one, its heading, how to build it
 * and the parameters it is built with by default.
 *
 * One place, because two callers must agree on the same list and would otherwise
 * drift: `analytics:refresh` walks it to precompute every variant, and
 * AnalyticsController reads through it to serve a request.
 *
 * ## What R left behind here
 *
 * Each entry used to carry an `endpoint` — the route on the R (plumber) service
 * that computed it — and a `dataset` closure that gathered the rows to POST
 * there. Its builder was filed under `local`, meaning "computed here rather than
 * by R". R has been removed, so the endpoint and the push are gone and the
 * builder is simply `build`: there is no longer a non-local alternative for the
 * name to distinguish it from.
 *
 * `pushable()` went with them. It filtered this list down to the datasets R had
 * an endpoint for, which was the only sense in which a dataset could be
 * refreshable-but-not-servable. Every dataset here is now built the same way by
 * the same code, so the whole registry is the refresh list and `all()` is it.
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
     * Not extra keys on the renewal-risk payload, because the two are different
     * KINDS of claim and a reader has to be able to tell which is which. The
     * rule score is a transparent weighted ranking that never needed evidence;
     * the fitted figure is a claim about the world that is worthless without the
     * AUC, Brier and calibration readings attached. They are shown side by side
     * and stored apart. See RenewalModelAnalytics.
     */
    public const RENEWAL_MODEL = 'renewal_model';

    /**
     * @return array<string, array{
     *     label: string,
     *     dataset: callable(array<string, int>): array<string, mixed>,
     *     build: callable(array<string, int>): array<string, mixed>,
     *     defaults: array<string, int>
     * }>
     */
    public static function all(): array
    {
        return [
            self::DASHBOARD => [
                'label' => 'Analytics Dashboard',
                'dataset' => static fn (array $p): array => DashboardAnalytics::dataset(
                    $p['months'] ?? DashboardAnalytics::DEFAULT_WINDOW_MONTHS,
                ),
                'build' => static fn (array $p): array => DashboardAnalytics::build(
                    $p['months'] ?? DashboardAnalytics::DEFAULT_WINDOW_MONTHS,
                ),
                'defaults' => ['months' => DashboardAnalytics::DEFAULT_WINDOW_MONTHS],
            ],

            self::PROCESSING_TIME => [
                'label' => 'Permit Processing Time Monitoring',
                'dataset' => static fn (array $p): array => ProcessingTimeAnalytics::dataset(
                    $p['weeks'] ?? ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS,
                ),
                'build' => static fn (array $p): array => ProcessingTimeAnalytics::build(
                    $p['weeks'] ?? ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS,
                ),
                'defaults' => ['weeks' => ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS],
            ],

            self::RENEWAL_RISK => [
                'label' => 'Renewal Risk Prediction',
                'dataset' => static fn (array $p): array => RenewalRiskAnalytics::dataset(
                    $p['days'] ?? RenewalRiskAnalytics::DEFAULT_HORIZON_DAYS,
                    $p['limit'] ?? RenewalRiskAnalytics::DEFAULT_LIMIT,
                ),
                'build' => static fn (array $p): array => RenewalRiskAnalytics::build(
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
                'build' => static fn (array $p): array => RenewalModelAnalytics::build(
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
                'dataset' => static fn (array $p): array => BusinessGrowthAnalytics::dataset(
                    $p['months'] ?? BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS,
                ),
                'build' => static fn (array $p): array => BusinessGrowthAnalytics::build(
                    $p['months'] ?? BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS,
                ),
                'defaults' => ['months' => BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS],
            ],
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     dataset: callable(array<string, int>): array<string, mixed>,
     *     build: callable(array<string, int>): array<string, mixed>,
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

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

    /**
     * The six offices side by side, rather than one office at a time.
     *
     * Its own dataset and not more keys on PROCESSING_TIME, because the two are
     * different KINDS of claim. Processing Time asks whether an office is behaving the way that
     * office normally behaves, which every office answers in its own fitted
     * units; this asks how the offices compare, which needs one unit for all of
     * them. Sharing a payload would put two answers to two questions under one
     * heading and one `computed_at`.
     */
    public const OFFICE_PERFORMANCE = 'office_performance';

    /*
     * RENEWAL_RISK, RENEWAL_MODEL and BUSINESS_GROWTH were registered here and
     * went with their screens (checklist 2026-09-27, item 6). Their stored
     * snapshots are not deleted by this change; nothing reads them, and the
     * refresh no longer rewrites them.
     */

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

            self::OFFICE_PERFORMANCE => [
                /*
                 * "Office Performance" rather than "Department Performance": the
                 * client, the RA 11032 literature and every screen in this
                 * product say office, and `departments` is a table name. The
                 * heading on the screen is this string — e2e/office-performance
                 * asserts the two match, the same guard Business Growth carries.
                 */
                'label' => 'Office Performance',
                'dataset' => static fn (array $p): array => OfficePerformanceAnalytics::dataset(
                    $p['weeks'] ?? OfficePerformanceAnalytics::DEFAULT_WINDOW_WEEKS,
                ),
                'build' => static fn (array $p): array => OfficePerformanceAnalytics::build(
                    $p['weeks'] ?? OfficePerformanceAnalytics::DEFAULT_WINDOW_WEEKS,
                ),
                'defaults' => ['weeks' => OfficePerformanceAnalytics::DEFAULT_WINDOW_WEEKS],
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

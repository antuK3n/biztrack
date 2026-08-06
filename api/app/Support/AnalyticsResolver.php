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
 * 'local', `meta.engine` is 'PHP' and `meta.fallback_reason` says why.
 * Presenting fallback output as R output would make the two implementations'
 * drift invisible, which is precisely the risk the fallback introduces — and the
 * reason the parity test against shared fixtures exists.
 *
 * ## Where that provenance is spoken aloud, and where it is not
 *
 * "Never silent" is a guarantee about the payload, not a licence to put an
 * engine architecture in front of a licensing officer. The two are separable and
 * they have been separated:
 *
 *  - **The fields always travel.** `source`, `engine`, `engine_version`,
 *    `fallback_reason` and `notice` are on every response, in every case. The
 *    parity test and the printed reports read them. Nothing below removes one.
 *  - **`meta.notice` is written for the printed report.** A PDF is forwarded,
 *    filed and quoted months later by a reader who cannot ask which engine ran,
 *    so the document names it — see resources/views/pdf/partials. That is the
 *    surface where "R" earns its place.
 *  - **The screens do not render `notice`.** A BPLO officer cannot act on the
 *    location of a computation. web/src/pages/admin/ComputedAt.tsx keys its own
 *    plain-language line off `fallback_reason` instead, and shows a notice at
 *    all only for the one reason that names something the reader can do.
 *
 * So `notice` stays engine-worded on purpose. Reword it for a screen and the
 * document loses the vocabulary it is the last witness to.
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

        /*
         * What the figures mean does not depend on which engine computed them —
         * both emit the same schema, which is the premise the parity test
         * enforces. So the definitions are resolved once, outside the branch: if
         * they differed by engine they would be describing a difference that is
         * not supposed to exist.
         */
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
                // Locally computed figures are current by construction: they were
                // computed to answer this request.
                'computed_at' => CarbonImmutable::now()->toISOString(),
                'stale' => false,
                'stale_after_hours' => (int) config('analytics.stale_after_hours'),
                'fallback_reason' => $reason,
                'notice' => self::noticeFor($reason),
                'definitions' => $definitions,
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

        /*
         * A window nobody asked to precompute is a configuration answer
         * (config/analytics.php), not an outage — and it is the only one of the
         * four reasons that describes a correct, intended, permanent outcome.
         *
         * config/analytics.php now mirrors every window selector the screens
         * offer, so a plain window choice no longer lands here. What still does
         * is Renewal Risk's filtered, resized and paginated requests, whose key
         * space is unbounded by design. That is why the screen renders nothing
         * for this reason: it would be flagging the register's own filters as a
         * fault, forever.
         */
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

    /**
     * The sentence the printed report carries. Each one names the engine, and
     * that is the point.
     *
     * These used to be shown on screen too, which is how a BPLO officer came to
     * be reading "This window is not one of the precomputed windows, so the R
     * service has no result for it" above their dashboard. Nothing in that
     * sentence is addressed to them: they did not choose the architecture, they
     * cannot edit config/analytics.php, and the Refresh button beside it would
     * not have helped. The client asked for it to go, and it has gone from the
     * screens — ComputedAt.tsx writes its own copy from `fallback_reason`.
     *
     * It has NOT gone from the payload, because the PDF reports embed it and a
     * document has the opposite need: the reader holding a printout months later
     * cannot ask which of the two implementations produced the figures, so the
     * page has to say. Provenance that is noise in a dashboard header is
     * evidence in a filed report.
     */
    private static function noticeFor(string $reason): string
    {
        return match ($reason) {
            'no_r_endpoint' => 'R does not compute this view yet, so the local implementation produced these figures.',
            'r_disabled' => 'The R statistics service is switched off in the environment that produced this report.',
            'window_not_precomputed' => 'This window is outside the set R precomputes, so the local implementation answered it.',
            default => 'R had not yet computed this view when this report was produced, so the local implementation answered it.',
        };
    }
}

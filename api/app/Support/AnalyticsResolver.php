<?php

namespace App\Support;

use App\Models\AnalyticsSnapshot;
use Carbon\CarbonImmutable;

/**
 * Serves an analytics dataset: the precomputed figures if we have them, freshly
 * computed figures if we do not — and always says which it was.
 *
 * ## What changed, and what did not
 *
 * This class used to arbitrate between two statistics engines. R was the
 * reference implementation, PHP was a fallback that stood in when R was
 * unreachable or switched off, and the meta existed largely to keep the two
 * distinguishable so their drift could not go unnoticed. R has been removed from
 * BizTrack. `App\Support\DashboardAnalytics`, `ProcessingTimeAnalytics`,
 * `RenewalRiskAnalytics`, `BusinessGrowthAnalytics` and `RenewalModelAnalytics`
 * are now the only implementation, so there is no second engine to disagree
 * with, no outage to survive and no drift to detect.
 *
 * What survived the removal is the thing that was never about R: analytics are
 * PRECOMPUTED. `analytics:refresh` walks the registry nightly and stores one
 * snapshot per (dataset, parameter combination); a page load reads that snapshot
 * rather than recomputing. The client chose that explicitly over computing on
 * request, and it is still the reason this class exists. So the axis the meta
 * reports is no longer "which engine" but "how fresh":
 *
 *  - **`source: 'snapshot'`** — read from the last refresh. `computed_at` is when
 *    that refresh ran, which may be hours ago, and `stale` says when that is old
 *    enough to mention.
 *  - **`source: 'local'`** — no snapshot existed for these exact parameters, so
 *    the figures were computed to answer this request. They are current by
 *    construction.
 *
 * `engine` is now always the string 'BizTrack' and `engine_version` always null.
 * Neither carries information any more, and both are kept because the printed
 * reports and the analytics screens read them: the client asked for the banner
 * that read "by R 4.6.1" to read "by BizTrack", not to disappear.
 *
 * ## Why a miss is not always a fault — and why there are still two reasons
 *
 * The reasons that meant "R was unreachable", "R was switched off" and "R has no
 * endpoint for this view" went with R. Two survive, and they must NOT be
 * collapsed into one however similar they look from here:
 *
 *  - **`not_yet_refreshed`** — this dataset's precomputed set includes these
 *    exact parameters, and the refresh has not written them yet. Something we
 *    intended to precompute is missing. That is a real freshness signal, the
 *    Refresh button will fix it, and the screen is right to say so loudly.
 *  - **`window_not_precomputed`** — the caller asked for a combination that was
 *    never in the precomputed set: a barangay filter, a risk band, a different
 *    page size, an offset. Computing it on request is the DESIGNED behaviour and
 *    no refresh will ever change it.
 *
 * Collapsing them was tried and had to be undone. Renewal Risk's key space
 * carries the page size, the filters and the pagination offset, so it is
 * unbounded and cannot be precomputed (see config/analytics.php). With one reason
 * for both, pressing an ordinary band filter reported the same state as a refresh
 * that had never run, and the screen raised a staleness panel over a supported
 * option working exactly as designed. ComputedAt.tsx's own note is the argument
 * against it: a warning that fires on the majority of a screen's own options has
 * stopped carrying information.
 *
 * So the distinction the screen renders is: did we fail to precompute something
 * we meant to, or did the caller ask for something outside the set?
 * AnalyticsDatasets::variants() is what defines that set and is what decides.
 */
final class AnalyticsResolver
{
    /**
     * @param  array<string, int|string>  $params  parameters that identify the snapshot
     * @param  callable(): array<string, mixed>  $local  computes the figures now, called only on a miss
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public static function resolve(string $dataset, array $params, callable $local): array
    {
        $key = AnalyticsSnapshot::keyFor($dataset, $params);
        $snapshot = AnalyticsSnapshot::where('key', $key)->first();

        /*
         * The definitions describe what the figures MEAN, which does not depend
         * on when they were computed. Resolved once, outside the branch, so a
         * snapshot and a fresh computation cannot end up explaining themselves
         * differently.
         */
        $definitions = AnalyticsDefinitions::for($dataset);

        if ($snapshot !== null) {
            return [
                'data' => $snapshot->payload,
                'meta' => self::meta(
                    source: 'snapshot',
                    computedAt: $snapshot->computed_at->toISOString(),
                    stale: $snapshot->isStale(),
                    reason: null,
                    definitions: $definitions,
                ),
            ];
        }

        return [
            'data' => $local(),
            'meta' => self::meta(
                source: 'local',
                // Computed to answer this request, so current by construction.
                computedAt: CarbonImmutable::now()->toISOString(),
                stale: false,
                reason: self::missReason($dataset, $params),
                definitions: $definitions,
            ),
        ];
    }

    /**
     * Why there was no snapshot: a refresh that owes us this view, or a request
     * for one that was never going to be precomputed.
     *
     * The two call for different things from the reader — one is worth a panel
     * and a button press, the other is worth a quiet line — so they are reported
     * apart. See the class docblock for what happened when they were not.
     *
     * @param  array<string, int|string>  $params
     */
    private static function missReason(string $dataset, array $params): string
    {
        return self::isPrecomputedVariant($dataset, $params)
            ? 'not_yet_refreshed'
            : 'window_not_precomputed';
    }

    /**
     * Whether these exact parameters are one of the combinations the refresh
     * writes.
     *
     * Compared by SNAPSHOT KEY rather than by array equality, because the key is
     * what actually decides a hit — it sorts the parameters and stringifies them,
     * so two arrays that differ only in ordering are the same snapshot, and a
     * variant configured with the wrong key shape is not.
     *
     * @param  array<string, int|string>  $params
     */
    private static function isPrecomputedVariant(string $dataset, array $params): bool
    {
        $wanted = AnalyticsSnapshot::keyFor($dataset, $params);

        foreach (AnalyticsDatasets::variants($dataset) as $variant) {
            if (AnalyticsSnapshot::keyFor($dataset, $variant) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * The provenance block that travels on every analytics response.
     *
     * Assembled in one place because the two branches above must not be able to
     * disagree about which keys exist. Screens, the PDF partials and
     * AnalyticsInsightsTest all read this shape unconditionally, and a key that
     * appears in only one branch is a blank panel on whichever branch omits it.
     *
     * @param  array<string, mixed>  $definitions
     * @return array<string, mixed>
     */
    private static function meta(
        string $source,
        string $computedAt,
        bool $stale,
        ?string $reason,
        array $definitions,
    ): array {
        return [
            'source' => $source,

            /*
             * One engine, named rather than versioned. `engine_version` held R's
             * "4.6.1" and answered a question that mattered when two engines could
             * disagree — if two snapshots differed, the first thing to check was
             * whether the engine had changed underneath them. With a single
             * implementation shipped in the same deploy as the code that reads it,
             * there is no such question, and PHP's own version answers nothing a
             * reader of a permit report could act on. The key stays, always null,
             * because the reports and the screens read it.
             */
            'engine' => 'BizTrack',
            'engine_version' => null,

            'computed_at' => $computedAt,
            'stale' => $stale,
            'stale_after_hours' => (int) config('analytics.stale_after_hours'),
            'fallback_reason' => $reason,
            'notice' => $reason === null ? null : self::noticeFor($reason),
            'definitions' => $definitions,
        ];
    }

    /**
     * The sentence the printed report carries when the figures were not read from
     * a refresh.
     *
     * These used to name the engine, and that was their whole justification: a PDF
     * is forwarded, filed and quoted months later by a reader who cannot ask which
     * of two implementations produced the figures, so the document had to say.
     * There is one implementation now, so naming it tells the reader nothing.
     *
     * What a reader of a filed report still cannot work out is HOW OLD the numbers
     * are, and whether the view they are looking at is one the register keeps
     * precomputed at all. Those are the two things left to say, and they are the
     * two reasons — so the sentences differ by reason rather than by engine.
     */
    private static function noticeFor(string $reason): string
    {
        return match ($reason) {
            // Coverage, not staleness. Nothing is wrong and no refresh will
            // change it, so the sentence must not imply either.
            'window_not_precomputed' => 'This combination is not one the register precomputes, '
                .'so these figures were computed as this report was produced.',

            // Staleness. The scheduled run owes this view a result.
            default => 'These figures were computed as this report was produced, '
                .'because the scheduled refresh has not yet stored a result for this view.',
        };
    }
}

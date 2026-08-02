<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * Business Location Insights (docs/r-integration-spec.md §5) — the four figures
 * shown to an applicant in the apply wizard's zoning step, for the point they
 * just dropped on the map.
 *
 * ## Why this is computed per request, in PHP
 *
 * The rest of the analytics suite is batch: `analytics:refresh` pushes rows to
 * the R service and persists snapshots, and page loads read the snapshot
 * (AnalyticsResolver). That architecture cannot answer this question. A snapshot
 * is keyed by a fixed list of parameter combinations (config/analytics.php
 * `variants`) because "control limits for a 52-week window cannot be sliced out
 * of a 26-week result". Here the parameter is a latitude/longitude the applicant
 * chose seconds ago — a continuous, unbounded key space. There is no finite set
 * of variants to precompute, and a nightly figure for a point nobody had picked
 * yet does not exist.
 *
 * So this is the case config/analytics.php already describes as the honest
 * outcome: "A request outside these combinations is computed locally and says
 * so." Responses carry `meta.engine = "PHP"` for exactly that reason.
 *
 * The paper attributes the spatial analysis to R (`sf`/`dplyr`). The statistics
 * here are a count, a banding, a mode and an arithmetic mean over a haversine
 * distance — no `sf` spatial predicate, no model fitting — so the port is small
 * enough to be obviously equivalent, and shelling out to `Rscript` on an
 * applicant's click is explicitly ruled out.
 *
 * ## What counts as a neighbour
 *
 * A **registered** business: not soft-deleted, and carrying at least one
 * application that has left `draft`. A half-finished draft is not in the
 * register, and counting one would let a tester's own abandoned attempt inflate
 * the next applicant's neighbourhood.
 *
 * Nothing identifying leaves this class. The four figures are a count, a band, a
 * category name and a mean distance; no business name, address or owner is
 * exposed, which is what makes the numbers safe to show one applicant about
 * everybody else's premises.
 */
final class LocationInsights
{
    /** Radius for every "nearby" figure, in metres (§5). */
    public const RADIUS_M = 500;

    /** Concentration bands (§5): Low 0–5, Medium 6–10, High 11+. */
    public const BAND_MEDIUM_FROM = 6;

    public const BAND_HIGH_FROM = 11;

    /** `similar.reason`: nothing to compare against, no line of business named yet. */
    public const NO_LINE_CHOSEN = 'line_not_chosen';

    /** `similar.reason`: a line was named, but it carries no classification. */
    public const LINE_UNCLASSIFIED = 'line_unclassified';

    private const EARTH_RADIUS_M = 6_371_000;

    /**
     * @param  float  $lat  the point the applicant pinned
     * @param  float  $lng  the point the applicant pinned
     * @param  string|null  $psicCode  the applicant's own line of business, when they have
     *                                 already chosen one. The zoning step runs BEFORE the
     *                                 Line of Business step, so on a new filing this is
     *                                 usually null and the two "similar" figures are
     *                                 reported as unavailable rather than guessed.
     * @param  int|null  $excludeBusinessId  the applicant's own draft business, so they are
     *                                       never counted as their own neighbour
     * @return array<string, mixed>
     */
    public static function forPoint(
        float $lat,
        float $lng,
        ?string $psicCode = null,
        ?int $excludeBusinessId = null,
    ): array {
        $nearby = self::nearby($lat, $lng, $excludeBusinessId);

        return [
            'radius_m' => self::RADIUS_M,
            'concentration' => self::concentration($nearby),
            'similar' => self::similar($nearby, $psicCode),
            'common_type' => self::commonType($nearby),
        ];
    }

    /**
     * Registered businesses within RADIUS_M of the point, each with its distance
     * and PSIC code.
     *
     * The bounding box is a prefilter, not the answer: it is a cheap SQL-side
     * cut that works identically on SQLite and PostgreSQL, and the haversine
     * below is what actually decides membership. Without the box this would pull
     * the whole register into PHP on every pin drop.
     *
     * @return Collection<int, array{distance_m: float, psic_code: string|null}>
     */
    private static function nearby(float $lat, float $lng, ?int $excludeBusinessId): Collection
    {
        $latDelta = rad2deg(self::RADIUS_M / self::EARTH_RADIUS_M);
        // Longitude degrees shrink with latitude; guard the pole case so the
        // divisor can never reach zero.
        $cos = max(cos(deg2rad($lat)), 0.000001);
        $lngDelta = $latDelta / $cos;

        $query = Business::query()
            ->whereHas('applications', fn ($q) => $q->where('status', '!=', 'draft'))
            ->whereHas('address', function ($q) use ($lat, $lng, $latDelta, $lngDelta) {
                $q->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
                    ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta]);
            })
            ->with([
                'address:id,business_id,latitude,longitude',
                'lines:id,business_id,psic_code_id',
                'lines.psicCode:id,code',
            ]);

        if ($excludeBusinessId !== null) {
            $query->whereKeyNot($excludeBusinessId);
        }

        return $query->get()
            ->map(function (Business $business) use ($lat, $lng) {
                $address = $business->address;

                if ($address === null || $address->latitude === null || $address->longitude === null) {
                    return null;
                }

                $distance = self::haversine($lat, $lng, (float) $address->latitude, (float) $address->longitude);

                if ($distance > self::RADIUS_M) {
                    return null;
                }

                return [
                    'distance_m' => $distance,
                    // A business may declare several lines; the first is the one
                    // the register treats as its principal trade, and it is what
                    // the wizard shows back to the applicant.
                    'psic_code' => $business->lines->first()?->psicCode?->code,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Great-circle distance in metres. Straight-line, not street distance — the
     * UI says so, because "320 m" that turns out to be a 900 m walk around a
     * river is worse than no figure.
     */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /**
     * Every registered business in the radius, banded.
     *
     * @param  Collection<int, array{distance_m: float, psic_code: string|null}>  $nearby
     * @return array<string, mixed>
     */
    private static function concentration(Collection $nearby): array
    {
        $count = $nearby->count();

        return [
            'count' => $count,
            'band' => self::band($count),
            'thresholds' => [
                'medium_from' => self::BAND_MEDIUM_FROM,
                'high_from' => self::BAND_HIGH_FROM,
            ],
        ];
    }

    public static function band(int $count): string
    {
        return match (true) {
            $count >= self::BAND_HIGH_FROM => 'high',
            $count >= self::BAND_MEDIUM_FROM => 'medium',
            default => 'low',
        };
    }

    /**
     * Count of, and mean distance to, businesses in the applicant's own PSIC
     * group — the standard's own "same or related trade" (see PsicTaxonomy).
     *
     * `available: false` is a real answer, not an error, and `reason` says which
     * of the two real answers it is. They are not interchangeable:
     *
     *  - `line_not_chosen` — the applicant has not named a line yet. The fix is
     *    theirs: name one and the figure appears.
     *  - `line_unclassified` — they named the catch-all 00000 "Other (not
     *    listed)", which classifies nothing and therefore has no related trade.
     *    Naming a line again will not help, and telling them to "choose your Line
     *    of Business first" when they just did is how this figure earned
     *    "Location Insights does not work properly" (checklist item 68).
     *
     * The distinction is about to matter far more than it did: checklist item 67
     * lets an applicant type their own trade under Other, so the catch-all stops
     * being the rare case.
     *
     * @param  Collection<int, array{distance_m: float, psic_code: string|null}>  $nearby
     * @return array<string, mixed>
     */
    private static function similar(Collection $nearby, ?string $psicCode): array
    {
        $group = PsicTaxonomy::group($psicCode);

        if ($group === null) {
            return [
                'available' => false,
                'reason' => $psicCode === null ? self::NO_LINE_CHOSEN : self::LINE_UNCLASSIFIED,
                'psic_group' => null,
                'count' => null,
                'average_distance_m' => null,
            ];
        }

        $matches = $nearby->filter(fn (array $row) => PsicTaxonomy::group($row['psic_code']) === $group);

        return [
            'available' => true,
            'reason' => null,
            'psic_group' => $group,
            'count' => $matches->count(),
            // No neighbours means no mean. Reporting 0 m would read as "there is
            // one right here".
            'average_distance_m' => $matches->isEmpty()
                ? null
                : (int) round($matches->avg('distance_m')),
        ];
    }

    /**
     * Mode of the nearby businesses' categories.
     *
     * Ties break on the category name so the same neighbourhood always reports
     * the same winner; an insight that flickers between two equally common types
     * on reload reads as a bug.
     *
     * @param  Collection<int, array{distance_m: float, psic_code: string|null}>  $nearby
     * @return array<string, mixed>
     */
    private static function commonType(Collection $nearby): array
    {
        if ($nearby->isEmpty()) {
            return ['available' => false, 'category' => null, 'count' => null, 'of_total' => 0];
        }

        $counts = $nearby
            ->groupBy(fn (array $row) => PsicTaxonomy::category($row['psic_code']))
            ->map->count()
            ->sortBy(fn (int $count, string $category) => sprintf('%09d|%s', 1_000_000 - $count, $category));

        /** @var string $category */
        $category = $counts->keys()->first();

        return [
            'available' => true,
            'category' => $category,
            'count' => $counts->first(),
            'of_total' => $nearby->count(),
        ];
    }
}

<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Collection;

final class LocationInsights
{
    public const RADIUS_M = 500;

    public const BAND_MEDIUM_FROM = 6;

    public const BAND_HIGH_FROM = 11;

    public const NO_LINE_CHOSEN = 'line_not_chosen';

    public const LINE_UNCLASSIFIED = 'line_unclassified';

    private const EARTH_RADIUS_M = 6_371_000;

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

    private static function nearby(float $lat, float $lng, ?int $excludeBusinessId): Collection
    {
        $latDelta = rad2deg(self::RADIUS_M / self::EARTH_RADIUS_M);

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

                    'psic_code' => $business->lines->first()?->psicCode?->code,
                ];
            })
            ->filter()
            ->values();
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

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

            'average_distance_m' => $matches->isEmpty()
                ? null
                : (int) round($matches->avg('distance_m')),
        ];
    }

    private static function commonType(Collection $nearby): array
    {
        if ($nearby->isEmpty()) {
            return ['available' => false, 'category' => null, 'count' => null, 'of_total' => 0];
        }

        $counts = $nearby
            ->groupBy(fn (array $row) => PsicTaxonomy::category($row['psic_code']))
            ->map->count()
            ->sortBy(fn (int $count, string $category) => sprintf('%09d|%s', 1_000_000 - $count, $category));

        $category = $counts->keys()->first();

        return [
            'available' => true,
            'category' => $category,
            'count' => $counts->first(),
            'of_total' => $nearby->count(),
        ];
    }
}

<?php

use App\Support\LocationInsights;
use App\Support\PsicTaxonomy;

/*
 * The pure parts of Business Location Insights (spec §5): the PSIC taxonomy that
 * decides what "similar" means, the concentration banding, and the distance
 * metric. The query side is covered in Feature/LocationInsightsApiTest.php.
 */

describe('PsicTaxonomy::group — what counts as a related trade', function () {
    it('groups a coffee shop with bars and beer houses, not with restaurants', function () {
        // 563 Beverage serving activities vs 561 Restaurants and mobile food.
        expect(PsicTaxonomy::group('56301'))->toBe('563')
            ->and(PsicTaxonomy::group('56302'))->toBe('563')
            ->and(PsicTaxonomy::group('56101'))->toBe('561')
            ->and(PsicTaxonomy::group('56103'))->toBe('561');
    });

    it('refuses to relate the catch-all 00000 row to anything', function () {
        /*
         * 00000 is "Other (not listed)" — the applicant could not find their
         * trade. Treating it as a group would make every unlisted trade in a
         * block a near neighbour of every other unlisted trade.
         */
        expect(PsicTaxonomy::group('00000'))->toBeNull()
            ->and(PsicTaxonomy::group(null))->toBeNull()
            ->and(PsicTaxonomy::group(''))->toBeNull()
            ->and(PsicTaxonomy::group('56'))->toBeNull();
    });
});

describe('PsicTaxonomy::category — the plain-language name for the mode', function () {
    it('names divisions the way an applicant would', function () {
        expect(PsicTaxonomy::category('56301'))->toBe('Foods & Beverages')
            ->and(PsicTaxonomy::category('47111'))->toBe('Retail Trade')
            ->and(PsicTaxonomy::category('46100'))->toBe('Wholesale Trade')
            ->and(PsicTaxonomy::category('41000'))->toBe('Construction')
            ->and(PsicTaxonomy::category('96200'))->toBe('Personal Services');
    });

    it('keeps food manufacturing out of Foods & Beverages', function () {
        /*
         * A bakeshop is division 10 (manufacturing), not 56 (food service).
         * Folding it in would tell someone opening a café that the block is
         * full of food-service competitors when it is full of bakeries.
         */
        expect(PsicTaxonomy::category('10711'))->toBe('Food & Beverage Manufacturing')
            ->and(PsicTaxonomy::category('36000'))->toBe('Water, Waste & Utilities');
    });

    it('falls back to Other for codes it cannot place', function () {
        expect(PsicTaxonomy::category('00000'))->toBe('Other')
            ->and(PsicTaxonomy::category('99999'))->toBe('Other')
            ->and(PsicTaxonomy::category(null))->toBe('Other');
    });
});

describe('LocationInsights::band', function () {
    it('bands on the spec boundaries: Low 0-5, Medium 6-10, High 11+', function () {
        expect(LocationInsights::band(0))->toBe('low')
            ->and(LocationInsights::band(5))->toBe('low')
            ->and(LocationInsights::band(6))->toBe('medium')
            ->and(LocationInsights::band(10))->toBe('medium')
            ->and(LocationInsights::band(11))->toBe('high')
            ->and(LocationInsights::band(400))->toBe('high');
    });
});

describe('LocationInsights::haversine', function () {
    it('returns zero for the same point', function () {
        expect(LocationInsights::haversine(14.657, 120.957, 14.657, 120.957))->toBe(0.0);
    });

    it('measures a known north-south offset', function () {
        // 0.001 degrees of latitude is ~111.2 m anywhere on the globe.
        expect(LocationInsights::haversine(14.657, 120.957, 14.658, 120.957))
            ->toBeGreaterThan(110.0)
            ->toBeLessThan(112.5);
    });

    it('shrinks longitude degrees at Malabon latitude', function () {
        // cos(14.66°) ≈ 0.9675, so 0.001° of longitude is ~107.6 m here.
        expect(LocationInsights::haversine(14.657, 120.957, 14.657, 120.958))
            ->toBeGreaterThan(106.0)
            ->toBeLessThan(109.0);
    });

    it('is symmetric', function () {
        $there = LocationInsights::haversine(14.65, 120.95, 14.68, 120.98);
        $back = LocationInsights::haversine(14.68, 120.98, 14.65, 120.95);

        expect(round($there, 6))->toBe(round($back, 6));
    });
});

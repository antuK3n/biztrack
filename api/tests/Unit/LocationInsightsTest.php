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

/*
 * The invariant the residual `'Manufacturing'` bucket broke.
 *
 * Sixteen unnamed divisions used to share that one word. A dairy applicant
 * (division 10, *Food & Beverage Manufacturing*) read "Most common line of
 * business — Manufacturing" and reasonably took it to include them; it was a
 * sibling bucket that excluded them by construction, and no reading of the
 * screen recovered that.
 *
 * A residual bucket is fine. A residual bucket whose NAME is the parent term of
 * a named bucket beside it is not. These tests state that as a property over the
 * whole table rather than as five sampled labels, because the old sampling test
 * passed the entire time the defect was live — it never happened to sample the
 * residual one.
 */
/**
 * Every distinct label the table can produce, read through the public API so the
 * test constrains behaviour rather than a private constant.
 *
 * @return list<string>
 */
function everyCategoryLabel(): array
{
    $labels = [];

    foreach (range(0, 99) as $division) {
        $label = PsicTaxonomy::category(sprintf('%02d000', $division));

        if ($label !== PsicTaxonomy::UNCLASSIFIED) {
            $labels[$label] = true;
        }
    }

    return array_keys($labels);
}

describe('PsicTaxonomy::category — no bucket is named as a superset of another', function () {
    it('lets no label contain another label', function () {
        /*
         * Containment is the exact shape of the defect: 'Manufacturing' inside
         * 'Food & Beverage Manufacturing'. When one bucket's name is a fragment
         * of another's, the shorter reads as the category and the longer as one
         * of its kinds — so a figure reported under the shorter name looks like
         * it counts the longer, and it does not.
         *
         * The catch-all is excluded on purpose. 'Other' is a fragment of 'Boats
         * & Other Transport Equipment' and that is harmless: 'Other' claims to
         * contain nothing, so nobody reads it as a parent term.
         */
        $labels = everyCategoryLabel();

        foreach ($labels as $a) {
            foreach ($labels as $b) {
                if ($a === $b) {
                    continue;
                }

                expect(str_contains($b, $a))->toBeFalse(
                    "Category '{$a}' is contained in '{$b}', so '{$a}' reads as a bucket that ".
                    "includes '{$b}' while in fact excluding it. Give it a name for what it ".
                    'actually makes.'
                );
            }
        }
    });

    it('gives the client six manufacturers six honest names, not one word', function () {
        /*
         * The exact codes found within 500 m of the reported pin. Under the old
         * table all six answered 'Manufacturing'; under this one each says what
         * it makes, and none of them collides with the applicant's own division.
         */
        expect(PsicTaxonomy::category('31001'))->toBe('Furniture Manufacturing')
            ->and(PsicTaxonomy::category('23950'))->toBe('Concrete, Glass & Ceramics')
            ->and(PsicTaxonomy::category('22200'))->toBe('Rubber & Plastics')
            ->and(PsicTaxonomy::category('20230'))->toBe('Chemicals & Cleaning Products')
            ->and(PsicTaxonomy::category('25920'))->toBe('Metalwork & Machine Shops')
            ->and(PsicTaxonomy::category('32110'))->toBe('Jewellery, Toys & Small Goods');
    });

    it('keeps the dairy applicant own division out of every one of them', function () {
        // The complaint in one assertion: their category is not the mode's, and
        // was never meant to be.
        $dairy = PsicTaxonomy::category('10500');

        expect($dairy)->toBe('Food & Beverage Manufacturing')
            ->and($dairy)->not->toBe(PsicTaxonomy::category('31001'))
            ->and($dairy)->not->toBe(PsicTaxonomy::category('23950'))
            ->and($dairy)->not->toBe(PsicTaxonomy::category('22200'))
            // ...but a bakeshop IS in it. Division 10 is food manufacturing, and
            // that is the neighbour the applicant actually has.
            ->and(PsicTaxonomy::category('10711'))->toBe($dairy);
    });
});

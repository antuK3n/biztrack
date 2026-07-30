<?php

namespace App\Support;

/**
 * Two levels of "what kind of business is this?", both read straight off the
 * PSIC code rather than invented for this feature.
 *
 * PSIC nests section → division (2 digits) → group (3) → class (4) → sub-class
 * (5). The register stores the 5-digit sub-class, so relatedness is already
 * encoded in the code itself and does not need a hand-drawn similarity table:
 *
 *  - **group()** — the first 3 digits. This is the standard's own notion of
 *    "same or related trade": 56301 (coffee shop) and 56302 (bar, beer house)
 *    share group 563 *Beverage serving activities*, while 56101 (restaurant)
 *    sits in 561 with fast food and food carts. Used for "similar businesses".
 *  - **category()** — a plain-language name for the division, for the
 *    "most common business type nearby" mode. An applicant reads "Retail
 *    Trade", not "division 47".
 *
 * The category names are deliberately narrower than PSIC sections: "Foods &
 * Beverages" is division 56 (food *service*) only. Bakeshops and water
 * refilling stations are food *manufacturing* and get their own name, because
 * labelling a bakery "Foods & Beverages" would tell an applicant opening a café
 * that they have four food-service neighbours when they have none.
 */
final class PsicTaxonomy
{
    /** Division prefix (first 2 digits of the PSIC code) → plain-language category. */
    private const DIVISIONS = [
        '10' => 'Food & Beverage Manufacturing',
        '11' => 'Food & Beverage Manufacturing',
        '12' => 'Manufacturing',
        '13' => 'Garments & Footwear',
        '14' => 'Garments & Footwear',
        '15' => 'Garments & Footwear',
        '16' => 'Wood, Paper & Printing',
        '17' => 'Wood, Paper & Printing',
        '18' => 'Wood, Paper & Printing',
        '19' => 'Manufacturing',
        '20' => 'Manufacturing',
        '21' => 'Manufacturing',
        '22' => 'Manufacturing',
        '23' => 'Manufacturing',
        '24' => 'Manufacturing',
        '25' => 'Manufacturing',
        '26' => 'Manufacturing',
        '27' => 'Manufacturing',
        '28' => 'Manufacturing',
        '29' => 'Manufacturing',
        '30' => 'Manufacturing',
        '31' => 'Manufacturing',
        '32' => 'Manufacturing',
        '33' => 'Repair Services',
        '35' => 'Water, Waste & Utilities',
        '36' => 'Water, Waste & Utilities',
        '37' => 'Water, Waste & Utilities',
        '38' => 'Water, Waste & Utilities',
        '39' => 'Water, Waste & Utilities',
        '41' => 'Construction',
        '42' => 'Construction',
        '43' => 'Construction',
        '45' => 'Motor Vehicles & Motorcycles',
        '46' => 'Wholesale Trade',
        '47' => 'Retail Trade',
        '49' => 'Transport & Logistics',
        '50' => 'Transport & Logistics',
        '51' => 'Transport & Logistics',
        '52' => 'Transport & Logistics',
        '53' => 'Transport & Logistics',
        '55' => 'Accommodation',
        '56' => 'Foods & Beverages',
        '58' => 'Information & Technology',
        '59' => 'Information & Technology',
        '60' => 'Information & Technology',
        '61' => 'Information & Technology',
        '62' => 'Information & Technology',
        '63' => 'Information & Technology',
        '64' => 'Financial Services',
        '65' => 'Financial Services',
        '66' => 'Financial Services',
        '68' => 'Real Estate',
        '69' => 'Professional Services',
        '70' => 'Professional Services',
        '71' => 'Professional Services',
        '72' => 'Professional Services',
        '73' => 'Professional Services',
        '74' => 'Professional Services',
        '75' => 'Professional Services',
        '77' => 'Business Support Services',
        '78' => 'Business Support Services',
        '79' => 'Business Support Services',
        '80' => 'Business Support Services',
        '81' => 'Business Support Services',
        '82' => 'Business Support Services',
        '85' => 'Education',
        '86' => 'Health Services',
        '87' => 'Health Services',
        '88' => 'Health Services',
        '90' => 'Recreation & Entertainment',
        '91' => 'Recreation & Entertainment',
        '92' => 'Recreation & Entertainment',
        '93' => 'Recreation & Entertainment',
        '94' => 'Business Support Services',
        '95' => 'Repair Services',
        '96' => 'Personal Services',
    ];

    /** Where a code we cannot place lands, including the catch-all 00000 row. */
    public const UNCLASSIFIED = 'Other';

    /**
     * The PSIC group — first 3 digits — or null for a code that carries no
     * classification at all (the catch-all 00000 "Other (not listed)").
     *
     * Returning null matters: 00000 means "the applicant could not find their
     * trade in the list", so every 00000 business would otherwise count as
     * every other 00000 business's near neighbour in the same trade.
     */
    public static function group(?string $code): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $code) ?? '';

        if (strlen($digits) < 3 || ltrim($digits, '0') === '') {
            return null;
        }

        return substr($digits, 0, 3);
    }

    /** Plain-language category name for a PSIC code. */
    public static function category(?string $code): string
    {
        $digits = preg_replace('/\D/', '', (string) $code) ?? '';

        if (strlen($digits) < 2) {
            return self::UNCLASSIFIED;
        }

        return self::DIVISIONS[substr($digits, 0, 2)] ?? self::UNCLASSIFIED;
    }
}

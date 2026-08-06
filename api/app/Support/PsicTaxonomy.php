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
 *
 * ## No division shares a name with a bucket that excludes it
 *
 * Sixteen manufacturing divisions used to collapse into the single word
 * `'Manufacturing'` — not as a design choice but as the leftover bucket for the
 * divisions nobody had named. It produced the one defect a taxonomy must never
 * produce, and a client found it on the first try:
 *
 *   An applicant filing PSIC 10500 *Manufacture of dairy products* read
 *   "Most common line of business — Manufacturing (6 of 33)" directly above
 *   "Similar businesses — 0", and reasonably concluded the count was broken.
 *   It was not. Their division 10 is *Food & Beverage Manufacturing*; the six
 *   were furniture, concrete and plastics. `'Manufacturing'` read as a superset
 *   containing them while being a sibling that excluded them by construction.
 *
 * A residual bucket is fine. A residual bucket whose *name* is the parent term
 * of the named buckets beside it is not, because there is no reading of the
 * screen that recovers the truth. So every division below carries a name for
 * what it actually makes, and no two buckets stand in a superset relation.
 * `UNCLASSIFIED` is the only catch-all left, and it says "Other" — a word that
 * claims to contain nothing.
 *
 * The names describe output, not process, because the reader is choosing a
 * street corner and not filling in a census form: "Concrete, Glass & Ceramics"
 * over PSIC's "Manufacture of other non-metallic mineral products".
 */
final class PsicTaxonomy
{
    /**
     * Division prefix (first 2 digits of the PSIC code) → plain-language category.
     *
     * Every entry names its own trade. Adding a division here means choosing a
     * name for it; it must never mean reaching for the nearest broad word, which
     * is how `'Manufacturing'` came to cover sixteen unrelated divisions.
     */
    private const DIVISIONS = [
        '10' => 'Food & Beverage Manufacturing',
        '11' => 'Food & Beverage Manufacturing',
        '12' => 'Tobacco Products',
        '13' => 'Garments & Footwear',
        '14' => 'Garments & Footwear',
        '15' => 'Garments & Footwear',
        '16' => 'Wood, Paper & Printing',
        '17' => 'Wood, Paper & Printing',
        '18' => 'Wood, Paper & Printing',
        '19' => 'Fuel & Petroleum Products',
        '20' => 'Chemicals & Cleaning Products',
        '21' => 'Medicines & Pharmaceuticals',
        '22' => 'Rubber & Plastics',
        '23' => 'Concrete, Glass & Ceramics',
        '24' => 'Metal Production',
        '25' => 'Metalwork & Machine Shops',
        // 26 makes the devices; 58–63 below sell and service them. Distinct
        // names, because a block full of electronics assemblers is not a block
        // full of software firms.
        '26' => 'Electronics Manufacturing',
        '27' => 'Electrical Equipment',
        '28' => 'Machinery & Equipment',
        // 29 builds vehicles; 45 sells and repairs them. Same caution as 26.
        '29' => 'Motor Vehicle Manufacturing',
        '30' => 'Boats & Other Transport Equipment',
        '31' => 'Furniture Manufacturing',
        // PSIC's own residual class, not ours: division 32 is titled "Other
        // manufacturing" in the standard and covers jewellery, musical
        // instruments, toys and medical appliances. Naming what it actually
        // holds beats inheriting the standard's shrug.
        '32' => 'Jewellery, Toys & Small Goods',
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

<?php

namespace App\Support;

final class PsicTaxonomy
{
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

    public const UNCLASSIFIED = 'Other';

    public static function group(?string $code): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $code) ?? '';

        if (strlen($digits) < 3 || ltrim($digits, '0') === '') {
            return null;
        }

        return substr($digits, 0, 3);
    }

    public static function category(?string $code): string
    {
        $digits = preg_replace('/\D/', '', (string) $code) ?? '';

        if (strlen($digits) < 2) {
            return self::UNCLASSIFIED;
        }

        return self::DIVISIONS[substr($digits, 0, 2)] ?? self::UNCLASSIFIED;
    }
}

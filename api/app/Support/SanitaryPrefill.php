<?php

namespace App\Support;

use App\Models\Application;
use App\Services\FeeCalculator;

/**
 * The Sanitary sheet's classification, suggested from the line of business.
 *
 * ── Why a suggestion and not a derivation ────────────────────────────────────
 *
 * PD 856 sorts establishments into food, non-food, personal/public service and
 * industrial, and the City Health Office inspects each to a different checklist.
 * The filing already knows the trade — the PSIC class and permit category the
 * fee engine prices on — so the box can be filled from that. But the mapping
 * is a reading of the trade, not a fact of it: a sari-sari store that fries
 * fishballs out front is a food establishment to a sanitary inspector and a
 * retailer to the Revenue Code. So it is OFFERED, on the `prefill` channel,
 * flagged "suggested from your line of business", and the applicant may
 * change it — the same channel the account's home address uses.
 *
 * Client, 5 October 2026, on this sheet: *"If something needs auto-filling,
 * do so."*
 */
final class SanitaryPrefill
{
    private const FOOD = [
        'restaurant', 'bar_nightclub', 'cafe_cafeteria', 'independent_caterer',
        'liquor_serving', 'amusement_place',
    ];

    private const INDUSTRIAL = [
        'manufacturer', 'essential_manufacturer', 'manufacturer_small_scale',
        'lathe_machine_shop', 'liquor_manufacturer',
    ];

    private const PERSONAL_OR_PUBLIC_SERVICE = [
        'barber_shop', 'funeral_independent', 'medical_dental_lab', 'veterinary_clinic',
        'boarding_house', 'janitorial_manpower_service', 'learning_institute',
        'sports_recreational_facility', 'movie_house', 'sauna_massage_parlor',
        'hospital_clinic_lab',
    ];

    /**
     * @param  array<string, mixed>  $stored  the sheet's saved `form_data`, if any
     * @return array<string, string>
     */
    public static function forSheet(Application $application, string $permitTypeCode, array $stored): array
    {
        if ($permitTypeCode !== 'SANITARY') {
            return [];
        }
        if (trim((string) ($stored['sanitary_classification'] ?? '')) !== '') {
            return [];
        }

        $categories = app(FeeCalculator::class)->facts($application)['categories'] ?? [];
        if ($categories === []) {
            return [];
        }

        return ['sanitary_classification' => self::classify($categories)];
    }

    /**
     * Food wins over everything — a factory canteen is inspected as a food
     * establishment; then industrial, then personal or public service.
     *
     * @param  list<string>  $categories
     */
    public static function classify(array $categories): string
    {
        return match (true) {
            array_intersect($categories, self::FOOD) !== [] => 'Food Establishment',
            array_intersect($categories, self::INDUSTRIAL) !== [] => 'Industrial',
            array_intersect($categories, self::PERSONAL_OR_PUBLIC_SERVICE) !== [] => 'Personal / Public Service',
            default => 'Non-Food Establishment',
        };
    }
}

<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rule;

/**
 * What each office sheet's answer may hold, key by key.
 *
 * Client, 10 November 2026 ("screen + server"): `form_data` was opaque JSON,
 * so a count took "two", a ZIP code took "abcde", a pick-one took a word the
 * list never offered, and an answer could be a nested object the certificate
 * then printed as "Array". The sheet's own controls narrowed most of that in
 * the browser; nothing stopped any other caller.
 *
 * Per sheet, because one key means different things on different papers —
 * `application_type` is OBO's Full / Partial and CHO's derived New / Renewal.
 * A key a sheet does not list is still refused as an array or object, and
 * otherwise kept as sent: the payload stays the applicant's, and a key added
 * to a sheet tomorrow is not refused for want of a line here.
 *
 * ── The option lists are mirrored, not shared ───────────────────────────────
 *
 * web/src/pages/applicant/OfficeFormStep.tsx declares the same lists
 * (SANITARY_CLASSIFICATIONS, WATER_SOURCES, … COMPLETION_PROFESSIONS) and draws
 * the chips and selects from them. PHP cannot import that file and the sheet
 * renders before any request could fetch them, so the two are kept in step
 * by hand: change a list in both places, in the same spelling, or the sheet
 * offers an answer this class refuses.
 */
class OfficeFormRules
{
    public const SANITARY_CLASSIFICATIONS = [
        'Food Establishment',
        'Non-Food Establishment',
        'Personal / Public Service',
        'Industrial',
    ];

    public const WATER_SOURCES = ['Level III (Waterworks)', 'Deep Well', 'Bottled / Refill', 'Other'];

    public const TOILET_TYPES = ['Water-sealed flush', 'Pour-flush', 'Other'];

    public const SEWAGE_DISPOSALS = ['Public sewer (Maynilad)', 'Septic tank', 'Other'];

    public const SOLID_WASTE_DISPOSALS = [
        'City garbage collection',
        'Private hauler',
        'Composting / recycling',
        'Other',
    ];

    public const PEST_CONTROL_MEASURES = ['Contracted pest control service', 'Own measures', 'None'];

    public const YES_NO = ['Yes', 'No'];

    public const OCCUPANCY_SCOPES = ['Full', 'Partial'];

    public const ZONING_INDUSTRIAL_PROJECT_TYPES = [
        'Pollutive',
        'Non-Pollutive',
        'Hazardous',
        'Non-Hazardous',
        'Other',
    ];

    /**
     * Who signed the Certificate of Completion: the two professions NBC Form
     * B-13 prints (client, 10 November 2026). OBO's Documents Submitted is
     * not here: the API ticks it from the uploads (OfficeFormAnswers), so it
     * is derived and never judged as an answer.
     */
    public const COMPLETION_PROFESSIONS = ['Civil Engineer', 'Architect'];

    /**
     * The rules for the keys one sheet asks, as `form_data.<key>` => rules.
     *
     * @return array<string, list<mixed>>
     */
    public static function forSheet(string $code): array
    {
        $text = ['nullable', 'string', 'max:255'];
        $name = ['nullable', 'string', 'max:255', ContactRules::personName()];
        $pick = fn (array $options) => ['nullable', 'string', Rule::in($options)];
        $storeys = ['nullable', 'integer', 'min:1', 'max:200'];

        $rules = match ($code) {
            'ZONING' => [
                'zoning_home_address' => $text,
                'zoning_project_description' => ['nullable', 'string', 'max:1000'],
                'building_storeys' => $storeys,
                'lessor_name' => $text,
                'lessor_address' => $text,
                'zoning_industrial_project_type' => $pick(self::ZONING_INDUSTRIAL_PROJECT_TYPES),
                'authorized_representative' => $name,
            ],
            'SANITARY' => [
                'sanitary_classification' => $pick(self::SANITARY_CLASSIFICATIONS),
                'operating_hours' => $text,
                'seating_capacity' => ['nullable', 'integer', 'min:0', 'max:100000'],
                'has_kitchen' => $pick(self::YES_NO),
                'has_cold_storage' => $pick(self::YES_NO),
                'water_source' => $pick(self::WATER_SOURCES),
                'toilets_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
                'toilet_type' => $pick(self::TOILET_TYPES),
                'toilets_separate_sexes' => $pick(self::YES_NO),
                'sewage_disposal' => $pick(self::SEWAGE_DISPOSALS),
                'solid_waste_disposal' => $pick(self::SOLID_WASTE_DISPOSALS),
                'waste_segregation' => $pick(self::YES_NO),
                'pest_control' => $pick(self::PEST_CONTROL_MEASURES),
            ],
            'CEC' => [
                'owner_address' => $text,
            ],
            'FSIC' => [
                'authorized_representative' => $name,
                'occupancy_type' => $text,
                'building_storeys' => $storeys,
            ],
            'OCCUPANCY' => [
                'application_type' => $pick(self::OCCUPANCY_SCOPES),
                'building_permit_no' => $text,
                'fsec_no' => $text,
                'owner_address' => $text,
                'owner_zip' => ['nullable', 'regex:/^\d{4}$/'],
                'owner_tel' => ['nullable', 'string', 'max:40', ContactRules::phone()],
                'owner_ctc_no' => ['nullable', 'regex:/^\d{1,20}$/'],
                'owner_ctc_place' => $text,
                'project_name' => $text,
                'project_location' => $text,
                'occupancy_type' => $text,
                'building_storeys' => $storeys,
                'building_units' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'total_floor_area_sqm' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],
                'completion_other_document' => $text,
                'completion_submitted_by' => $name,
                'completion_professional' => $pick(self::COMPLETION_PROFESSIONS),
            ],
            default => [],
        };

        $out = [];
        foreach ($rules as $key => $rule) {
            $out["form_data.$key"] = $rule;
        }

        return $out;
    }

    /**
     * One short line per kind of mistake (AGENTS.md §6.4; client, short
     * validation messages). Keyed by rule, so a key added above inherits its
     * wording.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'form_data.*.integer' => 'Enter a whole number.',
            'form_data.*.numeric' => 'Enter a number.',
            'form_data.*.in' => 'Choose one of the listed options.',
            'form_data.*.string' => 'Enter text here.',
            'form_data.zoning_project_description.max' => 'Keep this under 1,000 characters.',
            'form_data.building_storeys.min' => 'Enter 1 to 200 storeys.',
            'form_data.building_storeys.max' => 'Enter 1 to 200 storeys.',
            'form_data.building_units.min' => 'Enter at least 1 unit.',
            'form_data.building_units.max' => 'Enter a smaller number of units.',
            'form_data.toilets_count.min' => 'Enter 0 to 1,000 toilets.',
            'form_data.toilets_count.max' => 'Enter 0 to 1,000 toilets.',
            'form_data.seating_capacity.min' => 'Enter 0 or more seats.',
            'form_data.seating_capacity.max' => 'Enter a smaller seating capacity.',
            'form_data.total_floor_area_sqm.gt' => 'Enter an area above zero.',
            'form_data.total_floor_area_sqm.max' => 'Enter a smaller floor area.',
            'form_data.owner_zip.regex' => 'Enter a 4-digit ZIP code.',
            'form_data.owner_ctc_no.regex' => 'Enter the CTC number in digits.',
            // Last, so the per-key wording above wins (first match is used).
            'form_data.*.max' => 'This answer is too long.',
        ];
    }

    /**
     * An answer is one value — text, a number, yes or no — never a list or
     * an object. Applied to every key the payload carries, listed or not.
     */
    public static function scalar(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_array($value) || is_object($value)) {
                $fail('Enter a single answer here.');
            }
        };
    }
}

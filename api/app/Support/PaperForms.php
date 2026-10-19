<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Models\Application;
use App\Models\ApplicationAmendment;
use App\Models\ApplicationOfficeForm;
use App\Models\PsicCode;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The values a printed paper form needs, read off one filing.
 *
 * Client, 19 October 2026: *"It is also requested to us to have a downloadable
 * application form in the admin side when viewing the filing/application…
 * Also, the printed format should be the same as the paper-based forms."*
 *
 * The forms themselves are Blade views under `pdf/forms/`, each laid out after
 * the office's own paper (MCG-BPLO-FO-001/002, the BPLO Amendment Form,
 * BFP-QSF-FSED-002, the OBO Unified Application Form, and the system's own
 * Zoning, Sanitary and CENRO sheets where no paper photo exists). This class is
 * the ONE place that reads the filing for them, so a view never queries and two
 * views never disagree about what, say, the floor area is.
 *
 * ── Which figure, when two exist ───────────────────────────────────────────
 *
 * The Business Operation figures live twice: on the filing (`fee_profile`,
 * what the applicant declared for THIS filing and what the fee was assessed
 * on) and on the business record (the current register). The filing wins —
 * a printed application form is a record of what was filed — and the business
 * record fills only what the filing never asked.
 *
 * ── Blank is honest ────────────────────────────────────────────────────────
 *
 * Every value here is a string, '' when the register does not hold it. A view
 * prints the blank line the paper prints. Nothing is invented to fill a box:
 * the officer writes it, as they would on the paper.
 */
final class PaperForms
{
    /** The BPLO form code by filing type, as ApplyWizard prints it. */
    public const BPLO_FORM = [
        'new' => ['id' => 'MCG-BPLO-FO-001', 'version' => '2.0', 'prepared' => '08-01-2025'],
        'renewal' => ['id' => 'MCG-BPLO-FO-002', 'version' => '2.0', 'prepared' => '08-01-2025'],
        'amendment' => ['id' => 'MCG-BPLO-FO-003', 'version' => '2.0', 'prepared' => '08-01-2025'],
    ];

    /** Paper sizes. The BPLO and office A4 sheets; long bond (8.5" × 13") for the rest. */
    public const LONG_BOND = [0, 0, 612, 936];

    /** @return array<string, mixed> */
    public static function common(Application $application): array
    {
        $application->loadMissing([
            'business' => fn ($q) => $q->withTrashed(),
            'business.address.barangay',
            'business.owners',
            'business.lines.psicCode',
            'business.owner',
            'applicant',
            'priorPermit',
            'feeAssessment',
            'documents.documentType',
        ]);

        $business = $application->business;
        $address = $business?->address;
        $profile = is_array($application->fee_profile) ? $application->fee_profile : [];
        $owner = $business?->owners?->firstWhere('is_primary', true) ?? $business?->owners?->first();
        $applicant = $application->applicant ?? $business?->owner;

        $s = fn ($v): string => $v === null ? '' : trim((string) $v);
        $num = function ($v) use ($s): string {
            if ($v === null || $v === '') {
                return '';
            }

            return is_numeric($v) ? (string) (0 + $v) : $s($v);
        };
        $pick = function (...$vals) use ($num): string {
            foreach ($vals as $v) {
                $out = $num($v);
                if ($out !== '') {
                    return $out;
                }
            }

            return '';
        };

        $male = $pick($profile['male_employees'] ?? null, $business?->male_employees);
        $female = $pick($profile['female_employees'] ?? null, $business?->female_employees);
        $total = $pick($profile['employees'] ?? null, $business?->total_employees);
        if ($total === '' && ($male !== '' || $female !== '')) {
            $total = (string) ((int) $male + (int) $female);
        }

        $delivery = $business?->delivery_units;
        if (isset($profile['delivery_vehicles_motorized']) || isset($profile['delivery_vehicles_other'])) {
            $delivery = (int) ($profile['delivery_vehicles_motorized'] ?? 0) + (int) ($profile['delivery_vehicles_other'] ?? 0);
        }

        $barangay = $s($address?->barangay?->name);
        $house = $s($address?->house_bldg_no);
        $street = $s($address?->street ?: $address?->line1);
        $city = $s($address?->city ?: 'Malabon');
        $province = $s($address?->province ?: 'Metro Manila');
        $postal = $s($address?->postal_code);
        $fullAddress = implode(', ', array_filter([
            trim($house.' '.$street), $barangay !== '' ? 'Brgy. '.$barangay : '', $city !== '' ? $city.' City' : '',
        ]));

        $ownerName = $owner
            ? trim(implode(' ', array_filter([$owner->given_name, $owner->middle_name, $owner->surname, $owner->suffix])))
            : $s($applicant?->name);
        $ownerLastFirst = $owner
            ? trim($s($owner->surname).', '.trim($s($owner->given_name).' '.$s($owner->middle_name)), ', ')
            : $s($applicant?->name);

        $homeAddress = implode(', ', array_filter([
            $s($applicant?->home_street), $s($applicant?->home_barangay) !== '' ? 'Brgy. '.$s($applicant?->home_barangay) : '',
            $s($applicant?->home_city), $s($applicant?->home_province),
        ]));

        // Lines of business: the business's own rows, gross sales from the filing.
        $profileLines = collect($profile['lines'] ?? [])->keyBy(fn ($l) => (int) ($l['psic_code_id'] ?? 0));
        $lines = ($business?->lines ?? collect())->map(function ($line) use ($profileLines, $s) {
            $fromProfile = $profileLines->get((int) $line->psic_code_id) ?? [];

            return [
                'line' => $s($line->line_of_business ?: $line->psicCode?->title),
                'products' => $s($line->products_services),
                'psic' => $s($line->psicCode?->code),
                'gross_sales' => self::money($fromProfile['gross_sales'] ?? $line->gross_sales),
                'capitalization' => self::money($fromProfile['capitalization'] ?? $line->capitalization),
            ];
        })->values()->all();
        if ($lines === [] && $profileLines->isNotEmpty()) {
            $codes = PsicCode::whereIn('id', $profileLines->keys())->get()->keyBy('id');
            foreach ($profileLines as $id => $l) {
                $lines[] = [
                    'line' => $s($codes[$id]->title ?? ''),
                    'products' => '',
                    'psic' => $s($codes[$id]->code ?? ''),
                    'gross_sales' => self::money($l['gross_sales'] ?? null),
                    'capitalization' => self::money($l['capitalization'] ?? null),
                ];
            }
        }

        $docCodes = $application->documents
            ->map(fn ($d) => $d->documentType?->code)
            ->filter()->unique()->values()->all();

        $receivedBy = $application->received_by_user_id ? User::find($application->received_by_user_id) : null;

        return [
            'tracking_id' => $s($application->tracking_id),
            'type' => $application->application_type?->value ?? 'new',
            'is_renewal' => $application->application_type === ApplicationType::Renewal,
            'submitted_on' => self::date($application->submitted_at),
            'date_received' => self::date($application->date_received ?? null),
            'received_by' => $s($receivedBy?->name),
            'printed_on' => self::date(now()),
            'ban' => $s($business?->ban),
            'prior_permit_no' => $s($application->priorPermit?->permit_number),
            'business_name' => $s($business?->name),
            'trade_name' => $s($business?->trade_name),
            'registration_type' => $s($business?->form_of_organization ?: $business?->registration_type),
            'registration_number' => $s($business?->registration_number),
            'tin' => $s($business?->tin),
            'house' => $house,
            'street' => $street,
            'barangay' => $barangay,
            'city' => $city,
            'province' => $province,
            'postal' => $postal,
            'address' => $fullAddress,
            'telephone' => $s($address?->telephone),
            'mobile' => $s($address?->mobile_number ?: $applicant?->mobile_number),
            'email' => $s($address?->email ?: $applicant?->email),
            'website' => $s($address?->website),
            'contact' => implode(' / ', array_filter([$s($address?->mobile_number ?: $applicant?->mobile_number), $s($address?->telephone)])),
            'owner_surname' => $s($owner?->surname),
            'owner_given' => $s($owner?->given_name),
            'owner_middle' => $s($owner?->middle_name),
            'owner_mi' => $owner && $s($owner->middle_name) !== '' ? mb_substr($s($owner->middle_name), 0, 1).'.' : '',
            'owner_suffix' => $s($owner?->suffix),
            'owner_gender' => match (strtoupper($s($owner?->gender ?? $applicant?->gender))) {
                'M', 'MALE' => 'Male', 'F', 'FEMALE' => 'Female', default => '',
            },
            'owner_name' => $ownerName,
            'owner_last_first' => $ownerLastFirst,
            'home_address' => $homeAddress,
            'home_postal' => $s($applicant?->home_postal_code),
            'president' => $s($business?->president_officer_name),
            'citizenship' => $s($business?->citizenship),
            'capital_participation' => $num($business?->capital_participation_filipino),
            'economic_organization' => $s($business?->economic_organization),
            'economic_organization_others' => $s($business?->economic_organization_others),
            'capital_investment' => self::money($profile['capitalization'] ?? $business?->capital_investment),
            'area' => $pick($profile['floor_area_sqm'] ?? null, $business?->business_area_sqm),
            'storeys' => $pick($profile['storeys'] ?? null),
            'employees_male' => $male,
            'employees_female' => $female,
            'employees_total' => $total,
            'employees_lgu' => $pick($profile['employees_in_lgu'] ?? null, $business?->employees_within_lgu),
            'delivery_units' => $num($delivery),
            'has_tax_incentives' => (bool) ($business?->has_tax_incentives),
            'pays_rent' => (bool) ($business?->pays_rent || $business?->is_rented),
            'lessor_name' => $s($business?->lessor_name),
            'lessor_address' => $s($business?->lessor_address),
            'lines' => $lines,
            'payment_mode' => $s($application->payment_mode ?: 'annual'),
            'privacy_consent' => (bool) $application->data_privacy_consent,
            'documents' => $docCodes,
            'has_amendments' => (bool) $application->has_amendments,
            'amendment_ownership' => (bool) $application->amendment_ownership,
            'amendment_location' => (bool) $application->amendment_location,
            'amendment_nature' => (bool) $application->amendment_nature,
            'amendment_other' => $s($application->amendment_other),
            'amend_from' => $s($application->amendment_from_registration_type),
            'amend_to' => $s($application->amendment_to_registration_type),
            'fee_total' => self::money($application->feeAssessment?->total_amount),
            'seal' => file_exists(public_path('malabon-seal.png')) ? public_path('malabon-seal.png') : null,
        ];
    }

    /**
     * Annex 1 of FO-001 — each clearance's standing, and what the fire office
     * assessed. A clearance the office has issued reads as "Yes"; one it
     * refused reads as "No"; anything still in flight is left for the
     * evaluator to tick, as on the paper.
     *
     * @return array{verification: array<string, string>, fsic_fee: string}
     */
    public static function annex(Application $application): array
    {
        $verification = [];
        foreach ($application->permitTypes as $type) {
            $status = $type->pivot?->status;
            $verification[$type->code] = match ($status instanceof ClearanceStatus ? $status : ClearanceStatus::tryFrom((string) $status)) {
                ClearanceStatus::Approved => 'yes',
                ClearanceStatus::Rejected => 'no',
                default => '',
            };
        }

        return ['verification' => $verification, 'fsic_fee' => self::feeFor($application, 'FSIC')];
    }

    /** The amount the filing's assessment bills for one permit type, '' when none. */
    public static function feeFor(Application $application, string $permitCode): string
    {
        $items = $application->feeAssessment?->line_items;
        $items = is_string($items) ? json_decode($items, true) : $items;
        if (! is_array($items)) {
            return '';
        }

        $sum = 0.0;
        $found = false;
        foreach ($items as $item) {
            if (in_array($permitCode, (array) ($item['permit_codes'] ?? []), true)) {
                $sum += (float) ($item['amount'] ?? 0);
                $found = true;
            }
        }

        return $found ? self::money($sum) : '';
    }

    /**
     * The amendment rows grouped the way FO-003's paper groups them.
     *
     * @return array<string, array<string, array{label: string, from: string, to: string}>>
     */
    public static function amendments(Application $application): array
    {
        $groups = ['address' => [], 'ownership' => [], 'trade_name' => [], 'other' => []];

        $rows = ApplicationAmendment::where('application_id', $application->id)->orderBy('id')->get();
        foreach ($rows as $row) {
            $group = AmendableFields::group($row->field) ?? 'other';
            $groups[$group][$row->field] = [
                'label' => AmendableFields::allows($row->field) ? AmendableFields::label($row->field) : $row->field,
                'from' => (string) (AmendableFields::describe($row->field, $row->old_value) ?? $row->old_value ?? ''),
                'to' => (string) (AmendableFields::describe($row->field, $row->new_value) ?? $row->new_value ?? ''),
            ];
        }

        return $groups;
    }

    /**
     * One office sheet's answers, derived ones merged in, plus its checklist
     * state keyed by row key (true = the applicant attached it).
     *
     * @return array{data: array<string, mixed>, ticks: array<string, bool>, saved_on: string}
     */
    public static function sheet(Application $application, string $code): array
    {
        $form = ApplicationOfficeForm::where('application_id', $application->id)
            ->whereHas('permitType', fn ($q) => $q->where('code', $code))
            ->first();

        $data = OfficeFormAnswers::derive($application, $code, is_array($form?->form_data) ? $form->form_data : []);

        $ticks = [];
        foreach (SheetRequirements::for($application, $code) ?? [] as $row) {
            $ticks[$row['key']] = (bool) ($row['satisfied'] ?? false);
        }

        $submitted = $application->permitTypes->firstWhere('code', $code)?->pivot?->submitted_at;

        return [
            'data' => $data,
            'ticks' => $ticks,
            'saved_on' => self::date($submitted ?? $form?->updated_at),
        ];
    }

    public static function money($value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '';
        }

        return number_format((float) $value, 2);
    }

    public static function date($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->timezone(config('app.timezone'))->format('m/d/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}

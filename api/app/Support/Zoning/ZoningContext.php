<?php

namespace App\Support\Zoning;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\ApplicationOfficeForm;
use App\Models\Barangay;
use App\Models\PermitType;
use App\Models\PsicCode;

/**
 * Everything the zoning check reads about one filing, from wherever it lives.
 *
 * Two builders, one shape, because the check has two callers that must not
 * disagree: the applicant's wizard asks with what is on screen (some of it not
 * yet saved), and the officer's review sheet asks with what the filing holds.
 * Both end here, and ZoningCheck never reads a model or a request itself.
 */
final class ZoningContext
{
    /**
     * @param  list<array{psic: PsicCode, capitalization: ?float, gross_sales: ?float, description: string}>  $lines
     * @param  array<string, mixed>  $facts  Applicant facts with officer answers laid over them.
     * @param  array<string, string>  $factSources  key => 'applicant' | 'officer'
     * @param  array{line_changed: bool, area_expanded: ?bool, moved: bool}|null  $amendment
     */
    public function __construct(
        public readonly ?Barangay $barangay,
        public readonly array $lines,
        public readonly ?string $applicationType,
        public readonly ?float $floorArea,
        public readonly ?float $lotArea,
        public readonly ?int $employees,
        public readonly ?float $capitalization,
        public readonly ?string $street,
        public readonly ?bool $isRented,
        public readonly ?int $storeys,
        public readonly ?string $sheetIndustryType,
        public readonly array $facts,
        public readonly array $factSources,
        public readonly ?array $amendment = null,
    ) {}

    /** A fact's value, or null when nobody has answered it. */
    public function fact(string $key): mixed
    {
        return $this->facts[$key] ?? null;
    }

    /**
     * Every line's PSIC title with the applicant's own description of it,
     * lower-cased — what the clause triggers read. "Retail sale of second-hand
     * goods" says nothing about a junk shop; "junk shop, scrap metal" typed
     * beside it does.
     *
     * @return list<string>
     */
    public function titles(): array
    {
        return array_values(array_map(
            fn (array $l) => mb_strtolower(trim($l['psic']->title.' '.($l['description'] ?? ''))),
            $this->lines,
        ));
    }

    /**
     * The applicant's own description of each line, lower-cased, without the
     * PSIC title — for what the title cannot say (a pay parking lot under
     * "other transportation support", a driving school under "other
     * education").
     *
     * @return list<string>
     */
    public function descriptions(): array
    {
        return array_values(array_map(fn (array $l) => mb_strtolower(trim((string) ($l['description'] ?? ''))), $this->lines));
    }

    /** @return list<string> PSIC codes of every line. */
    public function psicCodes(): array
    {
        return array_values(array_map(fn (array $l) => (string) $l['psic']->code, $this->lines));
    }

    /**
     * Build from the wizard's request.
     *
     * @param  array<string, mixed>  $data  Validated by ZoningCheckController.
     */
    public static function fromRequest(array $data): self
    {
        $barangay = isset($data['barangay_id'])
            ? Barangay::with(['zoningClassifications', 'zoningOverlays'])->find($data['barangay_id'])
            : null;

        $lines = [];
        foreach ((array) ($data['lines'] ?? []) as $line) {
            $psic = isset($line['psic_code_id']) ? PsicCode::find($line['psic_code_id']) : null;
            if ($psic === null) {
                continue;
            }
            $lines[] = [
                'psic' => $psic,
                'capitalization' => self::num($line['capitalization'] ?? null),
                'gross_sales' => self::num($line['gross_sales'] ?? null),
                'description' => trim((string) ($line['description'] ?? '')),
            ];
        }

        $facts = ZoningFacts::clean($data['zoning_facts'] ?? null, ZoningFacts::applicantKeys());

        return new self(
            barangay: $barangay,
            lines: $lines,
            applicationType: $data['application_type'] ?? null,
            floorArea: self::num($data['floor_area_sqm'] ?? null),
            lotArea: self::num($data['lot_area_sqm'] ?? null),
            employees: self::int($data['employees'] ?? null),
            capitalization: self::num($data['capitalization'] ?? null),
            street: isset($data['street']) ? (string) $data['street'] : null,
            isRented: isset($data['is_rented']) ? (bool) $data['is_rented'] : null,
            storeys: self::int($data['storeys'] ?? null),
            sheetIndustryType: null,
            facts: $facts,
            factSources: array_fill_keys(array_keys($facts), 'applicant'),
        );
    }

    /**
     * Build from a stored filing, for the officer's review sheet and for the
     * applicant reopening a filing.
     *
     * An amendment is assessed as it WILL BE: the register still holds the old
     * address and trade until BPLO approves, and CPDO is deciding the new one,
     * so a requested barangay or line of business replaces the current one.
     */
    public static function fromApplication(Application $application): self
    {
        $application->loadMissing([
            'business.address.barangay.zoningClassifications',
            'business.address.barangay.zoningOverlays',
            'business.lines.psicCode',
            'requestedChanges',
        ]);
        $business = $application->business;
        $address = $business?->address;
        $profile = is_array($application->fee_profile) ? $application->fee_profile : [];
        $requested = $application->requestedChanges->pluck('new_value', 'field')->all();

        $barangay = $address?->barangay;
        if (isset($requested['address_barangay_id'])) {
            $barangay = Barangay::with(['zoningClassifications', 'zoningOverlays'])
                ->find((int) $requested['address_barangay_id']) ?? $barangay;
        }

        // Per-line money from the fee profile when the filing carries it, else
        // from the business's own lines — a renewal re-declares, a new filing
        // may still be filling the profile in.
        $profileLines = collect((array) ($profile['lines'] ?? []))->keyBy(fn ($l) => (int) ($l['psic_code_id'] ?? 0));
        $lines = [];
        foreach ($business?->lines ?? [] as $line) {
            if ($line->psicCode === null) {
                continue;
            }
            $declared = $profileLines->get($line->psic_code_id, []);
            $lines[] = [
                'psic' => $line->psicCode,
                'capitalization' => self::num($declared['capitalization'] ?? $line->capitalization),
                'gross_sales' => self::num($declared['gross_sales'] ?? $line->gross_sales),
                'description' => trim($line->line_of_business.' '.$line->products_services),
            ];
        }
        if (isset($requested['line_of_business']) && ($psic = PsicCode::find((int) $requested['line_of_business']))) {
            $lines = [['psic' => $psic, 'capitalization' => $lines[0]['capitalization'] ?? null, 'gross_sales' => $lines[0]['gross_sales'] ?? null, 'description' => '']];
        }

        $sheet = self::zoningSheet($application);

        $floorArea = self::num($profile['floor_area_sqm'] ?? null)
            ?? self::num($sheet['total_floor_area_sqm'] ?? null)
            ?? self::num($business?->business_area_sqm);
        if (isset($requested['business_area_sqm'])) {
            $floorArea = self::num($requested['business_area_sqm']);
        }

        $applicant = ZoningFacts::clean($application->zoning_facts ?? null, ZoningFacts::applicantKeys());
        $officer = ZoningFacts::clean($application->zoning_officer_facts ?? null, ZoningFacts::officerKeys());
        $sources = array_fill_keys(array_keys($applicant), 'applicant');
        foreach (array_keys($officer) as $key) {
            $sources[$key] = 'officer';
        }

        $amendment = null;
        if ($application->application_type === ApplicationType::Amendment) {
            $before = self::num($business?->business_area_sqm);
            $after = isset($requested['business_area_sqm']) ? self::num($requested['business_area_sqm']) : null;
            $amendment = [
                'line_changed' => isset($requested['line_of_business']),
                'area_expanded' => $after === null ? false : ($before === null ? null : $after > $before),
                'moved' => isset($requested['address_pin']),
            ];
        }

        return new self(
            barangay: $barangay,
            lines: $lines,
            applicationType: $application->application_type?->value,
            floorArea: $floorArea,
            lotArea: self::num($address?->lot_area_sqm),
            employees: self::int($profile['employees'] ?? $business?->total_employees),
            capitalization: self::num($profile['capitalization'] ?? null)
                ?? (array_sum(array_map(fn ($l) => $l['capitalization'] ?? 0, $lines)) ?: null),
            street: $requested['address_street'] ?? $address?->street,
            isRented: $business?->is_rented,
            storeys: self::int($sheet['building_storeys'] ?? $profile['storeys'] ?? null),
            sheetIndustryType: isset($sheet['zoning_industrial_project_type']) ? (string) $sheet['zoning_industrial_project_type'] : null,
            facts: $officer + $applicant,
            factSources: $sources,
            amendment: $amendment,
        );
    }

    /** The saved ZONING sheet's answers, or [] when it has never been opened. */
    private static function zoningSheet(Application $application): array
    {
        $typeId = PermitType::where('code', 'ZONING')->value('id');
        if ($typeId === null) {
            return [];
        }
        $form = ApplicationOfficeForm::where('application_id', $application->id)
            ->where('permit_type_id', $typeId)
            ->first();

        return is_array($form?->form_data) ? $form->form_data : [];
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

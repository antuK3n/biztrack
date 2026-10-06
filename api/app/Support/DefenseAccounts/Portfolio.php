<?php

namespace App\Support\DefenseAccounts;

use App\Enums\OfficerRequestStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\DocumentType;
use App\Models\Inspection;
use App\Models\OfficerRequest;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Support\DenrRequirements;
use App\Support\MalabonGeo;
use App\Support\RequiredDocuments;
use App\Support\SheetRequirements;
use App\Support\Zoning\PinZone;
use App\Support\ZoningConformance;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Several businesses for ONE existing owner, each a list of dated steps.
 *
 * ── Why not Playbook ────────────────────────────────────────────────────────
 *
 * Playbook makes a new owner per scenario and lays each history out as "day 0,
 * day 1…" counted back from the run. Ken's request of 6 October 2026 is the
 * other way round: his own account, nine businesses, and the moment of every
 * step named ("BPLO approved Wed 16 Sep, paid Thu 17 Sep…"). So the history
 * here is data — `database/data/owner-portfolio.json` — and this class only
 * knows how each kind of step is clicked. The clicks are Playbook's: the same
 * routes, as the same accounts, through AppClient, with nothing written to
 * the database directly. The only reads are what a screen would have shown
 * the person (the id of a queue item, a visit, a requirement).
 *
 * ── When ────────────────────────────────────────────────────────────────────
 *
 * Each step's first request lands at its `at` exactly (`Clock::landNextAt`);
 * the requests inside a step follow two to four minutes apart, as clicks do.
 * `problems()` refuses a spec whose moments are not weekdays between 8:00 AM
 * and 5:00 PM, run backwards, or fall after today, and `play()` refuses a step
 * that runs past 5:00 PM or into the next step — so the register can only get
 * a history a person could have made in office hours.
 */
final class Portfolio
{
    /** The office that issues each permit, by permit code. */
    private array $issuer;

    /** @var array<string, int> */
    private array $typeIds;

    private User $owner;

    /** @var array<string, mixed> */
    private array $biz;

    private string $tin;

    /** The filing the next step acts on: the newest one this business has. */
    private ?int $current = null;

    /** @var array<string, int> the visit each office booked on the current filing */
    private array $visits = [];

    /** @var list<int> applications this business created, for the command's cleanup */
    public array $applicationIds = [];

    /**
     * @param  array<string, User>  $offices  by department code
     * @param  Closure(): mixed  $scanPermits  the scheduler's expiry scan
     */
    public function __construct(
        private readonly AppClient $api,
        private readonly Clock $clock,
        private readonly array $offices,
        private readonly Closure $scanPermits,
    ) {
        $this->typeIds = PermitType::pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $this->issuer = PermitType::with('department')->get()
            ->mapWithKeys(fn (PermitType $t) => [$t->code => (string) $t->department?->code])->all();
    }

    /**
     * What is wrong with one business's plan, before anything is written.
     *
     * @param  array<string, mixed>  $biz
     * @return list<string>
     */
    public static function problems(array $biz, CarbonImmutable $now): array
    {
        $out = [];
        $key = $biz['key'] ?? '?';
        $last = null;

        foreach (['name', 'psic', 'barangay', 'pin', 'street', 'dti', 'events'] as $field) {
            if (empty($biz[$field])) {
                $out[] = "{$key}: no {$field}";
            }
        }
        if ($out !== []) {
            return $out;
        }

        if (($why = self::pinProblem($biz['barangay'], $biz['psic'], $biz['pin'])) !== null) {
            $out[] = "{$key}: pin {$why}";
        }

        foreach ($biz['events'] as $i => $event) {
            if (($event['do'] ?? '') === 'expiry_scan') {
                continue;
            }
            $at = self::moment((string) ($event['at'] ?? ''));
            if (($why = self::officeHours($at)) !== null) {
                $out[] = "{$key} step ".($i + 1)." ({$event['do']}): {$why}";
            }
            if ($at->greaterThan($now)) {
                $out[] = "{$key} step ".($i + 1)." ({$event['do']}) is after today";
            }
            if ($last !== null && $at->lessThanOrEqualTo($last)) {
                $out[] = "{$key} step ".($i + 1)." ({$event['do']}) is not after the step before it";
            }
            $last = $at;

            if (isset($event['office']) && ! in_array($event['office'], $biz['ticks'], true)) {
                $out[] = "{$key} step ".($i + 1).": {$event['office']} is not one of the permits BPLO ticks";
            }
            if ($event['do'] === 'book') {
                $visit = self::moment((string) $event['visit']);
                if (($why = self::officeHours($visit)) !== null || $visit->lessThan($at)) {
                    $out[] = "{$key} step ".($i + 1).': the visit '.($why ?? 'is before the booking');
                }
            }
            if ($event['do'] === 'amend_address'
                && ($why = self::pinProblem($event['barangay'], $biz['psic'], $event['pin'])) !== null) {
                $out[] = "{$key}: the new pin {$why}";
            }
        }

        return $out;
    }

    /**
     * Why this pin would not turn the wizard's zoning box green, or null: the
     * same three questions Roster::pin asks of a point it picks.
     *
     * @param  array{0: float, 1: float}  $pin
     */
    public static function pinProblem(string $barangayName, string $psicCode, array $pin): ?string
    {
        $barangay = Barangay::with('zoningClassifications')->where('name', $barangayName)->first();
        $psic = PsicCode::where('code', $psicCode)->first();
        if ($barangay === null || $psic === null) {
            return "names an unknown barangay or trade ({$barangayName}, {$psicCode})";
        }
        [$lat, $lng] = $pin;

        return MalabonGeo::pinProblem($lat, $lng, $barangay->name)
            ?? PinZone::refusal($lat, $lng, $barangay, [$psic])
            ?? (ZoningConformance::forPin($barangay, $psic, $lat, $lng)['verdict'] === 'listed'
                ? null
                : "is in a zone that does not list {$psicCode}");
    }

    /** The zone codes under a pin, for the plan. */
    public static function zoneAt(string $barangayName, array $pin): string
    {
        $barangay = Barangay::where('name', $barangayName)->first();
        $zone = $barangay === null ? null : PinZone::at($pin[0], $pin[1], $barangay);

        return $zone === null ? '—' : implode('+', $zone['codes']);
    }

    public static function moment(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Asia/Manila');
    }

    private static function officeHours(CarbonImmutable $at): ?string
    {
        if ($at->isWeekend()) {
            return $at->format('D j M Y').' is a weekend';
        }
        $minutes = $at->hour * 60 + $at->minute;

        return $minutes < 8 * 60 || $minutes > 17 * 60 ? $at->format('g:i A').' is outside 8:00 AM to 5:00 PM' : null;
    }

    /**
     * Play one business's steps for the owner.
     *
     * @param  array<string, mixed>  $biz
     */
    public function play(User $owner, string $tin, array $biz): void
    {
        $this->owner = $owner;
        $this->tin = $tin;
        $this->biz = $biz;
        $this->current = null;
        $this->visits = [];
        $this->applicationIds = [];
        $ended = null;

        foreach ($biz['events'] as $event) {
            if ($event['do'] === 'expiry_scan') {
                // The scheduler's nightly job, run on the real clock the way
                // Playbook::expired runs it — see SeedDefenseAccounts.
                $this->clock->release();
                ($this->scanPermits)();

                continue;
            }

            $at = self::moment($event['at']);
            if ($ended !== null && $ended->greaterThanOrEqualTo($at->subMinutes(2))) {
                throw new RuntimeException("{$biz['key']}: the step before {$event['do']} at {$event['at']} ran into it");
            }
            $this->clock->landNextAt($at);

            match ($event['do']) {
                'file' => $this->submit($this->draft()),
                'draft' => $this->draft(),
                'submit' => $this->submit($this->current),
                'bplo_approve' => $this->bploApproves($event['remarks']),
                'bplo_return' => $this->bploReturns($event),
                'pay_at_counter' => $this->asOffice(PermitType::OUTCOME_CODE)
                    ->post('BPLO marks the bill paid at the counter', "applications/{$this->current}/counter-payment"),
                'apply' => array_map(fn (string $code) => $this->handIn($code), $biz['ticks']),
                'office_approve' => $this->officeApproves($event['office'], $event['remarks']),
                'book' => $this->book($event['office'], self::moment($event['visit'])),
                'record' => $this->record($event),
                'answer_denr' => $this->answerDenr(),
                'accept_denr' => $this->acceptDenr(),
                'renew' => $this->renew(),
                'amend_address' => $this->amendAddress($event),
                default => throw new RuntimeException("{$biz['key']}: no such step, {$event['do']}"),
            };

            $ended = $end = $this->clock->now();
            if (! $end->isSameDay($at) || $end->hour * 60 + $end->minute > 17 * 60) {
                throw new RuntimeException("{$biz['key']}: {$event['do']} at {$event['at']} ran past 5:00 PM");
            }
        }
    }

    /* ── The steps ───────────────────────────────────────────────────── */

    /** The wizard up to Submit: business, draft, tax profile, documents. */
    private function draft(): int
    {
        $b = $this->biz;
        $this->zoningBoxIsGreen($b['barangay'], $b['pin']);

        $business = $this->asOwner()->post('save the business', 'businesses', [
            'name' => $b['name'],
            'trade_name' => null,
            'registration_type' => 'sole_proprietorship',
            'registration_number' => $b['dti'],
            'tin' => $b['form_tin'] ?? $this->tin,
            'is_rented' => false,
            'capital_investment' => $b['capital'],
            'has_tax_incentives' => false,
            'owner' => [
                'surname' => $this->owner->last_name,
                'given_name' => $this->owner->first_name,
                'middle_name' => $this->owner->middle_name,
                'gender' => $this->owner->gender,
            ],
            'address' => [
                'house_bldg_no' => $b['house_no'],
                'street' => $b['street'],
                'barangay_id' => $this->barangayId($b['barangay']),
                'latitude' => $b['pin'][0],
                'longitude' => $b['pin'][1],
                'mobile_number' => $this->owner->mobile_number,
                'email' => $this->owner->email,
            ],
            'lines' => [['psic_code_id' => $this->psicId()]],
        ])['data']['id'];

        $id = $this->asOwner()->post('start the application', 'applications', [
            'business_id' => $business,
            'application_type' => 'new',
            'data_privacy_consent' => true,
            'permit_type_ids' => [$this->typeIds[PermitType::OUTCOME_CODE]],
        ])['data']['id'];
        $this->opened($id);

        $this->asOwner()->put('save the tax profile', "applications/{$id}", [
            'fee_profile' => $this->feeProfile(),
            'data_privacy_consent' => true,
        ]);
        $this->uploadRequired($id);

        return $id;
    }

    private function submit(int $id): void
    {
        $this->asOwner()->post('submit the application', "applications/{$id}/submit");
    }

    /**
     * BPLO takes the filing and approves it. On a new filing it ticks the
     * other permits the business needs (client, 5 October 2026); a renewal
     * or an amendment carries what it carries.
     */
    private function bploApproves(string $remarks): void
    {
        $assignment = $this->take('BPLO');
        $new = Application::findOrFail($this->current)->application_type->value === 'new';
        $this->api->post('BPLO approves the form', "assignments/{$assignment}/approve", [
            'remarks' => $remarks,
            ...($new && $this->biz['ticks'] !== []
                ? ['permit_type_ids' => array_map(fn (string $code) => $this->typeIds[$code], $this->biz['ticks'])]
                : []),
        ]);
    }

    private function bploReturns(array $event): void
    {
        $assignment = $this->take('BPLO');
        $this->api->post('BPLO returns the form', "assignments/{$assignment}/return", [
            'remarks' => $event['remarks'],
            'remarks_target' => $event['target'],
            'remarks_notes' => [$event['target'] => $event['note']],
        ]);
    }

    /** Apply for a clearance, upload its checklist, hand in its sheet. */
    private function handIn(string $code): void
    {
        $id = $this->current;
        $this->asOwner()->post("apply for {$code}", "applications/{$id}/clearances/{$code}/apply");
        $this->uploadSheetRequirements($code);
        $this->asOwner()->put("hand in the {$code} sheet", "applications/{$id}/office-forms/{$code}", [
            'form_data' => $this->sheetAnswers($code),
            'submit' => true,
        ]);
    }

    private function uploadSheetRequirements(string $code): void
    {
        $id = $this->current;
        foreach (SheetRequirements::for(Application::findOrFail($id), $code) ?? [] as $row) {
            if (($row['blocking'] ?? false) !== true || ($row['satisfied'] ?? false) === true || ($row['code'] ?? null) === null) {
                continue;
            }
            $this->asOwner()->upload("upload {$code} {$row['code']}", "applications/{$id}/office-forms/{$code}/requirements/{$row['code']}", [],
                Str::slug(Str::limit((string) $row['label'], 60, '')).'.pdf',
                DocumentPdf::make(Str::limit((string) $row['label'], 110).' — '.$this->biz['name'], $this->addressLines()),
            );
        }
    }

    private function officeApproves(string $code, string $remarks): void
    {
        $assignment = $this->take($this->issuer[$code]);
        $this->api->post("{$code} approves the papers", "assignments/{$assignment}/approve", ['remarks' => $remarks]);
    }

    /** The office books its visit, typing the inspector's name (55e3c602). */
    private function book(string $code, CarbonImmutable $when): void
    {
        $this->visits[$code] = $this->asOffice($code)->post("{$code} books its visit", "applications/{$this->current}/permits/{$code}/inspection", [
            'scheduled_at' => $when->format('Y-m-d H:i:s'),
            'inspector_name' => Playbook::INSPECTORS[$code] ?? null,
        ])['data']['id'];
    }

    /** A pass issues the permit; a failure suspends the Business Permit. */
    private function record(array $event): void
    {
        $code = $event['office'];
        $visit = $this->visits[$code] ?? Inspection::where('application_id', $this->current)
            ->whereHas('department', fn ($d) => $d->where('code', $this->issuer[$code]))
            ->latest('id')->value('id');
        $this->asOffice($code)->post("{$code} records the visit", "inspections/{$visit}/conduct", [
            'result' => $event['result'],
            'findings' => $event['findings'],
        ]);
    }

    /** The owner uploads each DENR paper the CEC raised (DenrRequirements). */
    private function answerDenr(): void
    {
        foreach ($this->openDenr() as $requirement) {
            $this->asOwner()->upload("answer {$requirement->title}", "requests/{$requirement->id}/answer", [],
                Str::slug(Str::limit($requirement->title, 60, '')).'.pdf',
                DocumentPdf::make("{$requirement->title} — {$this->biz['name']}", $this->addressLines()),
                'document',
            );
        }
    }

    private function acceptDenr(): void
    {
        foreach (OfficerRequest::where('application_id', $this->current)
            ->where('system_key', 'like', DenrRequirements::KEY_PREFIX.'%')
            ->where('status', '!=', OfficerRequestStatus::Fulfilled->value)
            ->orderBy('id')->get() as $requirement) {
            $this->asOffice('CEC')->post("CENRO accepts {$requirement->title}", "requests/{$requirement->id}/close", [
                'outcome' => 'fulfilled',
            ]);
        }
    }

    /** @return Collection<int, OfficerRequest> */
    private function openDenr()
    {
        return OfficerRequest::where('application_id', $this->current)
            ->where('system_key', 'like', DenrRequirements::KEY_PREFIX.'%')
            ->where('status', OfficerRequestStatus::Pending->value)
            ->orderBy('id')
            ->get();
    }

    /** The Business Permit renewed: renewal started, profile, documents, submitted. */
    private function renew(): void
    {
        $prior = $this->businessPermit();
        $id = $this->asOwner()->post('start the renewal', 'applications', [
            'business_id' => $prior->business_id,
            'application_type' => 'renewal',
            'prior_permit_ids' => [$prior->id],
            'data_privacy_consent' => true,
        ])['data']['id'];
        $this->opened($id);
        $this->asOwner()->put('save the tax profile', "applications/{$id}", [
            'fee_profile' => $this->feeProfile(renewal: true),
            'data_privacy_consent' => true,
        ]);
        $this->uploadRequired($id);
        $this->submit($id);
    }

    /**
     * A Change of Address: the new house number, street, barangay and pin,
     * with the paper's note. Any change in that box brings the Zoning
     * Clearance onto the filing (Ken, 6 October 2026), and the wizard's
     * Zoning step takes CPDD's sheet and its files on the draft.
     */
    private function amendAddress(array $event): void
    {
        $prior = $this->businessPermit();
        $this->zoningBoxIsGreen($event['barangay'], $event['pin']);

        $id = $this->asOwner()->post('start the amendment', 'applications', [
            'business_id' => $prior->business_id,
            'application_type' => 'amendment',
            'prior_permit_id' => $prior->id,
            'data_privacy_consent' => true,
        ])['data']['id'];
        $this->opened($id);
        $this->biz['moved_to'] = $event;

        $this->asOwner()->post('state the new address', "applications/{$id}/amendments", [
            'changes' => [
                ['field' => 'address_house_bldg_no', 'new_value' => $event['house_no']],
                ['field' => 'address_street', 'new_value' => $event['street']],
                ['field' => 'address_barangay_id', 'new_value' => (string) $this->barangayId($event['barangay'])],
                ['field' => 'address_pin', 'new_value' => $event['pin'][0].','.$event['pin'][1]],
                ['field' => 'address_details', 'new_value' => $event['details']],
            ],
        ]);

        $this->uploadSheetRequirements('ZONING');
        $this->asOwner()->put('fill in the ZONING sheet', "applications/{$id}/office-forms/ZONING", [
            'form_data' => $this->sheetAnswers('ZONING'),
            'submit' => false,
        ]);
        $this->uploadRequired($id);
        $this->submit($id);
    }

    /* ── What a screen would have shown ──────────────────────────────── */

    /**
     * Ask what the location step asks as the pin settles, and stop where the
     * owner would be stopped (see Playbook::zoningBoxIsGreen).
     */
    private function zoningBoxIsGreen(string $barangay, array $pin): void
    {
        $id = $this->barangayId($barangay);
        $zone = $this->asOwner()->get('drop the pin', 'zone-at-pin', [
            'latitude' => $pin[0], 'longitude' => $pin[1], 'barangay_id' => $id, 'psic_code_ids' => [$this->psicId()],
        ])['data'] ?? [];
        $insights = $this->asOwner()->get('read the zoning box', 'location-insights', [
            'latitude' => $pin[0], 'longitude' => $pin[1], 'barangay_id' => $id, 'psic_code_id' => $this->psicId(),
        ])['data'] ?? [];

        $verdict = $insights['zoning']['verdict'] ?? null;
        if (($zone['refusal'] ?? null) !== null || $verdict !== 'listed') {
            throw new StepRefused('read the zoning box', 422, (string) ($zone['refusal'] ?? "the zoning box reads {$verdict}, not green"));
        }
    }

    private function take(string $department): int
    {
        $assignment = ApplicationAssignment::where('application_id', $this->current)
            ->whereHas('department', fn ($d) => $d->where('code', $department))
            ->latest('id')
            ->value('id');
        if ($assignment === null) {
            throw new StepRefused("find {$department}'s queue item", 404, "application {$this->current} is not in {$department}'s queue");
        }
        $this->api->as($this->offices[$department])->post("{$department} takes the filing", "assignments/{$assignment}/claim");

        return (int) $assignment;
    }

    private function businessPermit(): Permit
    {
        return Permit::where('business_id', Application::findOrFail($this->current)->business_id)
            ->whereHas('permitType', fn ($t) => $t->where('code', PermitType::OUTCOME_CODE))
            ->whereIn('status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->latest('id')
            ->firstOrFail();
    }

    /* ── Plumbing ────────────────────────────────────────────────────── */

    private function opened(int $id): void
    {
        $this->current = $id;
        $this->applicationIds[] = $id;
        $this->visits = [];
    }

    private function uploadRequired(int $id): void
    {
        foreach (RequiredDocuments::missingFor(Application::findOrFail($id)) as $type) {
            [$title, $lines] = $this->documentText($type);
            $this->asOwner()->upload("upload {$type->name}", "applications/{$id}/documents", [
                'document_type_id' => $type->id,
            ], Str::slug(Str::limit($title, 80, '')).'.pdf', DocumentPdf::make($title, $lines));
        }
    }

    private function asOwner(): AppClient
    {
        return $this->api->as($this->owner);
    }

    private function asOffice(string $permitCode): AppClient
    {
        return $this->api->as($this->offices[$this->issuer[$permitCode]]);
    }

    private function psicId(): int
    {
        return (int) PsicCode::where('code', $this->biz['psic'])->value('id');
    }

    private function barangayId(string $name): int
    {
        return (int) Barangay::where('name', $name)->value('id');
    }

    /** The Business & Tax Profile step's answers. */
    private function feeProfile(bool $renewal = false): array
    {
        $b = $this->biz;
        $male = intdiv($b['employees'] + 1, 2);
        $gross = $b['gross_sales'] ?? $b['capital'] * 4;
        $line = ['psic_code_id' => $this->psicId()] + ($renewal ? ['gross_sales' => $gross] : ['capitalization' => $b['capital']]);

        return [
            'lines' => [$line],
            ...($renewal ? ['gross_sales' => $gross] : ['capitalization' => $b['capital']]),
            'floor_area_sqm' => $b['floor_area'],
            'employees' => $b['employees'],
            'employees_in_lgu' => $b['employees'],
            'male_employees' => $male,
            'female_employees' => $b['employees'] - $male,
            'business_structure' => 'sole_proprietorship',
        ];
    }

    /** A few of each sheet's answers, as an owner would type them. */
    private function sheetAnswers(string $code): array
    {
        $b = $this->biz;

        return match ($code) {
            'SANITARY' => [
                'sanitary_classification' => 'Food Establishment',
                'water_source' => 'Maynilad',
                'toilets_count' => 1,
                'operating_hours' => '7:00 AM – 8:00 PM',
            ],
            'ZONING' => [
                'zoning_project_description' => $b['name'],
                'project_location' => $this->addressLines()[0],
            ],
            'OCCUPANCY', 'FSIC' => [
                'building_storeys' => 1,
                'total_floor_area_sqm' => $b['floor_area'],
            ],
            default => [],
        };
    }

    /** The address the business is at, or moving to while an amendment is open. @return list<string> */
    private function addressLines(): array
    {
        $at = $this->biz['moved_to'] ?? $this->biz;

        return ["{$at['house_no']} {$at['street']}, {$at['barangay']}, Malabon City", $this->biz['name']];
    }

    private function ownerName(): string
    {
        return trim(implode(' ', array_filter([$this->owner->first_name, $this->owner->middle_name, $this->owner->last_name])));
    }

    /** A requirement file's title and body lines. @return array{0: string, 1: list<string>} */
    private function documentText(DocumentType $type): array
    {
        $b = $this->biz;
        $owner = $this->ownerName();

        return match ($type->code) {
            'DTI_SEC_CDA' => ["DTI Certificate of Business Name Registration — {$b['name']}", [
                "Business name: {$b['name']}",
                "Certificate no.: {$b['dti']}",
                "Owner: {$owner}",
                "TIN: {$this->tin}",
                "{$b['house_no']} {$b['street']}, {$b['barangay']}, Malabon City",
            ]],
            'LOCATION_SKETCH' => ["Sketch and photos of location — {$b['name']}", $this->addressLines()],
            'LAND_TITLE' => ["Tax Declaration — {$this->addressLines()[0]}", ["Declared owner: {$owner}"]],
            'PRIOR_PERMIT' => ["Mayor's Permit ".$this->businessPermit()->permit_number." — {$b['name']}", $this->addressLines()],
            'AMEND_AFFIDAVIT' => ["Affidavit requesting the amendment — {$b['name']}", [
                "Affiant: {$owner}",
                'Requested change: business address to '.$this->addressLines()[0],
            ]],
            default => ["{$type->name} — {$b['name']}", $this->addressLines()],
        };
    }

    /* ── The summary ─────────────────────────────────────────────────── */

    /**
     * One row per business as the register now holds it: the newest
     * filing's status and tracking ID, every permit with its number and
     * status, and the first and last moments of its history.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null
     */
    public static function summaryRow(User $owner, array $biz): ?array
    {
        $business = Business::where('owner_user_id', $owner->id)->where('name', $biz['name'])->first();
        if ($business === null) {
            return null;
        }
        $app = Application::where('business_id', $business->id)->latest('id')->first();
        $permits = Permit::with('permitType')->where('business_id', $business->id)->orderBy('id')->get()
            ->map(fn (Permit $p) => "{$p->permitType?->code} {$p->permit_number} ".$p->status->value)
            ->implode('; ');
        $first = Application::where('business_id', $business->id)->min('submitted_at') ?? $app?->created_at;

        return [
            $biz['key'].' '.$biz['name'],
            ($app?->application_type->value ?? '—').' '.($app?->status->value ?? '—'),
            (string) ($app?->tracking_id ?? '—'),
            $permits ?: '—',
            'filed '.CarbonImmutable::parse($first)->timezone('Asia/Manila')->format('D j M Y g:i A'),
        ];
    }
}

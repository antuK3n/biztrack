<?php

namespace App\Support\DefenseAccounts;

use App\Enums\OfficerRequestStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\OfficerRequest;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\User;
use App\Support\DenrRequirements;
use App\Support\RequiredDocuments;
use App\Support\SheetRequirements;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Each scenario as the sequence of clicks that produces it.
 *
 * ── The rule this class keeps ───────────────────────────────────────────────
 *
 * Every state change below is a request through AppClient, made by the
 * account a person would make it as: the owner files, BPLO approves and ticks
 * the other permits (client, 5 October 2026), BPLO marks the bill paid at the
 * counter (never KwikPay), each office takes its own clearance, approves it,
 * books its visit and records the result, the super admin blacklists. The
 * only direct reads of the database are to find what a screen would have
 * shown the person — the id of BPLO's queue item, the requirement list the
 * Documents step draws, the permit row the Revoke button sits on. Nothing is
 * written except through the API.
 *
 * If the app refuses a step, StepRefused propagates and the command rolls the
 * whole owner back and reports the refusal. A scenario the rules will not
 * produce is reported, never forced.
 *
 * ── When things happen ──────────────────────────────────────────────────────
 *
 * Day 0 is when the owner files; the other actors act on the weekdays after,
 * as `LAST_DAY` lays out per scenario. The start is counted back from today
 * so the last act lands one or two weekdays before the run — a filing
 * waiting on BPLO has waited a day or two, not three weeks past its RA 11032
 * deadline. The two expired scenarios play their first filing in 2025 so the
 * Business Permit it issues really is a 2025 permit.
 */
final class Playbook
{
    /** The weekday, counted from day 0, of each scenario's last act. */
    public const LAST_DAY = [
        'draft' => 0, 'for-approval' => 0, 'returned' => 1, 'awaiting-payment' => 1,
        'offices-reviewing' => 3, 'approved' => 4, 'rejected' => 1, 'expired' => 4,
        'renewal' => 4, 'amendment-review' => 7, 'amendment-approved' => 7, 'suspended' => 4,
        'revoked' => 6, 'blacklisted' => 6, 'open-requirement' => 3, 'messages' => 1,
    ];

    private CarbonImmutable $start;

    private ?User $owner = null;

    /** @var array<string, mixed> */
    private array $plan = [];

    /** @var list<int> applications this owner created, for the command's cleanup */
    public array $applicationIds = [];

    /** @var array<string, int> */
    private array $typeIds;

    /** @var array<string, string> permit code => issuing department code */
    private array $issuer;

    /**
     * @param  array<string, User>  $offices  by department code
     * @param  Closure(): mixed  $scanPermits  the scheduler's expiry scan
     */
    public function __construct(
        private readonly AppClient $api,
        private readonly Clock $clock,
        private readonly array $offices,
        private readonly User $admin,
        private readonly string $password,
        private readonly Closure $scanPermits,
    ) {
        $this->typeIds = PermitType::pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $this->issuer = PermitType::with('department')->get()
            ->mapWithKeys(fn (PermitType $t) => [$t->code => (string) $t->department?->code])->all();
    }

    /**
     * Build one owner's scenario. Answers the owner.
     *
     * @param  array<string, mixed>  $plan  one row of Roster::owners()
     */
    public function play(array $plan): User
    {
        $this->plan = $plan;
        $this->applicationIds = [];
        $slug = $plan['scenario'];
        $today = Clock::realToday();
        $jitter = ($plan['n'] - 1) % 2;

        if (in_array($slug, ['expired', 'renewal'], true)) {
            // Spring 2025, a week apart per owner, the renewal owners a
            // fortnight behind the expired ones so the two sets interleave.
            $this->start = Clock::weekdaysAfter(
                CarbonImmutable::create(2025, 3, 2, 0, 0, 0, 'Asia/Manila')->addDays(7 * ($plan['n'] - 1) + ($slug === 'renewal' ? 14 : 0)),
                1,
            );
        } else {
            $this->start = Clock::weekdaysBefore($today, self::LAST_DAY[$slug] + 1 + $jitter);
        }

        $this->day(0, '08:30');
        $this->owner = $this->register();

        match ($slug) {
            'draft' => $this->draft(),
            'for-approval' => $this->filed(),
            'returned' => $this->returned(),
            'awaiting-payment' => $this->awaitingPayment(),
            'offices-reviewing' => $this->officesReviewing(),
            'approved' => $this->approvedHistory(),
            'rejected' => $this->rejected(),
            'expired' => $this->expired(),
            'renewal' => $this->lateRenewal($today, $jitter),
            'amendment-review' => $this->amendment(false),
            'amendment-approved' => $this->amendment(true),
            'suspended' => $this->approvedHistory(refuse: $this->ticks()[count($this->ticks()) - 1]),
            'revoked' => $this->revoked(),
            'blacklisted' => $this->blacklisted(),
            'open-requirement' => $this->openRequirement(),
            'messages' => $this->messages(),
        };

        return $this->owner;
    }

    /* ── The scenarios ───────────────────────────────────────────────── */

    /** Wizard started and saved: business, draft, tax profile, one file. */
    private function draft(): void
    {
        $id = $this->startNewFiling();
        $this->uploadRequired($id, limit: 1);
    }

    /** Submitted; with BPLO. */
    private function filed(): int
    {
        $id = $this->startNewFiling();
        $this->uploadRequired($id);
        $this->asOwner()->post('submit the application', "applications/{$id}/submit");

        return $id;
    }

    private function returned(): void
    {
        $id = $this->filed();
        $this->day(1, '09:15');
        $assignment = $this->take('BPLO', $id);
        $this->api->post('BPLO returns the form', "assignments/{$assignment}/return", [
            'remarks' => 'Please add the shop’s telephone or a second contact number, then resubmit.',
            'remarks_target' => 'form:telephone',
            'remarks_notes' => ['form:telephone' => 'No telephone or alternate contact number is given for the shop.'],
        ]);
    }

    private function awaitingPayment(): int
    {
        $id = $this->filed();
        $this->day(1, '09:15');
        $this->bploApproves($id);

        return $id;
    }

    /**
     * Paid; every ticked clearance handed in. The first office has approved
     * the papers and booked its visit for the next working day after the
     * run, the second has approved and not yet booked, the rest have not
     * reached it.
     */
    private function officesReviewing(): void
    {
        $id = $this->paidAndHandedIn();
        $ticks = $this->ticks();

        $this->day(3, '09:00');
        $first = array_shift($ticks);
        $this->officeApproves($id, $first);
        $this->book($id, $first, Clock::weekdaysAfter(Clock::realToday(), 1)->setTime(10, 0));
        if ($ticks !== []) {
            $this->officeApproves($id, array_shift($ticks));
        }
    }

    /**
     * Filed, approved with the other permits ticked, paid at the counter,
     * every clearance handed in, approved, visited and passed — so every
     * permit is issued and the filing closes itself. With `$refuse`, that
     * office's visit fails and it refuses the clearance instead, which
     * suspends the Business Permit.
     */
    private function approvedHistory(?string $refuse = null): int
    {
        $id = $this->paidAndHandedIn();

        $this->day(3, '09:00');
        $visits = [];
        foreach ($this->ticks() as $i => $code) {
            $this->officeApproves($id, $code);
            $visits[$code] = $this->book($id, $code, Clock::weekdaysAfter($this->start, 4)->setTime(10, 0)->addMinutes(45 * $i));
        }

        $this->day(4, '10:00');
        foreach ($visits as $code => $visit) {
            $this->asOffice($code);
            if ($code === $refuse) {
                $this->api->post("{$code} records a failed visit", "inspections/{$visit}/conduct", [
                    'result' => 'failed',
                    'findings' => $this->failedFindings($code),
                ]);
                $this->api->post("{$code} refuses the clearance", 'assignments/'.$this->assignment($this->issuer[$code], $id).'/reject', [
                    'reason' => $this->failedFindings($code),
                    'remedy' => 'Correct the findings above, then apply for the clearance again so the office can re-inspect.',
                ]);

                continue;
            }
            $this->api->post("{$code} records a passed visit", "inspections/{$visit}/conduct", [
                'result' => 'passed',
                'findings' => 'Premises inspected. Compliant.',
            ]);
        }

        $this->day(4, '14:00');
        $this->answerDenrRequirements($id);

        return $id;
    }

    /**
     * Granting a City Environmental Certificate raises the DENR papers the
     * business still owes as requirements of CENRO's (`DenrRequirements`).
     * Left open, an "every permit issued" account would also read as one
     * with requirements outstanding — the open-requirement scenario's state,
     * not this one's — so the owner uploads each and CENRO accepts it.
     */
    private function answerDenrRequirements(int $id): void
    {
        $open = OfficerRequest::where('application_id', $id)
            ->where('system_key', 'like', DenrRequirements::KEY_PREFIX.'%')
            ->where('status', OfficerRequestStatus::Pending->value)
            ->orderBy('id')
            ->get();

        foreach ($open as $requirement) {
            $this->asOwner()->upload("answer {$requirement->title}", "requests/{$requirement->id}/answer", [],
                Str::slug(Str::limit($requirement->title, 60, '')).'.pdf',
                DocumentPdf::make("{$requirement->title} — {$this->plan['business']['name']}", $this->addressLines()),
                'document',
            );
            $this->asOffice('CEC')->post("CENRO accepts {$requirement->title}", "requests/{$requirement->id}/close", [
                'outcome' => 'fulfilled',
            ]);
        }
    }

    private function rejected(): void
    {
        $id = $this->filed();
        $this->day(1, '09:15');
        $this->take('BPLO', $id);
        $this->api->post('BPLO rejects the application', "applications/{$id}/reject", [
            'reason' => 'The business name on the DTI certificate does not match the name on the application. '
                .'File a new application under the registered business name.',
        ]);
    }

    /** A 2025 Business Permit, lapsed on 31 December, flipped by the nightly scan. */
    private function expired(): int
    {
        $id = $this->approvedHistory();
        $this->clock->release();
        ($this->scanPermits)();

        return $id;
    }

    /** As expired, then a renewal filed late, in the last days before the run. */
    private function lateRenewal(CarbonImmutable $today, int $jitter): void
    {
        $first = $this->expired();
        $business = Application::findOrFail($first)->business_id;
        $prior = $this->businessPermit($first);

        $this->start = Clock::weekdaysBefore($today, 1 + $jitter);
        $this->day(0, '09:00');
        $owner = $this->asOwner();
        $id = $owner->post('start the renewal', 'applications', [
            'business_id' => $business,
            'application_type' => 'renewal',
            'prior_permit_ids' => [$prior->id],
            'data_privacy_consent' => true,
        ])['data']['id'];
        $this->applicationIds[] = $id;
        $owner->put('save the tax profile', "applications/{$id}", [
            'fee_profile' => $this->feeProfile(renewal: true),
            'data_privacy_consent' => true,
        ]);
        $this->uploadRequired($id);
        $this->asOwner()->post('submit the renewal', "applications/{$id}/submit");
    }

    /** Approved, then a change of trade name filed; approved too when `$approve`. */
    private function amendment(bool $approve): void
    {
        $first = $this->approvedHistory();
        $business = Application::findOrFail($first)->business_id;
        $prior = $this->businessPermit($first);

        $this->day(6, '10:30');
        $owner = $this->asOwner();
        $id = $owner->post('start the amendment', 'applications', [
            'business_id' => $business,
            'application_type' => 'amendment',
            'prior_permit_id' => $prior->id,
            'data_privacy_consent' => true,
        ])['data']['id'];
        $this->applicationIds[] = $id;
        $owner->post('state the new trade name', "applications/{$id}/amendments", [
            'changes' => [
                ['field' => 'trade_name', 'new_value' => $this->plan['last_name'].' General Merchandise'],
                ['field' => 'trade_name_details', 'new_value' => 'The shop now sells general merchandise and is registered under the new trade name.'],
            ],
        ]);
        $this->uploadRequired($id);
        $this->asOwner()->post('submit the amendment', "applications/{$id}/submit");

        $this->day(7, '09:20');
        $assignment = $this->take('BPLO', $id);
        if ($approve) {
            $this->api->post('BPLO approves the amendment', "assignments/{$assignment}/approve", [
                'remarks' => 'Change of trade name verified against the DTI certificate.',
            ]);
        }
    }

    private function revoked(): void
    {
        $id = $this->approvedHistory();
        $this->day(6, '14:00');
        $this->asOffice(PermitType::OUTCOME_CODE)->post('BPLO revokes the Mayor’s Permit', 'permits/'.$this->businessPermit($id)->id.'/revoke', [
            'reason' => 'Operating a second line of business not declared on the permit, after a written warning.',
        ]);
    }

    private function blacklisted(): void
    {
        $id = $this->approvedHistory();
        $this->day(6, '15:00');
        $this->api->as($this->admin)->post('the super admin blacklists the owner', 'admin/businesses/'.Application::findOrFail($id)->business_id.'/status', [
            'status' => 'blacklisted',
            'reason' => 'Fraudulent documents: the lease contract submitted was found to be falsified.',
        ]);
    }

    /** Paid and handed in; the health office (fire, for a shop it does not visit) asks for a document. */
    private function openRequirement(): void
    {
        $id = $this->paidAndHandedIn();
        $code = in_array('SANITARY', $this->ticks(), true) ? 'SANITARY' : 'FSIC';

        $this->day(3, '10:00');
        $this->take($this->issuer[$code], $id);
        $this->api->post("{$code} raises a requirement", "applications/{$id}/requests", $code === 'SANITARY'
            ? [
                'request_type' => 'document',
                'title' => 'Health certificates of all food handlers',
                'description' => 'Upload the health certificate of every employee who handles food, issued by the City Health Office this year.',
                'due_date' => Clock::weekdaysAfter(Clock::realToday(), 5)->toDateString(),
            ]
            : [
                'request_type' => 'document',
                'title' => 'Updated floor plan',
                'description' => 'Upload a floor plan showing the exits and where the fire extinguishers are mounted.',
                'due_date' => Clock::weekdaysAfter(Clock::realToday(), 5)->toDateString(),
            ]);
    }

    /** Filed; a BPLO officer takes it and the two exchange messages. */
    private function messages(): void
    {
        $id = $this->filed();
        $bplo = Department::where('code', 'BPLO')->value('id');

        $this->day(1, '10:00');
        $this->take('BPLO', $id);
        $this->asOwner()->post('the owner writes to BPLO', "applications/{$id}/messages", [
            'body' => 'Good morning po. May I ask how long the review usually takes? I would like to open the shop next week.',
            'department_id' => $bplo,
        ]);
        $this->asOffice(PermitType::OUTCOME_CODE)->post('BPLO replies', "applications/{$id}/messages", [
            'body' => 'Good morning. Your application is being reviewed now. If the form is complete you will receive the Tax Order of Payment within three working days.',
            'department_id' => $bplo,
        ]);
        $this->asOwner()->post('the owner replies', "applications/{$id}/messages", [
            'body' => 'Thank you po. I will wait for the Tax Order of Payment.',
            'department_id' => $bplo,
        ]);
    }

    /* ── The steps ───────────────────────────────────────────────────── */

    /** Sign up, then follow the link in the confirmation e-mail. */
    private function register(): User
    {
        $plan = $this->plan;
        $body = $this->api->as(null)->post('sign up', 'auth/register', [
            'first_name' => $plan['first_name'],
            'middle_name' => $plan['middle_name'],
            'last_name' => $plan['last_name'],
            'gender' => $plan['gender'],
            'email' => $plan['email'],
            'mobile_number' => $plan['mobile'],
            'password' => $this->password,
            'password_confirmation' => $this->password,
            'data_privacy_consent' => true,
            'home_street' => $plan['home_street'],
            'home_barangay' => $plan['home_barangay'],
            'home_postal_code' => '1470',
        ]);
        $this->api->adopt((string) ($body['data']['token'] ?? ''));

        $user = User::where('email', $plan['email'])->firstOrFail();
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $response = $this->api->visit($link, '10.99.'.intdiv($plan['index'], 250).'.'.($plan['index'] % 250 + 1));
        $landed = (string) $response->headers->get('Location');
        if (! str_contains($landed, 'status=verified')) {
            throw new StepRefused('confirm the e-mail address', $response->getStatusCode(), $landed);
        }

        return $user->fresh();
    }

    /** The wizard up to the Documents step: business, draft, tax profile. */
    private function startNewFiling(): int
    {
        $plan = $this->plan;
        $b = $plan['business'];
        $owner = $this->asOwner();

        $business = $owner->post('save the business', 'businesses', [
            'name' => $b['name'],
            'trade_name' => $b['name'],
            'registration_type' => $b['registration_type'],
            'registration_number' => $b['registration_number'],
            'tin' => $b['tin'],
            'is_rented' => false,
            'capital_investment' => $b['capital'],
            'has_tax_incentives' => false,
            'owner' => [
                'surname' => $plan['last_name'],
                'given_name' => $plan['first_name'],
                'middle_name' => $plan['middle_name'],
                'gender' => $plan['gender'],
            ],
            'address' => [
                'house_bldg_no' => $b['house_no'],
                'street' => $b['street'],
                'barangay_id' => $b['barangay_id'],
                'latitude' => $b['pin'][0] ?? null,
                'longitude' => $b['pin'][1] ?? null,
                'mobile_number' => $plan['mobile'],
                'email' => $plan['email'],
            ],
            'lines' => [['psic_code_id' => $this->psicId()]],
        ])['data']['id'];

        $id = $owner->post('start the application', 'applications', [
            'business_id' => $business,
            'application_type' => 'new',
            'data_privacy_consent' => true,
            'permit_type_ids' => [$this->typeIds[PermitType::OUTCOME_CODE]],
        ])['data']['id'];
        $this->applicationIds[] = $id;

        $owner->put('save the tax profile', "applications/{$id}", [
            'fee_profile' => $this->feeProfile(),
            'data_privacy_consent' => true,
        ]);

        return $id;
    }

    /** Upload what the Documents step asks for, as one generated PDF each. */
    private function uploadRequired(int $id, ?int $limit = null): void
    {
        $missing = RequiredDocuments::missingFor(Application::findOrFail($id));
        foreach ($limit === null ? $missing : $missing->take($limit) as $type) {
            [$title, $lines] = $this->documentText($type, $id);
            $this->asOwner()->upload("upload {$type->name}", "applications/{$id}/documents", [
                'document_type_id' => $type->id,
            ], Str::slug(Str::limit($title, 80, '')).'.pdf', DocumentPdf::make($title, $lines));
        }
    }

    /** BPLO takes the filing and approves it, ticking the other permits. */
    private function bploApproves(int $id): void
    {
        $assignment = $this->take('BPLO', $id);
        $this->api->post('BPLO approves and ticks the other permits', "assignments/{$assignment}/approve", [
            'remarks' => 'Form and documents complete.',
            'permit_type_ids' => array_map(fn (string $code) => $this->typeIds[$code], $this->ticks()),
        ]);
    }

    /** Filed, approved, paid at the counter, every ticked clearance handed in (days 0–2). */
    private function paidAndHandedIn(): int
    {
        $id = $this->filed();

        $this->day(1, '09:15');
        $this->bploApproves($id);

        $this->day(2, '09:30');
        $this->asOffice(PermitType::OUTCOME_CODE)->post('BPLO marks the bill paid at the counter', "applications/{$id}/counter-payment");
        foreach ($this->ticks() as $code) {
            $this->handIn($id, $code);
        }

        return $id;
    }

    /** Apply for a clearance, upload its checklist, hand in its sheet. */
    private function handIn(int $id, string $code): void
    {
        $this->asOwner()->post("apply for {$code}", "applications/{$id}/clearances/{$code}/apply");

        foreach (SheetRequirements::for(Application::findOrFail($id), $code) ?? [] as $row) {
            if (($row['blocking'] ?? false) !== true || ($row['satisfied'] ?? false) === true || ($row['code'] ?? null) === null) {
                continue;
            }
            $title = Str::limit((string) $row['label'], 110).' — '.$this->plan['business']['name'];
            $this->asOwner()->upload("upload {$code} {$row['code']}", "applications/{$id}/office-forms/{$code}/requirements/{$row['code']}", [],
                Str::slug(Str::limit((string) $row['label'], 60, '')).'.pdf',
                DocumentPdf::make($title, $this->addressLines()),
            );
        }

        $this->asOwner()->put("hand in the {$code} sheet", "applications/{$id}/office-forms/{$code}", [
            'form_data' => $this->sheetAnswers($code),
            'submit' => true,
        ]);
    }

    private function officeApproves(int $id, string $code): void
    {
        $assignment = $this->take($this->issuer[$code], $id);
        $this->api->post("{$code} approves the papers", "assignments/{$assignment}/approve", [
            'remarks' => 'Requirements complete. For inspection.',
        ]);
    }

    /** The office books its visit; answers the inspection id. */
    private function book(int $id, string $code, CarbonImmutable $when): int
    {
        return $this->asOffice($code)->post("{$code} books its visit", "applications/{$id}/permits/{$code}/inspection", [
            'scheduled_at' => $when->format('Y-m-d H:i:s'),
        ])['data']['id'];
    }

    /** The office's officer takes the queue item. Answers its id. */
    private function take(string $department, int $id): int
    {
        $assignment = $this->assignment($department, $id);
        $this->api->as($this->offices[$department])->post("{$department} takes the filing", "assignments/{$assignment}/claim");

        return $assignment;
    }

    /* ── What a screen would have shown ──────────────────────────────── */

    private function assignment(string $department, int $id): int
    {
        $assignment = ApplicationAssignment::where('application_id', $id)
            ->whereHas('department', fn ($d) => $d->where('code', $department))
            ->value('id');
        if ($assignment === null) {
            throw new StepRefused("find {$department}'s queue item", 404, "application {$id} is not in {$department}'s queue");
        }

        return (int) $assignment;
    }

    private function businessPermit(int $id): Permit
    {
        return Permit::where('application_id', $id)
            ->whereHas('permitType', fn ($t) => $t->where('code', PermitType::OUTCOME_CODE))
            ->whereIn('status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->latest('id')
            ->firstOrFail();
    }

    /* ── Plumbing ────────────────────────────────────────────────────── */

    private function day(int $k, string $time): void
    {
        $this->clock->on(Clock::weekdaysAfter($this->start, $k), $time);
    }

    private function asOwner(): AppClient
    {
        return $this->api->as($this->owner);
    }

    private function asOffice(string $permitCode): AppClient
    {
        return $this->api->as($this->offices[$this->issuer[$permitCode]]);
    }

    /** @return list<string> */
    private function ticks(): array
    {
        return $this->plan['business']['ticks'];
    }

    private function psicId(): int
    {
        return (int) PsicCode::where('code', $this->plan['business']['psic'])->value('id');
    }

    /** The Business & Tax Profile step's answers. */
    private function feeProfile(bool $renewal = false): array
    {
        $b = $this->plan['business'];
        $male = intdiv($b['employees'] + 1, 2);
        $line = ['psic_code_id' => $this->psicId()];
        $line += $renewal ? ['gross_sales' => $b['capital'] * 4] : ['capitalization' => $b['capital']];

        return [
            'lines' => [$line],
            ...($renewal ? ['gross_sales' => $b['capital'] * 4] : ['capitalization' => $b['capital']]),
            'floor_area_sqm' => $b['floor_area'],
            'employees' => $b['employees'],
            'employees_in_lgu' => $b['employees'],
            'male_employees' => $male,
            'female_employees' => $b['employees'] - $male,
            'business_structure' => $b['registration_type'],
        ];
    }

    /** A few of each sheet's answers, as an owner would type them. */
    private function sheetAnswers(string $code): array
    {
        $b = $this->plan['business'];

        return match ($code) {
            'SANITARY' => [
                'sanitary_classification' => in_array($b['psic'], ['96110', '96120', '47721'], true) ? 'Non-Food Establishment' : 'Food Establishment',
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

    private function failedFindings(string $code): string
    {
        return match ($code) {
            'SANITARY' => 'No potable water connection; food is prepared beside an open drainage canal.',
            'FSIC' => 'No fire extinguisher on the premises and the only exit is blocked by stock.',
            'CEC' => 'Waste water is discharged straight into the street drain with no grease trap.',
            'OCCUPANCY' => 'A second storey was added without a building permit.',
            default => 'The business operates outside the zone its clearance was applied for.',
        };
    }

    /** @return list<string> */
    private function addressLines(): array
    {
        $b = $this->plan['business'];

        return [
            "{$b['house_no']} {$b['street']}, {$b['barangay']}, Malabon City",
            $b['name'],
        ];
    }

    /** A requirement file's title and body lines. @return array{0: string, 1: list<string>} */
    private function documentText(DocumentType $type, int $id): array
    {
        $b = $this->plan['business'];
        $owner = "{$this->plan['first_name']} {$this->plan['middle_name']} {$this->plan['last_name']}";

        return match ($type->code) {
            'DTI_SEC_CDA' => $b['registration_type'] === 'sole_proprietorship'
                ? ["DTI Certificate of Business Name Registration — {$b['name']}", [
                    "Business name: {$b['name']}",
                    "Certificate no.: {$b['registration_number']}",
                    "Owner: {$owner}",
                    $this->addressLines()[0],
                ]]
                : ["SEC Certificate of Incorporation — {$b['name']}", [
                    "Corporate name: {$b['name']}",
                    "SEC registration no.: {$b['registration_number']}",
                    "President: {$owner}",
                ]],
            'LOCATION_SKETCH' => ["Sketch and photos of location — {$b['name']}", $this->addressLines()],
            'LAND_TITLE' => ["Tax Declaration — {$this->addressLines()[0]}", ["Declared owner: {$owner}"]],
            'PRIOR_PERMIT' => ["Mayor's Permit ".$this->businessPermitNumber($id)." — {$b['name']}", $this->addressLines()],
            'AMEND_AFFIDAVIT' => ["Affidavit requesting the amendment — {$b['name']}", [
                "Affiant: {$owner}",
                "Requested change: trade name to {$this->plan['last_name']} General Merchandise",
            ]],
            default => ["{$type->name} — {$b['name']}", $this->addressLines()],
        };
    }

    private function businessPermitNumber(int $id): string
    {
        $prior = Application::find($id)?->prior_permit_id;

        return (string) Permit::whereKey($prior)->value('permit_number');
    }
}

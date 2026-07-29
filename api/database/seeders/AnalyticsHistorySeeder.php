<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\InspectionResult;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\BusinessAddress;
use App\Models\BusinessLine;
use App\Models\BusinessOwner;
use App\Models\Department;
use App\Models\FeeAssessment;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\PsicCode;
use App\Models\Role;
use App\Models\User;
use App\Services\Sms\SmsChannel;
use App\Services\WorkflowService;
use App\Support\Numbering;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Thirty-six months of believable permit history, written as real rows.
 *
 * WHY THIS EXISTS
 * ---------------
 * Permit Processing Time Monitoring (Feature 7) fits control limits on the
 * first 24 weeks of its window and ignores any week with fewer than
 * `Spc::MIN_COMPLETIONS_PER_WEEK` completed reviews, and Business Growth
 * Analysis returns a null growth rate when the prior period is empty. A
 * register holding a handful of reviews inside two calendar weeks therefore
 * renders an empty state — correctly. The fix is history, not a special case:
 * nothing in App\Support\* or the frontend knows this seeder exists.
 *
 * HOW IT IS BUILT
 * ---------------
 * `r/R/generate.R` is the spec for shape and volume — seasonality (January
 * x2.5, February x1.6, December x0.7), lognormal review durations whose
 * arithmetic mean hits the per-office target, a 10% returned/resubmitted loop,
 * and one deliberately injected slowdown for SPC to catch. It is a spec, not a
 * dependency: no R runs here.
 *
 * Every state transition goes through WorkflowService, driven under
 * `Carbon::setTestNow()` so the workflow's own `now()` writes historical
 * timestamps. That is the point: `submitted_at`, `deadline_at`, assignment
 * `assigned_at`/`completed_at`, inspection dates, permit validity, `decided_at`
 * and the whole `application_status_history` chain are produced by the same
 * code path a live filing takes, so they cannot drift out of agreement with
 * each other or with what the app would ever produce.
 *
 * THE INJECTED SLOWDOWN
 * ---------------------
 * CHO (City Health Office) reviews assigned inside the most recent
 * self::ANOMALY_WEEKS ISO weeks run progressively slower, ramping from x1.35 to
 * x1.95 of the office's baseline. generate.R steps CHO by a flat x1.8; the ramp
 * is deliberate here so the EWMA drift rule fires a couple of weeks *before*
 * any single week breaches the Shewhart limit — which is the reading the
 * screen's gradual-slowdown detector exists to show.
 *
 * SEPARABILITY
 * ------------
 * Real testers share this database, so nothing this seeder writes may be
 * mistaken for their work. Every row is reachable from one of two tags:
 *
 *   - accounts whose email ends in `@`.self::EMAIL_DOMAIN
 *   - businesses whose `registration_number` starts with self::REGISTRATION_PREFIX
 *
 * Everything else (applications, assignments, permits, payments, history,
 * inspections, notifications, audit rows) hangs off those two by foreign key.
 * Running the seeder twice is a no-op. To remove every seeded row:
 *
 *     php artisan db:seed --class=AnalyticsHistoryPurgeSeeder
 *
 * NOT REGISTERED IN DatabaseSeeder — on purpose. `Tests\TestCase::$seed` runs
 * DatabaseSeeder before every feature test, so registering it would add ~1,000
 * applications to every one of them, and would break the analytics tests that
 * assert a one-month window has an empty prior period and that the review
 * history starts clean. A fresh `migrate --seed` on staging should also not
 * invent three years of filings. Demo history is an explicit act.
 */
class AnalyticsHistorySeeder extends Seeder
{
    /* ── tags ─────────────────────────────────────────────────────────────── */

    /** Accounts this seeder owns. Nothing real signs in on this domain. */
    public const EMAIL_DOMAIN = 'analytics-seed.biztrack.invalid';

    /** Businesses this seeder owns, tagged independently of the account. */
    public const REGISTRATION_PREFIX = 'AHS-';

    /**
     * Optional visible marker appended to generated free text.
     *
     * Empty on purpose: these rows appear in screenshots that go into the
     * client's paper, and a "[demo history]" suffix on every business name
     * reads as scaffolding there. Identification does not depend on it —
     * isSeeded() and purge() match on EMAIL_DOMAIN and REGISTRATION_PREFIX,
     * which are structural and cannot be edited away by a staff user renaming
     * a business. Set this to a non-empty string to make demo rows obvious in
     * staff-facing lists again; every call site tolerates either.
     */
    public const LABEL = '';

    /* ── shape (see r/config.R) ───────────────────────────────────────────── */

    /** Same seed as r/config.R: every run produces the same history. */
    private const SEED = 1103;

    /*
     * The five knobs below are `protected` and read through `static::`, so a
     * subclass can drive the same code path over a short window. That is how
     * AnalyticsHistorySeederTest exercises the real generator and the real
     * purge without spending three years of history on every test run.
     */

    protected const MONTHS = 36;

    /**
     * Monthly filing volume ramps from START to END across the window.
     *
     * The ramp does double duty: it reads as an adoption curve (a register
     * three years into digitisation files more than it did on day one, which is
     * where the reported growth rate comes from), and it puts the density where
     * the control chart needs it — the trailing 52 weeks carry roughly 11
     * filings a week, which is 8-12 completed reviews a week for each of the
     * three core offices, comfortably clear of the minimum of 3.
     */
    protected const VOLUME_START = 26;

    protected const VOLUME_END = 54;

    /** Curvature of the adoption ramp; >1 back-loads volume. */
    protected const VOLUME_CURVE = 1.0;

    /** Office whose reviews are slowed, and by how much, over how long. */
    private const ANOMALY_DEPARTMENT = 'CHO';

    private const ANOMALY_WEEKS = 8;

    private const ANOMALY_MULTIPLIER_START = 1.35;

    private const ANOMALY_MULTIPLIER_END = 1.95;

    /**
     * Target mean review turnaround per office, in days, measured the way the
     * chart measures it: `completed_at - assigned_at`, queue time included.
     */
    private const OFFICE_TURNAROUND_DAYS = ['BPLO' => 2.0, 'CHO' => 2.5, 'BFP' => 3.0, 'CPDO' => 2.2];

    /** Shape of the lognormal review duration (generate.R's sdlog). */
    private const SERVICE_SDLOG = 0.40;

    /** Share of reviews that suffer a returned -> resubmitted loop. */
    private const RETURN_LOOP_RATE = 0.10;

    /** Share of seeded businesses that close, spread across the window. */
    private const CLOSURE_RATE = 0.075;

    /** How many owner accounts the seeded businesses are shared between. */
    protected const OWNER_ACCOUNTS = 60;

    /** Reviewer headcount per office (r/config.R DEPARTMENTS$reviewers). */
    private const REVIEWERS = ['BPLO' => 3, 'CHO' => 2, 'BFP' => 2, 'CPDO' => 1];

    /* ── runtime state ────────────────────────────────────────────────────── */

    private WorkflowService $workflow;

    private Carbon $anchor;

    /**
     * Seeded reviews stop closing at the start of the current ISO week.
     *
     * A week still in progress only contains the reviews that have *finished*
     * so far, which are the fast ones — so its mean is biased low, and on a
     * control chart that shows up as a steady office dipping below its own
     * lower limit for no reason. Every fully-elapsed week is unbiased, so the
     * seeded history simply stops closing reviews at the last week boundary.
     * Filings keep arriving up to the anchor; their reviews are left open, as a
     * live register's would be.
     */
    private Carbon $reviewCutoff;

    private Carbon $anomalyStart;

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, PermitType> */
    private array $permitTypes = [];

    /** @var array<string, list<User>> office code => reviewer accounts */
    private array $reviewers = [];

    /** @var list<User> */
    private array $owners = [];

    /** @var list<array{id: int, name: string, weight: float}> */
    private array $barangays = [];

    /** @var list<array{id: int, code: string, title: string, category: string, capital: array{int, int}}> */
    private array $lines = [];

    /** @var array<int, array{business: Business, registered_at: Carbon, renewed_years: array<int, true>}> */
    private array $register = [];

    private array $counts = [
        'businesses' => 0, 'applications' => 0, 'assignments' => 0,
        'completed_reviews' => 0, 'permits' => 0, 'closures' => 0,
        'approved' => 0, 'rejected' => 0, 'in_flight' => 0, 'returned_loops' => 0,
    ];

    public function run(): void
    {
        if (self::isSeeded()) {
            $this->command?->warn(
                'AnalyticsHistorySeeder: already seeded — nothing to do. '
                .'Run `php artisan db:seed --class=AnalyticsHistoryPurgeSeeder` first to rebuild.'
            );

            return;
        }

        $this->bootReferenceData();
        $this->quietOutboundChannels();

        $this->workflow = app(WorkflowService::class);
        $this->anchor = Carbon::now();
        $this->reviewCutoff = $this->anchor->copy()->startOfWeek(Carbon::MONDAY);
        $this->anomalyStart = $this->reviewCutoff->copy()->subWeeks(self::ANOMALY_WEEKS - 1);

        mt_srand(self::SEED);

        $this->command?->info(sprintf(
            'AnalyticsHistorySeeder: building %d months of history ending %s.',
            static::MONTHS,
            $this->anchor->toDateString(),
        ));

        try {
            $this->createOwnerAccounts();
            $this->createReviewerAccounts();

            $calendar = $this->planFilingDates();
            $this->command?->info(sprintf('  %d filings planned. Writing…', count($calendar)));

            foreach ($calendar as $i => $at) {
                $this->writeFiling($at);
                if ($this->command && $i > 0 && $i % 100 === 0) {
                    $this->command->line(sprintf('  … %d/%d filings', $i, count($calendar)));
                }
            }

            $this->closeBusinesses();
            $this->lapsePermits();
        } finally {
            Carbon::setTestNow();
            Auth::forgetGuards();
        }

        $this->report();
    }

    /** True when this seeder has already written its history. */
    public static function isSeeded(): bool
    {
        return User::withTrashed()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->exists()
            || Business::withTrashed()->where('registration_number', 'like', self::REGISTRATION_PREFIX.'%')->exists();
    }

    /* ── setup ────────────────────────────────────────────────────────────── */

    private function bootReferenceData(): void
    {
        $this->departments = Department::whereIn('code', ['BPLO', 'CHO', 'BFP', 'CPDO'])
            ->get()->keyBy('code')->all();
        $this->permitTypes = PermitType::whereIn('code', ['BUSINESS', 'SANITARY', 'FSIC', 'ZONING'])
            ->get()->keyBy('code')->all();

        if (count($this->departments) < 4 || count($this->permitTypes) < 4) {
            throw new \RuntimeException(
                'AnalyticsHistorySeeder needs ReferenceSeeder + RbacSeeder to have run first.'
            );
        }

        // generate.R's BARANGAY_WEIGHTS, keyed to the spellings ReferenceSeeder
        // actually stores (Santulan / Tañong, not Santolan / Tanong).
        $weights = [
            'Acacia' => 2, 'Baritan' => 3, 'Bayan-bayanan' => 4, 'Catmon' => 3,
            'Concepcion' => 8, 'Dampalit' => 3, 'Flores' => 2, 'Hulong Duhat' => 3,
            'Ibaba' => 2, 'Longos' => 12, 'Maysilo' => 4, 'Muzon' => 3,
            'Niugan' => 3, 'Panghulo' => 4, 'Potrero' => 10, 'San Agustin' => 3,
            'Santulan' => 2, 'Tañong' => 9, 'Tinajeros' => 9, 'Tonsuya' => 4,
            'Tugatog' => 3,
        ];
        foreach (Barangay::orderBy('name')->get(['id', 'name']) as $barangay) {
            $this->barangays[] = [
                'id' => $barangay->id,
                'name' => $barangay->name,
                'weight' => (float) ($weights[$barangay->name] ?? 2),
            ];
        }

        // A spread of PSIC lines with the revenue-code category the fee engine
        // matches on, so the assessments the workflow raises are real amounts.
        $spread = [
            '47111' => ['retailer', [80_000, 400_000]],
            '56101' => ['restaurant', [150_000, 900_000]],
            '10711' => ['essential_manufacturer', [200_000, 1_200_000]],
            '47721' => ['essential_retailer', [400_000, 2_500_000]],
            '47521' => ['retailer', [500_000, 3_000_000]],
            '96200' => ['retailer', [90_000, 350_000]],
            '36000' => ['essential_manufacturer', [250_000, 800_000]],
            '18120' => ['printing_publication', [200_000, 900_000]],
            '96110' => ['retailer', [60_000, 250_000]],
            '45201' => ['contractor', [300_000, 1_500_000]],
            '47411' => ['retailer', [400_000, 1_800_000]],
            '56301' => ['restaurant', [200_000, 1_100_000]],
            '47912' => ['retailer', [70_000, 600_000]],
            '82990' => ['contractor', [150_000, 700_000]],
            '93110' => ['amusement_place', [500_000, 2_000_000]],
            '14100' => ['manufacturer', [180_000, 800_000]],
            '22200' => ['manufacturer', [600_000, 3_500_000]],
            '23950' => ['manufacturer', [700_000, 4_000_000]],
            '25920' => ['manufacturer', [400_000, 2_200_000]],
            '31001' => ['manufacturer', [250_000, 1_400_000]],
            '41000' => ['contractor', [800_000, 5_000_000]],
            '43210' => ['contractor', [200_000, 1_000_000]],
            '46100' => ['wholesaler', [500_000, 3_000_000]],
        ];
        foreach (PsicCode::whereIn('code', array_keys($spread))->get(['id', 'code', 'title']) as $psic) {
            [$category, $capital] = $spread[$psic->code];
            $this->lines[] = [
                'id' => $psic->id,
                'code' => $psic->code,
                'title' => $psic->title,
                'category' => $category,
                'capital' => $capital,
            ];
        }

        if ($this->lines === []) {
            throw new \RuntimeException('AnalyticsHistorySeeder found no PSIC reference rows.');
        }
    }

    /**
     * Mail and SMS both run on the `log` driver in development. The workflow
     * fans out on every transition, so a run of this size would append tens of
     * thousands of lines to laravel.log for no one's benefit. The in-app
     * notification rows are still written — those are register history.
     */
    private function quietOutboundChannels(): void
    {
        config(['mail.default' => 'array']);
        app()->bind(SmsChannel::class, fn () => new class implements SmsChannel
        {
            public function send(string $to, string $message): void {}
        });
    }

    private function createOwnerAccounts(): void
    {
        $given = ['Nerissa', 'Rolando', 'Marilou', 'Danilo', 'Cristina', 'Alfredo', 'Jocelyn',
            'Wilfredo', 'Analiza', 'Rogelio', 'Emelita', 'Bernardo', 'Editha', 'Arnulfo',
            'Luzviminda', 'Teodoro', 'Rowena', 'Nestor', 'Girlie', 'Efren'];
        $surnames = ['Bautista', 'Salazar', 'Mendoza', 'Panganiban', 'Ocampo', 'Villanueva',
            'Delos Reyes', 'Cabrera', 'Sarmiento', 'Magtibay', 'Bandiola', 'Estrella',
            'Nabong', 'Rivera', 'Aquino', 'Bulaong', 'Fajardo', 'Guevarra'];

        for ($i = 1; $i <= static::OWNER_ACCOUNTS; $i++) {
            $first = $given[($i * 7) % count($given)];
            $last = $surnames[($i * 5) % count($surnames)];
            $this->owners[] = $this->account(
                sprintf('owner%03d@%s', $i, self::EMAIL_DOMAIN),
                $first,
                $last,
                $i % 2 === 0 ? 'F' : 'M',
                ['business_owner'],
            );
        }
    }

    /**
     * Reviewers of record for the seeded history.
     *
     * The demo staff accounts (bplo@, sanitary@, fire@) belong to real testers;
     * attributing three years of invented reviews to them would corrupt their
     * own view of their work. These accounts carry it instead, are labelled as
     * demo in every staff list, and go away with the purge.
     *
     * Headcount follows r/config.R, and the accounts are left ACTIVE, which is
     * a deliberate trade against WorkflowService::leastLoadedInspector picking
     * one for a real filing. StaffingSimulation models office capacity from the
     * count of active officers per department, so deactivating them would leave
     * one BPLO officer accountable for ~670 reviews a year and make that
     * screen's capacity model incoherent. The inspector picker orders by open
     * inspection count and every seeded inspection is closed, so the tie falls
     * to the lower-id real officers; and an OIC can reassign either way.
     */
    private function createReviewerAccounts(): void
    {
        $roles = [
            'BPLO' => 'bplo_staff',
            'CHO' => 'sanitary_officer',
            'BFP' => 'fire_inspector',
            'CPDO' => 'zoning_officer',
        ];
        $names = [
            'BPLO' => [['Perlita', 'Sandoval'], ['Ignacio', 'Bermudez'], ['Sonia', 'Talusan']],
            'CHO' => [['Almira', 'Delgado'], ['Bonifacio', 'Yumul']],
            'BFP' => [['Rodel', 'Pineda'], ['Marissa', 'Concepcion']],
            'CPDO' => [['Herminia', 'Alcantara']],
        ];

        foreach (self::REVIEWERS as $code => $headcount) {
            for ($i = 0; $i < $headcount; $i++) {
                [$first, $last] = $names[$code][$i];
                $this->reviewers[$code][] = $this->account(
                    sprintf('%s.reviewer%d@%s', strtolower($code), $i + 1, self::EMAIL_DOMAIN),
                    $first,
                    $last,
                    $i % 2 === 0 ? 'F' : 'M',
                    [$roles[$code]],
                    $this->departments[$code],
                );
            }
        }
    }

    private function account(
        string $email,
        string $first,
        string $last,
        string $gender,
        array $roles,
        ?Department $department = null,
    ): User {
        $user = User::create([
            'name' => rtrim("$first $last ".self::LABEL),
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'email' => $email,
            'mobile_number' => '09'.str_pad((string) mt_rand(100000000, 999999999), 9, '0'),
            // Unguessable and never printed: these accounts exist to own rows,
            // not to be signed into.
            'password' => Hash::make(bin2hex(random_bytes(24))),
            'department_id' => $department?->id,
            'is_active' => true,
            'email_verified_at' => Carbon::now(),
            'data_privacy_consent_at' => Carbon::now(),
        ]);
        $user->roles()->sync(Role::whereIn('name', $roles)->pluck('id'));

        return $user;
    }

    /* ── planning ─────────────────────────────────────────────────────────── */

    /**
     * Submission instants for every filing in the window, in chronological
     * order.
     *
     * Chronological matters: App\Support\Numbering derives each tracking id and
     * permit number from the highest already issued, so filing in submission
     * order keeps the identifiers reading naturally and keeps them continuous
     * with the numbers real testers have already been given.
     *
     * Which business each filing belongs to is decided while writing, not here
     * — the choice depends on who is already on the register at that instant.
     *
     * @return list<Carbon>
     */
    private function planFilingDates(): array
    {
        $firstMonth = $this->anchor->copy()->startOfMonth()->subMonths(static::MONTHS - 1);

        $dates = [];
        for ($m = 0; $m < static::MONTHS; $m++) {
            $month = $firstMonth->copy()->addMonths($m);
            $volume = $this->monthlyVolume($m, (int) $month->month);

            for ($k = 0; $k < $volume; $k++) {
                $day = mt_rand(1, $month->daysInMonth);
                // A business hour on a weekday: filings do not arrive at 03:00.
                $at = $month->copy()->setDay($day)
                    ->setTime(8, 0)
                    ->addSeconds((int) $this->uniform(0, 8 * 3600));
                while ($at->isWeekend()) {
                    $at->addDay();
                }
                if ($at->greaterThan($this->anchor)) {
                    continue;
                }
                $dates[] = $at;
            }
        }

        usort($dates, fn (Carbon $a, Carbon $b) => $a <=> $b);

        return $dates;
    }

    /** generate.R's seasonal multiplier over an adoption ramp, plus +/-10% noise. */
    private function monthlyVolume(int $monthIndex, int $monthNumber): int
    {
        $progress = static::MONTHS > 1 ? $monthIndex / (static::MONTHS - 1) : 1.0;
        $base = static::VOLUME_START
            + (static::VOLUME_END - static::VOLUME_START) * ($progress ** static::VOLUME_CURVE);

        $season = match ($monthNumber) {
            1 => 2.5,
            2 => 1.6,
            12 => 0.7,
            default => 1.0,
        };

        return max(4, (int) round($base * $season * $this->normal(1.0, 0.10)));
    }

    /**
     * A business that could plausibly file a renewal or amendment on this date.
     *
     * Registered at least 200 days ago, still open, and has not already renewed
     * this calendar year. Weighted Zipf-ishly towards the earliest registrants
     * so a handful of businesses accumulate three years of filings, as
     * generate.R's `biz_weights` intends.
     */
    private function pickRenewalCandidate(Carbon $at): ?int
    {
        if ($this->register === []) {
            return null;
        }

        $eligible = [];
        $weights = [];
        $rank = 0;
        foreach ($this->register as $id => $entry) {
            $rank++;
            if ($this->daysBetween($entry['registered_at'], $at) < 200) {
                continue;
            }
            if (isset($entry['renewed_years'][(int) $at->year])) {
                continue;
            }
            $eligible[] = $id;
            $weights[] = 1 / ($rank ** 0.6);
        }

        if ($eligible === []) {
            return null;
        }

        return $eligible[$this->pickWeighted($weights)];
    }

    /* ── writing ──────────────────────────────────────────────────────────── */

    private function writeFiling(Carbon $submittedAt): void
    {
        // Either a brand new registration (a "new" filing) or a business
        // already on the register coming back to renew or amend. January and
        // February are renewal season, so new registrations are rarer then.
        $renewalSeason = in_array((int) $submittedAt->month, [1, 2], true);
        $candidate = $this->pickRenewalCandidate($submittedAt);

        if ($candidate === null || $this->chance($renewalSeason ? 0.18 : 0.45)) {
            $type = ApplicationType::New;
            $business = $this->createBusiness($submittedAt);
        } else {
            $type = $this->chance(0.82) ? ApplicationType::Renewal : ApplicationType::Amendment;
            $business = $this->register[$candidate]['business'];
            $this->register[$candidate]['renewed_years'][(int) $submittedAt->year] = true;
        }

        $this->register[$business->id]['last_filing_at'] = $submittedAt->copy();
        $owner = $business->owner;

        // Which permits are being asked for. New filings go the full route;
        // renewals mostly re-validate health and fire too (both certificates
        // are annual), a minority are a BPLO re-validation only, and amendments
        // touch the business permit alone.
        $codes = match (true) {
            $type === ApplicationType::New => $this->chance(0.35)
                ? ['BUSINESS', 'SANITARY', 'FSIC', 'ZONING']
                : ['BUSINESS', 'SANITARY', 'FSIC'],
            $type === ApplicationType::Renewal => $this->chance(0.72)
                ? ['BUSINESS', 'SANITARY', 'FSIC']
                : ['BUSINESS'],
            default => ['BUSINESS'],
        };
        $requested = array_map(fn (string $c) => $this->permitTypes[$c], $codes);

        // ── draft ──────────────────────────────────────────────────────────
        $draftedAt = $submittedAt->copy()->subHours(mt_rand(1, 30));
        $this->travelTo($draftedAt);
        Auth::setUser($owner);

        $app = Application::create([
            'business_id' => $business->id,
            'applicant_user_id' => $owner->id,
            'application_type' => $type,
            'status' => ApplicationStatus::Draft,
            'fee_profile' => $this->feeProfile($business, $type),
            'payment_mode' => $this->chance(0.7) ? 'annual' : 'quarterly',
        ]);
        $app->permitTypes()->sync(collect($requested)->pluck('id'));
        // RA 11032 classification. New filings are complex (multi-office),
        // renewals and amendments are simple.
        $app->forceFill(['complexity' => $type === ApplicationType::New ? 'complex' : 'simple'])->save();

        // ── submit → fee assessment → pending payment ──────────────────────
        $this->travelTo($submittedAt);
        $app = $this->workflow->submit($app);
        $this->counts['applications']++;

        // A slice of very recent filings is genuinely still awaiting payment.
        $ageDays = $this->daysBetween($submittedAt, $this->anchor);
        if ($ageDays < 6 && $this->chance(0.35)) {
            $this->counts['in_flight']++;

            return;
        }

        // ── payment → under review → routed to the owning offices ──────────
        $paidAt = $submittedAt->copy()->addSeconds((int) $this->uniform(2 * 3600, 26 * 3600));
        if ($paidAt->greaterThan($this->anchor)) {
            $paidAt = $this->anchor->copy();
        }
        $this->travelTo($paidAt);

        $fee = $app->feeAssessment;
        $payment = Payment::create([
            'application_id' => $app->id,
            'fee_assessment_id' => $fee->id,
            'reference_number' => Numbering::paymentReference(),
            'amount' => $fee->total_amount,
            'method' => [PaymentMethod::Gcash, PaymentMethod::Maya, PaymentMethod::Card][mt_rand(0, 2)],
            'status' => PaymentStatus::Completed,
            'paid_at' => $paidAt,
        ]);
        $this->workflow->onPaymentCompleted($payment);

        $assignments = $app->assignments()->with('department')->get();
        $this->counts['assignments'] += $assignments->count();

        // ── the reviews ────────────────────────────────────────────────────
        $reviews = $this->planReviews($assignments, $paidAt);
        $reject = $this->chance($ageDays < 25 ? 0.05 : 0.07);

        // A rejection is a decision made while at least one office still has
        // the file open, so the last review is left outstanding for it.
        $completable = array_values(array_filter(
            $reviews,
            fn (array $r) => $r['completed_at']->lessThan($this->reviewCutoff),
        ));
        if ($reject && count($completable) === count($reviews) && count($reviews) > 0) {
            array_pop($completable);
        }

        $lastCompletedAt = null;
        foreach ($completable as $review) {
            $lastCompletedAt = $this->runReview($app, $review);
        }
        $allReviewsDone = count($completable) === count($reviews) && $reviews !== [];

        if ($reject) {
            $decidedAt = ($lastCompletedAt ?? $paidAt)->copy()->addSeconds((int) $this->uniform(3600, 30 * 3600));
            if ($decidedAt->greaterThan($this->anchor)) {
                $decidedAt = $this->anchor->copy();
            }
            $this->travelTo($decidedAt);
            Auth::setUser($this->reviewers['BPLO'][0]);
            $this->workflow->rejectApplication($app->fresh(), $this->rejectionReason());
            $this->counts['rejected']++;

            return;
        }

        if (! $allReviewsDone) {
            $this->counts['in_flight']++;

            return;
        }

        // ── inspections (scheduled by the workflow when reviews all clear) ──
        $app->refresh();
        $visits = [];
        foreach ($app->inspections()->with('department')->get() as $inspection) {
            $inspector = $this->reviewerFor($inspection->department->code);
            if ($inspector) {
                // The workflow picks the least-loaded active inspector, which
                // would land on a real tester's account. Reattribute to the
                // seeded reviewer for the same office; nothing else changes.
                $inspection->forceFill(['inspector_user_id' => $inspector->id])->save();
            }
            $visits[] = [
                'inspection' => $inspection,
                'inspector' => $inspector,
                'conducted_at' => $inspection->scheduled_at->copy()
                    ->addSeconds((int) $this->uniform(0.5 * 86400, 2.5 * 86400)),
            ];
        }
        // Conduct them in date order: the last visit is what triggers issuance,
        // so out-of-order visits would date the decision before an inspection.
        usort($visits, fn (array $a, array $b) => $a['conducted_at'] <=> $b['conducted_at']);

        $pendingInspection = false;
        foreach ($visits as $visit) {
            if ($visit['conducted_at']->greaterThan($this->anchor)) {
                $pendingInspection = true;

                continue;
            }

            $this->travelTo($visit['conducted_at']);
            Auth::setUser($visit['inspector'] ?? $this->reviewers['BPLO'][0]);
            $this->workflow->recordInspection(
                $visit['inspection'],
                $this->chance(0.86) ? InspectionResult::Passed : InspectionResult::Conditional,
                $this->chance(0.86)
                    ? 'Premises inspected. Compliant with the applicable requirements.'
                    : 'Compliant subject to correction of minor findings within 30 days.',
            );
        }

        if ($pendingInspection) {
            $this->counts['in_flight']++;

            return;
        }

        // No inspection-bearing permit type: the workflow already approved and
        // issued on the last review. Either way the application is decided.
        $app->refresh();
        if ($app->status === ApplicationStatus::Approved) {
            $this->counts['approved']++;
            $this->counts['permits'] += $app->permits()->count();
        } else {
            $this->counts['in_flight']++;
        }
    }

    /**
     * Assign a completion time to each office review.
     *
     * Duration is a lognormal draw whose arithmetic mean is the office target
     * (generate.R's `rlnorm_mean`), and the injected slowdown multiplies CHO's
     * draw inside the anomaly window.
     *
     * @param  Collection<int, ApplicationAssignment>  $assignments
     * @return list<array{assignment: ApplicationAssignment, code: string, completed_at: Carbon, loop: bool}>
     */
    private function planReviews($assignments, Carbon $assignedAt): array
    {
        $reviews = [];
        foreach ($assignments as $assignment) {
            $code = $assignment->department->code;
            $target = self::OFFICE_TURNAROUND_DAYS[$code] ?? 2.5;

            // Queue time before a reviewer picks the file up: same or next
            // business day. The rest is the review itself.
            $queue = $this->uniform(0.25, 1.25);
            $service = $this->lognormalWithMean(max(0.4, $target - 0.75));

            $loop = $this->chance(self::RETURN_LOOP_RATE);
            $loopDays = $loop ? $this->uniform(2, 5) : 0.0;

            $days = ($queue + $service + $loopDays) * $this->anomalyMultiplier($code, $assignedAt);

            $reviews[] = [
                'assignment' => $assignment,
                'code' => $code,
                'completed_at' => $assignedAt->copy()->addSeconds((int) round($days * 86400)),
                'loop' => $loop,
            ];
        }

        usort($reviews, fn (array $a, array $b) => $a['completed_at'] <=> $b['completed_at']);

        return $reviews;
    }

    /**
     * The injected slowdown: CHO reviews assigned inside the anomaly window run
     * progressively slower. This is the only place in the whole seeder that
     * treats one office differently, and it exists so the control chart has a
     * true signal to catch rather than noise to explain.
     */
    private function anomalyMultiplier(string $code, Carbon $assignedAt): float
    {
        if ($code !== self::ANOMALY_DEPARTMENT || $assignedAt->lessThan($this->anomalyStart)) {
            return 1.0;
        }

        $weeksIn = (int) floor($this->daysBetween($this->anomalyStart, $assignedAt) / 7);
        $weeksIn = max(0, min(self::ANOMALY_WEEKS - 1, $weeksIn));
        $progress = self::ANOMALY_WEEKS > 1 ? $weeksIn / (self::ANOMALY_WEEKS - 1) : 1.0;

        return self::ANOMALY_MULTIPLIER_START
            + (self::ANOMALY_MULTIPLIER_END - self::ANOMALY_MULTIPLIER_START) * $progress;
    }

    /**
     * Run one office review through the workflow, including the
     * returned/resubmitted loop when this review drew one.
     *
     * @param  array{assignment: ApplicationAssignment, code: string, completed_at: Carbon, loop: bool}  $review
     * @return Carbon when the review closed
     */
    private function runReview(Application $app, array $review): Carbon
    {
        $assignment = $review['assignment'];
        $officer = $this->reviewerFor($review['code']);
        $completedAt = $review['completed_at'];

        if ($officer) {
            $this->travelTo($assignment->assigned_at);
            Auth::setUser($officer);
            $this->workflow->assignOfficer($assignment, $officer, 'Queue distribution');
        }

        if ($review['loop']) {
            // Two thirds of the way through the review the office asks for a
            // correction; the applicant resubmits and the clock keeps running.
            $returnedAt = $assignment->assigned_at->copy()->addSeconds(
                (int) round($assignment->assigned_at->diffInSeconds($completedAt) * 0.55)
            );
            $this->travelTo($returnedAt);
            Auth::setUser($officer ?? $app->applicant);
            $this->workflow->returnAssignment($assignment, $this->returnRemark());

            $resubmittedAt = $returnedAt->copy()->addSeconds((int) $this->uniform(6 * 3600, 40 * 3600));
            if ($resubmittedAt->greaterThan($completedAt)) {
                $resubmittedAt = $completedAt->copy()->subMinutes(30);
            }
            $this->travelTo($resubmittedAt);
            Auth::setUser($app->applicant);
            $this->workflow->resubmit($app->fresh());
            $this->counts['returned_loops']++;

            $assignment->refresh();
        }

        $this->travelTo($completedAt);
        Auth::setUser($officer ?? $app->applicant);
        $this->workflow->approveAssignment($assignment, $this->reviewRemark($review['code']));
        $this->counts['completed_reviews']++;

        return $completedAt;
    }

    /* ── businesses ───────────────────────────────────────────────────────── */

    private function createBusiness(Carbon $firstFilingAt): Business
    {
        // Registered a little before the first filing, the way generate.R backs
        // `registered_at` off the first application date.
        $registeredAt = $firstFilingAt->copy()->subDays(mt_rand(3, 40))->setTime(mt_rand(9, 15), mt_rand(0, 59));
        $owner = $this->owners[mt_rand(0, count($this->owners) - 1)];
        $barangay = $this->barangays[$this->pickWeighted(array_column($this->barangays, 'weight'))];
        $line = $this->lines[mt_rand(0, count($this->lines) - 1)];

        $this->travelTo($registeredAt);
        Auth::setUser($owner);

        $sequence = $this->counts['businesses'] + 1;
        $business = Business::create([
            'owner_user_id' => $owner->id,
            'name' => $this->businessName($line, $owner, $sequence),
            'trade_name' => null,
            'registration_type' => $this->chance(0.72) ? 'DTI' : 'SEC',
            // Tag #2: independent of the account, so seeded businesses stay
            // identifiable even if an account is renamed or reassigned.
            'registration_number' => sprintf('%s%06d', self::REGISTRATION_PREFIX, $sequence),
            'tin' => sprintf('%03d-%03d-%03d-000', mt_rand(100, 999), mt_rand(100, 999), mt_rand(100, 999)),
            'ban' => Numbering::ban(),
            'status' => 'active',
        ]);

        BusinessAddress::create([
            'business_id' => $business->id,
            'line1' => mt_rand(1, 480).' '.$this->streetName().' St.',
            'barangay_id' => $barangay['id'],
            'latitude' => round(14.655 + mt_rand(0, 300) / 10000, 6),
            'longitude' => round(120.945 + mt_rand(0, 300) / 10000, 6),
        ]);

        BusinessLine::create([
            'business_id' => $business->id,
            'psic_code_id' => $line['id'],
            'capitalization' => mt_rand($line['capital'][0], $line['capital'][1]),
            'line_of_business' => $line['title'],
        ]);

        BusinessOwner::create([
            'business_id' => $business->id,
            'surname' => $owner->last_name,
            'given_name' => $owner->first_name,
            'gender' => $owner->gender,
            'is_primary' => true,
        ]);

        $business->setRelation('owner', $owner);
        $this->counts['businesses']++;
        $this->register[$business->id] = [
            'business' => $business,
            'registered_at' => $registeredAt,
            'last_filing_at' => $registeredAt,
            'renewed_years' => [],
        ];

        return $business;
    }

    /**
     * Closures, dated by `deleted_at` — the only honest closure date in the
     * schema (see BusinessGrowthAnalytics). Spread across the window so both
     * the reported period and the one before it have a trend to draw.
     */
    private function closeBusinesses(): void
    {
        $ids = array_keys($this->register);
        shuffle($ids);
        $target = (int) round(count($ids) * self::CLOSURE_RATE);

        $closed = 0;
        $latest = $this->anchor->copy()->subDays(5);
        foreach ($ids as $id) {
            if ($closed >= $target) {
                break;
            }
            $entry = $this->register[$id];

            // A closure needs a life before it: eight months of trading, and a
            // month clear of the last filing, so nothing is filed post-mortem.
            $earliest = $entry['registered_at']->copy()->addMonths(8);
            $afterLastFiling = $entry['last_filing_at']->copy()->addDays(30);
            if ($afterLastFiling->greaterThan($earliest)) {
                $earliest = $afterLastFiling;
            }
            if ($earliest->greaterThan($latest)) {
                continue;
            }

            $closedAt = $earliest->copy()
                ->addDays(mt_rand(0, max(1, (int) $this->daysBetween($earliest, $latest))))
                ->setTime(mt_rand(9, 16), mt_rand(0, 59));

            $this->travelTo($closedAt);
            $entry['business']->delete();
            $closed++;
        }

        $this->counts['closures'] = $closed;
    }

    /**
     * Lapse the permits whose validity has run out.
     *
     * The workflow issues every permit `active`; nothing in the prototype ages
     * them, so without this every three-year-old permit would still read as
     * live and the lifecycle summary would show no Expired businesses at all.
     * Scoped to seeded businesses — real testers' permits are left alone.
     */
    private function lapsePermits(): void
    {
        Carbon::setTestNow();
        $today = Carbon::now()->toDateString();

        $lapsed = 0;
        foreach (array_chunk(array_keys($this->register), 400) as $chunk) {
            $lapsed += Permit::whereIn('business_id', $chunk)
                ->where('status', PermitStatus::Active->value)
                ->whereDate('valid_until', '<', $today)
                ->update(['status' => PermitStatus::Expired->value]);
        }
        $this->counts['expired_permits'] = $lapsed;
    }

    /* ── content ──────────────────────────────────────────────────────────── */

    private function feeProfile(Business $business, ApplicationType $type): array
    {
        $business->loadMissing('lines.psicCode');
        $line = $this->lineMetaFor($business);
        $capitalization = (float) ($business->lines->first()?->capitalization ?? 250_000);
        $grossSales = $type === ApplicationType::New
            ? 0.0
            : round($capitalization * $this->uniform(0.9, 3.4), 2);

        return [
            'capitalization' => $capitalization,
            'gross_sales' => $grossSales,
            'is_new_business' => $type === ApplicationType::New,
            'lines' => [[
                'category' => $line['category'],
                'capitalization' => $capitalization,
                'gross_sales' => $grossSales,
            ]],
            'flags' => [],
        ];
    }

    /** @return array{category: string, title: string} */
    private function lineMetaFor(Business $business): array
    {
        $code = $business->lines->first()?->psicCode?->code;
        foreach ($this->lines as $line) {
            if ($line['code'] === $code) {
                return $line;
            }
        }

        return $this->lines[0];
    }

    private function businessName(array $line, User $owner, int $sequence): string
    {
        $patterns = [
            fn () => sprintf("%s's %s", $owner->first_name, $this->tradeWord($line)),
            fn () => sprintf('%s %s', $owner->last_name, $this->tradeWord($line)),
            fn () => sprintf('%s %s Trading', $owner->last_name, $this->tradeWord($line)),
            fn () => sprintf('%s %s Enterprises', $this->prefixWord(), $this->tradeWord($line)),
            fn () => sprintf('%s %s Center', $this->prefixWord(), $this->tradeWord($line)),
        ];

        return $patterns[$sequence % count($patterns)]().self::LABEL;
    }

    private function tradeWord(array $line): string
    {
        return match ($line['category']) {
            'restaurant' => ['Kitchen', 'Eatery', 'Carinderia', 'Kusina'][mt_rand(0, 3)],
            'manufacturer', 'essential_manufacturer' => ['Manufacturing', 'Works', 'Industries', 'Products'][mt_rand(0, 3)],
            'contractor' => ['Builders', 'Services', 'Construction', 'Works'][mt_rand(0, 3)],
            'wholesaler' => ['Distributors', 'Wholesale', 'Supply'][mt_rand(0, 2)],
            'amusement_place' => ['Fitness', 'Sports Hub', 'Recreation'][mt_rand(0, 2)],
            'printing_publication' => ['Printing', 'Press', 'Graphics'][mt_rand(0, 2)],
            default => ['Store', 'Mart', 'Shop', 'Trading', 'Variety Store'][mt_rand(0, 4)],
        };
    }

    private function prefixWord(): string
    {
        $words = ['Bagong', 'Maligaya', 'Sampaguita', 'Kanlaon', 'Pag-asa', 'Marikit',
            'Golden', 'Riverside', 'Northgate', 'Malabon', 'Tanging', 'Sinag'];

        return $words[mt_rand(0, count($words) - 1)];
    }

    private function streetName(): string
    {
        $streets = ['Gen. Luna', 'Rizal Ave.', 'M.H. del Pilar', 'Bonifacio', 'Sanciangco',
            'Gov. Pascual', 'F. Sevilla', 'Estrella', 'Katipunan', 'Mabini', 'Kaunlaran',
            'San Bartolome', 'Tugatog', 'Maya-maya'];

        return $streets[mt_rand(0, count($streets) - 1)];
    }

    private function reviewRemark(string $code): string
    {
        return match ($code) {
            'BPLO' => 'Requirements complete. Business permit review cleared.',
            'CHO' => 'Sanitary requirements verified. Cleared for health certificate.',
            'BFP' => 'Fire safety requirements verified. Cleared for FSIC.',
            'CPDO' => 'Locational clearance consistent with the zoning ordinance.',
            default => 'Office review cleared.',
        }.self::LABEL;
    }

    private function returnRemark(): string
    {
        $remarks = [
            'Uploaded barangay clearance is illegible — please re-upload a clearer copy.',
            'Lease contract is missing the lessor signature page.',
            'Declared floor area does not match the sketch plan; please reconcile.',
            'Latest community tax certificate is not attached.',
        ];

        return $remarks[mt_rand(0, count($remarks) - 1)].self::LABEL;
    }

    private function rejectionReason(): string
    {
        $reasons = [
            'Requested requirements were not completed within the allowed period.',
            'Declared line of business is not permitted at the proposed location.',
            'Applicant withdrew the filing after the deficiency notice.',
            'Sanitary deficiencies were not corrected on re-inspection.',
        ];

        return $reasons[mt_rand(0, count($reasons) - 1)].self::LABEL;
    }

    /* ── plumbing ─────────────────────────────────────────────────────────── */

    private function reviewerFor(string $code): ?User
    {
        $pool = $this->reviewers[$code] ?? [];
        if ($pool === []) {
            return null;
        }

        return $pool[mt_rand(0, count($pool) - 1)];
    }

    /**
     * Drive the workflow's `now()`.
     *
     * Everything downstream — model timestamps, `submitted_at`, `deadline_at`,
     * assignment and inspection dates, permit validity, `decided_at`, status
     * history — reads from here, which is how the seeded rows stay mutually
     * consistent without any of them being written by hand.
     */
    private function travelTo(Carbon $at): void
    {
        Carbon::setTestNow($at->copy());
    }

    /**
     * Signed fractional days from $from to $to.
     *
     * Spelled out rather than `diffInDays(..., false)` because the sign
     * convention of that argument changed between Carbon 2 and 3, and getting
     * it backwards here would silently mis-date the whole history.
     */
    private function daysBetween(Carbon $from, Carbon $to): float
    {
        return ($to->getTimestamp() - $from->getTimestamp()) / 86400;
    }

    /* ── deterministic random ─────────────────────────────────────────────── */

    private function uniform(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }

    private function chance(float $probability): bool
    {
        return (mt_rand() / mt_getrandmax()) < $probability;
    }

    /** Box-Muller, so the volume noise is Gaussian like generate.R's rnorm. */
    private function normal(float $mean, float $sd): float
    {
        $u1 = max(1e-12, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();

        return $mean + $sd * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /** generate.R's `rlnorm_mean`: lognormal whose arithmetic mean is the target. */
    private function lognormalWithMean(float $meanTarget, float $sdlog = self::SERVICE_SDLOG): float
    {
        $meanlog = log($meanTarget) - ($sdlog ** 2) / 2;

        return exp($this->normal($meanlog, $sdlog));
    }

    /** @param list<float> $weights */
    private function pickWeighted(array $weights): int
    {
        $total = array_sum($weights);
        $roll = $this->uniform(0, $total);
        foreach ($weights as $i => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $i;
            }
        }

        return count($weights) - 1;
    }

    /* ── reporting ────────────────────────────────────────────────────────── */

    private function report(): void
    {
        $anomalyEnd = $this->reviewCutoff->copy()->subWeek();

        $lines = [
            sprintf('businesses            %d (%d closed)', $this->counts['businesses'], $this->counts['closures']),
            sprintf('applications          %d (%d approved, %d rejected, %d in flight)',
                $this->counts['applications'], $this->counts['approved'],
                $this->counts['rejected'], $this->counts['in_flight']),
            sprintf('office assignments    %d (%d completed reviews, %d returned/resubmitted loops)',
                $this->counts['assignments'], $this->counts['completed_reviews'], $this->counts['returned_loops']),
            sprintf('permits issued        %d (%d lapsed to expired)',
                $this->counts['permits'], $this->counts['expired_permits'] ?? 0),
            sprintf('injected slowdown     %s, weeks of %s .. %s (x%.2f ramping to x%.2f)',
                self::ANOMALY_DEPARTMENT,
                $this->anomalyStart->toDateString(),
                $anomalyEnd->toDateString(),
                self::ANOMALY_MULTIPLIER_START,
                self::ANOMALY_MULTIPLIER_END),
            'remove everything     php artisan db:seed --class=AnalyticsHistoryPurgeSeeder',
        ];

        foreach ($lines as $line) {
            $this->command?->line('  '.$line);
        }
    }

    /* ── purge ────────────────────────────────────────────────────────────── */

    /**
     * Remove every row this seeder wrote, and nothing else.
     *
     * Reachability, not row counting: the two tags identify the accounts and
     * businesses, and everything else is found through them by foreign key.
     * Deletes run child-first so no constraint is ever left dangling, and every
     * `where … in` is bounded by a seeded id set — a real tester's application
     * can never appear in one.
     *
     * @return array<string, int> rows removed per table
     */
    public static function purge(): array
    {
        $userIds = User::withTrashed()
            ->where('email', 'like', '%@'.self::EMAIL_DOMAIN)
            ->pluck('id')->all();

        $businessIds = Business::withTrashed()
            ->where('registration_number', 'like', self::REGISTRATION_PREFIX.'%')
            ->when($userIds !== [], fn ($q) => $q->orWhereIn('owner_user_id', $userIds))
            ->pluck('id')->all();

        $applicationIds = $businessIds === [] ? [] : Application::withTrashed()
            ->whereIn('business_id', $businessIds)
            ->pluck('id')->all();

        // Applications filed by a seeded account against a business that is not
        // seeded should not exist, but cover it rather than orphan it.
        if ($userIds !== []) {
            $applicationIds = array_values(array_unique(array_merge(
                $applicationIds,
                Application::withTrashed()->whereIn('applicant_user_id', $userIds)->pluck('id')->all(),
            )));
        }

        $permitIds = $applicationIds === [] ? [] : DB::table('permits')
            ->whereIn('application_id', $applicationIds)->pluck('id')->all();
        $threadIds = $applicationIds === [] ? [] : DB::table('message_threads')
            ->whereIn('application_id', $applicationIds)->pluck('id')->all();
        $assignmentIds = $applicationIds === [] ? [] : DB::table('application_assignments')
            ->whereIn('application_id', $applicationIds)->pluck('id')->all();
        $requestIds = $applicationIds === [] ? [] : DB::table('officer_requests')
            ->whereIn('application_id', $applicationIds)->pluck('id')->all();

        $removed = [];
        $wipe = function (string $table, string $column, array $ids) use (&$removed): void {
            if ($ids === []) {
                return;
            }
            $count = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += DB::table($table)->whereIn($column, $chunk)->delete();
            }
            if ($count > 0) {
                $removed[$table] = ($removed[$table] ?? 0) + $count;
            }
        };

        // Deepest children first.
        $wipe('permit_expiry_notices', 'permit_id', $permitIds);
        $wipe('message_attachments', 'message_id', $threadIds === [] ? [] : DB::table('messages')
            ->whereIn('thread_id', $threadIds)->pluck('id')->all());
        $wipe('messages', 'thread_id', $threadIds);
        $wipe('message_threads', 'id', $threadIds);
        $wipe('officer_request_responses', 'officer_request_id', $requestIds);
        $wipe('officer_requests', 'id', $requestIds);
        $wipe('compliance_checks', 'application_assignment_id', $assignmentIds);
        $wipe('permits', 'id', $permitIds);
        $wipe('payments', 'application_id', $applicationIds);
        $wipe('fee_assessments', 'application_id', $applicationIds);
        $wipe('inspections', 'application_id', $applicationIds);
        $wipe('application_assignments', 'id', $assignmentIds);
        $wipe('application_status_history', 'application_id', $applicationIds);
        $wipe('application_documents', 'application_id', $applicationIds);
        $wipe('application_office_forms', 'application_id', $applicationIds);
        $wipe('application_permit_types', 'application_id', $applicationIds);

        // Audit rows point at the things above by class + key.
        foreach ([
            [Application::class, $applicationIds],
            [ApplicationAssignment::class, $assignmentIds],
            [Inspection::class, $applicationIds === [] ? [] : DB::table('inspections')
                ->whereIn('application_id', $applicationIds)->pluck('id')->all()],
            [FeeAssessment::class, $applicationIds === [] ? [] : DB::table('fee_assessments')
                ->whereIn('application_id', $applicationIds)->pluck('id')->all()],
        ] as [$type, $ids]) {
            if ($ids === []) {
                continue;
            }
            $count = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += DB::table('audit_logs')
                    ->where('auditable_type', $type)
                    ->whereIn('auditable_id', $chunk)->delete();
            }
            if ($count > 0) {
                $removed['audit_logs'] = ($removed['audit_logs'] ?? 0) + $count;
            }
        }

        $wipe('applications', 'id', $applicationIds);

        $wipe('business_lines', 'business_id', $businessIds);
        $wipe('business_addresses', 'business_id', $businessIds);
        $wipe('business_owners', 'business_id', $businessIds);
        $wipe('businesses', 'id', $businessIds);

        $wipe('audit_logs', 'user_id', $userIds);
        $wipe('app_notifications', 'user_id', $userIds);
        $wipe('chatbot_messages', 'conversation_id', $userIds === [] ? [] : DB::table('chatbot_conversations')
            ->whereIn('user_id', $userIds)->pluck('id')->all());
        $wipe('chatbot_conversations', 'user_id', $userIds);
        $wipe('officer_request_responses', 'user_id', $userIds);
        $wipe('personal_access_tokens', 'tokenable_id', $userIds);
        $wipe('sessions', 'user_id', $userIds);
        $wipe('user_roles', 'user_id', $userIds);
        $wipe('users', 'id', $userIds);

        return $removed;
    }
}

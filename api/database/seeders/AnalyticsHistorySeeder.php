<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\OfficerRequestStatus;
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
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\OfficerRequest;
use App\Models\OfficerRequestResponse;
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
 * WHAT IT FILLS IN THAT THE WORKFLOW WOULD NOT
 * --------------------------------------------
 * Five columns the paper reports on are real columns nothing had ever written
 * to, so the screens reading them showed honest empty states. They are filled
 * here from the register's own facts, never from a figure copied off the paper:
 *
 *   businesses.form_of_organization  allocated to the paper's shares, with
 *                                    registration_type set to the agency that
 *                                    registers that form (DTI / SEC / CDA)
 *   applications.prior_permit_id     every renewal and amendment points at the
 *                                    business permit it replaces, which is what
 *                                    makes Renewal Compliance computable. A
 *                                    renewal is late because its submitted_at
 *                                    really does fall after that permit expired
 *   applications.complexity          the highly-technical tier is a rule over
 *                                    line of business and declared capital, so
 *                                    RA 11032's 20-working-day limit has filings
 *                                    to be measured against
 *   inspections.inspection_type      written from the inspecting office, so the
 *                                    sanitary, fire and zoning visits, the
 *                                    failed ones, and the re-inspections that
 *                                    follow all carry their own type
 *   officer_requests / messages      requests with responses, meetings with
 *                                    attendance, and replies placed by walking
 *                                    forward through office hours so the
 *                                    latencies are working-hour gaps
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
    private const OFFICE_TURNAROUND_DAYS = [
        'BPLO' => 2.0, 'CHO' => 2.5, 'BFP' => 3.0, 'CPDO' => 2.2,
        // The three offices that had almost no seeded history at all — see
        // CLEARANCE_ATTACH_RATES below. Targets are their own rather than
        // copies: OBO signs off a certificate of occupancy against a building
        // record, which is the slowest desk read of the seven; CENRO checks an
        // environmental questionnaire against the declared line; and a market
        // clearance is a stall lookup, which is the quickest thing any of these
        // offices does. Nothing here is measured from the live register —
        // there was no history to measure — so they are ordered by how much
        // paper each decision actually involves.
        'OBO' => 2.8, 'CENRO' => 2.4, 'CMO-MARKET' => 1.8,
    ];

    /** Shape of the lognormal review duration (generate.R's sdlog). */
    private const SERVICE_SDLOG = 0.40;

    /** Share of reviews that suffer a returned -> resubmitted loop. */
    private const RETURN_LOOP_RATE = 0.10;

    /** Share of seeded businesses that close, spread across the window. */
    private const CLOSURE_RATE = 0.075;

    /** How many owner accounts the seeded businesses are shared between. */
    protected const OWNER_ACCOUNTS = 60;

    /** Reviewer headcount per office (r/config.R DEPARTMENTS$reviewers). */
    private const REVIEWERS = [
        'BPLO' => 3, 'CHO' => 2, 'BFP' => 2, 'CPDO' => 1,
        // One each. StaffingSimulation models office capacity from the count of
        // active officers per department, so these are not decoration: an office
        // carrying a hundred-odd reviews a year with nobody on its roll would
        // make that screen incoherent in the other direction.
        'OBO' => 1, 'CENRO' => 1, 'CMO-MARKET' => 1,
    ];

    /**
     * How often each supporting clearance is asked for, as [new, renewal].
     *
     * WHY THIS EXISTS AT ALL. Permit Processing Time Monitoring charted four of
     * the seven offices. Over the trailing 24 weeks the live register held BPLO
     * 273 completed reviews, CHO 215, BFP 209, CPDO 58 — and then OBO 3, CENRO
     * 4, CMO-MARKET 4. `Spc::MIN_COMPLETIONS_PER_WEEK` is 3, so a week needs
     * three finished reviews before it can be averaged at all; those three
     * offices could not produce a single chartable week and were demoted to the
     * payload's `thin` list. The client: "fill it asw".
     *
     * It is the same root cause as two other things that broke today, and worth
     * naming as one fact rather than three coincidences: OBO, CENRO, CPDO and
     * the Market Office had no `requires_inspection` on the permits they issue,
     * no `inspection.manage` on the roles that staff them, and next to no seeded
     * history behind them. They were second-class in the reference data, in the
     * permission matrix and in the demo register at once, because all three were
     * written from the three offices the manuscript names (BPLO / CHO / BFP)
     * plus zoning. This is the third of the three.
     *
     * WHY THESE NUMBERS. The trailing 52 weeks carry roughly 11 filings a week.
     * The rates below are chosen to put each of the three comfortably clear of
     * the minimum of 3 in most weeks while keeping them visibly the minor
     * offices they are — the chart should show BPLO/CHO/BFP as the busy desks
     * and these as the quiet ones, because that is true. Flat-rating them to
     * BPLO's volume would chart them and lie.
     *
     * The split by application type is the real-world reading, not a knob:
     *
     *  - OCCUPANCY (OBO) is about the premises. A new business fitting out a
     *    unit needs a certificate of occupancy; a renewal in the same unit
     *    mostly does not, so the renewal rate is a fraction of the new one.
     *  - CEC (CENRO) is an annual environmental compliance certificate, so it
     *    recurs on renewal nearly as often as it appears on a new filing.
     *  - MARKET is a stall clearance and applies to market-based businesses
     *    only, which is why it is the lowest of the three on both counts. It is
     *    still seeded high enough to chart, which is a deliberate trade: a
     *    truthful market-stall share of a general business register would leave
     *    the office un-chartable again and back in the footnote.
     *
     * Amendments carry none of these. An amendment changes a detail on a permit
     * already issued and is routed to BPLO alone, which is the behaviour the
     * existing `$codes` table already had.
     *
     * ZONING is in this table too, and only for renewals. It was never thin
     * enough to be dropped from the chart — 58 completed reviews against OBO's
     * 3 — but it was the only one of the four minor offices whose clearance is
     * asked for on new filings alone, and once the other three were seeded
     * properly it became the sparse office in their place: around ten chartable
     * weeks in twenty-four, against twenty-plus for the rest. Moving the
     * footnote from three offices to one is not a fix. A locational clearance is
     * re-validated when a business renews at a site the plan may have been
     * re-zoned around, so a renewal share is the honest way to lift it. The new
     * rate stays at 0.0 because the `$codes` table above already decides ZONING
     * for new filings (highly-technical always, 35% otherwise) and a second roll
     * would double-count it.
     *
     * @var array<string, array{float, float}>
     */
    private const CLEARANCE_ATTACH_RATES = [
        'OCCUPANCY' => [0.62, 0.30],
        'CEC' => [0.52, 0.40],
        'MARKET' => [0.44, 0.34],
        'ZONING' => [0.0, 0.34],
    ];

    /* ── register attributes the paper reports on ─────────────────────────── */

    /**
     * Form of organization mix.
     *
     * The paper's figure counts 2,318 sole proprietorships, 422 corporations and
     * 142 partnerships — 80.5% / 14.7% / 4.9% of the three it names. Those shares
     * are what is reproduced here, scaled to whatever number of businesses this
     * window produces, with a small cooperative tail because the register has a
     * column for it and Malabon does have cooperatives.
     *
     * @var array<string, float>
     */
    private const ORGANIZATION_MIX = [
        'sole_proprietorship' => 0.805,
        'corporation' => 0.146,
        'partnership' => 0.044,
        'cooperative' => 0.005,
    ];

    /**
     * Who registers each form, so `registration_type` and
     * `form_of_organization` cannot contradict each other.
     *
     * DTI registers sole proprietorships, the SEC registers corporations and
     * partnerships, and the CDA registers cooperatives. Before this the seeder
     * drew DTI/SEC on a 72/28 coin flip with nothing to tie it to, which would
     * now read as 28% of sole proprietorships having been registered with the
     * SEC.
     *
     * @var array<string, string>
     */
    private const REGISTRAR_BY_FORM = [
        'sole_proprietorship' => 'DTI',
        'corporation' => 'SEC',
        'partnership' => 'SEC',
        'cooperative' => 'CDA',
    ];

    /**
     * Share of renewal filings submitted on or before the prior permit expired.
     *
     * Lateness here is a real relationship between two dates, not a flag: a late
     * renewal is one whose `submitted_at` genuinely falls after the
     * `valid_until` of the permit its `prior_permit_id` points at. The rate is
     * reached by choosing WHICH business files on a given date (see
     * pickRenewalCandidate) — some businesses are current, some have let the
     * permit lapse — so no date is ever bent to produce it.
     *
     * 0.85 lands the paper's ~68% Renewal Compliance reading once the filings
     * that never arrive at all are counted in the denominator.
     */
    private const RENEWAL_ON_TIME_RATE = 0.85;

    /**
     * RA 11032 highly-technical tier.
     *
     * The law lets the LGU classify transactions that need technical evaluation
     * as highly technical and gives them twenty working days. The register's
     * checkable proxy for "needs technical evaluation" is the line of business
     * and the declared capital, so the rule is exactly that and nothing else:
     * a NEW filing for a manufacturing, construction or amusement line with at
     * least this much declared capital is highly technical. It is deterministic
     * — a panelist can be shown the two columns that decide it.
     */
    private const HIGH_TECH_CATEGORIES = [
        'manufacturer', 'essential_manufacturer', 'contractor', 'amusement_place',
    ];

    private const HIGH_TECH_CAPITAL_FLOOR = 1_000_000;

    /**
     * Days a highly-technical filing spends between the scheduled site visit and
     * the visit itself.
     *
     * This is where the tier's extra time sits, and it is a deliberate choice.
     * The alternative — slowing the office desk reviews — would inject outliers
     * into exactly the assignment durations the SPC control chart is fitted on,
     * and the gradual-slowdown signal that chart exists to show is the one thing
     * in this seeder that must not be contaminated. Technical evaluation and
     * site assessment is also where the time actually goes on a filing like
     * this, so the honest place to record it is the inspection stage.
     *
     * The result is a mean statutory turnaround in the mid-twenties of working
     * days against a twenty-day limit: the tier BREACHES, which is what the
     * paper reports and a more useful finding than a manufactured pass.
     */
    private const HIGH_TECH_EVALUATION_DAYS = [17.0, 30.0];

    /* ── inspections ──────────────────────────────────────────────────────── */

    /**
     * Outcome mix for a first site visit.
     *
     * Passed + conditional + failed = 1. A failed first visit is followed by a
     * re-inspection, so the pass rate the screen reports (passed ÷ completed,
     * counting the re-inspection as its own completed inspection) settles a
     * little under the first-visit pass rate — the 84-89% band the paper shows.
     */
    private const INSPECTION_FAIL_RATE = 0.05;

    private const INSPECTION_CONDITIONAL_RATE = 0.08;

    /** Share of failed first visits whose re-inspection also fails. */
    private const REINSPECTION_FAIL_RATE = 0.22;

    /** Days between a failed visit and the re-inspection. */
    private const REINSPECTION_GAP_DAYS = [10.0, 28.0];

    /**
     * Which inspection type each office records.
     *
     * `inspections.inspection_type` is a real column that nothing had ever
     * written to, so the dashboard was inferring the type from the inspecting
     * department. Writing it means the column and the department agree rather
     * than one standing in for the other.
     *
     * @var array<string, string>
     */
    private const INSPECTION_TYPE_BY_OFFICE = [
        'CHO' => 'sanitary',
        'BFP' => 'fire_safety',
        'CPDO' => 'zoning',
        'OBO' => 'building',
        'CENRO' => 'environmental',
        // The market visit had no entry because no seeded filing had ever been
        // routed to the Market Office. It has one now, and without this its
        // inspections would be the only ones on the register with a null type.
        'CMO-MARKET' => 'market',
    ];

    /* ── officer activity ─────────────────────────────────────────────────── */

    /**
     * Officer requests raised inside the trailing twelve months the Officer
     * Activity panel reports on.
     *
     * The paper shows 49 requests with 39 fulfilled (80%) and 18 meetings all
     * attended. Those are the targets: MEETINGS_WINDOW meeting requests, all
     * fulfilled and all with an applicant response against them, plus
     * REQUESTS_WINDOW document/message requests of which REQUESTS_FULFILLED are
     * fulfilled. 18 + 21 = 39 fulfilled out of 18 + 31 = 49.
     *
     * Earlier months get the same density, scaled by how many filings they hold,
     * so the register does not look as though officers only started answering
     * anyone twelve months ago.
     */
    private const OFFICER_REQUESTS_WINDOW = 31;

    private const OFFICER_REQUESTS_FULFILLED_WINDOW = 21;

    private const OFFICER_MEETINGS_WINDOW = 18;

    /**
     * Mean officer reply latency, in WORKING hours.
     *
     * The panel measures wall-clock hours from an applicant's message to the
     * next reply in that thread, so an afternoon question answered first thing
     * the next morning is a ~17-hour gap however promptly it was handled. Replies
     * are therefore placed by advancing through office hours (08:00-17:00,
     * weekdays) rather than by adding raw hours: the latencies that come out are
     * real office behaviour, and the handful that cross a night are what pulls
     * the reported average up towards the paper's 4.2 hours.
     */
    private const OFFICER_REPLY_WORKING_HOURS = 1.9;

    /** Share of applicant messages sent too late in the day to be answered that day. */
    private const OFFICER_LATE_DAY_SHARE = 0.07;

    private const OFFICE_DAY_START = 8;

    private const OFFICE_DAY_END = 17;

    /**
     * The window the Officer Activity panel reports on.
     *
     * Mirrors App\Support\DashboardAnalytics::DEFAULT_WINDOW_MONTHS. Kept as its
     * own number rather than imported because a seeder reaching into the
     * analytics layer for a constant would make the two impossible to change
     * independently; if that one moves, the counts here spread over a different
     * window and the report says so.
     */
    private const OFFICER_WINDOW_MONTHS = 12;

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

    /**
     * Moments where an office and an applicant had something to talk about.
     *
     * A returned assignment is the one point in the workflow where a real
     * applicant reliably picks up the phone, so that is where the seeded message
     * threads, requests for another requirement, and meetings hang off. One
     * entry per application, because `message_threads.application_id` is unique.
     *
     * @var array<int, array{application_id: int, applicant_id: int, department_id: int, officer_id: int, at: Carbon}>
     */
    private array $engagements = [];

    private array $counts = [
        'businesses' => 0, 'applications' => 0, 'assignments' => 0,
        'completed_reviews' => 0, 'permits' => 0, 'closures' => 0,
        'approved' => 0, 'rejected' => 0, 'in_flight' => 0, 'returned_loops' => 0,
        'highly_technical' => 0, 'renewals_linked' => 0, 'renewals_late' => 0,
        'inspections' => 0, 'reinspections' => 0, 'inspection_failures' => 0,
        'zoning_inspections' => 0, 'inspection_rejections' => 0,
        'threads' => 0, 'messages' => 0, 'officer_requests' => 0, 'meetings' => 0,
    ];

    /** @var array<string, int> businesses per form_of_organization */
    private array $organizationMix = [];

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

            $this->seedOfficerActivity();
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
        /*
         * All seven offices and all seven permit types, where this used to load
         * four of each.
         *
         * The four were the manuscript's three (BPLO / CHO / BFP) plus zoning,
         * and everything downstream inherited that boundary: no filing could be
         * routed to OBO, CENRO or the Market Office, so none of them could
         * accumulate the completed reviews Permit Processing Time Monitoring
         * needs to fit a control chart. See CLEARANCE_ATTACH_RATES for the
         * volumes and the reasoning behind them.
         *
         * The counts are asserted rather than assumed because a missing office
         * fails silently and far away: `reviewerFor()` returns null for an
         * office with no pool, and runReview then falls back to
         * `$app->applicant` — an APPLICANT approving an office's own assignment,
         * which is a plausible-looking history that is quietly wrong.
         */
        $officeCodes = ['BPLO', 'CHO', 'BFP', 'CPDO', 'OBO', 'CENRO', 'CMO-MARKET'];
        $permitCodes = ['BUSINESS', 'SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'CEC', 'MARKET'];

        $this->departments = Department::whereIn('code', $officeCodes)
            ->get()->keyBy('code')->all();
        $this->permitTypes = PermitType::whereIn('code', $permitCodes)
            ->get()->keyBy('code')->all();

        if (count($this->departments) < count($officeCodes) || count($this->permitTypes) < count($permitCodes)) {
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
            'OBO' => 'obo_staff',
            'CENRO' => 'cenro_officer',
            'CMO-MARKET' => 'market_admin',
        ];
        $names = [
            'BPLO' => [['Perlita', 'Sandoval'], ['Ignacio', 'Bermudez'], ['Sonia', 'Talusan']],
            'CHO' => [['Almira', 'Delgado'], ['Bonifacio', 'Yumul']],
            'BFP' => [['Rodel', 'Pineda'], ['Marissa', 'Concepcion']],
            'CPDO' => [['Herminia', 'Alcantara']],
            'OBO' => [['Teodoro', 'Mangahas']],
            'CENRO' => [['Rosalinda', 'Buenaventura']],
            'CMO-MARKET' => [['Efren', 'Salvacion']],
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
     *
     * `$preferOnTime` steers the Renewal Compliance indicator without touching a
     * single date. Eligible businesses fall into three groups by the state of the
     * business permit this filing would replace — still valid on `$at`, already
     * expired on `$at`, or none ever issued — and the caller says which group it
     * would rather draw from. A late renewal is then late because the business it
     * belongs to really did let its permit lapse before filing, which is what the
     * indicator is measuring. Preference, not restriction: if the preferred group
     * is empty on this date the whole eligible set is used, because forcing the
     * shape would mean skipping filings the calendar planned.
     */
    private function pickRenewalCandidate(Carbon $at, ?bool $preferOnTime = null): ?int
    {
        if ($this->register === []) {
            return null;
        }

        $today = $at->toDateString();
        $eligible = ['on_time' => [], 'late' => [], 'unpermitted' => []];
        $weights = ['on_time' => [], 'late' => [], 'unpermitted' => []];
        $rank = 0;
        foreach ($this->register as $id => $entry) {
            $rank++;
            if ($this->daysBetween($entry['registered_at'], $at) < 200) {
                continue;
            }
            if (isset($entry['renewed_years'][(int) $at->year])) {
                continue;
            }

            $permit = $entry['business_permit'] ?? null;
            $group = match (true) {
                $permit === null => 'unpermitted',
                $permit['valid_until'] >= $today => 'on_time',
                default => 'late',
            };

            $eligible[$group][] = $id;
            $weights[$group][] = 1 / ($rank ** 0.6);
        }

        $order = match ($preferOnTime) {
            true => ['on_time', 'late', 'unpermitted'],
            false => ['late', 'on_time', 'unpermitted'],
            default => ['on_time', 'late', 'unpermitted'],
        };

        foreach ($order as $group) {
            if ($eligible[$group] !== []) {
                return $eligible[$group][$this->pickWeighted($weights[$group])];
            }
        }

        return null;
    }

    /* ── writing ──────────────────────────────────────────────────────────── */

    private function writeFiling(Carbon $submittedAt): void
    {
        // Either a brand new registration (a "new" filing) or a business
        // already on the register coming back to renew or amend. January and
        // February are renewal season, so new registrations are rarer then.
        $renewalSeason = in_array((int) $submittedAt->month, [1, 2], true);
        // Decided before the business is chosen, because it is the choice of
        // business that makes a renewal on time or late.
        $wantOnTime = $this->chance(self::RENEWAL_ON_TIME_RATE);
        $candidate = $this->pickRenewalCandidate($submittedAt, $wantOnTime);

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

        // RA 11032 classification, decided before the permit route because the
        // highly-technical tier is the one that goes the full four-office route.
        $tier = $this->complexityFor($business, $type);

        // Which permits are being asked for. New filings go the full route;
        // renewals mostly re-validate health and fire too (both certificates
        // are annual), a minority are a BPLO re-validation only, and amendments
        // touch the business permit alone.
        $codes = match (true) {
            $tier === 'highly_technical' => ['BUSINESS', 'SANITARY', 'FSIC', 'ZONING'],
            $type === ApplicationType::New => $this->chance(0.35)
                ? ['BUSINESS', 'SANITARY', 'FSIC', 'ZONING']
                : ['BUSINESS', 'SANITARY', 'FSIC'],
            $type === ApplicationType::Renewal => $this->chance(0.72)
                ? ['BUSINESS', 'SANITARY', 'FSIC']
                : ['BUSINESS'],
            default => ['BUSINESS'],
        };
        // array_unique because ZONING can be reached from either the table above
        // (new filings) or the attach rates (renewals), and a duplicate code
        // would be synced twice into application_permit_types.
        $codes = array_values(array_unique([...$codes, ...$this->supportingClearancesFor($type)]));
        $requested = array_map(fn (string $c) => $this->permitTypes[$c], $codes);

        // The permit this filing replaces. Real column, real link: it is what
        // the Renewal Compliance indicator counts, and what an applicant picks
        // by hand on a live renewal (see PriorPermitController). Null when the
        // business has never been issued one — the honest answer for a business
        // whose earlier permits predate the system.
        $priorPermit = $type === ApplicationType::New
            ? null
            : ($this->register[$business->id]['business_permit'] ?? null);

        // ── draft ──────────────────────────────────────────────────────────
        $draftedAt = $submittedAt->copy()->subHours(mt_rand(1, 30));
        $this->travelTo($draftedAt);
        Auth::setUser($owner);

        $app = Application::create([
            'business_id' => $business->id,
            'applicant_user_id' => $owner->id,
            'application_type' => $type,
            'status' => ApplicationStatus::Draft,
            'prior_permit_id' => $priorPermit['id'] ?? null,
            'fee_profile' => $this->feeProfile($business, $type),
            'payment_mode' => $this->chance(0.7) ? 'annual' : 'quarterly',
        ]);
        $app->permitTypes()->sync(collect($requested)->pluck('id'));
        $app->forceFill(['complexity' => $tier])->save();

        if ($tier === 'highly_technical') {
            $this->counts['highly_technical']++;
        }
        if ($type === ApplicationType::Renewal && $priorPermit !== null) {
            $this->counts['renewals_linked']++;
            if ($priorPermit['valid_until'] < $submittedAt->toDateString()) {
                $this->counts['renewals_late']++;
            }
        }

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

        /*
         * BPLO confirms the processing category as the filing reaches the
         * offices, because that is when an office first has the file in front
         * of it — and because nothing downstream can be approved until somebody
         * has. `submit()` seeds a tier from Ra11032::tierFor(), but that is our
         * guess and requireProcessingCategory() refuses to treat a guess as a
         * decision; a seeded history of approved filings therefore has to
         * include the moment a person put their name to the classification, or
         * it is a history of something the product cannot produce.
         *
         * Filings that never got past `pending_payment` return above this line
         * and stay marked `automatic`, which is the truthful state for a filing
         * no office has opened. So the register keeps rows on both sides of the
         * gate rather than becoming uniformly categorised.
         *
         * `$tier` rather than a fresh draw: it is this seeder's stand-in for the
         * LGU's published classification (see complexityFor()), it agrees with
         * what submit() computed, and re-affirming it moves no deadline. The
         * officer is fixed rather than sampled so this adds no draw to the
         * shared mt_rand stream, which every later number depends on.
         */
        $this->travelTo($paidAt);
        Auth::setUser($this->reviewers['BPLO'][0]);
        $this->workflow->classify($app->fresh(), $tier, $this->reviewers['BPLO'][0]);
        $app->refresh();

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

        /*
         * ── inspections ────────────────────────────────────────────────────
         *
         * Every row here was booked by WorkflowService::scheduleInspections on
         * the last office sign-off, one per inspecting office, two working days
         * out. Nothing is added by hand.
         *
         * There used to be an addZoningInspection() at this point, writing the
         * CPDO visit itself because `permit_types.ZONING.requires_inspection`
         * was false and the workflow therefore never scheduled one — the
         * Inspections panel's third type was structurally empty and this seeder
         * filled it in for seeded filings only. All six supporting clearances
         * are inspected now (see ReferenceSeeder), so the workflow books the
         * zoning visit like any other and that compensation is gone. It could
         * not be left in place harmlessly either: it consumed a reviewerFor()
         * draw per zoning filing, and mt_rand is a single seeded stream shared
         * by the whole run, so a dead call still moves every number after it.
         */
        $app->refresh();

        $highlyTechnical = $tier === 'highly_technical';
        $visits = [];
        foreach ($app->inspections()->with('department')->get() as $inspection) {
            if ($inspection->department->code === 'CPDO') {
                $this->counts['zoning_inspections']++;
            }
            $inspector = $this->reviewerFor($inspection->department->code);
            if ($inspector) {
                // The workflow picks the least-loaded active inspector, which
                // would land on a real tester's account. Reattribute to the
                // seeded reviewer for the same office; nothing else changes.
                $inspection->forceFill(['inspector_user_id' => $inspector->id])->save();
            }
            $this->tagInspectionType($inspection);

            // A highly-technical filing waits on technical evaluation before the
            // site assessment happens; everything else is visited within days.
            $gap = $highlyTechnical
                ? $this->uniform(...self::HIGH_TECH_EVALUATION_DAYS)
                : $this->uniform(0.5, 2.5);

            $visits[] = [
                'inspection' => $inspection,
                'inspector' => $inspector,
                'conducted_at' => $inspection->scheduled_at->copy()->addSeconds((int) round($gap * 86400)),
            ];
        }
        // Conduct them in date order: the last visit is what triggers issuance,
        // so out-of-order visits would date the decision before an inspection.
        usort($visits, fn (array $a, array $b) => $a['conducted_at'] <=> $b['conducted_at']);

        $pendingInspection = false;
        $failed = [];
        $lastVisitAt = null;
        foreach ($visits as $visit) {
            if ($visit['conducted_at']->greaterThan($this->anchor)) {
                $pendingInspection = true;

                continue;
            }

            $result = $this->inspectionOutcome();
            $this->recordVisit($visit['inspection'], $visit['inspector'], $visit['conducted_at'], $result);
            $lastVisitAt = $visit['conducted_at'];

            if ($result === InspectionResult::Failed) {
                $failed[] = $visit;
            }
        }

        if ($pendingInspection) {
            $this->counts['in_flight']++;

            return;
        }

        // ── re-inspections ─────────────────────────────────────────────────
        // A failed visit does not end the filing: the department schedules
        // another one. That second visit is its own inspection row, which is why
        // the reported pass rate (passed / completed) sits a little below the
        // first-visit pass rate.
        $unresolved = false;
        foreach ($failed as $visit) {
            $reinspectedAt = $visit['conducted_at']->copy()
                ->addSeconds((int) round($this->uniform(...self::REINSPECTION_GAP_DAYS) * 86400));
            if ($reinspectedAt->greaterThan($this->anchor)) {
                $pendingInspection = true;

                break;
            }

            $result = $this->chance(self::REINSPECTION_FAIL_RATE)
                ? InspectionResult::Failed
                : ($this->chance(0.85) ? InspectionResult::Passed : InspectionResult::Conditional);

            $reinspection = Inspection::create([
                'application_id' => $app->id,
                'department_id' => $visit['inspection']->department_id,
                'inspector_user_id' => $visit['inspector']?->id,
                'status' => InspectionStatus::Scheduled,
                'scheduled_at' => $reinspectedAt->copy()->subDays(2),
            ]);
            $this->tagInspectionType($reinspection->setRelation('department', $visit['inspection']->department));
            $this->recordVisit($reinspection, $visit['inspector'], $reinspectedAt, $result, true);
            $this->counts['reinspections']++;

            $lastVisitAt = $reinspectedAt;
            if ($result === InspectionResult::Failed) {
                $unresolved = true;
            }
        }

        if ($pendingInspection) {
            $this->counts['in_flight']++;

            return;
        }

        // A deficiency that survived the re-inspection ends the filing. This is
        // the one rejection in the seeder with physical evidence behind it, and
        // the reason the rejection reasons include re-inspection failure.
        if ($unresolved) {
            $decidedAt = ($lastVisitAt ?? $paidAt)->copy()->addSeconds((int) $this->uniform(4 * 3600, 60 * 3600));
            if ($decidedAt->greaterThan($this->anchor)) {
                $decidedAt = $this->anchor->copy();
            }
            $this->travelTo($decidedAt);
            Auth::setUser($this->reviewers['BPLO'][0]);
            $this->workflow->rejectApplication(
                $app->fresh(),
                'Sanitary deficiencies were not corrected on re-inspection.'.self::LABEL,
            );
            $this->counts['rejected']++;
            $this->counts['inspection_rejections']++;

            return;
        }

        // A failed visit stays on the file forever, so recordInspection's
        // "every inspection passed" test can never come true again and the
        // workflow will not issue on its own. The re-inspection that cleared it
        // is the decision, so issuance is asked for explicitly, at that instant,
        // through the same public method the workflow uses itself.
        $app->refresh();
        if ($failed !== [] && $app->status === ApplicationStatus::ForInspection) {
            $this->travelTo($lastVisitAt ?? $paidAt);
            Auth::setUser($this->reviewers['BPLO'][0]);
            $this->workflow->approveAndIssue($app->fresh());
            $app->refresh();
        }

        // No inspection-bearing permit type: the workflow already approved and
        // issued on the last review. Either way the application is decided.
        if ($app->status === ApplicationStatus::Approved) {
            $this->counts['approved']++;
            $this->counts['permits'] += $app->permits()->count();
            $this->rememberBusinessPermit($app);
        } else {
            $this->counts['in_flight']++;
        }
    }

    /**
     * Write `inspections.inspection_type` from the inspecting office.
     *
     * Not a derived label standing in for a missing column: the column is filled
     * in, so the dashboard can read it directly and the two agree.
     */
    private function tagInspectionType(Inspection $inspection): void
    {
        $code = $inspection->department->code ?? null;
        $type = self::INSPECTION_TYPE_BY_OFFICE[$code] ?? null;
        if ($type === null) {
            return;
        }

        $inspection->forceFill(['inspection_type' => $type])->save();
    }

    /** passed / conditional / failed, in the mix the paper's pass rates imply. */
    private function inspectionOutcome(): InspectionResult
    {
        $roll = mt_rand() / mt_getrandmax();

        return match (true) {
            $roll < self::INSPECTION_FAIL_RATE => InspectionResult::Failed,
            $roll < self::INSPECTION_FAIL_RATE + self::INSPECTION_CONDITIONAL_RATE => InspectionResult::Conditional,
            default => InspectionResult::Passed,
        };
    }

    private function recordVisit(
        Inspection $inspection,
        ?User $inspector,
        Carbon $at,
        InspectionResult $result,
        bool $isReinspection = false,
    ): void {
        $this->travelTo($at);
        Auth::setUser($inspector ?? $this->reviewers['BPLO'][0]);
        $this->workflow->recordInspection($inspection, $result, $this->inspectionFindings($result, $isReinspection));
        $this->counts['inspections']++;
        if ($result === InspectionResult::Failed) {
            $this->counts['inspection_failures']++;
        }
    }

    private function inspectionFindings(InspectionResult $result, bool $isReinspection): string
    {
        $text = match ($result) {
            InspectionResult::Passed => $isReinspection
                ? 'Re-inspection conducted. Earlier findings have been corrected.'
                : 'Premises inspected. Compliant with the applicable requirements.',
            InspectionResult::Conditional => 'Compliant subject to correction of minor findings within 30 days.',
            InspectionResult::Failed => $isReinspection
                ? 'Re-inspection conducted. The findings raised on the first visit remain uncorrected.'
                : [
                    'Fire exit obstructed and no serviceable extinguisher on the premises.',
                    'Food handlers without current health certificates; storage area not vermin-proofed.',
                    'Occupied floor area exceeds what the locational clearance covers.',
                    'No potable water supply and no grease trap on the wash line.',
                ][mt_rand(0, 3)],
        };

        return $text.self::LABEL;
    }

    /**
     * Remember the business permit this business now holds, so the renewal that
     * replaces it can point at it.
     */
    private function rememberBusinessPermit(Application $app): void
    {
        $permit = $app->permits()
            ->where('permit_type_id', $this->permitTypes['BUSINESS']->id)
            ->latest('id')->first(['id', 'valid_until']);

        if ($permit === null || ! isset($this->register[$app->business_id])) {
            return;
        }

        $this->register[$app->business_id]['business_permit'] = [
            'id' => (int) $permit->id,
            'valid_until' => Carbon::parse($permit->valid_until)->toDateString(),
        ];
    }

    /**
     * RA 11032 tier for a filing.
     *
     * Renewals and amendments are simple transactions. A new registration is
     * complex — several offices have to clear it — unless its line of business
     * and declared capital put it in the tier the law reserves for filings that
     * need technical evaluation, which is a rule read off two real columns rather
     * than a coin flip.
     */
    private function complexityFor(Business $business, ApplicationType $type): string
    {
        if ($type !== ApplicationType::New) {
            return 'simple';
        }

        $business->loadMissing('lines.psicCode');
        $category = $this->lineMetaFor($business)['category'];
        $capital = (float) ($business->lines->first()?->capitalization ?? 0);

        return in_array($category, self::HIGH_TECH_CATEGORIES, true)
            && $capital >= self::HIGH_TECH_CAPITAL_FLOOR
                ? 'highly_technical'
                : 'complex';
    }

    /**
     * Which of OBO's, CENRO's and the Market Office's clearances this filing
     * asks for.
     *
     * Three independent rolls rather than one bundled choice, because they are
     * three independent facts about a business: whether it is fitting out
     * premises, whether its line has an environmental questionnaire against it,
     * and whether it trades from a market stall. Bundling them would produce
     * filings that carry all three or none, and the three offices' weekly
     * volumes would then move in lockstep — which would show up on the control
     * charts as three offices with suspiciously identical shapes.
     *
     * The rates and the reasoning behind them are on CLEARANCE_ATTACH_RATES.
     * `chance()` is the same generator the four original offices are driven by,
     * drawing from the same seeded `mt_srand` stream, so these filings are
     * built by the existing machinery rather than a second one bolted alongside.
     *
     * @return list<string>
     */
    private function supportingClearancesFor(ApplicationType $type): array
    {
        // An amendment changes a detail on a permit already issued; it is BPLO's
        // alone and does not re-open any office's clearance.
        if ($type === ApplicationType::Amendment) {
            return [];
        }

        $isNew = $type === ApplicationType::New;
        $codes = [];
        foreach (self::CLEARANCE_ATTACH_RATES as $code => [$newRate, $renewalRate]) {
            if ($this->chance($isNew ? $newRate : $renewalRate)) {
                $codes[] = $code;
            }
        }

        return $codes;
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

            // A returned filing is the moment an applicant has a question, so it
            // is where the seeded conversation and any request for a further
            // requirement hangs off. One per application: message_threads is
            // unique on application_id, and a second entry would be dropped.
            $this->engagements[$app->id] ??= [
                'application_id' => $app->id,
                'applicant_id' => $app->applicant_user_id,
                'department_id' => $assignment->department_id,
                'officer_id' => ($officer ?? $this->reviewers['BPLO'][0])->id,
                'at' => $returnedAt->copy(),
            ];

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
        $form = $this->nextOrganizationForm();

        $business = Business::create([
            'owner_user_id' => $owner->id,
            'name' => $this->businessName($line, $owner, $sequence),
            'trade_name' => null,
            'registration_type' => self::REGISTRAR_BY_FORM[$form],
            // Tag #2: independent of the account, so seeded businesses stay
            // identifiable even if an account is renamed or reassigned.
            'registration_number' => sprintf('%s%06d', self::REGISTRATION_PREFIX, $sequence),
            'tin' => sprintf('%03d-%03d-%03d-000', mt_rand(100, 999), mt_rand(100, 999), mt_rand(100, 999)),
            'ban' => Numbering::ban(),
            'status' => 'active',
        ]);
        // Not in Business::$fillable — it is a BPLO form field the API writes
        // through its own request object, so the seeder writes it directly.
        $business->forceFill(['form_of_organization' => $form])->save();
        $this->organizationMix[$form] = ($this->organizationMix[$form] ?? 0) + 1;

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
     * The next form of organization to register, kept on the paper's shares.
     *
     * Allocated rather than drawn. A weighted draw over seven hundred businesses
     * still misses its target shares by two or three points, and this figure is
     * one a panelist reads straight off the screen and compares with the paper —
     * so each new business takes whichever form is furthest behind its share.
     * The mix is then exact at any register size, and deterministic.
     */
    private function nextOrganizationForm(): string
    {
        $placed = array_sum($this->organizationMix) + 1;

        $pick = array_key_first(self::ORGANIZATION_MIX);
        $worst = -INF;
        foreach (self::ORGANIZATION_MIX as $form => $share) {
            $deficit = $share * $placed - ($this->organizationMix[$form] ?? 0);
            if ($deficit > $worst) {
                $worst = $deficit;
                $pick = $form;
            }
        }

        return $pick;
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

    /* ── officer activity ─────────────────────────────────────────────────── */

    /**
     * The conversations, requests and meetings the Officer Activity panel counts.
     *
     * All of it hangs off applications this seeder wrote, which is what keeps the
     * purge complete: message_threads, officer_requests and their responses are
     * all reached through `application_id`, so nothing new had to be added to
     * AnalyticsHistorySeeder::purge().
     *
     * Requests are placed to hit an exact count inside the panel's twelve-month
     * window, and the same density is applied to the months before it — an
     * officer-response record that starts abruptly twelve months ago would be an
     * artefact of the reporting window rather than a register.
     */
    private function seedOfficerActivity(): void
    {
        if ($this->engagements === []) {
            return;
        }

        $engagements = array_values($this->engagements);
        usort($engagements, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        foreach ($engagements as $engagement) {
            $this->writeThread($engagement);
        }

        $windowStart = $this->anchor->copy()->startOfDay()->subMonths(self::OFFICER_WINDOW_MONTHS);
        $inWindow = array_values(array_filter(
            $engagements,
            fn (array $e) => $e['at']->greaterThanOrEqualTo($windowStart),
        ));
        $earlier = array_values(array_filter(
            $engagements,
            fn (array $e) => $e['at']->lessThan($windowStart),
        ));

        $this->writeOfficerRequests(
            $inWindow,
            self::OFFICER_REQUESTS_WINDOW,
            self::OFFICER_MEETINGS_WINDOW,
            self::OFFICER_REQUESTS_FULFILLED_WINDOW,
        );

        $scale = $inWindow === [] ? 0.0 : count($earlier) / count($inWindow);
        $this->writeOfficerRequests(
            $earlier,
            (int) round(self::OFFICER_REQUESTS_WINDOW * $scale),
            (int) round(self::OFFICER_MEETINGS_WINDOW * $scale),
            (int) round(self::OFFICER_REQUESTS_FULFILLED_WINDOW * $scale),
        );
    }

    /**
     * One applicant/officer conversation on a returned filing.
     *
     * Each exchange is an applicant question and the office's answer. The answer
     * is placed by walking forward through office hours, so the wall-clock gap
     * the panel measures is whatever that walk produces — a couple of hours for
     * a morning question, most of a day for one sent at half past four.
     */
    private function writeThread(array $engagement): void
    {
        $openedAt = $this->askMoment($engagement['at']);
        if ($openedAt->greaterThan($this->anchor)) {
            return;
        }

        $this->travelTo($openedAt);
        $thread = MessageThread::create(['application_id' => $engagement['application_id']]);
        $this->counts['threads']++;

        $askedAt = $openedAt;
        $exchanges = mt_rand(1, 3);
        for ($i = 0; $i < $exchanges; $i++) {
            if ($askedAt->greaterThan($this->anchor)) {
                return;
            }

            $this->travelTo($askedAt);
            Message::create([
                'thread_id' => $thread->id,
                'sender_user_id' => $engagement['applicant_id'],
                'body' => $this->applicantQuestion($i).self::LABEL,
            ]);
            $this->counts['messages']++;

            $repliedAt = $this->advanceWorkingHours(
                $askedAt,
                $this->lognormalWithMean(self::OFFICER_REPLY_WORKING_HOURS, 0.7),
            );
            if ($repliedAt->greaterThan($this->anchor)) {
                return;
            }

            $this->travelTo($repliedAt);
            Message::create([
                'thread_id' => $thread->id,
                'sender_user_id' => $engagement['officer_id'],
                'body' => $this->officerReply($i).self::LABEL,
            ]);
            $this->counts['messages']++;

            $askedAt = $this->askMoment($repliedAt->copy()->addSeconds((int) $this->uniform(20 * 3600, 5 * 86400)));
        }
    }

    /**
     * Place `$documents` document/message requests and `$meetings` meeting
     * requests across `$pool`, of which `$fulfilled` of the non-meeting ones are
     * fulfilled.
     *
     * Which engagements get one is chosen by an even stride through the pool so
     * the requests spread across the whole period rather than bunching wherever
     * the random draw happened to land.
     *
     * @param  list<array{application_id: int, applicant_id: int, department_id: int, officer_id: int, at: Carbon}>  $pool
     */
    private function writeOfficerRequests(array $pool, int $documents, int $meetings, int $fulfilled): void
    {
        $total = $documents + $meetings;
        if ($pool === [] || $total <= 0) {
            return;
        }

        $picked = [];
        $stride = count($pool) / min($total, count($pool));
        for ($i = 0; $i < min($total, count($pool)); $i++) {
            $picked[] = $pool[(int) floor($i * $stride)];
        }

        // Meetings all end fulfilled — a meeting that happened and was answered
        // is the only kind this register can evidence. The rest split into
        // fulfilled, answered-but-not-yet-reviewed, and still outstanding.
        $outstanding = max(0, $documents - $fulfilled);
        $submitted = (int) ceil($outstanding * 0.6);
        $plan = array_merge(
            array_fill(0, $meetings, ['meeting', OfficerRequestStatus::Fulfilled]),
            array_fill(0, min($fulfilled, $documents), ['document', OfficerRequestStatus::Fulfilled]),
            array_fill(0, $submitted, ['document', OfficerRequestStatus::Submitted]),
            array_fill(0, max(0, $outstanding - $submitted), ['document', OfficerRequestStatus::Pending]),
        );
        shuffle($plan);

        foreach ($picked as $i => $engagement) {
            if (! isset($plan[$i])) {
                break;
            }
            [$kind, $status] = $plan[$i];
            $this->writeOfficerRequest($engagement, $kind, $status);
        }
    }

    private function writeOfficerRequest(array $engagement, string $kind, OfficerRequestStatus $status): void
    {
        $raisedAt = $this->nextOfficeMoment(
            $engagement['at']->copy()->addSeconds((int) $this->uniform(2 * 3600, 2 * 86400))
        );
        if ($raisedAt->greaterThan($this->anchor)) {
            return;
        }

        $meetingAt = null;
        if ($kind === 'meeting') {
            $meetingAt = $this->nextOfficeMoment($raisedAt->copy()->addWeekdays(mt_rand(2, 5)))
                ->startOfHour()->addHours(mt_rand(1, 6));
            if ($meetingAt->greaterThan($this->anchor)) {
                // The meeting has not happened yet, so there is nothing to count
                // as attended. Raise it as a document request instead of writing
                // a meeting the register cannot evidence.
                $kind = 'document';
                $meetingAt = null;
            }
        }

        $this->travelTo($raisedAt);
        [$title, $description] = $this->officerRequestText($kind, $meetingAt);
        $request = OfficerRequest::create([
            'application_id' => $engagement['application_id'],
            'requested_by_user_id' => $engagement['officer_id'],
            'department_id' => $engagement['department_id'],
            'title' => $title.self::LABEL,
            'description' => $description,
            'request_type' => $kind,
            'status' => OfficerRequestStatus::Pending,
            'due_date' => $raisedAt->copy()->addWeekdays(5),
            'meeting_scheduled_at' => $meetingAt,
            'meeting_duration_minutes' => $kind === 'meeting' ? [30, 45, 60][mt_rand(0, 2)] : 30,
            'meeting_link' => $kind === 'meeting'
                ? 'https://meet.google.com/'.substr(md5((string) $engagement['application_id']), 0, 3)
                    .'-'.substr(md5((string) $engagement['officer_id']), 0, 4)
                    .'-'.substr(md5((string) $engagement['applicant_id']), 0, 3)
                : null,
        ]);
        $this->counts['officer_requests']++;
        if ($meetingAt !== null) {
            $this->counts['meetings']++;
        }

        if ($status === OfficerRequestStatus::Pending) {
            return;
        }

        // The applicant answers — after the meeting, if there was one.
        $answeredAt = $meetingAt !== null
            ? $this->advanceWorkingHours($meetingAt->copy()->addMinutes(45), $this->uniform(0.5, 6.0))
            : $this->advanceWorkingHours($raisedAt, $this->uniform(4, 3 * 9));
        if ($answeredAt->greaterThan($this->anchor)) {
            return;
        }

        $body = $meetingAt === null
            ? 'Attaching the document requested. Please let us know if anything else is needed.'
            : 'Attended the scheduled meeting. Noting the agreed corrections for our records.';

        $this->travelTo($answeredAt);
        OfficerRequestResponse::create([
            'officer_request_id' => $request->id,
            'user_id' => $engagement['applicant_id'],
            'body' => $body.self::LABEL,
        ]);
        $request->update([
            'status' => OfficerRequestStatus::Submitted,
            'applicant_response' => $body.self::LABEL,
            'submitted_at' => $answeredAt,
        ]);

        if ($status !== OfficerRequestStatus::Fulfilled) {
            return;
        }

        $reviewedAt = $this->advanceWorkingHours($answeredAt, $this->uniform(1, 2 * 9));
        if ($reviewedAt->greaterThan($this->anchor)) {
            return;
        }

        $this->travelTo($reviewedAt);
        $request->update([
            'status' => OfficerRequestStatus::Fulfilled,
            'reviewed_by_user_id' => $engagement['officer_id'],
            'reviewed_at' => $reviewedAt,
            'remarks' => 'Accepted. Requirement complete.'.self::LABEL,
        ]);
    }

    /** @return array{string, string} */
    private function officerRequestText(string $kind, ?Carbon $meetingAt): array
    {
        if ($kind === 'meeting') {
            return [
                'Meeting: walkthrough of the outstanding findings',
                'Scheduled for '.($meetingAt?->format('j M Y, g:i a') ?? 'a date to be confirmed')
                    .'. Please bring the original documents so they can be sighted.',
            ];
        }

        $requests = [
            ['Certified true copy of the lease contract', 'The uploaded copy is unsigned on the lessor page.'],
            ['Latest community tax certificate', 'Not attached to the filing.'],
            ['Sketch plan with the declared floor area', 'The declared area does not match the plan on file.'],
            ['Barangay clearance, clearer scan', 'The uploaded scan is not legible.'],
            ['Occupancy permit for the unit', 'Required for the line of business declared.'],
            ['Fire safety maintenance certificate', 'Needed before the FSIC can be endorsed.'],
        ];
        $pick = $requests[mt_rand(0, count($requests) - 1)];

        return ['Additional requirement: '.$pick[0], $pick[1]];
    }

    private function applicantQuestion(int $index): string
    {
        $questions = [
            'Good day. We received a notice that our filing was returned — may we know exactly which document needs correcting?',
            'We have re-uploaded the corrected file. Is anything else outstanding on our side?',
            'May we follow up on the status? We would like to know if we should expect an inspection this week.',
            'Would it be possible to submit the missing page in person instead of online?',
        ];

        return $questions[$index % count($questions)];
    }

    private function officerReply(int $index): string
    {
        $replies = [
            'Good day. The barangay clearance on file is not legible — please re-upload a clearer copy and the review will resume.',
            'Received, thank you. Nothing further is outstanding; the file is back with the reviewing office.',
            'The review has been completed and the inspection is scheduled. The inspector will call ahead.',
            'Yes, you may submit it over the counter. Bring the original and we will sight it and attach the scan for you.',
        ];

        return $replies[$index % count($replies)];
    }

    /**
     * A plausible moment for an applicant to write, at or after `$at`.
     *
     * Weighted towards the morning, because that is when a question still gets
     * answered the same day. The minority sent late in the afternoon are the
     * ones whose answer lands the next working day, and they are what makes the
     * reported average response time longer than the office's actual handling
     * time — a distinction the panel's figure cannot make, so the register at
     * least has to make it truthfully.
     */
    private function askMoment(Carbon $at): Carbon
    {
        $hour = $this->chance(self::OFFICER_LATE_DAY_SHARE)
            ? $this->uniform(15.5, 16.9)
            : $this->uniform(self::OFFICE_DAY_START, 13.5);

        $day = $at->copy();
        for ($guard = 0; $guard < 10; $guard++) {
            if (! $day->isWeekend()) {
                $moment = $day->copy()->startOfDay()->addSeconds((int) round($hour * 3600));
                if ($moment->greaterThanOrEqualTo($at)) {
                    return $moment;
                }
            }
            $day->addDay();
        }

        return $day;
    }

    /** Snap a moment forward into the next stretch of office hours. */
    private function nextOfficeMoment(Carbon $at): Carbon
    {
        return $this->advanceWorkingHours($at, 0.0);
    }

    /**
     * `$hours` of office time after `$from`, in wall-clock terms.
     *
     * Office hours are OFFICE_DAY_START..OFFICE_DAY_END on weekdays. Two hours
     * of work starting at 16:00 finishes at 09:00 the next morning, which is a
     * seventeen-hour wall-clock gap and a two-hour wait — the seeded latencies
     * are wall-clock because that is what the panel measures.
     */
    private function advanceWorkingHours(Carbon $from, float $hours): Carbon
    {
        $cursor = $from->copy();
        $remaining = max(0.0, $hours) * 3600;

        for ($guard = 0; $guard < 400; $guard++) {
            if ($cursor->isWeekend()) {
                $cursor = $cursor->copy()->addDay()->startOfDay()->addHours(self::OFFICE_DAY_START);

                continue;
            }

            $dayStart = $cursor->copy()->startOfDay()->addHours(self::OFFICE_DAY_START);
            $dayEnd = $cursor->copy()->startOfDay()->addHours(self::OFFICE_DAY_END);

            if ($cursor->lessThan($dayStart)) {
                $cursor = $dayStart;
            }
            if ($cursor->greaterThanOrEqualTo($dayEnd)) {
                $cursor = $cursor->copy()->addDay()->startOfDay()->addHours(self::OFFICE_DAY_START);

                continue;
            }

            $available = $dayEnd->getTimestamp() - $cursor->getTimestamp();
            if ($remaining <= $available) {
                return $cursor->copy()->addSeconds((int) round($remaining));
            }

            $remaining -= $available;
            $cursor = $cursor->copy()->addDay()->startOfDay()->addHours(self::OFFICE_DAY_START);
        }

        return $cursor;
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
            sprintf('RA 11032 tiers        %d highly technical, the rest complex (new) or simple (renewal/amendment)',
                $this->counts['highly_technical']),
            sprintf('form of organization  %s',
                implode(', ', array_map(
                    fn (string $form, int $n): string => sprintf('%s %d', $form, $n),
                    array_keys($this->organizationMix),
                    array_values($this->organizationMix),
                ))),
            sprintf('renewals linked       %d to the permit they replace (%d filed after it expired)',
                $this->counts['renewals_linked'], $this->counts['renewals_late']),
            sprintf('inspections recorded  %d (%d zoning, %d re-inspections, %d failures, %d ended in rejection)',
                $this->counts['inspections'], $this->counts['zoning_inspections'],
                $this->counts['reinspections'], $this->counts['inspection_failures'],
                $this->counts['inspection_rejections']),
            sprintf('officer activity      %d threads / %d messages, %d requests, %d meetings',
                $this->counts['threads'], $this->counts['messages'],
                $this->counts['officer_requests'], $this->counts['meetings']),
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

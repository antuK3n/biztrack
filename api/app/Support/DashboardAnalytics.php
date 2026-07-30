<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PermitStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Analytics Dashboard (docs/r-integration-spec.md §1), computed from the
 * register.
 *
 * Same split as every other analytics feature here: `dataset()` gathers facts in
 * bulk queries and is the payload `analytics:refresh` pushes to R; `compute()` is
 * the PHP engine that turns those facts into the screen's statistics and doubles
 * as the fallback when R is unreachable. R's `POST /dashboard` returns the same
 * schema from the same facts, and AnalyticsParityTest asserts they agree.
 *
 * WINDOWS, BECAUSE EACH PANEL ANSWERS A DIFFERENT QUESTION
 *
 * The mockup mixes three time bases and the numbers only reconcile if you honour
 * that. Its "This Month" KPI is 187 and its Application Volume total is also 187,
 * and its Decision Outcomes total the same 187 — so volume and outcomes are
 * this-month panels, not all-time ones. Meanwhile a tier mean or a pass rate over
 * a single month is noise, so those run on a trailing window. Every panel states
 * its own window on screen rather than letting the reader assume one.
 *
 *   this month     Application Volume, Decision Outcomes, the "This Month" KPI
 *   year to date   the "Applications YTD" KPI
 *   trailing N mo  tier processing times, time-in-stage, compliance indicators,
 *                  inspections, officer activity
 *   as of today    Active Businesses, expiry windows, barangays, lines of
 *                  business, form of organization, the map
 *
 * DEFINITIONS THAT ARE CHOICES, stated because they are arguable
 *
 *  - **RA 11032 tier** is `applications.complexity`, a real column: simple /
 *    complex / highly_technical. Nothing is inferred. A tier with no decided
 *    filing in the window reports no observations rather than a zero mean — the
 *    register has no highly-technical filings on record and the screen says that
 *    instead of drawing a bar at 0 days against a 20-day target.
 *  - **Processing time is measured in WORKING days**, because RA 11032 sets its
 *    3/7/20-day limits in working days. Calendar days are reported alongside as
 *    context but are never the figure compared against the statutory target.
 *    Measuring a working-day statute in calendar days would manufacture breaches;
 *    that is as dishonest as hiding one.
 *  - **"Decisioned" excludes Pending.** Approval Rate divides by approved +
 *    returned + rejected, never by the grand total. Cancelled filings are neither
 *    a decision nor pending and are counted in their own bucket, surfaced only
 *    when non-zero.
 *  - **Active business** holds a permit valid today — the same definition
 *    BusinessGrowthAnalytics uses for its Active row, so the two screens cannot
 *    disagree about how many businesses are active.
 *  - **Inspection type comes from the inspecting department**, because
 *    `inspections.inspection_type` is null on every seeded row. City Health reads
 *    as Sanitary, Fire Protection as Fire Safety, Zoning as Zoning. The
 *    department is real data; the type column is not populated.
 *  - **Expiry windows are cumulative** (30d ⊂ 60d ⊂ 90d), matching the mockup.
 *    Expired is a separate, non-overlapping row.
 */
final class DashboardAnalytics
{
    /** The R endpoint that computes this dataset. */
    public const R_ENDPOINT = '/dashboard';

    /**
     * Trailing window, in months, for the rate and mean panels.
     *
     * Twelve because a tier mean or a pass rate wants a full year of seasonality,
     * and because a renewal-compliance figure has to cover a whole renewal cycle
     * to mean anything.
     */
    public const DEFAULT_WINDOW_MONTHS = 12;

    /** Rows in the ranked panels (barangays, lines of business). */
    private const TOP_N = 5;

    /**
     * Points plotted on the map, at most.
     *
     * The map is a point layer, and a snapshot has to stay a reasonable size. The
     * payload also reports how many businesses have coordinates in total, so a
     * truncated layer says so rather than reading as the whole register.
     */
    private const MAP_POINT_LIMIT = 1000;

    /**
     * RA 11032's statutory limits, in WORKING days.
     *
     * These are legal thresholds from the Ease of Doing Business Act, not
     * internal service targets, and they are the reason this screen exists. They
     * travel in the payload so R and PHP read one copy instead of hardcoding two.
     *
     * @var array<string, array{label: string, statutory_working_days: int}>
     */
    private const TIERS = [
        'simple' => ['label' => 'Simple', 'statutory_working_days' => 3],
        'complex' => ['label' => 'Complex', 'statutory_working_days' => 7],
        'highly_technical' => ['label' => 'Highly technical', 'statutory_working_days' => 20],
    ];

    /** Cumulative expiry horizons, in days. */
    private const EXPIRY_WINDOWS = [30, 60, 90];

    /**
     * Which department's inspections count as which type.
     *
     * @var array<string, string>
     */
    private const INSPECTION_TYPE_BY_DEPARTMENT = [
        'CHO' => 'Sanitary',
        'BFP' => 'Fire Safety',
        'CPDO' => 'Zoning',
    ];

    /**
     * The four forms of organization the spec names, in its order.
     *
     * @var array<string, string>
     */
    private const ORGANIZATION_FORMS = [
        'sole_proprietorship' => 'Sole Proprietorship',
        'corporation' => 'Corporation',
        'partnership' => 'Partnership',
        'cooperative' => 'Cooperative',
    ];

    /**
     * The facts every panel needs, gathered from the register.
     *
     * No statistics here — no rates, no means, no ranks. Those are R's job (and
     * compute()'s). What travels is counts, per-observation rows, and the rules
     * (statutory targets, window horizons) so neither engine keeps its own copy
     * of a number the other also keeps.
     *
     * @return array<string, mixed>
     */
    public static function dataset(int $windowMonths = self::DEFAULT_WINDOW_MONTHS): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $windowStart = $today->subMonths($windowMonths);
        $ytdStart = $today->startOfYear();
        $monthStart = $today->startOfMonth();

        // Gathered once: the RA 11032 compliance indicator is derived from these
        // same rows so the indicator and the tier panel cannot disagree.
        $tierObservations = self::tierObservations($windowStart, $now);

        return [
            'params' => ['months' => $windowMonths],
            'now' => $now->toISOString(),
            'today' => $today->toDateString(),
            'window_start' => $windowStart->toDateString(),
            'ytd_start' => $ytdStart->toDateString(),
            'month_start' => $monthStart->toDateString(),
            'top_n' => self::TOP_N,
            'expiry_windows' => self::EXPIRY_WINDOWS,
            'tiers' => self::tierRules(),
            'map_point_limit' => self::MAP_POINT_LIMIT,

            'kpis' => self::kpiFacts($today, $ytdStart, $monthStart, $now),
            'volume' => self::volumeFacts($monthStart, $now),
            'decisions' => self::decisionFacts($monthStart, $now),
            'tier_observations' => $tierObservations,
            'stage_observations' => self::stageObservations($windowStart, $now),
            'compliance' => self::complianceFacts($today, $windowStart, $now, $tierObservations),
            'permit_type_columns' => self::permitTypeColumns(),
            'expiring_permits' => self::expiringPermits($today),
            'barangays' => self::barangayFacts($today),
            'lines_of_business' => self::lineOfBusinessFacts($today),
            'organization_forms' => self::organizationFormFacts(),
            'inspections' => self::inspectionFacts($windowStart, $now),
            'officer_activity' => self::officerActivityFacts($windowStart, $now),
            'map' => self::mapFacts($today),
        ];
    }

    /** @return array<string, mixed> */
    public static function build(int $windowMonths = self::DEFAULT_WINDOW_MONTHS): array
    {
        return self::compute(self::dataset($windowMonths));
    }

    /**
     * The local (PHP) engine: facts in, dashboard statistics out, no database.
     *
     * @param  array<string, mixed>  $dataset  as returned by dataset()
     * @return array<string, mixed>
     */
    public static function compute(array $dataset): array
    {
        $topN = (int) ($dataset['top_n'] ?? self::TOP_N);

        $decisions = self::computeDecisions($dataset['decisions']);
        $compliance = self::computeCompliance($dataset['compliance']);

        return [
            // Echoed, not re-derived: the frame is Laravel's clock and R must
            // stay a pure function of its input.
            'generated_at' => (string) $dataset['now'],
            'window_months' => (int) $dataset['params']['months'],
            'window_start' => (string) $dataset['window_start'],
            'ytd_start' => (string) $dataset['ytd_start'],
            'month_start' => (string) $dataset['month_start'],
            'today' => (string) $dataset['today'],

            'kpis' => self::computeKpis($dataset['kpis'], $compliance),
            'volume' => self::computeVolume($dataset['volume']),
            'decisions' => $decisions,
            'processing_tiers' => self::computeTiers($dataset['tiers'], $dataset['tier_observations']),
            'stages' => self::computeStages($dataset['stage_observations']),
            'compliance' => $compliance,
            'expiry' => self::computeExpiry(
                $dataset['permit_type_columns'],
                $dataset['expiring_permits'],
                $dataset['expiry_windows'],
            ),
            'top_barangays' => self::computeShares($dataset['barangays'], 'barangay', $topN),
            'top_lines_of_business' => self::computeShares($dataset['lines_of_business'], 'industry', $topN),
            'organization_forms' => self::computeOrganizationForms($dataset['organization_forms']),
            'inspections' => self::computeInspections($dataset['inspections']),
            'officer_activity' => self::computeOfficerActivity($dataset['officer_activity']),
            'map' => self::computeMap($dataset['map']),
        ];
    }

    /* ── the rules, shipped with the facts ─────────────────────────────── */

    /** @return list<array{tier: string, label: string, statutory_working_days: int}> */
    private static function tierRules(): array
    {
        $rules = [];
        foreach (self::TIERS as $tier => $rule) {
            $rules[] = [
                'tier' => $tier,
                'label' => $rule['label'],
                'statutory_working_days' => $rule['statutory_working_days'],
            ];
        }

        return $rules;
    }

    /* ── facts: KPI cards ──────────────────────────────────────────────── */

    /** @return array<string, int> */
    private static function kpiFacts(
        CarbonImmutable $today,
        CarbonImmutable $ytdStart,
        CarbonImmutable $monthStart,
        CarbonImmutable $now,
    ): array {
        return [
            'active_businesses' => count(self::activeBusinessIds($today)),
            'applications_ytd' => DB::table('applications')
                ->whereNull('deleted_at')
                ->where('created_at', '>=', $ytdStart)
                ->where('created_at', '<=', $now)
                ->count(),
            'applications_this_month' => DB::table('applications')
                ->whereNull('deleted_at')
                ->where('created_at', '>=', $monthStart)
                ->where('created_at', '<=', $now)
                ->count(),
        ];
    }

    /**
     * Businesses holding a permit valid today.
     *
     * Memoised for the pass: four panels need this same set and it is one query.
     *
     * @return list<int>
     */
    private static function activeBusinessIds(CarbonImmutable $today): array
    {
        static $cache = [];
        $key = $today->toDateString();

        if (! isset($cache[$key])) {
            $cache[$key] = DB::table('permits')
                ->join('businesses', 'businesses.id', '=', 'permits.business_id')
                ->whereNull('businesses.deleted_at')
                ->where('permits.status', PermitStatus::Active->value)
                ->whereDate('permits.valid_until', '>=', $key)
                ->distinct()
                ->pluck('permits.business_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return $cache[$key];
    }

    /* ── facts: Application Volume ─────────────────────────────────────── */

    /**
     * Filings this month by transaction type.
     *
     * Every type is emitted whether or not it occurred, so a month with no
     * amendments shows "Amendments 0" rather than dropping the row and leaving
     * the reader to wonder whether the panel is broken.
     *
     * @return list<array{type: string, label: string, count: int}>
     */
    private static function volumeFacts(CarbonImmutable $monthStart, CarbonImmutable $now): array
    {
        $counts = DB::table('applications')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<=', $now)
            ->groupBy('application_type')
            ->selectRaw('application_type, count(*) as c')
            ->pluck('c', 'application_type');

        $labels = [
            ApplicationType::New->value => 'New',
            ApplicationType::Renewal->value => 'Renewals',
            ApplicationType::Amendment->value => 'Amendments',
        ];

        $rows = [];
        foreach ($labels as $type => $label) {
            $rows[] = ['type' => $type, 'label' => $label, 'count' => (int) ($counts[$type] ?? 0)];
        }

        return $rows;
    }

    /* ── facts: Decision Outcomes ──────────────────────────────────────── */

    /**
     * This month's filings bucketed by outcome.
     *
     * `decisioned` marks the three buckets that belong in the Approval Rate
     * denominator. Carrying the flag with the fact keeps the formula's one
     * subtlety — that Pending is excluded — out of two engines' arithmetic.
     *
     * @return list<array{outcome: string, label: string, count: int, decisioned: bool}>
     */
    private static function decisionFacts(CarbonImmutable $monthStart, CarbonImmutable $now): array
    {
        $counts = DB::table('applications')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<=', $now)
            ->groupBy('status')
            ->selectRaw('status, count(*) as c')
            ->pluck('c', 'status');

        $approved = (int) ($counts[ApplicationStatus::Approved->value] ?? 0);
        $returned = (int) ($counts[ApplicationStatus::Returned->value] ?? 0);
        $rejected = (int) ($counts[ApplicationStatus::Rejected->value] ?? 0);
        $cancelled = (int) ($counts[ApplicationStatus::Cancelled->value] ?? 0);

        // Everything still moving through the workflow. Listed by exclusion so a
        // new status added to the enum lands in Pending rather than vanishing
        // from the panel and quietly breaking the total.
        $pending = 0;
        foreach ($counts as $status => $count) {
            if (! in_array((string) $status, [
                ApplicationStatus::Approved->value,
                ApplicationStatus::Returned->value,
                ApplicationStatus::Rejected->value,
                ApplicationStatus::Cancelled->value,
            ], true)) {
                $pending += (int) $count;
            }
        }

        return [
            ['outcome' => 'approved', 'label' => 'Approved', 'count' => $approved, 'decisioned' => true],
            ['outcome' => 'returned', 'label' => 'Returned for revision', 'count' => $returned, 'decisioned' => true],
            ['outcome' => 'rejected', 'label' => 'Rejected', 'count' => $rejected, 'decisioned' => true],
            ['outcome' => 'pending', 'label' => 'Pending', 'count' => $pending, 'decisioned' => false],
            ['outcome' => 'cancelled', 'label' => 'Cancelled', 'count' => $cancelled, 'decisioned' => false],
        ];
    }

    /* ── facts: processing time per RA 11032 tier ──────────────────────── */

    /**
     * One row per decided filing in the window: its tier and how long it took.
     *
     * Working days are counted here rather than in R because the calendar is
     * Laravel's and R must stay pure. Both measures travel; only the working-day
     * one is compared against the statutory limit.
     *
     * TWO DEADLINES, AND THEY ARE NOT THE SAME DEADLINE. `within_statutory` is
     * measured against RA 11032's limit for the filing's own tier — 3, 7 or 20
     * working days. `within_recorded_deadline` is measured against
     * `applications.deadline_at`, which the workflow sets to a flat ten working
     * days for every filing regardless of tier. Those are different yardsticks and
     * they are kept as different fields, because reporting the second one next to
     * the statutory limit reads as statutory compliance when it is not: ten working
     * days is more than three times what the law allows a simple transaction.
     *
     * `recorded_deadline_working_days` travels so the screen can state that gap
     * rather than leave a reader to assume the two agree.
     *
     * @return list<array{tier: string, working_days: int, calendar_days: float, within_statutory: bool, within_recorded_deadline: bool, recorded_deadline_working_days: int|null}>
     */
    private static function tierObservations(CarbonImmutable $windowStart, CarbonImmutable $now): array
    {
        $rows = DB::table('applications')
            ->whereNull('deleted_at')
            ->whereNotNull('complexity')
            ->whereNotNull('submitted_at')
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', $windowStart)
            ->where('decided_at', '<=', $now)
            ->orderBy('id')
            ->get(['complexity', 'submitted_at', 'decided_at', 'deadline_at']);

        $observations = [];
        foreach ($rows as $row) {
            $tier = (string) $row->complexity;
            if (! isset(self::TIERS[$tier])) {
                // An unrecognised tier is not silently folded into a real one:
                // it would move a statutory mean it does not belong to.
                continue;
            }

            $submitted = CarbonImmutable::parse($row->submitted_at);
            $decided = CarbonImmutable::parse($row->decided_at);
            $workingDays = self::workingDaysBetween($submitted, $decided);

            $observations[] = [
                'tier' => $tier,
                'working_days' => $workingDays,
                'calendar_days' => Rounding::statistic($submitted->diffInHours($decided) / 24),
                // The statutory test: this filing's own turnaround against the
                // legal limit for its tier.
                'within_statutory' => $workingDays <= self::TIERS[$tier]['statutory_working_days'],
                'within_recorded_deadline' => $row->deadline_at !== null
                    && $decided->lessThanOrEqualTo(CarbonImmutable::parse($row->deadline_at)),
                'recorded_deadline_working_days' => $row->deadline_at === null
                    ? null
                    : self::workingDaysBetween($submitted, CarbonImmutable::parse($row->deadline_at)),
            ];
        }

        return $observations;
    }

    /**
     * Whole working days from one instant to another, weekends excluded.
     *
     * Matches how `deadline_at` is set (`addWeekdays`), so a filing's measured
     * duration and its statutory deadline are counted on the same calendar.
     * Philippine holidays are not modelled — the register does not carry a
     * holiday table, so this slightly overstates working days around them.
     */
    private static function workingDaysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $cursor = $from->startOfDay();
        $end = $to->startOfDay();
        $days = 0;

        while ($cursor->lessThan($end)) {
            $cursor = $cursor->addDay();
            if (! $cursor->isWeekend()) {
                $days++;
            }
        }

        return $days;
    }

    /* ── facts: time-in-stage per department ───────────────────────────── */

    /**
     * One row per completed review assignment in the window.
     *
     * @return list<array{code: string, name: string, days: float}>
     */
    private static function stageObservations(CarbonImmutable $windowStart, CarbonImmutable $now): array
    {
        $rows = DB::table('application_assignments')
            ->join('departments', 'departments.id', '=', 'application_assignments.department_id')
            ->whereNotNull('application_assignments.completed_at')
            ->where('application_assignments.completed_at', '>=', $windowStart)
            ->where('application_assignments.completed_at', '<=', $now)
            ->orderBy('application_assignments.id')
            ->get([
                'departments.code',
                'departments.name',
                'application_assignments.assigned_at',
                'application_assignments.completed_at',
            ]);

        $observations = [];
        foreach ($rows as $row) {
            $assigned = CarbonImmutable::parse($row->assigned_at);
            $completed = CarbonImmutable::parse($row->completed_at);

            $observations[] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'days' => Rounding::statistic($assigned->diffInHours($completed) / 24),
            ];
        }

        return $observations;
    }

    /* ── facts: the three compliance indicators ────────────────────────── */

    /**
     * Numerator and denominator for each indicator in spec §1.
     *
     * Only the two counts travel. Both engines then compute the same division,
     * which is the point: an indicator that cannot be computed (empty
     * denominator) is null in both, and neither invents a zero.
     *
     * @return list<array{indicator: string, label: string, numerator: int, denominator: int, numerator_label: string, denominator_label: string}>
     */
    private static function complianceFacts(
        CarbonImmutable $today,
        CarbonImmutable $windowStart,
        CarbonImmutable $now,
        array $tierObservations,
    ): array {
        return [
            self::ra11032Compliance($tierObservations),
            self::permitValidityCompliance($today),
            self::renewalCompliance($windowStart, $now),
        ];
    }

    /**
     * Share of decided filings that met RA 11032's limit for their own tier.
     *
     * MEASURED AGAINST THE STATUTE, NOT `deadline_at`. An earlier version of this
     * counted filings decided on or before `applications.deadline_at` and reported
     * 99%. That column is a flat ten working days for every filing whatever its
     * tier, so for a simple transaction it is over three times the three working
     * days the law allows — and labelling the result "RA 11032 processing" claimed
     * a statutory compliance the figure did not measure. It sat next to a tier
     * panel flagging the same filings as breaching, which is how the contradiction
     * surfaced.
     *
     * Derived from the tier observations rather than its own query, so this
     * indicator and the tier panel cannot disagree about the same filings.
     *
     * @param  list<array<string, mixed>>  $tierObservations
     * @return array{indicator: string, label: string, numerator: int, denominator: int, numerator_label: string, denominator_label: string}
     */
    private static function ra11032Compliance(array $tierObservations): array
    {
        $onTime = 0;
        foreach ($tierObservations as $observation) {
            if ($observation['within_statutory']) {
                $onTime++;
            }
        }

        return [
            'indicator' => 'ra11032_processing',
            'label' => 'RA 11032 processing',
            'numerator' => $onTime,
            'denominator' => count($tierObservations),
            'numerator_label' => 'were decided inside the statutory limit for their tier',
            'denominator_label' => 'decided filings with a recorded tier',
        ];
    }

    /**
     * Businesses whose every issued permit type is currently valid.
     *
     * "Complete valid permits" has to mean something checkable. It cannot mean
     * "holds every permit type the city issues" — most businesses need only
     * some. So the test is that nothing the business was ever issued has been
     * left to lapse: for each permit type it has ever held, it holds one valid
     * today. The denominator is businesses that have ever been issued a permit,
     * because a business that has never held one has no validity to be complete.
     *
     * @return array{indicator: string, label: string, numerator: int, denominator: int, numerator_label: string, denominator_label: string}
     */
    private static function permitValidityCompliance(CarbonImmutable $today): array
    {
        $rows = DB::table('permits')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNull('businesses.deleted_at')
            ->get(['permits.business_id', 'permits.permit_type_id', 'permits.status', 'permits.valid_until']);

        $everHeld = [];
        $validToday = [];
        foreach ($rows as $row) {
            $businessId = (int) $row->business_id;
            $typeId = (int) $row->permit_type_id;
            $everHeld[$businessId][$typeId] = true;

            if ($row->status === PermitStatus::Active->value
                && CarbonImmutable::parse($row->valid_until)->toDateString() >= $today->toDateString()) {
                $validToday[$businessId][$typeId] = true;
            }
        }

        $complete = 0;
        foreach ($everHeld as $businessId => $types) {
            if (count($validToday[$businessId] ?? []) === count($types)) {
                $complete++;
            }
        }

        return [
            'indicator' => 'permit_validity',
            'label' => 'Business permit compliance',
            'numerator' => $complete,
            'denominator' => count($everHeld),
            'numerator_label' => 'hold a valid permit for every type they have been issued',
            'denominator_label' => 'businesses ever issued a permit',
        ];
    }

    /**
     * Share of permits that fell due in the window whose renewal was filed
     * before they expired.
     *
     * A renewal is linked to the permit it replaces by `applications.prior_permit_id`,
     * the only such link in the schema. Never-submitted drafts are not on-time.
     *
     * THE FAILURE MODE THIS GUARDS. That link is set on almost none of the
     * register's renewal filings — 2 of 746 at the time of writing. Divide anyway
     * and the indicator reports 0%, which does not read as "we cannot tell": it
     * reads as "not one business in Malabon renewed on time", which is a much
     * stronger and entirely false claim. So when the window has permits that fell
     * due but not one of them has a renewal filing linked to it, the indicator
     * declines to compute and says why.
     *
     * The test is on the data, not a flag: link the filings and the indicator
     * starts working with no change here.
     *
     * @return array{indicator: string, label: string, numerator: int, denominator: int, numerator_label: string, denominator_label: string, unavailable_reason?: string}
     */
    private static function renewalCompliance(CarbonImmutable $windowStart, CarbonImmutable $now): array
    {
        /*
         * THE DENOMINATOR HAS TO BE COMMENSURABLE WITH THE NUMERATOR.
         *
         * A renewal application carries exactly one `prior_permit_id`, so it can
         * only ever be credited against one permit — in practice the business
         * permit, with the sanitary, fire and zoning clearances riding along on
         * the same filing. Counting every permit type that fell due therefore
         * built a denominator the numerator could not reach by construction:
         * 1,257 permits due (444 business, 321 sanitary, 321 fire, 71 zoning)
         * against a numerator capped at the number of filings. The indicator read
         * 21.2% and looked like a compliance catastrophe when it was an
         * arithmetic artefact.
         *
         * So restrict the denominator to the permit types renewals actually
         * re-validate, derived from the register rather than hardcoded, which
         * keeps this correct if this LGU ever starts filing standalone renewals
         * for a clearance. Same rows, scoped: 266 of 444 = 59.9%.
         *
         * The remaining shortfall is a real finding, not an artefact — 169
         * business permits fell due with no renewal filed at all, while 91% of
         * the renewals that were filed arrived on time.
         */
        $renewablePermitTypes = DB::table('applications')
            ->join('permits', 'permits.id', '=', 'applications.prior_permit_id')
            ->whereNull('applications.deleted_at')
            ->where('applications.application_type', ApplicationType::Renewal->value)
            ->whereNotNull('applications.submitted_at')
            ->distinct()
            ->pluck('permits.permit_type_id')
            ->all();

        $due = DB::table('permits')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNull('businesses.deleted_at')
            ->when(
                $renewablePermitTypes !== [],
                static fn ($q) => $q->whereIn('permits.permit_type_id', $renewablePermitTypes),
            )
            ->whereDate('permits.valid_until', '>=', $windowStart->toDateString())
            ->whereDate('permits.valid_until', '<=', $now->toDateString())
            ->pluck('permits.valid_until', 'permits.id');

        if ($due->isEmpty()) {
            return [
                'indicator' => 'renewal',
                'label' => 'Renewal compliance',
                'numerator' => 0,
                'denominator' => 0,
                'numerator_label' => 'renewed before expiry',
                'denominator_label' => 'permits due for renewal',
            ];
        }

        $renewals = DB::table('applications')
            ->whereNull('deleted_at')
            ->where('application_type', ApplicationType::Renewal->value)
            ->whereNotNull('submitted_at')
            ->whereIn('prior_permit_id', $due->keys()->all())
            ->get(['prior_permit_id', 'submitted_at']);

        $onTime = [];
        $linked = [];
        foreach ($renewals as $renewal) {
            $permitId = (int) $renewal->prior_permit_id;
            $linked[$permitId] = true;
            $expiry = CarbonImmutable::parse($due[$permitId])->startOfDay();
            if (CarbonImmutable::parse($renewal->submitted_at)->startOfDay()->lessThanOrEqualTo($expiry)) {
                $onTime[$permitId] = true;
            }
        }

        $fact = [
            'indicator' => 'renewal',
            'label' => 'Renewal compliance',
            'numerator' => count($onTime),
            'denominator' => $due->count(),
            'numerator_label' => 'renewed before expiry',
            'denominator_label' => 'permits due for renewal',
        ];

        if ($linked === []) {
            $fact['unavailable_reason'] = 'No renewal filing in this window records which permit it replaces, '
                .'so on-time renewals cannot be counted. This is a gap in the register, not a compliance finding.';
        }

        return $fact;
    }

    /* ── facts: permits approaching expiry ─────────────────────────────── */

    /**
     * The permit types the expiry table has columns for.
     *
     * Read off the register rather than hardcoded to the mockup's three, so the
     * zoning and occupancy permits this LGU actually issues cannot silently drop
     * out of a compliance table.
     *
     * @return list<array{code: string, label: string}>
     */
    private static function permitTypeColumns(): array
    {
        return DB::table('permit_types')
            ->join('permits', 'permits.permit_type_id', '=', 'permit_types.id')
            ->groupBy('permit_types.id', 'permit_types.code', 'permit_types.name')
            ->orderBy('permit_types.id')
            ->get(['permit_types.code', 'permit_types.name'])
            ->map(static fn ($row): array => [
                'code' => (string) $row->code,
                'label' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * One row per permit in the widest window, with its signed days to expiry.
     *
     * R buckets these cumulatively. Emitting per-permit rows rather than
     * pre-bucketed counts is what lets the cumulative nesting (30 ⊂ 60 ⊂ 90) be
     * a computation R performs instead of an assumption Laravel bakes in.
     *
     * @return list<array{code: string, days_to_expiry: int}>
     */
    private static function expiringPermits(CarbonImmutable $today): array
    {
        $rows = DB::table('permits')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNull('businesses.deleted_at')
            ->whereIn('permits.status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->orderBy('permits.id')
            ->get(['permit_types.code', 'permits.valid_until']);

        $out = [];
        foreach ($rows as $row) {
            $validUntil = CarbonImmutable::parse($row->valid_until)->startOfDay();

            $out[] = [
                'code' => (string) $row->code,
                'days_to_expiry' => (int) $today->diffInDays($validUntil, false),
            ];
        }

        return $out;
    }

    /* ── facts: rankings ──────────────────────────────────────────────── */

    /**
     * Active businesses per barangay.
     *
     * @return list<array{barangay: string, count: int}>
     */
    private static function barangayFacts(CarbonImmutable $today): array
    {
        $activeIds = self::activeBusinessIds($today);

        if ($activeIds === []) {
            return [];
        }

        return DB::table('business_addresses')
            ->join('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
            ->whereIn('business_addresses.business_id', $activeIds)
            ->where('business_addresses.address_type', 'business_location')
            ->groupBy('barangays.id', 'barangays.name')
            ->orderBy('barangays.name')
            ->get(['barangays.name', DB::raw('count(distinct business_addresses.business_id) as c')])
            ->map(static fn ($row): array => [
                'barangay' => (string) $row->name,
                'count' => (int) $row->c,
            ])
            ->all();
    }

    /**
     * Active businesses per line of business.
     *
     * Grouped by PSIC code, using `psic_codes.title`. The mockup shows broader
     * buckets ("Retail — general"); those would need a PSIC division-to-label
     * table the register does not have, and inventing one would put a label on
     * the screen that no column can be checked against. So the real, narrower
     * PSIC titles are what is ranked.
     *
     * @return list<array{industry: string, psic_code: string, count: int}>
     */
    private static function lineOfBusinessFacts(CarbonImmutable $today): array
    {
        $activeIds = self::activeBusinessIds($today);

        if ($activeIds === []) {
            return [];
        }

        return DB::table('business_lines')
            ->join('psic_codes', 'psic_codes.id', '=', 'business_lines.psic_code_id')
            ->whereIn('business_lines.business_id', $activeIds)
            ->groupBy('psic_codes.id', 'psic_codes.code', 'psic_codes.title')
            ->orderBy('psic_codes.code')
            ->get([
                'psic_codes.code',
                'psic_codes.title',
                DB::raw('count(distinct business_lines.business_id) as c'),
            ])
            ->map(static fn ($row): array => [
                'industry' => (string) $row->title,
                'psic_code' => (string) $row->code,
                'count' => (int) $row->c,
            ])
            ->all();
    }

    /**
     * Businesses by form of organization, plus how many have none on record.
     *
     * `businesses.form_of_organization` exists but is null on every seeded row.
     * The unrecorded count travels so the screen can say that plainly instead of
     * printing four zeros that read as "no corporations in Malabon". Deriving the
     * form from `registration_type` was considered and rejected: DTI vs SEC
     * separates sole proprietorships from the rest but cannot tell a corporation
     * from a partnership, so two of the four rows would still be guesses wearing
     * a real column's name.
     *
     * @return array{forms: list<array{form: string, label: string, count: int}>, unrecorded: int, total: int}
     */
    private static function organizationFormFacts(): array
    {
        $counts = DB::table('businesses')
            ->whereNull('deleted_at')
            ->whereNotNull('form_of_organization')
            ->groupBy('form_of_organization')
            ->selectRaw('form_of_organization, count(*) as c')
            ->pluck('c', 'form_of_organization');

        $total = DB::table('businesses')->whereNull('deleted_at')->count();

        $forms = [];
        $recorded = 0;
        foreach (self::ORGANIZATION_FORMS as $form => $label) {
            $count = (int) ($counts[$form] ?? 0);
            $recorded += $count;
            $forms[] = ['form' => $form, 'label' => $label, 'count' => $count];
        }

        return [
            'forms' => $forms,
            // Anything on record under a value outside the four named forms is
            // counted as unrecorded rather than dropped, so the parts sum.
            'unrecorded' => max(0, $total - $recorded),
            'total' => $total,
        ];
    }

    /* ── facts: inspections ────────────────────────────────────────────── */

    /**
     * Per inspection type: scheduled, completed, and the completed breakdown.
     *
     * @return list<array{type: string, label: string, scheduled: int, completed: int, passed: int, failed: int, conditional: int}>
     */
    private static function inspectionFacts(CarbonImmutable $windowStart, CarbonImmutable $now): array
    {
        $rows = DB::table('inspections')
            ->join('departments', 'departments.id', '=', 'inspections.department_id')
            ->join('applications', 'applications.id', '=', 'inspections.application_id')
            ->whereNull('applications.deleted_at')
            ->where('inspections.created_at', '>=', $windowStart)
            ->where('inspections.created_at', '<=', $now)
            ->get(['departments.code', 'inspections.status', 'inspections.result']);

        $buckets = [];
        foreach (self::INSPECTION_TYPE_BY_DEPARTMENT as $code => $label) {
            $buckets[$code] = [
                'type' => $code,
                'label' => $label,
                'scheduled' => 0,
                'completed' => 0,
                'passed' => 0,
                'failed' => 0,
                'conditional' => 0,
            ];
        }

        foreach ($rows as $row) {
            $code = (string) $row->code;
            if (! isset($buckets[$code])) {
                continue;
            }

            // Every inspection on record was scheduled at some point, so
            // "scheduled" is the total rather than the count still awaiting a
            // visit — otherwise a fully worked-through queue would report zero
            // scheduled and a pass rate with no context.
            $buckets[$code]['scheduled']++;

            if ((string) $row->status !== InspectionStatus::Completed->value) {
                continue;
            }

            $buckets[$code]['completed']++;

            match ((string) $row->result) {
                InspectionResult::Passed->value => $buckets[$code]['passed']++,
                InspectionResult::Failed->value => $buckets[$code]['failed']++,
                InspectionResult::Conditional->value => $buckets[$code]['conditional']++,
                default => null,
            };
        }

        return array_values($buckets);
    }

    /* ── facts: officer activity ───────────────────────────────────────── */

    /**
     * Officer response latencies, request fulfilment, and meeting participation.
     *
     * Response time is measured per officer reply: the hours from an applicant's
     * message to the next message in that thread sent by someone else. Only
     * replies are timed — an officer's opening message answers nothing.
     *
     * Meetings come from `officer_requests.meeting_scheduled_at`. The column
     * exists and nothing has ever been written to it, so the count is a true
     * zero and travels as one; the screen reports "none on record" rather than
     * a 0% participation rate, which would read as officers skipping meetings
     * that were never scheduled.
     *
     * @return array<string, mixed>
     */
    private static function officerActivityFacts(CarbonImmutable $windowStart, CarbonImmutable $now): array
    {
        $messages = DB::table('messages')
            ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
            ->where('messages.created_at', '>=', $windowStart)
            ->where('messages.created_at', '<=', $now)
            ->orderBy('messages.thread_id')
            ->orderBy('messages.created_at')
            ->orderBy('messages.id')
            ->get(['messages.thread_id', 'messages.sender_user_id', 'messages.created_at']);

        $applicants = DB::table('applications')
            ->whereNull('applications.deleted_at')
            ->join('message_threads', 'message_threads.application_id', '=', 'applications.id')
            ->pluck('applications.applicant_user_id', 'message_threads.id');

        $latencies = [];
        $awaiting = [];
        foreach ($messages as $message) {
            $threadId = (int) $message->thread_id;
            $applicantId = isset($applicants[$threadId]) ? (int) $applicants[$threadId] : null;
            $isApplicant = $applicantId !== null && (int) $message->sender_user_id === $applicantId;
            $sentAt = CarbonImmutable::parse($message->created_at);

            if ($isApplicant) {
                // Only the FIRST unanswered applicant message starts the clock;
                // a burst of four follow-ups is one wait, not four.
                $awaiting[$threadId] ??= $sentAt;

                continue;
            }

            if (isset($awaiting[$threadId])) {
                $latencies[] = Rounding::statistic($awaiting[$threadId]->diffInMinutes($sentAt) / 60);
                unset($awaiting[$threadId]);
            }
        }

        $requests = DB::table('officer_requests')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $now);

        $meetings = DB::table('officer_requests')
            ->whereNotNull('meeting_scheduled_at')
            ->where('meeting_scheduled_at', '>=', $windowStart)
            ->where('meeting_scheduled_at', '<=', $now);

        return [
            'response_hours' => $latencies,
            'threads_awaiting_reply' => count($awaiting),
            'requests' => [
                'total' => (clone $requests)->count(),
                'fulfilled' => (clone $requests)->where('status', OfficerRequestStatus::Fulfilled->value)->count(),
            ],
            'meetings' => [
                'scheduled' => (clone $meetings)->count(),
                // Attendance is not recorded anywhere. A meeting counts as
                // attended when the applicant left a response against it, which
                // is the closest thing the schema has to evidence someone turned
                // up — stated here so nobody reads it as attendance tracking.
                'attended' => (clone $meetings)
                    ->whereIn('id', DB::table('officer_request_responses')->select('officer_request_id'))
                    ->count(),
            ],
        ];
    }

    /* ── facts: GIS ────────────────────────────────────────────────────── */

    /**
     * Business locations for the map.
     *
     * These come from `business_addresses.latitude/longitude`, which is populated
     * for every seeded business. The previous version of this screen read
     * coordinates off the *inspections* feed instead, where latitude is null on
     * every row — which is why the map rendered "No mapped business locations
     * yet" while 748 businesses had coordinates on file. The empty state was
     * honest about what it had been given and wrong about the register.
     *
     * @return array<string, mixed>
     */
    private static function mapFacts(CarbonImmutable $today): array
    {
        $activeIds = self::activeBusinessIds($today);
        $active = array_fill_keys($activeIds, true);

        $rows = DB::table('business_addresses')
            ->join('businesses', 'businesses.id', '=', 'business_addresses.business_id')
            ->leftJoin('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
            ->whereNull('businesses.deleted_at')
            ->where('business_addresses.address_type', 'business_location')
            ->whereNotNull('business_addresses.latitude')
            ->whereNotNull('business_addresses.longitude')
            ->orderBy('businesses.id')
            ->get([
                'businesses.id',
                'businesses.name',
                'barangays.name as barangay',
                'business_addresses.latitude',
                'business_addresses.longitude',
            ]);

        $points = [];
        $seen = [];
        foreach ($rows as $row) {
            $businessId = (int) $row->id;
            if (isset($seen[$businessId])) {
                continue;
            }
            $seen[$businessId] = true;

            $points[] = [
                'business_id' => $businessId,
                'business' => (string) $row->name,
                'barangay' => $row->barangay === null ? null : (string) $row->barangay,
                'latitude' => Rounding::statistic((float) $row->latitude, 6),
                'longitude' => Rounding::statistic((float) $row->longitude, 6),
                'permit_state' => isset($active[$businessId]) ? 'active' : 'lapsed',
            ];
        }

        return [
            'mapped' => count($points),
            'total_businesses' => DB::table('businesses')->whereNull('deleted_at')->count(),
            'points' => array_slice($points, 0, self::MAP_POINT_LIMIT),
        ];
    }

    /* ── statistics: the PHP engine ───────────────────────────────────── */

    /**
     * @param  array<string, int>  $kpis
     * @param  list<array<string, mixed>>  $compliance
     * @return array<string, mixed>
     */
    private static function computeKpis(array $kpis, array $compliance): array
    {
        // The Compliance Rate card is the permit-validity indicator, not a fifth
        // figure of its own. Reading it out of the computed panel is what stops
        // the card and the panel disagreeing.
        $validity = null;
        foreach ($compliance as $indicator) {
            if ($indicator['indicator'] === 'permit_validity') {
                $validity = $indicator['rate'];
            }
        }

        return [
            'active_businesses' => (int) $kpis['active_businesses'],
            'applications_ytd' => (int) $kpis['applications_ytd'],
            'applications_this_month' => (int) $kpis['applications_this_month'],
            'compliance_rate' => $validity,
        ];
    }

    /**
     * @param  list<array{type: string, label: string, count: int}>  $volume
     * @return array{rows: list<array{type: string, label: string, count: int}>, total: int}
     */
    private static function computeVolume(array $volume): array
    {
        $rows = [];
        $total = 0;
        foreach ($volume as $row) {
            $count = (int) $row['count'];
            $total += $count;
            $rows[] = ['type' => (string) $row['type'], 'label' => (string) $row['label'], 'count' => $count];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Decision outcomes and the approval rate.
     *
     * The denominator is the sum of the buckets flagged `decisioned`, so Pending
     * cannot leak into it.
     *
     * @param  list<array{outcome: string, label: string, count: int, decisioned: bool}>  $decisions
     * @return array<string, mixed>
     */
    private static function computeDecisions(array $decisions): array
    {
        $rows = [];
        $decisioned = 0;
        $approved = 0;
        $total = 0;

        foreach ($decisions as $row) {
            $count = (int) $row['count'];
            $total += $count;

            if ($row['decisioned']) {
                $decisioned += $count;
            }
            if ($row['outcome'] === 'approved') {
                $approved = $count;
            }

            $rows[] = [
                'outcome' => (string) $row['outcome'],
                'label' => (string) $row['label'],
                'count' => $count,
                'decisioned' => (bool) $row['decisioned'],
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'decisioned' => $decisioned,
            'approved' => $approved,
            // Null, not zero, when nothing has been decided: a rate with an
            // empty denominator is not a number.
            'approval_rate' => $decisioned > 0
                ? Rounding::statistic(($approved / $decisioned) * 100, 1)
                : null,
        ];
    }

    /**
     * Mean processing time per RA 11032 tier against the statutory limit.
     *
     * A tier with no decided filing in the window returns nulls and
     * `observations: 0`. That is the honest reading — the register has nothing to
     * average — and it is why the screen can say "no filings on record" for
     * highly-technical work instead of drawing a compliant-looking bar at zero
     * days.
     *
     * @param  list<array{tier: string, label: string, statutory_working_days: int}>  $tiers
     * @param  list<array{tier: string, working_days: int, calendar_days: float, within_deadline: bool}>  $observations
     * @return list<array<string, mixed>>
     */
    private static function computeTiers(array $tiers, array $observations): array
    {
        $grouped = [];
        foreach ($observations as $observation) {
            $grouped[(string) $observation['tier']][] = $observation;
        }

        $out = [];
        foreach ($tiers as $tier) {
            $key = (string) $tier['tier'];
            $target = (int) $tier['statutory_working_days'];
            $rows = $grouped[$key] ?? [];
            $n = count($rows);

            if ($n === 0) {
                $out[] = [
                    'tier' => $key,
                    'label' => (string) $tier['label'],
                    'statutory_working_days' => $target,
                    'observations' => 0,
                    'mean_working_days' => null,
                    'mean_calendar_days' => null,
                    'within_statutory' => 0,
                    'within_statutory_rate' => null,
                    'within_recorded_deadline' => 0,
                    'recorded_deadline_working_days' => null,
                    'overage_days' => null,
                    'breaching' => false,
                ];

                continue;
            }

            $meanWorking = Rounding::statistic(
                array_sum(array_map(static fn (array $r): float => (float) $r['working_days'], $rows)) / $n,
                1,
            );
            $meanCalendar = Rounding::statistic(
                array_sum(array_map(static fn (array $r): float => (float) $r['calendar_days'], $rows)) / $n,
                1,
            );

            $withinStatutory = count(array_filter($rows, static fn (array $r): bool => (bool) $r['within_statutory']));

            // The recorded deadline is uniform across the register today, but that
            // is data rather than a guarantee, so it is reported only when every
            // filing in the tier agrees. A mixed tier gets null and the screen
            // stays quiet instead of naming a figure that is not the whole story.
            $recordedDeadlines = array_unique(array_map(
                static fn (array $r) => $r['recorded_deadline_working_days'],
                $rows,
            ));

            $out[] = [
                'tier' => $key,
                'label' => (string) $tier['label'],
                'statutory_working_days' => $target,
                'observations' => $n,
                'mean_working_days' => $meanWorking,
                'mean_calendar_days' => $meanCalendar,
                // Against the STATUTE — the same yardstick as `breaching`.
                'within_statutory' => $withinStatutory,
                'within_statutory_rate' => Rounding::statistic(($withinStatutory / $n) * 100, 1),
                // Against `applications.deadline_at`, which is a different and more
                // lenient yardstick. Never present this as statutory compliance.
                'within_recorded_deadline' => count(array_filter(
                    $rows,
                    static fn (array $r): bool => (bool) $r['within_recorded_deadline'],
                )),
                'recorded_deadline_working_days' => count($recordedDeadlines) === 1
                    ? reset($recordedDeadlines)
                    : null,
                'overage_days' => Rounding::statistic($meanWorking - $target, 1),
                // A statutory limit is breached when the mean exceeds it. No
                // tolerance band: 3.1 working days against a 3-day legal limit is
                // a breach, and softening it here would soften it on screen.
                'breaching' => $meanWorking > $target,
            ];
        }

        return $out;
    }

    /**
     * Mean time-in-stage per department, and which one is the bottleneck.
     *
     * The bottleneck is the slowest department by mean, and the summary sentence
     * is assembled from the computed values — never a fixed string. A hardcoded
     * "Fire Protection is the bottleneck" would keep reading as true after Fire
     * Protection got faster.
     *
     * @param  list<array{code: string, name: string, days: float}>  $observations
     * @return array<string, mixed>
     */
    private static function computeStages(array $observations): array
    {
        $grouped = [];
        foreach ($observations as $observation) {
            $code = (string) $observation['code'];
            $grouped[$code] ??= ['code' => $code, 'name' => (string) $observation['name'], 'days' => []];
            $grouped[$code]['days'][] = (float) $observation['days'];
        }

        $rows = [];
        foreach ($grouped as $group) {
            $n = count($group['days']);
            $rows[] = [
                'code' => $group['code'],
                'name' => $group['name'],
                'reviews' => $n,
                'mean_days' => Rounding::statistic(array_sum($group['days']) / $n, 1),
            ];
        }

        // Slowest first: the panel is read to find the queue that needs help.
        //
        // Code ascending is the final tie-break and it is not decoration. Three
        // offices here have one review each at a mean of 0.0 days, so mean and
        // volume both tie and the order would otherwise fall back to whatever
        // order the rows arrived in — which differs between this engine and R's.
        // The parity fixture caught exactly that.
        usort(
            $rows,
            static fn (array $a, array $b) => [$b['mean_days'], $b['reviews'], $a['code']]
                <=> [$a['mean_days'], $a['reviews'], $b['code']],
        );

        $overall = $observations === []
            ? null
            : Rounding::statistic(
                array_sum(array_map(static fn (array $o): float => (float) $o['days'], $observations)) / count($observations),
                1,
            );

        $bottleneck = null;
        if ($rows !== []) {
            $slowest = $rows[0];
            $bottleneck = [
                'code' => $slowest['code'],
                'name' => $slowest['name'],
                'mean_days' => $slowest['mean_days'],
                'reviews' => $slowest['reviews'],
                // How much slower than the all-department mean, which is the
                // number that makes "bottleneck" a claim rather than a label.
                'above_average_days' => $overall === null
                    ? null
                    : Rounding::statistic($slowest['mean_days'] - $overall, 1),
                'share_of_reviews' => Rounding::statistic(
                    ($slowest['reviews'] / max(1, count($observations))) * 100,
                    1,
                ),
            ];
        }

        return [
            'rows' => $rows,
            'reviews' => count($observations),
            'mean_days' => $overall,
            'bottleneck' => $bottleneck,
        ];
    }

    /**
     * The three indicators, each with its rate or null.
     *
     * @param  list<array<string, mixed>>  $compliance
     * @return list<array<string, mixed>>
     */
    private static function computeCompliance(array $compliance): array
    {
        $out = [];
        foreach ($compliance as $indicator) {
            $numerator = (int) $indicator['numerator'];
            $denominator = (int) $indicator['denominator'];
            $reason = $indicator['unavailable_reason'] ?? null;

            $out[] = [
                'indicator' => (string) $indicator['indicator'],
                'label' => (string) $indicator['label'],
                'numerator' => $numerator,
                'denominator' => $denominator,
                'numerator_label' => (string) $indicator['numerator_label'],
                'denominator_label' => (string) $indicator['denominator_label'],
                // Two ways an indicator has no rate, and they are different
                // things: nothing in the denominator, or a numerator the register
                // cannot establish. Both give null; only the second carries a
                // sentence, because only the second needs explaining.
                'rate' => $denominator > 0 && $reason === null
                    ? Rounding::statistic(($numerator / $denominator) * 100, 1)
                    : null,
                'unavailable_reason' => $reason === null ? null : (string) $reason,
            ];
        }

        return $out;
    }

    /**
     * Permits approaching expiry, in cumulative windows.
     *
     * 30d ⊂ 60d ⊂ 90d: a permit expiring in 20 days is counted in all three,
     * which is what the mockup's own figures do. Expired is disjoint from the
     * three forward windows.
     *
     * @param  list<array{code: string, label: string}>  $columns
     * @param  list<array{code: string, days_to_expiry: int}>  $permits
     * @param  list<int>  $windows
     * @return array<string, mixed>
     */
    private static function computeExpiry(array $columns, array $permits, array $windows): array
    {
        $codes = array_column($columns, 'code');

        $blank = array_fill_keys($codes, 0);
        $rows = [];
        foreach ($windows as $window) {
            $rows[] = [
                'window' => "next_{$window}d",
                'label' => "Next {$window}d",
                'days' => (int) $window,
                'expired' => false,
                'counts' => $blank,
                'total' => 0,
            ];
        }
        $rows[] = [
            'window' => 'expired',
            'label' => 'Expired',
            'days' => null,
            'expired' => true,
            'counts' => $blank,
            'total' => 0,
        ];

        foreach ($permits as $permit) {
            $code = (string) $permit['code'];
            if (! array_key_exists($code, $blank)) {
                continue;
            }

            $days = (int) $permit['days_to_expiry'];

            foreach ($rows as $index => $row) {
                $hit = $row['expired']
                    ? $days < 0
                    : ($days >= 0 && $days <= (int) $row['days']);

                if ($hit) {
                    $rows[$index]['counts'][$code]++;
                    $rows[$index]['total']++;
                }
            }
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Rank a count list and give each row its share of the total.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    private static function computeShares(array $facts, string $nameKey, int $topN): array
    {
        $total = array_sum(array_map(static fn (array $row): int => (int) $row['count'], $facts));

        $rows = [];
        foreach ($facts as $row) {
            $count = (int) $row['count'];
            $rows[] = [
                ...$row,
                'count' => $count,
                'share' => $total > 0 ? Rounding::statistic(($count / $total) * 100, 1) : null,
            ];
        }

        // Count descending, then name ascending so equal counts have a stable
        // order across refreshes instead of following query order.
        usort($rows, static fn (array $a, array $b) => [$b['count'], $a[$nameKey]] <=> [$a['count'], $b[$nameKey]]);

        $ranked = [];
        foreach (array_slice($rows, 0, $topN) as $index => $row) {
            $ranked[] = ['rank' => $index + 1, ...$row];
        }

        return ['rows' => $ranked, 'total' => $total, 'groups' => count($facts)];
    }

    /**
     * @param  array{forms: list<array{form: string, label: string, count: int}>, unrecorded: int, total: int}  $facts
     * @return array<string, mixed>
     */
    private static function computeOrganizationForms(array $facts): array
    {
        $total = (int) $facts['total'];
        $recorded = $total - (int) $facts['unrecorded'];

        $rows = [];
        foreach ($facts['forms'] as $row) {
            $count = (int) $row['count'];
            $rows[] = [
                'form' => (string) $row['form'],
                'label' => (string) $row['label'],
                'count' => $count,
                // Share of businesses whose form IS recorded, so the four rows
                // sum to 100% when any are populated rather than to a fraction
                // determined by how much of the column is blank.
                'share' => $recorded > 0 ? Rounding::statistic(($count / $recorded) * 100, 1) : null,
            ];
        }

        return [
            'rows' => $rows,
            'recorded' => $recorded,
            'unrecorded' => (int) $facts['unrecorded'],
            'total' => $total,
        ];
    }

    /**
     * Inspections per type with the pass rate over COMPLETED inspections.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    private static function computeInspections(array $facts): array
    {
        $rows = [];
        $combined = [
            'type' => 'combined',
            'label' => 'Combined',
            'scheduled' => 0,
            'completed' => 0,
            'passed' => 0,
            'failed' => 0,
            'conditional' => 0,
        ];

        foreach ($facts as $row) {
            foreach (['scheduled', 'completed', 'passed', 'failed', 'conditional'] as $field) {
                $combined[$field] += (int) $row[$field];
            }

            $rows[] = self::withPassRate([
                'type' => (string) $row['type'],
                'label' => (string) $row['label'],
                'scheduled' => (int) $row['scheduled'],
                'completed' => (int) $row['completed'],
                'passed' => (int) $row['passed'],
                'failed' => (int) $row['failed'],
                'conditional' => (int) $row['conditional'],
            ]);
        }

        return ['rows' => $rows, 'combined' => self::withPassRate($combined)];
    }

    /**
     * Pass Rate = Passed ÷ Completed × 100. The denominator is completed, never
     * scheduled: dividing by scheduled would report a queue's progress as a
     * quality figure.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withPassRate(array $row): array
    {
        $completed = (int) $row['completed'];

        return [
            ...$row,
            'pass_rate' => $completed > 0
                ? Rounding::statistic(((int) $row['passed'] / $completed) * 100, 1)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function computeOfficerActivity(array $facts): array
    {
        $latencies = array_map(static fn ($h): float => (float) $h, (array) $facts['response_hours']);
        $requests = $facts['requests'];
        $meetings = $facts['meetings'];

        $requestTotal = (int) $requests['total'];
        $meetingsScheduled = (int) $meetings['scheduled'];

        return [
            'responses' => count($latencies),
            'mean_response_hours' => $latencies === []
                ? null
                : Rounding::statistic(array_sum($latencies) / count($latencies), 1),
            // The median as well as the mean: with a handful of replies one
            // forgotten thread drags the mean somewhere no officer recognises.
            'median_response_hours' => self::median($latencies),
            'threads_awaiting_reply' => (int) $facts['threads_awaiting_reply'],
            'requests_total' => $requestTotal,
            'requests_fulfilled' => (int) $requests['fulfilled'],
            'requests_fulfilled_rate' => $requestTotal > 0
                ? Rounding::statistic(((int) $requests['fulfilled'] / $requestTotal) * 100, 1)
                : null,
            'meetings_scheduled' => $meetingsScheduled,
            'meetings_attended' => (int) $meetings['attended'],
            'meetings_attended_rate' => $meetingsScheduled > 0
                ? Rounding::statistic(((int) $meetings['attended'] / $meetingsScheduled) * 100, 1)
                : null,
        ];
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return Rounding::statistic(
            $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2,
            1,
        );
    }

    /**
     * The map layer, plus the per-barangay aggregation the choropleth reads.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private static function computeMap(array $facts): array
    {
        $points = (array) $facts['points'];

        $byBarangay = [];
        foreach ($points as $point) {
            $barangay = $point['barangay'] === null ? null : (string) $point['barangay'];
            if ($barangay === null) {
                continue;
            }
            $byBarangay[$barangay] ??= ['barangay' => $barangay, 'businesses' => 0, 'active' => 0];
            $byBarangay[$barangay]['businesses']++;
            if ((string) $point['permit_state'] === 'active') {
                $byBarangay[$barangay]['active']++;
            }
        }

        $plotted = count($points);
        $rows = [];
        foreach ($byBarangay as $row) {
            $rows[] = [
                ...$row,
                'share' => $plotted > 0 ? Rounding::statistic(($row['businesses'] / $plotted) * 100, 1) : null,
            ];
        }

        usort($rows, static fn (array $a, array $b) => [$b['businesses'], $a['barangay']] <=> [$a['businesses'], $b['barangay']]);

        return [
            'mapped' => (int) $facts['mapped'],
            'plotted' => $plotted,
            'total_businesses' => (int) $facts['total_businesses'],
            'points' => array_values($points),
            'by_barangay' => $rows,
        ];
    }
}

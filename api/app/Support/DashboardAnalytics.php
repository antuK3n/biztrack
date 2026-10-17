<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\ClearanceStatus;
use App\Enums\InspectionResult;
use App\Enums\InspectionStatus;
use App\Enums\OfficerRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use App\Models\Business;
use App\Models\PermitType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The Analytics Dashboard (docs/r-integration-spec.md §1), computed from the
 * register.
 *
 * Same split as every other analytics feature here: `dataset()` gathers facts in
 * bulk queries and is the payload `analytics:refresh` stores; `compute()` turns
 * those facts into the screen's statistics and touches no database at all. That
 * second half is the point of the split — plain arrays in, plain arrays out, so
 * every rate and rank on this screen can be replayed against a frozen dataset
 * and compared to a frozen answer (AnalyticsGoldenOutputTest).
 *
 * WINDOWS, BECAUSE EACH PANEL ANSWERS A DIFFERENT QUESTION
 *
 * The mockup mixes three time bases and the numbers only reconcile if you honour
 * that. Its "This Month" KPI is 187 and its Application Volume total is also 187,
 * and its Decision Outcomes total the same 187 — so volume and outcomes are
 * this-month panels, not all-time ones. Meanwhile an average time or a pass rate over
 * a single month is noise, so those run on a trailing window. Every panel states
 * its own window on screen rather than letting the reader assume one.
 *
 *   this month     Application Volume, Decision Outcomes, the "This Month" KPI
 *   calendar       Fees Collected: this month and this year, Manila calendar
 *   full term      the "Applications (all time)" KPI — every filing on record
 *   trailing N mo  time in office, compliance indicators, inspections,
 *                  messages and requests, new and closed businesses
 *   as of today    Active Businesses, Suspended Permits, expiry windows,
 *                  barangays, lines of business, form of organization, the map
 *
 * WHAT WENT, 18 OCTOBER 2026 (the client's picker)
 *
 * The RA 11032 tier panel and the "RA 11032 processing" compliance row both
 * rested on `applications.complexity`, which BPLO stopped setting on
 * 27 September (`canSetTier` false); the renewal forecast never had enough
 * history to say anything ('thin_history' on the live register); and the
 * officer panel's response-time and meeting figures were counts of things
 * nothing records. All four are gone from the payload, the screen and the
 * PDF. FilingClock, which timed the tier panel, still serves the Reports
 * tab's processing-time report. RenewalForecast was deleted with its panel —
 * nothing else read it; it is in git history (5 October 2026) if a renewal
 * estimate is wanted again once the register holds a few renewal seasons.
 *
 * DEFINITIONS THAT ARE CHOICES, stated because they are arguable
 *
 *  - **Time in office is measured in WORKING days**, from the permit's own
 *    status history: the day its office received it to the day that office
 *    issued or refused it. See stageObservations().
 *  - **"Decisioned" excludes Pending.** Approval Rate divides by approved +
 *    rejected, never by the grand total. Cancelled filings are neither a
 *    decision nor pending; they have no row since 18 October 2026 (applicants
 *    can no longer cancel, so the row was a permanent zero) and are left out
 *    of every bucket.
 *  - **Active business** holds a permit valid today.
 *  - **Inspection type comes from the inspecting department**, because
 *    `inspections.inspection_type` is null on every seeded row. City Health reads
 *    as Sanitary, Fire Protection as Fire Safety, and so on. The department is
 *    real data; the type column is not populated.
 *  - **An office inspects if it issues an inspected permit type**, read from
 *    `permit_types.requires_inspection` rather than from a list kept here. All
 *    six supporting clearances are inspected and the Mayor's Permit is not, so
 *    the panel shows six offices and no BPLO without either fact being spelled
 *    out twice. Every one of them is shown even at zero visits: an office
 *    missing from that panel reads as an office that does not inspect.
 *  - **Expiry windows are cumulative** (30d ⊂ 60d ⊂ 90d), matching the mockup.
 *    Expired is a separate, non-overlapping row.
 */
final class DashboardAnalytics
{
    /**
     * Trailing window, in months, for the rate and mean panels.
     *
     * Twelve because an average time or a pass rate wants a full year of seasonality,
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
     * The shape of the computed payload, stored with every snapshot.
     *
     * Bumped whenever a key is added or removed, so a snapshot written before
     * the change is never served after it: AnalyticsResolver treats a stored
     * payload of another version as no snapshot at all and computes the view
     * on the spot until the next refresh. Without it, the 18 October 2026
     * change would have kept serving `processing_tiers` and the renewal
     * forecast from last night's snapshots, and the new Fees Collected panel
     * would have been blank until 03:00.
     *
     *   1  everything before 18 October 2026 (no `schema_version` key)
     *   2  tier panel, RA 11032 row, renewal forecast, response time and
     *      meetings removed; time in office, fees collected, suspended
     *      permits and the all-time filing count added
     */
    public const SCHEMA_VERSION = 2;

    /** Cumulative expiry horizons, in days. */
    private const EXPIRY_WINDOWS = [30, 60, 90];

    /**
     * Permits an office must have issued or refused in the window before its
     * average is drawn, or it can be named the slowest office.
     *
     * A mean over one or two permits is an anecdote, not an office's time.
     * Decided here and sent as `stages.min_reviews` (the old panel's name for
     * it, kept as the wire name), so the screen and the slowest-office
     * sentence cannot disagree about who is drawn. Same number, and the same
     * reason, as the control chart's (Spc::MIN_COMPLETIONS_PER_WEEK).
     */
    public const MIN_REVIEWS_FOR_STAGE = Spc::MIN_COMPLETIONS_PER_WEEK;

    /**
     * How much slower than the average an office must be to be named the
     * slowest: at least a whole working day AND at least a quarter above.
     *
     * The client, 18 October 2026, on the live register's "CHO 0 days"
     * callout: drop it when the slowest office is within a small margin of
     * the others or has 0 days, and name one only when it is meaningfully
     * slower. Both tests, because each alone fails somewhere: a day over a
     * 20-day average is noise, and 25% over a 0.4-day average is two hours.
     */
    public const BOTTLENECK_MIN_DAYS_ABOVE = 1.0;

    public const BOTTLENECK_MIN_SHARE_ABOVE = 0.25;

    /**
     * Days a suspension has to have run before it is "long" — the admin
     * permits register's "Suspended over 30 days" list (PermitsPage
     * SUSPENDED_LONG_DAYS), which the panel links to. Client, 5 October 2026:
     * *"after 30 days unresolved BPLO sees it in a list and may revoke it"*.
     */
    public const SUSPENDED_LONG_DAYS = 30;

    /** Rows in the Suspended Permits list: the oldest few, not the register. */
    private const SUSPENDED_LIST = 5;

    /**
     * Fees Collected's rows, in the order the client listed them
     * (18 October 2026): Business Permit, Sanitary, FSIC, Zoning, Occupancy,
     * CEC, then Other. A permit type the register adds later follows these.
     */
    private const FEE_TYPE_ORDER = ['BUSINESS', 'SANITARY', 'FSIC', 'ZONING', 'OCCUPANCY', 'CEC'];

    /** The row for fee lines that belong to no single permit. */
    public const FEE_OTHER = 'OTHER';

    /**
     * The short name each inspecting office's visit goes by on the panel.
     *
     * NAMES ONLY — this map does not decide who appears. Which offices inspect
     * is read from the register (see inspectionFacts): whoever issues a permit
     * type flagged `requires_inspection`. This used to be the membership list as
     * well, hard-coded to CHO/BFP/CPDO, and the panel silently dropped every
     * inspection carried out by anyone else. When the client made all six
     * supporting clearances inspected, the register grew OBO, CENRO and Market
     * inspections and the panel went on showing three offices — reading as
     * though the other three do not inspect at all.
     *
     * A department with no entry here falls back to its own code rather than to
     * a guess, so an office added later is visibly unmapped instead of
     * mislabelled — the same rule the frontend applies to permit-type headings.
     *
     * The names are the *inspection*, not the office: City Health performs the
     * Sanitary inspection, Fire Protection the Fire Safety one. BPLO is absent
     * on purpose and is the one real exception — the Mayor's Permit is issued on
     * the strength of the six clearances rather than a visit of its own, which
     * is exactly what the client meant by "all 6 (no BPLO)".
     *
     * @var array<string, string>
     */
    private const INSPECTION_LABEL_BY_DEPARTMENT = [
        'CHO' => 'Sanitary',
        'BFP' => 'Fire Safety',
        'OBO' => 'Occupancy',
        'CENRO' => 'Environmental',
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
     * No statistics here — no rates, no means, no ranks. Those are compute()'s
     * job. What travels is counts, per-observation rows, and the rules (statutory
     * targets, window horizons), so the dataset is a self-contained record of the
     * question that was asked and compute() has nothing left to look up.
     *
     * @return array<string, mixed>
     */
    public static function dataset(int $windowMonths = self::DEFAULT_WINDOW_MONTHS, ?string $office = null): array
    {
        /*
         * $office is a department code, or null for every office. It has already
         * been decided by AnalyticsOffice::forRequest() by the time it arrives
         * here; this class does not know who is asking and must not be the place
         * that decides what they may see. It only narrows every fact query to
         * that office's filings, permits, visits and threads — see
         * AnalyticsOffice for what each of those means.
         */
        $scope = AnalyticsOffice::scope($office);
        $now = CarbonImmutable::now();
        /*
         * MANILA DAYS, NOT UTC ONES (ManilaCalendar). `$today` is the Manila
         * date, carried as a Manila-zoned Carbon for date comparisons and
         * printed as the date the City is living in. The `*Start` instants
         * below are where those Manila days and months begin, converted back to
         * UTC so they compare with the stored timestamps.
         *
         * This was `$now->startOfDay()` on the UTC clock. The nightly refresh
         * runs at 03:00 Manila, which is 19:00 UTC the day before, so every
         * stored dashboard was computed for yesterday: on the 1st of the month
         * "This Month" was last month.
         */
        $today = ManilaCalendar::today($now);
        $windowStart = ManilaCalendar::startOfDay($today->subMonths($windowMonths));
        /*
         * Still shipped, no longer used by a figure. `ytd_start` is a date label
         * that stayed behind when the yearly KPI became a full-term one; nothing
         * on screen reads it now, but it is on the published payload and on the
         * frozen fixture, so dropping it is a response-shape change rather than a
         * tidy-up. It goes together with renaming `kpis.applications_ytd` — see
         * kpiFacts() — and that is now a call this codebase can make on its own.
         */
        $ytdStart = $today->startOfYear();
        $monthStart = ManilaCalendar::startOfDay($today->startOfMonth());

        return [
            'params' => $office === null ? ['months' => $windowMonths] : ['months' => $windowMonths, 'office' => $office],
            'now' => $now->toISOString(),
            // Manila dates, all four: the dates the screen prints.
            'today' => $today->toDateString(),
            'window_start' => ManilaCalendar::dateOf($windowStart),
            'ytd_start' => $ytdStart->toDateString(),
            'month_start' => ManilaCalendar::dateOf($monthStart),
            'top_n' => self::TOP_N,
            'expiry_windows' => self::EXPIRY_WINDOWS,
            'map_point_limit' => self::MAP_POINT_LIMIT,

            'kpis' => self::kpiFacts($today, $monthStart, $now, $scope),
            'volume' => self::volumeFacts($monthStart, $now, $scope),
            'decisions' => self::decisionFacts($monthStart, $now, $scope),
            'stage_observations' => self::stageObservations($windowStart, $now, $scope),
            'stage_rules' => self::stageRules(),
            'compliance' => self::complianceFacts($today, $windowStart, $now, $scope),
            'fees' => self::feeFacts($today, $monthStart, $now, $scope),
            'suspended_permits' => self::suspendedFacts($today, $now, $scope),
            'permit_type_columns' => self::permitTypeColumns($scope),
            'expiring_permits' => self::expiringPermits($today, $scope),
            'barangays' => self::barangayFacts($today, $scope),
            'lines_of_business' => self::lineOfBusinessFacts($today, $scope),
            'organization_forms' => self::organizationFormFacts($scope),
            'inspections' => self::inspectionFacts($windowStart, $now, $scope),
            'officer_activity' => self::officerActivityFacts($windowStart, $now, $scope),
            'map' => self::mapFacts($today, $scope),
            'business_movement' => self::movementFacts($windowStart, $now, $scope),
        ];
    }

    /** @return array<string, mixed> */
    public static function build(int $windowMonths = self::DEFAULT_WINDOW_MONTHS, ?string $office = null): array
    {
        return self::compute(self::dataset($windowMonths, $office));
    }

    /* ── office scope ──────────────────────────────────────────────────── */

    /**
     * Narrow a query to filings routed to the office. A no-op for every office.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     */
    private static function routedTo(QueryBuilder $query, ?array $scope, string $idColumn = 'applications.id'): QueryBuilder
    {
        if ($scope === null) {
            return $query;
        }

        /*
         * An uncorrelated IN rather than a correlated EXISTS: assignments are
         * indexed by department and not by application, so EXISTS rescanned
         * them per filing and a scoped dashboard took about two seconds. The IN
         * is evaluated once, off the department index.
         */
        return $query->whereIn($idColumn, DB::table('application_assignments')
            ->select('application_id')
            ->where('department_id', $scope['department_id']));
    }

    /**
     * Narrow a query on a business id column to the office's businesses: those
     * with a filing routed to it, OR holding (or having held) a permit it
     * issues. A no-op for every office.
     *
     * One base for every panel. Active Businesses counted permit holders while
     * the map, the organisation and the new-and-closed panels counted only
     * businesses with a filing routed here, so a permit from the old register
     * (no filing behind it) was active yet off the map. Either link makes a
     * business this office's.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     */
    private static function officeBusinesses(QueryBuilder $query, ?array $scope, string $businessIdColumn): QueryBuilder
    {
        if ($scope === null) {
            return $query;
        }

        return $query->where(static fn (QueryBuilder $q) => $q
            ->whereIn($businessIdColumn, self::routedTo(
                DB::table('applications')->select('applications.business_id'),
                $scope,
            ))
            ->orWhereIn($businessIdColumn, DB::table('permits')
                ->select('permits.business_id')
                ->whereIn('permits.permit_type_id', $scope['permit_type_ids'] === [] ? [0] : $scope['permit_type_ids'])));
    }

    /**
     * Narrow a query on a permit type id column to the types the office issues.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     */
    private static function issuedBy(QueryBuilder $query, ?array $scope, string $permitTypeColumn): QueryBuilder
    {
        if ($scope === null) {
            return $query;
        }

        return $query->whereIn($permitTypeColumn, $scope['permit_type_ids']);
    }

    /**
     * The engine: facts in, dashboard statistics out, no database.
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
            // The payload's shape, so a snapshot of an older one is never served
            // (see SCHEMA_VERSION and AnalyticsResolver).
            'schema_version' => self::SCHEMA_VERSION,
            // Echoed, not re-derived: the frame was fixed when dataset() ran, and
            // compute() has to stay a pure function of its input or the same
            // fixture would give a different answer tomorrow.
            'generated_at' => (string) $dataset['now'],
            'window_months' => (int) $dataset['params']['months'],
            'window_start' => (string) $dataset['window_start'],
            'ytd_start' => (string) $dataset['ytd_start'],
            'month_start' => (string) $dataset['month_start'],
            'today' => (string) $dataset['today'],

            'kpis' => self::computeKpis($dataset['kpis'], $compliance),
            'volume' => self::computeVolume($dataset['volume']),
            'decisions' => $decisions,
            /*
             * `stages` is the wire name the old "Average Processing Time by
             * Department" panel used. The figure under it is Time in Office
             * since 18 October 2026 — see stageObservations().
             */
            'stages' => self::computeStages($dataset['stage_observations'], $dataset['stage_rules']),
            'compliance' => $compliance,
            'fees_collected' => self::computeFees($dataset['fees']),
            'suspended_permits' => self::computeSuspended($dataset['suspended_permits']),
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
            'business_movement' => self::computeMovement($dataset['business_movement']),
        ];
    }

    /* ── facts: KPI cards ──────────────────────────────────────────────── */

    /**
     * THE TILE READS `applications_all_time`; `applications_ytd` STAYS BEHIND.
     *
     * The "Applications (all time)" tile read `applications_ytd`, which counted
     * only filings with a `submitted_at` — 18 on the live register on
     * 18 October 2026, against 26 non-draft filings. The other eight are
     * Approved new filings written without a submission date (the portfolio
     * flow and older seeding), and a tile titled "all time" that leaves out a
     * third of the register is not honest. The client chose the all-time
     * figure over relabelling the tile "this year".
     *
     * `applications_all_time` is every filing that is not a draft, of every
     * type, not removed from the register. A filing with no submission date
     * cannot be put in a month, so This Month and the panels below still count
     * by `submitted_at`; only the full-term count does not need a date.
     *
     * `applications_ytd` is kept, unchanged, because tests and older readers
     * still read it (AnalyticsOfficeScopeTest, DraftDeletionTest). No screen
     * or printed report shows it any more. Despite the name it is not year to
     * date either — it is the full term of submitted filings, below.
     *
     * WHY NOT YEAR TO DATE. The client's words were "do not put YTD only; it
     * should be the full term". Year-to-date made this card the odd one out in
     * its row: Active Businesses and Compliance Rate are both whole-register
     * figures, and This Month sits beside it as the short-window counterpart. A
     * calendar-year cutoff also makes the headline number collapse every 1
     * January, which is exactly when a register's total workload matters most.
     *
     * The upper bound stays. `now` is Laravel's clock for this build, and a
     * filing dated ahead of it is not yet part of the term being reported.
     *
     * ── FILINGS, NOT DRAFTS, AND DATED BY SUBMISSION ─────────────────────────
     *
     * Both counts, like the volume and outcome panels below, are of SUBMITTED
     * filings, dated by `submitted_at`. They counted every row from creation,
     * drafts included — for every office at once only: an office's view never
     * held drafts, because a draft is routed to nobody, so the city total and
     * the sum of its offices disagreed by however many drafts were sitting
     * unsent. A draft is not a filing until it is submitted, and a draft
     * started in September and submitted in October was filed in October.
     *
     * @return array<string, int>
     */
    private static function kpiFacts(
        CarbonImmutable $today,
        CarbonImmutable $monthStart,
        CarbonImmutable $now,
        ?array $scope = null,
    ): array {
        return [
            'active_businesses' => count(self::activeBusinessIds($today, $scope)),
            'applications_all_time' => self::routedTo(DB::table('applications'), $scope)
                ->whereNull('deleted_at')
                ->where('status', '!=', ApplicationStatus::Draft->value)
                ->where(static fn (QueryBuilder $q) => $q
                    ->whereNull('submitted_at')
                    ->orWhere('submitted_at', '<=', $now))
                ->count(),
            'applications_ytd' => self::submitted($scope)
                ->where('submitted_at', '<=', $now)
                ->count(),
            'applications_this_month' => self::submitted($scope)
                ->where('submitted_at', '>=', $monthStart)
                ->where('submitted_at', '<=', $now)
                ->count(),
        ];
    }

    /**
     * Submitted filings, routed to the office when there is one. Never a draft.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     */
    private static function submitted(?array $scope): QueryBuilder
    {
        return self::routedTo(DB::table('applications'), $scope)
            ->whereNull('deleted_at')
            ->whereNotNull('submitted_at');
    }

    /**
     * Businesses holding a permit valid today.
     *
     * Memoised for the pass: four panels need this same set and it is one query.
     *
     * @return list<int>
     */
    private static function activeBusinessIds(CarbonImmutable $today, ?array $scope = null): array
    {
        return self::activeBusinesses($today, $scope)
            ->pluck('permits.business_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The same set as a subquery, for `whereIn(column, …)`.
     *
     * Scoped, "active" means holding a valid permit THIS office issued: a
     * business with a current fire clearance is active to BFP whatever its
     * sanitary permit says.
     *
     * No cache. It used to keep the id list in a function-level static keyed by
     * day, which a long-running process (the queue worker) would have carried
     * past the permits issued later that day; and passing that list inline put
     * one bound parameter per business into every query that used it. A
     * subquery lets the database do both jobs.
     */
    private static function activeBusinesses(CarbonImmutable $today, ?array $scope = null): QueryBuilder
    {
        return self::issuedBy(DB::table('permits'), $scope, 'permits.permit_type_id')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNull('businesses.deleted_at')
            ->where('permits.status', PermitStatus::Active->value)
            ->whereDate('permits.valid_until', '>=', $today->toDateString())
            ->distinct()
            ->select('permits.business_id');
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
    private static function volumeFacts(CarbonImmutable $monthStart, CarbonImmutable $now, ?array $scope = null): array
    {
        // Submitted this month, never a draft — see kpiFacts().
        $counts = self::submitted($scope)
            ->where('submitted_at', '>=', $monthStart)
            ->where('submitted_at', '<=', $now)
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
     * `decisioned` marks the two buckets that belong in the Approval Rate
     * denominator: approved and rejected. Carrying the flag with the fact states the formula's one
     * subtlety — that Pending is excluded — next to the rows it applies to,
     * rather than leaving it as a list of outcome names buried in the arithmetic.
     *
     * @return list<array{outcome: string, label: string, count: int, decisioned: bool}>
     */
    private static function decisionFacts(CarbonImmutable $monthStart, CarbonImmutable $now, ?array $scope = null): array
    {
        /*
         * Scoped, this is still the FILING's outcome, not the office's own
         * clearance decision on it: "of the filings routed to CHO this month,
         * how many were approved". The per-clearance decision lives on
         * application_permit_types and is the Office Performance screen's
         * subject, not this panel's.
         */
        // Submitted this month, never a draft — see kpiFacts(). A draft was in
        // the "pending" bucket below, as a decision nobody had been asked for.
        $counts = self::submitted($scope)
            ->where('submitted_at', '>=', $monthStart)
            ->where('submitted_at', '<=', $now)
            ->groupBy('status')
            ->selectRaw('status, count(*) as c')
            ->pluck('c', 'status');

        $approved = (int) ($counts[ApplicationStatus::Approved->value] ?? 0);
        $returned = (int) ($counts[ApplicationStatus::Returned->value] ?? 0);
        $rejected = (int) ($counts[ApplicationStatus::Rejected->value] ?? 0);

        /*
         * Everything still moving through the workflow. Listed by exclusion so a
         * new status added to the enum lands in Pending rather than vanishing
         * from the panel and quietly breaking the total.
         *
         * Cancelled is still excluded here, and it no longer has a row of its
         * own. Applicants can no longer cancel a filing, so the row read 0 on
         * every dashboard; the client took it off on 18 October 2026. A
         * cancelled filing from before that is not pending either — it ended —
         * so it is left out of every bucket rather than swelling Pending.
         */
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

        /*
         * RETURNED IS NOT A DECISION. A returned filing is back with the
         * applicant to fix and resubmit; nobody has decided it, and it comes
         * back for the decision it is still owed. Counting it beside approved
         * and rejected in the approval rate's denominator read every return as
         * a refusal. It keeps its own row, so the reader can see how many are
         * out with applicants, and is left out of the rate like Pending.
         */
        return [
            ['outcome' => 'approved', 'label' => 'Approved', 'count' => $approved, 'decisioned' => true],
            ['outcome' => 'returned', 'label' => 'Returned for revision', 'count' => $returned, 'decisioned' => false],
            ['outcome' => 'rejected', 'label' => 'Rejected', 'count' => $rejected, 'decisioned' => true],
            ['outcome' => 'pending', 'label' => 'Pending', 'count' => $pending, 'decisioned' => false],
        ];
    }

    /* ── facts: time in office ─────────────────────────────────────────── */

    /**
     * One row per permit an office issued or refused in the window, with how
     * many working days it was with that office.
     *
     * ── WHY THIS REPLACED "AVERAGE PROCESSING TIME BY DEPARTMENT" ───────────
     *
     * The old panel timed `application_assignments`, from `assigned_at` to
     * `completed_at`. Since the September flow those rows are stamped as the
     * filing is routed and closed at the review step, not when the permit is
     * issued, so on the live register every office read 0 days and the panel
     * named "CHO 0 days" the bottleneck (18 October 2026). The office's real
     * time — reading the form, waiting for the visit, the visit, issuing — was
     * outside it. The client asked for time in office instead: *"from the
     * moment the office received the clearance to the moment that office
     * issued or refused it — so the review AND the inspection wait are both
     * inside."*
     *
     * ── WHAT IS MEASURED ────────────────────────────────────────────────────
     *
     * Each permit keeps its own status history since 26 September 2026
     * (`application_status_history` rows with a `permit_type_id`). A round
     * starts when the permit moves to For Approval — the office has the
     * applicant's form or upload — and ends at its first Approved or Rejected
     * after that. For Inspection is inside the round: the wait for the visit
     * and the visit are the office's time. A permit refused and applied for
     * again is a second round.
     *
     * Taken out, because they are the applicant's time and not the office's:
     * a permit sent back by its office (Returned, until it comes back For
     * Approval), and the filing's own Pending Payment and Returned stretches
     * (FilingClock::WITH_APPLICANT). The second matters for BPLO: the Business
     * Permit's row goes For Approval at submission and Approved at payment, so
     * without it BPLO would be charged for the days the applicant took to pay.
     * With it, BPLO's figure is its own desk time and BPLO is drawn beside the
     * other five — it was set aside on the old panel because its assignment
     * was stamped a second time at the final approval.
     *
     * Working days (ManilaCalendar), as everywhere RA 11032 is near: weekends
     * are not counted, holidays are not on the register and count as working
     * days. A permit issued the day it arrived is 0 days.
     *
     * Permits with no history of their own — everything decided before
     * 26 September 2026 — are left out rather than read as zero. So is a
     * filing or business removed from the register.
     *
     * @return list<array{code: string, name: string, days: int}>
     */
    private static function stageObservations(CarbonImmutable $windowStart, CarbonImmutable $now, ?array $scope = null): array
    {
        $decidedHere = DB::table('application_status_history')
            ->select('application_id')
            ->whereNotNull('permit_type_id')
            ->whereIn('to_status', [ClearanceStatus::Approved->value, ClearanceStatus::Rejected->value])
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $now);

        $live = DB::table('applications')
            ->join('businesses', 'businesses.id', '=', 'applications.business_id')
            ->whereNull('applications.deleted_at')
            ->whereNull('businesses.deleted_at')
            ->whereIn('applications.id', $decidedHere)
            ->select('applications.id');

        $rows = DB::table('application_status_history')
            ->join('permit_types', 'permit_types.id', '=', 'application_status_history.permit_type_id')
            ->join('departments', 'departments.id', '=', 'permit_types.issuing_department_id')
            ->whereIn('application_status_history.application_id', $live)
            ->when($scope !== null, static fn ($q) => $q->whereIn(
                'application_status_history.permit_type_id',
                $scope['permit_type_ids'] === [] ? [0] : $scope['permit_type_ids'],
            ))
            ->whereNotNull('application_status_history.created_at')
            ->orderBy('application_status_history.application_id')
            ->orderBy('application_status_history.permit_type_id')
            ->orderBy('application_status_history.created_at')
            ->orderBy('application_status_history.id')
            ->get([
                'application_status_history.application_id',
                'application_status_history.permit_type_id',
                'application_status_history.to_status',
                'application_status_history.created_at',
                'departments.code',
                'departments.name',
            ]);

        // The filing's own stretches with the applicant, per filing.
        $filingAway = [];
        $filingRows = DB::table('application_status_history')
            ->whereIn('application_id', $live)
            ->whereNull('permit_type_id')
            ->whereNotNull('created_at')
            ->orderBy('application_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['application_id', 'to_status', 'created_at']);
        $open = [];
        foreach ($filingRows as $row) {
            $id = (int) $row->application_id;
            $at = CarbonImmutable::parse($row->created_at);
            if (isset($open[$id])) {
                $filingAway[$id][] = [$open[$id], $at];
                unset($open[$id]);
            }
            if (in_array((string) $row->to_status, FilingClock::WITH_APPLICANT, true)) {
                $open[$id] = $at;
            }
        }
        foreach ($open as $id => $since) {
            $filingAway[$id][] = [$since, null];
        }

        $byPermit = [];
        foreach ($rows as $row) {
            $byPermit[$row->application_id.'|'.$row->permit_type_id][] = $row;
        }

        $observations = [];
        foreach ($byPermit as $history) {
            $filingId = (int) $history[0]->application_id;
            $start = null;
            $returnedSince = null;
            $returns = [];

            foreach ($history as $row) {
                $status = (string) $row->to_status;
                $at = CarbonImmutable::parse($row->created_at);

                if ($status === ClearanceStatus::ForApproval->value) {
                    if ($start === null) {
                        $start = $at;           // the office has it
                    } elseif ($returnedSince !== null) {
                        $returns[] = [$returnedSince, $at];
                        $returnedSince = null;  // back from the applicant
                    }

                    continue;
                }

                if ($start === null) {
                    continue;
                }

                if ($status === ClearanceStatus::Returned->value) {
                    $returnedSince ??= $at;

                    continue;
                }

                if (in_array($status, [ClearanceStatus::Approved->value, ClearanceStatus::Rejected->value], true)) {
                    if ($at >= $windowStart && $at <= $now) {
                        $away = self::mergeStretches(array_merge($returns, $filingAway[$filingId] ?? []), $at);
                        $observations[] = [
                            'code' => (string) $row->code,
                            'name' => (string) $row->name,
                            'days' => ManilaCalendar::workingDaysOutside($start, $at, $away),
                        ];
                    }
                    // A refusal applied for again is a new round.
                    $start = null;
                    $returnedSince = null;
                    $returns = [];
                }
            }
        }

        // A stable order, so a frozen dataset is byte-stable.
        usort($observations, static fn (array $a, array $b) => [$a['code'], $a['days']] <=> [$b['code'], $b['days']]);

        return $observations;
    }

    /**
     * Overlapping [start, end) stretches folded into disjoint ones, as
     * ManilaCalendar::workingDaysOutside requires. A permit's own return and
     * its filing's return can overlap; subtracting both would take the same
     * day off twice. A null end runs to $end.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $stretches
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function mergeStretches(array $stretches, CarbonImmutable $end): array
    {
        $closed = array_map(static fn (array $s): array => [$s[0], $s[1] ?? $end], $stretches);
        usort($closed, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($closed as [$from, $to]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [$from, $to];
            }
        }

        return $merged;
    }

    /**
     * The Time in Office rules: who is drawn, and when an office is slow
     * enough to name. They travel with the facts so a stored snapshot records
     * the rule its sentence was judged by.
     *
     * @return array{min_reviews: int, bottleneck_min_days_above: float, bottleneck_min_share_above: float}
     */
    private static function stageRules(): array
    {
        return [
            'min_reviews' => self::MIN_REVIEWS_FOR_STAGE,
            'bottleneck_min_days_above' => self::BOTTLENECK_MIN_DAYS_ABOVE,
            'bottleneck_min_share_above' => self::BOTTLENECK_MIN_SHARE_ABOVE,
        ];
    }

    /* ── facts: the compliance indicators ──────────────────────────────── */

    /**
     * Numerator and denominator for each indicator in spec §1.
     *
     * Only the two counts travel; compute() does the division. That is the point:
     * the facts carry no rate, so an indicator with an empty denominator arrives
     * as "nothing to divide" and comes out null. A rate computed in SQL would
     * have to decide what to do about a zero denominator down in a query, where
     * the easy answer is a zero — and a zero is a claim, not an absence.
     *
     * Two indicators since 18 October 2026. The third, "RA 11032 processing",
     * judged each decided filing against the limit for its complexity tier,
     * and BPLO has not set a tier since 27 September (`canSetTier` false), so
     * the row measured a column nobody fills. The client took it off with the
     * tier panel it was derived from. It would come back with a tier someone
     * sets — FilingClock still times filings for the Reports tab.
     *
     * @return list<array{indicator: string, label: string, numerator: int, denominator: int, numerator_label: string, denominator_label: string}>
     */
    private static function complianceFacts(
        CarbonImmutable $today,
        CarbonImmutable $windowStart,
        CarbonImmutable $now,
        ?array $scope = null,
    ): array {
        return [
            self::permitValidityCompliance($today, $scope),
            self::renewalCompliance($windowStart, $now, $scope),
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
    private static function permitValidityCompliance(CarbonImmutable $today, ?array $scope = null): array
    {
        // Scoped, only the types this office issues are tested: CHO's compliance
        // is whether its sanitary permits are current, not whether BFP's are.
        $rows = self::issuedBy(DB::table('permits'), $scope, 'permits.permit_type_id')
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
     * ── WHICH RENEWAL RENEWS WHICH PERMIT ───────────────────────────────────
     *
     * This matched a renewal to a permit by `applications.prior_permit_id`
     * alone. A renewal filing carries one prior permit — in practice the
     * business permit — and the clearances riding on the same filing have no
     * link of their own, so the five clearance offices could never be credited
     * with a renewal. Their dashboards read "Renewal compliance" blank whatever
     * their businesses did.
     *
     * A permit is now renewed by a submitted renewal filing of the SAME
     * BUSINESS that carries the SAME PERMIT TYPE (`application_permit_types`),
     * filed during the permit's term — on or after its first valid day, and
     * not the filing that produced it. On time is filed on or before its
     * expiry date, both read in Manila. `prior_permit_id`, where it is set,
     * still links its permit directly. Never-submitted drafts are not renewals.
     *
     * ── THE DENOMINATOR STAYS COMMENSURABLE WITH THE NUMERATOR ───────────────
     *
     * Only the permit types renewals are actually filed for are due: the types
     * any submitted renewal filing carries, derived from the register. The old
     * reason for narrowing it — a prior-permit link could only ever credit one
     * permit per filing, so counting every type due built a denominator the
     * numerator could not reach (21.2% that was an arithmetic artefact) — is
     * answered by matching on the type instead, so every type a renewal
     * carries can now be credited.
     *
     * THE FAILURE MODE THIS STILL GUARDS. When permits fell due but not one of
     * them has a renewal matched to it, the indicator declines to compute and
     * says why, rather than reading 0% — which would claim that not one
     * business renewed on time, when it may be the register that cannot see
     * them.
     *
     * @return array{indicator: string, label: string, numerator: int, denominator: int, numerator_label: string, denominator_label: string, unavailable_reason?: string}
     */
    private static function renewalCompliance(CarbonImmutable $windowStart, CarbonImmutable $now, ?array $scope = null): array
    {
        $renewalFilings = static fn () => DB::table('applications')
            ->whereNull('applications.deleted_at')
            ->where('applications.application_type', ApplicationType::Renewal->value)
            ->whereNotNull('applications.submitted_at');

        $renewablePermitTypes = DB::table('application_permit_types')
            ->whereIn('application_id', $renewalFilings()->select('applications.id'))
            ->distinct()
            ->pluck('permit_type_id')
            ->merge($renewalFilings()
                ->join('permits', 'permits.id', '=', 'applications.prior_permit_id')
                ->distinct()
                ->pluck('permits.permit_type_id'))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        /*
         * Scoped, the office's own types among those. An office none of whose
         * types is ever renewed has nothing due here, and the indicator says
         * "nothing in the denominator" rather than borrowing the city's figure.
         */
        if ($scope !== null) {
            $renewablePermitTypes = array_values(array_intersect($renewablePermitTypes, $scope['permit_type_ids']));
        }

        $due = DB::table('permits')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNull('businesses.deleted_at')
            ->whereIn('permits.permit_type_id', $renewablePermitTypes === [] ? [0] : $renewablePermitTypes)
            // Manila dates: valid_until is a date the permit printed, and the
            // window runs from the Manila date it opened to today in Manila.
            ->whereDate('permits.valid_until', '>=', ManilaCalendar::dateOf($windowStart))
            ->whereDate('permits.valid_until', '<=', ManilaCalendar::dateOf($now));

        $permits = (clone $due)->get([
            'permits.id', 'permits.business_id', 'permits.permit_type_id',
            'permits.application_id', 'permits.valid_from', 'permits.valid_until',
        ]);

        $fact = [
            'indicator' => 'renewal',
            'label' => 'Renewal compliance',
            'numerator' => 0,
            'denominator' => $permits->count(),
            'numerator_label' => 'renewed before expiry',
            'denominator_label' => 'permits due for renewal',
        ];

        if ($permits->isEmpty()) {
            return $fact;
        }

        /*
         * Every submitted renewal of these businesses, once per permit type it
         * carries, plus the permit it names as prior, if any. Subqueries, not
         * id lists: a three-year window on a full register is thousands of
         * permits, and an inline IN list of that size is a statement some
         * drivers refuse.
         */
        $renewals = $renewalFilings()
            ->whereIn('applications.business_id', (clone $due)->select('permits.business_id'))
            ->leftJoin('application_permit_types', 'application_permit_types.application_id', '=', 'applications.id')
            ->get([
                'applications.id', 'applications.business_id', 'applications.prior_permit_id',
                'applications.submitted_at', 'application_permit_types.permit_type_id',
            ]);

        $byType = [];
        $byPrior = [];
        foreach ($renewals as $renewal) {
            $filed = ['id' => (int) $renewal->id, 'date' => ManilaCalendar::dateOf($renewal->submitted_at)];
            if ($renewal->permit_type_id !== null) {
                $byType[$renewal->business_id.'|'.$renewal->permit_type_id][] = $filed;
            }
            if ($renewal->prior_permit_id !== null) {
                $byPrior[(int) $renewal->prior_permit_id][] = $filed;
            }
        }

        $onTime = 0;
        $linked = 0;
        foreach ($permits as $permit) {
            $from = CarbonImmutable::parse($permit->valid_from)->toDateString();
            $until = CarbonImmutable::parse($permit->valid_until)->toDateString();

            $earliest = null;
            foreach ($byType[$permit->business_id.'|'.$permit->permit_type_id] ?? [] as $filed) {
                // Filed during this permit's term, and not the filing that produced it.
                if ($filed['date'] >= $from && $filed['id'] !== (int) $permit->application_id) {
                    $earliest = $earliest === null ? $filed['date'] : min($earliest, $filed['date']);
                }
            }
            foreach ($byPrior[(int) $permit->id] ?? [] as $filed) {
                $earliest = $earliest === null ? $filed['date'] : min($earliest, $filed['date']);
            }

            if ($earliest !== null) {
                $linked++;
                if ($earliest <= $until) {
                    $onTime++;
                }
            }
        }

        $fact['numerator'] = $onTime;

        if ($linked === 0) {
            $fact['unavailable_reason'] = 'No renewal filing in this window can be matched to a permit that fell due, '
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
    private static function permitTypeColumns(?array $scope = null): array
    {
        return self::issuedBy(DB::table('permit_types'), $scope, 'permit_types.id')
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
     * The expiry table's rows: one per permit still in force (with its signed
     * days to expiry), and one per business that has let a permit type lapse.
     *
     * The buckets are cumulative. Emitting per-permit rows rather than
     * pre-bucketed counts is what lets the cumulative nesting (30 ⊂ 60 ⊂ 90) be
     * a computation compute() performs, visibly and testably, instead of an
     * assumption baked into a SQL `case` nobody re-reads.
     *
     * ── "Already expired" is businesses, not permit rows ────────────────────
     *
     * The Expired row counted every permit on the register whose date had
     * passed. A business that has renewed every year for five years holds four
     * expired permits and one valid one, and was counted four times as
     * "expired" while trading lawfully. BPLO's row read 767 on the copy of the
     * register this was measured on, against 147 businesses actually lapsed.
     *
     * So the expired rows are now one per (business, permit type) whose LATEST
     * permit of that type has run out and which holds no permit of that type
     * in force today. A latest permit that was revoked or suspended is left
     * out: that business did not lapse, it was stopped, and the definition has
     * always excluded revoked permits. `business_id` travels so compute() can
     * also say how many distinct businesses that is (`businesses`).
     *
     * @return list<array{code: string, days_to_expiry: int, business_id: int}>
     */
    private static function expiringPermits(CarbonImmutable $today, ?array $scope = null): array
    {
        $rows = self::issuedBy(DB::table('permits'), $scope, 'permits.permit_type_id')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNull('businesses.deleted_at')
            // Each business's permits of one type together, oldest to latest.
            ->orderBy('permits.business_id')
            ->orderBy('permits.permit_type_id')
            ->orderBy('permits.valid_until')
            ->orderBy('permits.id')
            ->get(['permits.business_id', 'permits.permit_type_id', 'permit_types.code', 'permits.status', 'permits.valid_until']);

        $todayDate = $today->toDateString();
        $live = [PermitStatus::Active->value, PermitStatus::Expired->value];

        $out = [];
        $groups = [];
        foreach ($rows as $row) {
            $days = ManilaCalendar::daysBetweenDates(
                $todayDate,
                // Date to date: today is a Manila date and valid_until is the
                // date printed on the permit.
                CarbonImmutable::parse($row->valid_until)->toDateString(),
            );

            // Still in force, or due: one row per permit, as before.
            if (in_array((string) $row->status, $live, true) && $days >= 0) {
                $out[] = ['code' => (string) $row->code, 'days_to_expiry' => $days, 'business_id' => (int) $row->business_id];
            }

            $key = $row->business_id.'|'.$row->permit_type_id;
            $groups[$key]['code'] = (string) $row->code;
            $groups[$key]['business_id'] = (int) $row->business_id;
            // Ordered by valid_until, so the last one written is the latest.
            $groups[$key]['latest'] = ['status' => (string) $row->status, 'days' => $days];
            if ($row->status === PermitStatus::Active->value && $days >= 0) {
                $groups[$key]['in_force'] = true;
            }
        }

        foreach ($groups as $group) {
            $latest = $group['latest'];
            if (! isset($group['in_force']) && $latest['days'] < 0 && in_array($latest['status'], $live, true)) {
                $out[] = ['code' => $group['code'], 'days_to_expiry' => $latest['days'], 'business_id' => $group['business_id']];
            }
        }

        return $out;
    }

    /* ── facts: rankings ──────────────────────────────────────────────── */

    /**
     * Active businesses per barangay.
     *
     * @return list<array{barangay: string, count: int}>
     */
    private static function barangayFacts(CarbonImmutable $today, ?array $scope = null): array
    {
        return DB::table('business_addresses')
            ->join('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
            ->whereIn('business_addresses.business_id', self::activeBusinesses($today, $scope))
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
    private static function lineOfBusinessFacts(CarbonImmutable $today, ?array $scope = null): array
    {
        return DB::table('business_lines')
            ->join('psic_codes', 'psic_codes.id', '=', 'business_lines.psic_code_id')
            ->whereIn('business_lines.business_id', self::activeBusinesses($today, $scope))
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
    private static function organizationFormFacts(?array $scope = null): array
    {
        $counts = self::officeBusinesses(DB::table('businesses'), $scope, 'businesses.id')
            ->whereNull('deleted_at')
            ->whereNotNull('form_of_organization')
            ->groupBy('form_of_organization')
            ->selectRaw('form_of_organization, count(*) as c')
            ->pluck('c', 'form_of_organization');

        $total = self::officeBusinesses(DB::table('businesses'), $scope, 'businesses.id')
            ->whereNull('deleted_at')
            ->count();

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
     * Per inspecting office: scheduled, completed, and the completed breakdown.
     *
     * WHO APPEARS IS READ FROM THE REGISTER, NOT LISTED HERE.
     *
     * An office inspects if it issues a permit type flagged
     * `requires_inspection` — that flag is the same one WorkflowService uses to
     * decide whether a clearance needs a visit, so the panel cannot disagree
     * with the queue officers actually work. All six supporting clearances carry
     * it; only the Mayor's Permit (BPLO) does not, so BPLO is absent without
     * being named absent anywhere.
     *
     * Every such office is emitted even with nothing to report, because an
     * office omitted from this panel reads as an office that does not inspect.
     * A row of zeros says "no visits yet"; no row at all says something false.
     * This is the same rule the volume panel follows for a transaction type
     * nobody filed this month.
     *
     * An office that has inspections on record but no longer issues an inspected
     * permit type still gets a bucket. Dropping its rows would quietly shrink
     * the combined total and make the overall pass rate a figure over a subset
     * the reader was never told about — which is precisely the bug this replaces.
     *
     * @return list<array{type: string, label: string, scheduled: int, completed: int, passed: int, failed: int, conditional: int}>
     */
    private static function inspectionFacts(CarbonImmutable $windowStart, CarbonImmutable $now, ?array $scope = null): array
    {
        $buckets = [];

        /** Open a bucket for an office the first time it is named, in the order named. */
        $open = static function (string $code) use (&$buckets): void {
            $buckets[$code] ??= [
                'type' => $code,
                'label' => self::INSPECTION_LABEL_BY_DEPARTMENT[$code] ?? $code,
                'scheduled' => 0,
                'completed' => 0,
                'passed' => 0,
                'failed' => 0,
                'conditional' => 0,
            ];
        };

        /*
         * Ordered by permit type so the panel reads in the order the register
         * lists the clearances, rather than alphabetically by an office code no
         * applicant has ever seen. It is only a display order — nothing is
         * computed from it — but it has to be *some* deterministic order, or the
         * bars reshuffle between refreshes and the chart stops being comparable
         * against yesterday's screenshot.
         */
        $inspectingOffices = DB::table('permit_types')
            ->join('departments', 'departments.id', '=', 'permit_types.issuing_department_id')
            ->where('permit_types.requires_inspection', true)
            ->when($scope !== null, static fn ($q) => $q->where('departments.id', $scope['department_id']))
            ->orderBy('permit_types.id')
            ->pluck('departments.code');

        foreach ($inspectingOffices as $code) {
            $open((string) $code);
        }

        $rows = DB::table('inspections')
            ->join('departments', 'departments.id', '=', 'inspections.department_id')
            ->join('applications', 'applications.id', '=', 'inspections.application_id')
            ->whereNull('applications.deleted_at')
            ->where('inspections.created_at', '>=', $windowStart)
            ->where('inspections.created_at', '<=', $now)
            ->when($scope !== null, static fn ($q) => $q->where('inspections.department_id', $scope['department_id']))
            ->orderBy('departments.code')
            ->get(['departments.code', 'inspections.status', 'inspections.result']);

        foreach ($rows as $row) {
            $code = (string) $row->code;
            $open($code);

            // A cancelled visit was never going to happen, so it is not part of
            // the work: counted as scheduled, it read as backlog forever (the
            // gap between scheduled and completed is what this panel calls the
            // backlog). A rescheduled one stays: rescheduling moves the same
            // visit to a new date (InspectionController::reschedule), and it is
            // still owed.
            if ((string) $row->status === InspectionStatus::Cancelled->value) {
                continue;
            }

            // Every other inspection on record counts as scheduled, so
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

    /* ── facts: messages and requests ──────────────────────────────────── */

    /**
     * Conversations waiting on an office, and requests raised and fulfilled.
     *
     * ── WHAT WENT, AND WHY THE PANEL STAYED ─────────────────────────────────
     *
     * This was "Officer Activity" and also timed officer replies and counted
     * meetings. On the live register on 18 October 2026 it read 0 replies, no
     * response time and 0 meetings: there is no meetings feature at all (the
     * figure was AnalyticsHistorySeeder's rows read back as attendance), and
     * applicants and offices barely use the message threads for replies. The
     * client asked for the metrics nothing records to go. Both went, from the
     * facts as well as the screen — the `meetings_*` keys this payload carried
     * "only to hold the published shape" went with them, since the schema
     * version (SCHEMA_VERSION) now retires old snapshots.
     *
     * What is left is worth a slim panel, "Messages & Requests": officer
     * requests ARE used (seven raised, one fulfilled, on the live register),
     * and a thread whose last word is the applicant's is an unanswered
     * question someone at the office owns.
     *
     * A thread is awaiting a reply when an applicant's message in the window
     * has had no message from anyone else after it.
     *
     * @return array{threads_awaiting_reply: int, requests: array{total: int, fulfilled: int}}
     */
    private static function officerActivityFacts(CarbonImmutable $windowStart, CarbonImmutable $now, ?array $scope = null): array
    {
        // Scoped, the office's own threads and its own requests.
        $messages = DB::table('messages')
            ->join('message_threads', 'message_threads.id', '=', 'messages.thread_id')
            ->when($scope !== null, static fn ($q) => $q->where('message_threads.department_id', $scope['department_id']))
            ->where('messages.created_at', '>=', $windowStart)
            ->where('messages.created_at', '<=', $now)
            ->orderBy('messages.thread_id')
            ->orderBy('messages.created_at')
            ->orderBy('messages.id')
            ->get(['messages.thread_id', 'messages.sender_user_id']);

        $applicants = DB::table('applications')
            ->whereNull('applications.deleted_at')
            ->join('message_threads', 'message_threads.application_id', '=', 'applications.id')
            ->pluck('applications.applicant_user_id', 'message_threads.id');

        $awaiting = [];
        foreach ($messages as $message) {
            $threadId = (int) $message->thread_id;
            $applicantId = isset($applicants[$threadId]) ? (int) $applicants[$threadId] : null;

            if ($applicantId !== null && (int) $message->sender_user_id === $applicantId) {
                $awaiting[$threadId] = true;
            } else {
                unset($awaiting[$threadId]);
            }
        }

        $requests = DB::table('officer_requests')
            ->when($scope !== null, static fn ($q) => $q->where('department_id', $scope['department_id']))
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $now);

        return [
            'threads_awaiting_reply' => count($awaiting),
            'requests' => [
                'total' => (clone $requests)->count(),
                'fulfilled' => (clone $requests)->where('status', OfficerRequestStatus::Fulfilled->value)->count(),
            ],
        ];
    }

    /* ── facts: new and closed businesses ──────────────────────────────── */

    /**
     * Real openings and real closures per month across the trailing window.
     *
     * Moved here from Business Growth Analysis when that screen was removed
     * (checklist 2026-09-27, item 6).
     *
     * ── WHAT WAS WRONG (18 October 2026) ────────────────────────────────────
     *
     * October read 34 registered and 22 closed while the register held 24
     * businesses (46 counting removed ones). Registered counted every
     * `businesses` row created in the month, and closed counted every soft
     * delete: test businesses created and removed the same week were counted
     * twice over — once in, once out. A `businesses` row is created when an
     * owner STARTS a draft (and by the portfolio and import tools), so its
     * creation date is not an opening; and removing a record is housekeeping,
     * not a business ceasing to trade. The client: count only real openings
     * and closures, and a removed business in neither.
     *
     * ── OPENED ──────────────────────────────────────────────────────────────
     *
     * A business opens in the month of its first SUBMITTED filing (any type,
     * not removed) — the day it actually applied to the City — or of its
     * first permit if that is earlier, which is how a business carried over
     * from the old register (LegacyImporter: permits, no filings) opens: on
     * the date its oldest permit was issued, or became valid where the old
     * register gave no issue date. A business with neither has applied for
     * nothing, so it has not opened; one removed from the register is left
     * out whatever it holds.
     *
     * The rejected alternative was "created that month, not removed, with at
     * least one submitted filing". It still dates the opening by when the row
     * was typed rather than when anything was filed, so a draft started in
     * August and submitted in October opened in August.
     *
     * ── CLOSED ──────────────────────────────────────────────────────────────
     *
     * Two things close a business, both on a business still on the register:
     *
     *  - BPLO retiring its Mayor's Permit — PermitStatus::Retired, "the
     *    business stopped operating" (client, 5 October 2026). Dated by the
     *    permit's `updated_at`: Retired is final (WorkflowService::movableFrom
     *    offers no way out of it), so the last write to the row is the
     *    retirement. A business retired twice closes once, on the later date.
     *  - A blacklisting: the business's own status (`status_changed_at`), or
     *    since 5 October 2026 its owner's (`users.blacklisted_at`), which
     *    suspends the businesses rather than relabelling them. A blacklisting
     *    with no recorded date cannot be put in a month and is left out.
     *
     * Keyed by business, so a business both retired and blacklisted closes
     * once. Suspension is temporary and is not a closure. A soft delete is
     * not one either any more — see above.
     *
     * @return list<array{month: string, registered: int, closed: int, partial: bool}>
     */
    private static function movementFacts(CarbonImmutable $windowStart, CarbonImmutable $now, ?array $scope = null): array
    {
        // Manila months: a business registered at 7 am Manila on the 1st is
        // that month's, not the previous month's as the UTC clock had it.
        $buckets = [];
        $cursor = ManilaCalendar::local($windowStart)->startOfMonth();
        $lastMonth = ManilaCalendar::monthOf($now);
        while ($cursor->format('Y-m') <= $lastMonth) {
            // The current month is still running: its count is not a drop, it
            // is a month not over yet. Flagged so the chart can say so instead
            // of ending every line in a cliff to zero.
            $buckets[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'registered' => 0,
                'closed' => 0,
                'partial' => $cursor->format('Y-m') === $lastMonth,
            ];
            $cursor = $cursor->addMonth();
        }

        // Scoped, the office's businesses (officeBusinesses): a filing routed
        // to it, or a permit it issued. Never a removed one.
        $live = static fn () => self::officeBusinesses(DB::table('businesses'), $scope, 'businesses.id')
            ->whereNull('businesses.deleted_at');

        $firstFiling = DB::table('applications')
            ->whereIn('applications.business_id', $live()->select('businesses.id'))
            ->whereNull('applications.deleted_at')
            ->whereNotNull('applications.submitted_at')
            ->groupBy('applications.business_id')
            ->selectRaw('applications.business_id, min(applications.submitted_at) as first_at')
            ->pluck('first_at', 'business_id');

        $firstPermit = DB::table('permits')
            ->whereIn('permits.business_id', $live()->select('businesses.id'))
            ->groupBy('permits.business_id')
            ->selectRaw('permits.business_id, min(coalesce(permits.issued_at, permits.valid_from)) as first_at')
            ->pluck('first_at', 'business_id');

        $opened = [];
        foreach ([$firstFiling, $firstPermit] as $firsts) {
            foreach ($firsts as $businessId => $at) {
                if ($at === null) {
                    continue;
                }
                $at = CarbonImmutable::parse($at);
                $opened[$businessId] = isset($opened[$businessId]) ? min($opened[$businessId], $at) : $at;
            }
        }

        $retired = DB::table('permits')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->whereIn('permits.business_id', $live()->select('businesses.id'))
            ->where('permit_types.code', PermitType::OUTCOME_CODE)
            ->where('permits.status', PermitStatus::Retired->value)
            ->where('permits.updated_at', '>=', $windowStart)
            ->where('permits.updated_at', '<=', $now)
            ->groupBy('permits.business_id')
            ->selectRaw('permits.business_id, max(permits.updated_at) as closed_at')
            ->pluck('closed_at', 'business_id');

        $blacklisted = $live()
            ->where('businesses.status', Business::STATUS_BLACKLISTED)
            ->whereNotNull('businesses.status_changed_at')
            ->where('businesses.status_changed_at', '>=', $windowStart)
            ->where('businesses.status_changed_at', '<=', $now)
            ->pluck('businesses.status_changed_at', 'businesses.id');

        $ownerBarred = $live()
            ->join('users', 'users.id', '=', 'businesses.owner_user_id')
            ->whereNotNull('users.blacklisted_at')
            ->where('users.blacklisted_at', '>=', $windowStart)
            ->where('users.blacklisted_at', '<=', $now)
            ->pluck('users.blacklisted_at', 'businesses.id');

        // One closure per business: the owner's date over the business's own
        // label, either over a retirement.
        $closed = $ownerBarred->union($blacklisted)->union($retired)->values();

        foreach ($opened as $at) {
            $month = ManilaCalendar::monthOf($at);
            if ($at >= $windowStart && $at <= $now && isset($buckets[$month])) {
                $buckets[$month]['registered']++;
            }
        }
        foreach ($closed as $at) {
            $month = ManilaCalendar::monthOf($at);
            if (isset($buckets[$month])) {
                $buckets[$month]['closed']++;
            }
        }

        return array_values($buckets);
    }

    /* ── facts: fees collected ─────────────────────────────────────────── */

    /**
     * Money received this month and this year, by the permit it was for.
     *
     * Added 18 October 2026 (client's picker, item A). Calendar windows in
     * Manila — the 1st of this month and 1 January — not the trailing window:
     * collections are reported to the Treasurer by month and by year.
     *
     * ── WHAT COUNTS AS COLLECTED ────────────────────────────────────────────
     *
     * A payment with status Completed, dated by `paid_at`. Pending, failed,
     * refunded and set-aside payments are money nobody has. A payment on a
     * filing or business removed from the register is left out, as removed
     * rows are everywhere on this screen.
     *
     * ── WHICH PERMIT A PESO WAS FOR ─────────────────────────────────────────
     *
     * A payment settles a fee assessment, and the assessment's line items say
     * what each peso was for. A payment is spread over its lines in
     * proportion (an instalment pays a share of every line), the rule
     * LguReports' collections report uses, so the two cannot disagree.
     *
     *  - A line from the fee engine names its permit (`permit_codes`, from the
     *    fee rule). One permit: that permit's row. Business tax, community
     *    tax and the garbage fee are billed on the Business Permit and land
     *    on it.
     *  - A deferred fee folded into a Business Permit bill carries no code,
     *    only its label: "Sanitary Permit fee (June 2026, unbilled until
     *    now)" (WorkflowService::deferredFeeLines). It is the Sanitary
     *    Permit's money, so it is matched to its permit by that name — not
     *    left on the Business Permit bill it happened to ride on.
     *  - Everything else — late-renewal surcharges and interest, penalty lines,
     *    an amendment's flat fee, a line naming several permits — is Other.
     *
     * Scoped to one office, only the permits it issues: CHO sees Sanitary,
     * BPLO the Business Permit. Other belongs to no office and is not shown.
     *
     * @return array{month_start: string, year_start: string, types: list<array{code: string, label: string}>, amounts: array<string, array{month: float, year: float}>, payments: array{month: int, year: int}}
     */
    private static function feeFacts(CarbonImmutable $today, CarbonImmutable $monthStart, CarbonImmutable $now, ?array $scope = null): array
    {
        $yearStart = ManilaCalendar::startOfDay($today->startOfYear());

        $register = DB::table('permit_types')->orderBy('id')->get(['id', 'code', 'name']);
        $codeByName = [];
        $known = [];
        foreach ($register as $type) {
            $known[(string) $type->code] = (string) $type->name;
            $codeByName[mb_strtolower((string) $type->name)] = (string) $type->code;
        }

        $order = array_merge(
            array_values(array_intersect(self::FEE_TYPE_ORDER, array_keys($known))),
            array_values(array_diff(array_keys($known), self::FEE_TYPE_ORDER)),
        );
        if ($scope !== null) {
            $mine = $register->whereIn('id', $scope['permit_type_ids'])->pluck('code')->map(static fn ($c): string => (string) $c)->all();
            $order = array_values(array_intersect($order, $mine));
        }

        $types = array_map(static fn (string $code): array => ['code' => $code, 'label' => $known[$code]], $order);
        if ($scope === null) {
            $types[] = ['code' => self::FEE_OTHER, 'label' => 'Other'];
        }
        $shown = array_column($types, 'code');

        $amounts = array_fill_keys($shown, ['month' => 0.0, 'year' => 0.0]);
        $payments = ['month' => 0, 'year' => 0];

        $rows = DB::table('payments')
            ->join('fee_assessments', 'fee_assessments.id', '=', 'payments.fee_assessment_id')
            ->join('applications', 'applications.id', '=', 'payments.application_id')
            ->join('businesses', 'businesses.id', '=', 'applications.business_id')
            ->whereNull('applications.deleted_at')
            ->whereNull('businesses.deleted_at')
            ->where('payments.status', PaymentStatus::Completed->value)
            ->whereNotNull('payments.paid_at')
            ->where('payments.paid_at', '>=', $yearStart)
            ->where('payments.paid_at', '<=', $now)
            ->orderBy('payments.id')
            ->get(['payments.amount', 'payments.paid_at', 'fee_assessments.line_items', 'fee_assessments.total_amount']);

        foreach ($rows as $payment) {
            $assessed = (float) $payment->total_amount;
            if ($assessed <= 0) {
                continue;
            }
            $share = (float) $payment->amount / $assessed;
            $thisMonth = CarbonImmutable::parse($payment->paid_at) >= $monthStart;
            $touched = false;

            foreach ((array) (json_decode((string) $payment->line_items, true) ?: []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $code = self::feeLineType($line, $known, $codeByName);
                $amount = (float) ($line['amount'] ?? 0) * $share;
                if (! isset($amounts[$code]) || $amount == 0.0) {
                    continue;
                }
                $touched = true;
                $amounts[$code]['year'] += $amount;
                if ($thisMonth) {
                    $amounts[$code]['month'] += $amount;
                }
            }

            if ($touched) {
                $payments['year']++;
                if ($thisMonth) {
                    $payments['month']++;
                }
            }
        }

        foreach ($amounts as $code => $sums) {
            $amounts[$code] = ['month' => round($sums['month'], 2), 'year' => round($sums['year'], 2)];
        }

        return [
            'month_start' => ManilaCalendar::dateOf($monthStart),
            'year_start' => ManilaCalendar::dateOf($yearStart),
            'types' => $types,
            'amounts' => $amounts,
            'payments' => $payments,
        ];
    }

    /**
     * The permit type a fee line was for, or FEE_OTHER.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, string>  $known  permit type code => name
     * @param  array<string, string>  $codeByName  lower-cased permit type name => code
     */
    private static function feeLineType(array $line, array $known, array $codeByName): string
    {
        $codes = array_values(array_unique(array_map('strval', (array) ($line['permit_codes'] ?? []))));
        if (count($codes) === 1 && isset($known[$codes[0]])) {
            return $codes[0];
        }
        if ($codes !== []) {
            return self::FEE_OTHER;
        }

        // "Sanitary Permit fee (June 2026, unbilled until now)" — a deferred
        // fee, named after its permit. A penalty line under it ("Late
        // surcharge and interest — …") does not start with a permit's name.
        if (preg_match('/^(.+) fee \(.*unbilled until now\)$/u', (string) ($line['label'] ?? ''), $m)) {
            return $codeByName[mb_strtolower(trim($m[1]))] ?? self::FEE_OTHER;
        }

        return self::FEE_OTHER;
    }

    /* ── facts: suspended permits ──────────────────────────────────────── */

    /**
     * Every permit suspended today, with how long and on whom it waits.
     *
     * Added 18 October 2026 (client's picker, item C). A Mayor's Permit is
     * suspended when another office refuses its own permit, and stays so until
     * that office is satisfied; after 30 days unresolved BPLO may revoke it
     * (client, 5 October 2026). This is the count, the long-running share and
     * the oldest few, so the list on the permits register is not the only
     * place the backlog shows.
     *
     * `suspended_at` exists since 5 October 2026 and was backfilled from the
     * audit trail where a row recorded it; a suspension with no date is
     * counted but has no age, and is never called long — the register's
     * "Suspended over 30 days" filter leaves it out too.
     *
     * LONG uses the register's own cutoff (PermitController,
     * `suspended_over_days`): suspended on or before the end of the day 30
     * days before today. The same arithmetic, so the number here is the
     * number of rows the link opens.
     *
     * Scoped to one office: the permits suspended because of ITS permit
     * (`suspended_for_permit_type_id`), plus its own permits suspended with no
     * permit named (a sanctioned business, BPLO's own suspension) — those
     * wait on the office that issued them.
     *
     * @return list<array<string, mixed>>
     */
    private static function suspendedFacts(CarbonImmutable $today, CarbonImmutable $now, ?array $scope = null): array
    {
        $long = $now->startOfDay()->subDays(self::SUSPENDED_LONG_DAYS)->endOfDay();
        $types = $scope === null ? [] : ($scope['permit_type_ids'] === [] ? [0] : $scope['permit_type_ids']);

        $rows = DB::table('permits')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->leftJoin('permit_types as waits', 'waits.id', '=', 'permits.suspended_for_permit_type_id')
            ->leftJoin('departments as waits_office', 'waits_office.id', '=', 'waits.issuing_department_id')
            ->whereNull('businesses.deleted_at')
            ->where('permits.status', PermitStatus::Suspended->value)
            ->when($scope !== null, static fn ($q) => $q->where(static fn ($w) => $w
                ->whereIn('permits.suspended_for_permit_type_id', $types)
                ->orWhere(static fn ($own) => $own
                    ->whereNull('permits.suspended_for_permit_type_id')
                    ->whereIn('permits.permit_type_id', $types))))
            ->orderBy('permits.id')
            ->get([
                'permits.id', 'permits.permit_number', 'permits.suspended_at',
                'businesses.id as business_id', 'businesses.name as business',
                'permit_types.code as permit_code', 'permit_types.name as permit',
                'waits.name as waits_permit', 'waits_office.code as waits_office_code',
                'waits_office.name as waits_office',
            ]);

        $out = [];
        foreach ($rows as $row) {
            $since = $row->suspended_at === null ? null : CarbonImmutable::parse($row->suspended_at);
            $out[] = [
                'permit_id' => (int) $row->id,
                'permit_number' => (string) $row->permit_number,
                'business_id' => (int) $row->business_id,
                'business' => (string) $row->business,
                'permit_code' => (string) $row->permit_code,
                'permit' => (string) $row->permit,
                'suspended_on' => $since === null ? null : ManilaCalendar::dateOf($since),
                'days' => $since === null ? null : ManilaCalendar::daysBetweenDates(ManilaCalendar::dateOf($since), $today->toDateString()),
                'long' => $since !== null && $since <= $long,
                'waits_on' => $row->waits_permit === null ? null : [
                    'permit' => (string) $row->waits_permit,
                    'office_code' => $row->waits_office_code === null ? null : (string) $row->waits_office_code,
                    'office' => $row->waits_office === null ? null : (string) $row->waits_office,
                ],
            ];
        }

        return $out;
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
    private static function mapFacts(CarbonImmutable $today, ?array $scope = null): array
    {
        $activeIds = self::activeBusinessIds($today, $scope);
        $active = array_fill_keys($activeIds, true);

        $rows = self::officeBusinesses(DB::table('business_addresses'), $scope, 'businesses.id')
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
            'total_businesses' => self::officeBusinesses(DB::table('businesses'), $scope, 'businesses.id')
                ->whereNull('deleted_at')
                ->count(),
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
            'applications_all_time' => (int) $kpis['applications_all_time'],
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
     * Time in office per office, and the slowest office when one stands out.
     *
     * Every office with a permit decided in the window is listed in `rows`,
     * slowest first, each saying whether it has enough permits to be `drawn`
     * (`min_reviews`). The overall average is over every permit in the panel.
     *
     * ── THE SLOWEST OFFICE IS NAMED ONLY WHEN IT IS SLOW ────────────────────
     *
     * The old panel always named the slowest drawn office, so with every
     * office at 0 days it announced "CHO 0 days" as the bottleneck — a tie
     * broken by alphabetical order, read as a finding. Since 18 October 2026
     * (the client's rule) the head of the table is named only when it is at
     * least BOTTLENECK_MIN_DAYS_ABOVE working days AND BOTTLENECK_MIN_SHARE_ABOVE
     * above the overall average; otherwise `bottleneck` is null and the
     * screen says nothing. The rules travel in the dataset, so a stored
     * snapshot is judged by the rule it was written under.
     *
     * @param  list<array{code: string, name: string, days: int|float}>  $observations
     * @param  array{min_reviews: int, bottleneck_min_days_above: float, bottleneck_min_share_above: float}  $rules
     * @return array<string, mixed>
     */
    private static function computeStages(array $observations, array $rules): array
    {
        $minReviews = (int) $rules['min_reviews'];
        $minDaysAbove = (float) $rules['bottleneck_min_days_above'];
        $minShareAbove = (float) $rules['bottleneck_min_share_above'];

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
                // Permits issued or refused. The key is the old panel's name.
                'reviews' => $n,
                'mean_days' => Rounding::statistic(array_sum($group['days']) / $n, 1),
                'drawn' => $n >= $minReviews,
            ];
        }

        // Slowest first, then busiest, then by code: a tie must not fall back
        // to the order the rows arrived in, which is not a stable property.
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
        $drawn = array_values(array_filter($rows, static fn (array $row): bool => $row['drawn']));
        if ($drawn !== [] && $overall !== null) {
            $slowest = $drawn[0];
            $above = Rounding::statistic($slowest['mean_days'] - $overall, 1);

            if ($above >= $minDaysAbove && $slowest['mean_days'] >= $overall * (1 + $minShareAbove)) {
                $bottleneck = [
                    'code' => $slowest['code'],
                    'name' => $slowest['name'],
                    'mean_days' => $slowest['mean_days'],
                    'reviews' => $slowest['reviews'],
                    'above_average_days' => $above,
                    'share_of_reviews' => Rounding::statistic(
                        ($slowest['reviews'] / max(1, count($observations))) * 100,
                        1,
                    ),
                ];
            }
        }

        return [
            'unit' => 'working_days',
            'rows' => $rows,
            'reviews' => count($observations),
            'mean_days' => $overall,
            'bottleneck' => $bottleneck,
            'min_reviews' => $minReviews,
        ];
    }

    /**
     * The compliance indicators, each with its rate or null.
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
     * three forward windows, and counts lapsed businesses per permit type —
     * see expiringPermits().
     *
     * @param  list<array{code: string, label: string}>  $columns
     * @param  list<array{code: string, days_to_expiry: int, business_id?: int}>  $permits
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

        $businesses = array_fill(0, count($rows), []);
        foreach ($permits as $n => $permit) {
            $code = (string) $permit['code'];
            if (! array_key_exists($code, $blank)) {
                continue;
            }

            $days = (int) $permit['days_to_expiry'];
            // A fact without a business id (a snapshot or fixture written
            // before the id travelled) stands for a business of its own.
            $business = isset($permit['business_id']) ? 'b'.$permit['business_id'] : 'row'.$n;

            foreach ($rows as $index => $row) {
                $hit = $row['expired']
                    ? $days < 0
                    : ($days >= 0 && $days <= (int) $row['days']);

                if ($hit) {
                    $rows[$index]['counts'][$code]++;
                    $rows[$index]['total']++;
                    $businesses[$index][$business] = true;
                }
            }
        }

        /*
         * `businesses`, beside `total`: how many distinct businesses the row
         * is about. `total` stays the sum of the cells so the table adds up
         * across; a business lapsed on its sanitary permit and its fire
         * clearance is two cells and one business.
         */
        foreach ($rows as $index => $row) {
            $rows[$index]['businesses'] = count($businesses[$index]);
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * @param  list<array{month: string, registered: int, closed: int, partial?: bool}>  $facts
     * @return array{rows: list<array{month: string, registered: int, closed: int, net: int, partial: bool}>, registered: int, closed: int}
     */
    private static function computeMovement(array $facts): array
    {
        $rows = [];
        $registered = 0;
        $closed = 0;
        foreach ($facts as $fact) {
            $registered += (int) $fact['registered'];
            $closed += (int) $fact['closed'];
            $rows[] = [
                'month' => (string) $fact['month'],
                'registered' => (int) $fact['registered'],
                'closed' => (int) $fact['closed'],
                'net' => (int) $fact['registered'] - (int) $fact['closed'],
                'partial' => (bool) ($fact['partial'] ?? false),
            ];
        }

        return ['rows' => $rows, 'registered' => $registered, 'closed' => $closed];
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
     * Messages & Requests. `officer_activity` is the old panel's wire name.
     *
     * @param  array{threads_awaiting_reply: int, requests: array{total: int, fulfilled: int}}  $facts
     * @return array<string, mixed>
     */
    private static function computeOfficerActivity(array $facts): array
    {
        $requestTotal = (int) $facts['requests']['total'];
        $fulfilled = (int) $facts['requests']['fulfilled'];

        return [
            'threads_awaiting_reply' => (int) $facts['threads_awaiting_reply'],
            'requests_total' => $requestTotal,
            'requests_fulfilled' => $fulfilled,
            'requests_fulfilled_rate' => $requestTotal > 0
                ? Rounding::statistic(($fulfilled / $requestTotal) * 100, 1)
                : null,
        ];
    }

    /**
     * Fees Collected: a row per permit type, and the two totals.
     *
     * Every row is kept at zero, so "₱0 for Zoning this month" reads as
     * nothing collected rather than as a missing row.
     *
     * @param  array{month_start: string, year_start: string, types: list<array{code: string, label: string}>, amounts: array<string, array{month: float, year: float}>, payments: array{month: int, year: int}}  $facts
     * @return array<string, mixed>
     */
    private static function computeFees(array $facts): array
    {
        $rows = [];
        $month = 0.0;
        $year = 0.0;
        foreach ($facts['types'] as $type) {
            $code = (string) $type['code'];
            $sums = $facts['amounts'][$code] ?? ['month' => 0, 'year' => 0];
            $month += (float) $sums['month'];
            $year += (float) $sums['year'];
            $rows[] = [
                'code' => $code,
                'label' => (string) $type['label'],
                'month' => round((float) $sums['month'], 2),
                'year' => round((float) $sums['year'], 2),
            ];
        }

        return [
            'month_start' => (string) $facts['month_start'],
            'year_start' => (string) $facts['year_start'],
            'month_total' => round($month, 2),
            'year_total' => round($year, 2),
            'payments_month' => (int) $facts['payments']['month'],
            'payments_year' => (int) $facts['payments']['year'],
            'rows' => $rows,
        ];
    }

    /**
     * Suspended Permits: the count, the long-running share, the oldest few.
     *
     * Oldest first; a suspension with no date has no age and goes last.
     *
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    private static function computeSuspended(array $facts): array
    {
        usort($facts, static fn (array $a, array $b) => [
            $a['suspended_on'] === null, $a['suspended_on'] ?? '', $a['permit_id'],
        ] <=> [
            $b['suspended_on'] === null, $b['suspended_on'] ?? '', $b['permit_id'],
        ]);

        return [
            'total' => count($facts),
            'long' => count(array_filter($facts, static fn (array $p): bool => (bool) $p['long'])),
            'long_after_days' => self::SUSPENDED_LONG_DAYS,
            'undated' => count(array_filter($facts, static fn (array $p): bool => $p['suspended_on'] === null)),
            'oldest' => array_slice($facts, 0, self::SUSPENDED_LIST),
        ];
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

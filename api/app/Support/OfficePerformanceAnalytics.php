<?php

namespace App\Support;

use App\Enums\AssignmentStatus;
use App\Models\ApplicationAssignment;
use App\Models\Business;
use App\Models\Department;
use Carbon\CarbonImmutable;

/**
 * One view across all six offices — BPLO, CHO, BFP, OBO, CENRO, CPDO — so that
 * a super admin can compare them against each other and against RA 11032.
 *
 * ## Why this exists beside Processing Time Monitoring
 *
 * Every analytics screen this product already has answers a question about ONE
 * office at a time, or about the register as a whole. Processing Time draws a
 * control chart per department, which answers "is this office behaving the way
 * it normally behaves" — a question each office answers in its own units,
 * against its own fitted limits. That is the right question for spotting a
 * slowdown and the wrong one for ranking: an office with a slow but very steady
 * process reads "Within Normal Range" while sitting at nine days, and an office
 * that normally turns work round in a morning reads "Outside Normal Range" at
 * two. Neither figure is comparable with the other.
 *
 * So this screen deliberately does NOT re-fit anything. It puts the offices in
 * one table on three axes that mean the same thing in every column:
 *
 *   handled     how many reviews the office finished in the window
 *   open now    how many are sitting with it at this moment, and the oldest
 *   turnaround  working days it held a filing, mean and middle value
 *   breached    holds that on their own ran past the whole statutory allowance
 *
 * ## THE BPLO PROBLEM — read this before adding BPLO to a turnaround column
 *
 * BPLO's `completed_at` does not measure BPLO. It measures the whole filing.
 *
 * There is one `application_assignments` row per (application, department) —
 * verified against the live register, which holds zero duplicate pairs — and
 * BPLO acts on a filing TWICE: once at intake, when it accepts the form and the
 * Tax Order of Payment is raised, and again at the end, when every clearance is
 * in and it approves overall. Both acts call WorkflowService::completeAssignment,
 * and that method sets `completed_at => now()` unconditionally, with no guard
 * for a row it has already stamped. The second stamp overwrites the first.
 *
 * The consequence is that a BPLO row reads `assigned_at` = submission and
 * `completed_at` = final approval, which is the ENTIRE lifetime of the filing —
 * the five clearance offices' own reviews, the applicant's payment, and any wait
 * for an inspection, all of it inside a number labelled as BPLO's. On the live
 * register 177 BPLO rows carry a `completed_at` equal to their application's
 * `decided_at` to the second; no other office has more than one, and that one is
 * a coincidence of timing.
 *
 * Every other office's row is honest: it is stamped once, by the one act that
 * office performs.
 *
 * ### What was done about it, and what was not
 *
 * EXCLUDED AND ANNOTATED, not silently dropped and not quietly fixed.
 *
 * BPLO keeps its volume and its open caseload, which are counts of rows and are
 * unaffected by when those rows were stamped. Its turnaround, its breach count
 * and its share of the tier panel are null, and the screen prints the reason on
 * the row rather than a dash the reader has to interpret. An office missing from
 * a six-office comparison reads as an office that does not exist — see the same
 * argument, and the same client report, on ProcessingTimePage's status strip.
 *
 * Fixing the stamp was NOT attempted here. It is a one-line guard in
 * WorkflowService::completeAssignment — skip the update when `completed_at` is
 * already set, or split intake and final approval into two rows — but it changes
 * what the register records for every filing, and it would silently rewrite the
 * figures on Processing Time Monitoring, which reads the same column. That is a
 * workflow change with its own tests and its own conversation, not a detail of
 * an analytics screen. When it is made, delete BPLO from
 * NOT_COMPARABLE below and this whole section with it.
 *
 * The status-history route was tried and abandoned: BPLO's intake step can be
 * derived from `application_status_history` (submitted → first `pending_payment`
 * row), but on this register the seeder writes every history row for a filing in
 * the same instant, so the derived figure is 0.0 days for all 1,659 filings that
 * have one. A measurement that reads zero everywhere is not a measurement.
 *
 * ## The split, and why it is the same split as everywhere else in app/Support
 *
 *  - **dataset()** runs the SQL and returns plain rows. All database access here.
 *  - **compute()** turns those rows into the payload with no database access at
 *    all, so the arithmetic can be pinned to a frozen fixture.
 */
final class OfficePerformanceAnalytics
{
    /**
     * How far back the comparison looks, in weeks.
     *
     * A year, matching ProcessingTimeAnalytics. The two screens are read one
     * after the other by the same person, and a reader who sees "3.2 days" on
     * one and "2.8 days" on the other should not have to check that the windows
     * agree before wondering which is right.
     */
    public const DEFAULT_WINDOW_WEEKS = 52;

    /**
     * Offices whose recorded turnaround measures something other than their own
     * step, keyed by code, valued with the sentence the screen prints.
     *
     * One entry, and the class docblock is the argument for it. This is a map
     * rather than a bare list because a reader confronted with a blank cell in a
     * comparison needs the reason in the same place as the blank — DESIGN.md's
     * "Never Color Alone" applied to an absence: an unexplained gap is a
     * colour-only signal made of whitespace.
     *
     * @var array<string, string>
     */
    private const NOT_COMPARABLE = [
        'BPLO' => 'BPLO is stamped again at final approval, so its recorded time is the whole '
            .'filing rather than BPLO\'s own step. Volume and open caseload are unaffected.',
    ];

    /**
     * Name patterns that mark a business as created by the test suite.
     *
     * There is NO provenance column on `businesses`. Nothing records whether a
     * row came from a citizen, the demo seeder or a Playwright run, so this is a
     * name-shape guess and the screen says as much and prints the patterns. It
     * is here rather than hidden in a query so a reader can check it against
     * what they see in Records.
     *
     * The suite names its fixtures deliberately — `E2E Wizard Clearances
     * 1788063770358`, `QA Trace2 Store AGENT …`, `Test Business Name` — so the
     * prefixes catch them without a wildcard loose enough to catch a real trade.
     * A genuine business called "Test" is conceivable; one called "E2E Wizard
     * Clearances" followed by a millisecond timestamp is not.
     *
     * @var list<string>
     */
    private const TEST_DATA_PATTERNS = ['E2E%', 'QA %', 'Test%'];

    /**
     * @return array{
     *     params: array{weeks: int},
     *     now: string,
     *     window_start: string,
     *     not_comparable: array<string, string>,
     *     test_data_patterns: list<string>,
     *     departments: list<array{code: string, name: string}>,
     *     holds: list<array{department_code: string, assigned_at: string, completed_at: string, tier: string|null, from_test_business: bool}>,
     *     open: list<array{department_code: string, assigned_at: string|null}>,
     *     businesses: array{total: int, test_shaped: int}
     * }
     */
    public static function dataset(int $windowWeeks = self::DEFAULT_WINDOW_WEEKS): array
    {
        $now = CarbonImmutable::now();
        $windowStart = $now->subWeeks($windowWeeks)->startOfWeek(CarbonImmutable::MONDAY);

        $departments = Department::orderBy('id')->get(['code', 'name'])
            ->map(fn ($row) => ['code' => (string) $row->code, 'name' => (string) $row->name])
            ->all();

        /*
         * The test-data predicate, expressed once and applied twice: once to
         * count the businesses and once to mark the holds that descend from
         * them. Written as a closure over the query builder rather than as a
         * raw string so the two uses cannot drift into two different rules.
         */
        $testShaped = static function ($query, string $column): void {
            $query->where(function ($inner) use ($column): void {
                foreach (self::TEST_DATA_PATTERNS as $pattern) {
                    $inner->orWhere($column, 'like', $pattern);
                }
            });
        };

        $holds = ApplicationAssignment::query()
            ->join('departments', 'departments.id', '=', 'application_assignments.department_id')
            ->join('applications', 'applications.id', '=', 'application_assignments.application_id')
            /*
             * Left join, not inner: `businesses` is soft-deleted and 139 filings
             * on the live register point at a deleted one (see AGENTS.md §11).
             * An inner join would drop those holds from the volume counts
             * entirely, which would understate the offices rather than merely
             * leave one flag unknown.
             */
            ->leftJoin('businesses', 'businesses.id', '=', 'applications.business_id')
            ->whereNotNull('application_assignments.completed_at')
            ->whereNotNull('application_assignments.assigned_at')
            ->where('application_assignments.completed_at', '>=', $windowStart)
            ->orderBy('application_assignments.completed_at')
            ->get([
                'departments.code as department_code',
                'application_assignments.assigned_at',
                'application_assignments.completed_at',
                'applications.complexity as tier',
                'businesses.name as business_name',
            ])
            ->map(fn ($row) => [
                'department_code' => (string) $row->department_code,
                'assigned_at' => CarbonImmutable::parse($row->assigned_at)->toISOString(),
                'completed_at' => CarbonImmutable::parse($row->completed_at)->toISOString(),
                // Null where no office has classified the filing yet. Carried as
                // null rather than defaulted to `complex`, because a tier nobody
                // set is not evidence of the tier the fallback happens to pick.
                'tier' => Ra11032::isTier($row->tier) ? (string) $row->tier : null,
                'from_test_business' => self::looksLikeTestData((string) ($row->business_name ?? '')),
            ])
            ->all();

        /*
         * Open work is a PRESENT-TENSE fact and is deliberately not filtered by
         * the window: a filing that has sat with an office since before the
         * window opened is the single most useful row on this screen, and a
         * window filter would be exactly what hides it.
         */
        $open = ApplicationAssignment::query()
            ->join('departments', 'departments.id', '=', 'application_assignments.department_id')
            ->whereIn('application_assignments.status', [
                AssignmentStatus::Pending->value,
                AssignmentStatus::InProgress->value,
            ])
            ->get([
                'departments.code as department_code',
                'application_assignments.assigned_at',
            ])
            ->map(fn ($row) => [
                'department_code' => (string) $row->department_code,
                'assigned_at' => $row->assigned_at === null
                    ? null
                    : CarbonImmutable::parse($row->assigned_at)->toISOString(),
            ])
            ->all();

        return [
            'params' => ['weeks' => $windowWeeks],
            'now' => $now->toISOString(),
            'window_start' => $windowStart->toDateString(),
            // Carried on the dataset rather than read from the constants inside
            // compute(), so a frozen fixture records the rules its numbers were
            // produced under instead of picking up today's.
            'not_comparable' => self::NOT_COMPARABLE,
            'test_data_patterns' => self::TEST_DATA_PATTERNS,
            'departments' => $departments,
            'holds' => $holds,
            'open' => $open,
            'businesses' => [
                'total' => Business::count(),
                'test_shaped' => Business::where(fn ($q) => $testShaped($q, 'name'))->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function build(int $windowWeeks = self::DEFAULT_WINDOW_WEEKS): array
    {
        return self::compute(self::dataset($windowWeeks));
    }

    /**
     * The engine: dataset in, screen payload out, no database.
     *
     * @param  array<string, mixed>  $dataset  as returned by dataset()
     * @return array<string, mixed>
     */
    public static function compute(array $dataset): array
    {
        // Echoed, not re-parsed: round-tripping through Carbon re-formats the
        // fractional seconds, which is the same instant and not the same bytes.
        $now = (string) $dataset['now'];
        $windowWeeks = (int) $dataset['params']['weeks'];

        /** @var array<string, string> $notComparable */
        $notComparable = $dataset['not_comparable'];

        /** @var list<array{code: string, name: string}> $departments */
        $departments = $dataset['departments'];

        /** @var list<array{department_code: string, assigned_at: string, completed_at: string, tier: string|null, from_test_business: bool}> $holds */
        $holds = $dataset['holds'];

        /** @var list<array{department_code: string, assigned_at: string|null}> $open */
        $open = $dataset['open'];

        // Working days held, attached to each hold once, because every figure
        // below is a different reduction of the same number and recomputing it
        // per panel is how two panels come to disagree.
        $measured = [];
        foreach ($holds as $hold) {
            $measured[] = $hold + ['working_days' => self::workingDaysBetween(
                CarbonImmutable::parse($hold['assigned_at']),
                CarbonImmutable::parse($hold['completed_at']),
            )];
        }

        $nowInstant = CarbonImmutable::parse($now);

        $offices = [];
        foreach ($departments as $department) {
            $code = $department['code'];
            $comparable = ! array_key_exists($code, $notComparable);

            $ours = array_values(array_filter(
                $measured,
                static fn (array $hold): bool => $hold['department_code'] === $code,
            ));
            $openHere = array_values(array_filter(
                $open,
                static fn (array $row): bool => $row['department_code'] === $code,
            ));

            $offices[] = [
                'code' => $code,
                'name' => $department['name'],

                // Counts of rows. True of BPLO as well: how many reviews an
                // office finished and how many it is holding do not depend on
                // when the row was stamped, so nothing here is suppressed.
                'handled' => count($ours),
                'open' => count($openHere),
                'oldest_open_working_days' => self::oldestOpen($openHere, $nowInstant),

                'turnaround_comparable' => $comparable,
                'not_comparable_reason' => $comparable ? null : $notComparable[$code],

                // Null, never zero, for an office whose clock measures something
                // else. A zero here would be read as "instant", which is the
                // opposite of what is true.
                ...($comparable ? self::turnaround($ours) : [
                    'mean_working_days' => null,
                    'median_working_days' => null,
                    'slowest_working_days' => null,
                    'classified_holds' => null,
                    'breached' => null,
                    'breach_rate' => null,
                ]),

                'test_holds' => count(array_filter(
                    $ours,
                    static fn (array $hold): bool => $hold['from_test_business'],
                )),
            ];
        }

        /*
         * The tier panel counts COMPARABLE holds only. Folding BPLO's rows in
         * would put the whole filing's lifetime — which by construction contains
         * every other office's hold — into the same total as the individual
         * holds it contains, and the statutory allowance would be measured
         * against a number that has been counted twice.
         */
        $comparableHolds = array_values(array_filter(
            $measured,
            static fn (array $hold): bool => ! array_key_exists($hold['department_code'], $notComparable),
        ));

        return [
            'generated_at' => $now,
            'window_weeks' => $windowWeeks,
            'window_start' => (string) $dataset['window_start'],
            'offices' => $offices,
            'tiers' => self::tiers($comparableHolds),
            'unclassified_holds' => count(array_filter(
                $comparableHolds,
                static fn (array $hold): bool => $hold['tier'] === null,
            )),
            'totals' => [
                'offices' => count($departments),
                'handled' => count($measured),
                'open' => count($open),
                'compared_offices' => count($comparableHolds) === 0
                    ? 0
                    : count(array_unique(array_column($comparableHolds, 'department_code'))),
            ],
            'data_quality' => [
                'total_businesses' => (int) $dataset['businesses']['total'],
                'test_businesses' => (int) $dataset['businesses']['test_shaped'],
                'test_holds' => count(array_filter(
                    $measured,
                    static fn (array $hold): bool => $hold['from_test_business'],
                )),
                'patterns' => $dataset['test_data_patterns'],
            ],
        ];
    }

    /**
     * Mean, middle and worst working days an office held a filing, plus how
     * often one office alone outran the whole statutory allowance.
     *
     * ── What "breached" means here, and what it deliberately does not ────────
     *
     * RA 11032 gives the LGU 3 / 7 / 20 working days for the whole transaction,
     * not per office, so no office's hold can be measured against a share of the
     * allowance — the statute does not apportion one and neither may we.
     *
     * What the statute DOES support is the one-sided claim: if a single office
     * held a filing for more working days than the entire transaction was
     * allowed, that office alone put the filing past its deadline, whatever
     * every other office did. That is unambiguous, it is attributable, and it is
     * what this column counts. A filing that went over because four offices each
     * took a reasonable time is a failure of the process rather than of an
     * office, and it is counted nowhere on this screen — the Analytics Dashboard
     * already reports compliance per filing, which is the right place for it.
     *
     * Holds on a filing nobody has classified are excluded rather than assumed
     * into a tier. Ra11032::statutoryWorkingDays() falls back to `complex` for an
     * unknown tier, which is right when a deadline must be produced for a live
     * filing and wrong when the question is whether a deadline was met: it would
     * report seven-day breaches against filings that might have been entitled to
     * twenty. The count of excluded holds is on the payload so the reader can see
     * how much the rate is standing on.
     *
     * @param  list<array{working_days: float, tier: string|null}>  $holds
     * @return array<string, float|int|null>
     */
    private static function turnaround(array $holds): array
    {
        if ($holds === []) {
            return [
                'mean_working_days' => null,
                'median_working_days' => null,
                'slowest_working_days' => null,
                'classified_holds' => 0,
                'breached' => null,
                'breach_rate' => null,
            ];
        }

        $days = array_column($holds, 'working_days');

        $classified = array_values(array_filter(
            $holds,
            static fn (array $hold): bool => $hold['tier'] !== null,
        ));

        $breached = count(array_filter(
            $classified,
            static fn (array $hold): bool => $hold['working_days']
                > Ra11032::statutoryWorkingDays($hold['tier']),
        ));

        return [
            'mean_working_days' => Rounding::statistic(array_sum($days) / count($days), 2),
            'median_working_days' => Rounding::statistic(self::median($days), 2),
            'slowest_working_days' => Rounding::statistic(max($days), 2),
            'classified_holds' => count($classified),
            'breached' => $classified === [] ? null : $breached,
            'breach_rate' => $classified === []
                ? null
                : Rounding::statistic($breached / count($classified) * 100, 1),
        ];
    }

    /**
     * The three statutory tiers, and how the comparable offices' holds sat
     * against each one's allowance.
     *
     * Every tier is emitted whether or not the window holds a filing in it. A
     * tier that vanishes from the table when nothing lands in it turns "no
     * highly technical filings this year" into "this LGU has two tiers", and the
     * statute has three.
     *
     * @param  list<array{working_days: float, tier: string|null}>  $holds
     * @return list<array{key: string, label: string, statutory_working_days: int, holds: int, within: int, over: int}>
     */
    private static function tiers(array $holds): array
    {
        $rows = [];

        foreach (Ra11032::TIERS as $key => $tier) {
            $ours = array_values(array_filter(
                $holds,
                static fn (array $hold): bool => $hold['tier'] === $key,
            ));

            $over = count(array_filter(
                $ours,
                static fn (array $hold): bool => $hold['working_days'] > $tier['statutory_working_days'],
            ));

            $rows[] = [
                'key' => $key,
                'label' => $tier['label'],
                'statutory_working_days' => $tier['statutory_working_days'],
                'holds' => count($ours),
                'within' => count($ours) - $over,
                'over' => $over,
            ];
        }

        return $rows;
    }

    /**
     * How long the longest-waiting open assignment has been waiting.
     *
     * Working days, the same unit as the turnaround columns beside it, so the
     * two can be read against each other — "the oldest thing on CENRO's desk has
     * been there nine working days and CENRO averages three" is the sentence
     * this column exists to let a reader form.
     *
     * A row with no `assigned_at` is skipped rather than treated as infinitely
     * old: the column is nullable and the register does contain such rows.
     *
     * @param  list<array{assigned_at: string|null}>  $open
     */
    private static function oldestOpen(array $open, CarbonImmutable $now): ?float
    {
        $waits = [];

        foreach ($open as $row) {
            if ($row['assigned_at'] === null) {
                continue;
            }
            $waits[] = self::workingDaysBetween(CarbonImmutable::parse($row['assigned_at']), $now);
        }

        return $waits === [] ? null : Rounding::statistic(max($waits), 2);
    }

    /**
     * Working days from one instant to another, counting the way RA 11032 counts.
     *
     * The statute's clock runs in working days and `Ra11032::deadlineFor` adds
     * them with `addWeekdays`, so a duration measured here and a deadline set
     * there are in the same unit and can be compared without a conversion —
     * which is the whole reason this is not simply a difference in hours.
     *
     * Counted over the half-open interval (from, to]: a filing received and
     * decided the same day took zero working days, and one received Friday and
     * decided Monday took one. Public holidays are not modelled, here or in
     * Ra11032, which makes every count slightly generous to the office — a hold
     * this method calls a breach is genuinely a breach.
     *
     * Arithmetic rather than a day-by-day loop for the whole span: any seven
     * consecutive days contain exactly five weekdays, so only the remainder has
     * to be walked. The register holds thousands of assignments per window and a
     * naive loop is a year of iterations per row.
     */
    private static function workingDaysBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $start = $from->startOfDay();
        $end = $to->startOfDay();

        if ($end <= $start) {
            return 0.0;
        }

        $days = (int) $start->diffInDays($end);
        $count = intdiv($days, 7) * 5;

        $cursor = $start;
        for ($i = 0; $i < $days % 7; $i++) {
            $cursor = $cursor->addDay();
            if ($cursor->isWeekday()) {
                $count++;
            }
        }

        return (float) $count;
    }

    /**
     * The middle value, shown beside the mean rather than instead of it.
     *
     * Both, because they answer different questions and this register makes the
     * difference visible: a handful of filings that sat for a fortnight pull an
     * office's mean up while its middle value stays where most of its work
     * actually lands. An office whose mean is well above its middle has a tail,
     * and the tail is the thing worth asking about.
     *
     * @param  list<float>  $values  non-empty
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** Does this business name match one of the suite's fixture shapes? */
    private static function looksLikeTestData(string $name): bool
    {
        foreach (self::TEST_DATA_PATTERNS as $pattern) {
            // The patterns are written in SQL's own syntax so that the constant
            // reads the same here as it does in the query it drives; `%` is the
            // only wildcard any of them uses.
            if (str_starts_with($name, rtrim($pattern, '%'))) {
                return true;
            }
        }

        return false;
    }
}

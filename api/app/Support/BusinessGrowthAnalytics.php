<?php

namespace App\Support;

use App\Enums\PermitStatus;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Business Lifecycle Monitoring, computed from the register.
 *
 * Naming: the client's paper calls this "Business Growth Analysis" and mockup 122
 * calls it "Business Lifecycle Monitoring". The mockup is newer, so it wins on
 * naming; the paper wins on formulas. That split is deliberate and is recorded in
 * docs/r-integration-spec.md §4.
 *
 * Same shape as the other analytics features: `dataset()` runs the SQL and
 * gathers facts, `compute()` turns those facts into statistics without touching
 * the database. Keeping the arithmetic on the far side of that seam is what lets
 * it be pinned to a frozen fixture (AnalyticsGoldenOutputTest) instead of to a
 * seeded database.
 *
 * DEFINITIONS THAT ARE CHOICES
 *
 *  - **Lifecycle status** (Active / Expired / Inactive / Closed) is derived from
 *    permits and from two ways a business can leave the register. A business is
 *    Closed when its registration was removed (soft delete) OR when an admin
 *    blacklisted it; Active when it holds a permit valid today; Expired when its
 *    permits have all lapsed; and Inactive when it is registered but has never
 *    been issued one.
 *  - **Blacklisting closes a business; suspension does not.** `businesses.status`
 *    (active / flagged / suspended / blacklisted) is the moderation state an
 *    admin sets, and most of it answers a different question from this screen.
 *    Blacklisting is the exception: it is the LGU striking a business off, which
 *    is the same thing the Closed bucket already counts. Suspension is temporary
 *    and reversible by design — counting it here would put businesses that are
 *    expected back into a panel that means "gone", and would make the Closure
 *    Trend a chart of sanctions rather than of closures.
 *    A blacklisting IS reversible too, so lifting one takes a point back off the
 *    trend. The client was told this and accepted it: the chart shows the
 *    register as it stands, not a ledger of everything that ever happened to it.
 *  - **A closure is dated by `deleted_at`, or by `status_changed_at` for a
 *    blacklisting.** There is no closed_at column and `updated_at` moves for
 *    every edit, so those two are the only honest closure dates in the schema.
 *    A blacklisted business whose `status_changed_at` is null — nobody recorded
 *    when the sanction landed — still counts as Closed in the Status Summary,
 *    which is an undated snapshot, but cannot appear in the monthly trend. There
 *    is no month to put it in and inventing one would draw a closure where
 *    nothing happened.
 *  - Closures in the period and the closure trend are counted by ONE query
 *    (closureDates()) so the headline figure and the sum of the chart cannot
 *    drift apart.
 *  - Growth compares the requested period against the equally long period that
 *    ended where it began.
 *
 * See cohortObservations() for how the survival measure is defined; it is the one
 * figure here that is a statistic rather than a count, and the reasoning behind
 * it is longer than a line.
 */
final class BusinessGrowthAnalytics
{
    public const DEFAULT_PERIOD_MONTHS = 12;

    /** How many barangays and industries the screen lists. */
    private const TOP_N = 6;

    /**
     * The smallest a line of business may be and still be RANKED BY CHANGE.
     *
     * ── What this protects against ──────────────────────────────────────────
     *
     * "Fastest growing" is a ranking over a difference, and a difference taken
     * on a handful of filings is mostly noise. On today's register, six PSIC
     * lines carry exactly one business each and every one of them was
     * registered inside the current period, so each scores a clean +1 from
     * nothing. "Other (not listed)" — the catch-all a clerk picks when no code
     * fits — carries seven businesses, all of them new, and scores +7. Rank the
     * whole register by change over a three-month window and that bucket takes
     * SECOND PLACE, ahead of every real trade in the city, on the strength of
     * seven filings nobody classified. A reader would leave the screen believing
     * the fastest-growing thing in the LGU is a data-entry default.
     *
     * ── Why ten, and why measured on `count` ────────────────────────────────
     *
     * `count` is how many live businesses carry the line TODAY. It is the
     * stable denominator: registrations move with the window, the register does
     * not. Below ten, a single filing moves the line by a tenth or more, which
     * is larger than the real year-on-year movement of most of the register — so
     * the ranking would be reporting arithmetic on individual filings rather
     * than a trend.
     *
     * The register agrees with the round number by a wide margin. The 30 lines
     * in use split 23 at twenty businesses or more and 7 at seven or fewer;
     * there is simply nothing between 8 and 19. So any floor in that gap
     * partitions today's data identically, and ten is chosen because it is the
     * one a reader can hold in their head and check — "at least 10 businesses"
     * is printed on the screen beside the chart, which is the point. The
     * exclusion is stated, never silent.
     *
     * The floor does NOT apply to the Largest lens. That lens ranks by `count`
     * itself, so its top six are above any floor by construction, and applying
     * one there would only be able to remove rows from a list that is already
     * answering "which lines are biggest".
     */
    public const INDUSTRY_LENS_MIN_BUSINESSES = 10;

    /**
     * Days after a permit expires before a missing renewal counts as a lapse.
     *
     * Without a grace period every business whose permit expired yesterday would
     * be recorded as having failed to renew, when in practice it is simply still
     * within the window where a renewal normally lands. Thirty days matches the
     * first renewal reminder, so a business counts as lapsed only once it has had
     * a reminder and a month.
     */
    private const RENEWAL_GRACE_DAYS = 30;

    /**
     * Days of coverage gap tolerated between consecutive permits.
     *
     * A permit valid to 31 December replaced by one effective 1 January is
     * contiguous cover and an on-time renewal, even though the dates are not
     * equal. One day is what makes that read correctly rather than as a lapse.
     */
    private const COVERAGE_GAP_TOLERANCE_DAYS = 1;

    /**
     * The permit type whose sequence defines a renewal cycle.
     *
     * The mayor's permit is the one every business must hold, so it is the only
     * one whose chain is comparable across the register. Following the sanitary
     * or fire chains as well would count a single year's renewal up to three
     * times for some businesses and once for others.
     */
    private const CYCLE_PERMIT_TYPE = 'BUSINESS';

    /**
     * The sentence that has to travel with the survival figure, wherever it is
     * shown. Kept server-side so an export cannot ship the number without it.
     *
     * It no longer names the estimator. It used to open "Cohort survival is a
     * Kaplan-Meier estimate over observed renewal cycles" and call the set-aside
     * businesses "censored" — three method words in one sentence, printed under
     * the chart on the face of the screen. The client's instruction is that the
     * explanations be simple enough for a first-time reader, and a BPLO officer
     * cannot act differently for knowing the estimator's name; what they have to
     * know is which businesses were followed and which were set aside, and that
     * is still here. NOTHING ABOUT THE METHOD CHANGED — only the words for it.
     * The method is documented on computeSurvival() below, where the audience is
     * a developer rather than an officer.
     */
    public const SURVIVAL_METHODOLOGY = 'Of the businesses that reached each renewal, this is the '
        .'share that had renewed every earlier one with no gap in cover. Businesses still inside '
        .'their current permit are set aside rather than counted as failures. It describes what this '
        .'group of businesses did. It is not a forecast of what any business will do next.';

    /**
     * @return array<string, mixed>
     */
    public static function build(int $periodMonths = self::DEFAULT_PERIOD_MONTHS): array
    {
        return self::compute(self::dataset($periodMonths));
    }

    /**
     * The facts every panel needs. No rates, no ranks, no survival curve — those
     * are compute()'s job, and keeping them out of here is what keeps this method
     * to plain SQL and its output to plain data.
     *
     * @return array<string, mixed>
     */
    public static function dataset(int $periodMonths = self::DEFAULT_PERIOD_MONTHS): array
    {
        [$periodStart, $priorStart, $now] = self::periodBounds($periodMonths);

        return [
            'params' => ['months' => $periodMonths],
            'now' => $now->toISOString(),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $now->toDateString(),
            'prior_period_start' => $priorStart->toDateString(),
            'top_n' => self::TOP_N,
            'survival_methodology' => self::SURVIVAL_METHODOLOGY,

            /*
             * The grace period travels with the facts rather than being read
             * straight out of the constant inside compute(). A dataset is a
             * self-contained record of the question that was asked, so a fixture
             * frozen today still describes the cut-off its numbers were produced
             * under, instead of quietly adopting a later one and reporting a
             * different lapse count for the same register.
             */
            'grace_days' => self::RENEWAL_GRACE_DAYS,

            'registrations' => self::countRegistrations($periodStart, $now, includeEnd: true),
            'registrations_prior' => self::countRegistrations($priorStart, $periodStart),
            'closures' => self::closures($periodStart, $now),
            'status_counts' => self::statusCounts(),
            'barangays' => self::barangayCounts($periodStart, $priorStart, $now),
            'closure_months' => self::closureMonths($periodStart, $now, $periodMonths),
            'industries' => self::industryCounts($periodStart, $priorStart, $now),
            'cohorts' => self::cohortObservations($now),
        ];
    }

    /**
     * The local (PHP) engine: facts in, lifecycle statistics out, no database.
     *
     * @param  array<string, mixed>  $dataset  as returned by dataset()
     * @return array<string, mixed>
     */
    public static function compute(array $dataset): array
    {
        $periodMonths = (int) $dataset['params']['months'];
        $registered = (int) $dataset['registrations'];
        $prior = (int) $dataset['registrations_prior'];
        $topN = (int) ($dataset['top_n'] ?? self::TOP_N);

        return [
            'generated_at' => (string) $dataset['now'],
            'period_months' => $periodMonths,
            'period_start' => (string) $dataset['period_start'],
            'period_end' => (string) $dataset['period_end'],
            'prior_period_start' => (string) $dataset['prior_period_start'],
            'registrations' => $registered,
            'registrations_prior' => $prior,
            // Null when there is nothing to compare against: a percentage change
            // from zero is not a number, and inventing one would be a lie. The
            // screen renders this as "No prior period".
            'growth_rate' => $prior > 0
                ? Rounding::statistic((($registered - $prior) / $prior) * 100, 1)
                : null,
            'closures' => (int) $dataset['closures'],
            'cohort_survival' => self::computeSurvival($dataset['cohorts'], (string) $dataset['survival_methodology']),
            'status_summary' => self::computeStatusSummary($dataset['status_counts']),
            'top_barangays' => self::computeBarangays($dataset['barangays'], $topN),
            'closure_trend' => self::computeClosureTrend($dataset['closure_months']),
            'industry_growth' => self::computeIndustries($dataset['industries'], $topN),
        ];
    }

    /**
     * The three dates every figure on this screen is measured between.
     *
     * Extracted so the lens decoration below cannot drift from dataset(): both
     * have to read "this period" and "the period before it" the same way, or
     * the toggle would rank one window while the panel around it describes
     * another.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     *                                                                           period start, prior-period start, now
     */
    private static function periodBounds(int $periodMonths): array
    {
        $now = CarbonImmutable::now();
        $periodStart = $now->subMonths($periodMonths)->startOfDay();

        return [$periodStart, $periodStart->subMonths($periodMonths)->startOfDay(), $now];
    }

    /* ── the industry lenses ───────────────────────────────────────────── */

    /**
     * Three answers to "which six lines of business belong on the chart?".
     *
     * ── The question this exists to answer ──────────────────────────────────
     *
     * A panelist asked it in these words: is there a criterion for which line
     * of business appears in the Business Industry Growth Trend, and what
     * happens if all of them do? The honest answer to the second half is that
     * the register holds 135 PSIC codes and drawing them all is not a styling
     * problem — six is already the ceiling the palette can keep distinguishable
     * without colour (see GrowthChartFrame's GROWTH_SERIES). The first half had
     * a weaker answer: the panel titled "Growth Trend" was ranked by SIZE, so a
     * line that doubled from 3 to 6 never appeared and two of the six on screen
     * were declining.
     *
     * Six slots stay. What changes is that the reader chooses the question:
     *
     *  - **Largest** — most businesses carrying the line today. This is the
     *    behaviour that shipped, and it stays the DEFAULT because it is the only
     *    one of the three that is true of the register rather than of a window:
     *    it answers "what is this city made of", needs no floor, no prior
     *    period and no comparison to be meaningful, and is the same six lines
     *    whichever window is selected. Opening on a change ranking would open on
     *    the noisiest of the three views.
     *  - **Fastest growing** — biggest increase in new registrations between the
     *    two periods.
     *  - **Fastest declining** — biggest decrease.
     *
     * Growing and declining rank on `delta`, the net change in registrations,
     * and not on a percentage. Same rule as computeBarangays() above, for the
     * same reason, plus one more that only bites here: a percentage of a prior
     * period of zero is not a number, and six of today's lines have exactly
     * that. Ranked by rate they would all tie at infinity.
     *
     * ── Why this is not on compute() ────────────────────────────────────────
     *
     * compute() feeds the nightly snapshot, and AnalyticsResolver serves that
     * stored snapshot verbatim when one exists. Anything added to compute()
     * therefore reaches the browser a refresh late, at last night's vintage —
     * which is exactly what these lenses must not be (see the next section).
     * Its `industry_growth` panel is also part of a published response shape:
     * the PDF report reads it, and the golden fixture is frozen against it.
     *
     * So this is spliced onto the response at serve time by
     * AnalyticsController, exactly as the renewal-risk barangay menu and
     * permit-lifecycle split are. `industry_growth` is left untouched: the PDF
     * report and the golden fixture still read it, and it is still the Largest
     * lens' answer.
     *
     * ── Why all three lenses are computed here, live ────────────────────────
     *
     * Not just the two new ones. The floor needs the WHOLE fact table to say
     * how many lines it excluded, and a caption reading "7 of 30 lines are
     * below the floor" computed from today's register, printed above six rows
     * lifted from last night's snapshot, would be two vintages in one panel.
     * One read, one vintage, one internally consistent panel — and the Largest
     * lens reproduces `industry_growth`'s ranking rule exactly, so on an
     * unchanged register the two agree row for row.
     *
     * @return array<string, mixed>
     */
    public static function industryLenses(int $periodMonths = self::DEFAULT_PERIOD_MONTHS): array
    {
        [$periodStart, $priorStart, $now] = self::periodBounds($periodMonths);

        return self::computeIndustryLenses(
            self::industryCounts($periodStart, $priorStart, $now),
            self::TOP_N,
            self::INDUSTRY_LENS_MIN_BUSINESSES,
        );
    }

    /**
     * The ranking itself: facts in, three lenses out, no database.
     *
     * Split from industryLenses() so the rules that decide what a reader sees —
     * the floor, the three orderings, and the refusal to pad — can be tested
     * against a register built by hand rather than by seeding one.
     *
     * `qualifying` is how many lines the lens could have drawn, before the six
     * slots cut it. The screen prints it whenever it is under six, because a
     * chart with four lines on it and no explanation reads as a chart that lost
     * two. Nothing is padded to fill the slots: a steady line has not declined,
     * and putting it in the declining lens to make the count come out at six
     * would be inventing a finding.
     *
     * @param  list<array{industry: string, psic_code: string, count: int, registrations: int, prior: int}>  $facts
     * @return array<string, mixed>
     */
    public static function computeIndustryLenses(array $facts, int $slots, int $minBusinesses): array
    {
        $rows = [];
        foreach ($facts as $fact) {
            $current = (int) $fact['registrations'];
            $prior = (int) $fact['prior'];
            $delta = $current - $prior;

            $rows[] = [
                'industry' => (string) $fact['industry'],
                'psic_code' => (string) $fact['psic_code'],
                'count' => (int) $fact['count'],
                'registrations' => $current,
                'prior' => $prior,
                'delta' => $delta,
                'direction' => match (true) {
                    $delta > 0 => 'growing',
                    $delta < 0 => 'declining',
                    default => 'steady',
                },
            ];
        }

        $aboveFloor = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['count'] >= $minBusinesses,
        ));

        // Identical to computeIndustries(): count, then change, then PSIC code,
        // so ties hold a stable order across reloads and between this panel and
        // that one rather than following whatever order the rows arrived in.
        $largest = $rows;
        usort(
            $largest,
            static fn (array $a, array $b) => [$b['count'], $b['delta'], $a['psic_code']]
                <=> [$a['count'], $a['delta'], $b['psic_code']],
        );

        $growing = array_values(array_filter(
            $aboveFloor,
            static fn (array $row): bool => $row['delta'] > 0,
        ));
        usort(
            $growing,
            static fn (array $a, array $b) => [$b['delta'], $b['count'], $a['psic_code']]
                <=> [$a['delta'], $a['count'], $b['psic_code']],
        );

        $declining = array_values(array_filter(
            $aboveFloor,
            static fn (array $row): bool => $row['delta'] < 0,
        ));
        // Ascending on delta: -5 is a bigger decline than -1. Then count
        // descending, so where two lines both lost one registration the larger
        // trade is the more notable loss.
        usort(
            $declining,
            static fn (array $a, array $b) => [$a['delta'], $b['count'], $a['psic_code']]
                <=> [$b['delta'], $a['count'], $b['psic_code']],
        );

        return [
            'slots' => $slots,
            'min_businesses' => $minBusinesses,
            'lines_on_record' => count($rows),
            'above_floor' => count($aboveFloor),
            'lenses' => [
                [
                    'key' => 'largest',
                    'label' => 'Largest',
                    // No floor: this lens ranks by the very number a floor would
                    // test, so every row it can draw already clears one.
                    'floored' => false,
                    'qualifying' => count($rows),
                    'rows' => array_slice($largest, 0, $slots),
                ],
                [
                    'key' => 'growing',
                    'label' => 'Fastest growing',
                    'floored' => true,
                    'qualifying' => count($growing),
                    'rows' => array_slice($growing, 0, $slots),
                ],
                [
                    'key' => 'declining',
                    'label' => 'Fastest declining',
                    'floored' => true,
                    'qualifying' => count($declining),
                    'rows' => array_slice($declining, 0, $slots),
                ],
            ],
        ];
    }

    /* ── facts ─────────────────────────────────────────────────────────── */

    /**
     * The period end is inclusive because it is "now": timestamps are stored to
     * the second, so an exclusive bound silently drops anything registered in
     * the current second. Interior boundaries stay exclusive so a row cannot
     * land in both the period and the one before it.
     */
    private static function countRegistrations(CarbonImmutable $from, CarbonImmutable $to, bool $includeEnd = false): int
    {
        return Business::withTrashed()
            ->where('created_at', '>=', $from)
            ->where('created_at', $includeEnd ? '<=' : '<', $to)
            ->count();
    }

    /**
     * Every closure inside the window, as a list of the dates they happened on.
     *
     * Two things close a business and they are dated by different columns, so
     * this is the one place that knows the union. `closures()` counts what comes
     * back and `closureMonths()` buckets it; neither reads the register itself.
     * That is deliberate — the headline "Closures in period" and the sum of the
     * Closure Trend are the same number in two shapes, and a reader who adds up
     * the chart and gets a different figure from the card above it has been told
     * two things by one screen. Two closure counts that disagree would be worse
     * than the dead chart this change exists to fix.
     *
     * Soft deletes are matched with `onlyTrashed` and blacklistings with the
     * default scope, so the two sets cannot overlap: a business that was
     * blacklisted and later removed from the register closed once, on the day it
     * was removed, and is counted once.
     *
     * A blacklisted business with a null `status_changed_at` is absent from
     * both. See the class docblock.
     *
     * @return list<CarbonImmutable>
     */
    private static function closureDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $removed = Business::onlyTrashed()
            ->where('deleted_at', '>=', $from)
            ->where('deleted_at', '<=', $to)
            ->pluck('deleted_at');

        $blacklisted = Business::query()
            ->where('status', Business::STATUS_BLACKLISTED)
            ->whereNotNull('status_changed_at')
            ->where('status_changed_at', '>=', $from)
            ->where('status_changed_at', '<=', $to)
            ->pluck('status_changed_at');

        return $removed->concat($blacklisted)
            ->map(static fn ($date): CarbonImmutable => CarbonImmutable::parse($date))
            ->values()
            ->all();
    }

    private static function closures(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return count(self::closureDates($from, $to));
    }

    /**
     * Active / Expired / Inactive / Closed, from permits, soft deletes and
     * blacklistings.
     *
     * Unlike the trend, this needs no date: it is a snapshot of how the register
     * stands right now, so a blacklisted business counts as Closed whether or
     * not anyone recorded when the sanction landed. That is the one place the
     * two panels legitimately disagree — a blacklisting with a null
     * `status_changed_at` is Closed here and invisible on the chart — and it is
     * the honest split, because "this business is struck off" is knowable today
     * while "it was struck off in March" is not.
     *
     * @return array<string, int>
     */
    private static function statusCounts(): array
    {
        $today = CarbonImmutable::now()->toDateString();

        $businesses = Business::withTrashed()->get(['id', 'deleted_at', 'status']);

        // One pass over permits: has each business ever held one, and does it
        // hold one that is valid today?
        $everPermitted = [];
        $livePermitted = [];
        foreach (DB::table('permits')->get(['business_id', 'status', 'valid_until']) as $permit) {
            $everPermitted[$permit->business_id] = true;
            if ($permit->status === PermitStatus::Active->value
                && CarbonImmutable::parse($permit->valid_until)->toDateString() >= $today) {
                $livePermitted[$permit->business_id] = true;
            }
        }

        $counts = ['active' => 0, 'expired' => 0, 'inactive' => 0, 'closed' => 0];

        foreach ($businesses as $business) {
            // Closed is tested first, and both ways of closing are tested
            // together: a blacklisted business may well still hold a permit
            // valid today — nothing revokes it — and without this it would be
            // counted as Active, which is exactly the gap this fixes.
            if ($business->deleted_at !== null || $business->status === Business::STATUS_BLACKLISTED) {
                $counts['closed']++;
            } elseif (! isset($everPermitted[$business->id])) {
                $counts['inactive']++;
            } elseif (isset($livePermitted[$business->id])) {
                $counts['active']++;
            } else {
                $counts['expired']++;
            }
        }

        return $counts;
    }

    /**
     * New registrations per barangay, this period and the one before it.
     *
     * @return list<array{barangay: string, registrations: int, prior: int}>
     */
    private static function barangayCounts(CarbonImmutable $periodStart, CarbonImmutable $priorStart, CarbonImmutable $now): array
    {
        $rows = DB::table('businesses')
            ->join('business_addresses', 'business_addresses.business_id', '=', 'businesses.id')
            ->join('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
            ->where('businesses.created_at', '>=', $priorStart)
            ->where('businesses.created_at', '<=', $now)
            ->get(['barangays.name as barangay', 'businesses.created_at']);

        $current = [];
        $prior = [];
        foreach ($rows as $row) {
            $createdAt = CarbonImmutable::parse($row->created_at);
            if ($createdAt >= $periodStart) {
                $current[$row->barangay] = ($current[$row->barangay] ?? 0) + 1;
            } else {
                $prior[$row->barangay] = ($prior[$row->barangay] ?? 0) + 1;
            }
        }

        $names = array_unique([...array_keys($current), ...array_keys($prior)]);
        sort($names);

        $out = [];
        foreach ($names as $name) {
            $out[] = [
                'barangay' => (string) $name,
                'registrations' => $current[$name] ?? 0,
                'prior' => $prior[$name] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Closures per month across the period.
     *
     * @return list<array{month: string, closures: int}>
     */
    private static function closureMonths(CarbonImmutable $periodStart, CarbonImmutable $now, int $periodMonths): array
    {
        $closures = self::closureDates($periodStart, $now);

        // Walk from the first of the month: adding months to, say, the 29th
        // overflows past February and would drop that bucket entirely.
        $buckets = [];
        $cursor = $periodStart->startOfMonth();
        for ($i = 0; $i <= $periodMonths; $i++) {
            $buckets[$cursor->addMonths($i)->format('Y-m')] = 0;
        }

        foreach ($closures as $closedAt) {
            $month = $closedAt->format('Y-m');
            if (array_key_exists($month, $buckets)) {
                $buckets[$month]++;
            }
        }

        $trend = [];
        foreach ($buckets as $month => $count) {
            $trend[] = ['month' => $month, 'closures' => $count];
        }

        return $trend;
    }

    /**
     * Registrations by line of business (PSIC), this period against the last.
     *
     * `count` is how many live businesses carry that line today; the two period
     * counts are what "growing" and "declining" read.
     *
     * @return list<array{industry: string, psic_code: string, count: int, registrations: int, prior: int}>
     */
    private static function industryCounts(CarbonImmutable $periodStart, CarbonImmutable $priorStart, CarbonImmutable $now): array
    {
        $lines = DB::table('business_lines')
            ->join('businesses', 'businesses.id', '=', 'business_lines.business_id')
            ->join('psic_codes', 'psic_codes.id', '=', 'business_lines.psic_code_id')
            ->whereNull('businesses.deleted_at')
            ->orderBy('psic_codes.code')
            ->get(['psic_codes.code as psic_code', 'psic_codes.title as industry', 'businesses.created_at']);

        $totals = [];
        foreach ($lines as $line) {
            $key = $line->psic_code;
            $totals[$key] ??= [
                'industry' => (string) $line->industry,
                'psic_code' => (string) $line->psic_code,
                'count' => 0,
                'registrations' => 0,
                'prior' => 0,
            ];
            $totals[$key]['count']++;

            $createdAt = CarbonImmutable::parse($line->created_at);
            if ($createdAt >= $periodStart && $createdAt <= $now) {
                $totals[$key]['registrations']++;
            } elseif ($createdAt >= $priorStart && $createdAt < $periodStart) {
                $totals[$key]['prior']++;
            }
        }

        return array_values($totals);
    }

    /**
     * One survival observation per business: how many renewal cycles it cleared,
     * and whether it then lapsed or is still being watched.
     *
     * WHY THIS IS A SURVIVAL MEASURE AND NOT A RATIO
     *
     * The paper's formula reads "businesses that continued renewing on time ÷
     * total businesses in the group", and taken literally as a single division
     * that figure is wrong in a way that flatters the LGU: a business registered
     * last month has not had a renewal to miss, so counting it in the denominator
     * drags the rate toward whatever share of the register is simply too new to
     * have failed. Cohort survival is the measure that handles this — businesses
     * still inside their current permit are *censored*, meaning they count while
     * they were observed and stop counting once there is nothing left to observe.
     * That is why the paper names the `survival` package.
     *
     * WHAT A CYCLE IS. Each business's mayor's-permit chain is ordered by
     * `valid_from`. Cycle k is the k-th renewal of that chain. A renewal is on
     * time when the new permit takes effect before the old one's cover ends
     * (within COVERAGE_GAP_TOLERANCE_DAYS). The observation for a business is:
     *
     *   time  = the cycle it failed at, or the last cycle it is known to have
     *           cleared if it has not failed
     *   event = 1 if it lapsed at `time`, 0 if it is still under observation
     *
     * A business with one permit still in force has time 0 and event 0: it has
     * not yet reached its first renewal, so it informs no cycle and correctly
     * drops out of the risk set. A business whose only permit expired more than
     * RENEWAL_GRACE_DAYS ago with no successor failed at cycle 1.
     *
     * The cohort is the year the chain started, which is what lets the screen show
     * whether recent cohorts renew better or worse than older ones.
     *
     * @return list<array{cohort: string, business_id: int, time: int, event: int}>
     */
    private static function cohortObservations(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();

        $rows = DB::table('permits')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->whereNull('businesses.deleted_at')
            ->where('permit_types.code', self::CYCLE_PERMIT_TYPE)
            ->whereIn('permits.status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->orderBy('permits.business_id')
            ->orderBy('permits.valid_from')
            ->orderBy('permits.id')
            ->get(['permits.business_id', 'permits.valid_from', 'permits.valid_until']);

        $chains = [];
        foreach ($rows as $row) {
            $chains[(int) $row->business_id][] = [
                'from' => CarbonImmutable::parse($row->valid_from)->startOfDay(),
                'until' => CarbonImmutable::parse($row->valid_until)->startOfDay(),
            ];
        }

        $observations = [];
        foreach ($chains as $businessId => $chain) {
            $cohort = $chain[0]['from']->format('Y');
            $cleared = 0;
            $event = 0;

            for ($i = 0; $i < count($chain); $i++) {
                $current = $chain[$i];
                $next = $chain[$i + 1] ?? null;

                if ($next !== null) {
                    $deadline = $current['until']->addDays(self::COVERAGE_GAP_TOLERANCE_DAYS);
                    if ($next['from']->lessThanOrEqualTo($deadline)) {
                        $cleared++;

                        continue;
                    }

                    // A gap in cover: this is the cycle the business lapsed at.
                    $event = 1;
                    $cleared++;
                    break;
                }

                // End of the chain. Still inside the permit (or its grace) means
                // there is nothing yet to judge; past it with no successor is a
                // lapse at the next cycle.
                if ($today->greaterThan($current['until']->addDays(self::RENEWAL_GRACE_DAYS))) {
                    $event = 1;
                    $cleared++;
                }
            }

            $observations[] = [
                'cohort' => $cohort,
                'business_id' => (int) $businessId,
                'time' => $cleared,
                'event' => $event,
            ];
        }

        return $observations;
    }

    /* ── statistics ────────────────────────────────────────────────────── */

    /**
     * Kaplan-Meier survival over renewal cycles, overall and per cohort.
     *
     * The product-limit estimator: at each cycle t, S(t) = S(t-1) × (1 − d/n)
     * where n is how many businesses reached cycle t and d how many lapsed there.
     * Censored businesses leave the risk set without ever counting as failures,
     * which is the whole reason for using this rather than a single division.
     *
     * This is the same estimator `survival::survfit` implements, written out here
     * rather than taken from a library — it is four lines of arithmetic, and
     * writing it out is what lets the censoring rule above be stated in the same
     * place as the code that applies it.
     *
     * @param  list<array{cohort: string, business_id: int, time: int, event: int}>  $observations
     * @return array<string, mixed>
     */
    private static function computeSurvival(array $observations, string $methodology): array
    {
        $overall = self::survivalCurve($observations);

        $byCohort = [];
        $grouped = [];
        foreach ($observations as $observation) {
            $grouped[(string) $observation['cohort']][] = $observation;
        }
        ksort($grouped);

        foreach ($grouped as $cohort => $rows) {
            $curve = self::survivalCurve($rows);
            $byCohort[] = [
                'cohort' => (string) $cohort,
                'businesses' => $curve['businesses'],
                'renewals_observed' => $curve['renewals_observed'],
                'lapses' => $curve['lapses'],
                'max_cycle' => $curve['max_cycle'],
                'survival' => $curve['survival'],
                'points' => $curve['points'],
            ];
        }

        return [
            'methodology' => $methodology,
            'grace_days' => self::RENEWAL_GRACE_DAYS,
            'businesses' => $overall['businesses'],
            'renewals_observed' => $overall['renewals_observed'],
            'lapses' => $overall['lapses'],
            'max_cycle' => $overall['max_cycle'],
            // The headline: survival through the last cycle any business reached.
            // Null when no business has reached a first renewal yet — there is no
            // cohort to have survived anything.
            'survival' => $overall['survival'],
            'points' => $overall['points'],
            'cohorts' => $byCohort,
        ];
    }

    /**
     * @param  list<array{time: int, event: int}>  $rows
     * @return array<string, mixed>
     */
    private static function survivalCurve(array $rows): array
    {
        $maxCycle = 0;
        $lapses = 0;
        foreach ($rows as $row) {
            $maxCycle = max($maxCycle, (int) $row['time']);
            $lapses += (int) $row['event'];
        }

        $points = [];
        $survival = 1.0;
        $estimable = false;

        for ($t = 1; $t <= $maxCycle; $t++) {
            $atRisk = 0;
            $events = 0;
            foreach ($rows as $row) {
                if ((int) $row['time'] >= $t) {
                    $atRisk++;
                }
                if ((int) $row['time'] === $t && (int) $row['event'] === 1) {
                    $events++;
                }
            }

            if ($atRisk === 0) {
                break;
            }

            $estimable = true;
            $survival *= 1 - ($events / $atRisk);

            $points[] = [
                'cycle' => $t,
                'at_risk' => $atRisk,
                'lapses' => $events,
                'survival' => Rounding::statistic($survival * 100, 1),
            ];
        }

        return [
            'businesses' => count($rows),
            // How many renewal cycles the cohort actually lived through, which is
            // the sample size behind the curve.
            'renewals_observed' => array_sum(array_map(static fn (array $r): int => (int) $r['time'], $rows)),
            'lapses' => $lapses,
            'max_cycle' => $maxCycle,
            'survival' => $estimable ? Rounding::statistic($survival * 100, 1) : null,
            'points' => $points,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{status: string, label: string, count: int, share: float|null}>
     */
    private static function computeStatusSummary(array $counts): array
    {
        $labels = [
            'active' => 'Active',
            'expired' => 'Expired',
            'inactive' => 'Inactive',
            'closed' => 'Closed',
        ];

        $total = 0;
        foreach ($labels as $status => $_label) {
            $total += (int) ($counts[$status] ?? 0);
        }

        $summary = [];
        foreach ($labels as $status => $label) {
            $count = (int) ($counts[$status] ?? 0);
            $summary[] = [
                'status' => $status,
                'label' => $label,
                'count' => $count,
                'share' => $total > 0 ? Rounding::statistic(($count / $total) * 100, 1) : null,
            ];
        }

        return $summary;
    }

    /**
     * Barangays ranked by the INCREASE between periods, as the spec asks — not by
     * how many they have. A barangay with 300 registrations and no change is not
     * growing.
     *
     * @param  list<array{barangay: string, registrations: int, prior: int}>  $facts
     * @return list<array<string, mixed>>
     */
    private static function computeBarangays(array $facts, int $topN): array
    {
        $out = [];
        foreach ($facts as $row) {
            $current = (int) $row['registrations'];
            $prior = (int) $row['prior'];
            $out[] = [
                'barangay' => (string) $row['barangay'],
                'registrations' => $current,
                'prior' => $prior,
                'delta' => $current - $prior,
                'growth_rate' => $prior > 0
                    ? Rounding::statistic((($current - $prior) / $prior) * 100, 1)
                    : null,
            ];
        }

        // Delta descending, then volume, then name so ties hold a stable order.
        usort(
            $out,
            static fn (array $a, array $b) => [$b['delta'], $b['registrations'], $a['barangay']]
                <=> [$a['delta'], $a['registrations'], $b['barangay']],
        );

        return array_slice($out, 0, $topN);
    }

    /**
     * @param  list<array{month: string, closures: int}>  $facts
     * @return list<array{month: string, closures: int}>
     */
    private static function computeClosureTrend(array $facts): array
    {
        $trend = [];
        foreach ($facts as $row) {
            $trend[] = ['month' => (string) $row['month'], 'closures' => (int) $row['closures']];
        }

        return $trend;
    }

    /**
     * @param  list<array{industry: string, psic_code: string, count: int, registrations: int, prior: int}>  $facts
     * @return list<array<string, mixed>>
     */
    private static function computeIndustries(array $facts, int $topN): array
    {
        $out = [];
        foreach ($facts as $row) {
            $current = (int) $row['registrations'];
            $prior = (int) $row['prior'];
            $delta = $current - $prior;

            $out[] = [
                'industry' => (string) $row['industry'],
                'psic_code' => (string) $row['psic_code'],
                'count' => (int) $row['count'],
                'registrations' => $current,
                'prior' => $prior,
                'delta' => $delta,
                'direction' => match (true) {
                    $delta > 0 => 'growing',
                    $delta < 0 => 'declining',
                    default => 'steady',
                },
            ];
        }

        usort(
            $out,
            static fn (array $a, array $b) => [$b['count'], $b['delta'], $a['psic_code']]
                <=> [$a['count'], $a['delta'], $b['psic_code']],
        );

        return array_slice($out, 0, $topN);
    }
}

<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\InspectionResult;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Renewal Risk, computed from the register.
 *
 * The scoring rules and every claim about what the number means live in
 * RenewalRiskScoring — read its docblock before touching either file. In one
 * line: this ranks permits by known risk signals, it does not predict anything,
 * and the score is not a probability.
 *
 * This class gathers the five facts each rule needs and does it in five bulk
 * queries rather than per permit, because the watchlist covers every permit in
 * the expiry window and an N+1 here would be a page load per business.
 *
 * DEFINITIONS THAT ARE CHOICES
 *
 *  - **In scope** is a permit whose `valid_until` falls between LAPSED_GRACE_DAYS
 *    ago and `horizon` days ahead, whose status is Active or Expired, and whose
 *    business is still registered. Recently lapsed permits are included
 *    deliberately: they are the highest-risk rows on the screen, and a watchlist
 *    that dropped them the day they expired would hide its own failures. Revoked
 *    and suspended permits are excluded — those are enforcement states, not
 *    renewal states, and no reminder is going to fix them.
 *  - **A renewal belongs to a permit** through `applications.prior_permit_id`.
 *    That is the only link in the schema between a filing and the permit it
 *    replaces.
 *  - **A renewal was late** when it was submitted after the permit it replaced
 *    had already expired. Filings never submitted are not counted either way —
 *    an abandoned draft is not evidence of lateness.
 *  - **An open finding** is an unticked compliance check on a filing that has
 *    not yet been decided, or a failed/conditional inspection in the last
 *    twelve months. Unticked checks on decided filings are history, not debt.
 *  - **Fee state is read off the renewal filing**, not the business. A business
 *    with no renewal filed owes nothing yet, so it scores `settled` on that rule
 *    and carries its risk on the progress rule instead. Scoring it twice would
 *    double-count one fact.
 *
 * WHICH SLICE YOU ARE LOOKING AT IS NOT A STATISTIC
 *
 * The watchlist is ranked worst first and cut at `limit`, which is right for a
 * follow-up screen and wrong as the ONLY reachable state: this register scores
 * 2,060 permits Low, and not one of them is in the leading 25, so the green
 * badge the spec asks for could never be drawn. Raising `limit` does not fix
 * that — it moves the cut further down the same ranking.
 *
 * So compute() takes a `$view`: a barangay, a band, an action, and an offset.
 * Two different kinds of narrowing, and the difference is the whole reason the
 * counts stay trustworthy:
 *
 *  - **Barangay is a POPULATION filter.** It is applied before scoring, so
 *    `counts`, `scored_permits` and `actions` all describe the same set of
 *    permits the table is drawn from. Ask for Tonsuya and every figure on the
 *    screen is about Tonsuya.
 *  - **Band and action are VIEW filters.** They are applied after the counting,
 *    so the three summary cards keep describing every scored permit in the
 *    population — which is what makes them the legend for this control. The
 *    card says "Low risk 2,060"; pick Low and there are exactly 2,060 rows to
 *    page through. The cards and the table cannot disagree, because the card IS
 *    the row count.
 *
 * `matching` is that row count, and it is what the table's footer states. It is
 * deliberately NOT `scored_permits`: one is "how many rows this filter has",
 * the other is "how many permits the bands are out of", and conflating them is
 * how a footer starts lying about a filtered table.
 *
 * An empty `$view` reproduces the previous behaviour exactly — same ranking,
 * same slice, same keys — which is what lets the R engine keep serving the
 * default screen unchanged. See AnalyticsController::renewalRisk() for why a
 * filtered request is computed locally instead.
 */
final class RenewalRiskAnalytics
{
    /**
     * How far ahead the watchlist looks by default, in days.
     *
     * A full renewal cycle, not a quarter. The KPI cards count every permit in
     * the window, so a 90-day window would report "Low Risk: 0" — not because no
     * permit is low risk but because a permit has to be near expiry to be in the
     * window at all. Covering the year makes the three bands a real distribution
     * of the register instead of a slice of its most urgent edge.
     */
    public const DEFAULT_HORIZON_DAYS = 365;

    /** Permits that lapsed within this many days stay on the watchlist. */
    public const LAPSED_GRACE_DAYS = 60;

    /** Rows in the "Businesses at Risk" table. */
    public const DEFAULT_LIMIT = 25;

    /**
     * The bands and actions a caller may filter on.
     *
     * Read from here rather than written out at the controller, so a rename in
     * RenewalRiskScoring cannot leave a filter quietly accepting a value the
     * scorer no longer produces — which would present as an always-empty table
     * rather than as an error.
     *
     * Note that the action set is one-to-one with the band set: the action is a
     * direct function of the band and not a second judgement (see
     * RenewalRiskScoring::score()). Both filters exist because the client asked
     * for both, and an officer who thinks in "who do I chase today" should not
     * have to translate that into a band first. Combining them is allowed and
     * is an intersection, so Moderate + Immediate follow-up is legitimately
     * empty rather than an error.
     *
     * @var list<string>
     */
    public const BANDS = ['high', 'moderate', 'low'];

    /** @var list<string> */
    public const ACTIONS = ['immediate_follow_up', 'send_reminder', 'monitor'];

    /**
     * The four permit lifecycle states, in the order an officer reads them.
     *
     * These replaced the dashboard's "Permits Approaching Expiry" table, whose
     * first column was 30d / 60d / 90d — three cumulative time windows. The
     * client asked for named states instead, and the axis change is the whole
     * point rather than a relabelling: the old bands answered "when does this
     * expire", these answer "what is the position of this permit", which is the
     * question that decides whether anyone needs to be rung today. A permit 12
     * days out with a renewal already under review and one 12 days out with
     * nothing filed sat in the same 30d cell and are two different mornings.
     *
     * The panel moved here at the same time, and that was overdue. It was on the
     * Analytics Dashboard — a screen about volumes and processing times — while
     * this screen is already the list of permits running out, read by the officer
     * who chases them. Two screens were describing the same population.
     *
     * ── THE RULES, AND WHY EACH ONE IS WHERE THE CUT IS ─────────────────────
     *
     * Evaluated in the order below; the first that matches wins. They are total
     * (the last is unconditional) and mutually exclusive (first match wins), so
     * every permit on the watchlist lands in exactly one and the counts sum to
     * `scored_permits`. RenewalRiskLifecycleTest asserts both.
     *
     *  1. **Overdue / Expired — the expiry date has passed.** This wins over
     *     everything, including a renewal in the queue. A lapsed permit means a
     *     business is trading without cover TODAY; a filing under review does not
     *     restore that cover, and the officer's next move is an enforcement
     *     question rather than a follow-up one. Ranking the renewal above the
     *     lapse would let the most serious row on the register hide inside the
     *     calmest bucket.
     *  2. **Pending Renewal — a renewal was filed and has not been decided.**
     *     Date-independent, exactly as the client specified: a renewal under
     *     review 200 days out and one 5 days out are both "we are handling it".
     *     "Filed" is the register's own event — `applications.submitted_at` —
     *     which is why a DRAFT is not counted here. A draft renewal has never
     *     reached the LGU, nobody has anything to decide, and marking that permit
     *     as handled on the strength of a form nobody has received is the one
     *     failure mode this column can have. The scorer already reads it the same
     *     way: a draft costs 20 of the 25 progress points against 25 for nothing
     *     at all (RenewalRiskScoring::PROGRESS_POINTS).
     *  3. **Near Expiry — inside the reminder window with nothing on file.**
     *     Reached only when rules 1 and 2 did not match, so "no renewal filed
     *     yet" is already true by construction. An APPROVED renewal therefore
     *     never lands here: it was filed and it was granted, so the successor
     *     permit exists and there is nobody to chase. It falls through to Active.
     *  4. **Active / Compliant — everything else.** In force, and either more
     *     than the reminder window away or already renewed.
     *
     * @var list<array{state: string, label: string}>
     */
    public const LIFECYCLE_STATES = [
        ['state' => 'active', 'label' => 'Active / Compliant'],
        ['state' => 'near_expiry', 'label' => 'Near Expiry'],
        ['state' => 'pending_renewal', 'label' => 'Pending Renewal'],
        ['state' => 'overdue', 'label' => 'Overdue / Expired'],
    ];

    /**
     * The renewal stages that count as "filed, not yet decided".
     *
     * Read against the stages RenewalRiskAnalytics::stageFor() produces, which is
     * the ONE place an application status becomes a renewal stage. There is no
     * second definition of "a renewal is in progress" in this codebase and there
     * must not be — the scoring rule and this column have to agree about the same
     * filing or the table contradicts itself row by row.
     *
     *  - `in_progress` covers submitted, pending payment, under review and for
     *    inspection: the filing is with the LGU.
     *  - `returned` is a filing the LGU reviewed and sent back for corrections.
     *    It is still open and still awaiting a decision, so it is still pending —
     *    the chase is "finish what you started", not "you have not started".
     *
     * Not here, and each for a stated reason: `draft` was never submitted (see
     * rule 2 above), `approved` and `rejected` are decisions, and a cancelled
     * filing leaves nothing standing at all — stageFor() returns null for it and
     * the permit reads `none`.
     *
     * @var list<string>
     */
    public const PENDING_RENEWAL_STAGES = ['in_progress', 'returned'];

    /** Drivers shown per row before the rest are folded away. */
    private const DRIVERS_PER_ROW = 3;

    /** Window for counting a failed inspection against a business, in months. */
    private const FINDINGS_LOOKBACK_MONTHS = 12;

    /**
     * The sentence the screen, the CSV, and any future PDF all have to carry.
     * Kept server-side on purpose: if the honesty statement lived only in the
     * React copy, an export would quietly ship the numbers without it.
     */
    public const METHODOLOGY = 'Each permit is checked against five things: how soon it expires, whether a '
        .'renewal has been filed, whether this business has renewed late before, open compliance findings, '
        .'and unpaid fees. Each adds points, up to 100. A higher score means more warning signs — it is not '
        .'a prediction, and it does not say how likely a renewal is to be late.';

    /** The R endpoint that scores this dataset. */
    public const R_ENDPOINT = '/renewal-risk';

    /**
     * The facts each in-scope permit carries, gathered from the register.
     *
     * This is the whole SQL half of the feature and the payload
     * `analytics:refresh` pushes to R. The split matters here more than anywhere
     * else in the analytics code, because the two halves are different kinds of
     * decision: *what counts as a risk signal* is a register question settled by
     * the five bulk queries below, and *how signals become a score and a band* is
     * the rule set — which lives in R, with RenewalRiskScoring as its fallback.
     *
     * Note what is NOT here: no scores, no bands, no ranking. R gets facts.
     *
     * @return array<string, mixed>
     */
    public static function dataset(int $horizonDays = self::DEFAULT_HORIZON_DAYS, int $limit = self::DEFAULT_LIMIT): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $windowStart = $today->subDays(self::LAPSED_GRACE_DAYS);
        $windowEnd = $today->addDays($horizonDays);

        $frame = [
            'params' => ['days' => $horizonDays, 'limit' => $limit],
            'now' => $now->toISOString(),
            'lapsed_grace_days' => self::LAPSED_GRACE_DAYS,
            'window_start' => $windowStart->toDateString(),
            'window_end' => $windowEnd->toDateString(),
            'drivers_per_row' => self::DRIVERS_PER_ROW,
            'methodology' => self::METHODOLOGY,
            // The rule set travels with the facts. R reads the weights, bands and
            // thresholds out of this payload instead of keeping its own copy, so
            // there is exactly one place the numbers live (RenewalRiskScoring)
            // and no way for the two engines to disagree about them. What R
            // duplicates is the logic, which is what the parity test checks.
            'parameters' => RenewalRiskScoring::parameters(),
            'rulebook' => RenewalRiskScoring::rulebook(),
        ];

        $permits = self::permitsInScope($windowStart, $windowEnd);

        if ($permits === []) {
            return $frame + ['reminders_sent' => 0, 'permits' => []];
        }

        $permitIds = array_column($permits, 'id');
        $businessIds = array_values(array_unique(array_column($permits, 'business_id')));

        $renewals = self::renewalsByPriorPermit($permitIds);
        $punctuality = self::punctualityByBusiness($businessIds, $permitIds);
        $findings = self::openFindingsByBusiness($businessIds, $now);
        $feeStates = self::feeStateByApplication(array_column($renewals, 'application_id'));
        $noticeCounts = self::noticeCountsByPermit($permitIds);

        $rows = [];
        foreach ($permits as $permit) {
            $renewal = $renewals[$permit['id']] ?? null;
            $validUntil = CarbonImmutable::parse($permit['valid_until'])->startOfDay();

            $rows[] = [
                'permit_id' => $permit['id'],
                'permit_number' => $permit['permit_number'],
                'business_id' => $permit['business_id'],
                'business' => $permit['business'],
                'barangay' => $permit['barangay'],
                'permit_type' => $permit['permit_type'],
                'valid_until' => $validUntil->toDateString(),
                // Whole days, signed: negative means the permit has already
                // lapsed. Computed here, not in R, because "today" is Laravel's
                // clock and R must stay a pure function of its input.
                'days_to_expiry' => (int) $today->diffInDays($validUntil, false),
                'renewal_stage' => $renewal['stage'] ?? 'none',
                'renewal_tracking_id' => $renewal['tracking_id'] ?? null,
                'prior_renewals' => $punctuality[$permit['business_id']]['total'] ?? 0,
                'late_renewals' => $punctuality[$permit['business_id']]['late'] ?? 0,
                'open_findings' => $findings[$permit['business_id']] ?? 0,
                'fee_state' => $renewal === null ? 'settled' : ($feeStates[$renewal['application_id']] ?? 'settled'),
                'reminders_sent' => $noticeCounts[$permit['id']] ?? 0,
            ];
        }

        return $frame + ['reminders_sent' => array_sum($noticeCounts), 'permits' => $rows];
    }

    /**
     * Which of the four lifecycle states one permit is in.
     *
     * The whole state machine, in one place, so the screen, the PDF and the test
     * cannot each have their own reading of it. See LIFECYCLE_STATES for why the
     * cuts are where they are; the order of the branches below IS the precedence
     * and changing it changes the answer.
     *
     * @param  int  $daysToExpiry  signed — negative once the permit has lapsed
     * @param  string  $renewalStage  as produced by stageFor(), or 'none'
     */
    public static function lifecycleState(int $daysToExpiry, string $renewalStage): string
    {
        return match (true) {
            // The permit has lapsed. Beats a renewal in the queue: cover is gone
            // today and a pending filing does not give it back.
            $daysToExpiry < 0 => 'overdue',
            // Filed and undecided, whatever the date.
            in_array($renewalStage, self::PENDING_RENEWAL_STAGES, true) => 'pending_renewal',
            // An approved renewal is a filing that was made and granted, so this
            // is never the "nothing filed yet" case however close the date is.
            $renewalStage === 'approved' => 'active',
            $daysToExpiry <= RenewalRiskScoring::RENEWAL_DUE_WITHIN_DAYS => 'near_expiry',
            default => 'active',
        };
    }

    /**
     * The Permit Lifecycle panel: the watchlist split four ways, by permit type.
     *
     * ── WHY THIS IS NOT PART OF compute() ───────────────────────────────────
     *
     * It cannot be. compute() is the PHP half of a two-engine contract:
     * AnalyticsParityTest walks R's golden output against compute()'s key for
     * key, in BOTH directions, and reports any key present in one and absent
     * from the other. R does not compute lifecycle states, r/R/service.R is out
     * of bounds for this change, and a new key on compute() would therefore fail
     * parity on the first run — the exact "passes locally, forks the engines"
     * outcome that test exists to prevent.
     *
     * So it is a serve-time decoration, joining the two that are already there
     * for the same class of reason (see AnalyticsController::decorateRenewalRisk):
     * the barangay menu, which is a register question rather than a statistic,
     * and the officer follow-up marks, which are live state rather than a nightly
     * figure. This one is a third kind — a statistic R was never asked for — and
     * it is honest about that rather than smuggled into the snapshot.
     *
     * ── WHY THE POPULATION IS THE WATCHLIST, EXACTLY ───────────────────────
     *
     * The same permitsInScope() call the scored table is drawn from, narrowed by
     * the same barangay filter compute() applies before it counts. That is not
     * tidiness: it is what makes `total` identical to `scored_permits`, so the
     * four states are a partition of the very permits the three risk cards
     * describe and the two panels cannot disagree about how many permits exist.
     * Re-deriving the window here with a second `where` clause is how that would
     * quietly stop being true.
     *
     * Consequence worth stating on screen, and stated in the definitions:
     * "Overdue / Expired" is bounded by LAPSED_GRACE_DAYS. A permit that lapsed
     * four months ago has fallen off the watchlist and is not counted here.
     *
     * ── COST ────────────────────────────────────────────────────────────────
     *
     * Two bulk queries, not five: the state needs only the expiry date and the
     * renewal stage, so the punctuality, findings and fee lookups a full
     * dataset() pays for are not run. Both are queries this endpoint's own
     * snapshot path already runs shapes of, and neither is per row.
     *
     * @return array{
     *     columns: list<array{code: string, label: string}>,
     *     rows: list<array{state: string, label: string, counts: array<string, int>, total: int}>,
     *     total: int,
     *     near_expiry_days: int,
     *     lapsed_grace_days: int,
     * }
     */
    public static function lifecycle(int $horizonDays = self::DEFAULT_HORIZON_DAYS, ?string $barangay = null): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $permits = self::permitsInScope($today->subDays(self::LAPSED_GRACE_DAYS), $today->addDays($horizonDays));

        if ($barangay !== null && $barangay !== '') {
            $permits = array_values(array_filter(
                $permits,
                static fn (array $permit): bool => $permit['barangay'] === $barangay,
            ));
        }

        $renewals = $permits === []
            ? []
            : self::renewalsByPriorPermit(array_column($permits, 'id'));

        /*
         * Columns are read off the permits actually on the watchlist rather than
         * off the permit_types table, for the reason the dashboard panel gave
         * for reading them off the register: a column of zeros for a type this
         * LGU has never issued is noise, and a type it has issued must never be
         * silently absent. Ordered by code so the columns do not reshuffle
         * between refreshes.
         */
        $columns = [];
        foreach ($permits as $permit) {
            $columns[$permit['permit_type_code']] = $permit['permit_type'];
        }
        ksort($columns);

        $blank = array_fill_keys(array_keys($columns), 0);

        $rows = [];
        foreach (self::LIFECYCLE_STATES as $state) {
            $rows[$state['state']] = $state + ['counts' => $blank, 'total' => 0];
        }

        foreach ($permits as $permit) {
            $validUntil = CarbonImmutable::parse($permit['valid_until'])->startOfDay();

            $state = self::lifecycleState(
                (int) $today->diffInDays($validUntil, false),
                $renewals[$permit['id']]['stage'] ?? 'none',
            );

            // One increment per permit, into one row. That single statement is
            // what makes the buckets mutually exclusive and total, and it is why
            // lifecycleState() returns a state rather than a list of matches.
            $rows[$state]['counts'][$permit['permit_type_code']]++;
            $rows[$state]['total']++;
        }

        return [
            'columns' => array_map(
                static fn (string $code, string $label): array => ['code' => $code, 'label' => $label],
                array_keys($columns),
                array_values($columns),
            ),
            'rows' => array_values($rows),
            'total' => count($permits),
            'near_expiry_days' => RenewalRiskScoring::RENEWAL_DUE_WITHIN_DAYS,
            'lapsed_grace_days' => self::LAPSED_GRACE_DAYS,
        ];
    }

    /**
     * @param  array{barangay?: string|null, band?: string|null, action?: string|null, offset?: int}  $view
     * @return array<string, mixed>
     */
    public static function build(
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
        int $limit = self::DEFAULT_LIMIT,
        array $view = [],
    ): array {
        return self::compute(self::dataset($horizonDays, $limit), $view);
    }

    /**
     * The barangays the filter may offer, for a given horizon.
     *
     * Read from the register rather than from the reference table, because the
     * two answer different questions: `barangays` lists every barangay in
     * Malabon, and this lists the ones with a permit on the watchlist. Offering
     * a barangay that can only ever return an empty table is offering a broken
     * control.
     *
     * Deliberately computed against the UNFILTERED window, so choosing a
     * barangay does not collapse the menu to the one already chosen — a filter
     * you cannot back out of is a filter that has trapped its reader.
     *
     * The shape is not arbitrary. Written the obvious way — one join from
     * permits through businesses and addresses to barangays, DISTINCT on the
     * name — this took 2.8 SECONDS on the seeded register, on every page load,
     * to produce twenty-one strings. Narrowing to the business ids first and
     * then asking the address table takes about fifteen milliseconds, because
     * the second half is then a lookup over hundreds of rows instead of a
     * distinct sort over a five-thousand-row join. Kept as a subquery rather
     * than two round trips so a register with more businesses than SQLite will
     * bind parameters for does not quietly stop working.
     *
     * @return list<string>
     */
    public static function barangaysInScope(int $horizonDays = self::DEFAULT_HORIZON_DAYS): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $from = $today->subDays(self::LAPSED_GRACE_DAYS)->toDateString();
        $to = $today->addDays($horizonDays)->toDateString();

        $names = DB::table('business_addresses')
            ->join('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
            ->where('business_addresses.address_type', '=', 'business_location')
            ->whereIn('business_addresses.business_id', static function ($query) use ($from, $to) {
                $query->from('permits')
                    ->join('businesses', 'businesses.id', '=', 'permits.business_id')
                    ->whereNull('businesses.deleted_at')
                    ->whereIn('permits.status', [PermitStatus::Active->value, PermitStatus::Expired->value])
                    ->whereDate('permits.valid_until', '>=', $from)
                    ->whereDate('permits.valid_until', '<=', $to)
                    ->select('permits.business_id');
            })
            ->distinct()
            ->orderBy('barangays.name')
            ->pluck('barangays.name');

        return array_values(array_map(static fn ($name): string => (string) $name, $names->all()));
    }

    /**
     * The local (PHP) engine: facts in, scored watchlist out, no database.
     *
     * R's `POST /renewal-risk` returns this same schema from the same facts. The
     * numbers must agree — AnalyticsParityTest is what enforces that, and without
     * it the fallback would quietly become a second, divergent rule set.
     *
     * The `$view` argument is NOT pushed to R and R is never asked for a
     * filtered watchlist: `analytics:refresh` only ever sends the unfiltered
     * variants in config/analytics.php, and a request carrying filters keys to
     * a snapshot that cannot exist, so it lands here. That is the reason the
     * two engines cannot drift over filtering — only one of them does any.
     *
     * @param  array<string, mixed>  $dataset  as returned by dataset()
     * @param  array{barangay?: string|null, band?: string|null, action?: string|null, offset?: int}  $view
     * @return array<string, mixed>
     */
    public static function compute(array $dataset, array $view = []): array
    {
        // Echoed, not re-parsed: see the note in ProcessingTimeAnalytics::compute().
        $now = (string) $dataset['now'];
        $limit = (int) $dataset['params']['limit'];
        $driversPerRow = (int) ($dataset['drivers_per_row'] ?? self::DRIVERS_PER_ROW);
        $filters = self::normaliseView($view);

        $rows = [];
        $counts = ['high' => 0, 'moderate' => 0, 'low' => 0];

        foreach ($dataset['permits'] as $permit) {
            /*
             * The population filter, and the only one applied before the count.
             * Everything downstream — counts, scored_permits, the action panel
             * — therefore describes exactly the permits the table is drawn
             * from. Move this below the counting and the summary cards start
             * describing the whole city while the table shows one barangay.
             */
            if ($filters['barangay'] !== null && ($permit['barangay'] ?? null) !== $filters['barangay']) {
                continue;
            }

            $facts = [
                'days_to_expiry' => (int) $permit['days_to_expiry'],
                'renewal_stage' => (string) $permit['renewal_stage'],
                'prior_renewals' => (int) $permit['prior_renewals'],
                'late_renewals' => (int) $permit['late_renewals'],
                'open_findings' => (int) $permit['open_findings'],
                'fee_state' => (string) $permit['fee_state'],
            ];

            $scored = RenewalRiskScoring::score($facts);
            $counts[$scored['band']]++;

            $rows[] = [
                'permit_id' => $permit['permit_id'],
                'permit_number' => $permit['permit_number'],
                'business_id' => $permit['business_id'],
                'business' => $permit['business'],
                'barangay' => $permit['barangay'],
                'permit_type' => $permit['permit_type'],
                'valid_until' => $permit['valid_until'],
                'days_to_expiry' => $facts['days_to_expiry'],
                'score' => $scored['score'],
                'band' => $scored['band'],
                'band_label' => $scored['band_label'],
                'action' => $scored['action'],
                'action_label' => $scored['action_label'],
                'renewal_stage' => $facts['renewal_stage'],
                'renewal_tracking_id' => $permit['renewal_tracking_id'] ?? null,
                'reminders_sent' => (int) $permit['reminders_sent'],
                // Only the drivers that actually cost points; a row listing
                // "Fees settled: 0" is noise dressed as transparency.
                'drivers' => array_slice(
                    array_values(array_filter($scored['drivers'], static fn (array $d): bool => $d['points'] > 0)),
                    0,
                    $driversPerRow,
                ),
            ];
        }

        // Highest score first, then soonest expiry: two permits on the same
        // score are not equally urgent.
        usort($rows, static fn (array $a, array $b) => [$b['score'], $a['days_to_expiry']] <=> [$a['score'], $b['days_to_expiry']]);

        /*
         * The view filters, applied after the counting. `$matching` is the
         * length of the filtered list and is what the table's footer states —
         * never `scored_permits`, which is the denominator the bands are out
         * of and is a larger number the moment any band is selected.
         */
        $matching = $filters['band'] === null && $filters['action'] === null
            ? $rows
            : array_values(array_filter($rows, static fn (array $row): bool => (
                ($filters['band'] === null || $row['band'] === $filters['band'])
                && ($filters['action'] === null || $row['action'] === $filters['action'])
            )));

        $perPage = max(1, $limit);
        $offset = self::pageStart($filters['offset'], count($matching), $perPage);

        /*
         * The three view fields are added ONLY when a view was actually asked
         * for, and that is not tidiness — it is what keeps AnalyticsParityTest
         * meaningful.
         *
         * That test compares this function's output against R's, key for key,
         * over a shared fixture. R does no filtering and never will (it is only
         * ever handed the whole watchlist), so emitting `filters`, `matching`
         * and `offset` unconditionally makes the two schemas differ on every
         * run and the parity check has to be loosened to accommodate keys it
         * was written to catch. An unfiltered compute is therefore byte-for-key
         * identical to R's, which is exactly the claim parity exists to make;
         * a filtered one is a shape R was never asked to produce.
         *
         * AnalyticsController fills the same three in for an R-served payload,
         * with the values that are true of an unfiltered result by definition.
         */
        $view = $filters['barangay'] !== null || $filters['band'] !== null
            || $filters['action'] !== null || $filters['offset'] > 0
            ? [
                /*
                 * What was actually applied, echoed rather than assumed.
                 * Unknown band or action values are dropped instead of rejected
                 * (a stray query string should not 500 a dashboard), and
                 * dropping them silently would leave the screen labelled "Low
                 * risk" over an unfiltered table. The echo is what makes the
                 * leniency honest — the client renders these, not what it
                 * asked for.
                 */
                'filters' => [
                    'barangay' => $filters['barangay'],
                    'band' => $filters['band'],
                    'action' => $filters['action'],
                ],
                'matching' => count($matching),
                'offset' => $offset,
            ]
            : [];

        return $view + [
            'generated_at' => $now,
            'horizon_days' => (int) $dataset['params']['days'],
            'lapsed_grace_days' => (int) $dataset['lapsed_grace_days'],
            'window_start' => (string) $dataset['window_start'],
            'window_end' => (string) $dataset['window_end'],
            'scored_permits' => count($rows),
            'counts' => $counts,
            'reminders_sent' => (int) $dataset['reminders_sent'],
            'at_risk' => array_slice($matching, $offset, $perPage),
            'actions' => self::actionTotals($counts),
            'rulebook' => RenewalRiskScoring::rulebook(),
            'thresholds' => [
                'high' => RenewalRiskScoring::HIGH_THRESHOLD,
                'moderate' => RenewalRiskScoring::MODERATE_THRESHOLD,
            ],
            'methodology' => (string) ($dataset['methodology'] ?? self::METHODOLOGY),
        ];
    }

    /**
     * A caller's `$view` reduced to the four things compute() acts on.
     *
     * Blank strings and the sentinel "all" both mean "no filter": the screen's
     * selects carry an "All" option and posting its value must not be a
     * barangay named "all". Unknown bands and actions are dropped for the
     * reason given where `filters` is echoed.
     *
     * @param  array{barangay?: string|null, band?: string|null, action?: string|null, offset?: int}  $view
     * @return array{barangay: string|null, band: string|null, action: string|null, offset: int}
     */
    private static function normaliseView(array $view): array
    {
        $clean = static function (mixed $value): ?string {
            $value = is_string($value) ? trim($value) : null;

            return ($value === null || $value === '' || $value === 'all') ? null : $value;
        };

        $band = $clean($view['band'] ?? null);
        $action = $clean($view['action'] ?? null);

        return [
            'barangay' => $clean($view['barangay'] ?? null),
            'band' => in_array($band, self::BANDS, true) ? $band : null,
            'action' => in_array($action, self::ACTIONS, true) ? $action : null,
            'offset' => max(0, (int) ($view['offset'] ?? 0)),
        ];
    }

    /**
     * The first row of the page to return, snapped onto a page boundary.
     *
     * An offset past the end is not an error — it is what a reader who was on
     * page four of High risk gets the moment they switch to Moderate, of which
     * there are fewer. Returning nothing would read as "no moderate-risk
     * permits", which is false, so the last populated page is returned
     * instead and the footer's "showing 51–60 of 60" says where they landed.
     */
    private static function pageStart(int $offset, int $total, int $perPage): int
    {
        if ($total === 0 || $offset < $total) {
            return $total === 0 ? 0 : $offset;
        }

        return intdiv($total - 1, $perPage) * $perPage;
    }

    /**
     * Permits whose expiry falls in the window, with the business and barangay
     * the table needs.
     *
     * `permit_type_code` is carried for the lifecycle panel's columns and is
     * deliberately NOT copied onto the dataset rows in dataset(): those rows are
     * the payload pushed to R, and a field R was never sent is a field the parity
     * check would report as PHP-only. The scored table shows the type's full
     * name; only the lifecycle table, whose headings are four characters wide,
     * needs the code.
     *
     * @return list<array{id: int, permit_number: string, business_id: int, business: string, barangay: string|null, permit_type: string, permit_type_code: string, valid_until: string}>
     */
    private static function permitsInScope(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('permits')
            ->join('businesses', 'businesses.id', '=', 'permits.business_id')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->leftJoin('business_addresses', function ($join) {
                $join->on('business_addresses.business_id', '=', 'businesses.id')
                    ->where('business_addresses.address_type', '=', 'business_location');
            })
            ->leftJoin('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
            ->whereNull('businesses.deleted_at')
            ->whereIn('permits.status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->whereDate('permits.valid_until', '>=', $from->toDateString())
            ->whereDate('permits.valid_until', '<=', $to->toDateString())
            ->orderBy('permits.valid_until')
            ->get([
                'permits.id',
                'permits.permit_number',
                'permits.business_id',
                'permits.valid_until',
                'businesses.name as business',
                'barangays.name as barangay',
                'permit_types.name as permit_type',
                'permit_types.code as permit_type_code',
            ]);

        $permits = [];
        $seen = [];
        foreach ($rows as $row) {
            // The left join on addresses can duplicate a permit when a business
            // recorded more than one business_location row.
            if (isset($seen[$row->id])) {
                continue;
            }
            $seen[$row->id] = true;
            $permits[] = [
                'id' => (int) $row->id,
                'permit_number' => (string) $row->permit_number,
                'business_id' => (int) $row->business_id,
                'business' => (string) $row->business,
                'barangay' => $row->barangay === null ? null : (string) $row->barangay,
                'permit_type' => (string) $row->permit_type,
                'permit_type_code' => (string) $row->permit_type_code,
                'valid_until' => (string) $row->valid_until,
            ];
        }

        return $permits;
    }

    /**
     * The renewal filing standing against each in-scope permit, if any.
     *
     * An approved renewal wins over anything else — a business that filed twice
     * and got one through is not at risk. Otherwise the most recent filing that
     * was not cancelled represents the state of play.
     *
     * @param  list<int>  $permitIds
     * @return array<int, array{application_id: int, tracking_id: string|null, stage: string}>
     */
    private static function renewalsByPriorPermit(array $permitIds): array
    {
        $rows = DB::table('applications')
            ->whereNull('deleted_at')
            ->where('application_type', ApplicationType::Renewal->value)
            ->whereIn('prior_permit_id', $permitIds)
            ->orderBy('created_at')
            ->get(['id', 'tracking_id', 'prior_permit_id', 'status']);

        $best = [];
        foreach ($rows as $row) {
            $stage = self::stageFor((string) $row->status);
            if ($stage === null) {
                continue;
            }

            $permitId = (int) $row->prior_permit_id;
            $existing = $best[$permitId] ?? null;
            // Later row wins, except that an approval already recorded stands.
            if ($existing !== null && $existing['stage'] === 'approved') {
                continue;
            }

            $best[$permitId] = [
                'application_id' => (int) $row->id,
                'tracking_id' => $row->tracking_id === null ? null : (string) $row->tracking_id,
                'stage' => $stage,
            ];
        }

        return $best;
    }

    /** Map an application status onto a scoring stage; null means "ignore it". */
    private static function stageFor(string $status): ?string
    {
        return match ($status) {
            ApplicationStatus::Approved->value => 'approved',
            ApplicationStatus::Rejected->value => 'rejected',
            ApplicationStatus::Returned->value => 'returned',
            ApplicationStatus::Draft->value => 'draft',
            ApplicationStatus::Submitted->value,
            ApplicationStatus::PendingPayment->value,
            ApplicationStatus::UnderReview->value,
            ApplicationStatus::ForInspection->value => 'in_progress',
            // Cancelled leaves no filing standing, which is the same position as
            // never having filed — handled by the caller's 'none' default.
            default => null,
        };
    }

    /**
     * Earlier renewal cycles per business, and how many were filed late.
     *
     * Only filings against permits OTHER than the ones on the watchlist count:
     * the current cycle is scored by the progress rule, and letting it in here
     * would score the same fact twice.
     *
     * @param  list<int>  $businessIds
     * @param  list<int>  $excludePermitIds
     * @return array<int, array{total: int, late: int}>
     */
    private static function punctualityByBusiness(array $businessIds, array $excludePermitIds): array
    {
        $rows = DB::table('applications')
            ->join('permits', 'permits.id', '=', 'applications.prior_permit_id')
            ->whereNull('applications.deleted_at')
            ->where('applications.application_type', ApplicationType::Renewal->value)
            ->whereNotNull('applications.submitted_at')
            ->whereIn('applications.business_id', $businessIds)
            ->whereNotIn('applications.prior_permit_id', $excludePermitIds)
            ->get([
                'applications.business_id',
                'applications.submitted_at',
                'permits.valid_until',
            ]);

        $out = [];
        foreach ($rows as $row) {
            $businessId = (int) $row->business_id;
            $out[$businessId] ??= ['total' => 0, 'late' => 0];
            $out[$businessId]['total']++;

            $submitted = CarbonImmutable::parse($row->submitted_at)->startOfDay();
            $expired = CarbonImmutable::parse($row->valid_until)->startOfDay();
            if ($submitted->greaterThan($expired)) {
                $out[$businessId]['late']++;
            }
        }

        return $out;
    }

    /**
     * Open compliance findings per business.
     *
     * @param  list<int>  $businessIds
     * @return array<int, int>
     */
    private static function openFindingsByBusiness(array $businessIds, CarbonImmutable $now): array
    {
        $decided = [
            ApplicationStatus::Approved->value,
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Cancelled->value,
        ];

        $checks = DB::table('compliance_checks')
            ->join('application_assignments', 'application_assignments.id', '=', 'compliance_checks.application_assignment_id')
            ->join('applications', 'applications.id', '=', 'application_assignments.application_id')
            ->whereNull('applications.deleted_at')
            ->where('compliance_checks.is_checked', false)
            ->whereNotIn('applications.status', $decided)
            ->whereIn('applications.business_id', $businessIds)
            ->groupBy('applications.business_id')
            ->get(['applications.business_id', DB::raw('count(*) as findings')]);

        $inspections = DB::table('inspections')
            ->join('applications', 'applications.id', '=', 'inspections.application_id')
            ->whereNull('applications.deleted_at')
            ->whereIn('inspections.result', [InspectionResult::Failed->value, InspectionResult::Conditional->value])
            ->whereNotNull('inspections.conducted_at')
            ->where('inspections.conducted_at', '>=', $now->subMonths(self::FINDINGS_LOOKBACK_MONTHS))
            ->whereIn('applications.business_id', $businessIds)
            ->groupBy('applications.business_id')
            ->get(['applications.business_id', DB::raw('count(*) as findings')]);

        $out = [];
        foreach ([$checks, $inspections] as $set) {
            foreach ($set as $row) {
                $businessId = (int) $row->business_id;
                $out[$businessId] = ($out[$businessId] ?? 0) + (int) $row->findings;
            }
        }

        return $out;
    }

    /**
     * Fee state per renewal application: settled, pending, or unpaid.
     *
     * @param  list<int>  $applicationIds
     * @return array<int, string>
     */
    private static function feeStateByApplication(array $applicationIds): array
    {
        if ($applicationIds === []) {
            return [];
        }

        $assessed = DB::table('fee_assessments')
            ->whereIn('application_id', $applicationIds)
            ->where('total_amount', '>', 0)
            ->pluck('application_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($assessed === []) {
            return [];
        }

        $payments = DB::table('payments')
            ->whereIn('application_id', $assessed)
            ->get(['application_id', 'status']);

        $hasCompleted = [];
        $hasPending = [];
        foreach ($payments as $payment) {
            $id = (int) $payment->application_id;
            if ($payment->status === PaymentStatus::Completed->value) {
                $hasCompleted[$id] = true;
            } elseif ($payment->status === PaymentStatus::Pending->value) {
                $hasPending[$id] = true;
            }
        }

        $out = [];
        foreach ($assessed as $id) {
            $out[$id] = match (true) {
                isset($hasCompleted[$id]) => 'settled',
                isset($hasPending[$id]) => 'pending',
                // No payment row, or only failed/refunded ones: nothing has been
                // collected against an assessment that exists.
                default => 'unpaid',
            };
        }

        return $out;
    }

    /**
     * Expiry reminders already sent, per permit.
     *
     * Counted off `permit_expiry_notices`, the dedupe ledger
     * `biztrack:scan-permits` writes one row into each time it actually sends a
     * notification. These are real sends, not a derived estimate — but only the
     * reminder kinds count. `threshold_60` / `_30` / `_7` are the pre-expiry
     * nudges and `renewal_due` is the post-expiry one; `expired` is the
     * status-change notice telling an owner their permit has lapsed, which is
     * not a reminder to renew and would inflate the KPI if pooled in.
     *
     * Consequence worth knowing: the figure is zero until the nightly scan has
     * run at least once. That is a true zero — nothing was sent — and the screen
     * says so rather than substituting a count of permits that were merely
     * eligible for a reminder.
     *
     * The `manual_*` kinds an officer's Send Reminder button writes are NOT
     * pooled in here, and that is a decision rather than an oversight. This
     * figure's published definition (AnalyticsDefinitions, `reminders_sent`)
     * names the scheduled buckets and says the count "reads zero until the
     * nightly permit scan has run"; folding officer-initiated follow-ups into
     * it would make that sentence false while leaving it on screen. They are
     * surfaced beside it instead — see manualRemindersByPermit().
     *
     * @param  list<int>  $permitIds
     * @return array<int, int>
     */
    private static function noticeCountsByPermit(array $permitIds): array
    {
        $rows = DB::table('permit_expiry_notices')
            ->whereIn('permit_id', $permitIds)
            ->where(static function ($query) {
                $query->where('notice_kind', 'like', 'threshold_%')
                    ->orWhere('notice_kind', '=', 'renewal_due');
            })
            ->groupBy('permit_id')
            ->get(['permit_id', DB::raw('count(*) as notices')]);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->permit_id] = (int) $row->notices;
        }

        return $out;
    }

    /**
     * The band one permit scores, or null if it is not on the watchlist at all.
     *
     * The Send Reminder button needs two answers about the permit under it —
     * "may this be followed up?" and "how urgently?" — and they must not be
     * able to disagree with the row the officer pressed. So both come from one
     * pass through the same facts and the same scorer the table was drawn
     * from. Restating the window as a second `where` clause on the controller
     * was the alternative, and it is the shape where a permit the screen lists
     * gets refused by the endpoint for a reason nobody can see.
     *
     * Null means "not in scope": wrong status, or an expiry outside the
     * lapsed-grace-to-horizon window, or a closed business. Every one of those
     * is a permit the screen does not show, so it is a permit with no button.
     *
     * This costs the five bulk queries a page load costs, which is why it is a
     * POST-time call and not something to reach for in a loop.
     */
    public static function bandForPermit(int $permitId, int $horizonDays = self::DEFAULT_HORIZON_DAYS): ?string
    {
        foreach (self::dataset($horizonDays)['permits'] as $permit) {
            if ((int) $permit['permit_id'] !== $permitId) {
                continue;
            }

            return RenewalRiskScoring::score([
                'days_to_expiry' => (int) $permit['days_to_expiry'],
                'renewal_stage' => (string) $permit['renewal_stage'],
                'prior_renewals' => (int) $permit['prior_renewals'],
                'late_renewals' => (int) $permit['late_renewals'],
                'open_findings' => (int) $permit['open_findings'],
                'fee_state' => (string) $permit['fee_state'],
            ])['band'];
        }

        return null;
    }

    /**
     * The ledger kind an officer-initiated follow-up claims, one per permit per
     * day.
     *
     * `permit_expiry_notices` carries a unique index on (permit_id,
     * notice_kind) and the insert is the permission to send — that is the
     * property ScanPermits is built on, and reusing it is what makes a manual
     * send idempotent without a second mechanism or a migration. Putting the
     * date IN the kind is what sets the grain:
     *
     *  - a bare `manual` would be one follow-up per permit FOREVER, so an
     *    officer chasing the same business again next quarter would silently
     *    send nothing;
     *  - no ledger row at all would make a double-click two messages to a real
     *    business owner.
     *
     * One a day is the answer to both. A second press the same day is refused
     * by the database rather than by a flag in the browser, so it holds across
     * two officers, two tabs and a replayed request.
     */
    public static function manualNoticeKind(?CarbonImmutable $day = null): string
    {
        return 'manual_'.($day ?? CarbonImmutable::now())->toDateString();
    }

    /**
     * Officer-initiated follow-ups per permit: how many, and the last one.
     *
     * Read live at serve time rather than carried on the analytics snapshot,
     * because the snapshot is a nightly statistic and this is the state of an
     * action taken minutes ago. A "sent" mark read from last night's figures
     * would tell an officer they had not contacted a business they contacted
     * after breakfast — which is precisely the mistake the button exists to
     * prevent.
     *
     * @param  list<int>  $permitIds
     * @return array<int, array{count: int, last_at: string}>
     */
    public static function manualRemindersByPermit(array $permitIds): array
    {
        if ($permitIds === []) {
            return [];
        }

        $rows = DB::table('permit_expiry_notices')
            ->whereIn('permit_id', $permitIds)
            // `_` is a single-character wildcard here rather than a literal, the
            // same slight looseness `threshold_%` above already carries. No other
            // kind begins "manual", so the pattern selects exactly this ledger.
            ->where('notice_kind', 'like', 'manual_%')
            ->groupBy('permit_id')
            ->get(['permit_id', DB::raw('count(*) as sends'), DB::raw('max(created_at) as last_at')]);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->permit_id] = [
                'count' => (int) $row->sends,
                'last_at' => CarbonImmutable::parse($row->last_at)->toISOString(),
            ];
        }

        return $out;
    }

    /**
     * The Recommended Actions panel: one bar per action, sized by how many
     * permits landed in the band that recommends it.
     *
     * @param  array{high: int, moderate: int, low: int}  $counts
     * @return list<array{action: string, label: string, band: string, count: int}>
     */
    private static function actionTotals(array $counts): array
    {
        return [
            ['action' => 'immediate_follow_up', 'label' => 'Immediate follow-up', 'band' => 'high', 'count' => $counts['high']],
            ['action' => 'send_reminder', 'label' => 'Send reminder', 'band' => 'moderate', 'count' => $counts['moderate']],
            ['action' => 'monitor', 'label' => 'Monitor', 'band' => 'low', 'count' => $counts['low']],
        ];
    }
}

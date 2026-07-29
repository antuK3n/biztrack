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

    /** Drivers shown per row before the rest are folded away. */
    private const DRIVERS_PER_ROW = 3;

    /** Window for counting a failed inspection against a business, in months. */
    private const FINDINGS_LOOKBACK_MONTHS = 12;

    /**
     * The sentence the screen, the CSV, and any future PDF all have to carry.
     * Kept server-side on purpose: if the honesty statement lived only in the
     * React copy, an export would quietly ship the numbers without it.
     */
    public const METHODOLOGY = 'Risk scores are a weighted rule set over the register, not a statistical '
        .'prediction. Each of five signals contributes a fixed maximum number of points; the total ranks '
        .'permits by how many known risk signals they carry. The score is not a probability and does not '
        .'estimate how likely a renewal is to be late.';

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
     * @return array<string, mixed>
     */
    public static function build(int $horizonDays = self::DEFAULT_HORIZON_DAYS, int $limit = self::DEFAULT_LIMIT): array
    {
        return self::compute(self::dataset($horizonDays, $limit));
    }

    /**
     * The local (PHP) engine: facts in, scored watchlist out, no database.
     *
     * R's `POST /renewal-risk` returns this same schema from the same facts. The
     * numbers must agree — AnalyticsParityTest is what enforces that, and without
     * it the fallback would quietly become a second, divergent rule set.
     *
     * @param  array<string, mixed>  $dataset  as returned by dataset()
     * @return array<string, mixed>
     */
    public static function compute(array $dataset): array
    {
        // Echoed, not re-parsed: see the note in ProcessingTimeAnalytics::compute().
        $now = (string) $dataset['now'];
        $limit = (int) $dataset['params']['limit'];
        $driversPerRow = (int) ($dataset['drivers_per_row'] ?? self::DRIVERS_PER_ROW);

        $rows = [];
        $counts = ['high' => 0, 'moderate' => 0, 'low' => 0];

        foreach ($dataset['permits'] as $permit) {
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

        return [
            'generated_at' => $now,
            'horizon_days' => (int) $dataset['params']['days'],
            'lapsed_grace_days' => (int) $dataset['lapsed_grace_days'],
            'window_start' => (string) $dataset['window_start'],
            'window_end' => (string) $dataset['window_end'],
            'scored_permits' => count($rows),
            'counts' => $counts,
            'reminders_sent' => (int) $dataset['reminders_sent'],
            'at_risk' => array_slice($rows, 0, max(1, $limit)),
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
     * Permits whose expiry falls in the window, with the business and barangay
     * the table needs.
     *
     * @return list<array{id: int, permit_number: string, business_id: int, business: string, barangay: string|null, permit_type: string, valid_until: string}>
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

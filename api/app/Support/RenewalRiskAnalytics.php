<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\InspectionResult;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RenewalRiskAnalytics
{
    public const DEFAULT_HORIZON_DAYS = 365;

    public const LAPSED_GRACE_DAYS = 60;

    public const DEFAULT_LIMIT = 25;

    private const DRIVERS_PER_ROW = 3;

    private const FINDINGS_LOOKBACK_MONTHS = 12;

    public const METHODOLOGY = 'Each permit is checked against five things: how soon it expires, whether a '
        .'renewal has been filed, whether this business has renewed late before, open compliance findings, '
        .'and unpaid fees. Each adds points, up to 100. A higher score means more warning signs — it is not '
        .'a prediction, and it does not say how likely a renewal is to be late.';

    public const R_ENDPOINT = '/renewal-risk';

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

    public static function build(int $horizonDays = self::DEFAULT_HORIZON_DAYS, int $limit = self::DEFAULT_LIMIT): array
    {
        return self::compute(self::dataset($horizonDays, $limit));
    }

    public static function compute(array $dataset): array
    {
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

                'drivers' => array_slice(
                    array_values(array_filter($scored['drivers'], static fn (array $d): bool => $d['points'] > 0)),
                    0,
                    $driversPerRow,
                ),
            ];
        }

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

            default => null,
        };
    }

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

                default => 'unpaid',
            };
        }

        return $out;
    }

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

    private static function actionTotals(array $counts): array
    {
        return [
            ['action' => 'immediate_follow_up', 'label' => 'Immediate follow-up', 'band' => 'high', 'count' => $counts['high']],
            ['action' => 'send_reminder', 'label' => 'Send reminder', 'band' => 'moderate', 'count' => $counts['moderate']],
            ['action' => 'monitor', 'label' => 'Monitor', 'band' => 'low', 'count' => $counts['low']],
        ];
    }
}

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

final class DashboardAnalytics
{
    public const R_ENDPOINT = '/dashboard';

    public const DEFAULT_WINDOW_MONTHS = 12;

    private const TOP_N = 5;

    private const MAP_POINT_LIMIT = 1000;

    private const TIERS = Ra11032::TIERS;

    private const EXPIRY_WINDOWS = [30, 60, 90];

    private const INSPECTION_TYPE_BY_DEPARTMENT = [
        'CHO' => 'Sanitary',
        'BFP' => 'Fire Safety',
        'CPDO' => 'Zoning',
    ];

    private const ORGANIZATION_FORMS = [
        'sole_proprietorship' => 'Sole Proprietorship',
        'corporation' => 'Corporation',
        'partnership' => 'Partnership',
        'cooperative' => 'Cooperative',
    ];

    public static function dataset(int $windowMonths = self::DEFAULT_WINDOW_MONTHS): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $windowStart = $today->subMonths($windowMonths);
        $ytdStart = $today->startOfYear();
        $monthStart = $today->startOfMonth();

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

    public static function build(int $windowMonths = self::DEFAULT_WINDOW_MONTHS): array
    {
        return self::compute(self::dataset($windowMonths));
    }

    public static function compute(array $dataset): array
    {
        $topN = (int) ($dataset['top_n'] ?? self::TOP_N);

        $decisions = self::computeDecisions($dataset['decisions']);
        $compliance = self::computeCompliance($dataset['compliance']);

        return [

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
                continue;
            }

            $submitted = CarbonImmutable::parse($row->submitted_at);
            $decided = CarbonImmutable::parse($row->decided_at);
            $workingDays = self::workingDaysBetween($submitted, $decided);

            $observations[] = [
                'tier' => $tier,
                'working_days' => $workingDays,
                'calendar_days' => Rounding::statistic($submitted->diffInHours($decided) / 24),

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

    private static function renewalCompliance(CarbonImmutable $windowStart, CarbonImmutable $now): array
    {
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

            'unrecorded' => max(0, $total - $recorded),
            'total' => $total,
        ];
    }

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

                'attended' => (clone $meetings)
                    ->whereIn('id', DB::table('officer_request_responses')->select('officer_request_id'))
                    ->count(),
            ],
        ];
    }

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

    private static function computeKpis(array $kpis, array $compliance): array
    {
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

            'approval_rate' => $decisioned > 0
                ? Rounding::statistic(($approved / $decisioned) * 100, 1)
                : null,
        ];
    }

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

                'within_statutory' => $withinStatutory,
                'within_statutory_rate' => Rounding::statistic(($withinStatutory / $n) * 100, 1),

                'within_recorded_deadline' => count(array_filter(
                    $rows,
                    static fn (array $r): bool => (bool) $r['within_recorded_deadline'],
                )),
                'recorded_deadline_working_days' => count($recordedDeadlines) === 1
                    ? reset($recordedDeadlines)
                    : null,
                'overage_days' => Rounding::statistic($meanWorking - $target, 1),

                'breaching' => $meanWorking > $target,
            ];
        }

        return $out;
    }

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

                'rate' => $denominator > 0 && $reason === null
                    ? Rounding::statistic(($numerator / $denominator) * 100, 1)
                    : null,
                'unavailable_reason' => $reason === null ? null : (string) $reason,
            ];
        }

        return $out;
    }

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

        usort($rows, static fn (array $a, array $b) => [$b['count'], $a[$nameKey]] <=> [$a['count'], $b[$nameKey]]);

        $ranked = [];
        foreach (array_slice($rows, 0, $topN) as $index => $row) {
            $ranked[] = ['rank' => $index + 1, ...$row];
        }

        return ['rows' => $ranked, 'total' => $total, 'groups' => count($facts)];
    }

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

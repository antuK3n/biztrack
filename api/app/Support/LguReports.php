<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\PermitType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The printable reports on the Reports screen (checklist "Manage Approved
 * Permits – Ken", item 7: "redo Report Generation").
 *
 * ── WHAT THEY ARE MODELLED ON ───────────────────────────────────────────────
 *
 * Not on the analytics screens. A report here is a document a Philippine LGU
 * office files or forwards, so each one follows a report that BPLOs and the
 * offices around them already keep:
 *
 *  1. permits-issued      — the monthly report of business permits issued, NEW
 *                           versus RENEWAL. DTI-DILG-DICT JMC 01-2016 (revised
 *                           BPLS standards) sets separate targets for new and
 *                           renewal applications, which is why every BPLO keeps
 *                           the two apart; LGUs publish their counts the same
 *                           way ("14,473 renewals and 219 new permits in
 *                           January").
 *  2. collections         — an abstract of collections by nature of fee, the
 *                           shape the Treasurer's report of collections takes
 *                           (business tax, Mayor's permit fee, regulatory fees,
 *                           fire code fees, and so on), by office and by month.
 *  3. businesses-by-area  — the masterlist summary: businesses permitted in the
 *                           period by barangay and by kind of business (the
 *                           Revenue Code's retailer / wholesaler / manufacturer
 *                           / contractor categories).
 *  4. clearances          — clearances issued per office (sanitary permit, FSIC,
 *                           locational clearance, occupancy, environmental), the
 *                           count each regulatory office reports to BPLO and to
 *                           its own line agency.
 *  5. pending-processing  — the RA 11032 / ARTA processing-time report: filings
 *                           decided against their statutory tier limit, and what
 *                           was still pending at the end of the period, by age.
 *
 * Five, deliberately. The checklist asked for four to six, and these are the
 * ones an office is asked for; anything more is the dashboard's job.
 *
 * ── ONE SHAPE FOR ALL FIVE ──────────────────────────────────────────────────
 *
 * Every report is a list of sections, each a table: typed columns, rows of raw
 * values, an optional total row and a note. The screen renders that shape, the
 * CSV writes it, and neither knows which report it is holding — so the printed
 * figures and the exported figures cannot come from two different computations.
 *
 * Figures are raw numbers; formatting (pesos, thousands separators) is the
 * reader's business. A dash is sent as null, never as 0 (AGENTS.md §6.4).
 *
 * ── OFFICE SCOPE ────────────────────────────────────────────────────────────
 *
 * The same boundary as the dashboard, decided the same way: the controller asks
 * AnalyticsOffice which office the request is answered for and passes the code
 * in. Nothing here knows who is asking. See AnalyticsOffice for what "an
 * office's figures" means; the one addition is money, where an office's
 * collections are the fee lines billed in its name.
 *
 * Everything is computed on request. The date range is arbitrary, so there is
 * no snapshot to serve.
 *
 * ── MANILA DATES ────────────────────────────────────────────────────────────
 *
 * The period is two Manila dates, inclusive, and every section counts the UTC
 * instants between the first moment of the first and the first moment after
 * the last (ManilaCalendar::period). Months are Manila months, bucketed in PHP
 * so SQLite and PostgreSQL bucket alike. This was the UTC day, which put
 * everything filed before 8 am Manila on the 1st into the previous month and
 * dropped the last eight hours of the period.
 */
final class LguReports
{
    /** @var array<string, array{title: string, summary: string}> */
    public const REPORTS = [
        'permits-issued' => [
            'title' => 'Permits Issued — New and Renewal',
            'summary' => 'Permits released in the period, by month and by permit type, split into new, renewal and amendment.',
        ],
        'collections' => [
            'title' => 'Collections by Nature of Fee',
            'summary' => 'Fees paid in the period, by office, by nature of collection and by month.',
        ],
        'businesses-by-area' => [
            'title' => 'Businesses Permitted by Barangay and Kind of Business',
            'summary' => 'Businesses issued a permit in the period, by barangay (new and renewal) and by kind of business.',
        ],
        'clearances' => [
            'title' => 'Clearances Issued per Office',
            'summary' => 'Each office’s clearances and permits issued in the period, and those it refused.',
        ],
        'pending-processing' => [
            'title' => 'Processing Time and Pending Applications',
            'summary' => 'Filings decided in the period against their RA 11032 limit, and what was still pending at its end.',
        ],
    ];

    /** Nature-of-collection labels for the fee groups the fee engine writes. */
    private const FEE_GROUPS = [
        'business_tax' => 'Local business tax',
        'mayors_permit' => 'Mayor’s permit fees',
        'regulatory' => 'Regulatory fees',
        'fire_code' => 'Fire code fees',
        'zoning' => 'Zoning and locational clearance fees',
        'admin' => 'Administrative charges (plates, stickers, certifications)',
        'city_charge' => 'Other city charges',
        'ctc' => 'Community tax',
        'exemption_claim' => 'Exemptions claimed',
    ];

    /** Offices that bill a fee line but are not departments on this register. */
    private const OTHER_FEE_OFFICES = [
        'CTO' => 'City Treasurer’s Office',
        'CMO-MARKET' => 'City Market Office',
    ];

    private const TYPES = [
        'new' => 'New',
        'renewal' => 'Renewal',
        'amendment' => 'Amendment',
    ];

    /**
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     * @return array{key: string, title: string, sections: list<array<string, mixed>>}
     */
    public static function build(string $key, CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        /*
         * $from and $to arrive as Manila dates. From here on they are the UTC
         * instants that bound the period: $from its first moment, $to the
         * first moment AFTER it — every comparison against $to is `<`.
         */
        [$from, $to] = ManilaCalendar::period($from->toDateString(), $to->toDateString());

        $sections = match ($key) {
            'permits-issued' => self::permitsIssued($from, $to, $scope),
            'collections' => self::collections($from, $to, $scope),
            'businesses-by-area' => self::businessesByArea($from, $to, $scope),
            'clearances' => self::clearances($from, $to, $scope),
            'pending-processing' => self::pendingProcessing($from, $to, $scope),
            default => throw new \InvalidArgumentException("Unknown report [{$key}]."),
        };

        return [
            'key' => $key,
            'title' => self::REPORTS[$key]['title'],
            'sections' => $sections,
        ];
    }

    /* ── 1. permits issued, new and renewal ──────────────────────────────── */

    /**
     * ── Business permits by month, every type by type ─────────────────────
     *
     * The month table is the JMC 01-2016 count — business permits, new versus
     * renewal — and for every office at once it added the five clearances into
     * the same New and Renewal cells, so one new business opening with its
     * sanitary, fire, occupancy, environmental and zoning papers read as six
     * new permits. For every office the month table now counts the Mayor's
     * (business) permit only; one office's report counts its own types as
     * before. The permit-type table breaks down every type, each in its own
     * row, so nothing is mixed and nothing is lost.
     *
     * @return list<array<string, mixed>>
     */
    private static function permitsIssued(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        $rows = self::issued($from, $to, $scope);
        $businessPermit = DB::table('permit_types')->where('code', PermitType::OUTCOME_CODE)->value('id');

        $months = self::months($from, $to);
        $byMonth = [];
        foreach ($months as $month => $label) {
            $byMonth[$month] = ['label' => $label, 'new' => 0, 'renewal' => 0, 'amendment' => 0];
        }
        $byType = [];

        foreach ($rows as $row) {
            $month = ManilaCalendar::monthOf($row['issued_at']);
            if (isset($byMonth[$month]) && ($scope !== null || $row['type_id'] === (int) $businessPermit)) {
                $byMonth[$month][$row['kind']]++;
            }
            $byType[$row['type_id']] ??= ['label' => $row['type_name'], 'new' => 0, 'renewal' => 0, 'amendment' => 0];
            $byType[$row['type_id']][$row['kind']]++;
        }
        ksort($byType);

        $columns = [
            ['key' => 'label', 'label' => 'Month', 'format' => 'text'],
            ['key' => 'new', 'label' => 'New', 'format' => 'count'],
            ['key' => 'renewal', 'label' => 'Renewal', 'format' => 'count'],
            ['key' => 'amendment', 'label' => 'Amendment', 'format' => 'count'],
            ['key' => 'total', 'label' => 'Total', 'format' => 'count'],
        ];

        return [
            self::table(
                $scope === null ? 'Business permits by month' : 'By month',
                $columns,
                array_map(self::withTotal(...), array_values($byMonth)),
                ($scope === null
                    ? 'Mayor’s (business) permits released in the period. The clearances are counted by type below and on the Clearances report, not added in here. '
                    : 'Permits released in the period. ')
                .self::KIND_NOTE,
            ),
            self::table(
                'By permit type',
                array_replace($columns, [0 => ['key' => 'label', 'label' => 'Permit type', 'format' => 'text']]),
                array_map(self::withTotal(...), array_values($byType)),
                'Every permit released in the period, one row per type. A permit later revoked, suspended or replaced by a renewal is still counted: it was issued in the period. Permits brought over from the old register are included.',
            ),
        ];
    }

    /** How a permit is classed, said once for every table that classes one. */
    private const KIND_NOTE = 'A permit is a renewal when the business already held that type of permit before it was issued, or was renewing one issued on paper; an amendment when it reissues an amended permit; new otherwise.';

    /**
     * Every permit released in [from, to), oldest first, with how it is classed.
     *
     * ── New or renewal, by what the business already held ─────────────────
     *
     * This read the kind of FILING that produced the permit. Over a long
     * period that is not the question the report answers — whether the City
     * gained a permit holder or kept one — and a business's first permit of a
     * type is new whatever form it came in on, while a business that already
     * held the type is renewing. So:
     *
     *   amendment  the filing was an amendment: the same permit, reissued;
     *   renewal    the business held a permit of this type issued before this
     *              one, on BizTrack or brought over from the old register —
     *              or the filing was a renewal, which covers a permit the
     *              business renews from paper (AGENTS.md §11: in year one the
     *              common case, and the register cannot see the paper);
     *   new        anything else.
     *
     * ── Permits from the old register ──────────────────────────────────────
     *
     * Imported permits have no filing (`application_id` is null), and the
     * inner join to `applications` dropped every one of them from every
     * permit report. They are joined LEFT now, and classed by what the
     * business held before them like any other permit.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     * @return list<array{id: int, business_id: int, type_id: int, type_name: string, issued_at: string, kind: string}>
     */
    private static function issued(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        $inPeriod = self::issuedBy(DB::table('permits'), $scope)
            ->where('permits.issued_at', '>=', $from)
            ->where('permits.issued_at', '<', $to);

        $rows = (clone $inPeriod)
            ->leftJoin('applications', 'applications.id', '=', 'permits.application_id')
            ->join('permit_types', 'permit_types.id', '=', 'permits.permit_type_id')
            ->orderBy('permits.issued_at')
            ->orderBy('permits.id')
            ->get([
                'permits.id', 'permits.business_id', 'permits.permit_type_id', 'permits.issued_at',
                'applications.application_type', 'permit_types.name as type_name',
            ]);

        /*
         * The earliest permit each of these businesses holds of each type:
         * one row per (business, type), over the businesses in the period, so
         * the cost follows the period and not the register.
         */
        $first = [];
        foreach (DB::table('permits')
            ->whereIn('business_id', (clone $inPeriod)->select('permits.business_id'))
            ->whereNotNull('issued_at')
            ->groupBy('business_id', 'permit_type_id')
            ->selectRaw('business_id, permit_type_id, min(issued_at) as first_issued')
            ->get() as $row) {
            $first[$row->business_id.'|'.$row->permit_type_id] = (string) $row->first_issued;
        }

        $seenFirst = [];
        $out = [];
        foreach ($rows as $row) {
            $key = $row->business_id.'|'.$row->permit_type_id;
            $issuedAt = (string) $row->issued_at;
            /*
             * Held before: an earlier permit of this type exists. Two permits
             * of one type issued in the same second are told apart by id —
             * the first one seen (rows are oldest first) is the first.
             */
            $isFirst = ! isset($seenFirst[$key])
                && CarbonImmutable::parse($issuedAt)->equalTo(CarbonImmutable::parse($first[$key] ?? $issuedAt));
            $seenFirst[$key] = true;

            $kind = match (true) {
                $row->application_type === 'amendment' => 'amendment',
                ! $isFirst, $row->application_type === 'renewal' => 'renewal',
                default => 'new',
            };

            $out[] = [
                'id' => (int) $row->id,
                'business_id' => (int) $row->business_id,
                'type_id' => (int) $row->permit_type_id,
                'type_name' => (string) $row->type_name,
                'issued_at' => $issuedAt,
                'kind' => $kind,
            ];
        }

        return $out;
    }

    /* ── 2. collections by nature of fee ─────────────────────────────────── */

    /** @return list<array<string, mixed>> */
    private static function collections(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        $payments = DB::table('payments')
            ->join('fee_assessments', 'fee_assessments.id', '=', 'payments.fee_assessment_id')
            ->where('payments.status', PaymentStatus::Completed->value)
            ->where('payments.paid_at', '>=', $from)
            ->where('payments.paid_at', '<', $to)
            ->orderBy('payments.id')
            ->get(['payments.id', 'payments.amount', 'payments.paid_at', 'fee_assessments.line_items', 'fee_assessments.total_amount']);

        $offices = self::officeNames();
        $byOffice = [];
        $byNature = [];
        $byMonth = [];
        foreach (self::months($from, $to) as $month => $label) {
            $byMonth[$month] = ['label' => $label, 'payments' => 0, 'amount' => 0.0];
        }

        foreach ($payments as $payment) {
            $lines = json_decode((string) $payment->line_items, true) ?: [];
            $assessed = (float) $payment->total_amount;
            if ($assessed <= 0) {
                continue;
            }
            // A payment is spread over its assessment's fee lines in proportion.
            $share = (float) $payment->amount / $assessed;

            $touched = false;
            $paidHere = 0.0;
            foreach ($lines as $line) {
                $office = (string) ($line['office'] ?? '');
                if ($scope !== null && $office !== $scope['code']) {
                    continue;
                }
                $amount = (float) ($line['amount'] ?? 0) * $share;
                if ($amount == 0.0) {
                    continue;
                }
                $touched = true;
                $paidHere += $amount;

                $officeLabel = $offices[$office] ?? ($office === '' ? 'Not itemised by office' : $office);
                $byOffice[$officeLabel] ??= ['label' => $officeLabel, 'payments' => [], 'amount' => 0.0];
                $byOffice[$officeLabel]['payments'][$payment->id] = true;
                $byOffice[$officeLabel]['amount'] += $amount;

                $group = (string) ($line['group'] ?? '');
                $natureLabel = self::FEE_GROUPS[$group] ?? ($group === '' ? 'Permit fees (not itemised)' : $group);
                $byNature[$natureLabel] ??= ['label' => $natureLabel, 'amount' => 0.0];
                $byNature[$natureLabel]['amount'] += $amount;
            }

            $month = ManilaCalendar::monthOf($payment->paid_at);
            if ($touched && isset($byMonth[$month])) {
                $byMonth[$month]['payments']++;
                $byMonth[$month]['amount'] += $paidHere;
            }
        }

        $officeRows = array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'payments' => count($row['payments']),
            'amount' => round($row['amount'], 2),
        ], array_values($byOffice));
        usort($officeRows, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        $natureRows = array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'amount' => round($row['amount'], 2),
        ], array_values($byNature));
        usort($natureRows, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        $monthRows = array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'payments' => $row['payments'],
            'amount' => round($row['amount'], 2),
        ], array_values($byMonth));

        $amountTotal = round(array_sum(array_column($monthRows, 'amount')), 2);

        return [
            self::table('By office', [
                ['key' => 'label', 'label' => 'Office', 'format' => 'text'],
                ['key' => 'payments', 'label' => 'Payments', 'format' => 'count'],
                ['key' => 'amount', 'label' => 'Amount (PHP)', 'format' => 'money'],
            ], $officeRows, 'An office is credited with the fee lines billed in its name. One payment can carry several offices’ fees, so the payment counts do not add up across offices.', [
                'label' => 'Total', 'payments' => null, 'amount' => round(array_sum(array_column($officeRows, 'amount')), 2),
            ]),
            self::table('By nature of collection', [
                ['key' => 'label', 'label' => 'Nature of collection', 'format' => 'text'],
                ['key' => 'amount', 'label' => 'Amount (PHP)', 'format' => 'money'],
            ], $natureRows, null, [
                'label' => 'Total', 'amount' => round(array_sum(array_column($natureRows, 'amount')), 2),
            ]),
            self::table('By month', [
                ['key' => 'label', 'label' => 'Month', 'format' => 'text'],
                ['key' => 'payments', 'label' => 'Payments', 'format' => 'count'],
                ['key' => 'amount', 'label' => 'Amount (PHP)', 'format' => 'money'],
            ], $monthRows, 'Dated by when the payment cleared. Where a filing was paid in two instalments, each instalment is spread across its fee lines in proportion. Payments in BizTrack are simulated until a real payment channel is connected.', [
                'label' => 'Total', 'payments' => array_sum(array_column($monthRows, 'payments')), 'amount' => $amountTotal,
            ]),
        ];
    }

    /* ── 3. businesses by barangay and kind of business ──────────────────── */

    /** @return list<array<string, mixed>> */
    private static function businessesByArea(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        // Each business once: the kind of its EARLIEST permit in the period,
        // classed as the permits report classes it (issued()), so a business
        // that held permits before the period is not "new" in it.
        $kind = [];
        foreach (self::issued($from, $to, $scope) as $row) {
            $kind[$row['business_id']] ??= $row['kind'];
        }
        $businessIds = array_keys($kind);

        $barangays = [];
        if ($businessIds !== []) {
            $located = DB::table('business_addresses')
                ->leftJoin('barangays', 'barangays.id', '=', 'business_addresses.barangay_id')
                ->whereIn('business_addresses.business_id', $businessIds)
                ->where('business_addresses.address_type', 'business_location')
                ->get(['business_addresses.business_id', 'barangays.name']);

            $placed = [];
            foreach ($located as $row) {
                $id = (int) $row->business_id;
                if (isset($placed[$id])) {
                    continue;
                }
                $placed[$id] = true;
                $name = $row->name === null ? 'No barangay on record' : (string) $row->name;
                $barangays[$name] ??= ['label' => $name, 'new' => 0, 'renewal' => 0, 'amendment' => 0];
                $barangays[$name][$kind[$id]]++;
            }
            foreach ($businessIds as $id) {
                if (! isset($placed[$id])) {
                    $barangays['No barangay on record'] ??= ['label' => 'No barangay on record', 'new' => 0, 'renewal' => 0, 'amendment' => 0];
                    $barangays['No barangay on record'][$kind[$id]]++;
                }
            }
        }
        ksort($barangays);

        $categories = [];
        if ($businessIds !== []) {
            $lines = DB::table('business_lines')
                ->join('psic_codes', 'psic_codes.id', '=', 'business_lines.psic_code_id')
                ->whereIn('business_lines.business_id', $businessIds)
                ->get(['business_lines.business_id', 'psic_codes.category']);

            foreach ($lines as $row) {
                $label = self::categoryLabel($row->category);
                $categories[$label] ??= ['label' => $label, 'businesses' => []];
                $categories[$label]['businesses'][(int) $row->business_id] = true;
            }
        }
        $categoryRows = array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'businesses' => count($row['businesses']),
        ], array_values($categories));
        usort($categoryRows, static fn (array $a, array $b): int => [$b['businesses'], $a['label']] <=> [$a['businesses'], $b['label']]);

        return [
            self::table('By barangay', [
                ['key' => 'label', 'label' => 'Barangay', 'format' => 'text'],
                ['key' => 'new', 'label' => 'New', 'format' => 'count'],
                ['key' => 'renewal', 'label' => 'Renewal', 'format' => 'count'],
                ['key' => 'amendment', 'label' => 'Amendment', 'format' => 'count'],
                ['key' => 'total', 'label' => 'Total', 'format' => 'count'],
            ], array_map(self::withTotal(...), array_values($barangays)),
                'Each business is counted once, under its business location, by the first permit it was issued in the period: new if it held no permit of that type before. Permits from the old register are included.'),
            self::table('By kind of business', [
                ['key' => 'label', 'label' => 'Kind of business', 'format' => 'text'],
                ['key' => 'businesses', 'label' => 'Businesses', 'format' => 'count'],
            ], $categoryRows,
                'Kinds are the Revenue Code’s tax categories on each line of business. A business with lines in two kinds is counted in both, so this column does not add up to the barangay total.',
                null),
        ];
    }

    /* ── 4. clearances issued per office ─────────────────────────────────── */

    /** @return list<array<string, mixed>> */
    private static function clearances(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        $types = DB::table('permit_types')
            ->join('departments', 'departments.id', '=', 'permit_types.issuing_department_id')
            ->when($scope !== null, static fn ($q) => $q->whereIn('permit_types.id', $scope['permit_type_ids']))
            ->orderBy('permit_types.id')
            ->get(['permit_types.id', 'permit_types.name', 'departments.code']);

        $rows = [];
        foreach ($types as $type) {
            $rows[(int) $type->id] = [
                'office' => (string) $type->code,
                'label' => (string) $type->name,
                'new' => 0, 'renewal' => 0, 'amendment' => 0, 'total' => 0,
                'refused' => 0,
            ];
        }

        foreach (self::issued($from, $to, $scope) as $row) {
            if (isset($rows[$row['type_id']])) {
                $rows[$row['type_id']][$row['kind']]++;
                $rows[$row['type_id']]['total']++;
            }
        }

        $refused = DB::table('application_permit_types')
            ->whereNotNull('rejected_at')
            ->where('rejected_at', '>=', $from)
            ->where('rejected_at', '<', $to)
            ->when($scope !== null, static fn ($q) => $q->whereIn('permit_type_id', $scope['permit_type_ids']))
            ->groupBy('permit_type_id')
            ->selectRaw('permit_type_id, count(*) as c')
            ->pluck('c', 'permit_type_id');
        foreach ($refused as $id => $count) {
            if (isset($rows[(int) $id])) {
                $rows[(int) $id]['refused'] = (int) $count;
            }
        }

        $rows = array_values($rows);
        $total = ['office' => 'Total', 'label' => ''];
        foreach (['new', 'renewal', 'amendment', 'total', 'refused'] as $k) {
            $total[$k] = array_sum(array_column($rows, $k));
        }

        return [
            self::table('Issued and refused, per office', [
                ['key' => 'office', 'label' => 'Office', 'format' => 'text'],
                ['key' => 'label', 'label' => 'Clearance or permit', 'format' => 'text'],
                ['key' => 'new', 'label' => 'New', 'format' => 'count'],
                ['key' => 'renewal', 'label' => 'Renewal', 'format' => 'count'],
                ['key' => 'amendment', 'label' => 'Amendment', 'format' => 'count'],
                ['key' => 'total', 'label' => 'Issued', 'format' => 'count'],
                ['key' => 'refused', 'label' => 'Refused', 'format' => 'count'],
            ], $rows,
                'Issued counts permits released in the period. Refused counts clearances an office refused in the period, whether or not the applicant later filed again.',
                $total),
        ];
    }

    /* ── 5. processing time and pending applications ─────────────────────── */

    /** @return list<array<string, mixed>> */
    private static function pendingProcessing(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        /*
         * Decided in the period, against the filing's own RA 11032 tier, timed
         * the way the dashboard's tier panel times it — FilingClock, one
         * implementation for both. All offices: submission to decision, less
         * the stretches the filing sat with its applicant. One office: that
         * office's own review (BPLO: the time at its desk). This used to time
         * every routed filing's whole lifetime even on an office's report.
         */
        $tiers = [];
        foreach (Ra11032::TIERS as $tier => $rule) {
            $tiers[$tier] = ['label' => $rule['label'].' ('.$rule['statutory_working_days'].' working days)', 'decided' => 0, 'days' => 0, 'within' => 0];
        }
        $untiered = 0;
        foreach (FilingClock::decided($from, $to, $scope) as $row) {
            $tier = (string) $row['tier'];
            if (! isset($tiers[$tier])) {
                $untiered++;

                continue;
            }
            $days = $row['working_days'];
            $tiers[$tier]['decided']++;
            $tiers[$tier]['days'] += $days;
            if ($days <= Ra11032::TIERS[$tier]['statutory_working_days']) {
                $tiers[$tier]['within']++;
            }
        }
        $tierRows = array_map(static fn (array $row): array => [
            'label' => $row['label'],
            'decided' => $row['decided'],
            'mean_days' => $row['decided'] === 0 ? null : round($row['days'] / $row['decided'], 1),
            'within' => $row['within'],
            'within_rate' => $row['decided'] === 0 ? null : round($row['within'] / $row['decided'] * 100, 1),
        ], array_values($tiers));

        /*
         * Still pending at the end of the period, aged in working days. Not
         * pending: a filing rejected or cancelled by then, and one that was
         * waiting on its applicant at that moment (to pay, or to resubmit) —
         * that is the applicant's queue, not the City's. See FilingClock.
         */
        $buckets = ['within_3' => 3, 'within_7' => 7, 'within_20' => 20];
        $pendingRows = [];
        foreach (self::TYPES as $type => $label) {
            $pendingRows[$type] = ['label' => $label, 'within_3' => 0, 'within_7' => 0, 'within_20' => 0, 'over_20' => 0];
        }

        // The last moment of the period, or now if the period has not ended
        // yet. $to itself is the first moment AFTER the period — on the next
        // Manila date — and ageing to it would add a working day.
        $current = $to->greaterThan(CarbonImmutable::now());
        $asOf = $current ? CarbonImmutable::now() : $to->subSecond();
        foreach (FilingClock::pending($to, $asOf, $scope, $current) as $row) {
            $bucket = 'over_20';
            foreach ($buckets as $key => $limit) {
                if ($row['age'] <= $limit) {
                    $bucket = $key;
                    break;
                }
            }
            $pendingRows[self::transaction($row['application_type'])][$bucket]++;
        }
        $pendingRows = array_map(static function (array $row): array {
            $row['total'] = $row['within_3'] + $row['within_7'] + $row['within_20'] + $row['over_20'];

            return $row;
        }, array_values($pendingRows));
        $pendingTotal = ['label' => 'Total'];
        foreach (['within_3', 'within_7', 'within_20', 'over_20', 'total'] as $k) {
            $pendingTotal[$k] = array_sum(array_column($pendingRows, $k));
        }

        $decidedTotal = array_sum(array_column($tierRows, 'decided'));
        $withinTotal = array_sum(array_column($tierRows, 'within'));

        $bplo = $scope !== null && FilingClock::bplo($scope);
        $decidedNote = match (true) {
            $scope === null => 'Working days from submission to decision, leaving out the time a filing waited on its applicant to pay or to resubmit.',
            $bplo => 'Working days the filings decided in the period spent at BPLO’s desk (For Approval and For Final Approval) — BPLO’s own time, not the other offices’. Filings handled before September 2026, when every office reviewed at once, cannot show BPLO’s own days and are left out.',
            default => 'This office’s reviews finished in the period: working days from the filing reaching the office to the office finishing it, leaving out time the filing waited on its applicant.',
        };

        return [
            self::table('Decided in the period, against RA 11032', [
                ['key' => 'label', 'label' => 'Tier (statutory limit)', 'format' => 'text'],
                ['key' => 'decided', 'label' => 'Decided', 'format' => 'count'],
                ['key' => 'mean_days', 'label' => 'Average working days', 'format' => 'decimal'],
                ['key' => 'within', 'label' => 'Within the limit', 'format' => 'count'],
                ['key' => 'within_rate', 'label' => 'Within the limit (%)', 'format' => 'percent'],
            ], $tierRows,
                $decidedNote.' Weekends are excluded; holidays are not on the register and count as working days, so no figure here is shorter than the real one.'
                .($untiered > 0 ? " {$untiered} with no tier on record are left out." : ''),
                [
                    'label' => 'Total', 'decided' => $decidedTotal, 'mean_days' => null, 'within' => $withinTotal,
                    'within_rate' => $decidedTotal === 0 ? null : round($withinTotal / $decidedTotal * 100, 1),
                ]),
            self::table('Still pending at the end of the period, by age', [
                ['key' => 'label', 'label' => 'Transaction', 'format' => 'text'],
                ['key' => 'within_3', 'label' => '3 working days or less', 'format' => 'count'],
                ['key' => 'within_7', 'label' => '4 to 7', 'format' => 'count'],
                ['key' => 'within_20', 'label' => '8 to 20', 'format' => 'count'],
                ['key' => 'over_20', 'label' => 'Over 20', 'format' => 'count'],
                ['key' => 'total', 'label' => 'Total', 'format' => 'count'],
            ], $pendingRows,
                match (true) {
                    $scope === null => 'Filings submitted and not yet decided on the last day of the period, aged from submission. Rejected and cancelled filings are left out, and so are filings waiting on their applicant to pay or to resubmit; that time is also left out of the age.',
                    $bplo => 'Filings at BPLO’s desk (For Approval or For Final Approval) on the last day of the period, aged by the working days they have spent there.',
                    default => 'This office’s reviews not yet finished on the last day of the period, aged from when the filing reached the office. Rejected and cancelled filings are left out, and so are filings waiting on their applicant; that time is also left out of the age.',
                },
                $pendingTotal),
        ];
    }

    /* ── helpers ──────────────────────────────────────────────────────────── */

    /**
     * @param  list<array{key: string, label: string, format: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null|false  $total  false: sum every count/money column
     * @return array<string, mixed>
     */
    private static function table(string $heading, array $columns, array $rows, ?string $note, array|null|false $total = false): array
    {
        if ($total === false) {
            $total = [$columns[0]['key'] => 'Total'];
            foreach ($columns as $column) {
                if (in_array($column['format'], ['count', 'money'], true)) {
                    $total[$column['key']] = array_sum(array_map(static fn (array $row) => $row[$column['key']] ?? 0, $rows));
                }
            }
        }

        return [
            'heading' => $heading,
            'columns' => array_values($columns),
            'rows' => array_values($rows),
            'total' => $total,
            'note' => $note,
        ];
    }

    /** @param  array<string, mixed>  $row */
    private static function withTotal(array $row): array
    {
        $row['total'] = $row['new'] + $row['renewal'] + $row['amendment'];

        return $row;
    }

    /** Any transaction type the enum grows is read as an amendment rather than dropped. */
    private static function transaction(string $type): string
    {
        return array_key_exists($type, self::TYPES) ? $type : 'amendment';
    }

    /**
     * "2026-09" => "September 2026", every Manila month the period touches.
     *
     * $to is the exclusive end instant, so the last month is the one its
     * previous second falls in.
     *
     * @return array<string, string>
     */
    private static function months(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $last = ManilaCalendar::monthOf($to->subSecond());
        for ($cursor = ManilaCalendar::local($from)->startOfMonth(); $cursor->format('Y-m') <= $last; $cursor = $cursor->addMonth()) {
            $out[$cursor->format('Y-m')] = $cursor->format('F Y');
        }

        return $out;
    }

    /** @return array<string, string> office code => name, departments plus the fee-only offices */
    private static function officeNames(): array
    {
        $names = DB::table('departments')->pluck('name', 'code')->map(static fn ($n): string => (string) $n)->all();

        return $names + self::OTHER_FEE_OFFICES;
    }

    private static function categoryLabel(?string $category): string
    {
        if ($category === null || trim($category) === '') {
            return 'Not classified';
        }

        return ucfirst(str_replace('_', ' ', $category));
    }

    private static function issuedBy(QueryBuilder $query, ?array $scope): QueryBuilder
    {
        return $scope === null ? $query : $query->whereIn('permits.permit_type_id', $scope['permit_type_ids']);
    }

    private static function routedTo(QueryBuilder $query, ?array $scope): QueryBuilder
    {
        return $scope === null ? $query : $query->whereIn('applications.id', DB::table('application_assignments')
            ->select('application_id')
            ->where('department_id', $scope['department_id']));
    }
}

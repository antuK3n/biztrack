<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Custom Report builder (client, 19 October 2026: "reports generation
 * where the admin can select specific information like the total number of
 * male employees, total number of specific line of businesses, how many
 * registered in specific barangays").
 *
 * A report is a DEFINITION — what to count, which numbers, which filters and
 * which breakdown — and this class is the only thing that turns one into SQL:
 *
 *   {
 *     "subject":   "businesses" | "applications" | "permits" | "payments",
 *     "measures":  ["count", "male_employees", …],      1 to 8
 *     "filters":   {"barangay": ["3", "5"], "form": ["corporation"]},
 *     "period":    {"from": "2026-01-01", "to": "2026-09-30"} | null,
 *     "breakdown": ["barangay", "month"]                0 to 2
 *   }
 *
 * ── A WHITELIST, NOT A QUERY LANGUAGE ───────────────────────────────────────
 *
 * Every measure, filter and breakdown a subject offers is declared below with
 * the SQL expression it maps to. The definition only ever NAMES one of them;
 * nothing the reader sends is written into SQL. Filter values are bound
 * parameters, checked against the declared choices first. An unknown key
 * anywhere in the definition is a 422 (validate()), never ignored — a
 * definition that silently dropped a filter would print a wider count under a
 * heading that claims a narrower one.
 *
 * ── WHAT IS COUNTED (the same exclusions as the rest of analytics) ──────────
 *
 *  - Businesses: not removed (soft-deleted), and ON THE REGISTER — at least
 *    one filing past draft or a permit. A `businesses` row is written when an
 *    owner STARTS a draft, so the bare table over-counts; this is
 *    DashboardAnalytics::movementFacts()'s rule ("a business with neither has
 *    applied for nothing"), with "past draft" from kpiFacts() so the filings
 *    the portfolio flow writes without a submission date still count.
 *  - Applications: not drafts, not removed, and not of a removed business
 *    (DashboardAnalytics::kpiFacts and the volume panels). Dated by
 *    `submitted_at`; a filing with no submission date is counted only when no
 *    dates are chosen, as the dashboard's all-time tile counts it.
 *  - Permits: every permit with an issue date, whatever became of it later,
 *    including those brought over from the old register — exactly
 *    LguReports::issued(), so "Permits issued per type" here reconciles with
 *    the fixed Permits Issued report.
 *  - Payments: completed payments, dated by `paid_at` — LguReports::collections().
 *
 * Permits and payments do NOT leave out a removed business: the fixed reports
 * do not, and a permit issued or money collected happened whatever was later
 * done to the record. Change both here and in LguReports, or neither.
 *
 * ── OFFICE SCOPE ────────────────────────────────────────────────────────────
 *
 * The office arrives from AnalyticsOffice::forRequest (the controller), as it
 * does for the fixed reports, and means what AnalyticsOffice says it means:
 *
 *  - applications: routed to the office (an `application_assignments` row);
 *  - permits: of a type the office issues;
 *  - businesses: a filing routed to the office or a permit it issued
 *    (DashboardAnalytics::officeBusinesses);
 *  - payments: the fee lines billed in the office's name, each payment spread
 *    over its fee lines in proportion (LguReports::collections). That needs
 *    the JSON fee lines, so an office's payments are summed in PHP over the
 *    filtered payment rows; every other figure is one GROUP BY in SQL.
 *
 * ── LINES OF BUSINESS AND DOUBLE COUNTING ───────────────────────────────────
 *
 * A business can have several lines. FILTERING by line keeps a business that
 * has at least one of the chosen lines, once. BREAKING DOWN by line counts it
 * under each of its lines — so a two-line business's employees appear in two
 * rows and the rows add up to more than the business total. The Total row is
 * therefore never the sum of the rows: it is the same query run without the
 * breakdown, which counts each business, filing or payment once. The note
 * under the table says so whenever it can happen. Capitalization and gross
 * sales are recorded PER LINE, so under a line breakdown they are the line's
 * own figures and do add up.
 */
final class CustomReports
{
    /** The most rows a breakdown returns; the rest are counted and said. */
    public const MAX_ROWS = 500;

    public const MAX_MEASURES = 8;

    public const MAX_BREAKDOWN = 2;

    private const MAX_VALUES = 200;

    private const NOT_ON_RECORD = 'Not on record';

    public const SUBJECTS = [
        'businesses' => ['label' => 'Businesses', 'noun' => 'businesses', 'date' => null],
        'applications' => ['label' => 'Applications', 'noun' => 'applications', 'date' => 'Filed'],
        'permits' => ['label' => 'Permits', 'noun' => 'permits', 'date' => 'Issued'],
        'payments' => ['label' => 'Payments', 'noun' => 'payments', 'date' => 'Paid'],
    ];

    private const TOP_KEYS = ['subject', 'measures', 'filters', 'period', 'breakdown'];

    private const FORM_LABELS = [
        'sole_proprietorship' => 'Sole proprietorship',
        'partnership' => 'Partnership',
        'corporation' => 'Corporation',
        'cooperative' => 'Cooperative',
    ];

    private const ECONOMIC_LABELS = [
        'single_establishment' => 'Single establishment',
        'branch' => 'Branch',
        'establishment_and_main_office' => 'Establishment and main office',
        'main_office_only' => 'Main office only',
        'ancillary_unit' => 'Ancillary unit',
        'others' => 'Others',
    ];

    private const BUSINESS_STATUS_LABELS = [
        'active' => 'Active',
        'flagged' => 'Flagged',
        'suspended' => 'Suspended',
        'blacklisted' => 'Blacklisted',
        'retired' => 'Retired',
    ];

    private const YES_NO = ['1' => 'Yes', '0' => 'No'];

    /* ── the catalogue ───────────────────────────────────────────────────── */

    /**
     * The numbers each subject can show: label, format, SQL aggregate.
     *
     * `line` marks a figure recorded per line of business (see the class
     * note); `distinct` a count of distinct businesses, which a business in
     * two rows makes add up to more than its total.
     *
     * @return array<string, array{label: string, format: string, sql: string, line?: bool, distinct?: bool, sum?: bool}>
     */
    public static function measures(string $subject): array
    {
        return match ($subject) {
            'businesses' => [
                'count' => ['label' => 'Number of businesses', 'format' => 'count', 'sql' => 'count(distinct b.id)'],
                'total_employees' => ['label' => 'Total employees', 'format' => 'count', 'sql' => 'sum(b.total_employees)', 'sum' => true],
                'male_employees' => ['label' => 'Male employees', 'format' => 'count', 'sql' => 'sum(b.male_employees)', 'sum' => true],
                'female_employees' => ['label' => 'Female employees', 'format' => 'count', 'sql' => 'sum(b.female_employees)', 'sum' => true],
                'employees_within_lgu' => ['label' => 'Employees living in Malabon', 'format' => 'count', 'sql' => 'sum(b.employees_within_lgu)', 'sum' => true],
                'delivery_units' => ['label' => 'Delivery vehicles', 'format' => 'count', 'sql' => 'sum(b.delivery_units)', 'sum' => true],
                'capital_investment' => ['label' => 'Capital investment (PHP)', 'format' => 'money', 'sql' => 'sum(b.capital_investment)', 'sum' => true],
                'business_area_sqm' => ['label' => 'Business area (sq m)', 'format' => 'decimal', 'sql' => 'sum(b.business_area_sqm)', 'sum' => true],
                'capitalization' => ['label' => 'Capitalization (PHP)', 'format' => 'money', 'sql' => 'sum(%s.cap)', 'line' => true, 'sum' => true],
                'gross_sales' => ['label' => 'Gross sales (PHP)', 'format' => 'money', 'sql' => 'sum(%s.gross)', 'line' => true, 'sum' => true],
            ],
            'applications' => [
                'count' => ['label' => 'Number of applications', 'format' => 'count', 'sql' => 'count(distinct a.id)'],
                'businesses' => ['label' => 'Number of businesses', 'format' => 'count', 'sql' => 'count(distinct a.business_id)', 'distinct' => true],
            ],
            'permits' => [
                'count' => ['label' => 'Number of permits', 'format' => 'count', 'sql' => 'count(distinct p.id)'],
                'businesses' => ['label' => 'Number of businesses', 'format' => 'count', 'sql' => 'count(distinct p.business_id)', 'distinct' => true],
            ],
            'payments' => [
                'count' => ['label' => 'Number of payments', 'format' => 'count', 'sql' => 'count(distinct pay.id)'],
                'amount' => ['label' => 'Amount collected (PHP)', 'format' => 'money', 'sql' => 'sum(pay.amount)', 'sum' => true],
            ],
            default => [],
        };
    }

    /**
     * Everything a report can filter on or break down by.
     *
     * `sql` is the grouping/filtering expression, written here and nowhere
     * else; `join` names the on-demand join it needs; `cast` how a chosen value
     * is bound. `filter`/`breakdown` say which of the two it may be used as.
     *
     * @return array<string, array{label: string, subjects: list<string>, sql?: string, join?: string, cast?: string, line?: bool, filter?: bool, breakdown?: bool, empty?: string, closed?: bool}>
     */
    private static function dimensions(): array
    {
        $all = array_keys(self::SUBJECTS);

        return [
            'barangay' => ['label' => 'Barangay', 'subjects' => $all, 'sql' => 'loc.barangay_id', 'join' => 'loc', 'cast' => 'int', 'empty' => 'No barangay on record', 'closed' => true],
            'psic' => ['label' => 'Line of business', 'subjects' => $all, 'sql' => 'lines.k', 'line' => true, 'cast' => 'int', 'empty' => 'No line of business on record'],
            'kind' => ['label' => 'Kind of business', 'subjects' => $all, 'sql' => 'lines.k', 'line' => true, 'empty' => 'Not classified', 'closed' => true],
            'form' => ['label' => 'Form of organization', 'subjects' => $all, 'sql' => 'b.form_of_organization', 'closed' => true],
            'registrar' => ['label' => 'Registered with', 'subjects' => $all, 'sql' => self::registrarSql(), 'closed' => true],
            'economic' => ['label' => 'Type of establishment', 'subjects' => $all, 'sql' => 'b.economic_organization', 'closed' => true],
            'business_status' => ['label' => 'Business status', 'subjects' => $all, 'sql' => 'b.status', 'closed' => true],
            'owner_sex' => ['label' => 'Owner’s sex', 'subjects' => $all, 'sql' => 'own.gender', 'join' => 'own', 'closed' => true],
            'citizenship' => ['label' => 'Citizenship', 'subjects' => $all, 'sql' => 'lower(trim(b.citizenship))'],
            // `case when col` rather than `col = 1`: these are boolean columns on
            // PostgreSQL, where comparing a boolean to an integer is an error.
            'tax_incentives' => ['label' => 'Has tax incentives', 'subjects' => $all, 'sql' => 'case when b.has_tax_incentives then 1 else 0 end', 'cast' => 'int', 'closed' => true],
            'rented' => ['label' => 'Rents the business place', 'subjects' => $all, 'sql' => 'case when b.is_rented then 1 else 0 end', 'cast' => 'int', 'closed' => true],
            'standing' => ['label' => 'Holds a valid permit today', 'subjects' => ['businesses'], 'sql' => 'case when act.business_id is null then 0 else 1 end', 'join' => 'act', 'cast' => 'int', 'closed' => true],
            'app_type' => ['label' => 'Application type', 'subjects' => ['applications', 'payments'], 'sql' => 'a.application_type', 'closed' => true],
            'app_status' => ['label' => 'Application status', 'subjects' => ['applications'], 'sql' => 'a.status', 'closed' => true],
            'permit_type' => ['label' => 'Permit type', 'subjects' => ['permits'], 'sql' => 'p.permit_type_id', 'cast' => 'int', 'closed' => true],
            'permit_status' => ['label' => 'Permit status', 'subjects' => ['permits'], 'sql' => 'p.status', 'closed' => true],
            'filed_as' => ['label' => 'Filed as', 'subjects' => ['permits'], 'sql' => "coalesce(pa.application_type, 'none')", 'join' => 'pa', 'closed' => true],
            'method' => ['label' => 'Payment method', 'subjects' => ['payments'], 'sql' => 'pay.method', 'closed' => true],
            'month' => ['label' => 'Month', 'subjects' => ['applications', 'permits', 'payments'], 'filter' => false, 'empty' => 'No date on record'],
        ];
    }

    /** The agency that registers each form (Business::REGISTRAR_BY_FORM), as SQL. */
    private static function registrarSql(): string
    {
        $cases = '';
        foreach (Business::REGISTRAR_BY_FORM as $form => $agency) {
            // Both sides are constants of this codebase, never input.
            $cases .= " when '{$form}' then '{$agency}'";
        }

        return "case b.form_of_organization{$cases} end";
    }

    /**
     * The choices a filter offers, value => label, in the order a breakdown
     * lists them. Values are strings on the wire whatever the column holds.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     * @return array<string, string>
     */
    private static function options(string $dimension, ?array $scope): array
    {
        return match ($dimension) {
            'barangay' => DB::table('barangays')->orderBy('name')->pluck('name', 'id')
                ->mapWithKeys(static fn ($n, $id) => [(string) $id => (string) $n])->all(),
            'psic' => DB::table('psic_codes')->orderBy('code')->get(['id', 'code', 'title'])
                ->mapWithKeys(static fn ($r) => [(string) $r->id => "{$r->code} — {$r->title}"])->all(),
            'kind' => DB::table('psic_codes')->whereNotNull('category')->where('category', '!=', '')
                ->distinct()->orderBy('category')->pluck('category')
                ->mapWithKeys(static fn ($c) => [(string) $c => ucfirst(str_replace('_', ' ', (string) $c))])->all(),
            'form' => self::FORM_LABELS,
            'registrar' => self::registrars(),
            'economic' => self::ECONOMIC_LABELS,
            'business_status' => self::BUSINESS_STATUS_LABELS,
            'owner_sex' => ['M' => 'Male', 'F' => 'Female'],
            'citizenship' => DB::table('businesses')->whereNotNull('citizenship')
                ->whereRaw("trim(citizenship) != ''")
                ->selectRaw('distinct lower(trim(citizenship)) as c')
                ->orderBy('c')->limit(100)->pluck('c')
                ->mapWithKeys(static fn ($c) => [(string) $c => ucwords((string) $c)])->all(),
            'tax_incentives', 'rented', 'standing' => self::YES_NO,
            'app_type' => self::enumLabels(ApplicationType::cases()),
            'app_status' => array_filter(
                self::enumLabels(ApplicationStatus::cases()),
                static fn (string $k) => $k !== ApplicationStatus::Draft->value,
                ARRAY_FILTER_USE_KEY,
            ),
            'permit_type' => DB::table('permit_types')
                ->when($scope !== null, static fn ($q) => $q->whereIn('id', $scope['permit_type_ids'] === [] ? [0] : $scope['permit_type_ids']))
                ->orderBy('id')->pluck('name', 'id')
                ->mapWithKeys(static fn ($n, $id) => [(string) $id => (string) $n])->all(),
            'permit_status' => self::enumLabels(PermitStatus::cases()),
            'filed_as' => self::enumLabels(ApplicationType::cases()) + ['none' => 'No filing (old register)'],
            'method' => self::enumLabels(PaymentMethod::cases()),
            default => [],
        };
    }

    /** @return array<string, string> DTI, SEC, CDA — each agency once */
    private static function registrars(): array
    {
        $agencies = array_values(array_unique(Business::REGISTRAR_BY_FORM));

        return array_combine($agencies, $agencies);
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumLabels(array $cases): array
    {
        $out = [];
        foreach ($cases as $case) {
            $out[(string) $case->value] = method_exists($case, 'label') ? $case->label() : ucfirst((string) $case->value);
        }

        return $out;
    }

    /**
     * The one-click starting points. Each is a plain definition; the screen
     * loads it into the four steps, where the reader can change anything.
     *
     * @return list<array{key: string, title: string, definition: array<string, mixed>}>
     */
    public static function starters(): array
    {
        $today = ManilaCalendar::today();
        $year = ['from' => $today->startOfYear()->toDateString(), 'to' => $today->toDateString()];

        return [
            ['key' => 'employees-by-sex', 'title' => 'Employees by sex', 'definition' => [
                'subject' => 'businesses', 'measures' => ['male_employees', 'female_employees', 'total_employees'],
                'filters' => [], 'period' => null, 'breakdown' => ['barangay'],
            ]],
            ['key' => 'businesses-per-barangay', 'title' => 'Businesses per barangay', 'definition' => [
                'subject' => 'businesses', 'measures' => ['count'], 'filters' => [], 'period' => null, 'breakdown' => ['barangay'],
            ]],
            ['key' => 'businesses-per-line', 'title' => 'Businesses per line of business', 'definition' => [
                'subject' => 'businesses', 'measures' => ['count'], 'filters' => [], 'period' => null, 'breakdown' => ['psic'],
            ]],
            ['key' => 'collections-per-month', 'title' => 'Collections per month', 'definition' => [
                'subject' => 'payments', 'measures' => ['count', 'amount'], 'filters' => [], 'period' => $year, 'breakdown' => ['month'],
            ]],
            ['key' => 'permits-per-type', 'title' => 'Permits issued per type', 'definition' => [
                'subject' => 'permits', 'measures' => ['count'], 'filters' => [], 'period' => $year, 'breakdown' => ['permit_type'],
            ]],
            ['key' => 'applications-per-month', 'title' => 'Applications per month, by type', 'definition' => [
                'subject' => 'applications', 'measures' => ['count'], 'filters' => [], 'period' => $year, 'breakdown' => ['month', 'app_type'],
            ]],
            ['key' => 'owners-by-sex', 'title' => 'Businesses by owner’s sex', 'definition' => [
                'subject' => 'businesses', 'measures' => ['count'], 'filters' => [], 'period' => null, 'breakdown' => ['owner_sex'],
            ]],
            ['key' => 'businesses-by-form', 'title' => 'Businesses by form of organization', 'definition' => [
                'subject' => 'businesses', 'measures' => ['count', 'capital_investment'], 'filters' => [], 'period' => null, 'breakdown' => ['form'],
            ]],
        ];
    }

    /**
     * What the screen needs to draw the four steps.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     * @return array<string, mixed>
     */
    public static function catalogue(?array $scope): array
    {
        $subjects = [];
        foreach (self::SUBJECTS as $key => $subject) {
            $filters = [];
            $breakdowns = [];
            foreach (self::dimensions() as $dim => $spec) {
                if (! in_array($key, $spec['subjects'], true)) {
                    continue;
                }
                if ($spec['filter'] ?? true) {
                    $options = [];
                    foreach (self::options($dim, $scope) as $value => $label) {
                        $options[] = ['value' => (string) $value, 'label' => $label];
                    }
                    $filters[] = ['key' => $dim, 'label' => $spec['label'], 'options' => $options];
                }
                if ($spec['breakdown'] ?? true) {
                    $breakdowns[] = ['key' => $dim, 'label' => $spec['label']];
                }
            }

            $subjects[] = [
                'key' => $key,
                'label' => $subject['label'],
                'date_label' => $subject['date'],
                'measures' => array_map(
                    static fn (string $k, array $m) => ['key' => $k, 'label' => $m['label'], 'format' => $m['format']],
                    array_keys(self::measures($key)),
                    self::measures($key),
                ),
                'filters' => $filters,
                'breakdowns' => $breakdowns,
            ];
        }

        return [
            'subjects' => $subjects,
            'starters' => self::starters(),
            'limits' => ['measures' => self::MAX_MEASURES, 'breakdown' => self::MAX_BREAKDOWN, 'rows' => self::MAX_ROWS],
        ];
    }

    /* ── validation ──────────────────────────────────────────────────────── */

    /**
     * Check a definition strictly and return it normalised.
     *
     * Messages are one short line each (the screen shows them as they are).
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     * @return array{subject: string, measures: list<string>, filters: array<string, list<string>>, period: array{from: string|null, to: string|null}|null, breakdown: list<string>}
     */
    public static function validate(mixed $definition, ?array $scope): array
    {
        $fail = static fn (string $message, string $field = 'definition') => throw ValidationException::withMessages([$field => [$message]]);

        if (! is_array($definition)) {
            $fail('Choose what to count.');
        }
        foreach (array_keys($definition) as $key) {
            if (! in_array($key, self::TOP_KEYS, true)) {
                $fail('Unknown setting: '.mb_strimwidth((string) $key, 0, 24, '…').'.');
            }
        }

        $subject = $definition['subject'] ?? null;
        if (! is_string($subject) || ! array_key_exists($subject, self::SUBJECTS)) {
            $fail('Choose what to count.', 'definition.subject');
        }

        // Measures: 1..8 distinct names this subject offers.
        $measures = $definition['measures'] ?? [];
        if (! is_array($measures) || ! array_is_list($measures) || $measures === []) {
            $fail('Tick at least one number to show.', 'definition.measures');
        }
        if (count($measures) > self::MAX_MEASURES) {
            $fail('Show up to '.self::MAX_MEASURES.' numbers at a time.', 'definition.measures');
        }
        $offered = self::measures($subject);
        foreach ($measures as $m) {
            if (! is_string($m) || ! array_key_exists($m, $offered)) {
                $fail('A chosen number is not offered here.', 'definition.measures');
            }
        }
        if (count(array_unique($measures)) !== count($measures)) {
            $fail('A number is ticked twice.', 'definition.measures');
        }

        // Filters: dimension => non-empty list of declared choices.
        $filters = $definition['filters'] ?? [];
        if ($filters === null) {
            $filters = [];
        }
        if (! is_array($filters) || ($filters !== [] && array_is_list($filters))) {
            $fail('Filters are not in the right shape.', 'definition.filters');
        }
        $dimensions = self::dimensions();
        $cleanFilters = [];
        foreach ($filters as $dim => $values) {
            $spec = $dimensions[$dim] ?? null;
            if ($spec === null || ! ($spec['filter'] ?? true) || ! in_array($subject, $spec['subjects'], true)) {
                $fail('A filter does not apply to '.self::SUBJECTS[$subject]['label'].'.', 'definition.filters');
            }
            if (! is_array($values) || ! array_is_list($values) || $values === []) {
                $fail('Pick at least one '.mb_strtolower($spec['label']).'.', "definition.filters.{$dim}");
            }
            if (count($values) > self::MAX_VALUES) {
                $fail('Too many choices in one filter.', "definition.filters.{$dim}");
            }
            $choices = self::options($dim, $scope);
            $clean = [];
            foreach ($values as $value) {
                if (! is_string($value) && ! is_int($value)) {
                    $fail('Unknown choice in '.$spec['label'].'.', "definition.filters.{$dim}");
                }
                $value = (string) $value;
                if (! array_key_exists($value, $choices)) {
                    $fail('Unknown choice in '.$spec['label'].'.', "definition.filters.{$dim}");
                }
                $clean[$value] = true;
            }
            $cleanFilters[$dim] = array_map('strval', array_keys($clean));
        }

        // Period: dates only, and only where the subject has a date.
        $period = $definition['period'] ?? null;
        if ($period !== null) {
            if (self::SUBJECTS[$subject]['date'] === null) {
                $fail('Dates do not apply to businesses.', 'definition.period');
            }
            if (! is_array($period) || array_diff(array_keys($period), ['from', 'to']) !== []) {
                $fail('Dates are not in the right shape.', 'definition.period');
            }
            foreach (['from', 'to'] as $end) {
                $value = $period[$end] ?? null;
                if ($value === null || $value === '') {
                    $period[$end] = null;

                    continue;
                }
                $parsed = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                    $fail('Enter dates as YYYY-MM-DD.', "definition.period.{$end}");
                }
            }
            if ($period['from'] !== null && $period['to'] !== null && $period['to'] < $period['from']) {
                $fail('The end date is before the start date.', 'definition.period');
            }
            if ($period['from'] === null && $period['to'] === null) {
                $period = null;
            }
        }

        // Breakdown: up to two distinct dimensions this subject offers.
        $breakdown = $definition['breakdown'] ?? [];
        if ($breakdown === null) {
            $breakdown = [];
        }
        if (! is_array($breakdown) || ! array_is_list($breakdown)) {
            $fail('Breakdown is not in the right shape.', 'definition.breakdown');
        }
        if (count($breakdown) > self::MAX_BREAKDOWN) {
            $fail('Break down by up to two things.', 'definition.breakdown');
        }
        foreach ($breakdown as $dim) {
            $spec = is_string($dim) ? ($dimensions[$dim] ?? null) : null;
            if ($spec === null || ! ($spec['breakdown'] ?? true) || ! in_array($subject, $spec['subjects'], true)) {
                $fail('A breakdown does not apply here.', 'definition.breakdown');
            }
        }
        if (count(array_unique($breakdown)) !== count($breakdown)) {
            $fail('Pick two different breakdowns.', 'definition.breakdown');
        }
        if (in_array('psic', $breakdown, true) && in_array('kind', $breakdown, true)) {
            $fail('Pick line of business or kind, not both.', 'definition.breakdown');
        }

        return [
            'subject' => $subject,
            'measures' => array_values($measures),
            'filters' => $cleanFilters,
            'period' => $period,
            'breakdown' => array_values($breakdown),
        ];
    }

    /* ── running a report ────────────────────────────────────────────────── */

    /**
     * Run a VALIDATED definition (validate() first).
     *
     * @param  array{subject: string, measures: list<string>, filters: array<string, list<string>>, period: array{from: string|null, to: string|null}|null, breakdown: list<string>}  $def
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     * @return array<string, mixed>
     */
    public static function run(array $def, ?array $scope): array
    {
        $subject = $def['subject'];
        $measures = self::measures($subject);
        $dimensions = self::dimensions();
        $chosen = array_intersect_key($measures, array_flip($def['measures']));
        // In the order the reader ticked them, not the catalogue's.
        $chosen = array_replace(array_flip($def['measures']), $chosen);

        $columns = [];
        foreach ($def['breakdown'] as $i => $dim) {
            $columns[] = ['key' => 'by'.($i + 1), 'label' => $dimensions[$dim]['label'], 'format' => 'text'];
        }
        if ($def['breakdown'] === []) {
            $columns[] = ['key' => 'by1', 'label' => self::SUBJECTS[$subject]['label'], 'format' => 'text'];
        }
        foreach ($chosen as $key => $m) {
            $columns[] = ['key' => $key, 'label' => $m['label'], 'format' => $m['format']];
        }

        $officePayments = $subject === 'payments' && $scope !== null;

        // The Total row: the same filters, no breakdown. Never the sum of the rows.
        $total = $officePayments
            ? self::officePaymentTotals($def, $scope)
            : self::sqlTotals($def, $scope, $chosen);

        $rows = [];
        $truncated = 0;
        if ($def['breakdown'] !== [] && $total['_n'] > 0) {
            $grouped = $officePayments
                ? self::officePaymentGroups($def, $scope)
                : self::sqlGroups($def, $scope, $chosen);
            [$rows, $truncated] = self::shapeRows($def, $scope, $grouped, $chosen);
        }

        $totalRow = ['by1' => 'Total'];
        if (count($def['breakdown']) === 2) {
            $totalRow['by2'] = '';
        }
        foreach ($chosen as $key => $m) {
            $totalRow[$key] = self::cast($total[$key] ?? null, $m['format']);
        }

        return [
            'subject' => $subject,
            'title' => self::title($def),
            'columns' => $columns,
            'rows' => $rows,
            'total' => $totalRow,
            'matched' => (int) $total['_n'],
            'empty' => $total['_n'] === 0
                ? 'No '.self::SUBJECTS[$subject]['noun'].' match these filters.'
                : null,
            'truncated' => $truncated,
            'filters_text' => self::describeFilters($def, $scope),
            'period' => $def['period'],
            'notes' => self::notes($def, $scope, $chosen),
            'definition' => $def,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $chosen
     * @return array<string, mixed>
     */
    private static function sqlTotals(array $def, ?array $scope, array $chosen): array
    {
        $query = self::base($def, $scope, false);
        $query->selectRaw(self::idCount($def['subject']).' as _n');
        foreach ($chosen as $key => $m) {
            $query->selectRaw(self::measureSql($m, false).' as '.self::alias($key));
        }

        $row = (array) $query->first();
        $out = ['_n' => (int) ($row['_n'] ?? 0)];
        foreach (array_keys($chosen) as $key) {
            $out[$key] = $row[self::alias($key)] ?? null;
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $chosen
     * @return list<array{keys: list<string|null>, values: array<string, mixed>}>
     */
    private static function sqlGroups(array $def, ?array $scope, array $chosen): array
    {
        $query = self::base($def, $scope, true);
        $line = self::lineBreakdown($def) !== null;
        $exprs = [];
        foreach ($def['breakdown'] as $i => $dim) {
            $exprs[$i] = self::dimensionSql($dim, $def['subject']);
            $query->selectRaw("{$exprs[$i]} as g{$i}");
        }
        foreach ($chosen as $key => $m) {
            $query->selectRaw(self::measureSql($m, $line).' as '.self::alias($key));
        }
        foreach ($exprs as $expr) {
            $query->groupByRaw($expr);
        }

        $out = [];
        foreach ($query->get() as $row) {
            $row = (array) $row;
            $keys = [];
            foreach (array_keys($def['breakdown']) as $i) {
                $keys[] = $row["g{$i}"] === null ? null : (string) $row["g{$i}"];
            }
            $values = [];
            foreach (array_keys($chosen) as $key) {
                $values[$key] = $row[self::alias($key)] ?? null;
            }
            $out[] = ['keys' => $keys, 'values' => $values];
        }

        return $out;
    }

    /**
     * An office's payments: the fee lines billed in its name, each payment
     * spread over its lines in proportion — LguReports::collections(), read
     * the same way, so this total and that report's agree.
     *
     * @return iterable<array{id: int, share: float, keys: list<string|null>}>
     */
    private static function officePaymentRows(array $def, array $scope, bool $grouped): iterable
    {
        $query = self::base($def, $scope, $grouped)
            ->join('fee_assessments as fa', 'fa.id', '=', 'pay.fee_assessment_id')
            ->select(['pay.id', 'pay.amount', 'fa.line_items', 'fa.total_amount']);
        if ($grouped) {
            foreach ($def['breakdown'] as $i => $dim) {
                $query->selectRaw(self::dimensionSql($dim, 'payments')." as g{$i}");
            }
        }

        $officeOfPermit = LguReports::issuingOfficeByPermitCode();
        foreach ($query->orderBy('pay.id')->cursor() as $row) {
            $row = (array) $row;
            $assessed = (float) $row['total_amount'];
            if ($assessed <= 0) {
                continue;
            }
            $ratio = (float) $row['amount'] / $assessed;
            $share = 0.0;
            foreach (json_decode((string) $row['line_items'], true) ?: [] as $line) {
                if (is_array($line) && LguReports::lineOffice($line, $officeOfPermit) === $scope['code']) {
                    $share += (float) ($line['amount'] ?? 0) * $ratio;
                }
            }
            if ($share == 0.0) {
                continue;
            }
            $keys = [];
            if ($grouped) {
                foreach (array_keys($def['breakdown']) as $i) {
                    $keys[] = $row["g{$i}"] === null ? null : (string) $row["g{$i}"];
                }
            }
            yield ['id' => (int) $row['id'], 'share' => $share, 'keys' => $keys];
        }
    }

    /** @return array<string, mixed> */
    private static function officePaymentTotals(array $def, array $scope): array
    {
        $seen = [];
        $amount = 0.0;
        foreach (self::officePaymentRows($def, $scope, false) as $row) {
            if (! isset($seen[$row['id']])) {
                $seen[$row['id']] = true;
                $amount += $row['share'];
            }
        }

        return ['_n' => count($seen), 'count' => count($seen), 'amount' => $seen === [] ? null : $amount];
    }

    /** @return list<array{keys: list<string|null>, values: array<string, mixed>}> */
    private static function officePaymentGroups(array $def, array $scope): array
    {
        $groups = [];
        foreach (self::officePaymentRows($def, $scope, true) as $row) {
            $k = json_encode($row['keys']);
            $groups[$k] ??= ['keys' => $row['keys'], 'ids' => [], 'amount' => 0.0];
            if (! isset($groups[$k]['ids'][$row['id']])) {
                $groups[$k]['ids'][$row['id']] = true;
                $groups[$k]['amount'] += $row['share'];
            }
        }

        return array_values(array_map(static fn (array $g) => [
            'keys' => $g['keys'],
            'values' => ['count' => count($g['ids']), 'amount' => $g['amount']],
        ], $groups));
    }

    /**
     * The subject's rows with every filter applied and, when $grouped, the
     * breakdown's joins. Joins are added only when something needs them.
     */
    private static function base(array $def, ?array $scope, bool $grouped): QueryBuilder
    {
        $subject = $def['subject'];
        $period = $def['period'] === null ? null : self::instants($def['period']);

        switch ($subject) {
            case 'businesses':
                $query = DB::table('businesses as b')->whereNull('b.deleted_at')
                    ->where(static fn (QueryBuilder $q) => $q
                        ->whereIn('b.id', DB::table('applications')->select('business_id')
                            ->whereNull('deleted_at')
                            ->where('status', '!=', ApplicationStatus::Draft->value))
                        ->orWhereIn('b.id', DB::table('permits')->select('business_id')));
                if ($scope !== null) {
                    $query->where(static fn (QueryBuilder $q) => $q
                        ->whereIn('b.id', DB::table('applications')->select('applications.business_id')
                            ->whereIn('applications.id', self::routed($scope)))
                        ->orWhereIn('b.id', DB::table('permits')->select('business_id')
                            ->whereIn('permit_type_id', self::types($scope))));
                }
                $bid = 'b.id';
                break;

            case 'applications':
                $query = DB::table('applications as a')
                    ->join('businesses as b', 'b.id', '=', 'a.business_id')
                    ->whereNull('a.deleted_at')
                    ->whereNull('b.deleted_at')
                    ->where('a.status', '!=', ApplicationStatus::Draft->value);
                if ($scope !== null) {
                    $query->whereIn('a.id', self::routed($scope));
                }
                if ($period !== null) {
                    self::between($query, 'a.submitted_at', $period);
                }
                $bid = 'a.business_id';
                break;

            case 'permits':
                $query = DB::table('permits as p')
                    ->leftJoin('businesses as b', 'b.id', '=', 'p.business_id')
                    ->whereNotNull('p.issued_at');
                if ($scope !== null) {
                    $query->whereIn('p.permit_type_id', self::types($scope));
                }
                if ($period !== null) {
                    self::between($query, 'p.issued_at', $period);
                }
                $bid = 'p.business_id';
                break;

            default: // payments
                $query = DB::table('payments as pay')
                    ->join('applications as a', 'a.id', '=', 'pay.application_id')
                    ->leftJoin('businesses as b', 'b.id', '=', 'a.business_id')
                    ->where('pay.status', PaymentStatus::Completed->value)
                    ->whereNotNull('pay.paid_at');
                if ($period !== null) {
                    self::between($query, 'pay.paid_at', $period);
                }
                $bid = 'a.business_id';
        }

        $dimensions = self::dimensions();
        $used = array_keys($def['filters']);
        if ($grouped) {
            $used = array_merge($used, $def['breakdown']);
        }
        $joins = [];
        foreach ($used as $dim) {
            if (isset($dimensions[$dim]['join'])) {
                $joins[$dimensions[$dim]['join']] = true;
            }
        }

        if (isset($joins['loc'])) {
            // One business location per business: LguReports places a business
            // under its first `business_location` row, and so does this.
            $query->leftJoinSub(
                DB::table('business_addresses')->where('address_type', 'business_location')
                    ->groupBy('business_id')->selectRaw('business_id, min(barangay_id) as barangay_id'),
                'loc', 'loc.business_id', '=', $bid,
            );
        }
        if (isset($joins['own'])) {
            $query->leftJoinSub(
                DB::table('business_owners')->where('is_primary', true)
                    ->groupBy('business_id')->selectRaw('business_id, max(gender) as gender'),
                'own', 'own.business_id', '=', $bid,
            );
        }
        if (isset($joins['act'])) {
            // Holding a permit valid today (DashboardAnalytics::activeBusinesses):
            // scoped, a permit of the office's own types.
            $query->leftJoinSub(
                DB::table('permits')
                    ->where('status', PermitStatus::Active->value)
                    ->whereDate('valid_until', '>=', ManilaCalendar::today()->toDateString())
                    ->when($scope !== null, static fn ($q) => $q->whereIn('permit_type_id', self::types($scope)))
                    ->distinct()->select('business_id'),
                'act', 'act.business_id', '=', $bid,
            );
        }
        if (isset($joins['pa'])) {
            $query->leftJoin('applications as pa', 'pa.id', '=', 'p.application_id');
        }

        // Line-of-business figures without a line breakdown: the business's
        // own total over its lines, one row per business.
        $lineMeasures = array_filter(self::measures($subject), static fn ($m) => $m['line'] ?? false);
        $line = $grouped ? self::lineBreakdown($def) : null;
        if ($line === null && array_intersect($def['measures'], array_keys($lineMeasures)) !== []) {
            $query->leftJoinSub(
                DB::table('business_lines')->groupBy('business_id')
                    ->selectRaw('business_id, sum(capitalization) as cap, sum(gross_sales) as gross'),
                'blt', 'blt.business_id', '=', $bid,
            );
        }
        if ($line !== null) {
            // One row per (business, line) or (business, kind) — distinct, so a
            // business with two lines of the same kind is under that kind once.
            $lines = $line === 'psic'
                ? DB::table('business_lines')->groupBy('business_id', 'psic_code_id')
                    ->selectRaw('business_id, psic_code_id as k, sum(capitalization) as cap, sum(gross_sales) as gross')
                : DB::table('business_lines')->join('psic_codes', 'psic_codes.id', '=', 'business_lines.psic_code_id')
                    ->groupBy('business_lines.business_id', 'psic_codes.category')
                    ->selectRaw('business_lines.business_id as business_id, psic_codes.category as k, sum(business_lines.capitalization) as cap, sum(business_lines.gross_sales) as gross');
            $query->leftJoinSub($lines, 'lines', 'lines.business_id', '=', $bid);
        }

        // Filters. Line filters keep a business with ANY chosen line, once.
        foreach ($def['filters'] as $dim => $values) {
            $spec = $dimensions[$dim];
            $bound = ($spec['cast'] ?? null) === 'int' ? array_map('intval', $values) : $values;
            if ($dim === 'psic') {
                $query->whereIn($bid, DB::table('business_lines')->select('business_id')->whereIn('psic_code_id', $bound));
            } elseif ($dim === 'kind') {
                $query->whereIn($bid, DB::table('business_lines')
                    ->join('psic_codes', 'psic_codes.id', '=', 'business_lines.psic_code_id')
                    ->select('business_lines.business_id')
                    ->whereIn('psic_codes.category', $bound));
            } else {
                $query->whereIn(DB::raw($spec['sql']), $bound);
            }
        }

        return $query;
    }

    /** Applications routed to the office (ApplicationVisibility::scope's rule). */
    private static function routed(array $scope): QueryBuilder
    {
        return DB::table('application_assignments')->select('application_id')
            ->where('department_id', $scope['department_id']);
    }

    /** @return list<int> */
    private static function types(array $scope): array
    {
        return $scope['permit_type_ids'] === [] ? [0] : $scope['permit_type_ids'];
    }

    /** @param  array{0: CarbonImmutable|null, 1: CarbonImmutable|null}  $period */
    private static function between(QueryBuilder $query, string $column, array $period): void
    {
        if ($period[0] !== null) {
            $query->where($column, '>=', $period[0]);
        }
        if ($period[1] !== null) {
            $query->where($column, '<', $period[1]);
        }
    }

    /**
     * Manila dates as the instants that bound them (ManilaCalendar::period):
     * the first moment of `from`, the first moment AFTER `to`.
     *
     * @param  array{from: string|null, to: string|null}  $period
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function instants(array $period): array
    {
        return [
            $period['from'] === null ? null : ManilaCalendar::startOfDay($period['from']),
            $period['to'] === null ? null : ManilaCalendar::startOfNextDay($period['to']),
        ];
    }

    private static function lineBreakdown(array $def): ?string
    {
        foreach ($def['breakdown'] as $dim) {
            if (self::dimensions()[$dim]['line'] ?? false) {
                return $dim;
            }
        }

        return null;
    }

    private static function measureSql(array $measure, bool $lineGrouped): string
    {
        return ($measure['line'] ?? false)
            ? sprintf($measure['sql'], $lineGrouped ? 'lines' : 'blt')
            : $measure['sql'];
    }

    private static function idCount(string $subject): string
    {
        return match ($subject) {
            'businesses' => 'count(distinct b.id)',
            'applications' => 'count(distinct a.id)',
            'permits' => 'count(distinct p.id)',
            default => 'count(distinct pay.id)',
        };
    }

    /** Measure keys are whitelisted, but aliases are prefixed so none is a keyword. */
    private static function alias(string $key): string
    {
        return 'm_'.$key;
    }

    private static function dimensionSql(string $dim, string $subject): string
    {
        if ($dim !== 'month') {
            return self::dimensions()[$dim]['sql'];
        }

        $column = match ($subject) {
            'applications' => 'a.submitted_at',
            'permits' => 'p.issued_at',
            default => 'pay.paid_at',
        };

        return self::monthSql($column);
    }

    /**
     * The Manila month ("2026-09") of a stored timestamp, in SQL.
     *
     * ManilaCalendar buckets in PHP because the engines share no date
     * function; a GROUP BY cannot, so this is written once per engine. The
     * stored zone is the app's (Asia/Manila since 5 October 2026); if it is
     * ever set back to UTC the offset below shifts the instant into Manila
     * first. Both zones have fixed offsets, and the offset is an integer this
     * method computes — nothing from the request reaches the expression.
     */
    private static function monthSql(string $column): string
    {
        $now = CarbonImmutable::now();
        $offset = (int) ($now->setTimezone(ManilaCalendar::timezone())->getOffset()
            - $now->setTimezone((string) config('app.timezone', 'UTC'))->getOffset());

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column} + interval '{$offset} seconds', 'YYYY-MM')",
            'mysql', 'mariadb' => "date_format(date_add({$column}, interval {$offset} second), '%Y-%m')",
            default => sprintf("strftime('%%Y-%%m', %s, '%+d seconds')", $column, $offset),
        };
    }

    /**
     * Groups into labelled, ordered rows: labels from the declared choices,
     * every choice of a one-level breakdown listed even at zero (a barangay
     * with no businesses is a figure, not an omission), capped at MAX_ROWS.
     *
     * @param  list<array{keys: list<string|null>, values: array<string, mixed>}>  $groups
     * @param  array<string, array<string, mixed>>  $chosen
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private static function shapeRows(array $def, ?array $scope, array $groups, array $chosen): array
    {
        $dimensions = self::dimensions();
        $labels = [];
        $orders = [];
        foreach ($def['breakdown'] as $i => $dim) {
            $labels[$i] = $dim === 'month' ? [] : self::options($dim, $scope);
            $orders[$i] = array_flip(array_map('strval', array_keys($labels[$i])));
        }

        // A one-level breakdown over a closed list shows every choice.
        if (count($def['breakdown']) === 1) {
            $dim = $def['breakdown'][0];
            $fill = [];
            if ($dim === 'month' && $def['period'] !== null && $def['period']['from'] !== null) {
                $fill = array_keys(self::months($def['period']));
            } elseif ($dims = $dimensions[$dim] ?? null) {
                if ($dims['closed'] ?? false) {
                    $fill = array_map('strval', array_keys($labels[0]));
                }
            }
            if (isset($def['filters'][$dim])) {
                $fill = array_values(array_intersect($fill, $def['filters'][$dim]));
            }
            $present = [];
            foreach ($groups as $g) {
                $present[(string) $g['keys'][0]] = true;
            }
            foreach ($fill as $key) {
                if (! isset($present[$key])) {
                    $groups[] = ['keys' => [$key], 'values' => array_map(
                        static fn ($m) => ($m['sum'] ?? false) ? null : 0,
                        $chosen,
                    )];
                }
            }
        }

        $rank = static function (int $i, ?string $key) use ($def, $orders): array {
            if ($key === null) {
                return [2, ''];
            }
            if ($def['breakdown'][$i] === 'month' || ! isset($orders[$i][$key])) {
                return [1, $key];
            }

            return [0, sprintf('%06d', $orders[$i][$key])];
        };
        usort($groups, static function (array $x, array $y) use ($rank, $def): int {
            foreach (array_keys($def['breakdown']) as $i) {
                $cmp = $rank($i, $x['keys'][$i]) <=> $rank($i, $y['keys'][$i]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        $truncated = max(0, count($groups) - self::MAX_ROWS);
        $groups = array_slice($groups, 0, self::MAX_ROWS);

        $rows = [];
        foreach ($groups as $g) {
            $row = [];
            foreach ($def['breakdown'] as $i => $dim) {
                $row['by'.($i + 1)] = self::label($dim, $g['keys'][$i], $labels[$i]);
            }
            foreach ($chosen as $key => $m) {
                $row[$key] = self::cast($g['values'][$key] ?? null, $m['format']);
            }
            $rows[] = $row;
        }

        return [$rows, $truncated];
    }

    /** @param  array<string, string>  $labels */
    private static function label(string $dim, ?string $key, array $labels): string
    {
        if ($key === null || $key === '') {
            return self::dimensions()[$dim]['empty'] ?? self::NOT_ON_RECORD;
        }
        if ($dim === 'month') {
            $at = \DateTimeImmutable::createFromFormat('!Y-m', $key);

            return $at === false ? $key : $at->format('F Y');
        }
        if ($dim === 'citizenship') {
            return ucwords($key);
        }
        if ($dim === 'kind' && ! isset($labels[$key])) {
            return ucfirst(str_replace('_', ' ', $key));
        }

        return $labels[$key] ?? $key;
    }

    /** @return array<string, string> "2026-09" => "September 2026", every month of the period */
    private static function months(array $period): array
    {
        $out = [];
        $cursor = CarbonImmutable::parse($period['from'])->startOfMonth();
        $last = substr((string) ($period['to'] ?? ManilaCalendar::today()->toDateString()), 0, 7);
        for ($guard = 0; $cursor->format('Y-m') <= $last && $guard < 600; $guard++, $cursor = $cursor->addMonth()) {
            $out[$cursor->format('Y-m')] = $cursor->format('F Y');
        }

        return $out;
    }

    private static function cast(mixed $value, string $format): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return match ($format) {
            'count' => (int) round((float) $value),
            default => round((float) $value, 2),
        };
    }

    /** A plain-words heading: "Businesses: Male employees, Female employees, by Barangay". */
    private static function title(array $def): string
    {
        $measures = self::measures($def['subject']);
        $names = array_map(static fn (string $k) => $measures[$k]['label'], $def['measures']);
        $by = array_map(static fn (string $d) => mb_strtolower(self::dimensions()[$d]['label']), $def['breakdown']);

        return self::SUBJECTS[$def['subject']]['label'].': '.implode(', ', $names)
            .($by === [] ? '' : ', by '.implode(' and ', $by));
    }

    /** @return list<string> "Barangay: Acacia, Baritan" — what the figures were narrowed to. */
    public static function describeFilters(array $def, ?array $scope): array
    {
        $out = [];
        foreach ($def['filters'] as $dim => $values) {
            $labels = self::options($dim, $scope);
            $names = array_map(static fn (string $v) => $labels[$v] ?? $v, $values);
            $out[] = self::dimensions()[$dim]['label'].': '.implode(', ', $names);
        }

        return $out;
    }

    /**
     * The notes printed under the table: what was counted, and every way the
     * rows might not add up to the Total.
     *
     * @param  array<string, array<string, mixed>>  $chosen
     * @return list<string>
     */
    private static function notes(array $def, ?array $scope, array $chosen): array
    {
        $notes = [match ($def['subject']) {
            'businesses' => 'Businesses on the register: not removed, with a filing past draft or a permit. Figures are what each business last declared.',
            'applications' => 'Filings past draft, not removed, of businesses still on the register'
                .($def['period'] === null ? '.' : ', dated by when they were submitted.'),
            'permits' => 'Every permit issued'.($def['period'] === null ? '' : ' in the period')
                .', including those later revoked, suspended or renewed, and permits from the old register.',
            default => 'Completed payments'.($def['period'] === null ? '' : ', dated by when they cleared').'.'
                .($scope === null ? '' : ' An office is credited with the fee lines billed in its name; a payment is spread over its fee lines in proportion.'),
        }];

        if (array_filter($chosen, static fn ($m) => ($m['sum'] ?? false) && $def['subject'] === 'businesses') !== []) {
            $notes[] = 'A business with no figure on record is left out of a sum; a dash means none in the row had one.';
        }

        $line = self::lineBreakdown($def);
        if ($line !== null) {
            $notes[] = 'A business with several lines of business is counted under each, so the rows can add up to more than the total. The total counts each once.';
            if (array_filter($chosen, static fn ($m) => $m['line'] ?? false) !== []) {
                $notes[] = 'Capitalization and gross sales are recorded per line, so those columns add up.';
            }
        } elseif ($def['breakdown'] !== [] && array_filter($chosen, static fn ($m) => $m['distinct'] ?? false) !== []) {
            $notes[] = 'A business can appear in more than one row; the total counts it once.';
        }

        if (in_array('filed_as', array_merge($def['breakdown'], array_keys($def['filters'])), true)) {
            $notes[] = '“Filed as” is the type of filing that produced the permit, not whether the business held one before.';
        }

        return $notes;
    }
}

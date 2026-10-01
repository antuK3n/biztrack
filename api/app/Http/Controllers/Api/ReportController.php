<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OfficeSignatory;
use App\Models\User;
use App\Support\AnalyticsOffice;
use App\Support\LguReports;
use App\Support\ManilaCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report Generation (checklist "Manage Approved Permits – Ken", item 7).
 *
 * Three doors onto App\Support\LguReports: the list, one report as JSON (which
 * the screen renders and prints), and the same report as CSV. The office is
 * decided exactly as on the dashboard — AnalyticsOffice::forRequest — so an
 * office account can neither read nor export another office's report by
 * editing the query string.
 *
 * Printing is the browser's: the screen lays the report out for A4 and calls
 * window.print(), which also gives "Save as PDF". A server-side PDF would be a
 * second rendering of the same figures to keep in step, which is the drift the
 * one-shape design in LguReports exists to prevent.
 */
class ReportController extends Controller
{
    /** The longest period one report may cover. */
    private const MAX_DAYS = 1096;

    public function index(Request $request): JsonResponse
    {
        $office = AnalyticsOffice::forRequest($request->user(), $request->query('office'));

        $reports = [];
        foreach (LguReports::REPORTS as $key => $report) {
            $reports[] = ['key' => $key, 'title' => $report['title'], 'summary' => $report['summary']];
        }

        return response()->json([
            'data' => $reports,
            'scope' => AnalyticsOffice::describe($request->user(), $office),
        ]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        return response()->json(['data' => $this->compile($request, $report)]);
    }

    /**
     * The report as a spreadsheet: a header block saying what, whose and when,
     * then each section as its own table with its total and note.
     *
     * Raw numbers, no thousands separators or peso signs, so the file sums in a
     * spreadsheet without cleaning. A figure with no value is an empty cell, not
     * a zero.
     */
    public function csv(Request $request, string $report): StreamedResponse
    {
        $compiled = $this->compile($request, $report);
        $office = $compiled['scope']['office'];
        $filename = sprintf(
            '%s%s-%s-to-%s.csv',
            $report,
            $office === null ? '' : '-'.strtolower($office),
            $compiled['period']['from'],
            $compiled['period']['to'],
        );

        return response()->streamDownload(function () use ($compiled) {
            $out = fopen('php://output', 'w');
            // A byte-order mark, so a spreadsheet opens "Mayor’s" and "—" as
            // written rather than as mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['City of Malabon']);
            fputcsv($out, [$compiled['title']]);
            fputcsv($out, ['Office', $compiled['scope']['office_name']]);
            fputcsv($out, ['Period', $compiled['period']['from'], $compiled['period']['to']]);
            fputcsv($out, ['Generated', $compiled['generated_at'], $compiled['prepared_by']['name']]);

            foreach ($compiled['sections'] as $section) {
                fputcsv($out, []);
                fputcsv($out, [$section['heading']]);
                fputcsv($out, array_column($section['columns'], 'label'));
                $keys = array_column($section['columns'], 'key');
                $formats = array_column($section['columns'], 'format', 'key');
                // Money always with two decimals (503000.00, not 503000 or
                // 55845.8), so a spreadsheet column reads as one kind of number.
                // No peso sign: it would make the column text.
                $cell = static fn (string $k, array $r) => ($formats[$k] ?? null) === 'money' && is_numeric($r[$k] ?? null)
                    ? number_format((float) $r[$k], 2, '.', '')
                    : ($r[$k] ?? '');
                foreach ($section['rows'] as $row) {
                    fputcsv($out, array_map(static fn (string $k) => $cell($k, $row), $keys));
                }
                if (is_array($section['total'])) {
                    fputcsv($out, array_map(static fn (string $k) => $cell($k, $section['total']), $keys));
                }
                if ($section['note'] !== null) {
                    fputcsv($out, [$section['note']]);
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function compile(Request $request, string $report): array
    {
        abort_unless(array_key_exists($report, LguReports::REPORTS), 404, 'There is no such report.');

        /** @var User $user */
        $user = $request->user();
        $office = AnalyticsOffice::forRequest($user, $request->query('office'));
        [$from, $to] = $this->period($request);

        $compiled = LguReports::build($report, $from, $to, AnalyticsOffice::scope($office));

        return $compiled + [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'scope' => AnalyticsOffice::describe($user, $office),
            // Manila time with its offset, to the second: a clerk reading the
            // file sees when it was made in their own day, not UTC with
            // microseconds.
            'generated_at' => ManilaCalendar::local(CarbonImmutable::now())->format('Y-m-d\\TH:i:sP'),
            'prepared_by' => [
                'name' => (string) $user->name,
                'position' => (string) ($user->roles()->value('display_name') ?? ''),
            ],
            'noted_by' => $this->notedBy($office),
        ];
    }

    /**
     * The "Noted by" line: the office's most senior current signatory, as the
     * administrators have entered it in Office Signatories — never a name
     * written here (names on LGU forms are admin-edited data). Null when the
     * office has none on record, and for an all-office report, which no single
     * office head signs; the screen then prints a blank line to sign on.
     *
     * @return array{name: string, position: string}|null
     */
    private function notedBy(?string $office): ?array
    {
        if ($office === null) {
            return null;
        }

        $departmentId = DB::table('departments')->where('code', $office)->value('id');
        $signatory = OfficeSignatory::query()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->orderByDesc('sort_order')
            ->first();

        return $signatory === null ? null : ['name' => $signatory->name, 'position' => $signatory->role];
    }

    /**
     * The reporting period. Defaults to the current month to date, which is the
     * report an office is most often asked for. Dates only, inclusive at both
     * ends, and MANILA dates: "today" is the City's today, not the UTC one,
     * which is still yesterday until 8 am in Malabon. LguReports turns the two
     * dates into the instants that bound them (ManilaCalendar::period).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        // Held as a bare date, like the parsed query values below, so the two
        // compare and subtract as dates whatever zone either came from.
        $today = CarbonImmutable::parse(ManilaCalendar::today()->toDateString());

        $validated = Validator::make($request->query(), [
            // `string` first: `?from[]=…` reached date_format as an array and
            // answered 500 instead of saying what was wrong.
            'from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'to' => ['nullable', 'string', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'The end date has to be on or after the start date.',
        ])->validate();

        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : $today->startOfMonth();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : $today;

        abort_if($to->lessThan($from), 422, 'The end date has to be on or after the start date.');
        abort_if(
            $from->diffInDays($to) > self::MAX_DAYS,
            422,
            'A report can cover up to three years. Choose a shorter period.',
        );

        return [$from, $to];
    }
}

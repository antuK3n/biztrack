<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedReport;
use App\Models\User;
use App\Support\AnalyticsOffice;
use App\Support\CustomReports;
use App\Support\ManilaCalendar;
use App\Support\PdfFile;
use App\Support\SimpleXlsx;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Custom Report tab beside Reports (client, 19 October 2026).
 *
 * Same permission as Reports (`analytics.view`, on the route group) and the
 * same office boundary: every request asks AnalyticsOffice::forRequest which
 * office it is answered for, exactly as ReportController does, so an office
 * account can neither run nor export another office's figures by editing the
 * query string. The definition itself is checked by CustomReports::validate.
 *
 * Saved set-ups belong to the reader who saved them (see the 2026_10_19_090000
 * migration): another reader's id answers 404, as if it did not exist.
 */
class CustomReportController extends Controller
{
    /** Saved set-ups one reader may keep. */
    private const MAX_SAVED = 50;

    public function catalogue(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $office = AnalyticsOffice::forRequest($user, $request->query('office'));

        return response()->json([
            'data' => CustomReports::catalogue(AnalyticsOffice::scope($office)),
            'scope' => AnalyticsOffice::describe($user, $office),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->compile($request)]);
    }

    /** The result as CSV, Excel or PDF — the same compiled figures as the screen. */
    public function export(Request $request, string $format): Response|StreamedResponse
    {
        $report = $this->compile($request);
        $filename = $this->filename($report, $format);

        return match ($format) {
            'csv' => $this->csv($report, $filename),
            'xlsx' => $this->xlsx($report, $filename),
            default => $this->pdf($report, $filename),
        };
    }

    /* ── saved set-ups ───────────────────────────────────────────────────── */

    public function savedIndex(Request $request): JsonResponse
    {
        return response()->json([
            'data' => SavedReport::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('name')
                ->get()
                ->map(fn (SavedReport $s) => $this->savedPayload($s))
                ->all(),
        ]);
    }

    public function savedStore(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $name = $this->validName($request, $user->id);
        $definition = CustomReports::validate($request->input('definition'), $this->scope($request));

        if (SavedReport::where('user_id', $user->id)->count() >= self::MAX_SAVED) {
            throw ValidationException::withMessages(['name' => ['You can keep up to '.self::MAX_SAVED.' saved reports.']]);
        }

        $saved = SavedReport::create(['user_id' => $user->id, 'name' => $name, 'definition' => $definition]);

        return response()->json(['data' => $this->savedPayload($saved)], 201);
    }

    public function savedUpdate(Request $request, int $savedReport): JsonResponse
    {
        $saved = $this->own($request, $savedReport);
        $changes = [];
        if ($request->has('name')) {
            $changes['name'] = $this->validName($request, $saved->user_id, $saved->id);
        }
        if ($request->has('definition')) {
            $changes['definition'] = CustomReports::validate($request->input('definition'), $this->scope($request));
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['name' => ['Nothing to change.']]);
        }
        $saved->update($changes);

        return response()->json(['data' => $this->savedPayload($saved)]);
    }

    public function savedDestroy(Request $request, int $savedReport): JsonResponse
    {
        $this->own($request, $savedReport)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /* ── internals ───────────────────────────────────────────────────────── */

    /** @return array{code: string, department_id: int, permit_type_ids: list<int>}|null */
    private function scope(Request $request): ?array
    {
        return AnalyticsOffice::scope(AnalyticsOffice::forRequest($request->user(), $request->query('office')));
    }

    /** @return array<string, mixed> */
    private function compile(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $office = AnalyticsOffice::forRequest($user, $request->query('office'));
        $scope = AnalyticsOffice::scope($office);

        $definition = CustomReports::validate($request->input('definition'), $scope);

        return CustomReports::run($definition, $scope) + [
            'scope' => AnalyticsOffice::describe($user, $office),
            'generated_at' => ManilaCalendar::local(CarbonImmutable::now())->format('Y-m-d\\TH:i:sP'),
        ];
    }

    private function validName(Request $request, int $userId, ?int $ignoreId = null): string
    {
        $name = $request->input('name');
        $name = is_string($name) ? trim(preg_replace('/\s+/', ' ', $name) ?? '') : $name;

        validator(['name' => $name], [
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('saved_reports', 'name')->where('user_id', $userId)->ignore($ignoreId),
            ],
        ], [
            'name.required' => 'Give the report a name.',
            'name.string' => 'Give the report a name.',
            'name.max' => 'Keep the name under 80 characters.',
            'name.unique' => 'You already have a report by that name.',
        ])->validate();

        return $name;
    }

    private function own(Request $request, int $id): SavedReport
    {
        $saved = SavedReport::where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_if($saved === null, 404, 'There is no such saved report.');

        return $saved;
    }

    /** @return array<string, mixed> */
    private function savedPayload(SavedReport $saved): array
    {
        return [
            'id' => $saved->id,
            'name' => $saved->name,
            'definition' => $saved->definition,
            'updated_at' => $saved->updated_at?->toIso8601String(),
        ];
    }

    private function filename(array $report, string $format): string
    {
        $office = $report['scope']['office'];

        return sprintf(
            'custom-report-%s%s-%s.%s',
            $report['subject'],
            $office === null ? '' : '-'.strtolower($office),
            substr((string) $report['generated_at'], 0, 10),
            $format,
        );
    }

    /**
     * The header block every export opens with: what, whose, which period,
     * which filters and when — so a forwarded file still says what it counts.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function header(array $report): array
    {
        $period = $report['period'];
        $lines = [
            ['Office', $report['scope']['office_name']],
            ['Period', $period === null ? 'All dates on record' : sprintf(
                '%s to %s',
                $period['from'] ?? 'the earliest on record',
                $period['to'] ?? 'today',
            )],
        ];
        foreach ($report['filters_text'] as $filter) {
            $lines[] = ['Only', $filter];
        }
        $lines[] = ['Generated', (string) $report['generated_at']];

        return $lines;
    }

    private function csv(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            // A byte-order mark, as ReportController writes, so a spreadsheet
            // opens "Owner’s" and "—" as written.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['City of Malabon']);
            fputcsv($out, [$report['title']]);
            foreach ($this->header($report) as $line) {
                fputcsv($out, array_map($this->csvText(...), $line));
            }
            fputcsv($out, []);

            $keys = array_column($report['columns'], 'key');
            $formats = array_column($report['columns'], 'format', 'key');
            fputcsv($out, array_map($this->csvText(...), array_column($report['columns'], 'label')));
            // Money with two decimals and no peso sign, as ReportController does,
            // so the column stays numbers. A figure with no value is empty.
            $cell = fn (string $k, array $row) => match (true) {
                ! array_key_exists($k, $row) || $row[$k] === null => '',
                ($formats[$k] ?? null) === 'money' => number_format((float) $row[$k], 2, '.', ''),
                is_string($row[$k]) => $this->csvText($row[$k]),
                default => $row[$k],
            };
            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(static fn (string $k) => $cell($k, $row), $keys));
            }
            fputcsv($out, array_map(static fn (string $k) => $cell($k, $report['total']), $keys));
            fputcsv($out, []);
            foreach ($this->footnotes($report) as $note) {
                fputcsv($out, [$note]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Text a spreadsheet will not run as a formula. Citizenship is typed by
     * owners, and a cell opening with = + - or @ is executed by Excel on open.
     */
    private function csvText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private function xlsx(array $report, string $filename): Response
    {
        $style = static fn (string $format, bool $bold) => match ($format) {
            'money' => $bold ? SimpleXlsx::MONEY_BOLD : SimpleXlsx::MONEY,
            'decimal' => $bold ? SimpleXlsx::DECIMAL_BOLD : SimpleXlsx::DECIMAL,
            'count' => $bold ? SimpleXlsx::COUNT_BOLD : SimpleXlsx::COUNT,
            default => $bold ? SimpleXlsx::BOLD : SimpleXlsx::PLAIN,
        };

        $rows = [
            [['City of Malabon', SimpleXlsx::BOLD]],
            [[$report['title'], SimpleXlsx::BOLD]],
        ];
        foreach ($this->header($report) as [$label, $value]) {
            $rows[] = [[$label], [$value]];
        }
        $rows[] = [];
        $rows[] = array_map(static fn (array $c) => [$c['label'], SimpleXlsx::BOLD], $report['columns']);
        foreach ([...$report['rows'], $report['total']] as $index => $row) {
            $bold = $index === count($report['rows']);
            $rows[] = array_map(
                static fn (array $c) => [$row[$c['key']] ?? null, $style($c['format'], $bold)],
                $report['columns'],
            );
        }
        $rows[] = [];
        foreach ($this->footnotes($report) as $note) {
            $rows[] = [[$note]];
        }

        $widths = array_map(static fn (array $c) => $c['format'] === 'text' ? 34 : max(14, mb_strlen($c['label']) + 2), $report['columns']);
        $bytes = SimpleXlsx::build('Custom report', $rows, $widths);

        return new Response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, $filename),
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    /** The same dompdf pipeline and letterhead as the dashboard summary PDF. */
    private function pdf(array $report, string $filename): Response
    {
        $pdf = Pdf::loadView('pdf.custom-report', [
            'report' => $report,
            'header' => $this->header($report),
            'notes' => $this->footnotes($report),
            'generated_at' => ManilaCalendar::local(CarbonImmutable::parse($report['generated_at']))->format('F j, Y g:i A').' Manila time',
        ])->setPaper('a4', count($report['columns']) > 5 ? 'landscape' : 'portrait');

        return PdfFile::render($pdf)->download($filename);
    }

    /** @return list<string> */
    private function footnotes(array $report): array
    {
        $notes = $report['notes'];
        if ($report['empty'] !== null) {
            array_unshift($notes, $report['empty']);
        }
        if ($report['truncated'] > 0) {
            array_unshift($notes, sprintf(
                'Showing the first %d rows; %d more are not listed. The total counts them all.',
                count($report['rows']),
                $report['truncated'],
            ));
        }

        return $notes;
    }
}

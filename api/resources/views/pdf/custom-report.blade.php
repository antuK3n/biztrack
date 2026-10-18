<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    {{--
        The Custom Report as a PDF (client, 19 October 2026). Same letterhead,
        type and table styling as pdf.analytics-dashboard-report, so the two
        documents an office prints from the analytics screens look like one
        family. The figures are the compiled report the screen shows
        (CustomReportController::compile), never recomputed here.

        Expects: $report (CustomReports::run + scope, generated_at),
        $header (label/value lines), $notes, $generated_at (formatted).
    --}}
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; margin: 0; padding: 36px; }
        .header { border-bottom: 3px solid #0025cc; padding-bottom: 10px; }
        .header .city { font-size: 18px; font-weight: bold; color: #0025cc; }
        .header .office { font-size: 11px; color: #444; }
        h1 { font-size: 14px; margin: 18px 0 6px; }
        .meta { font-size: 9.5px; color: #444; margin-bottom: 14px; }
        .meta td { padding: 1px 8px 1px 0; border: 0; font-size: 9.5px; }
        .meta td.label { color: #666; white-space: nowrap; }
        table.figures { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.figures th { background: #f0f2ff; text-align: left; padding: 5px 7px; font-size: 9.5px; border-bottom: 2px solid #0025cc; }
        table.figures td { padding: 4px 7px; font-size: 9.5px; border-bottom: 1px solid #eee; }
        td.num, th.num { text-align: right; }
        tr.total td { font-weight: bold; border-top: 1px solid #0025cc; }
        .empty { font-size: 10px; color: #666; font-style: italic; padding: 6px 0; }
        .note { font-size: 8.5px; color: #555; line-height: 1.5; margin: 2px 0; }
        .footer { margin-top: 22px; font-size: 8.5px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="city">CITY OF MALABON</div>
        <div class="office">{{ $report['scope']['office'] === null ? 'All offices' : $report['scope']['office_name'] }}</div>
    </div>

    <h1>{{ $report['title'] }}</h1>
    <table class="meta">
        @foreach ($header as $line)
            @continue($line[0] === 'Generated')
            <tr><td class="label">{{ $line[0] }}</td><td>{{ $line[1] }}</td></tr>
        @endforeach
    </table>

    @php
        // A dash, never a zero, where a figure has no value (AGENTS.md §6.4).
        $cell = static function ($value, string $format): string {
            if ($value === null || $value === '') {
                return $format === 'text' ? '' : '—';
            }
            if (is_string($value)) {
                return $value;
            }

            return match ($format) {
                'money' => number_format((float) $value, 2),
                'decimal' => number_format((float) $value, 2),
                default => number_format((float) $value),
            };
        };
    @endphp

    <table class="figures">
        <thead>
            <tr>
                @foreach ($report['columns'] as $column)
                    <th class="{{ $column['format'] === 'text' ? '' : 'num' }}">{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report['rows'] as $row)
                <tr>
                    @foreach ($report['columns'] as $column)
                        <td class="{{ $column['format'] === 'text' ? '' : 'num' }}">{{ $cell($row[$column['key']] ?? null, $column['format']) }}</td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="total">
                @foreach ($report['columns'] as $column)
                    <td class="{{ $column['format'] === 'text' ? '' : 'num' }}">{{ $cell($report['total'][$column['key']] ?? null, $column['format']) }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>

    @foreach ($notes as $note)
        <div class="note">{{ $note }}</div>
    @endforeach

    <div class="footer">
        BizTrack &middot; Counted from the register on {{ $generated_at }}.
    </div>
</body>
</html>

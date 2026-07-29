<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; margin: 0; padding: 36px; }
        .header { border-bottom: 3px solid #0025cc; padding-bottom: 10px; }
        .header .city { font-size: 18px; font-weight: bold; color: #0025cc; }
        .header .office { font-size: 11px; color: #444; }
        h1 { font-size: 15px; margin: 18px 0 4px; letter-spacing: 1px; }
        .meta { font-size: 10px; color: #666; margin-bottom: 14px; }
        .note { font-size: 9.5px; color: #555; line-height: 1.5; background: #f4f6ff; padding: 8px 10px; margin-bottom: 16px; }
        h2 { font-size: 12px; margin: 16px 0 6px; color: #0025cc; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #f0f2ff; text-align: left; padding: 5px 7px; font-size: 9.5px; border-bottom: 2px solid #0025cc; }
        td { padding: 5px 7px; font-size: 10px; border-bottom: 1px solid #eee; }
        td.num { text-align: right; }
        .headline td { border-bottom: none; padding: 3px 7px; }
        .headline td.label { color: #666; width: 34%; }
        .empty { font-size: 10px; color: #666; font-style: italic; padding: 6px 0; }
        .why { font-size: 9px; color: #666; }
        .footer { margin-top: 22px; font-size: 8.5px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="city">CITY OF MALABON</div>
        <div class="office">Business Permits and Licensing Office</div>
    </div>

    <h1>RENEWAL RISK</h1>
    <div class="meta">
        @include('pdf.partials.computed-by') &middot;
        Permits expiring on or before {{ $report['window_end'] }} &middot;
        Includes permits that lapsed since {{ $report['window_start'] }}
    </div>

    @include('pdf.partials.local-notice')

    {{--
        The methodology sentence travels with the numbers on purpose. A table of
        scores with no statement of what a score is would read as a prediction,
        which is exactly what this is not.
    --}}
    <div class="note">
        {{ $report['methodology'] }}
        Scores of {{ $report['thresholds']['high'] }} and above are High risk,
        {{ $report['thresholds']['moderate'] }} to {{ $report['thresholds']['high'] - 1 }} Moderate,
        below {{ $report['thresholds']['moderate'] }} Low.
    </div>

    <h2>Headline figures</h2>
    <table class="headline">
        <tr><td class="label">High risk</td><td>{{ number_format($report['counts']['high']) }} permits</td></tr>
        <tr><td class="label">Moderate risk</td><td>{{ number_format($report['counts']['moderate']) }} permits</td></tr>
        <tr><td class="label">Low risk</td><td>{{ number_format($report['counts']['low']) }} permits</td></tr>
        <tr>
            <td class="label">Reminders sent</td>
            <td>
                {{ number_format($report['reminders_sent']) }}
                @if ($report['reminders_sent'] === 0)
                    &mdash; no expiry reminder has been recorded for these permits yet
                @endif
            </td>
        </tr>
        <tr><td class="label">Permits scored</td><td>{{ number_format($report['scored_permits']) }}</td></tr>
    </table>

    <h2>How the score is built</h2>
    <table>
        <thead><tr><th>Signal</th><th class="num">Max points</th><th>What it measures</th></tr></thead>
        <tbody>
            @foreach ($report['rulebook'] as $rule)
                <tr>
                    <td>{{ $rule['label'] }}</td>
                    <td class="num">{{ $rule['max'] }}</td>
                    <td class="why">{{ $rule['description'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Businesses at risk</h2>
    @if (empty($report['at_risk']))
        <p class="empty">No permit expires in this window, so there is nothing to rank.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Business</th><th>Barangay</th>
                    <th class="num">Risk score</th><th>Band</th>
                    <th>Expires</th><th>Recommended action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['at_risk'] as $row)
                    <tr>
                        <td>
                            {{ $row['business'] }}
                            <div class="why">
                                {{ $row['permit_number'] }}
                                @if (! empty($row['drivers']))
                                    &middot;
                                    {{-- Escaped output, so the separator has to be a real character. --}}
                                    {{ implode(' · ', array_map(fn ($d) => $d['detail'], $row['drivers'])) }}
                                @endif
                            </div>
                        </td>
                        <td>{{ $row['barangay'] ?? 'Not on record' }}</td>
                        <td class="num">{{ $row['score'] }} / 100</td>
                        <td>{{ $row['band_label'] }}</td>
                        <td>{{ $row['valid_until'] }}</td>
                        <td>{{ $row['action_label'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Recommended actions</h2>
    <table>
        <thead><tr><th>Action</th><th class="num">Permits</th></tr></thead>
        <tbody>
            @foreach ($report['actions'] as $row)
                <tr><td>{{ $row['label'] }}</td><td class="num">{{ number_format($row['count']) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">BizTrack &middot; computed from the live permit register</div>
</body>
</html>

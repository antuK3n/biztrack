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
        .flag { color: #bd0000; font-weight: bold; }
        .summary td { border-bottom: none; padding: 2px 7px; }
        .summary td.label { color: #666; width: 34%; }
        .empty { font-size: 10px; color: #666; font-style: italic; padding: 6px 0; }
        .footer { margin-top: 22px; font-size: 8.5px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="city">CITY OF MALABON</div>
        <div class="office">Business Permits and Licensing Office</div>
    </div>

    <h1>PERMIT PROCESSING TIME MONITORING</h1>
    <div class="meta">
        @include('pdf.partials.computed-by') &middot;
        Window: {{ $report['window_weeks'] }} weeks from {{ $report['window_start'] }} &middot;
        {{ $report['completed_reviews'] }} completed reviews
    </div>

    @include('pdf.partials.local-notice')

    <div class="note">
        Weekly mean review turnaround per office, on an individuals control chart. A week counts only
        when at least {{ $report['min_completions_per_week'] }} reviews were completed in it. Control
        limits are fitted on the first {{ $report['calibration_weeks_cap'] }} weeks of the window, so a
        recent slowdown cannot widen the limits meant to catch it. A week is out of control when its
        mean falls outside those limits, or when the weighted trend (EWMA, lambda 0.2) drifts past its
        own limit. That second rule is what catches a run of small increases that never breaches
        a control limit on its own.
    </div>

    @forelse ($report['departments'] as $department)
        <h2>{{ $department["code"] }} &middot; {{ $department["name"] }}</h2>
        <table class="summary">
            <tr>
                <td class="label">Process status</td>
                <td><strong>{{ $department['status'] === 'outside' ? 'Outside control limits' : 'Inside control limits' }}</strong></td>
                <td class="label">Weighted trend</td>
                <td><strong>{{ ucfirst($department['trend']['direction']) }}</strong>
                    ({{ sprintf('%+.2f', $department['trend']['deviation_days']) }} days)</td>
            </tr>
            <tr>
                <td class="label">Centre line</td>
                <td>{{ number_format($department['center'], 2) }} days</td>
                <td class="label">Normal operating range</td>
                <td>{{ number_format($department['lcl'], 2) }} to {{ number_format($department['ucl'], 2) }} days</td>
            </tr>
            <tr>
                <td class="label">Weeks charted</td>
                <td>{{ count($department['points']) }} (calibrated on {{ $department['calibration_weeks'] }})</td>
                <td class="label">Completed reviews</td>
                <td>{{ $department['completed_reviews'] }}</td>
            </tr>
        </table>

        <table>
            <thead>
                <tr>
                    <th>Week of</th>
                    <th class="num">Reviews</th>
                    <th class="num">Mean days</th>
                    <th class="num">Deviation</th>
                    <th class="num">Weighted trend</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($department['points'] as $point)
                    <tr>
                        <td>{{ $point['week_start'] }}</td>
                        <td class="num">{{ $point['reviews'] }}</td>
                        <td class="num">{{ number_format($point['mean_days'], 2) }}</td>
                        <td class="num">{{ sprintf('%+.2f', $point['deviation_days']) }}</td>
                        <td class="num">{{ number_format($point['ewma'], 2) }}</td>
                        <td class="{{ $point['status'] === 'out_of_control' ? 'flag' : '' }}">
                            {{ $point['status'] === 'out_of_control' ? 'Outside ('.$point['rule_hit'].')' : 'In range' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="empty">
            No office reached {{ $report['min_completions_per_week'] }} completed reviews in any single
            week of this window, so no control chart can be fitted yet.
        </p>
    @endforelse

    @if (! empty($report['thin']))
        <h2>Offices without enough history</h2>
        <table>
            <thead>
                <tr><th>Office</th><th class="num">Completed reviews</th><th>Why it is not charted</th></tr>
            </thead>
            <tbody>
                @foreach ($report['thin'] as $row)
                    <tr>
                        <td>{{ $row["code"] }} &middot; {{ $row["name"] }}</td>
                        <td class="num">{{ $row['completed_reviews'] }}</td>
                        <td>{{ $row['reason'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">BizTrack &middot; computed from the live permit register</div>
</body>
</html>

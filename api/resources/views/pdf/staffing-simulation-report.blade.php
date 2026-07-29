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
        .headline td.label { color: #666; width: 40%; }
        .empty { font-size: 10px; color: #666; font-style: italic; padding: 6px 0; }
        .caveat { font-size: 9px; color: #666; line-height: 1.5; margin: 4px 0 0; }
        .footer { margin-top: 22px; font-size: 8.5px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="city">CITY OF MALABON</div>
        <div class="office">Business Permits and Licensing Office</div>
    </div>

    <h1>STAFFING SIMULATION</h1>
    <div class="meta">
        Generated {{ $generated_at }} &middot;
        Service times fitted from {{ $report['window_start'] }} onward ({{ $report['window_months'] }} months)
    </div>

    @if (! $report['data_sufficient'])
        <div class="note">{{ $report['reason'] }}</div>
        <p class="empty">Nothing to simulate yet.</p>
    @else
        <div class="note">
            A discrete-event simulation of the permit pipeline, run
            {{ $report['reps'] }} times over a {{ (int) ($report['horizon_days'] / 30) }}-month horizon on a
            fixed random seed, so re-running the same scenario returns the same numbers. Arrival rate,
            complexity mix, reviewer headcount and per-office service times all come from the register.
            The figures are a model of the pipeline, not a measurement of it: they answer "how would the
            queue respond", not "what will happen".
        </div>

        <h2>The question</h2>
        <table class="headline">
            <tr>
                <td class="label">Change tested</td>
                <td>
                    @if ($report['change']['added_reviewers'] === 0)
                        No staffing change &mdash; both columns are today's headcount
                    @else
                        +{{ $report['change']['added_reviewers'] }}
                        reviewer{{ $report['change']['added_reviewers'] === 1 ? '' : 's' }}
                        at {{ $report['change']['office_name'] }}
                    @endif
                </td>
            </tr>
            <tr>
                <td class="label">Demand</td>
                <td>
                    {{ $report['change']['demand_percent'] }}% of observed
                    ({{ number_format($report['change']['arrivals_per_day'], 3) }} filings per day;
                    observed {{ number_format($report['observed']['arrivals_per_day'], 3) }})
                </td>
            </tr>
            <tr>
                <td class="label">Observed history</td>
                <td>
                    {{ number_format($report['observed']['submissions']) }} filings submitted,
                    {{ number_format($report['observed']['completed_reviews']) }} reviews and
                    {{ number_format($report['observed']['completed_inspections']) }} inspections completed
                </td>
            </tr>
            <tr>
                <td class="label">Complexity mix</td>
                <td>
                    {{ number_format($report['observed']['complex_share'] * 100, 1) }}% new filings (full pipeline),
                    the rest renewals and amendments
                </td>
            </tr>
        </table>

        <h2>Outcome</h2>
        <table>
            <thead>
                <tr><th>Measure</th><th class="num">Today's staffing</th><th class="num">With the change</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>RA 11032 on-time rate</td>
                    <td class="num">{{ $report['baseline']['on_time_rate'] === null ? 'n/a' : number_format($report['baseline']['on_time_rate'], 1).'%' }}</td>
                    <td class="num">{{ $report['scenario']['on_time_rate'] === null ? 'n/a' : number_format($report['scenario']['on_time_rate'], 1).'%' }}</td>
                </tr>
                <tr>
                    <td>Mean end-to-end days</td>
                    <td class="num">{{ $report['baseline']['mean_flow_days'] ?? 'n/a' }}</td>
                    <td class="num">{{ $report['scenario']['mean_flow_days'] ?? 'n/a' }}</td>
                </tr>
                <tr>
                    <td>90th percentile days</td>
                    <td class="num">{{ $report['baseline']['p90_flow_days'] ?? 'n/a' }}</td>
                    <td class="num">{{ $report['scenario']['p90_flow_days'] ?? 'n/a' }}</td>
                </tr>
                <tr>
                    <td>Backlog at horizon</td>
                    <td class="num">{{ number_format($report['baseline']['backlog'], 1) }}</td>
                    <td class="num">{{ number_format($report['scenario']['backlog'], 1) }}</td>
                </tr>
            </tbody>
        </table>

        <h2>Per office</h2>
        <table>
            <thead>
                <tr>
                    <th>Office</th><th class="num">Reviewers</th>
                    <th class="num">Wait now</th><th class="num">Wait after</th>
                    <th class="num">Queue now</th><th class="num">Queue after</th>
                    <th class="num">Utilisation now</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['offices'] as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td class="num">
                            {{ $row['reviewers'] }}@if ($row['reviewers_after'] !== $row['reviewers']) &rarr; {{ $row['reviewers_after'] }}@endif
                        </td>
                        <td class="num">{{ number_format($row['baseline']['wait_days'], 2) }}d</td>
                        <td class="num">{{ number_format($row['scenario']['wait_days'], 2) }}d</td>
                        <td class="num">{{ number_format($row['baseline']['queue_length'], 2) }}</td>
                        <td class="num">{{ number_format($row['scenario']['queue_length'], 2) }}</td>
                        <td class="num">{{ number_format($row['baseline']['utilisation'] * 100, 1) }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>Fitted service times</h2>
        <table>
            <thead>
                <tr>
                    <th>Office</th><th>Stage</th>
                    <th class="num">Mean days</th><th class="num">Median days</th>
                    <th class="num">Observations</th><th>Source</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['fits'] as $fit)
                    <tr>
                        <td>{{ $fit['name'] }}</td>
                        <td>{{ ucfirst($fit['kind']) }}</td>
                        <td class="num">{{ number_format($fit['mean_days'], 2) }}</td>
                        <td class="num">{{ number_format($fit['median_days'], 2) }}</td>
                        <td class="num">{{ $fit['observations'] }}</td>
                        <td>{{ $fit['source'] === 'pooled' ? 'Pooled (own history too thin)' : 'Fitted' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (! empty($report['notes']))
            <h2>Caveats</h2>
            @foreach ($report['notes'] as $note)
                <p class="caveat">&middot; {{ $note }}</p>
            @endforeach
        @endif
    @endif

    <div class="footer">BizTrack &middot; discrete-event simulation over the live permit register</div>
</body>
</html>

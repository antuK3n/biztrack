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
        .footer { margin-top: 22px; font-size: 8.5px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="city">CITY OF MALABON</div>
        <div class="office">Business Permits and Licensing Office</div>
    </div>

    <h1>BUSINESS LIFECYCLE MONITORING</h1>
    <div class="meta">
        @include('pdf.partials.computed-by') &middot;
        Period: {{ $report['period_start'] }} to {{ $report['period_end'] }}
        ({{ $report['period_months'] }} months) &middot;
        Compared against {{ $report['prior_period_start'] }} to {{ $report['period_start'] }}
    </div>

    @include('pdf.partials.local-notice')

    <div class="note">
        {{-- "Business status", not "Lifecycle status". Spec §4 calls this row the
             Business Status Summary, and the printed report must not reintroduce
             the word the rest of this rename just removed. --}}
        Every figure is computed from the register. Business status is derived from permits: Active
        means a permit valid today, Expired means every permit has lapsed, Inactive means registered
        but never issued one, and Closed means the registration was removed. A closure is dated by
        when the registration was removed, which is the only closure date the schema records. Where a
        period has nothing to compare against, the rate is left blank rather than filled in.
    </div>

    <h2>Headline figures</h2>
    <table class="headline">
        <tr>
            <td class="label">Business growth rate</td>
            <td>
                @if ($report['growth_rate'] === null)
                    Not available: no registrations in the prior period to compare against
                @else
                    {{ sprintf('%+.1f%%', $report['growth_rate']) }}
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">New registrations</td>
            <td>{{ $report['registrations'] }} this period, {{ $report['registrations_prior'] }} in the prior period</td>
        </tr>
        <tr>
            <td class="label">Cohort survival rate</td>
            <td>
                @if ($report['cohort_survival']['survival'] === null)
                    Not available: no business has reached a first renewal yet
                @else
                    {{ number_format($report['cohort_survival']['survival'], 1) }}%
                    still renewing through {{ $report['cohort_survival']['max_cycle'] }}
                    renewal {{ \Illuminate\Support\Str::plural('cycle', $report['cohort_survival']['max_cycle']) }}
                    ({{ $report['cohort_survival']['lapses'] }} lapsed of
                    {{ $report['cohort_survival']['businesses'] }} businesses followed)
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Closures in period</td>
            <td>{{ $report['closures'] }}</td>
        </tr>
        <tr>
            <td class="label">Top growing barangay</td>
            <td>
                @if (empty($report['top_barangays']))
                    Not available: no registrations with a barangay on record
                @else
                    {{ $report['top_barangays'][0]['barangay'] }}
                    ({{ sprintf('%+d', $report['top_barangays'][0]['delta']) }})
                @endif
            </td>
        </tr>
    </table>

    <h2>Business renewal performance — cohort survival</h2>
    @if (empty($report['cohort_survival']['points']))
        <div class="empty">
            No business on record has reached a first renewal, so there is no cohort to have survived
            anything. This is not a 0% survival rate — there is nothing yet to measure.
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Renewal cycle</th>
                    <th class="num">Reached it</th>
                    <th class="num">Lapsed</th>
                    <th class="num">Still renewing</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['cohort_survival']['points'] as $point)
                    <tr>
                        <td>Cycle {{ $point['cycle'] }}</td>
                        <td class="num">{{ number_format($point['at_risk']) }}</td>
                        <td class="num">{{ number_format($point['lapses']) }}</td>
                        <td class="num">{{ $point['survival'] === null ? '—' : number_format($point['survival'], 1).'%' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (! empty($report['cohort_survival']['cohorts']))
            <table>
                <thead>
                    <tr>
                        <th>First held a permit</th>
                        <th class="num">Businesses</th>
                        <th class="num">Lapsed</th>
                        <th class="num">Cycles observed</th>
                        <th class="num">Still renewing</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['cohort_survival']['cohorts'] as $cohort)
                        <tr>
                            <td>{{ $cohort['cohort'] }}</td>
                            <td class="num">{{ number_format($cohort['businesses']) }}</td>
                            <td class="num">{{ number_format($cohort['lapses']) }}</td>
                            <td class="num">{{ $cohort['max_cycle'] }}</td>
                            <td class="num">
                                @if ($cohort['survival'] === null)
                                    no renewal reached yet
                                @else
                                    {{ number_format($cohort['survival'], 1) }}%
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="empty">
            {{ $report['cohort_survival']['methodology'] }}
            A business counts as lapsed when its permit ran out more than
            {{ $report['cohort_survival']['grace_days'] }} days ago with no successor, or when a
            renewal left a gap in cover.
        </div>
    @endif

    <h2>Business lifecycle status</h2>
    <table>
        <thead><tr><th>Status</th><th class="num">Count</th><th class="num">Share</th></tr></thead>
        <tbody>
            @foreach ($report['status_summary'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="num">{{ $row['share'] === null ? '—' : number_format($row['share'], 1).'%' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Top growing barangays</h2>
    @if (empty($report['top_barangays']))
        <p class="empty">No registrations with a barangay on record in this period.</p>
    @else
        <table>
            <thead>
                <tr><th>Barangay</th><th class="num">This period</th><th class="num">Prior</th><th class="num">Change</th></tr>
            </thead>
            <tbody>
                @foreach ($report['top_barangays'] as $row)
                    <tr>
                        <td>{{ $row['barangay'] }}</td>
                        <td class="num">{{ $row['registrations'] }}</td>
                        <td class="num">{{ $row['prior'] }}</td>
                        <td class="num">{{ sprintf('%+d', $row['delta']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Business closure trend</h2>
    <table>
        <thead><tr><th>Month</th><th class="num">Closures</th></tr></thead>
        <tbody>
            @foreach ($report['closure_trend'] as $row)
                <tr><td>{{ $row['month'] }}</td><td class="num">{{ $row['closures'] }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <h2>Business industry growth trend</h2>
    @if (empty($report['industry_growth']))
        <p class="empty">No lines of business on record.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Line of business</th><th>PSIC</th>
                    <th class="num">Businesses</th><th class="num">New</th>
                    <th class="num">Prior</th><th>Direction</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['industry_growth'] as $row)
                    <tr>
                        <td>{{ $row['industry'] }}</td>
                        <td>{{ $row['psic_code'] }}</td>
                        <td class="num">{{ $row['count'] }}</td>
                        <td class="num">{{ $row['registrations'] }}</td>
                        <td class="num">{{ $row['prior'] }}</td>
                        <td>{{ ucfirst($row['direction']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">BizTrack &middot; computed from the live permit register</div>
</body>
</html>

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
        .window { font-size: 8.5px; color: #888; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #f0f2ff; text-align: left; padding: 5px 7px; font-size: 9.5px; border-bottom: 2px solid #0025cc; }
        td { padding: 5px 7px; font-size: 10px; border-bottom: 1px solid #eee; }
        td.num, th.num { text-align: right; }
        tr.total td { font-weight: bold; border-top: 1px solid #0025cc; }
        .breach { color: #8a4b00; font-weight: bold; }
        .unavailable { color: #666; font-style: italic; }
        .empty { font-size: 10px; color: #666; font-style: italic; padding: 6px 0; }
        .footer { margin-top: 22px; font-size: 8.5px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="city">CITY OF MALABON</div>
        <div class="office">Business Permits and Licensing Office</div>
    </div>

    <h1>ANALYTICS DASHBOARD</h1>
    <div class="meta">
        @include('pdf.partials.computed-by') &middot;
        As of {{ $report['today'] }} &middot;
        Trailing window: {{ $report['window_start'] }} to {{ $report['today'] }}
        ({{ $report['window_months'] }} months)
    </div>

    @include('pdf.partials.local-notice')

    <div class="note">
        Every figure is computed from the register. Panels use different windows and each says which:
        application volume and decision outcomes cover the current month, the processing-time,
        time-in-stage, compliance, inspection and officer figures cover the trailing window, and the
        rankings and permit-expiry counts are as of the date above. Where a figure cannot be derived it
        is left blank with the reason stated, never filled in with a zero.
    </div>

    <h2>Headline figures</h2>
    <table>
        <tr>
            <th>Active businesses</th>
            <th>Applications YTD</th>
            <th>This month</th>
            <th>Compliance rate</th>
        </tr>
        <tr>
            <td class="num">{{ number_format($report['kpis']['active_businesses']) }}</td>
            <td class="num">{{ number_format($report['kpis']['applications_ytd']) }}</td>
            <td class="num">{{ number_format($report['kpis']['applications_this_month']) }}</td>
            <td class="num">
                @if ($report['kpis']['compliance_rate'] === null)
                    <span class="unavailable">n/a</span>
                @else
                    {{ number_format($report['kpis']['compliance_rate'], 1) }}%
                @endif
            </td>
        </tr>
    </table>

    <h2>Application volume <span class="window">(this month)</span></h2>
    <table>
        @foreach ($report['volume']['rows'] as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ number_format($row['count']) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td>Total</td>
            <td class="num">{{ number_format($report['volume']['total']) }}</td>
        </tr>
    </table>

    <h2>Decision outcomes <span class="window">(this month)</span></h2>
    <table>
        @foreach ($report['decisions']['rows'] as $row)
            @continue($row['outcome'] === 'cancelled' && $row['count'] === 0)
            <tr>
                <td>{{ $row['label'] }}@unless ($row['decisioned']) <span class="window">— excluded from the rate</span>@endunless</td>
                <td class="num">{{ number_format($row['count']) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td>Approval rate</td>
            <td class="num">
                @if ($report['decisions']['approval_rate'] === null)
                    <span class="unavailable">nothing decided yet</span>
                @else
                    {{ number_format($report['decisions']['approval_rate'], 1) }}%
                @endif
            </td>
        </tr>
    </table>
    @if ($report['decisions']['approval_rate'] !== null)
        <div class="empty">
            {{ number_format($report['decisions']['approved']) }} approved of
            {{ number_format($report['decisions']['decisioned']) }} decided. Pending filings are
            excluded from the denominator.
        </div>
    @endif

    <h2>Average processing time by RA 11032 tier <span class="window">(trailing window, working days)</span></h2>
    <table>
        <tr>
            <th>Tier</th>
            <th class="num">Statutory limit</th>
            <th class="num">Mean (working)</th>
            <th class="num">Mean (calendar)</th>
            <th class="num">Decided</th>
            <th>Against the limit</th>
        </tr>
        @foreach ($report['processing_tiers'] as $tier)
            <tr>
                <td>{{ $tier['label'] }}</td>
                <td class="num">{{ $tier['statutory_working_days'] }} days</td>
                @if ($tier['mean_working_days'] === null)
                    <td class="num unavailable">—</td>
                    <td class="num unavailable">—</td>
                    <td class="num">0</td>
                    <td class="unavailable">No filing on record in this window</td>
                @else
                    <td class="num @if ($tier['breaching']) breach @endif">{{ number_format($tier['mean_working_days'], 1) }}</td>
                    <td class="num">{{ number_format($tier['mean_calendar_days'], 1) }}</td>
                    <td class="num">{{ number_format($tier['observations']) }}</td>
                    <td @if ($tier['breaching']) class="breach" @endif>
                        @if ($tier['breaching'])
                            OVER THE STATUTORY LIMIT by {{ number_format($tier['overage_days'], 1) }} working days
                        @else
                            Within the limit by {{ number_format(abs($tier['overage_days']), 1) }} working days
                        @endif
                    </td>
                @endif
            </tr>
        @endforeach
    </table>
    <div class="empty">
        Republic Act 11032 sets these limits in working days, so the means are measured in working days;
        weekends are excluded and public holidays are not modelled. Tier comes from each application's
        recorded complexity.
    </div>

    <h2>Average time-in-stage by department <span class="window">(trailing window)</span></h2>
    @if (count($report['stages']['rows']) === 0)
        <div class="empty">No review assignment was completed in this window.</div>
    @else
        <table>
            <tr>
                <th>Department</th>
                <th class="num">Reviews</th>
                <th class="num">Mean days</th>
            </tr>
            @foreach ($report['stages']['rows'] as $row)
                <tr>
                    <td>{{ $row['name'] }} ({{ $row['code'] }})</td>
                    <td class="num">{{ number_format($row['reviews']) }}</td>
                    <td class="num">{{ number_format($row['mean_days'], 1) }}</td>
                </tr>
            @endforeach
        </table>
        @if ($report['stages']['bottleneck'])
            <div class="empty">
                {{ $report['stages']['bottleneck']['name'] }} is the slowest stage at
                {{ number_format($report['stages']['bottleneck']['mean_days'], 1) }} days per review@if ($report['stages']['bottleneck']['above_average_days'] > 0), {{ number_format($report['stages']['bottleneck']['above_average_days'], 1) }} days above the {{ number_format($report['stages']['mean_days'], 1) }}-day all-office average@endif,
                handling {{ number_format($report['stages']['bottleneck']['share_of_reviews'], 1) }}% of
                {{ number_format($report['stages']['reviews']) }} completed reviews.
            </div>
        @endif
    @endif

    <h2>Compliance monitoring <span class="window">(trailing window)</span></h2>
    <table>
        <tr>
            <th>Indicator</th>
            <th class="num">Rate</th>
            <th>Basis</th>
        </tr>
        @foreach ($report['compliance'] as $indicator)
            <tr>
                <td>{{ $indicator['label'] }}</td>
                <td class="num">
                    @if ($indicator['rate'] === null)
                        <span class="unavailable">n/a</span>
                    @else
                        {{ number_format($indicator['rate'], 1) }}%
                    @endif
                </td>
                <td>
                    @if ($indicator['rate'] === null)
                        <span class="unavailable">{{ $indicator['unavailable_reason'] ?? 'Nothing in the denominator for this window.' }}</span>
                    @else
                        {{ number_format($indicator['numerator']) }} of
                        {{ number_format($indicator['denominator']) }}
                        {{ $indicator['denominator_label'] }} {{ $indicator['numerator_label'] }}
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    <h2>Permits approaching expiry <span class="window">(as of {{ $report['today'] }}; 30/60/90 are cumulative)</span></h2>
    <table>
        <tr>
            <th>Window</th>
            @foreach ($report['expiry']['columns'] as $column)
                <th class="num">{{ $column['code'] }}</th>
            @endforeach
            <th class="num">Total</th>
        </tr>
        @foreach ($report['expiry']['rows'] as $row)
            <tr @if ($row['expired']) class="total" @endif>
                <td>{{ $row['label'] }}</td>
                @foreach ($report['expiry']['columns'] as $column)
                    <td class="num">{{ number_format($row['counts'][$column['code']] ?? 0) }}</td>
                @endforeach
                <td class="num">{{ number_format($row['total']) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Top barangays <span class="window">(active businesses, as of {{ $report['today'] }})</span></h2>
    @if (count($report['top_barangays']['rows']) === 0)
        <div class="empty">No active business has a barangay address on record.</div>
    @else
        <table>
            <tr><th>#</th><th>Barangay</th><th class="num">Businesses</th><th class="num">Share</th></tr>
            @foreach ($report['top_barangays']['rows'] as $row)
                <tr>
                    <td>{{ $row['rank'] }}</td>
                    <td>{{ $row['barangay'] }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="num">{{ $row['share'] === null ? '—' : number_format($row['share'], 1).'%' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Top lines of business <span class="window">(active businesses, by PSIC code)</span></h2>
    @if (count($report['top_lines_of_business']['rows']) === 0)
        <div class="empty">No active business has a line of business on record.</div>
    @else
        <table>
            <tr><th>#</th><th>Line of business</th><th>PSIC</th><th class="num">Businesses</th><th class="num">Share</th></tr>
            @foreach ($report['top_lines_of_business']['rows'] as $row)
                <tr>
                    <td>{{ $row['rank'] }}</td>
                    <td>{{ $row['industry'] }}</td>
                    <td>{{ $row['psic_code'] }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="num">{{ $row['share'] === null ? '—' : number_format($row['share'], 1).'%' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Form of organization</h2>
    @if ($report['organization_forms']['recorded'] === 0)
        <div class="empty">
            None of the {{ number_format($report['organization_forms']['total']) }} registered businesses
            has a form of organization on file, so this breakdown has nothing to count. The figure is not
            inferred from anything else in the register.
        </div>
    @else
        <table>
            <tr><th>Form</th><th class="num">Businesses</th><th class="num">Share of recorded</th></tr>
            @foreach ($report['organization_forms']['rows'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ number_format($row['count']) }}</td>
                    <td class="num">{{ $row['share'] === null ? '—' : number_format($row['share'], 1).'%' }}</td>
                </tr>
            @endforeach
        </table>
        @if ($report['organization_forms']['unrecorded'] > 0)
            <div class="empty">
                {{ number_format($report['organization_forms']['unrecorded']) }} of
                {{ number_format($report['organization_forms']['total']) }} businesses have no form on file
                and are excluded from the shares.
            </div>
        @endif
    @endif

    <h2>Inspections <span class="window">(trailing window; pass rate is passed ÷ completed)</span></h2>
    <table>
        <tr>
            <th>Type</th>
            <th class="num">Scheduled</th>
            <th class="num">Completed</th>
            <th class="num">Passed</th>
            <th class="num">Failed</th>
            <th class="num">Conditional</th>
            <th class="num">Pass rate</th>
        </tr>
        @foreach (array_merge($report['inspections']['rows'], [$report['inspections']['combined']]) as $row)
            <tr @if ($row['type'] === 'combined') class="total" @endif>
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ number_format($row['scheduled']) }}</td>
                <td class="num">{{ number_format($row['completed']) }}</td>
                <td class="num">{{ number_format($row['passed']) }}</td>
                <td class="num">{{ number_format($row['failed']) }}</td>
                <td class="num">{{ number_format($row['conditional']) }}</td>
                <td class="num">
                    @if ($row['pass_rate'] === null)
                        <span class="unavailable">none done</span>
                    @else
                        {{ number_format($row['pass_rate'], 1) }}%
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
    <div class="empty">
        Inspection type comes from the inspecting office, because the inspection-type field is not
        populated on any record.
    </div>

    <h2>Officer activity <span class="window">(trailing window)</span></h2>
    <table>
        <tr>
            <td>Average response time</td>
            <td>
                @if ($report['officer_activity']['mean_response_hours'] === null)
                    <span class="unavailable">No applicant message has been answered in this window</span>
                @else
                    {{ number_format($report['officer_activity']['mean_response_hours'], 1) }} hours
                    over {{ number_format($report['officer_activity']['responses']) }} replies
                    (median {{ number_format($report['officer_activity']['median_response_hours'], 1) }})
                @endif
            </td>
        </tr>
        <tr>
            <td>Fulfilled requests</td>
            <td>
                @if ($report['officer_activity']['requests_total'] === 0)
                    <span class="unavailable">No officer request was raised in this window</span>
                @else
                    {{ number_format($report['officer_activity']['requests_fulfilled']) }} of
                    {{ number_format($report['officer_activity']['requests_total']) }}
                    ({{ number_format($report['officer_activity']['requests_fulfilled_rate'], 1) }}%)
                @endif
            </td>
        </tr>
        {{--
            Two rows, where the client's paper asks for three. The third was
            "meeting participation", and it is gone from this report for the same
            reason it is gone from the dashboard: BizTrack has no meetings
            feature, so the figure reported nothing anyone had done. A printed
            report is the worst place to leave one — it gets filed and quoted
            months later by a reader who cannot ask what it was counting. See
            OfficerPanel in web/src/pages/admin/AnalyticsPage.tsx and the note on
            DashboardAnalytics::officerActivityFacts(). Restore alongside the
            card, if a meetings feature is ever built.
        --}}
    </table>

    <h2>GIS mapping</h2>
    <div class="empty">
        @if ($report['map']['plotted'] === 0)
            No business has coordinates on record, so there is nothing to plot.
        @else
            {{ number_format($report['map']['plotted']) }} of
            {{ number_format($report['map']['total_businesses']) }} registered businesses carry
            coordinates on their business-location address and are plotted on the on-screen map.
        @endif
    </div>
    @if (count($report['map']['by_barangay']) > 0)
        <table>
            <tr><th>Barangay</th><th class="num">Mapped</th><th class="num">With a valid permit</th><th class="num">Share</th></tr>
            @foreach (array_slice($report['map']['by_barangay'], 0, 10) as $row)
                <tr>
                    <td>{{ $row['barangay'] }}</td>
                    <td class="num">{{ number_format($row['businesses']) }}</td>
                    <td class="num">{{ number_format($row['active']) }}</td>
                    <td class="num">{{ $row['share'] === null ? '—' : number_format($row['share'], 1).'%' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        BizTrack &middot; Generated {{ $generated_at }} &middot; Figures are as fresh as the last
        analytics refresh, not as of this download.
    </div>
</body>
</html>

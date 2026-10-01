<?php

use App\Models\User;
use App\Support\AnalyticsOffice;
use App\Support\DashboardAnalytics;
use Carbon\Carbon;
use Smalot\PdfParser\Parser;

/*
 * The older "Dashboard summary (PDF)" on the Reports screen
 * (AnalyticsController::dashboardReport, pdf/analytics-dashboard-report).
 *
 * Three faults a reviewer found on paper: Blade printed its own `@if … @endif`
 * into the bottleneck sentence, the letterhead said "Business Permits and
 * Licensing Office" on every office's copy, and "Computed" carried a UTC clock
 * time with no zone, eight hours behind the office that filed it.
 */

afterEach(fn () => Carbon::setTestNow());

function dashboardPdfText(string $email, string $query = ''): string
{
    $bytes = test()->withHeaders(authAs($email))
        ->get('/api/v1/analytics/dashboard/report'.$query)
        ->assertOk()
        ->getContent();

    return (new Parser)->parseContent($bytes)->getText();
}

/** The view, rendered to HTML with a bottleneck that sits above the average. */
function dashboardPdfHtml(?string $office): string
{
    $report = DashboardAnalytics::build(DashboardAnalytics::DEFAULT_WINDOW_MONTHS, $office);
    $report['stages']['rows'] = [['code' => 'BFP', 'name' => 'Bureau of Fire Protection', 'reviews' => 12, 'mean_days' => 3.1]];
    $report['stages']['reviews'] = 60;
    $report['stages']['mean_days'] = 2.7;
    $report['stages']['bottleneck'] = [
        'code' => 'BFP',
        'name' => 'Bureau of Fire Protection',
        'mean_days' => 3.1,
        'reviews' => 12,
        'above_average_days' => 0.4,
        'share_of_reviews' => 20.0,
    ];

    $admin = User::where('email', 'admin@biztrack.local')->firstOrFail();

    return view('pdf.analytics-dashboard-report', [
        'report' => $report,
        'meta' => ['engine' => 'BizTrack', 'source' => 'snapshot'],
        'scope' => AnalyticsOffice::describe($admin, $office),
        'generated_at' => 'October 1, 2026 9:16 PM Manila time',
    ])->render();
}

it('prints the bottleneck sentence, not the Blade that builds it', function () {
    $html = dashboardPdfHtml(null);

    expect($html)->not->toContain('@if')
        ->and($html)->not->toContain('@endif')
        ->and($html)->toContain('0.4 office days above the 2.7-office-day all-office average');
});

it('heads each office’s copy with that office, not BPLO', function () {
    $text = dashboardPdfText('sanitary@biztrack.local');

    expect($text)->toContain('City Health Office')
        ->and($text)->not->toContain('Business Permits and Licensing Office');

    // The all-office copy is headed "All offices". BPLO may still appear
    // further down, as one of the departments in the tables.
    expect(dashboardPdfText('admin@biztrack.local', '?office=all'))
        ->toMatch('/^CITY OF MALABON\s+All offices\s+ANALYTICS DASHBOARD/');
});

it('says when the figures were computed in Manila time, and says that it is Manila time', function () {
    // 13:16 UTC is 9:16 PM in Manila. The old copy printed "1:16 PM", no zone.
    Carbon::setTestNow(Carbon::parse('2026-10-01 13:16:00', 'UTC'));

    $text = dashboardPdfText('bplo@biztrack.local');

    expect($text)->toContain('Computed October 1, 2026 9:16 PM Manila time')
        ->and($text)->not->toContain('1:16 PM');
});

it('leaves the inspections table off the copy of an office that does not inspect', function () {
    // BPLO issues the Mayor's Permit on the strength of the clearances, not a
    // visit of its own, so its copy has no inspecting office to report.
    expect(dashboardPdfText('bplo@biztrack.local'))->not->toContain('Pass rate');
    expect(dashboardPdfText('sanitary@biztrack.local'))->toContain('Pass rate');
});

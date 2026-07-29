<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Enums\PermitStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Payment;
use App\Models\Permit;
use App\Support\BusinessGrowthAnalytics;
use App\Support\PdfFile;
use App\Support\ProcessingTimeAnalytics;
use App\Support\RenewalRiskAnalytics;
use App\Support\Spc;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dashboard aggregates. Plain SQL/Eloquent only (guardrail: no R/Python) —
 * including Features 6 and 7, which used to live in the standalone r/ project.
 * The statistics are ported into App\Support\Spc and computed here from the
 * live register; nothing shells out to Rscript or calls the plumber API.
 *
 * Every route in this controller sits behind `analytics.view`, which only the
 * super admin holds. That matters: these aggregates read every office's
 * assignments, so exposing them to an office reviewer would hand them a summary
 * of filings ApplicationVisibility deliberately keeps out of their queue.
 */
class AnalyticsController extends Controller
{
    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->buildSummary()]);
    }

    /** Feature 7: per-department control charts over weekly review turnaround. */
    public function processingTime(Request $request): JsonResponse
    {
        return response()->json(['data' => ProcessingTimeAnalytics::build($this->weeks($request))]);
    }

    /** Printable Permit Processing Time Monitoring report. */
    public function processingTimeReport(Request $request): Response
    {
        $data = ProcessingTimeAnalytics::build($this->weeks($request));

        $pdf = Pdf::loadView('pdf.processing-time-report', [
            'report' => $data,
            'generated_at' => Carbon::parse($data['generated_at'])->format('F j, Y g:i A'),
        ])->setPaper('a4');

        // Render once: a second ->output() corrupts the font streams (see PdfFile).
        return PdfFile::render($pdf)->download('processing-time-monitoring.pdf');
    }

    /** Feature: business growth analysis over the register. */
    public function businessGrowth(Request $request): JsonResponse
    {
        return response()->json(['data' => BusinessGrowthAnalytics::build($this->months($request))]);
    }

    /** Printable Business Growth Analysis report. */
    public function businessGrowthReport(Request $request): Response
    {
        $data = BusinessGrowthAnalytics::build($this->months($request));

        $pdf = Pdf::loadView('pdf.business-growth-report', [
            'report' => $data,
            'generated_at' => Carbon::parse($data['generated_at'])->format('F j, Y g:i A'),
        ])->setPaper('a4');

        return PdfFile::render($pdf)->download('business-growth-analysis.pdf');
    }

    /**
     * Renewal Risk: permits near expiry, ranked by a weighted rule score.
     *
     * Not a prediction endpoint. See RenewalRiskScoring for what the number is
     * and, more importantly, what it is not.
     */
    public function renewalRisk(Request $request): JsonResponse
    {
        return response()->json([
            'data' => RenewalRiskAnalytics::build(
                $this->horizonDays($request),
                $this->limit($request),
            ),
        ]);
    }

    /** Printable Renewal Risk report. */
    public function renewalRiskReport(Request $request): Response
    {
        $data = RenewalRiskAnalytics::build($this->horizonDays($request), $this->limit($request));

        $pdf = Pdf::loadView('pdf.renewal-risk-report', [
            'report' => $data,
            'generated_at' => Carbon::parse($data['generated_at'])->format('F j, Y g:i A'),
        ])->setPaper('a4');

        return PdfFile::render($pdf)->download('renewal-risk.pdf');
    }

    /*
     * There is deliberately no staffing-simulation endpoint. App\Support\Des is
     * a complete, validated port of r/R/des.R, but docs/r-integration-spec.md
     * puts the discrete-event simulation out of scope for the delivered flow.
     * See the note in routes/workflow.php.
     */

    /** How far ahead the renewal watchlist looks, in days. */
    private function horizonDays(Request $request): int
    {
        $days = (int) $request->query('days', (string) RenewalRiskAnalytics::DEFAULT_HORIZON_DAYS);

        return max(7, min(365, $days));
    }

    /** Rows in the watchlist table. */
    private function limit(Request $request): int
    {
        $limit = (int) $request->query('limit', (string) RenewalRiskAnalytics::DEFAULT_LIMIT);

        return max(1, min(200, $limit));
    }

    /** Chart window in weeks, clamped so a stray query string cannot scan the table. */
    private function weeks(Request $request): int
    {
        $weeks = (int) $request->query('weeks', (string) ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS);

        return max(Spc::MIN_COMPLETIONS_PER_WEEK, min(104, $weeks));
    }

    /** Growth period in months, clamped to a sane range. */
    private function months(Request $request): int
    {
        $months = (int) $request->query('months', (string) BusinessGrowthAnalytics::DEFAULT_PERIOD_MONTHS);

        return max(1, min(36, $months));
    }

    /** CSV download of the summary (status counts, monthly, KPIs). */
    public function export(): StreamedResponse
    {
        $s = $this->buildSummary();

        return response()->streamDownload(function () use ($s) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Applications by status', '']);
            foreach ($s['applications_by_status'] as $status => $count) {
                fputcsv($out, [$status, $count]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Applications by type', '']);
            foreach ($s['applications_by_type'] as $type => $count) {
                fputcsv($out, [$type, $count]);
            }
            fputcsv($out, []);

            fputcsv($out, ['Month', 'Applications']);
            foreach ($s['applications_by_month'] as $row) {
                fputcsv($out, [$row['month'], $row['count']]);
            }
            fputcsv($out, []);

            fputcsv($out, ['KPI', 'Value']);
            fputcsv($out, ['Approval rate', $s['approval_rate']]);
            fputcsv($out, ['Avg processing days', $s['avg_processing_days']]);
            fputcsv($out, ['Active permits', $s['active_permits']]);
            fputcsv($out, ['Expiring permits', $s['expiring_permits']]);
            fputcsv($out, ['Simulated revenue', $s['simulated_revenue']]);

            fclose($out);
        }, 'analytics-summary.csv', ['Content-Type' => 'text/csv']);
    }

    private function buildSummary(): array
    {
        $byStatus = Application::select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status');

        $byType = Application::select('application_type', DB::raw('count(*) as c'))
            ->groupBy('application_type')->pluck('c', 'application_type');

        // Applications per month (last 12 months) — SQLite strftime.
        $byMonth = Application::select(
            DB::raw("strftime('%Y-%m', created_at) as month"),
            DB::raw('count(*) as count')
        )
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy('month')->orderBy('month')->get()
            ->map(fn ($r) => ['month' => $r->month, 'count' => (int) $r->count])->values();

        $decided = Application::whereIn('status', [
            ApplicationStatus::Approved->value,
            ApplicationStatus::Rejected->value,
        ])->count();
        $approved = Application::where('status', ApplicationStatus::Approved->value)->count();
        $approvalRate = $decided > 0 ? round($approved / $decided, 4) : 0;

        $avgProcessingDays = $this->avgProcessingDays();

        $activePermits = Permit::where('status', PermitStatus::Active->value)->count();

        $expiringPermits = Permit::where('status', PermitStatus::Active->value)
            ->whereDate('valid_until', '>=', now()->toDateString())
            ->whereDate('valid_until', '<=', now()->addDays(30)->toDateString())
            ->count();

        $simulatedRevenue = (float) Payment::where('status', PaymentStatus::Completed->value)->sum('amount');

        return [
            'applications_by_status' => $byStatus,
            'applications_by_type' => $byType,
            'applications_by_month' => $byMonth,
            'approval_rate' => $approvalRate,
            'avg_processing_days' => $avgProcessingDays,
            'active_permits' => $activePermits,
            'expiring_permits' => $expiringPermits,
            'simulated_revenue' => round($simulatedRevenue, 2),
        ];
    }

    /** Mean days from `submitted` to `approved` per application, from status history. */
    private function avgProcessingDays(): ?float
    {
        $submitted = ApplicationStatusHistory::where('to_status', ApplicationStatus::Submitted->value)
            ->select('application_id', DB::raw('min(created_at) as t'))
            ->groupBy('application_id')->pluck('t', 'application_id');

        $approved = ApplicationStatusHistory::where('to_status', ApplicationStatus::Approved->value)
            ->select('application_id', DB::raw('min(created_at) as t'))
            ->groupBy('application_id')->pluck('t', 'application_id');

        $spans = [];
        foreach ($approved as $appId => $approvedAt) {
            if (! isset($submitted[$appId])) {
                continue;
            }
            $spans[] = Carbon::parse($submitted[$appId])->diffInDays(Carbon::parse($approvedAt));
        }

        if (empty($spans)) {
            return null;
        }

        return round(array_sum($spans) / count($spans), 1);
    }
}

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
use App\Services\RAnalytics;
use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsRefresher;
use App\Support\AnalyticsResolver;
use App\Support\BusinessGrowthAnalytics;
use App\Support\DashboardAnalytics;
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
 * Dashboard aggregates and the R-computed analytics screens.
 *
 * Laravel owns all SQL; R is the statistics engine and stays a separate program
 * (docs/r-integration-spec.md). Nothing here calls R, though — the architecture
 * is batch. `analytics:refresh` pushes register rows to plumber, R computes, and
 * Laravel persists the result; these endpoints read that persisted result. So an
 * analytics page load costs one indexed read and cannot be slowed or broken by
 * the R service being slow or down.
 *
 * When no snapshot exists, the PHP port computes the figures instead and the
 * response says so in `meta`. Both engines emit the same schema, so the only way
 * a screen can tell them apart is that `meta`, which is exactly why every
 * analytics response carries it and every screen displays it. Serving fallback
 * numbers as R output would make the drift between two implementations
 * invisible.
 *
 * `meta.computed_at` is not decoration either. Figures are as fresh as the last
 * refresh, so a tester's brand-new application legitimately will not appear
 * until the next one — the timestamp on screen is what stops that reading as a
 * bug.
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

    /**
     * Screen 1: the Analytics Dashboard panels (spec §1).
     *
     * Every panel arrives on one payload deliberately. They share a clock — the
     * KPI cards, the volume table and the outcome table all have to reconcile to
     * the same month — and splitting them across endpoints would let a screen
     * render two different refreshes side by side and quietly fail to add up.
     */
    public function dashboard(Request $request): JsonResponse
    {
        return $this->serve(AnalyticsDatasets::DASHBOARD, ['months' => $this->windowMonths($request)]);
    }

    /** Printable Analytics Dashboard report. */
    public function dashboardReport(Request $request): Response
    {
        $resolved = $this->resolve(AnalyticsDatasets::DASHBOARD, ['months' => $this->windowMonths($request)]);

        $pdf = Pdf::loadView('pdf.analytics-dashboard-report', [
            'report' => $resolved['data'],
            'meta' => $resolved['meta'],
            'generated_at' => Carbon::parse($resolved['data']['generated_at'])->format('F j, Y g:i A'),
        ])->setPaper('a4');

        return PdfFile::render($pdf)->download('analytics-dashboard.pdf');
    }

    /** Feature 7: per-department control charts over weekly review turnaround. */
    public function processingTime(Request $request): JsonResponse
    {
        return $this->serve(AnalyticsDatasets::PROCESSING_TIME, ['weeks' => $this->weeks($request)]);
    }

    /** Printable Permit Processing Time Monitoring report. */
    public function processingTimeReport(Request $request): Response
    {
        $resolved = $this->resolve(AnalyticsDatasets::PROCESSING_TIME, ['weeks' => $this->weeks($request)]);

        $pdf = Pdf::loadView('pdf.processing-time-report', [
            'report' => $resolved['data'],
            'meta' => $resolved['meta'],
            'generated_at' => Carbon::parse($resolved['data']['generated_at'])->format('F j, Y g:i A'),
        ])->setPaper('a4');

        // Render once: a second ->output() corrupts the font streams (see PdfFile).
        return PdfFile::render($pdf)->download('processing-time-monitoring.pdf');
    }

    /** Feature: business growth analysis over the register. */
    public function businessGrowth(Request $request): JsonResponse
    {
        return $this->serve(AnalyticsDatasets::BUSINESS_GROWTH, ['months' => $this->months($request)]);
    }

    /** Printable Business Lifecycle Monitoring report. */
    public function businessGrowthReport(Request $request): Response
    {
        $resolved = $this->resolve(AnalyticsDatasets::BUSINESS_GROWTH, ['months' => $this->months($request)]);

        $pdf = Pdf::loadView('pdf.business-growth-report', [
            'report' => $resolved['data'],
            'meta' => $resolved['meta'],
            'generated_at' => Carbon::parse($resolved['data']['generated_at'])->format('F j, Y g:i A'),
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
        return $this->serve(AnalyticsDatasets::RENEWAL_RISK, [
            'days' => $this->horizonDays($request),
            'limit' => $this->limit($request),
        ]);
    }

    /**
     * Recompute every snapshot from R now, instead of waiting for 03:00.
     *
     * The screens are deliberately batch-fed — a page load reads a stored
     * snapshot and never calls R — so without this there is no way to see a
     * filing you just made reflected in the figures until the nightly run. That
     * is right for serving pages and wrong for a demo.
     *
     * Reports what happened per dataset rather than returning a bare 204. A
     * refresh can partly succeed: R may compute three datasets and fail the
     * fourth, leaving the screens showing a mix of fresh and stale figures, each
     * labelled with its own timestamp. The two failures worth telling apart are
     * "R is switched off" and "R did not answer", because the fix differs.
     */
    public function refresh(RAnalytics $r): JsonResponse
    {
        $outcome = AnalyticsRefresher::run($r);

        if ($outcome['disabled']) {
            return response()->json([
                'message' => 'R analytics is switched off, so there is nothing to refresh. The screens are computing locally.',
                'refreshed' => 0,
            ], 409);
        }

        if ($outcome['unreachable']) {
            return response()->json([
                'message' => 'The R statistics service did not answer. The screens keep serving the last figures and say how old they are.',
                'refreshed' => 0,
            ], 503);
        }

        /*
         * A run where every dataset failed is a 502 with the error envelope, so
         * the client's existing 4xx/5xx path surfaces `message` and the caller
         * does not have to inspect counts to notice nothing happened.
         */
        if ($outcome['succeeded'] === 0 && $outcome['failed'] > 0) {
            return response()->json([
                'message' => $this->refreshMessage($outcome),
                'errors' => [],
            ], 502);
        }

        // Success keeps the { data: ... } envelope every other endpoint uses.
        return response()->json([
            'data' => [
                'message' => $this->refreshMessage($outcome),
                'refreshed' => $outcome['succeeded'],
                'failed' => $outcome['failed'],
                'engine_version' => $outcome['engine_version'],
                'results' => $outcome['results'],
            ],
        ]);
    }

    /** @param  array{succeeded: int, failed: int, engine_version: string|null}  $outcome */
    private function refreshMessage(array $outcome): string
    {
        $engine = $outcome['engine_version'] !== null ? 'R '.$outcome['engine_version'] : 'R';

        if ($outcome['failed'] === 0) {
            return sprintf(
                '%d figure set%s recomputed by %s.',
                $outcome['succeeded'],
                $outcome['succeeded'] === 1 ? '' : 's',
                $engine,
            );
        }

        if ($outcome['succeeded'] === 0) {
            return sprintf('%s could not compute any figures. The screens keep the last ones.', $engine);
        }

        return sprintf(
            '%d recomputed by %s, %d failed. Those screens keep their previous figures.',
            $outcome['succeeded'],
            $engine,
            $outcome['failed'],
        );
    }

    /** Printable Renewal Risk report. */
    public function renewalRiskReport(Request $request): Response
    {
        $resolved = $this->resolve(AnalyticsDatasets::RENEWAL_RISK, [
            'days' => $this->horizonDays($request),
            'limit' => $this->limit($request),
        ]);

        $pdf = Pdf::loadView('pdf.renewal-risk-report', [
            'report' => $resolved['data'],
            'meta' => $resolved['meta'],
            'generated_at' => Carbon::parse($resolved['data']['generated_at'])->format('F j, Y g:i A'),
        ])->setPaper('a4');

        return PdfFile::render($pdf)->download('renewal-risk.pdf');
    }

    /**
     * Read a dataset's persisted statistics, or compute them locally.
     *
     * @param  array<string, int>  $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function resolve(string $dataset, array $params): array
    {
        $definition = AnalyticsDatasets::get($dataset);

        return AnalyticsResolver::resolve(
            $dataset,
            $params,
            static fn (): array => ($definition['local'])($params),
        );
    }

    /**
     * `meta` sits beside `data` rather than inside it so the payload R returns
     * stays exactly the payload R returned — the provenance of a figure is not
     * one of the figures.
     *
     * @param  array<string, int>  $params
     */
    private function serve(string $dataset, array $params): JsonResponse
    {
        $resolved = $this->resolve($dataset, $params);

        return response()->json([
            'data' => $resolved['data'],
            'meta' => $resolved['meta'],
        ]);
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

    /** Dashboard trailing window in months, clamped so a stray query cannot scan. */
    private function windowMonths(Request $request): int
    {
        $months = (int) $request->query('months', (string) DashboardAnalytics::DEFAULT_WINDOW_MONTHS);

        return max(1, min(36, $months));
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

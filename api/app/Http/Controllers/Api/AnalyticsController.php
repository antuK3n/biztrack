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
use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsDefinitions;
use App\Support\AnalyticsOffice;
use App\Support\AnalyticsRefresher;
use App\Support\AnalyticsResolver;
use App\Support\DashboardAnalytics;
use App\Support\PdfFile;
use App\Support\ProcessingTimeAnalytics;
use App\Support\Spc;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    /*
     * summary() and export() are register-wide counts with no office in them.
     * They sat on `analytics.view` when only BPLO held it; now that every office
     * admin holds it (checklist 2026-09-27, item 1) they are closed to anyone
     * who cannot read every office, rather than taught a second scoping rule.
     * No screen calls either today — the office-scoped CSVs are on the Reports
     * screen.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->requireEveryOffice($request);

        return response()->json(['data' => $this->buildSummary()]);
    }

    /**
     * The Analytics Dashboard, for one office or for all of them.
     *
     * The office is decided by AnalyticsOffice::forRequest() and nowhere else:
     * an office account gets its own office whatever it sends, and asking for
     * another is a 403. `scope` travels beside `data` and `meta` so the screen
     * can say whose figures these are and draw the office menu for the two
     * readers who have one.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $office = AnalyticsOffice::forRequest($request->user(), $request->query('office'));
        $resolved = $this->resolve(AnalyticsDatasets::DASHBOARD, $this->dashboardParams($request, $office));
        $resolved['meta']['definitions'] = AnalyticsDefinitions::forOffice($resolved['meta']['definitions'] ?? [], $office);

        return response()->json([
            'data' => $resolved['data'],
            'meta' => $resolved['meta'],
            'scope' => AnalyticsOffice::describe($request->user(), $office),
        ]);
    }

    public function dashboardReport(Request $request): Response
    {
        $office = AnalyticsOffice::forRequest($request->user(), $request->query('office'));
        $resolved = $this->resolve(AnalyticsDatasets::DASHBOARD, $this->dashboardParams($request, $office));

        $pdf = Pdf::loadView('pdf.analytics-dashboard-report', [
            'report' => $resolved['data'],
            'meta' => $resolved['meta'],
            'scope' => AnalyticsOffice::describe($request->user(), $office),
            'generated_at' => $this->printedTime($resolved['data']['generated_at']),
        ])->setPaper('a4');

        $suffix = $office === null ? '' : '-'.strtolower($office);

        return PdfFile::render($pdf)->download("analytics-dashboard{$suffix}.pdf");
    }

    /**
     * When the figures were computed, as a printed report states it: Manila
     * clock time, with the zone said in words.
     *
     * The app runs in UTC, and this used to format the UTC instant as it
     * stood, so a report computed at 9:16 in the evening in Malabon said
     * "1:16 PM" with nothing to tell the reader it was eight hours behind.
     * The zone is City Hall's (config/office_hours.php), the one every other
     * time on the office screens is read in.
     */
    private function printedTime(string $iso): string
    {
        $zone = (string) config('office_hours.timezone', 'Asia/Manila');

        return Carbon::parse($iso)->setTimezone($zone)->format('F j, Y g:i A')
            .($zone === 'Asia/Manila' ? ' Manila time' : ' ('.$zone.')');
    }

    /**
     * The snapshot key's parameters. The office is left OUT when it is every
     * office, so the whole-city key stays `dashboard:months=12` — the string the
     * snapshots stored before offices existed already carry.
     *
     * @return array<string, int|string>
     */
    private function dashboardParams(Request $request, ?string $office): array
    {
        $params = ['months' => $this->windowMonths($request)];
        if ($office !== null) {
            $params['office'] = $office;
        }

        return $params;
    }

    private function requireEveryOffice(Request $request): void
    {
        abort_unless(
            AnalyticsOffice::canSwitch($request->user()),
            403,
            'These figures cover every office, so only BPLO and the Super Administrator can read them.',
        );
    }

    public function processingTime(Request $request): JsonResponse
    {
        return $this->serve(AnalyticsDatasets::PROCESSING_TIME, ['weeks' => $this->weeks($request)]);
    }

    /**
     * Office Performance — the six offices on one set of axes (issue #102).
     *
     * Sits beside processingTime() on `analytics.processing_time` rather than on
     * `analytics.view`, and the reason is the one already written over the route
     * group: this screen measures the DEPARTMENTS, BPLO among them, so it
     * belongs to the office doing the oversight and not to one of the offices
     * being overseen. Putting it on `analytics.view` would hand BPLO a ranking
     * of its peers against itself.
     *
     * Nothing is spliced on at serve time here. Every figure the screen shows is
     * in the stored snapshot, which is what lets `computed_at` describe the
     * whole screen — including the test-data count, which is the one figure that
     * would have been tempting to read live. It must not be: a live count printed
     * over snapshot averages would tell the reader how much test data is in the
     * register NOW while the averages beside it carry however much was in it at
     * three in the morning, and nothing on the page would distinguish the two
     * vintages.
     */
    public function officePerformance(Request $request): JsonResponse
    {
        return $this->serve(AnalyticsDatasets::OFFICE_PERFORMANCE, ['weeks' => $this->weeks($request)]);
    }

    public function processingTimeReport(Request $request): Response
    {
        $resolved = $this->resolve(AnalyticsDatasets::PROCESSING_TIME, ['weeks' => $this->weeks($request)]);

        $pdf = Pdf::loadView('pdf.processing-time-report', [
            'report' => $resolved['data'],
            'meta' => $resolved['meta'],
            'generated_at' => $this->printedTime($resolved['data']['generated_at']),
        ])->setPaper('a4');

        // Render once: a second ->output() corrupts the font streams (see PdfFile).
        return PdfFile::render($pdf)->download('processing-time-monitoring.pdf');
    }

    /**
     * Recompute every snapshot now, instead of waiting for 03:00.
     *
     * The screens are deliberately batch-fed — a page load reads a stored
     * snapshot — so without this there is no way to see a filing you just made
     * reflected in the figures until the nightly run. That is right for serving
     * pages and wrong for a demo.
     *
     * ## What a failure means now that there is one engine
     *
     * This used to distinguish three outcomes, two of which were about R being a
     * separate process: "R is switched off" (409) and "R did not answer" (503).
     * R has been removed, so neither can happen and both are gone. There is no
     * service to be unreachable and no flag that can disable the statistics —
     * the builders are in this codebase and ship with it.
     *
     * What is left can only fail the way a query fails: a dataset throws while
     * being computed. That is still per snapshot rather than global — a dataset
     * that throws leaves its previous snapshot in place and costs the others
     * nothing — so a refresh can still partly succeed, and the response still
     * reports per dataset rather than returning a bare 204.
     */
    public function refresh(Request $request): JsonResponse
    {
        /*
         * BPLO and the super admin only: the two readers who see every office.
         * One press recomputes every office's figures, and its response names
         * every snapshot it rebuilt; an office that reads only its own figures
         * has no business doing either. Its screen shows no button.
         */
        abort_unless(
            AnalyticsOffice::canSwitch($request->user()),
            403,
            'Only BPLO and the super admin can recompute the figures. They are recomputed every night.',
        );

        $outcome = AnalyticsRefresher::run();

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

                // Kept, and kept null, for the reason AnalyticsResolver keeps
                // them on every response: the client reads this shape and the
                // engine is no longer a variable.
                'engine' => 'BizTrack',
                'engine_version' => null,

                'results' => $outcome['results'],
            ],
        ]);
    }

    /** @param  array{succeeded: int, failed: int}  $outcome */
    private function refreshMessage(array $outcome): string
    {
        if ($outcome['failed'] === 0) {
            return sprintf(
                '%d figure set%s recomputed.',
                $outcome['succeeded'],
                $outcome['succeeded'] === 1 ? '' : 's',
            );
        }

        if ($outcome['succeeded'] === 0) {
            return 'No figures could be computed. The screens keep the last ones.';
        }

        return sprintf(
            '%d figure set%s recomputed, %d failed. Those screens keep their previous figures.',
            $outcome['succeeded'],
            $outcome['succeeded'] === 1 ? '' : 's',
            $outcome['failed'],
        );
    }

    /**
     * Read a dataset's precomputed statistics, or compute them now.
     *
     * @param  array<string, int|string>  $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function resolve(string $dataset, array $params): array
    {
        $definition = AnalyticsDatasets::get($dataset);

        return AnalyticsResolver::resolve(
            $dataset,
            $params,
            static fn (): array => ($definition['build'])($params),
        );
    }

    /**
     * `meta` sits beside `data` rather than inside it so the computed payload
     * stays exactly the computed payload — the provenance of a figure is not one
     * of the figures, and a screen that spread them together would have to know
     * which keys were which.
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
     * a complete, validated discrete-event simulation, but the DES is out of
     * scope for the delivered flow — see the note in routes/workflow.php.
     */

    /** Chart window in weeks, clamped so a stray query string cannot scan the table. */
    private function weeks(Request $request): int
    {
        $weeks = (int) $request->query('weeks', (string) ProcessingTimeAnalytics::DEFAULT_WINDOW_WEEKS);

        return max(Spc::MIN_COMPLETIONS_PER_WEEK, min(104, $weeks));
    }

    /**
     * Dashboard trailing window in months: one of the windows the screen offers
     * and the nightly refresh precomputes (config analytics.variants.dashboard).
     * Any other number used to be clamped and computed on the spot, uncached,
     * on every request; now it is refused.
     */
    private function windowMonths(Request $request): int
    {
        $raw = $request->query('months', (string) DashboardAnalytics::DEFAULT_WINDOW_MONTHS);
        $offered = array_map(
            static fn (array $v): int => (int) $v['months'],
            AnalyticsDatasets::variants(AnalyticsDatasets::DASHBOARD),
        );

        abort_unless(
            is_string($raw) && ctype_digit($raw) && in_array((int) $raw, $offered, true),
            422,
            'Choose one of the windows the dashboard offers: '.implode(', ', $offered).' months.',
        );

        return (int) $raw;
    }

    /** CSV download of the summary (status counts, monthly, KPIs). */
    public function export(Request $request): StreamedResponse
    {
        $this->requireEveryOffice($request);

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
            fputcsv($out, ['Revenue', $s['simulated_revenue']]);

            fclose($out);
        }, 'analytics-summary.csv', ['Content-Type' => 'text/csv']);
    }

    private function buildSummary(): array
    {
        $byStatus = Application::select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status');

        $byType = Application::select('application_type', DB::raw('count(*) as c'))
            ->groupBy('application_type')->pluck('c', 'application_type');

        // Applications per month (last 12 months).
        $byMonth = Application::select(
            DB::raw($this->yearMonth('created_at').' as month'),
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

    /**
     * `YYYY-MM` of a timestamp column, in the SQL of whichever engine is
     * connected.
     *
     * This was `strftime('%Y-%m', …)` alone, which is SQLite's and nothing
     * else's: on PostgreSQL, the production database, the summary and its CSV
     * export answered 500 ("function strftime does not exist"). There is no
     * date-formatting function the two engines share, so the one line that
     * differs is chosen here. The column is a constant from this file, never
     * input.
     */
    private function yearMonth(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'mysql', 'mariadb' => "date_format({$column}, '%Y-%m')",
            default => "strftime('%Y-%m', {$column})",
        };
    }

    /** Mean days from `submitted` to `approved` per application, from status history. */
    private function avgProcessingDays(): ?float
    {
        /*
         * `for_approval` is where a submitted filing lands now, and `submitted`
         * is what it landed in before 6 September 2026. Both are matched
         * because this reads HISTORY: the older rows keep the name the system
         * gave them at the time, so looking only for the current name would
         * silently drop every filing made before the change out of the
         * processing-time average — and an average over a shrinking window
         * looks like an improvement.
         */
        $submitted = ApplicationStatusHistory::whereIn('to_status', [
            ApplicationStatus::ForApproval->value,
            'submitted',
        ])
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

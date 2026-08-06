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
use App\Models\PermitExpiryNotice;
use App\Services\NotificationService;
use App\Services\RAnalytics;
use App\Support\AnalyticsDatasets;
use App\Support\AnalyticsRefresher;
use App\Support\AnalyticsResolver;
use App\Support\Audit;
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

class AnalyticsController extends Controller
{
    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->buildSummary()]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->serve(AnalyticsDatasets::DASHBOARD, ['months' => $this->windowMonths($request)]);
    }

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

    public function processingTime(Request $request): JsonResponse
    {
        return $this->serve(AnalyticsDatasets::PROCESSING_TIME, ['weeks' => $this->weeks($request)]);
    }

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

    public function businessGrowth(Request $request): JsonResponse
    {
        return $this->serve(AnalyticsDatasets::BUSINESS_GROWTH, ['months' => $this->months($request)]);
    }

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
     *
     * ── Why the filters are query parameters and not browser work ────────────
     *
     * Same reasoning as the officer queue (see the long note in
     * web/src/pages/officer/QueuePage.tsx): a filter applied in the browser can
     * only narrow the rows that were already sent, so its totals describe the
     * page rather than the register. Here that failure has teeth — the payload
     * is the top `limit` rows BY SCORE, and on this register the leading 25 are
     * all High, so a browser-side "show me Low risk" would filter 25 High rows
     * down to nothing and report that the city has no low-risk businesses. It
     * has 2,060.
     *
     * ── Why a filtered request is computed locally ───────────────────────────
     *
     * The filters ride in the snapshot key, and `analytics:refresh` only
     * precomputes the unfiltered variants in config/analytics.php. So the
     * default screen keys to exactly the snapshot it always did and is still
     * served by R, and any filtered or paged request misses and falls to the
     * PHP engine, which says so through `meta.source` and `meta.notice`. That
     * is not a new behaviour to reason about: asking for more than 25 rows has
     * always dropped to the local engine for the same reason. Filtering has to
     * happen before the ranking is cut, R is only ever handed the whole
     * watchlist, and neither engine ends up with a second opinion about what a
     * filter means.
     *
     * The filters are deliberately absent from the key when they are unset,
     * rather than present as nulls — `renewal_risk:days=365,limit=25` has to
     * stay the string it is or the existing snapshots stop matching.
     */
    public function renewalRisk(Request $request): JsonResponse
    {
        $days = $this->horizonDays($request);
        $limit = $this->limit($request);
        $view = $this->renewalRiskView($request);

        $params = ['days' => $days, 'limit' => $limit];
        foreach (['barangay', 'band', 'action'] as $filter) {
            if ($view[$filter] !== null) {
                $params[$filter] = $view[$filter];
            }
        }
        if ($view['offset'] > 0) {
            $params['offset'] = $view['offset'];
        }

        $resolved = AnalyticsResolver::resolve(
            AnalyticsDatasets::RENEWAL_RISK,
            $params,
            static fn (): array => RenewalRiskAnalytics::build($days, $limit, $view),
        );

        return response()->json([
            'data' => $this->decorateRenewalRisk($resolved['data'], $days),
            'meta' => $resolved['meta'],
        ]);
    }

    /**
     * The filters and page offset a caller asked for, unvalidated at this layer.
     *
     * Clamped rather than rejected, in the same spirit as horizonDays(): a
     * stray query string should narrow nothing, not 422 a dashboard. What
     * stops that being a silent lie is that RenewalRiskAnalytics echoes back
     * the filters it actually applied, and the screen renders the echo.
     *
     * @return array{barangay: string|null, band: string|null, action: string|null, offset: int}
     */
    private function renewalRiskView(Request $request): array
    {
        $text = static function (?string $value): ?string {
            $value = trim((string) $value);

            return ($value === '' || $value === 'all') ? null : mb_substr($value, 0, 120);
        };

        $band = $text($request->query('band'));
        $action = $text($request->query('action'));

        return [
            'barangay' => $text($request->query('barangay')),
            'band' => in_array($band, RenewalRiskAnalytics::BANDS, true) ? $band : null,
            'action' => in_array($action, RenewalRiskAnalytics::ACTIONS, true) ? $action : null,
            // Bounded so a hand-typed offset cannot walk a scored register row
            // by row; the screen never sends one past `matching`.
            'offset' => max(0, min(100_000, (int) $request->query('offset', '0'))),
        ];
    }

    /**
     * Two things the statistics payload cannot carry, added at serve time.
     *
     *  - **The barangay menu.** A control's options are a register question,
     *    not a statistic, and they have to be there whichever engine answered.
     *    A snapshot computed by R has no idea what a filter is.
     *  - **Officer follow-ups per row.** These are live state — the whole point
     *    is that an officer sees a send they made a minute ago — and the
     *    snapshot is a nightly figure. Reading them off the payload would tell
     *    an officer they had not rung a business they rang this morning.
     *
     * The paging fields are defaulted rather than computed here: a payload with
     * no `filters` is by definition an unfiltered one, so `matching` is
     * `scored_permits` and the offset is zero. That is exactly the R-served
     * default screen, and it means the client has one shape to render instead
     * of two.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function decorateRenewalRisk(array $data, int $days): array
    {
        $data['filters'] ??= ['barangay' => null, 'band' => null, 'action' => null];
        $data['matching'] ??= (int) ($data['scored_permits'] ?? 0);
        $data['offset'] ??= 0;
        $data['barangays'] = RenewalRiskAnalytics::barangaysInScope($days);

        $rows = $data['at_risk'] ?? [];
        $manual = RenewalRiskAnalytics::manualRemindersByPermit(
            array_values(array_map(static fn (array $row): int => (int) $row['permit_id'], $rows)),
        );

        $data['at_risk'] = array_map(static function (array $row) use ($manual): array {
            $sent = $manual[(int) $row['permit_id']] ?? null;
            $row['manual_reminders'] = $sent['count'] ?? 0;
            $row['manual_reminder_at'] = $sent['last_at'] ?? null;

            return $row;
        }, $rows);

        return $data;
    }

    /**
     * Send one renewal follow-up to a business owner, now, from the watchlist.
     *
     * Keyed on the PERMIT rather than the business, which is not the obvious
     * choice and is the right one: a business commonly holds its business,
     * sanitary and fire permits at once, they expire on different dates, and
     * the watchlist has a row per permit. A business-keyed endpoint would have
     * to guess which permit the officer was looking at, and the message quotes
     * a permit number and an expiry date.
     *
     * ── The double-send guard ────────────────────────────────────────────────
     *
     * This puts a real message in a real business owner's notifications, so
     * "probably only once" is not good enough. The guard is the same one
     * `biztrack:scan-permits` runs on: an insert into `permit_expiry_notices`,
     * whose unique index on (permit_id, notice_kind) makes the row the
     * permission to send. Claim it and you send; fail to claim it and somebody
     * already did. The kind carries today's date
     * (RenewalRiskAnalytics::manualNoticeKind), so the grain is one follow-up
     * per permit per day — a double-click, a replayed request or a second
     * officer on the same row all resolve to one message, while next month's
     * chase is still possible.
     *
     * A repeat is a 200 rather than a 409. Nothing went wrong: the officer's
     * intent (this owner should have been told) is satisfied, and the answer
     * they need is "yes, and here is when" — which is what `already_sent` and
     * `sent_at` say.
     */
    public function remindRenewal(Permit $permit, NotificationService $notify): JsonResponse
    {
        $permit->loadMissing('business.owner');

        /*
         * Both models soft-delete, and neither absence is an error worth a 500.
         * A closed business has nobody to chase and an unclaimed one has no
         * inbox — the same two exclusions ScanPermits filters on, for the same
         * reason. Refused before the ledger is touched, because a ledger row is
         * a claim that a message went out.
         */
        $owner = $permit->business?->owner;
        if (! $owner) {
            return response()->json([
                'message' => $permit->business === null
                    ? 'That business has been removed from the register, so there is nobody to remind.'
                    : 'That business has no owner account yet, so there is no inbox to send a reminder to.',
                'errors' => [],
            ], 422);
        }

        /*
         * Only permits the watchlist would actually show, and the band is the
         * same answer — one pass, so the endpoint cannot refuse a permit the
         * screen lists or send the urgent wording to a row badged Moderate.
         * Without this the endpoint is a way to message any owner about any
         * permit they have ever held, including one that lapsed in 2024, which
         * is neither what the button on screen does nor something the officer
         * pressing it has been shown the facts for.
         *
         * Computed BEFORE the ledger row is claimed: a claimed row is a promise
         * that a message went out, so nothing may fail after it.
         */
        $band = RenewalRiskAnalytics::bandForPermit($permit->id);

        if ($band === null) {
            return response()->json([
                'message' => 'That permit is not on the renewal watchlist, so there is no renewal to follow up.',
                'errors' => [],
            ], 422);
        }

        /*
         * "Monitor" is not a message, so a Low-risk permit has no button and
         * this endpoint will not send one either.
         *
         * The spec lists three recommended actions and only two of them are
         * addressed to the applicant. "Send Reminder" and "Immediate
         * follow-up" both mean "tell this business something"; Monitor means
         * "an officer should keep an eye on this", which is advice to the
         * reader of the screen about their own attention. Manufacturing a
         * notification for it would put "we are monitoring you" into a
         * business owner's list on the strength of a permit that is 200 days
         * off with nothing at all against it — a message with no request in it,
         * sent to 2,060 people. The guard is here and not only in the UI
         * because a control that is absent from the screen must also be absent
         * from the API, or it is merely hidden.
         */
        if ($band === 'low') {
            return response()->json([
                'message' => 'This permit is low risk, and the recommended action is to monitor it rather than '
                    .'to contact the business. Nothing was sent.',
                'errors' => [],
            ], 422);
        }

        $kind = RenewalRiskAnalytics::manualNoticeKind();
        $notice = PermitExpiryNotice::firstOrCreate([
            'permit_id' => $permit->id,
            'notice_kind' => $kind,
        ]);

        if (! $notice->wasRecentlyCreated) {
            return response()->json(['data' => [
                'permit_id' => $permit->id,
                'already_sent' => true,
                'sent_at' => $notice->created_at?->toISOString(),
                'message' => 'A follow-up already went to this business today, so nothing was sent again.',
            ]]);
        }

        // The band decides the tone, and it came from the register above rather
        // than from the request: a caller can claim any urgency it likes.
        $urgent = $band === 'high';

        $notify->renewalFollowUp($permit, $urgent);

        /*
         * Recorded against the permit, with who and when coming from the audit
         * row itself. A message sent to a citizen on an officer's authority is
         * exactly the kind of act §5.2 exists for, and "the system sent it"
         * must not be an available answer to "who contacted this business".
         */
        Audit::log('permit.renewal_followup_sent', $permit, [
            'notice_kind' => $kind,
            'action' => $urgent ? 'immediate_follow_up' : 'send_reminder',
            'notified_user_id' => $owner->id,
        ]);

        return response()->json(['data' => [
            'permit_id' => $permit->id,
            'already_sent' => false,
            'sent_at' => $notice->created_at?->toISOString(),
            'message' => 'Reminder sent. It is in the business owner’s notifications now.',
        ]]);
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

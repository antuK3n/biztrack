<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Whose time a filing's time was, for the RA 11032 figures.
 *
 * The dashboard's tier panel and compliance card, and the Reports tab's
 * processing-time report, all measure how long the City took. Two things
 * made that measure wrong, and both are answered here so the screen and the
 * printed report cannot answer them differently.
 *
 * ── 1. An office was judged on the whole filing ─────────────────────────────
 *
 * Scoped to one office, the figure was still submission → final decision of
 * every filing routed to it: CHO's "average processing time" contained BPLO's
 * reading of the form, the applicant's payment, BFP's inspection and BPLO's
 * final approval. An office's view now times the office's OWN review:
 *
 *  - every office but BPLO: its assignment, from the filing reaching it
 *    (`assigned_at`) to the office finishing its review (`completed_at`), for
 *    the reviews it finished in the period;
 *  - BPLO: the time the filing sat at BPLO's desk, read from status history —
 *    For Approval (called Submitted before 6 September 2026) and For Final
 *    Approval. Not its assignment: BPLO's row is stamped a second time at the
 *    final approval, so its recorded span is the whole filing (the same reason
 *    OfficePerformanceAnalytics::NOT_COMPARABLE leaves BPLO's turnaround out).
 *
 * ── 2. The applicant's time was charged to the City ─────────────────────────
 *
 * While a filing is Pending Payment it waits on the applicant to pay; while
 * it is Returned it waits on them to fix and resubmit. RA 11032's clock is the
 * government's, so those stretches are taken out of every measure here, from
 * `application_status_history`, which records each move with its time. The
 * all-offices view keeps the whole filing — submission to decision — less
 * those stretches.
 *
 * What history cannot give: a single clearance sent back to the applicant by
 * its office (`returnClearance`) moves the permit, not the filing, and the
 * permit has no history table — `returned_at` is overwritten on every return.
 * That time stays inside the office's figure. A clearance the applicant has
 * not yet applied for is the same: the permit's own `submitted_at` is also
 * overwritten on every resubmission. Both are written down in
 * AnalyticsDefinitions so the figure does not claim more than it measures.
 *
 * ── Pending ──────────────────────────────────────────────────────────────────
 *
 * "Still pending at the end of the period" applies the same rules from the
 * other end: a filing rejected or cancelled by then is not pending, one
 * waiting on its applicant at that moment is not the City's backlog, and the
 * age of what is left leaves out the applicant's stretches.
 */
final class FilingClock
{
    /** The filing waits on its applicant: to pay, or to fix and resubmit. */
    public const WITH_APPLICANT = [
        ApplicationStatus::PendingPayment->value,
        ApplicationStatus::Returned->value,
    ];

    /**
     * Only BPLO can move a filing out of these. `submitted` is For Approval's
     * name before 6 September 2026; history keeps the name it had.
     */
    public const WITH_BPLO = [
        ApplicationStatus::ForApproval->value,
        'submitted',
        ApplicationStatus::ForFinalApproval->value,
    ];

    /** A filing in one of these has ended without a permit. */
    public const ENDED = [
        ApplicationStatus::Rejected->value,
        ApplicationStatus::Cancelled->value,
    ];

    /**
     * Statuses of the machine before 6 September 2026, when every office —
     * BPLO among them — reviewed a filing at once under one `under_review`.
     * A filing that went through them has no stretch that was BPLO's alone,
     * so BPLO's own time cannot be read off it and it is left out of BPLO's
     * figure rather than read as zero days. (On the register this was
     * written against, that is every seeded filing: their `submitted` →
     * `pending_payment` move was recorded in the same instant.)
     */
    public const BEFORE_SEPTEMBER_2026 = ['under_review', 'for_inspection'];

    /**
     * One row per decided filing (all offices, BPLO) or finished review (any
     * other office) in [from, to): its tier and how long the City — or the
     * office — took.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     * @return list<array{tier: string|null, working_days: int, calendar_days: float, end: CarbonImmutable, submitted_at: CarbonImmutable, deadline_at: CarbonImmutable|null}>
     */
    public static function decided(CarbonImmutable $from, CarbonImmutable $to, ?array $scope): array
    {
        if ($scope !== null && ! self::bplo($scope)) {
            return self::officeReviews($from, $to, $scope);
        }

        $filings = self::routedTo(DB::table('applications'), $scope)
            ->whereNull('applications.deleted_at')
            ->whereNotNull('applications.submitted_at')
            ->whereNotNull('applications.decided_at')
            ->where('applications.decided_at', '>=', $from)
            ->where('applications.decided_at', '<', $to);

        $segments = self::segments((clone $filings)->select('applications.id'));

        $out = [];
        foreach ($filings->orderBy('applications.id')->get([
            'applications.id', 'applications.complexity', 'applications.submitted_at',
            'applications.decided_at', 'applications.deadline_at',
        ]) as $row) {
            $start = self::instant($row->submitted_at);
            $end = self::instant($row->decided_at);
            $history = $segments[(int) $row->id] ?? [];

            if ($scope === null) {
                $away = self::stretches($history, self::WITH_APPLICANT);
                $workingDays = ManilaCalendar::workingDaysOutside($start, $end, $away);
                $seconds = self::seconds($start, $end) - self::secondsInside($start, $end, $away);
            } else {
                // BPLO: only the stretches at its own desk. A filing with no
                // history, or one handled under the old all-at-once machine,
                // cannot say where BPLO's time was, and is left out rather
                // than read as zero.
                if (! self::separatesBplo($history)) {
                    continue;
                }
                $desk = self::stretches($history, self::WITH_BPLO);
                $workingDays = self::workingDaysInside($start, $end, $desk);
                $seconds = self::secondsInside($start, $end, $desk);
            }

            $out[] = [
                'tier' => $row->complexity === null ? null : (string) $row->complexity,
                'working_days' => $workingDays,
                'calendar_days' => Rounding::statistic($seconds / 86400),
                'end' => $end,
                'submitted_at' => $start,
                'deadline_at' => $row->deadline_at === null ? null : self::instant($row->deadline_at),
            ];
        }

        return $out;
    }

    /**
     * What was still pending at the end of the period, with its age in
     * working days. $end is the first instant after the period; $asOf is the
     * moment the age is measured to (the period's last moment, or now — in
     * which case $current is true).
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope
     * @return list<array{application_type: string, age: int}>
     */
    public static function pending(CarbonImmutable $end, CarbonImmutable $asOf, ?array $scope, bool $current = false): array
    {
        if ($scope !== null && ! self::bplo($scope)) {
            $rows = DB::table('application_assignments')
                ->join('applications', 'applications.id', '=', 'application_assignments.application_id')
                ->whereNull('applications.deleted_at')
                ->where('application_assignments.department_id', $scope['department_id'])
                ->whereNotNull('application_assignments.assigned_at')
                ->where('application_assignments.assigned_at', '<', $end)
                ->where(static fn ($q) => $q->whereNull('application_assignments.completed_at')
                    ->orWhere('application_assignments.completed_at', '>=', $end));
            $since = 'application_assignments.assigned_at';
        } else {
            $rows = self::routedTo(DB::table('applications'), $scope)
                ->whereNull('applications.deleted_at')
                ->whereNotNull('applications.submitted_at')
                ->where('applications.submitted_at', '<', $end)
                ->where(static fn ($q) => $q->whereNull('applications.decided_at')
                    ->orWhere('applications.decided_at', '>=', $end));
            $since = 'applications.submitted_at';
        }

        $segments = self::segments((clone $rows)->select('applications.id'));

        $out = [];
        foreach ($rows->get(['applications.id', 'applications.status', 'applications.application_type', $since.' as since']) as $row) {
            $history = $segments[(int) $row->id] ?? [];
            // The status the filing stood at, at the moment the age is taken.
            // While the period is still running that is its status now — the
            // column is the authority, and an old row moved between machines
            // without a history entry would otherwise read as its last
            // recorded move. A past period has only history to go on, and a
            // filing with none has only its current status.
            $status = $current || $history === [] ? (string) $row->status : self::statusAt($history, $asOf);

            if ($status === null || in_array($status, self::ENDED, true) || in_array($status, self::WITH_APPLICANT, true)) {
                continue;
            }

            $start = self::instant($row->since);

            if ($scope !== null && self::bplo($scope)) {
                // BPLO's pending work is what sits at its desk right then.
                if (! in_array($status, self::WITH_BPLO, true)) {
                    continue;
                }
                $age = self::workingDaysInside($start, $asOf, self::stretches($history, self::WITH_BPLO));
            } else {
                $age = ManilaCalendar::workingDaysOutside($start, $asOf, self::stretches($history, self::WITH_APPLICANT));
            }

            $out[] = ['application_type' => (string) $row->application_type, 'age' => $age];
        }

        return $out;
    }

    /**
     * Can BPLO's own stretches be read off this history?
     *
     * @param  list<array{status: string, from: CarbonImmutable, to: CarbonImmutable|null}>  $history
     */
    private static function separatesBplo(array $history): bool
    {
        if ($history === []) {
            return false;
        }
        foreach ($history as $segment) {
            if (in_array($segment['status'], self::BEFORE_SEPTEMBER_2026, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is this the office whose recorded review time is not its own step?
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}  $scope
     */
    public static function bplo(array $scope): bool
    {
        return array_key_exists($scope['code'], OfficePerformanceAnalytics::NOT_COMPARABLE);
    }

    /**
     * An office's own finished reviews in [from, to), timed from reaching the
     * office to the office finishing, less the applicant's stretches.
     *
     * @param  array{code: string, department_id: int, permit_type_ids: list<int>}  $scope
     * @return list<array{tier: string|null, working_days: int, calendar_days: float, end: CarbonImmutable, submitted_at: CarbonImmutable, deadline_at: CarbonImmutable|null}>
     */
    private static function officeReviews(CarbonImmutable $from, CarbonImmutable $to, array $scope): array
    {
        $reviews = DB::table('application_assignments')
            ->join('applications', 'applications.id', '=', 'application_assignments.application_id')
            ->whereNull('applications.deleted_at')
            ->whereNotNull('applications.submitted_at')
            ->where('application_assignments.department_id', $scope['department_id'])
            ->whereNotNull('application_assignments.assigned_at')
            ->whereNotNull('application_assignments.completed_at')
            ->where('application_assignments.completed_at', '>=', $from)
            ->where('application_assignments.completed_at', '<', $to);

        $segments = self::segments((clone $reviews)->select('applications.id'));

        $out = [];
        foreach ($reviews->orderBy('application_assignments.id')->get([
            'applications.id', 'applications.complexity', 'applications.submitted_at', 'applications.deadline_at',
            'application_assignments.assigned_at', 'application_assignments.completed_at',
        ]) as $row) {
            $start = self::instant($row->assigned_at);
            $end = self::instant($row->completed_at);
            $away = self::stretches($segments[(int) $row->id] ?? [], self::WITH_APPLICANT);

            $out[] = [
                'tier' => $row->complexity === null ? null : (string) $row->complexity,
                'working_days' => ManilaCalendar::workingDaysOutside($start, $end, $away),
                'calendar_days' => Rounding::statistic(
                    (self::seconds($start, $end) - self::secondsInside($start, $end, $away)) / 86400,
                ),
                'end' => $end,
                'submitted_at' => self::instant($row->submitted_at),
                'deadline_at' => $row->deadline_at === null ? null : self::instant($row->deadline_at),
            ];
        }

        return $out;
    }

    /**
     * Each filing's status history as consecutive stretches: the status it
     * held, from when, until the next move (null: still holding it).
     *
     * @param  QueryBuilder  $applicationIds  a query selecting application ids
     * @return array<int, list<array{status: string, from: CarbonImmutable, to: CarbonImmutable|null}>>
     */
    private static function segments(QueryBuilder $applicationIds): array
    {
        $rows = DB::table('application_status_history')
            ->whereIn('application_id', $applicationIds)
            ->whereNotNull('created_at')
            ->orderBy('application_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['application_id', 'to_status', 'created_at']);

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->application_id;
            $at = self::instant($row->created_at);
            $last = array_key_last($out[$id] ?? []);
            if ($last !== null) {
                $out[$id][$last]['to'] = $at;
            }
            $out[$id][] = ['status' => (string) $row->to_status, 'from' => $at, 'to' => null];
        }

        return $out;
    }

    /**
     * The stretches spent in any of the given statuses.
     *
     * @param  list<array{status: string, from: CarbonImmutable, to: CarbonImmutable|null}>  $segments
     * @param  list<string>  $statuses
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>
     */
    private static function stretches(array $segments, array $statuses): array
    {
        $out = [];
        foreach ($segments as $segment) {
            if (in_array($segment['status'], $statuses, true)) {
                $out[] = [$segment['from'], $segment['to']];
            }
        }

        return $out;
    }

    /** @param  list<array{status: string, from: CarbonImmutable, to: CarbonImmutable|null}>  $segments */
    private static function statusAt(array $segments, CarbonImmutable $at): ?string
    {
        $status = null;
        foreach ($segments as $segment) {
            if ($segment['from'] > $at) {
                break;
            }
            $status = $segment['status'];
        }

        return $status;
    }

    /**
     * Working days inside the stretches, clipped to [from, to].
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $stretches
     */
    private static function workingDaysInside(CarbonImmutable $from, CarbonImmutable $to, array $stretches): int
    {
        $days = 0;
        foreach ($stretches as [$start, $end]) {
            $start = max($start, $from);
            $end = min($end ?? $to, $to);
            if ($end > $start) {
                $days += ManilaCalendar::workingDaysBetween($start, $end);
            }
        }

        return $days;
    }

    /** @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $stretches */
    private static function secondsInside(CarbonImmutable $from, CarbonImmutable $to, array $stretches): int
    {
        $seconds = 0;
        foreach ($stretches as [$start, $end]) {
            $seconds += self::seconds(max($start, $from), min($end ?? $to, $to));
        }

        return $seconds;
    }

    private static function seconds(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(0, $to->getTimestamp() - $from->getTimestamp());
    }

    private static function instant(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value, (string) config('app.timezone', 'UTC'));
    }

    /** @param  array{code: string, department_id: int, permit_type_ids: list<int>}|null  $scope */
    private static function routedTo(QueryBuilder $query, ?array $scope): QueryBuilder
    {
        return $scope === null ? $query : $query->whereIn('applications.id', DB::table('application_assignments')
            ->select('application_id')
            ->where('department_id', $scope['department_id']));
    }
}

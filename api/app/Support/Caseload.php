<?php

namespace App\Support;

use App\Enums\ApplicationStatus;
use App\Enums\InspectionStatus;
use App\Models\ApplicationAssignment;
use App\Models\Inspection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an officer is currently holding, and how to move it.
 *
 * ── Why this is one class rather than two queries at the call site ───────────
 *
 * Three screens need to agree on the same sentence — "this officer has N open
 * cases": the Reassign dialog (which must say what it is about to move), the
 * Deactivate warning (which must say what is about to be released), and the
 * reassignment itself (which must move exactly what it just promised). Three
 * copies of "open means not completed" is three chances for the dialog to
 * promise one number and the action to move another, and the admin would have
 * no way to tell.
 *
 * ── What "open" means ────────────────────────────────────────────────────────
 *
 * Open is defined by exclusion — everything that is not finished — rather than
 * by listing the live states. AssignmentStatus has exactly one terminal case
 * (Completed) and InspectionStatus two (Completed, Cancelled); a new
 * intermediate state added to either enum is by definition still work somebody
 * has to do, and this reads it as open without anyone having to remember to
 * come back here. The opposite spelling — an allow-list of live states — fails
 * silently and in the dangerous direction: the new state simply stops being
 * counted, so a caseload reads as empty and an officer is deactivated on top of
 * live work.
 *
 * `returned` counts as open. The office is waiting on the applicant rather than
 * working, but the case is still assigned to that officer and comes back to
 * them when the applicant answers, so an officer who has left must not keep it.
 */
class Caseload
{
    /** Inspection states that are finished and therefore not part of a caseload. */
    private const CLOSED_INSPECTIONS = [InspectionStatus::Completed, InspectionStatus::Cancelled];

    /**
     * Reviews this officer still holds.
     *
     * ── Why the line is the FILING, not the assignment's own status ─────────
     *
     * This asked `application_assignments.status != completed`, and that
     * reading froze the Officer in Charge of a live filing.
     *
     * `completed` on an assignment does not mean the case is closed. BPLO's row
     * is completed the moment the main form is approved — the START of the
     * filing, not the end: the applicant then pays, five offices work their
     * clearances, inspections are booked, and the filing sits at
     * `awaiting_other_permits` for weeks with BPLO's assignment already marked
     * done. On the tester register EVERY BPLO assignment is in that state.
     *
     * Both consequences were visible on screen. An officer's "My assigned"
     * section was empty while they held three live filings, and the super
     * admin's Reassign dialog refused to move any of them — with the OIC
     * register three menu items away listing all three under that officer's
     * name. Three screens, three different answers about one officer.
     *
     * So: held while the APPLICATION is live, a record once it is decided. That
     * is also the sentence the client uses — "officer in charge of the permit"
     * is about who owns the case, not about who signed one step of it.
     */
    public static function reviews(User $officer): Builder
    {
        return ApplicationAssignment::query()
            ->where('officer_user_id', $officer->id)
            ->whereHas('application', fn ($a) => $a->whereNotIn('status', self::decidedStatuses()));
    }

    /**
     * The application states that end a case.
     *
     * Read off the enum rather than listed here, so a state added later is
     * classified by `isTerminal()` — the one place that already answers this
     * question — instead of silently counting as live.
     *
     * @return array<int, string>
     */
    private static function decidedStatuses(): array
    {
        return array_values(array_map(
            fn (ApplicationStatus $s) => $s->value,
            array_filter(ApplicationStatus::cases(), fn (ApplicationStatus $s) => $s->isTerminal()),
        ));
    }

    /** Site visits this officer still holds. */
    public static function inspections(User $officer): Builder
    {
        return Inspection::query()
            ->where('inspector_user_id', $officer->id)
            ->whereNotIn('status', self::CLOSED_INSPECTIONS);
    }

    /**
     * Reviews this officer is NAMED on but which are finished.
     *
     * Not part of a caseload — nothing here can be moved, and rewriting the name
     * on a completed review would falsify the record of who did the work. It is
     * reported because two screens otherwise contradict each other about one
     * officer: the super admin's OIC register lists every assignment a name is
     * on, so it says "Liza Reyes is officer in charge of two filings", while a
     * caseload counting only open work says she is holding nothing. Both are
     * true. Saying only one of them is what made it look like a bug.
     */
    public static function finishedReviews(User $officer): Builder
    {
        return ApplicationAssignment::query()
            ->where('officer_user_id', $officer->id)
            ->whereHas('application', fn ($a) => $a->whereIn('status', self::decidedStatuses()));
    }

    /**
     * The counts, for a dialog that has to say what it is about to do.
     *
     * @return array{reviews: int, inspections: int, total: int, finished_reviews: int}
     */
    public static function summary(User $officer): array
    {
        $reviews = self::reviews($officer)->count();
        $inspections = self::inspections($officer)->count();

        return [
            'reviews' => $reviews,
            'inspections' => $inspections,
            'total' => $reviews + $inspections,
            'finished_reviews' => self::finishedReviews($officer)->count(),
        ];
    }

    /**
     * One row per review, in the shape both lists print.
     *
     * Shared so that "what this officer holds" and "what nobody holds" cannot
     * describe the same filing two different ways on one dialog.
     *
     * @param  Builder<ApplicationAssignment>  $query
     * @return array<int, array<string, mixed>>
     */
    private static function describeReviews($query, int $limit): array
    {
        return $query
            ->with([
                'department:id,code,name',
                'application:id,tracking_id,business_id',
                'application.business:id,name',
                'application.permitTypes:id,code,name,issuing_department_id',
            ])
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (ApplicationAssignment $a) => [
                'kind' => 'review',
                'id' => $a->id,
                'application_id' => $a->application_id,
                'tracking_id' => $a->application?->tracking_id,
                // Null when the business has been removed from the register;
                // the filing stays and the row still has to render.
                'business' => $a->application?->business?->name,
                'office' => $a->department ? ['code' => $a->department->code, 'name' => $a->department->name] : null,
                /*
                 * This office's own permit on the filing — what the officer is
                 * actually reviewing. Matched on the issuing department rather
                 * than taken as "the first permit", so a six-clearance filing
                 * names the sanitary permit to City Health and the fire one to
                 * BFP instead of naming the same permit to all six.
                 */
                'permit' => optional(
                    $a->application?->permitTypes?->firstWhere('issuing_department_id', $a->department_id)
                )->name,
                'status_label' => $a->status?->label(),
                'at' => optional($a->assigned_at)->toISOString(),
            ])
            ->values()
            ->all();
    }

    /**
     * The office's open work that NOBODY holds.
     *
     * The other half of the Reassign dialog: it could only move work away from
     * the officer whose row was clicked, and the client wants a case nobody has
     * picked up handed to them from the same place. Same permission, opposite
     * direction.
     *
     * Scoped to the officer's own DEPARTMENT, not to the register: this list
     * exists to be assigned from, and an officer may only be given work their
     * own office was routed. An account with no office is offered nothing —
     * `department_id` null matches no department rather than every one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function unassignedInOfficeOf(User $officer, int $limit = 50): array
    {
        if ($officer->department_id === null) {
            return [];
        }

        return self::describeReviews(
            ApplicationAssignment::query()
                ->where('department_id', $officer->department_id)
                ->whereNull('officer_user_id')
                ->whereHas('application', fn ($a) => $a->whereNotIn('status', self::decidedStatuses())),
            $limit,
        );
    }

    /**
     * The open work itself, named — one row per filing the officer holds.
     *
     * The Reassign dialog used to say "Open reviews: 2" and stop, so the admin
     * confirmed a move without ever seeing WHICH filings were changing hands.
     * Everywhere else on this feature the client asks for the work by name —
     * business, business number, office, permit — and this was the one screen
     * that reduced it to a count.
     *
     * Reviews and inspections in one list, each saying which it is, because
     * they are one caseload to the person holding them and the dialog's Scope
     * control already splits them when that matters.
     *
     * Capped. An officer with two hundred cases is a real possibility on a live
     * register and a dialog is not a queue screen; the count above stays exact,
     * and `$limit` only bounds what is printed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cases(User $officer, int $limit = 50): array
    {
        $reviews = collect(self::describeReviews(self::reviews($officer), $limit));

        $inspections = self::inspections($officer)
            ->with([
                'department:id,code,name',
                'application:id,tracking_id,business_id',
                'application.business:id,name',
            ])
            ->get()
            ->map(fn (Inspection $i) => [
                'kind' => 'inspection',
                'id' => $i->id,
                'application_id' => $i->application_id,
                'tracking_id' => $i->application?->tracking_id,
                'business' => $i->application?->business?->name,
                'office' => $i->department ? ['code' => $i->department->code, 'name' => $i->department->name] : null,
                // A site visit is about the premises rather than one permit.
                'permit' => null,
                'status_label' => $i->status?->label(),
                'at' => optional($i->scheduled_at)->toISOString(),
            ]);

        return $reviews->concat($inspections)
            // Oldest first: the case that has been held longest is the one an
            // admin is most likely to be moving, and it would otherwise be the
            // one pushed off the end of a capped list.
            ->sortBy(fn (array $row) => $row['at'] ?? '')
            ->take($limit)
            ->values()
            ->all();
    }
}

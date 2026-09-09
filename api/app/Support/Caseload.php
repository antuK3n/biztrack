<?php

namespace App\Support;

use App\Enums\AssignmentStatus;
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
    /** Assignment states that are finished and therefore not part of a caseload. */
    private const CLOSED_ASSIGNMENTS = [AssignmentStatus::Completed];

    /** Inspection states that are finished and therefore not part of a caseload. */
    private const CLOSED_INSPECTIONS = [InspectionStatus::Completed, InspectionStatus::Cancelled];

    /** Reviews this officer still holds. */
    public static function reviews(User $officer): Builder
    {
        return ApplicationAssignment::query()
            ->where('officer_user_id', $officer->id)
            ->whereNotIn('status', self::CLOSED_ASSIGNMENTS);
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
            ->whereIn('status', self::CLOSED_ASSIGNMENTS);
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
        $reviews = self::reviews($officer)
            ->with([
                'department:id,code,name',
                'application:id,tracking_id,business_id',
                'application.business:id,name',
                'application.permitTypes:id,code,name,issuing_department_id',
            ])
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
                 *
                 * Null is a real answer and the key is always present: an office
                 * can be routed a filing that carries no permit it issues.
                 */
                'permit' => optional(
                    $a->application?->permitTypes?->firstWhere('issuing_department_id', $a->department_id)
                )->name,
                'status_label' => $a->status?->label(),
                'at' => optional($a->assigned_at)->toISOString(),
            ]);

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

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
     * The two counts, for a dialog that has to say what it is about to do.
     *
     * @return array{reviews: int, inspections: int, total: int}
     */
    public static function summary(User $officer): array
    {
        $reviews = self::reviews($officer)->count();
        $inspections = self::inspections($officer)->count();

        return [
            'reviews' => $reviews,
            'inspections' => $inspections,
            'total' => $reviews + $inspections,
        ];
    }
}

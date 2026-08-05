<?php

namespace App\Support;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may read a filing (tester checklist item 56).
 *
 * The client settled the ambiguity: "no they cant see what theyre not included
 * in." An office reviews the clearance it was routed, so it reads the filings
 * it is part of and no others — a sanitary officer has no business opening a
 * market stall renewal that never reached the City Health Office.
 *
 * Two roles keep the whole register: BPLO issues the mayor's permit and
 * coordinates every other office's clearance, and the super admin audits the
 * system. They carry `application.view_any_office`; everybody else with
 * `application.view_all` is narrowed to the departments they are assigned to.
 *
 * Membership is the assignment row, not the permit type: assignments are what
 * WorkflowService creates when a paid application is routed, they are what the
 * officer queue reads, and they survive reassignment.
 */
final class ApplicationVisibility
{
    /** Reviewers who read every filing regardless of routing. */
    public const ANY_OFFICE = 'application.view_any_office';

    /** Reviewers who read filings beyond their own (scoped by this class). */
    public const VIEW_ALL = 'application.view_all';

    /** True when this reader is a reviewer with no office boundary (BPLO, admin). */
    public static function readsEveryOffice(User $user): bool
    {
        return $user->hasPermission(self::ANY_OFFICE);
    }

    /** True when this reader is an office reviewer scoped to its own queue. */
    public static function readsOwnOffice(User $user): bool
    {
        return ! self::readsEveryOffice($user) && $user->hasPermission(self::VIEW_ALL);
    }

    /** May this user read this application at all? */
    public static function canView(User $user, Application $application): bool
    {
        if ($application->applicant_user_id === $user->id) {
            return true;
        }
        if (self::readsEveryOffice($user)) {
            return true;
        }
        if (! $user->hasPermission(self::VIEW_ALL) || ! $user->department_id) {
            return false;
        }

        return $application->assignments()
            ->where('department_id', $user->department_id)
            ->exists();
    }

    /**
     * Refuse with 403 rather than 404: the reader is authenticated and the row
     * exists, so "you may not" is the honest answer and the frontend already
     * distinguishes it from a missing record.
     */
    public static function authorize(User $user, Application $application, ?string $message = null): void
    {
        abort_unless(
            self::canView($user, $application),
            403,
            $message ?? 'This application belongs to another office.'
        );
    }

    /**
     * Narrow a query so it can only ever return rows this user may read.
     *
     * $relation is the path from the queried model to the application: null for
     * a query on Application itself, otherwise a relation name such as
     * 'application'. A reviewer with no department matches nothing — the office
     * boundary fails closed.
     */
    public static function scope(Builder $query, User $user, ?string $relation = null): void
    {
        if (self::readsEveryOffice($user)) {
            return;
        }

        $constrain = function (Builder $app) use ($user) {
            if (! $user->hasPermission(self::VIEW_ALL)) {
                $app->where('applicant_user_id', $user->id);

                return;
            }
            if (! $user->department_id) {
                $app->whereRaw('1 = 0');

                return;
            }
            $app->where(function (Builder $sub) use ($user) {
                $sub->where('applicant_user_id', $user->id)
                    ->orWhereHas('assignments', fn (Builder $a) => $a->where('department_id', $user->department_id));
            });
        };

        if ($relation === null) {
            $constrain($query);

            return;
        }

        $query->whereHas($relation, $constrain);
    }
}

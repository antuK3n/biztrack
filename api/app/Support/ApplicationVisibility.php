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

    /**
     * May this reader see ONE office's form sheet? (checklist item 111)
     *
     * canView() above answers the coarse question — may you open this filing at
     * all. This answers the finer one: a six-clearance filing is routed to six
     * offices, so all six pass the coarse check, and each was then handed all
     * six questionnaires. The sanitary officer could read the fire office's
     * FSIC answers, and on a seven-office filing the CHO officer's payload
     * carried CENRO's `owner_birthday` — a date of birth, on a screen that
     * prints an RA 10173 consent notice eight sections earlier.
     *
     * This lives here, and not in the controller where it was born, because it
     * had TWO readers and only one of them was applying it. The item-111 fix
     * put the rule inside `OfficeFormController::readableCode`, which gates
     * `GET /applications/{id}/office-forms`. The officer's review sheet does not
     * call that endpoint — it reads office forms out of
     * `GET /assignments/{id}` → ApplicationResource, which had no filter at all.
     * Same user, same filing, two endpoints, two answers. A rule that is a
     * private method on one of its two consumers is a rule the other consumer
     * cannot obey; hence one predicate, in the class that already owns "who may
     * read what".
     *
     * The boundary is the permit type's issuing department: the FSIC sheet
     * belongs to whoever issues FSIC. Three readers keep everything:
     *
     *  - the applicant, because every one of these sheets is their own answers.
     *    They fill all six in the wizard; hiding them would break the filing;
     *  - BPLO and the super admin (ANY_OFFICE), who coordinate and audit across
     *    offices by design;
     *  - the office that issues the clearance the sheet is for.
     *
     * Fails closed on a null issuing department — an unrecognised permit code,
     * or a sheet whose type was deleted — because `department_id !== null` is
     * checked on the reader's side of the comparison, not the sheet's. A
     * reviewer with no department matches nothing, the same posture scope()
     * takes.
     *
     * This is about OFFICE FORMS ONLY. It is not a licence to strip the shared
     * sheet: the address, barangay, PSIC line, products, uploaded requirements
     * and floor area are the APPLICANT's particulars and every office on the
     * filing needs them to work — CENRO reviews the PSIC line, CPDO's fee is per
     * square metre, and an inspector who cannot read the address cannot find the
     * premises.
     */
    public static function readsOfficeSheet(?User $user, ?int $issuingDepartmentId): bool
    {
        if ($user === null) {
            return false;
        }
        if (self::readsEveryOffice($user)) {
            return true;
        }
        // The applicant is the author of every sheet on their own filing.
        if (! $user->hasPermission(self::VIEW_ALL)) {
            return true;
        }

        return $user->department_id !== null
            && $user->department_id === $issuingDepartmentId;
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

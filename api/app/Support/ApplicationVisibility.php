<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Inspection;
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

    /**
     * May this reader see ONE office's issued clearance?
     *
     * The same boundary as readsOfficeSheet, and deliberately the same code:
     * the answers an office collects and the certificate it issues off those
     * answers belong to that office, and it would be incoherent to hide the
     * FSIC questionnaire from the sanitary officer while handing them the FSIC.
     *
     * That incoherence was live. PermitController drew its line at the FILING —
     * `canView()` — so a six-clearance filing routed to six offices gave all
     * six offices all six certificates. A CHO session could read a BFP-issued
     * Fire Safety Inspection Certificate and download its PDF: 200, the owner's
     * name and street address, more than the deliberately anonymous public
     * /verify endpoint gives out. Permit ids are sequential and the URL is
     * typeable, so filtering the list alone was never a boundary.
     *
     * The client's sentence is the specification: "sanitary accounts can only
     * see sanitary permits, and fire accounts can only see fire".
     *
     * Same three readers keep everything, for the same reasons as the sheets:
     * the applicant (it is their certificate), BPLO and the super admin
     * (ANY_OFFICE — they coordinate and audit across offices by design), and
     * the office that issues that clearance.
     *
     * Fails closed on a null department on either side.
     */
    public static function readsPermitOf(?User $user, ?int $issuingDepartmentId): bool
    {
        return self::readsOfficeSheet($user, $issuingDepartmentId);
    }

    /**
     * May this reader see the WRITTEN-UP half of one site visit? (INS-8)
     *
     * The third instance of the same shape, and the third time the rule was
     * enforced at one door and not the other. `InspectionController` has always
     * refused a cross-office read — `GET /inspections/10163` answers 403 to a
     * CHO session on a BFP visit — but the officer's review sheet never calls
     * that endpoint. It reads visits out of `GET /assignments/{id}`, and
     * `GET /applications/{id}` carries the same block, both through
     * ApplicationResource → InspectionResource, which had no filter at all. An
     * e2e run found 21 leaked visits across all six inspecting offices: the CHO
     * session refused inspection 10163 could nonetheless read that BFP visit's
     * inspector by name off assignment 15805, and on filing 3393 the prose
     * "Food handlers without current health certificates…".
     *
     * ── Where the boundary is ─────────────────────────────────────────────────
     *
     * NOT the permit type's issuing department, which is what readsOfficeSheet
     * and readsPermitOf use. A visit carries its OWN `department_id` — the
     * office that booked it and will conduct it (WorkflowService::openInspection
     * sets it, and the column is NOT NULL) — so the conducting
     * office is a fact on the row rather than something to infer through the
     * permit type. The two happen to coincide today because each clearance is
     * inspected by the office that issues it, but they are different questions
     * and this one has a direct answer.
     *
     * ── Which readers keep everything ─────────────────────────────────────────
     *
     *  - the applicant, because the visit is to THEIR premises and the findings
     *    are what they have to put right before a re-inspection can pass;
     *  - BPLO and the super admin (ANY_OFFICE), who coordinate and audit across
     *    offices by design;
     *  - the office that conducted the visit;
     *  - the inspector NAMED on the visit, even from another office. That
     *    disjunct is not new here — it is the second half of
     *    InspectionController::authorizeDepartment, which lets a departmentless
     *    officer act on the visits booked to them. Read and write have to agree,
     *    or `/inspections` would hand that officer a row whose own findings are
     *    blanked while `conduct` still accepts their result.
     *
     * ── What is withheld, and what deliberately is not ────────────────────────
     *
     * Only `findings` and `inspector`. Bare progress — status, result, the
     * office, the dates — stays visible to every office on the filing, because
     * the filing does not advance until every current visit passes
     * (WorkflowService::recordInspection) and an office waiting on a
     * clearance has a genuine need to know that the fire visit happened and
     * whether it passed. Withholding the result would replace a privacy defect
     * with a coordination one. What another office has no need for is the free
     * prose about someone else's premises and the name of the officer who wrote
     * it — the two fields the e2e run actually surfaced.
     *
     * Fails closed on a null user, and on a reviewer with no department, for the
     * same reason scope() does.
     */
    public static function readsInspectionDetail(?User $user, Inspection $inspection): bool
    {
        if ($user === null) {
            return false;
        }

        return self::readsOfficeSheet($user, $inspection->department_id)
            || $inspection->inspector_user_id === $user->id;
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

<?php

namespace App\Http\Resources;

use App\Enums\ClearanceStatus;
use App\Models\ApplicationReturnNote;
use App\Support\ApplicationVisibility;
use App\Support\CaseHolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches contract AssignmentResource.
 *
 * ── SEP-7: whose review this is, versus what they wrote about it ───────────
 *
 * An application is routed to every office that owes it a permit, and
 * `ApplicationResource` embeds all of their assignments — so a payload the City
 * Health Office reads carries the Bureau of Fire Protection's row. Two fields on
 * that row are the fire office's alone: the `remarks` an officer typed about
 * this applicant's premises, and the NAME of the officer who typed them.
 *
 * This is the same defect and the same split as INS-8 settled for site visits,
 * one object over. Bare progress — the office, the status, when it was assigned
 * and when it completed — stays visible to everyone on the filing, because BPLO
 * cannot approve until every office is done and each office is waiting on the
 * others. The prose and the person are withheld.
 *
 * The boundary is the assignment's OWN `department_id`, not a permit type's
 * issuing department: an assignment is a fact about which office holds the work,
 * so the answer is on the row rather than inferred through the permit.
 *
 * `readsOfficeSheet` is the predicate rather than a fifth near-copy of it. Its
 * three keeper-of-everything readers are exactly right here too: the applicant
 * (these remarks are addressed to them and are what they must act on), BPLO and
 * the super admin (they coordinate and audit across offices by design), and the
 * office whose row it is.
 */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $readsWords = ApplicationVisibility::readsOfficeSheet(
            $request->user(),
            $this->department_id,
        );

        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'remarks' => $readsWords ? $this->remarks : null,
            /*
             * Behind the same gate as the prose it points at. A pointer without
             * its remarks names a field and gives no reason, which is worse
             * than silence — and it is the same sentence, so it answers to the
             * same reader.
             */
            'remarks_target' => $readsWords ? $this->remarks_target : null,
            'department' => $this->relationLoaded('department') && $this->department ? [
                'code' => $this->department->code,
                'name' => $this->department->name,
            ] : null,
            'officer' => $readsWords && $this->relationLoaded('officer') && $this->officer ? [
                'id' => $this->officer->id,
                'name' => $this->officer->name,
            ] : null,
            /*
             * Is `officer` null because NOBODY has claimed this review, or
             * because this reader may not be told who did?
             *
             * The two were indistinguishable and one screen was guessing. The
             * Office approvals panel printed "Not yet assigned to an officer"
             * whenever `officer` was null, so the scoping rule above — which is
             * working exactly as intended — made every other office's row state
             * a fact about staffing that was not true. On filing 5 a CENRO
             * session read that sentence under BPLO, whose review was completed
             * by officer 2.
             *
             * A withheld value must never render as a factual claim about the
             * thing withheld. So the resource says which of the two it is, and
             * the screen has something honest to print for each.
             *
             * `officer_user_id` is deliberately the test rather than the loaded
             * relation: a deleted staff account leaves the id standing on the
             * row, and "somebody signed this off" stays true after they leave.
             */
            'officer_withheld' => ! $readsWords && $this->officer_user_id !== null,
            /*
             * What this reader may DO with the row, decided here rather than in
             * the browser.
             *
             * The queue has to render a colleague's case read-only and its own
             * as workable, and those two rows are identical apart from an id
             * comparison. Making the screen do that comparison puts the OIC rule
             * in two places — one of which nobody can enforce — and the two
             * would drift the first time the rule gains a case. So the server
             * answers, and the screen renders the answer.
             *
             * Both are false for a reader outside the office: they can neither
             * take it nor act on it, which is what `authorizeDepartment` says.
             */
            'can_claim' => $this->canClaim($request),
            'can_act' => $this->canAct($request),
            'assigned_at' => optional($this->assigned_at)->toISOString(),
            'completed_at' => optional($this->completed_at)->toISOString(),
            /*
             * This office's own permit on the filing, so a queue row can say
             * what it is actually waiting for.
             *
             * The assignment's status cannot: `approveClearance()` completes it
             * the moment the paperwork is accepted, which is when the site visit
             * has still to happen — so `completed` covers both "inspecting" and
             * "finished" and the row would read as done in both. The clearance
             * status is the honest one, and it is the same field the queue's
             * `?clearance_status=` filter selects on, so the row cannot say
             * something other than the tab it arrived in.
             *
             * Null when the office holds no permit on this filing — an office
             * routed something it does not issue, or a caller that did not
             * eager-load. BPLO is NOT that case: it issues the Mayor's /
             * Business Permit, so this is populated for BPLO too. Read it with
             * care there, though — that permit is the filing's outcome rather
             * than one of the five clearances, and it sits at `for_approval`
             * from Pending Payment all the way to Final Approval, so it does not
             * distinguish BPLO's two acts. The application's status does.
             *
             * No RBAC gate, and that is deliberate rather than an omission —
             * this is the reader's OWN office's permit by construction, matched
             * on `issuing_department_id === $this->department_id`. Progress is
             * shared across the filing in any case (see the note above); it is
             * the prose that is withheld, and none is exposed here.
             */
            'clearance' => $this->clearanceRow(),
            'inspection' => $this->inspectionRow($request),
            'application' => $this->whenLoaded('application', fn () => [
                'id' => $this->application->id,
                'tracking_id' => $this->application->tracking_id,
                'business' => $this->application->relationLoaded('business') && $this->application->business ? [
                    'name' => $this->application->business->name,
                ] : null,
                'application_type' => $this->application->application_type?->value,
                'status' => $this->application->status?->value,
                // See the note on ApplicationResource's own `decided`.
                'status_label' => $this->application->statusLabel(),
                'decided' => $this->application->isDecided(),
            ]),
        ];
    }

    /**
     * The permit this office issues on this filing, or null if it issues none.
     *
     * Reads from the already-loaded `application.permitTypes` rather than
     * querying: this resource is rendered once per row of a 25-row queue, and a
     * lookup here would be 25 round trips behind a screen that has to feel
     * instant. `relationLoaded` is checked instead of lazy-loading so a caller
     * that forgot to eager-load gets a null — a missing chip — rather than a
     * silent N+1 nobody notices until the queue is slow.
     *
     * Only the FIRST match is reported. Every office in the register issues
     * exactly one permit type today; if one ever issues two, this shows one of
     * them and the queue row becomes ambiguous — that is the moment to make this
     * a list, and the reason it is written as a `first()` rather than a `sole()`
     * that would take a working screen down instead.
     */
    private function clearanceRow(): ?array
    {
        if (! $this->relationLoaded('application')
            || $this->application === null
            || ! $this->application->relationLoaded('permitTypes')) {
            return null;
        }

        $type = $this->application->permitTypes
            ->first(fn ($pt) => $pt->issuing_department_id === $this->department_id);

        if ($type === null) {
            return null;
        }

        return [
            'code' => $type->code,
            'name' => $type->name,
            'status' => $type->pivot?->status?->value,
            'status_label' => $type->pivot?->status?->label(),
            'mode' => $type->pivot?->mode,
            'requires_inspection' => (bool) $type->requires_inspection,
            /*
             * When this office last sent the permit back.
             *
             * The client's decision of 17 September 2026 was to show how long a
             * return has been waiting on BOTH sides — the applicant's card and
             * the officer's queue row — and invent no deadline. The applicant's
             * half shipped; this is the officer's, and it is the half that
             * matters for chasing: it is what lets an office see which filings
             * have gone quiet.
             *
             * Progress, not prose, so it is NOT gated on `readsOfficeSheet`
             * like the remarks are. "This office is waiting on the applicant"
             * is the same kind of fact as the status beside it, and says
             * nothing about what anyone wrote.
             */
            'returned_at' => optional($type->pivot?->returned_at)->toISOString(),
            /*
             * What this office last asked for, and what it said about each.
             *
             * The office reading its OWN open return, so that amending it can
             * open on the fields already ticked rather than on a blank list —
             * the pointer is replaced wholesale on every write, so an officer
             * adding one field to a blank composer would silently drop the
             * others.
             *
             * Lives on the pivot rather than the assignment: `returnClearance`
             * writes the permit row, because one filing carries six permits
             * and an office's question is about its own.
             */
            'return_target' => $type->pivot?->remarks_target,
            'return_remark' => $type->pivot?->remarks,
            'return_notes' => ApplicationReturnNote::where(
                'application_id',
                $type->pivot?->application_id,
            )
                ->where('permit_type_id', $type->id)
                ->pluck('note', 'target')
                ->all() ?: (object) [],
            /*
             * ── Has this office refused this permit before? ──────────────
             *
             * The re-read is the whole reason these exist. When an applicant
             * re-applies after a refusal, `submitClearanceForm` clears the
             * remarks — the instruction has been answered — and the row comes
             * back to this office as a clean `for_approval`. Without this the
             * officer cannot tell it is the second time, and can approve in
             * good faith exactly what their office turned down last week.
             *
             * It matters more than it would have a week ago: the sheet
             * reopens with every answer still in it, so an applicant can
             * resubmit unchanged and the office receives an identical form.
             *
             * Progress, not prose, on the same reasoning as `returned_at`
             * above: this is the office's own decision being read back to the
             * office that made it, not another office's paperwork.
             */
            'rejected_at' => optional($type->pivot?->rejected_at)->toISOString(),
            'rejection_note' => $type->pivot?->rejection_note,
            'rejection_remedy' => $type->pivot?->rejection_remedy,
        ];
    }

    /**
     * This office's current site visit, and the inspector's name typed on it.
     *
     * ── Not a second officer in charge ──────────────────────────────────────
     *
     * On 4 October 2026 this carried an inspector ACCOUNT with Claim/Release
     * flags, after the client said the For Inspection officer could differ
     * from the For Approval one. On 5 October they settled what that means:
     * *"since an inspector can have no account in the system, would it be
     * better if the admin just type the name of the inspector assigned? The
     * officer in charge is still the one to approve or reject the inspection,
     * but he/she must still be able to put the inspector name just for the
     * record. The field must be editable."*
     *
     * So the officer in charge (`officer` above) holds the visit as it holds
     * the review, and this row only adds the typed name and whether the
     * reader may change it.
     *
     * `can_name_inspector` is worked out from what the queue already loaded —
     * the office's permit pivot and the filing's `decided_at` — rather than
     * `Inspection::inspectorNameEditableBy()`, which asks two queries per row
     * on a 25-row page. Same rule: the reader's own office, `inspection.manage`,
     * the permit still for inspection on an undecided filing.
     *
     * Null where there is no visit, which is most rows: the collection arrives
     * already narrowed to current visits (see the eager load in
     * `AssignmentController::index`), so this picks its own department's and
     * asks no question about which visit is the live one.
     */
    private function inspectionRow(Request $request): ?array
    {
        if (! $this->relationLoaded('application')
            || $this->application === null
            || ! $this->application->relationLoaded('inspections')) {
            return null;
        }

        $visit = $this->application->inspections
            ->firstWhere('department_id', $this->department_id);

        if ($visit === null) {
            return null;
        }

        $user = $request->user();
        $permit = $this->application->relationLoaded('permitTypes')
            ? $this->application->permitTypes
                ->first(fn ($pt) => $pt->issuing_department_id === $this->department_id)
            : null;

        return [
            'id' => $visit->id,
            'status' => $visit->status?->value,
            'scheduled_at' => optional($visit->scheduled_at)->toISOString(),
            'inspector_name' => $visit->inspector_name,
            'can_name_inspector' => $user !== null
                && CaseHolder::mayAct($user, $this->resource)
                && $user->hasPermission('inspection.manage')
                && $permit?->pivot?->status === ClearanceStatus::ForInspection
                && ! $this->application->isDecided(),
        ];
    }

    /** May this reader take an unheld case in their own office? */
    private function canClaim(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && $user->department_id !== null
            && $user->department_id === $this->department_id
            && $this->officer_user_id === null;
    }

    /**
     * May this reader work the case — approve, return, check, classify?
     *
     * `CaseHolder::mayAct`, the same question every door on the API asks: the
     * holder, or anyone in BPLO while nobody holds it. The pairing is
     * deliberate — a screen that offered a button the server then refused
     * would be worse than no button.
     */
    private function canAct(Request $request): bool
    {
        return CaseHolder::mayAct($request->user(), $this->resource);
    }
}

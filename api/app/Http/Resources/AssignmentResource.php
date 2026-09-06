<?php

namespace App\Http\Resources;

use App\Support\ApplicationVisibility;
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
            'department' => $this->relationLoaded('department') && $this->department ? [
                'code' => $this->department->code,
                'name' => $this->department->name,
            ] : null,
            'officer' => $readsWords && $this->relationLoaded('officer') && $this->officer ? [
                'id' => $this->officer->id,
                'name' => $this->officer->name,
            ] : null,
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
            'application' => $this->whenLoaded('application', fn () => [
                'id' => $this->application->id,
                'tracking_id' => $this->application->tracking_id,
                'business' => $this->application->relationLoaded('business') && $this->application->business ? [
                    'name' => $this->application->business->name,
                ] : null,
                'application_type' => $this->application->application_type?->value,
                'status' => $this->application->status?->value,
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
        ];
    }
}

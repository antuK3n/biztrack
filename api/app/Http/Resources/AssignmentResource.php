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
}

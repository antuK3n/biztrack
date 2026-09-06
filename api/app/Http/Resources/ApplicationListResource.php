<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact application shape for list endpoints. */
class ApplicationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tracking_id' => $this->tracking_id,
            'application_type' => $this->application_type?->value,
            // Null falls back to the business name on the Drafts page.
            'title' => $this->title,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'business' => $this->relationLoaded('business') && $this->business ? [
                'id' => $this->business->id,
                'name' => $this->business->name,
            ] : null,
            /*
             * Who filed it. The officer request composer has to name the person
             * a requirement is being sent to before it is sent (checklist item
             * 89), and the picker it reads is this list. Null is a real state
             * rather than a bug: User soft-deletes, so a filing can outlive the
             * account that made it — readers fall back to the business.
             */
            'applicant' => $this->relationLoaded('applicant') && $this->applicant ? [
                'id' => $this->applicant->id,
                'name' => $this->applicant->name,
            ] : null,
            'submitted_at' => optional($this->submitted_at)->toISOString(),
            'deadline_at' => optional($this->deadline_at)->toISOString(),
            /*
             * Each permit with its OWN status, not just its name.
             *
             * This sent `{ code, name }` and the tracking screen inferred the
             * rest — from the issuing office's assignment and the application's
             * status, which is the only evidence there was before the pivot
             * existed. Both inferences are now wrong in opposite directions: a
             * completed BPLO assignment means "the form was accepted", not "the
             * permit is approved", and a filing at `awaiting_other_permits` says
             * nothing about whether any particular permit has been applied for.
             * The screen showed the Business Permit as Approved and five
             * untouched permits as For Approval on a filing where none of that
             * was true.
             *
             * Two extra columns on a list row, and they remove a whole class of
             * defect: the tracking screen no longer holds a second, weaker copy
             * of the state machine, and its rows stop being wrong for the
             * moment before the detail request lands — which on a slow
             * connection is the only version many people read.
             */
            'permit_types' => $this->relationLoaded('permitTypes')
                ? $this->permitTypes->map(fn ($pt) => [
                    'code' => $pt->code,
                    'name' => $pt->name,
                    'status' => $pt->pivot?->status?->value,
                    'status_label' => $pt->pivot?->status?->label(),
                ])->values()
                : [],
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}

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
            'permit_types' => $this->relationLoaded('permitTypes')
                ? $this->permitTypes->map(fn ($pt) => ['code' => $pt->code, 'name' => $pt->name])->values()
                : [],
            'created_at' => optional($this->created_at)->toISOString(),
        ];
    }
}

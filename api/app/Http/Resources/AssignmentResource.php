<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches contract AssignmentResource. */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'remarks' => $this->remarks,
            'department' => $this->relationLoaded('department') && $this->department ? [
                'code' => $this->department->code,
                'name' => $this->department->name,
            ] : null,
            'officer' => $this->relationLoaded('officer') && $this->officer ? [
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

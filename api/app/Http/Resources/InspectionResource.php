<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches contract InspectionResource. */
class InspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * relationLoaded, not whenLoaded.
         *
         * `whenLoaded('application')` with a single argument returns a
         * MissingValue *object* when the relation is absent, and an object is
         * truthy — so this ternary took the "loaded" branch every time and
         * `$this->application` lazy-loaded a row. Invisible in the inspection
         * list, where the relation really is eager-loaded; a query per row
         * everywhere InspectionResource is nested without it, which is every
         * ApplicationResource (`inspections.department`, `inspections.inspector`
         * — no `inspections.application`) and the assignment detail page.
         */
        $app = $this->relationLoaded('application') ? $this->application : null;
        $address = $app && $app->relationLoaded('business') && $app->business && $app->business->relationLoaded('address')
            ? $app->business->address
            : null;

        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'result' => $this->result?->value,
            'result_label' => $this->result?->label(),
            'scheduled_at' => optional($this->scheduled_at)->toISOString(),
            'conducted_at' => optional($this->conducted_at)->toISOString(),
            'findings' => $this->findings,
            'department' => $this->relationLoaded('department') && $this->department ? [
                'code' => $this->department->code,
                'name' => $this->department->name,
            ] : null,
            'inspector' => $this->relationLoaded('inspector') && $this->inspector ? [
                'id' => $this->inspector->id,
                'name' => $this->inspector->name,
            ] : null,
            'application' => $app ? [
                'id' => $app->id,
                'tracking_id' => $app->tracking_id,
                'business' => $app->relationLoaded('business') && $app->business ? [
                    'name' => $app->business->name,
                ] : null,
                'address' => $address ? [
                    'line1' => $address->line1,
                    'barangay' => $address->relationLoaded('barangay') && $address->barangay ? [
                        'name' => $address->barangay->name,
                    ] : null,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                ] : null,
            ] : null,
        ];
    }
}

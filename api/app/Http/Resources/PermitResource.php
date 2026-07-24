<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches contract PermitResource. */
class PermitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'permit_number' => $this->permit_number,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'valid_from' => optional($this->valid_from)->toDateString(),
            'valid_until' => optional($this->valid_until)->toDateString(),
            'days_until_expiry' => (int) $this->daysUntilExpiry(),
            'permit_type' => $this->relationLoaded('permitType') && $this->permitType ? [
                'code' => $this->permitType->code,
                'name' => $this->permitType->name,
            ] : null,
            'business' => $this->relationLoaded('business') && $this->business ? [
                'id' => $this->business->id,
                'name' => $this->business->name,
            ] : null,
            'application' => $this->relationLoaded('application') && $this->application ? [
                'id' => $this->application->id,
                'tracking_id' => $this->application->tracking_id,
            ] : null,
            'verify_url' => rtrim((string) config('app.frontend_url'), '/').'/verify/'.$this->permit_number,
        ];
    }
}

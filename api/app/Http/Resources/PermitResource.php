<?php

namespace App\Http\Resources;

use App\Support\RenewalWindow;
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
                // The office that issued it — the one an owner writes to about it.
                'department_id' => $this->permitType->issuing_department_id,
                'office' => $this->permitType->relationLoaded('department') ? $this->permitType->department?->name : null,
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
            /*
             * Why this permit cannot be renewed today, or null if it can.
             *
             * The renewal picker offered EVERY active and expired permit and
             * had no way to know the server would refuse one: a clearance
             * lapsed past the window was ticked, the whole wizard filled in,
             * and the refusal arrived as a 422 on the last screen. The rule
             * was added on 1 October 2026 and this is the half of it the
             * applicant can see.
             *
             * The SENTENCE, not a boolean. "Too early, come back on this
             * date" and "too late, file a New Application" are different
             * news and the picker should not have to reconstruct which from
             * a flag; `RenewalWindow` already words both, and wording them
             * twice is how the two copies drift apart.
             *
             * Needs `permitType` to answer, since the business permit is
             * exempt. Unloaded, it says nothing rather than guessing — a
             * caller that did not ask for the relation is not a caller that
             * is drawing a renewal picker.
             */
            'renewal_blocked_reason' => $this->relationLoaded('permitType')
                ? RenewalWindow::refusalFor($this->resource)
                : null,
            // Set on the owner's own list only (PermitController::index).
            'renewal_in_progress' => $this->whenHas('renewal_in_progress'),
        ];
    }
}

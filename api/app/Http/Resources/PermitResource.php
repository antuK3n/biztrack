<?php

namespace App\Http\Resources;

use App\Enums\PermitStatus;
use App\Models\Permit;
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
            ] : null,
            'business' => $this->relationLoaded('business') && $this->business ? [
                'id' => $this->business->id,
                'name' => $this->business->name,
            ] : null,
            'application' => $this->relationLoaded('application') && $this->application ? [
                'id' => $this->application->id,
                'tracking_id' => $this->application->tracking_id,
            ] : null,
            /*
             * Why it is suspended, and since when. Client, 5 October 2026:
             * *"Show WHY it is suspended and WHICH office caused it."* Null on
             * every permit that is not Suspended, so a screen never explains a
             * suspension that is over. `suspended_for` is null when the cause
             * named no single permit (a sanctioned business, a rejected
             * filing). The owner and staff get the reason; the public QR page
             * builds its own smaller payload in VerifyController.
             */
            ...$this->suspension(),
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
        ];
    }

    /** @return array{suspended_at: ?string, suspended_days: ?int, suspension_reason: ?string, suspended_for: ?array} */
    private function suspension(): array
    {
        $on = $this->status === PermitStatus::Suspended;

        return [
            'suspended_at' => $on ? optional($this->suspended_at)->toDateString() : null,
            'suspended_days' => $on ? $this->daysSuspended() : null,
            'suspension_reason' => $on ? $this->suspension_reason : null,
            'suspended_for' => $on ? self::suspendedFor($this->resource) : null,
        ];
    }

    /**
     * The refused permit and its office, or null. Shared with the public
     * verify payload and the Track row so the three name it alike.
     *
     * @return array{code: string, name: string, office: ?string}|null
     */
    public static function suspendedFor(Permit $permit): ?array
    {
        if ($permit->suspended_for_permit_type_id === null) {
            return null;
        }
        $type = $permit->suspendedFor;

        return $type === null ? null : [
            'code' => $type->code,
            'name' => $type->name,
            'office' => $type->department?->name,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches contract PaymentResource.
 *
 * `pay_url` / `pay_url_kind` are sent only while a KwikPay payment is still
 * pending: they are where the owner goes (a link) or what they scan (a QR
 * image) to finish paying, and once the payment is settled they are an
 * invitation to pay again. `gateway_order_id`, the check counters and the
 * gateway's own words stay server-side — they are for staff, not the owner.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Set aside: still pending at KwikPay, but the owner is not sent back to it.
        $pending = $this->status === PaymentStatus::Pending && $this->abandoned_at === null;

        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'amount' => $this->amount,
            'method' => $this->method?->value,
            'status' => $this->status?->value,
            'paid_at' => optional($this->paid_at)->toISOString(),
            'gateway' => $this->gateway ?? 'simulated',
            'pay_url' => $pending ? $this->pay_url : null,
            'pay_url_kind' => $pending ? $this->pay_url_kind : null,
            'created_at' => optional($this->created_at)->toISOString(),
            // Owner chose "Pay a different way"; still being checked.
            'set_aside' => $this->abandoned_at !== null,
            // Paid twice for one bill; staff are reviewing a refund.
            'refund_review' => $this->refund_review_at !== null,
            'application' => $this->whenLoaded('application', fn () => [
                'id' => $this->application->id,
                'tracking_id' => $this->application->tracking_id,
            ]),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One piece of proof of a counter payment (App\Models\PaymentProof).
 *
 * The file itself is never in the payload: `has_photo` says there is one and
 * `GET /payment-proofs/{id}/file` serves it to the people allowed to see it.
 * The stored path stays server-side, as a document's does.
 */
class PaymentProofResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'or_number' => $this->or_number,
            // A bare calendar date, as the owner typed it.
            'or_date' => optional($this->or_date)->toDateString(),
            'has_photo' => $this->resource->hasPhoto(),
            'photo_name' => $this->original_filename,
            'submitted_at' => optional($this->submitted_at)->toISOString(),
            'submitted_by' => $this->whenLoaded('submittedBy', fn () => $this->submittedBy ? [
                'id' => $this->submittedBy->id,
                'name' => $this->submittedBy->name,
            ] : null),
        ];
    }
}

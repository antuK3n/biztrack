<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of OR Records: a payment with an Official Receipt (OrRecordController).
 *
 * `proof` is the one photo the row's View opens, or null when there is none.
 * The newest photo linked to the payment: the officer's, when they attached
 * one at the counter, else the owner's. Every proof row is kept regardless;
 * this only chooses what one link opens.
 */
class OrRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $application = $this->application;
        $photo = $this->relationLoaded('proofs') ? $this->proofs->sortByDesc('id')->first() : null;

        return [
            'id' => $this->id,
            'or_number' => $this->or_number,
            'or_date' => optional($this->or_date)->toDateString(),
            'amount' => $this->amount,
            // When BPLO recorded it.
            'recorded_at' => optional($this->paid_at)->toISOString(),
            'application' => $application ? [
                'id' => $application->id,
                'tracking_id' => $application->tracking_id,
            ] : null,
            // Null when the business has been removed from the register.
            'business' => $application?->business ? [
                'id' => $application->business->id,
                'name' => $application->business->name,
            ] : null,
            'recorded_by' => $this->recordedBy ? [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ] : null,
            'proof' => $photo ? [
                'id' => $photo->id,
                'name' => $photo->original_filename,
            ] : null,
        ];
    }
}

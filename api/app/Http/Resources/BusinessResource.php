<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches the contract Business resource shape. */
class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'registration_type' => $this->registration_type,
            'registration_number' => $this->registration_number,
            'tin' => $this->tin,
            'ban' => $this->ban,
            'is_active' => (bool) $this->is_active,
            // Owner-visible standing (p006 blacklist modal reads this).
            'status' => $this->status ?? 'active',
            'address' => $this->whenLoaded('address', fn () => $this->address ? [
                'line1' => $this->address->line1,
                'line2' => $this->address->line2,
                'city' => $this->address->city,
                'province' => $this->address->province,
                'postal_code' => $this->address->postal_code,
                'latitude' => $this->address->latitude,
                'longitude' => $this->address->longitude,
                'barangay' => $this->address->relationLoaded('barangay') && $this->address->barangay ? [
                    'id' => $this->address->barangay->id,
                    'name' => $this->address->barangay->name,
                ] : null,
            ] : null),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'psic_code' => $line->relationLoaded('psicCode') && $line->psicCode ? [
                    'id' => $line->psicCode->id,
                    'code' => $line->psicCode->code,
                    'title' => $line->psicCode->title,
                ] : null,
                'capitalization' => $line->capitalization,
            ])->values()),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessAddress extends Model
{
    /*
     * `house_bldg_no` and `street` were missing here, and that is not a
     * historical detail — it is why an approved address amendment did nothing.
     *
     * `AmendableFields::flush` writes the address with `update()`, and Eloquent
     * drops an unfillable key in SILENCE: the request succeeded, the amendment
     * was stamped applied, and the register still held the old street. Every
     * other writer of these two columns assigns them as properties
     * (`BusinessController::syncAddressAndLines`), which is why nothing had
     * noticed in the year they were absent.
     *
     * `line1` is here too and is COMPOSED from the pair rather than typed —
     * see the note in `syncAddressAndLines`, and the matching one in `flush`.
     */
    protected $fillable = [
        'business_id', 'line1', 'line2', 'house_bldg_no', 'street', 'block', 'lot',
        'lot_area_sqm', 'barangay_id', 'city', 'province', 'postal_code', 'latitude', 'longitude',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'lot_area_sqm' => 'float',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }
}

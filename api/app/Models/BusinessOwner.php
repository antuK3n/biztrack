<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paper Table 41 — personal owner details (supports multiple owners). */
class BusinessOwner extends Model
{
    protected $fillable = [
        'business_id', 'surname', 'given_name', 'middle_name',
        'suffix', 'gender', 'is_primary',
    ];

    protected $casts = ['is_primary' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}

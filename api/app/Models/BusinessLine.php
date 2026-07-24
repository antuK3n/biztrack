<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessLine extends Model
{
    protected $fillable = ['business_id', 'psic_code_id', 'capitalization'];

    protected $casts = ['capitalization' => 'decimal:2'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function psicCode(): BelongsTo
    {
        return $this->belongsTo(PsicCode::class);
    }
}

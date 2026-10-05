<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeAssessment extends Model
{
    protected $fillable = [
        'application_id', 'line_items', 'total_amount', 'adjusted_by_user_id',
    ];

    protected $casts = [
        'line_items' => 'array',
        'total_amount' => 'decimal:2',
        // Read as a date by Numbering::taxOrderReference, which takes its year.
        'assessed_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

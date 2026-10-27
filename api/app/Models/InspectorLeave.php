<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Days an inspector is away: a visit is never suggested inside one. */
class InspectorLeave extends Model
{
    protected $fillable = ['inspector_id', 'starts_on', 'ends_on', 'reason'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Inspector::class);
    }
}

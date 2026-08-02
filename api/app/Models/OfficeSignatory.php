<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named officeholder who signs one department's forms.
 *
 * See the create_office_signatories_table migration for why these are rows and
 * not constants.
 */
class OfficeSignatory extends Model
{
    protected $fillable = ['department_id', 'role', 'name', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Only the people currently holding the post, in signature-block order. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('role');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PermitType extends Model
{
    protected $fillable = [
        'code', 'name', 'permit_number_prefix', 'issuing_department_id',
        'validity_days', 'description',
        'requires_inspection', 'base_fee', 'per_line_surcharge',
    ];

    protected $casts = [
        'requires_inspection' => 'boolean',
        'validity_days' => 'integer',
        'base_fee' => 'decimal:2',
        'per_line_surcharge' => 'decimal:2',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'issuing_department_id');
    }

    public function documentTypes(): BelongsToMany
    {
        return $this->belongsToMany(DocumentType::class, 'permit_type_requirements')
            ->withPivot('context', 'is_mandatory', 'notes');
    }
}

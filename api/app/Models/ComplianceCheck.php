<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCheck extends Model
{
    protected $fillable = [
        'application_assignment_id', 'application_document_id', 'label',
        'is_checked', 'note',
    ];

    protected $casts = ['is_checked' => 'boolean'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ApplicationAssignment::class, 'application_assignment_id');
    }
}

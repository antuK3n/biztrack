<?php

namespace App\Models;

use App\Support\AmendableFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requested change to one business detail.
 *
 * The request, not the change: nothing here touches the business until BPLO
 * approves the filing, at which point `WorkflowService::applyAmendments()`
 * writes the value and stamps `applied_at`. Until then this is what the
 * applicant asked for and what the officer is reading.
 *
 * Deliberately NOT a place for behaviour, for the same reason
 * `ApplicationPermitType` is not: the write is a workflow act with history and
 * a transaction around it, and a model method able to apply itself would be a
 * second door onto the register that skips both.
 */
class ApplicationAmendment extends Model
{
    protected $fillable = ['application_id', 'field', 'old_value', 'new_value', 'applied_at'];

    protected $casts = [
        'applied_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** "Floor area (sqm)" rather than `business_area_sqm`, for every reader. */
    public function label(): string
    {
        return AmendableFields::label($this->field);
    }

    /** Has this one been written to the business yet? */
    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }
}

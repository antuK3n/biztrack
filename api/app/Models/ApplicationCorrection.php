<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field an applicant put right after BPLO sent the filing back.
 *
 * Written at the moment the correction is saved, holding both halves — see the
 * migration for why "before" cannot be derived later. The officer reads these
 * to check the three fields they asked about instead of re-reading fifty.
 */
class ApplicationCorrection extends Model
{
    protected $fillable = ['application_id', 'target', 'old_value', 'new_value'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

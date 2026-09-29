<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A filing the applicant has started but that cannot be a draft yet.
 *
 * See the migration for why this exists rather than a laxer `businesses`. In
 * short: the register's validation is what makes a row there mean something,
 * and unfinished answers belong somewhere that means nothing.
 *
 * One row per user per application type, replaced on every save and deleted
 * the moment a real draft exists. Nothing but the wizard reads `payload`.
 */
class WizardDraft extends Model
{
    protected $fillable = ['user_id', 'application_type', 'title', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

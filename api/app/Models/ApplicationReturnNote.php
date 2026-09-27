<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What BPLO said about one returned field.
 *
 * Replaced as a set on every return — see the migration. The applicant reads
 * these beside the box for each field, so the instruction and the input sit
 * together instead of one paragraph covering three questions.
 */
class ApplicationReturnNote extends Model
{
    protected $fillable = ['application_id', 'target', 'note'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

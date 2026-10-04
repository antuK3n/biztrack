<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DocumentType extends Model
{
    /** The permit types that list this document among their requirements. */
    public function permitTypes(): BelongsToMany
    {
        return $this->belongsToMany(PermitType::class, 'permit_type_requirements');
    }

    protected $fillable = ['code', 'name', 'help_text'];
}

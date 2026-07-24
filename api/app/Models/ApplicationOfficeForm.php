<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** UI prototype Parts 4-7 — per-office form payload for one application/permit type. */
class ApplicationOfficeForm extends Model
{
    protected $fillable = ['application_id', 'permit_type_id', 'form_data'];

    protected $casts = ['form_data' => 'array'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }
}

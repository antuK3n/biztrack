<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    protected $fillable = [
        'application_id', 'document_type_id', 'permit_type_id', 'original_filename',
        'stored_path', 'mime_type', 'size_bytes',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * Set only on a certificate the applicant already holds and submitted in
     * place of applying for that clearance (checklist item 59). Null on every
     * ordinary documentary requirement.
     */
    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }
}

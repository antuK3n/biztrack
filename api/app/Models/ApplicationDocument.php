<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    protected $fillable = [
        'application_id', 'document_type_id', 'permit_type_id', 'original_filename',
        'stored_path', 'mime_type', 'size_bytes',
        /*
         * The sha256 of the stored file, written since 30 September 2026.
         *
         * The column has existed from the start and nothing wrote it, so it
         * had to be added here before it would persist — `update()` and
         * `create()` drop an unfillable key in silence, which is how
         * `returned_state` spent a day looking stored and being null.
         *
         * Read by DocumentController to refuse a second, byte-identical copy
         * of a requirement: two files nobody can tell apart are not history.
         */
        'file_hash',
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

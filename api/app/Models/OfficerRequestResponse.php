<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One applicant reply on an "Other Requirements" request; a request may have many. */
class OfficerRequestResponse extends Model
{
    protected $fillable = [
        'officer_request_id', 'user_id', 'body',
        'application_document_id', 'file_name', 'file_path',
        // The office's verdict ON THIS submission, so a requirement that goes
        // round twice can show why #1 was refused as well as what became of #2.
        'review_outcome', 'review_remarks', 'reviewed_at', 'reviewed_by_user_id',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function officerRequest(): BelongsTo
    {
        return $this->belongsTo(OfficerRequest::class);
    }

    /** Author of the reply (the applicant). */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'application_document_id');
    }
}

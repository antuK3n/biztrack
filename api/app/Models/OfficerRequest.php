<?php

namespace App\Models;

use App\Enums\OfficerRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficerRequest extends Model
{
    protected $fillable = [
        'application_id', 'requested_by_user_id', 'department_id', 'title',
        'description', 'request_type', 'status', 'due_date',
        // The note written when the requirement is RAISED, and the office's
        // optional reference file (a blank form, a template). Both are separate
        // from `remarks`/`file_path`, which travel the other way — see the
        // migration that added them.
        'additional_remarks', 'reference_path', 'reference_name',
        'file_name', 'file_path', 'application_document_id',
        'meeting_scheduled_at', 'meeting_duration_minutes', 'meeting_link',
        'meeting_platform', 'external_calendar_event_id',
        'applicant_response', 'submitted_at',
        'reviewed_by_user_id', 'reviewed_at', 'remarks',
    ];

    protected $casts = [
        'status' => OfficerRequestStatus::class,
        'due_date' => 'datetime',
        'meeting_scheduled_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** Officer who created the request (paper: requested_by_user_id). */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'application_document_id');
    }

    /**
     * Every applicant reply, oldest first. The parent's applicant_response /
     * submitted_at columns mirror the latest of these for v2-contract clients.
     */
    public function responses(): HasMany
    {
        return $this->hasMany(OfficerRequestResponse::class)->oldest('id');
    }
}

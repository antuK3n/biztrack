<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tracking_id', 'business_id', 'applicant_user_id', 'application_type',
        'status', 'prior_permit_id', 'submitted_at', 'deadline_at', 'decided_at',
        'rejection_reason',
    ];

    protected $casts = [
        'status' => ApplicationStatus::class,
        'application_type' => ApplicationType::class,
        'submitted_at' => 'datetime',
        'deadline_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    public function permitTypes(): BelongsToMany
    {
        return $this->belongsToMany(PermitType::class, 'application_permit_types');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderBy('created_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ApplicationAssignment::class);
    }

    public function feeAssessment(): HasOne
    {
        return $this->hasOne(FeeAssessment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function permits(): HasMany
    {
        return $this->hasMany(Permit::class);
    }

    public function priorPermit(): BelongsTo
    {
        return $this->belongsTo(Permit::class, 'prior_permit_id');
    }

    public function messageThread(): HasOne
    {
        return $this->hasOne(MessageThread::class);
    }

    public function officerRequests(): HasMany
    {
        return $this->hasMany(OfficerRequest::class);
    }
}

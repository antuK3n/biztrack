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
        'tracking_id', 'business_id', 'applicant_user_id', 'application_type', 'title',
        'status', 'prior_permit_id', 'submitted_at', 'deadline_at', 'decided_at',
        // Set at submission from Support\Ra11032. Absent from this list, mass
        // assignment drops the tier in silence, and the RA 11032 panel — which
        // requires a non-null complexity — cannot see the filing at all.
        'complexity',
        /*
         * WHO set the tier, and when. Null means it came from
         * `Support\Ra11032::tierFor()` — our rule, not the LGU's published
         * classification (open question A10) — which is what the review sheet
         * shows an officer so they know they are overriding a guess rather
         * than filling in a blank. WorkflowService::classify() is the only
         * writer; see the migration for the whole argument.
         */
        'complexity_set_by_user_id', 'complexity_set_at',
        'rejection_reason', 'fee_profile', 'payment_mode',
        /*
         * The paper BPLO form's "Amendment from:" block (checklist items 82/84).
         * The manuscript-alignment migration created these columns and nothing
         * ever wrote them, so /apply?type=amendment was the new-application
         * wizard with a different title — it never asked the one question that
         * makes a filing an amendment. Mass assignment is how the controller
         * fills them, so they have to be listed or the answer is dropped in
         * silence exactly as `complexity` was above.
         *
         * `has_amendments` is derived, never taken from the client: it is a
         * summary of the other four and a caller that could set it independently
         * could claim an amendment amending nothing.
         */
        'has_amendments', 'amendment_ownership', 'amendment_location',
        'amendment_nature', 'amendment_other',
    ];

    protected $casts = [
        'status' => ApplicationStatus::class,
        'application_type' => ApplicationType::class,
        'submitted_at' => 'datetime',
        'deadline_at' => 'datetime',
        'decided_at' => 'datetime',
        'complexity_set_at' => 'datetime',
        'fee_profile' => 'array',
        // Without these, SQLite hands back 0/1 and the JSON payload says
        // `"amendment_ownership": 1`, which the officer screen renders as a
        // number rather than a ticked box.
        'has_amendments' => 'boolean',
        'amendment_ownership' => 'boolean',
        'amendment_location' => 'boolean',
        'amendment_nature' => 'boolean',
    ];

    /**
     * The amendment kinds ticked on this filing, as the paper form's four
     * checkboxes. "Others" is the free text itself — a specified other IS the
     * tick, which is why there is no fifth boolean column to keep in step
     * with it.
     *
     * @return array<int, string>
     */
    public function amendmentKinds(): array
    {
        return array_values(array_filter([
            $this->amendment_ownership ? 'Ownership' : null,
            $this->amendment_location ? 'Location' : null,
            $this->amendment_nature ? 'Nature of Business' : null,
            filled($this->amendment_other) ? 'Others: '.$this->amendment_other : null,
        ]));
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    /**
     * The officer who set the RA 11032 tier, if a person set it at all.
     *
     * Null is the ordinary case and means the tier was classified
     * automatically at submission. `withTrashed` is deliberate: `User`
     * soft-deletes and its filings outlive it, and a reclassification whose
     * author has since left the LGU is still a reclassification a person made
     * — resolving to null there would silently relabel it as automatic, which
     * is the one thing this relation exists to prevent.
     */
    public function complexitySetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'complexity_set_by_user_id')->withTrashed();
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

    public function officeForms(): HasMany
    {
        return $this->hasMany(ApplicationOfficeForm::class);
    }
}

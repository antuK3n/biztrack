<?php

namespace App\Models;

use App\Enums\PermitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permit extends Model
{
    protected $fillable = [
        'permit_number', 'application_id', 'business_id', 'permit_type_id',
        'prior_permit_id',
        'status', 'valid_from', 'valid_until', 'pdf_path', 'issued_at',
        'issued_by_user_id',
    ];

    protected $casts = [
        'status' => PermitStatus::class,
        'valid_from' => 'date',
        'valid_until' => 'date',
        'issued_at' => 'datetime',
    ];

    /**
     * Write the renewal chain at issuance, from the filing that earned it.
     *
     * A permit issued by a renewal succeeds exactly one permit, and the
     * application already knows which: the applicant named it in the picker,
     * and it is sitting on `applications.prior_permit_id`. Until this column
     * existed nobody copied it across, so continuity between two certificates
     * had to be INFERRED afterwards from (business, permit_type, dates) —
     * RenewalOutcomes still does that, and its late/on-time verdict is what
     * fits the renewal model. An inference is fine right up until a business
     * holds two same-type permits at once, which is what renewing late looks
     * like, and then it is a guess feeding a regression.
     *
     * This lives on the model rather than in WorkflowService::approve because
     * `Permit::create()` is not the only writer — DemoSeeder and
     * AnalyticsHistorySeeder both mint permits directly, and a chain that only
     * holds for one caller is the same half-kept invariant that produced the
     * seven renewals of nothing. Putting it here means every writer gets it,
     * including ones not written yet.
     *
     * An explicitly-passed `prior_permit_id` always wins: this only fills a
     * blank. And the predecessor must belong to the same business, because a
     * cross-business link is worse than no link at all — analytics would read
     * it as one shop's continuous history when it is two shops.
     */
    protected static function booted(): void
    {
        static::creating(function (self $permit): void {
            if ($permit->prior_permit_id !== null || $permit->application_id === null) {
                return;
            }

            $prior = Application::query()
                ->whereKey($permit->application_id)
                ->value('prior_permit_id');

            if ($prior === null) {
                return;
            }

            $sameBusiness = self::query()
                ->whereKey($prior)
                ->where('business_id', $permit->business_id)
                ->exists();

            if ($sameBusiness) {
                $permit->prior_permit_id = $prior;
            }
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** The permit this one renewed, once the chain is explicit. */
    public function priorPermit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_permit_id');
    }

    /** The permits issued as renewals of this one. */
    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'prior_permit_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }

    /** Days until expiry (negative if already past). */
    public function daysUntilExpiry(): int
    {
        return now()->startOfDay()->diffInDays($this->valid_until, false);
    }
}

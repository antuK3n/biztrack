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
     *
     * ── It must be the same PERMIT TYPE, and that was missing ────────────────
     *
     * This read `applications.prior_permit_id` — the PRIMARY of the renewal —
     * and stamped it on every permit the filing issued, checking same-business
     * and nothing else. That held while a renewal meant one permit. It stopped
     * holding the moment a renewal could cover several, and on 9 September 2026
     * a two-permit renewal produced:
     *
     *     NEW SANITARY  MCS-2026-000001  prior = SANITARY MCS-2025-000770  ✓
     *     NEW ZONING    MCZ-2026-000001  prior = SANITARY MCS-2025-000770  ✗
     *
     * A zoning certificate declaring it succeeds a sanitary one. Worse than a
     * missing link and for the same reason as the cross-business case: nothing
     * downstream can tell it is wrong. RenewalOutcomes reads the chain to
     * decide whether a renewal was late, so the model was being fitted on a
     * comparison between two different permits' dates.
     *
     * So the predecessor is chosen from the FULL ticked set
     * (`applications.prior_permits`) by matching permit type, and the primary is
     * used only when it happens to be the right type. A renewal that covers a
     * permit the business did not previously hold correctly gets no link.
     */
    protected static function booted(): void
    {
        static::creating(function (self $permit): void {
            if ($permit->prior_permit_id !== null || $permit->application_id === null) {
                return;
            }

            $application = Application::query()
                ->with('priorPermits:id,business_id,permit_type_id')
                ->find($permit->application_id);

            if ($application === null) {
                return;
            }

            $match = fn (?self $candidate) => $candidate !== null
                && $candidate->business_id === $permit->business_id
                && $candidate->permit_type_id === $permit->permit_type_id;

            // The whole ticked set first — it is the complete answer, and the
            // primary is only its first element.
            $prior = $application->priorPermits->first($match);

            // Then the primary on its own, for a filing saved before the set
            // existed. Still type-checked: an unmatched primary is left alone.
            if ($prior === null && $application->prior_permit_id !== null) {
                $candidate = self::query()
                    ->select('id', 'business_id', 'permit_type_id')
                    ->find($application->prior_permit_id);
                $prior = $match($candidate) ? $candidate : null;
            }

            if ($prior !== null) {
                $permit->prior_permit_id = $prior->id;
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

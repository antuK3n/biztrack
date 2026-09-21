<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A permit fee incurred outside the January season, waiting to be collected.
 *
 * The client's rule, 17 September 2026: a clearance renewed in June is issued
 * and *not* billed; its fee appears on the next business-permit renewal's Tax
 * Order of Payment. See the migration that created the table for why a
 * `fee_assessment` could not express that, and docs/renewal-2026-09-17.md for
 * the decision in full.
 *
 * ── Two columns for two facts, and they are not the same moment ────────────
 *
 * `billed_on_application_id` is CLAIMED: an assessment has put this fee on its
 * bill. `billed_at` is COLLECTED: that bill has been paid. They are separate
 * because an unpaid January renewal must not leave the fee free to be swept
 * onto a second one — two filings would each show it and the LGU would collect
 * it twice or not at all — and because a filing that is cancelled has to give
 * the fee back.
 */
class UnbilledPermitFee extends Model
{
    protected $fillable = [
        'business_id', 'application_id', 'permit_type_id', 'amount',
        // What the line says on the January bill when the permit type is not
        // the answer — an amendment carries the business permit type but is
        // not a business permit fee. Null on every clearance row.
        'description',
        'incurred_at', 'billed_on_application_id', 'billed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'incurred_at' => 'datetime',
        'billed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** The filing that incurred the fee — the June renewal, in the example. */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function permitType(): BelongsTo
    {
        return $this->belongsTo(PermitType::class);
    }

    /** The filing that is collecting it — the January renewal. */
    public function billedOnApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'billed_on_application_id');
    }

    /**
     * Fees no bill has claimed yet — what a new assessment may sweep.
     *
     * Keyed on the CLAIM and not on `billed_at`, which is the distinction that
     * makes double-billing impossible: a fee sitting on an unpaid January
     * renewal is not available to a second filing, even though nobody has paid
     * it.
     */
    public function scopeUnclaimed(Builder $query): Builder
    {
        return $query->whereNull('billed_on_application_id');
    }

    /**
     * Fees the business still owes — claimed or not, as long as unpaid.
     *
     * This is the figure for the admin's arrears view and the business record.
     * It deliberately includes a fee already sitting on an unpaid bill, because
     * from the LGU's side the money is equally outstanding either way.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('billed_at');
    }
}

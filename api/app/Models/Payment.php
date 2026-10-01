<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay a Tax Order of Payment.
 *
 * `gateway` says which path made it (docs/payment-gateway.md). A simulated
 * payment is born completed. A KwikPay one is born pending and stays so until
 * KwikPay confirms it. The `order_id` sent to KwikPay is `gateway_order_id`:
 * the PAY- reference plus a random tail, plain ASCII with no spaces, & or =
 * (docs FAQ) — see the migration for why the reference alone is not enough.
 */
class Payment extends Model
{
    public const GATEWAY_SIMULATED = 'simulated';

    public const GATEWAY_KWIKPAY = 'kwikpay';

    protected $fillable = [
        'application_id', 'fee_assessment_id', 'reference_number', 'amount', 'gateway_amount',
        'method', 'status', 'receipt_path', 'paid_at',
        'gateway', 'gateway_order_id', 'pay_url', 'pay_url_kind', 'next_check_at', 'check_attempts',
        'flagged_at', 'gateway_note', 'abandoned_at', 'refund_review_at',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'method' => PaymentMethod::class,
        'amount' => 'decimal:2',
        'gateway_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'next_check_at' => 'datetime',
        'flagged_at' => 'datetime',
        'abandoned_at' => 'datetime',
        'refund_review_at' => 'datetime',
        'check_attempts' => 'integer',
    ];

    /*
     * A model default as well as the column default: `Payment::create()` does
     * not read the row back, so without this a freshly created simulated
     * payment would carry a null gateway in memory until refreshed.
     */
    protected $attributes = [
        'gateway' => self::GATEWAY_SIMULATED,
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function isKwikPay(): bool
    {
        return $this->gateway === self::GATEWAY_KWIKPAY;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    /** KwikPay payments nobody has confirmed either way yet. */
    public function scopeAwaitingKwikPay(Builder $query): Builder
    {
        return $query->where('gateway', self::GATEWAY_KWIKPAY)
            ->where('status', PaymentStatus::Pending->value);
    }
}

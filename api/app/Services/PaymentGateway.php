<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\FeeAssessment;
use App\Models\Payment;
use App\Support\Numbering;

/**
 * Simulated payment gateway (master plan §5.5, guardrail §9.2). Auto-completes
 * with a PAY- reference. No card fields, no external SDK.
 *
 * Used while App\Support\PaymentMode is 'simulated' — the default, and what a
 * presentation runs on. The real gateway sits beside it rather than behind the
 * same method (App\Services\KwikPay\KwikPayGateway), because it cannot keep
 * this method's promise: a KwikPay payment is not complete when the call
 * returns, and a caller that assumed it was would move the application on
 * before any money moved. "Swappable with no schema change" was the plan once;
 * a real gateway needed a pending state with somewhere to pay, so it took a
 * migration (2026_09_29_000100).
 */
class PaymentGateway
{
    /**
     * @param  float|null  $amount  What to take now. Null charges the whole
     *                              assessment, which is right for a first
     *                              payment and wrong for every one after it.
     *                              Once a clearance can be applied for after
     *                              payment the assessment total grows, and only
     *                              the unpaid part is owed — charging the total
     *                              again would bill the applicant a second time
     *                              for what they had already settled.
     */
    public function charge(FeeAssessment $fee, PaymentMethod $method, ?float $amount = null): Payment
    {
        return Payment::create([
            'application_id' => $fee->application_id,
            'fee_assessment_id' => $fee->id,
            'reference_number' => Numbering::paymentReference(),
            'amount' => $amount ?? $fee->total_amount,
            'method' => $method,
            'status' => PaymentStatus::Completed, // simulated: instant success
            'paid_at' => now(),
        ]);
    }
}

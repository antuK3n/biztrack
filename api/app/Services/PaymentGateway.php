<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\FeeAssessment;
use App\Models\Payment;
use App\Support\Numbering;

/**
 * Simulated payment gateway (master plan §5.5, guardrail §9.2). Auto-completes
 * with a PAY- reference. One interface, swappable for a real PCI-DSS gateway
 * later with no schema change. No card fields, no external SDK.
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

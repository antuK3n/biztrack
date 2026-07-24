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
    public function charge(FeeAssessment $fee, PaymentMethod $method): Payment
    {
        return Payment::create([
            'application_id' => $fee->application_id,
            'fee_assessment_id' => $fee->id,
            'reference_number' => Numbering::paymentReference(),
            'amount' => $fee->total_amount,
            'method' => $method,
            'status' => PaymentStatus::Completed, // simulated: instant success
            'paid_at' => now(),
        ]);
    }
}

<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Application;

/**
 * What a filing has been assessed, what it has actually paid, and the gap.
 *
 * One assessment, one payment (docs/clearances-before-payment.md). Everything
 * including the six LGU clearances is decided before submission, `assessFees`
 * runs once at submit over exactly those permit types, and the applicant pays
 * that figure to move the filing into review.
 *
 * Three things used to live here and are deliberately gone:
 *
 *   `hasClearedPayment()` answered "may the clearance stage open?", which was
 *   the whole of the after-payment design and is now the wrong question — the
 *   stage opens while the filing is a draft (ClearanceService::isUnlocked).
 *
 *   `hasOutstandingBalance()` and the EPSILON that gave it a centavo's
 *   tolerance existed for the gate in WorkflowService::approveAndIssue, which
 *   held the permit until a clearance balance cleared. No balance can appear
 *   behind a single payment, so the gate went and these went with it.
 *
 * What is left is a plain statement of the ledger. PaymentController charges
 * `balance_due` rather than the assessment total — with one payment they are
 * the same number, and charging what is owed is correct either way.
 */
final class PermitFees
{
    /** @return array{total_assessed: float, total_paid: float, balance_due: float} */
    public static function balance(Application $application): array
    {
        // No assessment yet means nothing has been charged — a draft, not a
        // debt. `?? 0` rather than a guard so the shape is always the same.
        $assessed = (float) ($application->feeAssessment?->total_amount ?? 0);

        /*
         * Completed only. A pending payment is an intention and a failed or
         * refunded one is money the LGU does not hold; counting either would
         * release a permit against a payment that never cleared, which is the
         * exact thing the balance exists to prevent.
         */
        $paid = (float) $application->payments()
            ->where('status', PaymentStatus::Completed->value)
            ->sum('amount');

        // Never negative: an overpayment (an officer adjusting the assessment
        // down after the fact) is not a credit the applicant can spend here,
        // and a negative "balance due" reads as an error to anyone seeing it.
        $due = max(0.0, round($assessed - $paid, 2));

        return [
            'total_assessed' => round($assessed, 2),
            'total_paid' => round($paid, 2),
            'balance_due' => $due,
        ];
    }

    /** Peso rendering for messages the applicant reads. */
    public static function peso(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }
}

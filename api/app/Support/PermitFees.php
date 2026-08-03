<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Application;

/**
 * What a filing has been assessed, what it has actually paid, and the gap.
 *
 * One ledger, two moments (docs/clearances-after-payment.md §"Decisions taken
 * to keep moving"): the business permit is paid to submit, and every clearance
 * applied for afterwards re-assesses into the same FeeAssessment row. So the
 * assessment is a running total, not a snapshot — and the only honest way to
 * know whether anything is still owed is to subtract what has cleared.
 *
 * This lives in Support rather than on either service because two callers with
 * opposite jobs need the same number: ClearanceService reports it to the
 * applicant, and WorkflowService refuses to issue a permit while it is
 * positive. Putting it on one of them would have made the other depend on it
 * for a three-line sum.
 */
final class PermitFees
{
    /**
     * Peso amounts are compared with a tolerance because both sides are
     * decimal(14,2) round-tripped through PHP floats: a balance of 0.000000001
     * is a paid filing, not an outstanding one, and half a centavo is not a
     * debt any cashier can settle.
     */
    public const EPSILON = 0.005;

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

    /** True when this filing still owes money. */
    public static function hasOutstandingBalance(Application $application): bool
    {
        return self::balance($application)['balance_due'] > self::EPSILON;
    }

    /** True once any payment on this filing has actually cleared. */
    public static function hasClearedPayment(Application $application): bool
    {
        return $application->payments()
            ->where('status', PaymentStatus::Completed->value)
            ->exists();
    }

    /** Peso rendering for messages the applicant reads. */
    public static function peso(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }
}

<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Application;

/**
 * What a filing has been assessed, what it has actually paid, and the gap.
 *
 * One ledger, two moments (docs/clearances-after-payment.md). The business
 * permit alone is assessed at submit and paid to move the filing into review;
 * each LGU clearance applied for afterwards re-assesses onto the SAME
 * FeeAssessment row, so the difference between what is assessed and what has
 * cleared is a real, growing debt the applicant settles before any permit is
 * released.
 *
 * That is why all three of these are here rather than inlined at their callers:
 *
 *   `balance()` is the figure the clearance screen shows and the figure
 *   PaymentController charges. It charges `balance_due`, never the assessment
 *   total, because on a second payment the total is money some of which the
 *   applicant has already handed over.
 *
 *   `hasClearedPayment()` answers "may the clearance stage open?"
 *   (ClearanceService::isUnlocked). Derived from the payments ledger rather
 *   than from a `clearances_unlocked` column, because a flag and a ledger drift
 *   and only one of them is the money.
 *
 *   `hasOutstandingBalance()` is the release gate
 *   (WorkflowService::approveAndIssue and isFullyCleared). EPSILON exists
 *   because the assessment and the payments are decimal(14,2) read back as
 *   floats: without it a filing paid to the centavo can carry a balance of
 *   0.0000000001 and its permits are never released, which is the worst
 *   possible failure for a gate whose whole job is to be exactly right about
 *   zero.
 */
final class PermitFees
{
    /**
     * A centavo's tolerance. Anything at or below this is "settled" — see the
     * class docblock for why the gate must not compare floats to 0.0.
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

    /**
     * Has this filing's FIRST payment cleared? The clearance stage's key.
     *
     * "A completed payment exists", and deliberately not "the status has moved
     * past pending_payment". The two agree on the happy path — payment is what
     * moves a filing into review — but they part company on exactly the rows
     * that matter. A filing an officer rejected at `pending_payment` never paid
     * and must not open the stage; a filing returned for revision after payment
     * has paid and must keep it open. Status is where the filing is; the ledger
     * is whether the LGU holds the money, and the money is the question.
     *
     * Completed only, for the same reason balance() counts only completed
     * payments: a pending charge is an intention and a failed one is money the
     * LGU does not hold, and either would open a chargeable stage against a
     * payment that never landed.
     */
    public static function hasClearedPayment(Application $application): bool
    {
        return $application->payments()
            ->where('status', PaymentStatus::Completed->value)
            ->exists();
    }

    /**
     * Does this filing still owe money? The release gate's question.
     *
     * Rule 6 of docs/clearances-after-payment.md: "the permit is not released
     * while a balance is outstanding — this is the gate that makes the accrual
     * real; without it the balance is decoration." A clearance applied for
     * after the first payment adds its office's lines to the assessment, and
     * nothing but this stops the applicant collecting a Mayor's Permit over an
     * unpaid Fire Safety Inspection fee.
     */
    public static function hasOutstandingBalance(Application $application): bool
    {
        return self::balance($application)['balance_due'] > self::EPSILON;
    }

    /** Peso rendering for messages the applicant reads. */
    public static function peso(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }
}

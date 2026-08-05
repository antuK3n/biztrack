<?php

namespace App\Support;

use App\Enums\PaymentStatus;
use App\Models\Application;

final class PermitFees
{
    public static function balance(Application $application): array
    {
        $assessed = (float) ($application->feeAssessment?->total_amount ?? 0);

        $paid = (float) $application->payments()
            ->where('status', PaymentStatus::Completed->value)
            ->sum('amount');

        $due = max(0.0, round($assessed - $paid, 2));

        return [
            'total_assessed' => round($assessed, 2),
            'total_paid' => round($paid, 2),
            'balance_due' => $due,
        ];
    }

    public static function peso(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }
}

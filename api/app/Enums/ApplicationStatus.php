<?php

namespace App\Enums;

/** applications.status machine (master plan §6.2). */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PendingPayment = 'pending_payment';
    case UnderReview = 'under_review';
    case ForInspection = 'for_inspection';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::PendingPayment => 'Awaiting payment',
            self::UnderReview => 'Under review',
            self::ForInspection => 'For inspection',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Returned => 'Returned for revision',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Terminal states cannot transition further. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Cancelled], true);
    }
}

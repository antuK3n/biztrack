<?php

namespace App\Enums;

/** officer_requests.status lifecycle: pending → submitted → fulfilled|rejected. */
enum OfficerRequestStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Fulfilled = 'fulfilled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Fulfilled => 'Fulfilled',
            self::Rejected => 'Rejected',
        };
    }
}

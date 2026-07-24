<?php

namespace App\Enums;

enum InspectionStatus: string
{
    case Scheduled = 'scheduled';
    case Rescheduled = 'rescheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Rescheduled => 'Rescheduled',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}

<?php

namespace App\Enums;

/** Set at completion; `conditional` progresses like a pass. */
enum InspectionResult: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Conditional = 'conditional';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Conditional => 'Passed with conditions',
        };
    }

    public function progresses(): bool
    {
        return $this === self::Passed || $this === self::Conditional;
    }
}

<?php

namespace App\Enums;

enum ApplicationType: string
{
    case New = 'new';
    case Renewal = 'renewal';
    case Amendment = 'amendment';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Renewal => 'Renewal',
            self::Amendment => 'Amendment',
        };
    }
}

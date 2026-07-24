<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Gcash = 'gcash';
    case Maya = 'maya';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Gcash => 'GCash',
            self::Maya => 'Maya',
            self::Card => 'Credit / Debit Card',
        };
    }
}

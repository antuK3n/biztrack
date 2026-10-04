<?php

namespace App\Enums;

/**
 * How the owner chose to pay.
 *
 * Which of these are OFFERED depends on the payment mode (App\Support\PaymentMode):
 * simulated offers GCash, Maya and Card, as it always has; KwikPay offers GCash,
 * Maya, QR Ph and GoTyme — its deposit channels (merchant docs §8) less USDT.
 * Card exists only in simulated mode because KwikPay has no card channel.
 */
enum PaymentMethod: string
{
    case Gcash = 'gcash';
    case Maya = 'maya';
    case Card = 'card';
    case Qrph = 'qrph';
    case Gotyme = 'gotyme';

    public function label(): string
    {
        return match ($this) {
            self::Gcash => 'GCash',
            self::Maya => 'Maya',
            self::Card => 'Credit / Debit Card',
            self::Qrph => 'QR Ph',
            self::Gotyme => 'GoTyme',
        };
    }

    /**
     * KwikPay's `bank_code` for this channel (merchant docs §8, "Deposit Bank
     * Codes"). Null where KwikPay has no such channel.
     */
    public function kwikpayBankCode(): ?string
    {
        return match ($this) {
            self::Gcash => 'gcash',
            self::Maya => 'PMP',
            self::Qrph => 'qrph',
            self::Gotyme => 'GOT',
            self::Card => null,
        };
    }

    /** @return list<self> */
    public static function forMode(string $mode): array
    {
        return $mode === 'kwikpay'
            ? [self::Gcash, self::Maya, self::Qrph, self::Gotyme]
            : [self::Gcash, self::Maya, self::Card];
    }
}

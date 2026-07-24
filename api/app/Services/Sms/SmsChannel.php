<?php

namespace App\Services\Sms;

/**
 * Outbound SMS abstraction (master plan §5.5). One interface, swappable for a
 * real gateway (Twilio/Semaphore) later with no call-site change.
 */
interface SmsChannel
{
    public function send(string $to, string $message): void;
}

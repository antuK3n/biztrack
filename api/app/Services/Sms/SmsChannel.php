<?php

namespace App\Services\Sms;

/**
 * Outbound SMS (master plan §5.5), chosen by SMS_DRIVER. send() throws when
 * the text did not go, so the job sending it can retry.
 */
interface SmsChannel
{
    public function send(string $to, string $message): void;
}

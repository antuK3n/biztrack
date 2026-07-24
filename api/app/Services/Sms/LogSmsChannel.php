<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Simulated SMS driver: writes to storage/logs/sms.log instead of dialing a real
 * gateway. Guardrail §9.2 — no external SDK. Generic payloads only.
 */
class LogSmsChannel implements SmsChannel
{
    public function send(string $to, string $message): void
    {
        Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/sms.log'),
        ])->info('SMS', ['to' => $to, 'message' => $message]);
    }
}

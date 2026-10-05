<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real SMS through an Android phone running SMS Gateway for Android
 * (sms-gate.app) in Cloud Server mode. `SMS_DRIVER=smsgate`.
 *
 * ── Why a phone ─────────────────────────────────────────────────────────────
 *
 * Paid providers were too expensive for the team, so Ken chose a spare phone
 * with an unli-text SIM [5 October 2026]. The app keeps a connection open to
 * api.sms-gate.app; we post to that server and the phone sends the text. The
 * request and its HTTP Basic credentials are the ones docs.sms-gate.app gives:
 * `{"textMessage": {"text": …}, "phoneNumbers": ["+639…"]}`.
 *
 * ── Failure ─────────────────────────────────────────────────────────────────
 *
 * Anything but a 2xx throws, and so does a timeout. That is deliberate: this
 * runs inside App\Jobs\SendSms, whose retries only happen when send() throws.
 * Nothing here is ever called inside an officer's request.
 *
 * Missing credentials are the one failure that does not throw. A retry cannot
 * fix them, so it is logged once and nothing is sent.
 */
class SmsGateChannel implements SmsChannel
{
    public function __construct(
        private string $url,
        private ?string $username,
        private ?string $password,
        private int $timeout = 10,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.sms.gateway.url'),
            config('services.sms.gateway.username'),
            config('services.sms.gateway.password'),
            (int) config('services.sms.gateway.timeout', 10),
        );
    }

    public function configured(): bool
    {
        return filled($this->username) && filled($this->password);
    }

    public function send(string $to, string $message): void
    {
        if (! $this->configured()) {
            Log::warning('SMS_DRIVER is smsgate but SMS_GATEWAY_USERNAME or SMS_GATEWAY_PASSWORD is not set. No SMS was sent.');

            return;
        }

        Http::withBasicAuth((string) $this->username, (string) $this->password)
            ->acceptJson()
            ->timeout($this->timeout)
            ->post($this->url, [
                'textMessage' => ['text' => $message],
                'phoneNumbers' => [$to],
            ])
            ->throw();
    }
}

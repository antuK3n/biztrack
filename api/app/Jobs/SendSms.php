<?php

namespace App\Jobs;

use App\Services\Sms\SmsChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one text through the configured SMS driver, off the request.
 *
 * Queued for the reason SendOwnerUpdateEmail is: the gateway is a phone on a
 * mobile network, and it can be slow, asleep or out of signal. The officer's
 * action has been recorded by the time this runs and must never wait on the
 * phone or fail because of it. Three attempts a few minutes apart ride out a
 * phone that is briefly offline.
 *
 * The number arrives already in +639 form (PhMobile) and the text is the one
 * the notice wrote, so nothing is looked up again when the worker runs.
 */
class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [60, 300];

    public function __construct(
        public string $to,
        public string $message,
        public string $kind,
        public ?int $userId = null,
    ) {}

    public function handle(SmsChannel $sms): void
    {
        $sms->send($this->to, $this->message);
    }

    /** Out of retries. Logged without the text; the notice stands regardless. */
    public function failed(?Throwable $e): void
    {
        Log::warning('SMS could not be sent', [
            'user_id' => $this->userId,
            'kind' => $this->kind,
            'error' => $e?->getMessage(),
        ]);
    }
}

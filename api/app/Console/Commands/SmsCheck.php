<?php

namespace App\Console\Commands;

use App\Services\Sms\LogSmsChannel;
use App\Services\Sms\SmsChannel;
use App\Services\Sms\SmsGateChannel;
use App\Support\PhMobile;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Text one number, now, through the configured SMS driver, and say whether it
 * went.
 *
 * For the server once the gateway phone is set up: it answers "are the
 * SMS_GATEWAY_* lines right and is the phone's account live" without filing
 * and rejecting an application to provoke a text. It sends synchronously, past
 * the queue, so the answer is the gateway's own, and on failure prints the
 * gateway's status and reply. Never the credentials.
 */
class SmsCheck extends Command
{
    protected $signature = 'biztrack:sms-check {number : A Philippine mobile number, as in 09171234567}';

    protected $description = 'Send "BizTrack: SMS is working." to one number through the configured SMS driver and report the result';

    public function handle(SmsChannel $sms): int
    {
        $this->line('driver:  '.config('services.sms.driver'));

        $to = PhMobile::e164($this->argument('number'));
        if ($to === null) {
            $this->error('That is not a Philippine mobile number (11 digits, starting 09). Nothing was sent.');

            return self::FAILURE;
        }

        if ($sms instanceof SmsGateChannel && ! $sms->configured()) {
            $this->warn('SMS_GATEWAY_USERNAME or SMS_GATEWAY_PASSWORD is not set. Nothing was sent.');

            return self::FAILURE;
        }

        try {
            $sms->send($to, 'BizTrack: SMS is working.');
        } catch (RequestException $e) {
            $this->error('gateway: HTTP '.$e->response->status().' '.Str::limit(trim($e->response->body()), 300));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('gateway: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($sms instanceof LogSmsChannel
            ? "logged:  {$to} in storage/logs/sms.log. SMS_DRIVER is log, so no text was sent."
            : "sent:    the gateway accepted the text to {$to}.");

        return self::SUCCESS;
    }
}

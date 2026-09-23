<?php

namespace App\Jobs;

use App\Mail\ApplicationDisapproved;
use App\Models\Application;
use App\Services\Sms\SmsChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells the owner, outside BizTrack, that their application was disapproved.
 *
 * ── Why queued, and why one job per channel ────────────────────────────────
 *
 * The client asked for e-mail and SMS on a disapproval (23 September 2026), on
 * top of the in-app notice. Both talk to something outside this process, and a
 * slow or failing gateway must never undo or stall the decision itself — the
 * officer's click has already been recorded by the time this runs. Queued, the
 * decision request returns without waiting on either.
 *
 * One job per channel so each retries on its own. A mail server that is down
 * should not also cost the owner their SMS, and a retried SMS should not send
 * the e-mail twice.
 *
 * ── Which number ───────────────────────────────────────────────────────────
 *
 * The account holder's own mobile first — that is the person who filed and who
 * signs in — and the BUSINESS's mobile (BPLO item A7, on `business_addresses`)
 * when the account has none. Without the fallback an owner who registered
 * without a number would get no SMS even though their filing gave us one.
 */
class SendDisapprovalNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [60, 300];

    public function __construct(
        public Application $application,
        public ?string $reason,
        public string $channel,   // 'mail' | 'sms'
    ) {}

    public function handle(SmsChannel $sms): void
    {
        $app = $this->application->loadMissing('applicant', 'business.address');
        $owner = $app->applicant;
        if (! $owner) {
            return;   // soft-deleted account: nobody left to tell
        }

        if ($this->channel === 'mail') {
            if ($owner->email) {
                Mail::to($owner->email)->send(new ApplicationDisapproved($app, $this->reason));
            }

            return;
        }

        $to = self::mobileFor($app);
        if ($to === null) {
            return;
        }

        /*
         * The reason is in the SMS too, shortened: an owner who reads the text
         * and never opens the e-mail should still learn why. Capped so one
         * officer's long paragraph does not become six billed messages.
         */
        $message = "BizTrack: {$app->tracking_id} was disapproved."
            .($this->reason ? ' Reason: '.Str::limit($this->reason, 120) : '')
            .' Open BizTrack for details.';

        $sms->send($to, $message);
    }

    /** The account's mobile, else the business's own. Null when neither exists. */
    public static function mobileFor(Application $app): ?string
    {
        $app->loadMissing('applicant', 'business.address');

        $number = $app->applicant?->mobile_number ?: $app->business?->address?->mobile_number;

        return $number ?: null;
    }

    /** Out of retries. Logged; the decision stands regardless. */
    public function failed(?Throwable $e): void
    {
        Log::warning('Disapproval notice could not be sent', [
            'application_id' => $this->application->id,
            'channel' => $this->channel,
            'error' => $e?->getMessage(),
        ]);
    }
}

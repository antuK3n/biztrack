<?php

namespace App\Jobs;

use App\Mail\OwnerUpdate;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mails a business owner the in-app notice they have just been given.
 *
 * ── Why every update, and why from one place ───────────────────────────────
 *
 * The assignment (Ken, "Email/Text Integration", 24 September 2026) is that an
 * owner hears about EVERY change to their filing by e-mail, not only a
 * disapproval. Every such change already produces exactly one in-app notice
 * through NotificationService::push(), so that is where this is dispatched
 * from: one hook, instead of a mail call next to each of the thirty-odd places
 * in the workflow that tell the owner something. A new owner notice added later
 * e-mails on its own; one that forgets to is impossible to write.
 *
 * The earlier disapproval-only version (branch ken/feedback-2026-09-23, commit
 * 41ec2e5, SendDisapprovalNotice) is where the queue, retry and failure
 * isolation below come from. Its SMS half was deliberately not brought over:
 * SMS is out of scope for now, and the log SMS driver in NotificationService's
 * fan-out is left as it was.
 *
 * ── Why queued ──────────────────────────────────────────────────────────────
 *
 * The mail server is outside this process and can be slow or down. The
 * officer's decision has been recorded by the time this runs; a failing SMTP
 * relay must neither stall that request nor undo it. Queued, the action returns
 * without waiting, and three attempts a few minutes apart ride out a brief
 * outage or Brevo's per-second throttle.
 *
 * ── Why the content is copied in, not looked up ───────────────────────────
 *
 * Title, body, reference and business name are captured when the notice is
 * written. A business can be soft-deleted and a filing's status can move again
 * before the worker gets to this, and the e-mail should say what the in-app
 * notice said, not something re-derived later. Only the recipient is a model:
 * if the account is gone by then there is nobody to tell, and
 * $deleteWhenMissingModels drops the job quietly instead of failing it.
 */
class SendOwnerUpdateEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [60, 300];

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public User $owner,
        public string $title,
        public string $body,
        public ?string $link = null,
        public ?string $reference = null,
        public ?string $businessName = null,
        public bool $isDisapproval = false,
    ) {}

    public function handle(): void
    {
        if (! $this->owner->email) {
            return;
        }

        Mail::to($this->owner->email, $this->owner->fullName() ?: $this->owner->name)->send(new OwnerUpdate(
            recipientName: $this->owner->first_name ?: null,
            title: $this->title,
            body: $this->body,
            url: self::siteUrl($this->link),
            reference: $this->reference,
            businessName: $this->businessName,
            isDisapproval: $this->isDisapproval,
        ));
    }

    /**
     * The notice's link as an absolute address on the owner's own site.
     *
     * Notification links are SPA paths ("/applications/12"); an e-mail needs the
     * whole URL. FRONTEND_URL is the same base PermitResource uses for a
     * permit's verify link, so the two agree on where the site is. No link means
     * the dashboard, which is where an owner lands on sign-in anyway.
     */
    public static function siteUrl(?string $link): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $path = $link && str_starts_with($link, '/') ? $link : '/dashboard';

        return $base.$path;
    }

    /** Out of retries. Logged; whatever caused the notice stands regardless. */
    public function failed(?Throwable $e): void
    {
        Log::warning('Owner update e-mail could not be sent', [
            'user_id' => $this->owner->id,
            'title' => $this->title,
            'reference' => $this->reference,
            'error' => $e?->getMessage(),
        ]);
    }
}

<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Business;
use App\Models\OfficerRequest;
use App\Models\Permit;
use App\Models\User;
use App\Services\Sms\SmsChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * In-app notification fan-out (master plan §4 — polling, no websockets). Status
 * changes, issuance and request events also fan out to mail (log mailer) and SMS
 * (log driver) via the simulation pattern (§5.5). Generic payloads only
 * (guardrail §9.5) — no PII beyond the tracking id.
 */
/*
 * Link targets must be real routes in web/src/App.tsx. `/track/{id}` and
 * `/review/{id}` were never routes, so every notification bounced the reader
 * to the sign-in redirect instead of the thing it was about.
 */
class NotificationService
{
    public function __construct(private SmsChannel $sms) {}

    public function push(User $user, string $type, string $title, string $body, ?string $link = null): void
    {
        AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ]);
    }

    public function applicationStatus(Application $app, ApplicationStatus $to, ?string $note): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        // The two end states get their own message (approved/rejected below),
        // so the applicant is not told the same thing twice.
        if ($to === ApplicationStatus::Approved || $to === ApplicationStatus::Rejected) {
            return;
        }
        $this->push(
            $app->applicant,
            'status_change',
            'Application update',
            "{$app->tracking_id} is now “{$to->label()}”.".($note ? " $note" : ''),
            "/applications/{$app->id}",
        );
        $this->fanOut($app->applicant, "BizTrack: {$app->tracking_id} is now {$to->label()}.");
    }

    /** End state: the application cleared every office (tester item 51). */
    public function applicationApproved(Application $app): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'decision',
            'Application approved',
            "{$app->tracking_id} is approved. Every office has cleared it, so nothing more is "
                .'needed from you. Your permit has been issued and is waiting under Permits.',
            '/permits',
        );
        $this->fanOut($app->applicant, "BizTrack: {$app->tracking_id} is approved. Your permit is ready under Permits.");
    }

    /** End state: BPLO or the super admin ended the application. */
    public function applicationRejected(Application $app, ?string $reason = null): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'decision',
            'Application rejected',
            "{$app->tracking_id} was rejected.".($reason ? " Reason: {$reason}" : '')
                .' You can message the office about it, or file a new application once the issue is settled.',
            "/applications/{$app->id}",
        );
        $this->fanOut($app->applicant, "BizTrack: {$app->tracking_id} was rejected. Open BizTrack for the reason.");
    }

    public function permitsIssued(Application $app): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'issuance',
            'Permit issued',
            "Your permit(s) for {$app->tracking_id} are ready to download.",
            '/permits',
        );
        $this->fanOut($app->applicant, "BizTrack: permit(s) for {$app->tracking_id} issued.");
    }

    // --- Messaging -----------------------------------------------------------
    public function newMessage(Application $app, User $recipient): void
    {
        $this->push(
            $recipient,
            'message',
            'New message',
            "You have a new message on {$app->tracking_id}.",
            "/applications/{$app->id}",
        );
        $this->fanOut($recipient, "BizTrack: new message on {$app->tracking_id}.");
    }

    // --- Officer requests ----------------------------------------------------
    public function requestCreated(OfficerRequest $request, User $recipient): void
    {
        $app = $request->application;
        $this->push(
            $recipient,
            'request',
            'Additional requirement requested',
            "An officer requested: {$request->title} on {$app->tracking_id}.",
            "/applications/{$app->id}",
        );
        $this->fanOut($recipient, "BizTrack: new requirement requested on {$app->tracking_id}.");
    }

    public function requestResponded(OfficerRequest $request, User $recipient): void
    {
        $app = $request->application;
        $this->push(
            $recipient,
            'request',
            'Requirement response received',
            "The applicant responded to “{$request->title}” on {$app->tracking_id}.",
            // The one notification in this file addressed to an OFFICER rather
            // than to the applicant, so the one that points into the LGU site.
            // Everything else here links to a citizen screen at the root.
            "/staff/queue/{$app->id}",
        );
        $this->fanOut($recipient, "BizTrack: requirement response on {$app->tracking_id}.");
    }

    public function requestClosed(OfficerRequest $request, User $recipient): void
    {
        $app = $request->application;
        $this->push(
            $recipient,
            'request',
            'Requirement '.$request->status->label(),
            "Your response to “{$request->title}” on {$app->tracking_id} was {$request->status->label()}.",
            "/applications/{$app->id}",
        );
        $this->fanOut($recipient, "BizTrack: requirement on {$app->tracking_id} {$request->status->label()}.");
    }

    // --- Fee adjustment ------------------------------------------------------
    public function feeAdjusted(Application $app): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'fee',
            'Fee assessment updated',
            "Your fee for {$app->tracking_id} was adjusted. Please review before paying.",
            "/pay/{$app->id}",
        );
        $this->fanOut($app->applicant, "BizTrack: fee for {$app->tracking_id} adjusted.");
    }

    // --- Permit expiry (scheduler) -------------------------------------------
    /**
     * Renewal reminder at one of the 30 / 15 / 7 / 1-day thresholds.
     *
     * The title is the mockup's (`updated-gui/120.png`) and the body is the
     * client paper's wording verbatim, because this is the one string in the
     * system the paper actually dictates.
     *
     * `$threshold` is the reminder bucket that fired; `$daysLeft` is the real
     * number of days remaining, which can be smaller — a permit first seen with
     * 22 days left fires the 30-day bucket. The copy quotes the bucket, matching
     * both the paper and the ledger row, and the "expires on" date carries the
     * exact fact so nothing has to be inferred from a rounded number.
     */
    public function permitExpiring(Permit $permit, int $threshold, ?int $daysLeft = null): void
    {
        $owner = $this->permitOwner($permit);
        if (! $owner) {
            return;
        }
        $unit = $threshold === 1 ? 'day' : 'days';
        $expiresOn = $permit->valid_until->format('j M Y');   // cast to a date on the model

        $this->push(
            $owner,
            'expiry',
            "Business Permit expiring in {$threshold} {$unit}",
            "Reminder: Your business permit will expire in {$threshold} {$unit}. Please renew your "
                ."permit before the expiration date to avoid penalties. Permit {$permit->permit_number} "
                ."expires on {$expiresOn}.",
            '/permits',
        );
        $this->fanOut($owner, "BizTrack: permit {$permit->permit_number} expires in ".($daysLeft ?? $threshold).' day(s).');
    }

    public function permitExpired(Permit $permit): void
    {
        $owner = $this->permitOwner($permit);
        if (! $owner) {
            return;
        }
        $this->push(
            $owner,
            'expiry',
            'Permit expired',
            "Permit {$permit->permit_number} has expired. Please file a renewal.",
            '/permits',
        );
        $this->fanOut($owner, "BizTrack: permit {$permit->permit_number} has expired.");
    }

    /**
     * A renewal follow-up an OFFICER asked for, from the Renewal Risk screen.
     *
     * Same path as every notification above — push() into the owner's in-app
     * list, then fanOut() to the log mailer and the SMS log — because the
     * applicant should not be able to tell "the system chased me" from "a
     * person chased me" by which channels answered. What differs is only the
     * words, and the words differ for two reasons:
     *
     *  - **It cannot quote a threshold.** permitExpiring() names one of the
     *    30/15/7/1-day buckets, which is true only because ScanPermits only
     *    ever calls it when a bucket has fired. An officer can press this on a
     *    permit with 47 days left, and "expires in 30 days" would then be a
     *    plain falsehood in a message to a business owner. The exact date is
     *    stated instead, which is true at any distance.
     *  - **It says a person sent it.** "An officer at the BPLO" is not
     *    decoration: it tells the reader there is somebody to ring back, and
     *    it is what distinguishes this from the automatic reminders in the
     *    same list. It is also simply what happened.
     *
     * `$urgent` follows the row's band — the spec's "Immediate follow-up" for
     * High and "Send reminder" for Moderate. It changes the tone and nothing
     * else; both are one notification, and neither claims anything about the
     * index that produced it. The score is an internal ranking and no message
     * from this method quotes it.
     */
    public function renewalFollowUp(Permit $permit, bool $urgent = false): void
    {
        $owner = $this->permitOwner($permit);
        if (! $owner) {
            return;
        }

        $expiresOn = $permit->valid_until->format('j M Y');   // cast to a date on the model
        $lapsed = $permit->valid_until->startOfDay()->isPast();

        $title = $urgent ? 'Renewal follow-up from the BPLO' : 'Renewal reminder from the BPLO';

        $body = $lapsed
            ? "An officer at the BPLO is following up on permit {$permit->permit_number}, which expired on "
                ."{$expiresOn}. Please file a renewal as soon as you can to avoid further penalties."
            : "An officer at the BPLO is reminding you that permit {$permit->permit_number} expires on "
                ."{$expiresOn}. Please renew before that date to avoid penalties.";

        $this->push($owner, 'expiry', $title, $body, '/permits');

        $this->fanOut(
            $owner,
            "BizTrack: the BPLO is following up on permit {$permit->permit_number} (expires {$expiresOn}).",
        );
    }

    public function renewalDue(Permit $permit): void
    {
        $owner = $this->permitOwner($permit);
        if (! $owner) {
            return;
        }
        $this->push(
            $owner,
            'expiry',
            'Renewal due',
            "Permit {$permit->permit_number} lapsed recently. Renew now to avoid penalties.",
            '/permits',
        );
        $this->fanOut($owner, "BizTrack: renewal due for permit {$permit->permit_number}.");
    }

    /**
     * The LGU has changed a business's standing. Tell the person it belongs to.
     *
     * ── Why this exists ──────────────────────────────────────────────────────
     *
     * Suspending or blacklisting a business is the heaviest thing the super
     * admin can do to a citizen on this system, and it used to happen entirely
     * behind their back: a status column moved, an audit row was written, and
     * the owner found out the next time they tried to file and were refused by
     * a sentence that did not say when, why, or by whom. The reason is already
     * required at the point of the change; this is what carries it to the one
     * person who has to act on it.
     *
     * ── Why the reason is repeated verbatim ──────────────────────────────────
     *
     * Guardrail §9.5 keeps PII out of notification payloads, and this obeys it:
     * the business name and the admin's own words are not third-party data, and
     * the owner is the subject of both. Paraphrasing would be worse than silent
     * — an appeal has to be against what was actually recorded.
     */
    public function businessStatusChanged(
        Business $business,
        string $from,
        string $to,
        string $reason,
        string $label,
    ): void {
        $business->loadMissing('owner');
        if (! $business->owner) {
            return;
        }

        /*
         * Restored reads as good news and everything else as a warning, because
         * a single flat "your status changed" makes the one message an owner
         * must act on look like the one they can ignore.
         */
        $restored = $to === 'active';
        $title = $restored ? 'Business account restored' : "Business account {$label}";

        $body = $restored
            ? "{$business->name} is active again and can file applications. Reason: {$reason}"
            : "{$business->name} is now {$label}. "
                .($to === Business::STATUS_BLACKLISTED || $to === 'suspended'
                    ? 'New applications cannot be filed for it while this stands. '
                    : '')
                ."Reason: {$reason} If you believe this is a mistake, message the City BPLO.";

        /*
         * `/dashboard`, not `/businesses` — there is no such route, and this
         * file's own opening note records what happens then: the reader is
         * bounced to the sign-in redirect and the notification is worse than
         * useless. The owner dashboard is also the right destination on its
         * merits, because it already raises AccountRestrictedModal for exactly
         * these two statuses, so following the link lands on an explanation
         * rather than somewhere the reader has to go looking.
         */
        $this->push($business->owner, 'account_status', $title, $body, '/dashboard');
        $this->fanOut($business->owner, "BizTrack: {$business->name} is now {$label}. {$reason}");
    }

    // --- Channel fan-out (mail log + sms log) --------------------------------
    private function fanOut(User $user, string $message): void
    {
        // Mail via the log mailer (renders into laravel.log).
        Mail::raw($message, function ($m) use ($user) {
            $m->to($user->email)->subject('BizTrack notification');
        });

        if ($user->mobile_number) {
            $this->sms->send($user->mobile_number, $message);
        }
    }

    private function permitOwner(Permit $permit): ?User
    {
        $permit->loadMissing('business.owner');

        return $permit->business?->owner;
    }
}

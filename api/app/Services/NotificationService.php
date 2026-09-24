<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Jobs\SendOwnerUpdateEmail;
use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Business;
use App\Models\OfficerRequest;
use App\Models\Permit;
use App\Models\PermitType;
use App\Models\User;
use App\Services\Sms\SmsChannel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * In-app notification fan-out (master plan §4 — polling, no websockets).
 *
 * Every notice written for a BUSINESS OWNER is also e-mailed to them, queued,
 * from push() — see queueOwnerEmail() and App\Jobs\SendOwnerUpdateEmail. That
 * replaced a synchronous `Mail::raw('BizTrack notification')` in fanOut(),
 * which sent a one-line mail to every recipient, staff included, inside the
 * request: harmless while the mailer was `log`, but with a real SMTP relay it
 * would have held every officer action on a network round-trip and turned an
 * unreachable relay into a failed approval. SMS (log driver, §5.5) still goes
 * through fanOut() unchanged — SMS is out of scope for now.
 *
 * Generic payloads only (guardrail §9.5) — no PII beyond the tracking id.
 */
/*
 * Link targets must be real routes in web/src/App.tsx. `/track/{id}` and
 * `/review/{id}` were never routes, so every notification bounced the reader
 * to the sign-in redirect instead of the thing it was about.
 *
 * And a route is not enough: it has to be a route on the READER'S OWN SITE.
 * The SPA is two sites on one origin — the citizen one at the root, the LGU one
 * under `/staff` — and lib/api.ts keys the session token by which of the two the
 * address bar is on. So a citizen path handed to an officer is not merely the
 * wrong screen: the officer arrives where their token is not sent, the first
 * request comes back 401, and the app puts them on the citizen sign-in page.
 * Nothing has actually ended their session, but there is no way to tell that
 * from the screen, which is how this reads as "clicking a notification logs me
 * out" (issue #98).
 *
 * Hence filingLink(): the one filing has two addresses, and which one is right
 * depends on who is being told about it, never on which method is telling them.
 */
class NotificationService
{
    public function __construct(private SmsChannel $sms) {}

    /**
     * Write one in-app notice, and e-mail it too when the reader is an owner.
     *
     * `$about` is what the notice concerns — the filing, the permit or the
     * business — and exists only so the e-mail can print a reference and the
     * business's name; the in-app row does not store it. `$disapproval` marks
     * the one notice whose title the e-mail may show in red.
     */
    public function push(
        User $user,
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        Application|Permit|Business|null $about = null,
        bool $disapproval = false,
    ): void {
        AppNotification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ]);

        if ($user->hasRole('business_owner')) {
            $this->queueOwnerEmail($user, $title, $body, $link, $about, $disapproval);
        }
    }

    /**
     * Queue the e-mail copy of an owner's notice. Never throws.
     *
     * ── Who gets one ─────────────────────────────────────────────────────────
     *
     * Holders of the `business_owner` role — the people the assignment is
     * about. Staff notices (a reply on a thread, a reassigned caseload, an
     * amendment another office should know of) stay in-app: officers are signed
     * in all day, and mailing them every queue event would bury the one mail an
     * owner actually needs among hundreds they do not.
     *
     * ── Why after commit, and why the try ────────────────────────────────────
     *
     * Most notices are written inside the DB::transaction of the action that
     * caused them. Dispatched immediately, a worker could pick the job up
     * before that transaction commits — or after it rolled back, e-mailing an
     * owner about a decision that never happened. DB::afterCommit() holds the
     * dispatch until the change is real (and runs it at once outside a
     * transaction).
     *
     * The try is inside the callback, not around it, because that is where the
     * dispatch actually happens. A queue that will not take the job (a missing
     * `jobs` table, a dead Redis) and, under the `sync` driver, a mail relay
     * that refuses the message both surface here, and both are logged and
     * dropped: the action that caused the notice has already been recorded and
     * must not answer 500 for a mail that did not go.
     */
    private function queueOwnerEmail(
        User $owner,
        string $title,
        string $body,
        ?string $link,
        Application|Permit|Business|null $about,
        bool $disapproval,
    ): void {
        [$reference, $businessName] = $this->describe($about);

        DB::afterCommit(function () use ($owner, $title, $body, $link, $reference, $businessName, $disapproval) {
            try {
                // Bus::dispatch, not the static ::dispatch(): that one queues
                // from a PendingDispatch destructor, outside this try.
                Bus::dispatch(new SendOwnerUpdateEmail(
                    $owner, $title, $body, $link, $reference, $businessName, $disapproval,
                ));
            } catch (Throwable $e) {
                Log::warning('Owner update e-mail was not queued', [
                    'user_id' => $owner->id,
                    'title' => $title,
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * The reference and business name an e-mail prints for what it is about.
     *
     * A filing is named by its tracking ID, a permit by its permit number, a
     * business by its account number — the three identifiers AGENTS.md §11
     * lists, each for the thing it names.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function describe(Application|Permit|Business|null $about): array
    {
        return match (true) {
            $about instanceof Application => [
                $about->tracking_id,
                $about->loadMissing('business')->business?->name,
            ],
            $about instanceof Permit => [
                $about->permit_number,
                $about->loadMissing('business')->business?->name,
            ],
            $about instanceof Business => [$about->ban ?: null, $about->name],
            default => [null, null],
        };
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
            $app,
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
            $app,
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
            $app,
            disapproval: true,
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
            $app,
        );
        $this->fanOut($app->applicant, "BizTrack: permit(s) for {$app->tracking_id} issued.");
    }

    // --- A refused permit, and the suspension it causes ----------------------

    /**
     * One office has refused one permit. Said separately from the suspension.
     *
     * Two notifications rather than one, because they are two facts with two
     * different next steps: this one is the office's decision and points at the
     * permit, and `outcomePermitSuspended` below is what it costs and points at
     * the certificate. Rolling them together would bury whichever half the
     * applicant most needed.
     *
     * The reason is quoted rather than summarised. It is the office's wording
     * and the only thing that tells the applicant what to do next.
     */
    public function clearanceRejected(Application $app, PermitType $type, string $reason): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'decision',
            "{$type->name} was rejected",
            "The office reviewing your {$type->name} on {$app->tracking_id} has refused it. "
                ."Reason: {$reason} You can apply for it again once the issue is settled.",
            "/applications/{$app->id}/clearances",
        );
        $this->fanOut(
            $app->applicant,
            "BizTrack: your {$type->name} on {$app->tracking_id} was rejected. Open BizTrack for the reason.",
        );
    }

    /**
     * The business permit has been suspended because a permit was refused.
     *
     * The heaviest thing this flow does to a citizen, so it says all four things
     * they need: that the certificate is suspended, which permit caused it, the
     * office's reason, and the way back. An owner who reads only the title still
     * learns the one fact that changes what they may do today.
     *
     * Links to the permit rather than to the filing. The certificate is the
     * object that changed and the one they will be asked to show.
     */
    public function outcomePermitSuspended(
        Application $app,
        Permit $permit,
        PermitType $refused,
        string $reason,
    ): void {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'decision',
            'Business Permit suspended',
            "Your Business Permit {$permit->permit_number} has been suspended because your "
                ."{$refused->name} was rejected. Reason: {$reason} Apply for that permit again, "
                .'and your Business Permit is restored as soon as it is approved.',
            '/permits',
        );
        $this->fanOut(
            $app->applicant,
            "BizTrack: Business Permit {$permit->permit_number} suspended — your {$refused->name} was rejected.",
        );
    }

    /**
     * The suspension is over, either by itself or by BPLO lifting it.
     *
     * One method for both, because to the owner it is one event — their permit
     * is valid again — and the difference is in WHY, which the body carries.
     * `$reason` present means a person decided it; absent means the condition
     * that caused it simply stopped holding.
     */
    public function outcomePermitReinstated(Application $app, Permit $permit, ?string $reason = null): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'issuance',
            'Business Permit restored',
            "Your Business Permit {$permit->permit_number} is active again."
                .($reason !== null
                    ? " BPLO lifted the suspension. Reason: {$reason}"
                    : ' No permit on this application is rejected any more.'),
            '/permits',
        );
        $this->fanOut(
            $app->applicant,
            "BizTrack: Business Permit {$permit->permit_number} is active again.",
        );
    }
    // --- Messaging -----------------------------------------------------------
    public function newMessage(Application $app, User $recipient): void
    {
        $this->push(
            $recipient,
            'message',
            'New message',
            "You have a new message on {$app->tracking_id}.",
            // The one notification here whose recipient can be EITHER side:
            // MessageController::counterparty() answers with the applicant when
            // an officer wrote, and with the office's assigned officer when the
            // applicant did. Hard-coding the citizen path sent every officer
            // reply-notification to a screen their token does not reach.
            $this->filingLink($recipient, $app),
            $app,
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
            $app,
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
            // Addressed to the officer who asked for the requirement, so it
            // points into the LGU site. Through the helper rather than written
            // out, so the address is decided by who is being TOLD — the same
            // question everywhere — instead of by a literal this method happened
            // to get right and newMessage() happened to get wrong.
            $this->filingLink($recipient, $app),
            $app,
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
            $app,
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
            /*
             * `/applications/{id}/pay`, which is what App.tsx actually mounts
             * PayPage at. `/pay/{id}` has never been a route — the same class of
             * mistake as the `/track` and `/review` prefixes named at the top of
             * this file, and it survived the fix that retired those because no
             * fee had been adjusted yet, so no row existed to fail the test.
             */
            "/applications/{$app->id}/pay",
            $app,
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
            $permit,
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
            $permit,
        );
        $this->fanOut($owner, "BizTrack: permit {$permit->permit_number} has expired.");
    }

    /**
     * A renewal follow-up an OFFICER asked for, from the Renewal Risk screen.
     *
     * Same path as every notification above — push() into the owner's in-app
     * list and e-mail, then fanOut() to the SMS log — because the
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

        $this->push($owner, 'expiry', $title, $body, '/permits', $permit);

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
            $permit,
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
        $this->push($business->owner, 'account_status', $title, $body, '/dashboard', $business);
        $this->fanOut($business->owner, "BizTrack: {$business->name} is now {$label}. {$reason}");
    }

    // --- Channel fan-out (sms log) -------------------------------------------
    /*
     * SMS only. The `Mail::raw` that used to open this method is gone: e-mail
     * now goes from push() to owners alone, queued, with the notice's own title
     * and a link (see the class note). Leaving it here as well would have sent
     * every owner two e-mails per event, one of them a bare line with no link.
     */
    private function fanOut(User $user, string $message): void
    {
        if ($user->mobile_number) {
            $this->sms->send($user->mobile_number, $message);
        }
    }

    /**
     * The address of a filing ON THE SITE THIS READER IS SIGNED INTO.
     *
     * A filing has two screens, not one: the applicant reads it at
     * `/applications/{id}` on the citizen site, a reviewer reads it at
     * `/staff/queue/{id}` on the LGU one. They are not interchangeable — see the
     * note at the top of this file for what handing over the wrong one does.
     *
     * `application.review` is the discriminator because it is what App.tsx gates
     * the queue route on; asking the same question the router asks is what keeps
     * the answer true when roles change. Anyone without it is a business owner,
     * and the citizen path is the only one they could open anyway.
     *
     * The officer link carries an APPLICATION id into a route that binds an
     * ASSIGNMENT. That is deliberate and long-standing (commit c922a8a):
     * ReviewPage resolves a stray id against the office's own queue and replaces
     * the URL, so the link self-corrects. An assignment id is not available here
     * — a filing routed to six offices has six of them, and which one is meant
     * depends on the reader, not on the filing.
     */
    private function filingLink(User $reader, Application $app): string
    {
        return $reader->hasPermission('application.review')
            ? "/staff/queue/{$app->id}"
            : "/applications/{$app->id}";
    }

    private function permitOwner(Permit $permit): ?User
    {
        $permit->loadMissing('business.owner');

        return $permit->business?->owner;
    }
}

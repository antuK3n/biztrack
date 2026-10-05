<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\PermitStatus;
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

    /**
     * The site visit did not pass, and the owner has to act on it.
     *
     * ── Why this is not `applicationStatus()` ───────────────────────────────
     *
     * It was, until 4 October 2026, and that quietly stopped working the day
     * `awaiting_other_permits` was removed. `applicationStatus` says nothing
     * for Approved or Rejected, because each has a dedicated notice of its own
     * — and a filing gathering its other permits now wears `approved`. So the
     * one inspection result an owner must act on went back to being recorded
     * in silence, which is the exact bug the note in
     * `WorkflowService::recordInspection` says was fixed on 24 September.
     *
     * A notice of its own also reads better than the one it borrowed. The old
     * body opened with the filing's status — "BIZ-… is now “Approved”. The
     * City Health Office inspection did not pass" — which announces good news
     * and then contradicts it.
     */
    public function inspectionFailed(Application $app, string $body): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'inspection',
            'Inspection did not pass',
            $body,
            "/applications/{$app->id}",
            $app,
        );
        $this->fanOut($app->applicant, "BizTrack: an inspection for {$app->tracking_id} did not pass.");
    }

    /**
     * Tell the applicant something about a filing whose status is NOT changing.
     *
     * ── Why this exists beside `applicationStatus()` ────────────────────────
     *
     * Seven places in WorkflowService had been calling `applicationStatus($app,
     * $app->status, $note)` — passing the CURRENT status — purely to carry a
     * sentence to the owner: an office changed what it asked for, a visit was
     * booked, a clearance was approved, the Business Permit was released. That
     * worked while no live filing ever wore `approved`.
     *
     * Since 4 October 2026 a paid filing wears it while its other permits
     * come in, and `applicationStatus` is deliberately silent on Approved
     * because the END of a filing has its own notice. So every one of those
     * seven sentences went quiet on exactly the filings they were about —
     * including the one that says the permit has been released, fired in the
     * same breath as the release. The owner paid and heard nothing.
     *
     * This carries the sentence and asks no question about the status, which
     * is the whole of what those callers wanted. `applicationStatus` keeps its
     * silence for the one caller that means a transition: `transition()`.
     */
    public function applicationNote(Application $app, string $note): void
    {
        $app->loadMissing('applicant');
        if (! $app->applicant) {
            return;
        }
        $this->push(
            $app->applicant,
            'status_change',
            'Application update',
            "{$app->tracking_id}: {$note}",
            "/applications/{$app->id}",
            $app,
        );
        $this->fanOut($app->applicant, "BizTrack: {$app->tracking_id} — {$note}");
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
        // `permit_suspended`: the owner's screen raises this as a modal.
        $this->push(
            $app->applicant,
            'permit_suspended',
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
     * The 7-day chase on a suspension nobody has settled.
     *
     * Client, 5 October 2026: *"remind the owner every 7 days."* Same channels
     * as the suspension itself, to the business's owner (the reminder is about
     * the certificate, like the expiry reminders). Names the permit and office
     * it waits on when the cause was one; otherwise repeats the recorded reason.
     */
    public function outcomePermitStillSuspended(Permit $permit, int $days): void
    {
        $owner = $this->permitOwner($permit);
        if (! $owner) {
            return;
        }
        $permit->loadMissing('suspendedFor.department');
        $for = $permit->suspendedFor;
        $office = $for?->department?->name;
        $why = $for !== null
            ? "{$for->name} is still pending".($office ? " with {$office}" : '').'.'
            : ($permit->suspension_reason ? "Reason: {$permit->suspension_reason}" : '');
        $tail = $why !== '' ? " — {$why}" : '.';

        $this->push(
            $owner,
            'decision',
            'Business Permit still suspended',
            "Your Business Permit {$permit->permit_number} has been suspended for {$days} days{$tail}",
            "/permits/{$permit->id}",
        );
        $this->fanOut(
            $owner,
            "BizTrack: Business Permit {$permit->permit_number} suspended for {$days} days{$tail}",
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
        // `permit_reactivated`: the owner's screen raises this as a modal.
        $this->push(
            $app->applicant,
            'permit_reactivated',
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

    /**
     * BPLO or the super admin took a permit away (checklist item 23).
     *
     * To the business's OWNER, found through the permit rather than through a
     * filing: a revocation is about the certificate, and a permit whose filing
     * was deleted is still one somebody holds. Soft-deleted businesses and
     * owners resolve to null and the notice is skipped — there is nobody left
     * to tell, and the audit row the caller writes is the record.
     *
     * The reason is included. A26 asked whether it should be; the officer typed
     * it knowing it is audited, and an owner told only "revoked" has nothing to
     * contest or correct. If the City answers otherwise, this sentence is the
     * one place it changes.
     *
     * `disapproval: true` gives the e-mail copy the same treatment a rejected
     * application's gets. push() queues that copy itself; nothing here mails.
     */
    public function permitRevoked(Permit $permit, string $reason): void
    {
        $owner = $this->permitOwner($permit);
        if (! $owner) {
            return;
        }

        $permit->loadMissing('permitType.department');
        $name = $permit->permitType?->name ?? 'Permit';
        // The office that revoked it, since each office now revokes its own.
        $office = $permit->permitType?->department?->name ?? 'issuing office';

        /*
         * Its own type, `permit_revoked`, not the generic `decision` [client,
         * 5 October 2026]: the owner's screen raises a modal the first time it
         * sees one unread, and it has to be able to tell this notice apart
         * from an approval to do that.
         */
        $this->push(
            $owner,
            'permit_revoked',
            "{$name} revoked",
            "Your {$name} {$permit->permit_number} has been revoked and is no longer valid. "
                ."Reason: {$reason} Contact the {$office} if you believe this is wrong.",
            '/permits',
            $permit,
            disapproval: true,
        );
        $this->fanOut($owner, "BizTrack: {$name} {$permit->permit_number} has been revoked.");
    }

    /**
     * An office changed one of its certificates' status from Change status.
     *
     * [Client, 5 October 2026: "magrereflect pa rin ito sa lahat, even sa mga
     * permits, notif, at mga modal pag log in ng business owners".] One notice
     * per change, typed by what happened so the owner's screen can raise it as
     * a modal and colour it in the list: `permit_suspended`, `permit_retired`,
     * `permit_rejected` or `permit_reactivated`. It names the office, since
     * each office now changes its own certificates. (Revoked has its own,
     * permitRevoked.)
     */
    public function permitStatusChanged(Permit $permit, PermitStatus $to, string $reason): void
    {
        $owner = $this->permitOwner($permit);
        if (! $owner) {
            return;
        }

        $permit->loadMissing('permitType.department');
        $name = $permit->permitType?->name ?? 'Permit';
        $office = $permit->permitType?->department?->name ?? 'issuing office';

        [$type, $title, $what] = match ($to) {
            PermitStatus::Suspended => ['permit_suspended', "{$name} suspended", 'has been suspended and may not be used while the suspension stands.'],
            PermitStatus::Retired => ['permit_retired', "{$name} retired", 'has been retired. The business is recorded as no longer operating under it.'],
            PermitStatus::Rejected => ['permit_rejected', "{$name} rejected", 'has been rejected by the office that issued it and is no longer valid.'],
            default => ['permit_reactivated', "{$name} active again", 'is active again.'],
        };

        $this->push(
            $owner,
            $type,
            $title,
            "Your {$name} {$permit->permit_number} {$what} Reason: {$reason} "
                ."Contact the {$office} if you have questions.",
            '/permits',
            $permit,
            disapproval: $to !== PermitStatus::Active,
        );
        $this->fanOut($owner, "BizTrack: {$title} — {$permit->permit_number}.");
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

    /**
     * An applicant has answered a RETURN and handed the filing back.
     *
     * Modelled on `requestResponded` directly above, which does the same job
     * for a requirement. A return is the larger of the two — it blocks the
     * whole filing rather than sitting beside it — and until 28 September
     * 2026 it was the one that told the office nothing.
     *
     * \@param  int  $fields  How many fields the applicant corrected, from
     *   `application_corrections`. Zero for a return answered in the wizard,
     *   where the changes are not recorded field by field — the sentence
     *   drops the count rather than claiming none were changed.
     */
    public function filingResubmitted(Application $app, User $recipient, int $fields = 0): void
    {
        $what = $fields > 0
            ? $fields.' field'.($fields === 1 ? '' : 's').' corrected'
            : 'Corrections received';

        $this->push(
            $recipient,
            'status_change',
            'Corrections received',
            "{$what} on {$app->tracking_id}. It is back with your office for review.",
            // Into the LGU site, chosen by who is being TOLD rather than by a
            // literal — the same reasoning `requestResponded` records.
            $this->filingLink($recipient, $app),
            $app,
        );
        $this->fanOut($recipient, "BizTrack: corrections received on {$app->tracking_id}.");
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

        /*
         * ── The right permit's name, and only where it was wrong ────────
         *
         * This said "Business Permit expiring" for EVERY type, so an owner
         * holding six certificates got reminders that all named the one
         * certificate that was usually not the one expiring.
         *
         * But the business permit's own wording is not a slip to correct:
         * `PermitExpiryRemindersTest` pins title and body as "the paper's
         * reminder wording and the mockup's title", which makes them
         * transcribed from the city's documents rather than written here.
         * A first pass replaced both and failed that test, correctly —
         * rewriting an LGU's own notice is the client's call, not a side
         * effect of fixing a name.
         *
         * So the mockup's words stand for the permit they were written
         * about, and the other five get the same sentence with their own
         * name in it. `permitType->name` is not used for the business
         * permit because the register calls it "Mayor's / Business
         * Permit" and the mockup says "Business Permit".
         */
        $permit->loadMissing('permitType');
        $isOutcome = $permit->permitType?->code === PermitType::OUTCOME_CODE;
        $name = $isOutcome ? 'Business Permit' : ($permit->permitType?->name ?? 'Permit');
        $subject = $isOutcome ? 'business permit' : $name;

        /*
         * The paper's sentence, then what lateness costs — APPENDED, not
         * substituted. "To avoid penalties" was true and unactionable
         * until 1 October 2026, because nothing called
         * `FeeCalculator::latePenalty`; there are penalties now, and a
         * reminder that names the number is a reason to act where one
         * gesturing at consequences is furniture. Appending keeps the
         * transcribed wording intact and still says the new thing.
         */
        $this->push(
            $owner,
            'expiry',
            "{$name} expiring in {$threshold} {$unit}",
            "Reminder: Your {$subject} will expire in {$threshold} {$unit}. Please renew your "
                ."permit before the expiration date to avoid penalties. Permit {$permit->permit_number} "
                ."expires on {$expiresOn}. Renewing after that date adds a 25% surcharge plus 2% "
                .'for every month it is late (Revenue Code Secs. 8A.04 and 8A.05).',
            '/permits',
            $permit,
        );
        $this->fanOut($owner, "BizTrack: permit {$permit->permit_number} expires in ".($daysLeft ?? $threshold).' day(s).');
    }

    /**
     * This permit is yours, and its fee arrives in January.
     *
     * A clearance renewed out of season is issued UNBILLED — the city
     * collects once a year — and until now nothing told the applicant so.
     * They were handed a certificate, paid nothing, and met the charge
     * months later on a bill they had no reason to expect, which is how a
     * correct rule becomes a complaint at the counter.
     *
     * The surcharge is named separately when there is one. Lumping it into
     * the fee would hide the one figure the applicant might dispute, and
     * this message is the first and best chance to raise it — months
     * before the bill, while the filing dates are still checkable.
     */
    public function permitIssuedUnbilled(Permit $permit, float $fee, float $penalty): void
    {
        $owner = $this->permitOwner($permit);
        if (! $owner) {
            return;
        }

        $permit->loadMissing('permitType');
        $name = $permit->permitType?->name ?? 'Permit';
        $money = fn (float $v) => '₱'.number_format($v, 2);

        $body = "Your {$name} ({$permit->permit_number}) has been issued. "
            ."There is nothing to pay today: its fee of {$money($fee)} is collected "
            .'with your next business permit renewal in January.';

        if ($penalty > 0.0) {
            $body .= " A late-renewal surcharge of {$money($penalty)} is included, "
                .'because this permit was renewed after it expired.';
        }

        $this->push($owner, 'payment', "{$name} issued — fee due in January", $body, '/permits', $permit);
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
        $title = $restored
            ? 'Business account restored'
            : ($to === Business::STATUS_BLACKLISTED
                // Named for what it is. "Business account Blacklisted" reads
                // as one account among several; this one is about the reader.
                ? 'Your account has been blacklisted'
                : "Business account {$label}");

        /*
         * -- A blacklisting is about the reader, not about one shopfront -----
         *
         * It said "{business} is now Blacklisted. New applications cannot be
         * filed for it" - true of the business named, and badly misleading
         * about everything else the reader owns, all of which was barred in
         * the same act. Somebody with three shops would have read this as one
         * shop's problem and found out the rest by being refused
         * [client, 27 September 2026: *"bale lahat lahat ng business nya ay
         * blacklisted na ... need ng notif sa business owner"*].
         *
         * The count comes from the owner's own businesses rather than from
         * the caller, so the sentence cannot drift from what was actually
         * written.
         */
        $blacklisted = $to === Business::STATUS_BLACKLISTED;
        $held = $blacklisted || $restored
            ? $business->owner->businesses()->count()
            : 1;

        $body = $restored
            ? "{$business->name} is active again and can file applications. Reason: {$reason}"
            : ($blacklisted
                ? 'This account has been blacklisted'
                    .($held > 1
                        ? ", so all {$held} of its businesses — including {$business->name} — are "
                            .'blacklisted and none of them can file or renew.'
                        : ", so {$business->name} cannot file or renew.")
                    ." Reason: {$reason} To ask what is needed to have this lifted, message the "
                    .'City BPLO through BizTrack.'
                : "{$business->name} is now {$label}. "
                    .($to === 'suspended'
                        ? 'New applications cannot be filed for it while this stands. '
                        : '')
                    ."Reason: {$reason} If you believe this is a mistake, message the City BPLO.");

        /*
         * `/dashboard`, not `/businesses` — there is no such route, and this
         * file's own opening note records what happens then: the reader is
         * bounced to the sign-in redirect and the notification is worse than
         * useless. The owner dashboard is also the right destination on its
         * merits: for a blacklisting the shell raises AccountRestrictedModal
         * there, so following the link lands on an explanation rather than
         * somewhere the reader has to go looking. A suspension raises nothing
         * since 5 October 2026 — it no longer bars the account, only the one
         * business, which is what this notice's own body says.
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

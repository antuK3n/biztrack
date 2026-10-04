<?php

namespace App\Enums;

/**
 * applications.status — the APPLICATION's machine.
 *
 * There are two machines now, and the split is the point (see
 * docs/application-flow-2026-09.md). This one tracks the filing as a whole:
 * BPLO reading the form, the applicant paying, the other permits being worked,
 * BPLO's final sign-off. What each individual permit is doing is
 * `ClearanceStatus` on the `application_permit_types` row.
 *
 * `submitted`, `under_review` and `for_inspection` were in this enum and are
 * gone. They described work that belongs to ONE permit, and an application can
 * no longer be in one of those states as a whole: by the time CHO is inspecting,
 * BFP may still be reading and CPDO may already have issued. A single column
 * saying "For Inspection" over that is not a summary, it is a wrong answer to a
 * question the screen did not ask. `submitted` went for a duller reason — the
 * client's flow moves a submitted form straight to For Approval, so the state
 * had a name and no duration.
 */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case ForApproval = 'for_approval';
    case PendingPayment = 'pending_payment';
    case ForFinalApproval = 'for_final_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * The one wording for a status, in the LGU's vocabulary.
     *
     * These are not free text. The same state was once called three different
     * things — the API said "Awaiting payment", the web said "For payment", the
     * design said "Pending Payment" — and an applicant ringing the office about
     * their "For payment" application was describing a state no officer had a
     * name for.
     *
     * The values (`for_approval`, `awaiting_other_permits`, …) are the contract
     * with the database, `application_status_history` and every `?status=`
     * query param. Only these labels are the LGU's to change.
     *
     * The web keeps its own copy in `web/src/lib/status.ts` because it labels
     * statuses the API never sends it and cannot wait for a round trip to
     * caption a filter. Two copies drift, so
     * `tests/Feature/StatusLabelParityTest.php` reads that file and fails the
     * build the moment either side is edited alone.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            /*
             * "For INITIAL Approval", renamed 16 September 2026.
             *
             * "For Initial Approval" from 17 September to 24 September 2026,
             * and "For Approval" either side of it.
             *
             * BPLO approves a filing twice — once on the form before there is a
             * bill, once again after every other permit is in. The word was
             * added because the second was called "For Final Approval": it
             * announced itself as one of a pair and the first did not, so an
             * applicant at the start could not tell they were at the start.
             *
             * The client took it out again when the LGU moved the release:
             * *"There is no Final Approval here since business permit is
             * already given after payment."* ForFinalApproval is still a real
             * status and still reachable — see `allowedNext` — but it now closes
             * a filing whose permit the applicant is already holding. The pair
             * exists for BPLO's clerks; it is no longer a pair the applicant
             * waits through, and "initial" promised them a second gate that
             * holds nothing of theirs.
             */
            self::ForApproval => 'For Approval',
            self::PendingPayment => 'Pending Payment',
            /*
             * "With BPLO", renamed 3 October 2026.
             *
             * The old label named an internal stage, and by then named one
             * the applicant was told about nowhere: it left the new rail on
             * 18 September and the renewal rail on 3 October, so the chip
             * pointed at a step in no guide on the site.
             *
             * A filing reaches it now only by having no confirmed RA 11032
             * category — an internal data problem the applicant did not
             * cause and cannot act on. "With BPLO" is true, is the only
             * part that concerns them, and asks nothing of them.
             *
             * `StatusLabelParityTest` holds this and `web/src/lib/status.ts`
             * to the same string, and it is what caught this one: the web
             * side was renamed first and the two drifted for one commit.
             */
            self::ForFinalApproval => 'With BPLO',
            /*
             * "Completed", renamed 24 September 2026.
             *
             * The client, reading the applicant's guide: *"Why is there
             * Approved at the end even though there is already Permit
             * Released?"* Because this stage stopped being the moment the
             * permit was granted — that moved to payment — and "Approved"
             * never said approved WHAT. Two green badges in a row, the
             * second of which appeared to repeat the first.
             *
             * What this stage actually is: the filing is CLOSED. Every other
             * permit is in, the status is terminal, and two things follow
             * that the applicant can feel — `rejectAssignment` refuses a
             * terminal filing, so no CLEARANCE ON THIS FILING can cause a
             * suspension any more; and ApplicationsPage drops a FINISHED
             * filing from Permit Tracking, so the application moves to their
             * Profile. "Completed" is the word for both.
             *
             * It does NOT mean the permit is safe. Enforcement against a
             * trading business is a separate lever and always was —
             * `businesses.status` carries suspended and blacklisted, and
             * `PermitStatus::Revoked` exists — and neither asks whether a
             * filing is finished. The client flagged the first draft of this
             * wording for implying otherwise, and anything written here has
             * to stay narrow: what closes is this application's own route to
             * a suspension, not suspension itself.
             *
             * `ClearanceStatus::Approved` KEEPS the word, deliberately. One
             * permit is approved by its own office, and that is what the
             * word now means with nothing else competing for it — the two
             * machines stop sharing a label that meant different things.
             */
            self::Approved => 'Completed',
            self::Rejected => 'Rejected',
            self::Returned => 'Returned',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Terminal states cannot transition further. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Cancelled], true);
    }

    /**
     * The legality table: which statuses may follow this one.
     *
     * `WorkflowService::transition()` is the only writer of `applications.status`
     * and consults this on every move, so a route added next year gets the
     * check for free. The cost of not having it was observed rather than
     * theorised: approving one office's review on a filing that had already
     * been REJECTED moved it `rejected → for_inspection` and booked a site visit
     * against a filing the LGU had refused.
     *
     * Read the table as five claims:
     *
     *  - **Terminal is terminal.** Approved, Rejected and Cancelled list
     *    nothing. A decision that can be walked back by a routine action is not
     *    a decision, and `decided_at`, the issued permits and the rejection
     *    reason are all already written by the time we are here.
     *
     *  - **Returned only goes forward.** To ForApproval (the applicant
     *    resubmits and BPLO reads it again) or to Rejected (BPLO gives up on
     *    it). It must not reach PendingPayment directly: the whole point of
     *    returning a form is that BPLO has not accepted it, and billing for it
     *    would say they had.
     *
     *  - **ForFinalApproval can go back.** This is the edge that did not exist
     *    in the old machine and it is not decoration. The filing arrives here
     *    because every required permit is approved; if one then stops being
     *    approved — a re-inspection is opened, an office reverses itself —
     *    BPLO must not be left holding an Approve button over an application
     *    that no longer qualifies. `WorkflowService::refreshReadiness()` is what
     *    walks it back.
     *
     *  - **Cancellation is the applicant's, and only before they have paid.**
     *    Draft, ForApproval, Returned, PendingPayment. This mirrors
     *    `ApplicationController::cancel()`'s own allow-list rather than widening
     *    it; if that list changes, this must change with it. Past payment it is
     *    refused deliberately: money has changed hands and offices are working,
     *    so ending the filing is a decision with a refund attached and not a
     *    button.
     *
     *  - **Rejection is reachable from every non-terminal status**, which looks
     *    permissive and is deliberate. `ApplicationController::reject()` states
     *    exactly that rule, and BPLO refusing an obviously bogus filing at
     *    `pending_payment` should not have to wait for the applicant to pay for
     *    it first. Mirrored here rather than tightened, because two places
     *    disagreeing about when a rejection is allowed is a worse failure than
     *    either rule alone.
     *
     * Self-transitions are absent on purpose. `transition()` treats from === to
     * as a no-op and returns before consulting this, because a status that did
     * not change is not movement and must not write a history row claiming it
     * was. Do not add self-edges here to "fix" that.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::ForApproval, self::Cancelled, self::Rejected],
            /*
             * `Approved` is here for ONE shape of filing: a renewal carrying no
             * business permit.
             *
             * Such a filing is never billed and never reaches BPLO (client,
             * 17 September 2026), so it has no Pending Payment and no Final
             * Approval to pass through. It is done the moment the office that
             * grants its permit has granted it — there is nothing left to
             * decide, the paperwork was accepted and the visit passed.
             *
             * `WorkflowService::refreshReadiness` is the only thing that takes
             * this edge, and only when `Application::defersPayment()` is true.
             * This table is the LEGALITY of a move, not the choice of one — so
             * an ordinary filing's route out of ForApproval is still the bill.
             */
            self::ForApproval => [
                self::PendingPayment,
                self::Approved,
                self::Returned,
                self::Cancelled,
                self::Rejected,
            ],
            self::Returned => [self::ForApproval, self::Cancelled, self::Rejected],
            /*
             * ── One way out of the bill, since 4 October 2026 ────────────────
             *
             * Payment releases the Mayor's Permit, so the filing is Approved
             * the moment the money clears — whether or not it still has other
             * permits to gather. There were two ways out of here until the
             * client had `awaiting_other_permits` removed (*"we no longer need
             * that status"*), and it could go because it never gated anything:
             * it named the period AFTER the certificate was handed over, not a
             * wait before it.
             *
             * What that status used to say — that the filing is still open —
             * is now `decided_at`, null until the last permit is granted. See
             * `Application::isDecided()`.
             *
             * `ForFinalApproval` stays reachable. A filing that becomes ready
             * with no confirmed RA 11032 category falls back to BPLO rather
             * than issuing against a deadline nobody set; that is the only
             * route left to it, and it is not about other permits at all.
             */
            self::PendingPayment => [
                self::ForFinalApproval,
                /*
                 * Every paid filing lands here now, gathering or not. It was
                 * added on 3 October 2026 for the one case that had nothing
                 * left to wait for — the common January renewal, the Mayor's
                 * Permit by itself — and on 4 October it became the only
                 * destination, when the status that covered the other case
                 * was removed.
                 */
                self::Approved,
                self::Cancelled,
                self::Rejected,
            ],
            /*
             * ── Nothing leads back to a gathering stage, because there is none ──
             *
             * `ForFinalApproval` could return to `AwaitingOtherPermits` until
             * 4 October 2026. With the status gone there is nowhere to return
             * to, and nothing ever took the route: no caller moved a filing
             * backwards out of Final Approval. The gathering it named is a
             * property of the permit rows now, which this table has no say
             * over.
             */
            self::ForFinalApproval => [self::Approved, self::Rejected],
            /*
             * ── Approved is not the end any more, not on its own ────────────
             *
             * It became the status a paid filing WAITS at on 4 October 2026,
             * when `awaiting_other_permits` was removed. So the two moves that
             * status allowed have to be allowed from here, or the removal
             * would have taken them with it:
             *
             *  - Rejected. An office can still refuse a clearance while the
             *    filing gathers, and BPLO can still refuse the filing.
             *  - ForFinalApproval, which `refreshReadiness` uses for a filing
             *    that becomes ready with no confirmed RA 11032 category.
             *
             * What stops a FINISHED filing taking either is not this table —
             * it reads the status and the two wear the same one — but
             * `Application::isDecided()`, which the callers check first. The
             * claim "terminal is terminal" above now rests on that guard
             * rather than on this row.
             */
            self::Approved => [self::ForFinalApproval, self::Rejected],
            self::Rejected, self::Cancelled => [],
        };
    }

    /** May this filing legally move to $to? See allowedNext() for the reasoning. */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /**
     * Is this filing waiting on the APPLICANT rather than on an office?
     *
     * The question the abandonment sweep asks, and the reason it is here
     * rather than in the command: a status added later is somebody's move,
     * and whoever adds it should decide whose while they are looking at
     * this table.
     *
     * ── What is deliberately NOT on this list ──────────────────────────
     *
     * `ForApproval` — BPLO is reading the form. An applicant who filed and
     * waited has done everything asked of them, and a sweep that removed
     * their filing would be punishing them for the office's backlog. That
     * is also the one case RA 11032 puts a clock on, and the clock runs
     * against the city.
     *
     * `Approved` with permits still outstanding — mixed, and that is why
     * it is not here. The applicant applies for each clearance, but the
     * offices then hold them for days at a time, and from the outside such
     * a filing may be waiting on either. Sweeping it would eventually
     * delete a filing whose last five days were CENRO's. (This was the
     * `AwaitingOtherPermits` status until 4 October 2026; the state it
     * named is now `Application::isDecided()` answering false.)
     *
     * `ForFinalApproval` — BPLO's, by definition.
     *
     * Written as a list of the applicant's states rather than "not one of
     * the office's", so a status added later is nobody's until somebody
     * says so — the safe default for a rule that removes records.
     */
    public function awaitsApplicant(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Returned,
            self::PendingPayment,
        ], true);
    }

    /**
     * Has the applicant paid? True for every status at or past the payment.
     *
     * Asked by the clearance stage (its gate is payment, per the client's
     * verified procedure) and by anything that must not act on an unpaid
     * filing. Written as a list of paid states rather than "not one of the
     * unpaid ones" so that a status added later is unpaid until someone says
     * otherwise — the safe default for a gate that guards money.
     */
    public function isPaid(): bool
    {
        return in_array($this, [
            self::ForFinalApproval,
            self::Approved,
        ], true);
    }

    /**
     * May money be taken against this filing at all?
     *
     * The LOWER bound on payment, and it is a different question from
     * `isPaid()`. Paid asks whether the first payment has happened; this asks
     * whether the filing has reached the point where a bill is owed. Draft,
     * ForApproval and Returned are all before that point: BPLO has not accepted
     * the form, so nothing has been billed and nothing may be collected.
     *
     * ── Why this exists ───────────────────────────────────────────────────
     *
     * It was missing, and the gap was live. `PaymentController::pay` refused a
     * closed filing and a settled one, and had no branch at all for a filing
     * that had not been billed yet — a hole that did not exist while submission
     * led STRAIGHT to PendingPayment, because there was then no status between
     * Draft and the bill. The 6 September flow put ForApproval in that gap, the
     * wizard called `pay()` immediately after `submit()`, and the money went
     * through at ForApproval: charged, recorded, and then ignored by
     * `WorkflowService::onPaymentCompleted`, which returns early on any status
     * but PendingPayment. The filing sat unmoved with a completed payment
     * against it, and BPLO's approval then asked the applicant to pay again.
     *
     * Do NOT confuse this with the upper bound. The long note in
     * `PaymentController::pay` explains why payment is deliberately allowed
     * AFTER PendingPayment — an officer can raise an assessment, and a balance
     * no screen can settle is worse than one paid late. That reasoning is
     * untouched. This closes the other end.
     */
    public function isBillable(): bool
    {
        return $this === self::PendingPayment || $this->isPaid();
    }
}

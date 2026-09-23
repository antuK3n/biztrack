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
    case AwaitingOtherPermits = 'awaiting_other_permits';
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
             * BPLO approves a filing twice — once on the form before there is
             * a bill, once again after every other permit is in — and the two
             * were called "For Approval" and "For Final Approval". The second
             * announced itself as one of a pair and the first did not, so an
             * applicant at the start could not tell they were at the start, and
             * the tracking card fell back to saying only that no bill existed
             * yet.
             */
            self::ForApproval => 'For Initial Approval',
            self::PendingPayment => 'Pending Payment',
            self::AwaitingOtherPermits => 'Awaiting Other Permits',
            self::ForFinalApproval => 'For Final Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Disapproved',
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
             * ── Two ways out of the bill, and which one depends on the type ──
             *
             * A NEW filing goes to AwaitingOtherPermits: the five clearances do
             * not exist yet and the applicant has to obtain each one.
             *
             * A RENEWAL goes straight to ForFinalApproval, because there is
             * nothing to gather. The client's decision of 17 September 2026:
             * *"there should no longer be Awaiting Other Permits status because
             * the applicant may already have valid other permit that he/she can
             * submit in the Upload/Submit button."* The copies are uploaded
             * before the filing is ever paid for, so by the time the money
             * clears the evidence is already in — and a stage named for waiting
             * would be a stage that waits for nothing.
             *
             * Both are listed here rather than branched, because this table is
             * the LEGALITY of a move and not the choice of one.
             * `onPaymentCompleted` makes the choice, and it is the only caller.
             */
            self::PendingPayment => [
                self::AwaitingOtherPermits,
                self::ForFinalApproval,
                self::Cancelled,
                self::Rejected,
            ],
            /*
             * ── A new filing issues straight from here ───────────────────────
             *
             * `Approved` was added on 18 September 2026. Client's question, and
             * it answered itself once asked: *"what is the purpose of the BPLO
             * checking if all other permits are legit, when those permits are
             * APPLIED DIRECTLY in BizTrack itself?"*
             *
             * On a NEW filing there is nothing to check. All five clearances
             * were applied for in this system, each office approved its own in
             * this system, and each inspection is a row against the pivot. BPLO
             * reading them was the system checking its own records against
             * itself, and the RA 11032 clock ran the whole time it waited.
             *
             * `ForFinalApproval` stays in this list and stays reachable, because
             * a RENEWAL still stops there — on that path BPLO reads certificate
             * copies the applicant uploaded, which is a real reading of real
             * evidence. The stage was hollow on one path, not both.
             */
            self::AwaitingOtherPermits => [self::Approved, self::ForFinalApproval, self::Rejected],
            self::ForFinalApproval => [self::Approved, self::AwaitingOtherPermits, self::Rejected],
            self::Approved, self::Rejected, self::Cancelled => [],
        };
    }

    /** May this filing legally move to $to? See allowedNext() for the reasoning. */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
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
            self::AwaitingOtherPermits,
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

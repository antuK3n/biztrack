<?php

namespace App\Enums;

/** applications.status machine (master plan §6.2). */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PendingPayment = 'pending_payment';
    case UnderReview = 'under_review';
    case ForInspection = 'for_inspection';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    /**
     * The one wording for a status, in the LGU's vocabulary (rehaul spec §4–5).
     *
     * These are not free text. The same state was called three different things
     * — the API said "Awaiting payment", the web said "For payment", the design
     * said "Pending Payment" — and "Under review" was on a row inside a queue
     * tab captioned "For Approval", so the screen contradicted itself in one
     * glance. An applicant ringing the office about their "For payment"
     * application was describing a state no officer had a name for.
     *
     * The values (`pending_payment`, `under_review`, …) are the contract with
     * the database, `application_status_history` and every `?status=` query
     * param. Only these labels are the LGU's to change.
     *
     * The web keeps its own copy in `web/src/lib/status.ts` because it labels
     * statuses the API never sends it (a locally-derived `issued`) and cannot
     * wait for a round trip to caption a filter. Two copies drift, so
     * `tests/Feature/StatusLabelParityTest.php` reads that file and fails the
     * build the moment either side is edited alone. Change a label here and
     * that test tells you exactly which line of TypeScript to change with it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::PendingPayment => 'Pending Payment',
            self::UnderReview => 'For Approval',
            self::ForInspection => 'For Inspection',
            self::Approved => 'Approved',
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
     * The legality table: which statuses may follow this one (§6.2).
     *
     * This did not exist. `WorkflowService::transition()` wrote whatever it was
     * handed, so the machine described in the docblock above was a description
     * of intent and nothing enforced it. The cost was observed, not theorised:
     * approving one office's review on a filing that had already been REJECTED
     * moved it `rejected → for_inspection`, wrote the history row, and booked a
     * site visit against a filing the LGU had refused. 101 rejected filings in
     * the register carry a still-open assignment, so 101 rejections could be
     * undone by an office that has no power to reverse one.
     *
     * It was unreachable until 5da4daa. `afterReviewProgress()` used to open
     * with "every assignment is completed, or return", and a returned or
     * rejected filing never satisfies that, so the missing table was covered by
     * an accident of a different rule. Deleting that rule uncovered it. That is
     * the argument for stating legality here rather than re-deriving it from
     * whatever guard happens to sit upstream: the next person to delete a gate
     * for a good reason must not silently delete this one with it.
     *
     * Read the table as three claims:
     *
     *  - Terminal is terminal. Approved, Rejected and Cancelled list nothing.
     *    A decision that can be walked back by a routine action is not a
     *    decision, and `decided_at`, the issued permits and the rejection
     *    reason are all already written by the time we are here.
     *  - `Returned` may only go forward to UnderReview (the applicant
     *    resubmits) or to Rejected (the office gives up on it). It must NOT
     *    reach ForInspection: the filing is in the applicant's hands being
     *    revised, and moving it would cancel their revision request without
     *    telling anyone — the whole point of returning it.
     *  - Cancellation is the applicant's, and only before review starts. This
     *    mirrors ApplicationController::cancel()'s own allow-list rather than
     *    widening it; if that list ever grows, this must grow with it.
     *  - Rejection is reachable from EVERY non-terminal status, which looks
     *    permissive and is deliberate: ApplicationController::reject() states
     *    exactly that rule ("if isTerminal → already decided") and a filing can
     *    be refused before anyone has been paid — an office rejecting an
     *    obviously bogus filing at `pending_payment` should not have to wait for
     *    the applicant to pay for it first. Mirrored here rather than tightened,
     *    because two places disagreeing about when a rejection is allowed is a
     *    worse failure than either rule alone.
     *
     * Self-transitions are absent on purpose. `transition()` treats from === to
     * as a no-op and returns before consulting this, because a status that did
     * not change is not movement and must not write a history row that claims
     * it was. Do not add self-edges here to "fix" that.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled, self::Rejected],
            self::Submitted => [self::PendingPayment, self::Cancelled, self::Rejected],
            self::PendingPayment => [self::UnderReview, self::Cancelled, self::Rejected],
            self::UnderReview => [self::ForInspection, self::Returned, self::Rejected, self::Approved],
            self::ForInspection => [self::Returned, self::Rejected, self::Approved],
            self::Returned => [self::UnderReview, self::Rejected],
            self::Approved, self::Rejected, self::Cancelled => [],
        };
    }

    /** May this filing legally move to $to? See allowedNext() for the reasoning. */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}

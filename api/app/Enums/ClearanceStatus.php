<?php

namespace App\Enums;

/**
 * `application_permit_types.status` — ONE other permit's machine.
 *
 * The second of the two machines (docs/application-flow-2026-09.md). The pivot
 * row between an application and a permit type used to carry nothing but the
 * link; it carries the permit's own progress now, because the six other permits
 * run independently and each has to be able to say where it is without
 * consulting the others.
 *
 * That independence is the client's verified procedure, not an optimisation:
 * "the other 6 permits are automatically released once they are approved by
 * their respective admins; no need to wait for each other to be approved." CHO
 * can be inspecting while BFP is still reading and CPDO has already issued.
 *
 * The `BUSINESS` pivot row is the exception and does not use the middle of this
 * machine. It tracks the main form, which BPLO reads once at the start, so it
 * goes ForApproval → Approved and never sees ForInspection: there is no visit
 * of its own, and putting a seventh inspection on every filing would stall
 * issuance behind one nobody performs.
 *
 * WHEN that row reaches Approved moved on 24 September 2026. It used to be the
 * end of the filing, issued on the strength of the other five; it is now
 * PAYMENT, on the LGU’s clarification that the business permit is released
 * once the applicant has paid. See `WorkflowService::onPaymentCompleted`. The
 * other five still run their own machines afterwards, and one of them being
 * REFUSED is what suspends the certificate this row issued.
 */
enum ClearanceStatus: string
{
    /*
     * ── Rejected is BACK, and this is the third decision about it ─────────────
     *
     * The LGU clarified the new-application flow on 24 September 2026: the
     * business permit is released once the applicant PAYS, not once all six
     * permits are in — and *"can be suspended if the other permits applied to
     * were rejected."*
     *
     * That sentence needs a rejection to exist. Under the old flow it did not:
     * an unapprovable permit simply held the filing at `awaiting_other_permits`
     * for ever, and the business had no permit to lose because none had been
     * issued. Withholding was the whole sanction. Now the certificate is
     * already in the applicant's hand by the time an office reads its permit,
     * so the sanction has to be something that can be done to a live permit,
     * and the trigger has to be a state an office can actually set.
     *
     * `Returned` cannot be that trigger, and the distinction is the same one
     * the note below drew — it was right, and it is the reason this is a new
     * state rather than a reuse. A return is FIXABLE and repeats as often as it
     * needs to; suspending a business permit every time an office asked for a
     * clearer scan would be absurd. Rejection is the office saying the permit
     * cannot be granted as applied for, which is the only thing that should
     * cost the applicant their business permit.
     *
     * It is not terminal, because the client also chose the way back: the
     * applicant re-applies for the refused permit, and when no permit on the
     * filing is rejected any more the business permit returns to Active by
     * itself. So `Rejected → ForApproval` is legal below, which is the route
     * `refileClearance()` was written for in August and never wired to
     * anything. This time the route exists: `ClearanceController::reject` sets
     * it and the ordinary apply path clears it.
     *
     * ── The 17 September reasoning, kept because it still explains Return ────
     *
     * The state was removed because nothing could ever produce it.
     * `rejectClearance()` had
     * no controller and no route; `refileClearance()` — the documented way back
     * — had neither either. So a state existed that no office could set and no
     * applicant could clear, while the notification for it told them "You can
     * file for it again" and pointed at a button that was never built.
     *
     * The client settled it on 17 September 2026, asked directly: *"I think
     * Return is enough already."* And it is. Everything an office might refuse a
     * permit for divides in two:
     *
     *  - FIXABLE — a cut-off scan, an expired lease, a wrong answer on the
     *    sheet. That is `Returned`, which works today: the sheet reopens
     *    editable (`OfficeFormController::ownerMayEdit`), the applicant fixes it
     *    and resubmits, and the permit goes back to ForApproval. It can happen
     *    as many times as it needs to.
     *  - NOT FIXABLE — the site's zoning forbids the use, the business type is
     *    not permitted here. No re-upload helps, and the honest answer is not a
     *    permit state: it is BPLO rejecting the filing (`rejectApplication`,
     *    which is kept and is a different thing) or the applicant changing
     *    something real and filing again.
     *
     * Removing it was safe to the row: the register had 0 permits at `rejected`
     * and 0 stored reasons, so there was nothing to migrate and nothing to
     * strand. A failed INSPECTION never needed it either — `recordInspection`
     * deliberately moves nothing on a failure and leaves the permit at
     * ForInspection, so the office can book a re-visit against the kept row.
     *
     * What has changed since that note is only the NOT FIXABLE half. It said
     * the honest answer there was BPLO rejecting the whole filing. That was
     * true while the business permit was withheld until the end; it is false
     * now, because the filing has already produced a permit and killing the
     * filing would revoke a certificate the LGU has decided should merely be
     * suspended. The fixable/not-fixable split stands — it is now a split
     * between two permit states rather than between a permit and a filing.
     */
    case NotStarted = 'not_started';
    case ForApproval = 'for_approval';
    case ForInspection = 'for_inspection';
    case Approved = 'approved';
    case Returned = 'returned';
    case Rejected = 'rejected';

    /**
     * The wording the applicant and the officer both see.
     *
     * Deliberately the same words as `ApplicationStatus` where the state is the
     * same idea. An applicant looking at "For Approval" on their Sanitary
     * Permit and "Awaiting Other Permits" on the application should not have to
     * learn that these are two different vocabularies.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not Yet Submitted',
            self::ForApproval => 'For Approval',
            self::ForInspection => 'For Inspection',
            self::Approved => 'Approved',
            self::Returned => 'Returned',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Approved is the only terminal state, and now the only one there could be.
     *
     * The permit is minted and numbered by then, so there is nothing further to
     * move it to. Every other state has a way forward: Returned goes back to
     * ForApproval when the applicant resubmits, and a failed inspection leaves
     * the permit at ForInspection so a re-visit can be booked against it.
     *
     * Rejected is deliberately NOT terminal, which is what this method said in
     * August and is saying again. The applicant may re-apply for a refused
     * permit — that is the route back from suspension the client chose on
     * 24 September 2026 — so a rejected permit can still move, and only an
     * issued one cannot.
     */
    public function isTerminal(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Has an office REFUSED this permit as applied for?
     *
     * The trigger for suspending the business permit, as its own predicate so
     * that the suspension rule and the reinstatement rule read the same
     * question rather than two comparisons that can drift.
     */
    public function isRefused(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * Does this permit still owe the application work?
     *
     * The predicate behind `for_final_approval`: BPLO may approve the overall
     * application only when no required permit is outstanding.
     *
     * Kept as its own method rather than inlined as `!== Approved`, even though
     * that is now all it is. It answers a different QUESTION from
     * `isTerminal()` — "does the application still need something from this
     * permit" versus "can this permit still move" — and the two agreeing today
     * is a fact about the current states, not a rule that should be collapsed.
     */
    public function isOutstanding(): bool
    {
        return $this !== self::Approved;
    }

    /**
     * The legality table for one permit.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            // Applying or uploading is what starts it. Both land in ForApproval:
            // an upload still gets read by the office, it is just an image
            // rather than a form.
            self::NotStarted => [self::ForApproval],

            /*
             * ── No Rejected here, and that is the client's correction ──────
             *
             * It was in this list for a few hours on 24 September 2026, and
             * the client caught the mistake in the reasoning behind it:
             * *"rejection comes mainly from 'For Inspection', not 'For
             * Approval'"*. They are right, and it follows from what each
             * stage IS.
             *
             * At ForApproval the officer is reading paperwork. Everything
             * wrong at that stage is a document or an answer, and both are
             * fixable — `Returned` reopens the sheet, and an Other
             * Requirement asks for a document without handing the permit
             * back at all. Refusing on paperwork would suspend a business's
             * Mayor's Permit over a form, which is not a basis anyone would
             * defend at a counter.
             *
             * There is a real cost, stated: an office that reads something
             * FATAL in the paperwork — a use the address cannot carry —
             * cannot refuse from here and must book a visit it knows will
             * fail. That is the right trade and it is rare: a business that
             * cannot be licensed at all is BPLO's `rejectApplication`, which
             * ends the filing, and a clearance office suspending a permit
             * over documents is not the escalation for it.
             */
            self::ForApproval => [self::ForInspection, self::Returned],

            // No route back to ForApproval. Once the office has accepted the
            // paperwork and booked a visit, what is outstanding is the visit;
            // sending it back to the reading queue would lose the booking and
            // tell the applicant nothing about why.
            /*
             * ── The ONLY door to a refusal, and therefore to a suspension ──
             *
             * The premises are the commonest reason a permit cannot be
             * granted, and an inspector who finds an unsafe kitchen learns it
             * on the visit rather than from the form. So a refusal always
             * follows somebody having been to look — which is what makes
             * taking a business's Mayor's Permit defensible.
             *
             * It sits alongside, not instead of, the re-visit.
             * `recordInspection` deliberately moves nothing on a failure
             * (`if (! $result->progresses()) return;`), leaving the permit
             * here so another visit can be booked against the kept row. So
             * the office has two answers after a bad visit: come back and
             * look again, or refuse. Rejecting is the office saying it will
             * not be returning.
             */
            self::ForInspection => [self::Approved, self::Rejected],

            // Returned resumes where it left off, as many times as it takes:
            // nothing caps how often an office may ask for a correction, which
            // is most of why Return is enough on its own.
            self::Returned => [self::ForApproval],

            /*
             * The way back, and the reason Rejected is not terminal. The
             * applicant applies for the refused permit again and it re-enters
             * the office's reading queue; when nothing on the filing is
             * refused any more, `WorkflowService::reconsiderSuspension()`
             * returns the business permit to Active.
             */
            self::Rejected => [self::ForApproval],

            self::Approved => [],
        };
    }

    /** May this permit legally move to $to? */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}

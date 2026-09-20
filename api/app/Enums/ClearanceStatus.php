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
 * machine. It tracks the main form, which BPLO reads once at the start and
 * signs off at the end, so it goes ForApproval → Approved and never sees
 * ForInspection — the Mayor's Permit is issued on the strength of the other
 * permits rather than a visit of its own. Putting a seventh inspection on every
 * filing would stall issuance behind a visit nobody performs.
 */
enum ClearanceStatus: string
{
    /*
     * ── There is no Rejected, and that is a decision ──────────────────────────
     *
     * There was one, and nothing could ever produce it. `rejectClearance()` had
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
     */
    case NotStarted = 'not_started';
    case ForApproval = 'for_approval';
    case ForInspection = 'for_inspection';
    case Approved = 'approved';
    case Returned = 'returned';

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
     * This used to read "Approved is terminal; Rejected is not" and explained at
     * length how a rejected permit could be re-filed. See the note on the cases
     * for why that state is gone.
     */
    public function isTerminal(): bool
    {
        return $this === self::Approved;
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

            self::ForApproval => [self::ForInspection, self::Returned],

            // No route back to ForApproval. Once the office has accepted the
            // paperwork and booked a visit, what is outstanding is the visit;
            // sending it back to the reading queue would lose the booking and
            // tell the applicant nothing about why.
            self::ForInspection => [self::Approved],

            // Returned resumes where it left off, as many times as it takes:
            // nothing caps how often an office may ask for a correction, which
            // is most of why Return is enough on its own.
            self::Returned => [self::ForApproval],

            self::Approved => [],
        };
    }

    /** May this permit legally move to $to? */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}

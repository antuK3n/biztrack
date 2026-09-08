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
    case NotStarted = 'not_started';
    case ForApproval = 'for_approval';
    case ForInspection = 'for_inspection';
    case Approved = 'approved';
    case Rejected = 'rejected';
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
            self::NotStarted => 'Not Started',
            self::ForApproval => 'For Approval',
            self::ForInspection => 'For Inspection',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Returned => 'Returned',
        };
    }

    /**
     * Approved is terminal; Rejected is not.
     *
     * A rejected permit can be re-filed — back to NotStarted, the applicant
     * starts that one office's application again — because the client's rule is
     * that a rejection kills only that permit and not the application. Leaving
     * it terminal would mean one office's no permanently blocks
     * `for_final_approval` with no way forward but abandoning the whole filing,
     * which is exactly what they said should NOT happen.
     *
     * Approved really is terminal: the permit is minted and numbered by then.
     */
    public function isTerminal(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Does this permit still owe the application work?
     *
     * The predicate behind `for_final_approval`: BPLO may approve the overall
     * application only when no required permit is outstanding. Rejected counts
     * as outstanding — the requirement is not met, it is refused — which is why
     * this is not simply `!== Approved` written twice.
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

            self::ForApproval => [self::ForInspection, self::Returned, self::Rejected],

            // No route back to ForApproval. Once the office has accepted the
            // paperwork and booked a visit, what is outstanding is the visit;
            // sending it back to the reading queue would lose the booking and
            // tell the applicant nothing about why.
            self::ForInspection => [self::Approved, self::Rejected],

            self::Returned => [self::ForApproval, self::Rejected],

            // Re-file. See isTerminal().
            self::Rejected => [self::NotStarted],

            self::Approved => [],
        };
    }

    /** May this permit legally move to $to? */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }
}

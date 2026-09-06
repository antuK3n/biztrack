<?php

namespace App\Enums;

/**
 * officer_requests.status lifecycle:
 *
 *   pending → submitted → fulfilled
 *                      ↘ needs_resubmission → submitted → …
 *                      ↘ rejected
 *
 * ── The words on screen ──────────────────────────────────────────────────────
 *
 * The client set the vocabulary: "No document submitted = Pending. Document
 * submitted = For Review. Approved = Completed. Rejected = Pending / Needs
 * Resubmission." The stored values are unchanged — renaming them would rewrite
 * live rows for a caption — so the mapping lives in label() instead:
 *
 *   submitted  → "For Review"   (it is with the office, not with the applicant)
 *   fulfilled  → "Approved"     ("Fulfilled" is not a word an applicant uses)
 *
 * ── Why NeedsResubmission is not a closed state ──────────────────────────────
 *
 * It is the difference between "you got it wrong" and "you got it wrong and
 * there is nothing you can do about it". The client is explicit: "Do NOT mark
 * the requirement as completed after rejection. The requirement should remain
 * active until the Admin approves a valid submission." So the office's Reject
 * lands here — sent back, with a reason, still answerable — and the requirement
 * is once again waiting on the applicant, exactly as Pending is.
 *
 * `rejected` stays as the one genuinely terminal refusal: a requirement raised
 * in error, or one that no longer applies. Nothing in the review screen offers
 * it, because rejecting a DOCUMENT is not the same act as withdrawing a
 * REQUIREMENT, and only the second one ends the matter.
 */
enum OfficerRequestStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Fulfilled = 'fulfilled';
    case NeedsResubmission = 'needs_resubmission';
    case Rejected = 'rejected';

    /**
     * Is this requirement still the applicant's to answer?
     *
     * Pending and Submitted have always accepted a reply; NeedsResubmission is
     * the whole point of the state. Fulfilled and Rejected are closed.
     */
    public function acceptsResponse(): bool
    {
        return match ($this) {
            self::Pending, self::Submitted, self::NeedsResubmission => true,
            self::Fulfilled, self::Rejected => false,
        };
    }

    /**
     * Is the ball in the APPLICANT's court?
     *
     * The client's "Pending" family: nothing has been submitted, or what was
     * submitted came back. Both mean the same thing to a business owner — you
     * owe us a document — and both must be counted together wherever the app
     * says how many requirements are outstanding. Submitted is deliberately not
     * here: the applicant has done their part and is waiting on the office.
     */
    public function awaitsApplicant(): bool
    {
        return match ($this) {
            self::Pending, self::NeedsResubmission => true,
            self::Submitted, self::Fulfilled, self::Rejected => false,
        };
    }

    /** Is the ball in the OFFICE's court — a submission waiting to be reviewed? */
    public function awaitsOffice(): bool
    {
        return $this === self::Submitted;
    }

    /** Neither side has anything left to do. */
    public function isClosed(): bool
    {
        return ! $this->acceptsResponse();
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            // With the office. "Submitted" describes what the applicant did;
            // "For Review" describes where the requirement now is, which is what
            // both sides actually need to read off a status column.
            self::Submitted => 'For Review',
            self::Fulfilled => 'Approved',
            self::NeedsResubmission => 'Needs Resubmission',
            self::Rejected => 'Rejected',
        };
    }
}

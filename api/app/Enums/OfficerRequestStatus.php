<?php

namespace App\Enums;

/**
 * officer_requests.status lifecycle:
 *
 *   pending ⇄ submitted → fulfilled
 *      ↑           ↓
 *      └── rejected / needs_resubmission ──┘
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
 * ── Approval is the only end ────────────────────────────────────────────────
 *
 * `rejected` used to be terminal: once an office refused a document the
 * applicant could never answer again, so an office that wanted a clearer copy
 * had to either accept the bad one or raise a second requirement from scratch.
 * The client has ruled the other way twice — "Do NOT mark the requirement as
 * completed after rejection", and then plainly: if it is rejected, the owner
 * can send another one. So rejection now returns the requirement to the
 * applicant with a reason attached, and a REQUIREMENT ends only when the office
 * approves a submission.
 *
 * `rejected` and `needs_resubmission` are therefore the same situation wearing
 * two names: refused, explained, still open. Both are kept because both are
 * already stored and both read naturally in different mouths — an office
 * "rejects" a document, and an applicant is asked to "resubmit" — and
 * collapsing them would rewrite history rows for a caption.
 *
 * CONSEQUENCE, recorded because it is a real hole and not an oversight: there
 * is now no way to withdraw a requirement raised in error. Approving one to
 * make it go away records an approval that never happened. If the LGU needs
 * that, it wants its own state (`cancelled`) rather than borrowing one of these.
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
     * Everything except an approval. Rejected is in this list deliberately —
     * see the note above: refusing a document does not close the requirement,
     * it hands it back, and the applicant must be able to send another one.
     */
    public function acceptsResponse(): bool
    {
        return match ($this) {
            self::Pending, self::Submitted, self::NeedsResubmission, self::Rejected => true,
            self::Fulfilled => false,
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
            self::Pending, self::NeedsResubmission, self::Rejected => true,
            self::Submitted, self::Fulfilled => false,
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

    /**
     * The statuses an OFFICE may set directly.
     *
     * Three, because those are the three the client named: pending, approved,
     * rejected. `submitted` is absent because it is not the office's to set —
     * it is what the applicant's own upload means — and inviting an officer to
     * mark a requirement "For Review" when nothing has been submitted would
     * make the status a claim rather than a fact.
     *
     * `needs_resubmission` is absent for the opposite reason: it is a synonym
     * for rejected here, still accepted by the endpoint so existing rows and
     * callers keep working, just not offered twice on one control.
     *
     * @return list<self>
     */
    public static function officeSettable(): array
    {
        return [self::Pending, self::Fulfilled, self::Rejected];
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

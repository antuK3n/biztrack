<?php

namespace App\Enums;

/**
 * officer_requests.status lifecycle:
 *
 *   pending → submitted → fulfilled
 *                      ↘ needs_resubmission → submitted → …
 *                      ↘ rejected
 *
 * NeedsResubmission is the difference between "you got it wrong" and "you got
 * it wrong and there is nothing you can do about it". Rejected is final and
 * closes the requirement; NeedsResubmission sends it back to the applicant with
 * a remark and keeps it answerable, which is what an office wants nearly every
 * time it turns something down — a blurred scan is not grounds for refusing a
 * permit, it is grounds for asking again.
 *
 * Before this there was no such state: rejecting a requirement made it
 * permanently unanswerable, so an office that wanted a clearer copy had to
 * either accept the bad one or raise a second requirement from scratch.
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

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Fulfilled => 'Fulfilled',
            self::NeedsResubmission => 'Needs Resubmission',
            self::Rejected => 'Rejected',
        };
    }
}

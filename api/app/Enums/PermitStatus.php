<?php

namespace App\Enums;

enum PermitStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Suspended = 'suspended';
    /**
     * Replaced by a renewal, before its own expiry date arrived.
     *
     * Added 9 September 2026, when a renewal was found to leave the business
     * holding two live certificates of the same type: renewing a sanitary
     * permit 60 days early produced MCS-2025-000099 (active to 7 November) and
     * MCS-2026-000001 (active to next September), both on the applicant's
     * profile and both offered by the renewal picker — so the following year
     * they could renew the one that had already been replaced.
     *
     * Not `expired`, which was the cheaper option and the wrong one: an expired
     * permit is one whose term ran out, and rewriting `valid_until` to today to
     * make that true would put a false date in the register. The expiry
     * analytics and the renewal-risk model both read those dates, and a permit
     * recorded as lapsing two months before it did is a permit the model learns
     * from. So the term stands as issued and the STATUS says what happened.
     */
    case Superseded = 'superseded';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Is this a certificate the business can act on today?
     *
     * The one predicate for "holds a live permit", so that a fifth status added
     * next year has one place to declare itself rather than being missed by
     * whichever `=== Active` comparison nobody remembered.
     */
    public function isLive(): bool
    {
        return $this === self::Active;
    }
}

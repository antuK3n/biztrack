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
}

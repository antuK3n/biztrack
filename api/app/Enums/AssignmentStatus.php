<?php

namespace App\Enums;

/** application_assignments.status (rejection is application-level). */
enum AssignmentStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Returned = 'returned';

    /*
     * The filing ended while this office's review was still open: BPLO
     * rejected it, or the applicant cancelled it. Written by
     * WorkflowService::transition() on the move to Rejected or Cancelled, and
     * once over the existing register by the migration of 1 October 2026.
     *
     * Not Completed, on purpose. Completed means the office finished its
     * review and is what every turnaround figure is measured from; a review
     * nobody finished is not a review handled, and stamping `completed_at` on
     * it would put the time to the rejection into the office's average.
     * `completed_at` therefore stays null. Before this existed the row stayed
     * `pending` for ever, and the office's backlog counted filings that no
     * longer existed (106 on the copy of the register this was measured on).
     */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Returned => 'Returned',
            self::Closed => 'Closed',
        };
    }

    /**
     * The states that still want something from the office: the open
     * backlog. Completed and Closed are the two ends.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Pending->value, self::InProgress->value, self::Returned->value];
    }
}

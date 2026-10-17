import { assignments } from '../../lib/resources'
import type { ApplicationStatus } from '../../lib/types'

/**
 * A narrowing the office home page's tiles open the queue on (18 October
 * 2026): "Waiting on you" and "Overdue". Not a control on this screen — the
 * reader arrives with one set, sees it named above the list, and clears it;
 * picking another stage clears it too, as it does the status filter.
 *
 * ── What "waiting on you" is ──────────────────────────────────────────────
 *
 * The For Approval stage less what is with the OWNER. That stage keeps a
 * filing the office sent back (`returned`, on the assignment or the filing)
 * because it is still the office's open case; it is not the office's move,
 * and a tile saying "waiting on you" over it would be counting the owner's
 * homework. So: this office's review not yet done (`pending`,
 * `in_progress`), on a filing at `for_approval` (BPLO's review of the main
 * form) or `approved` (an office's review of its own clearance).
 *
 * "Overdue" is the same rows whose RA 11032 deadline has passed — the
 * filing's deadline, not an office's share of it, which the statute does not
 * set (see `overdue` on AssignmentController::index).
 *
 * The tile counts with `countQueueFocus` below, which sends exactly the
 * request this screen sends for the same narrowing, so the number on the
 * tile is the number of rows it opens.
 */
export type QueueFocus = 'waiting' | 'overdue'

export const FOCUS_LABEL: Record<QueueFocus, string> = {
  waiting: 'Waiting on you',
  overdue: 'Overdue',
}

export const FOCUS_ASSIGNMENT_STATUSES = 'pending,in_progress'
export const FOCUS_APPLICATION_STATUSES: readonly ApplicationStatus[] = ['for_approval', 'approved']

/** How many rows the queue holds under a home-page narrowing. */
export async function countQueueFocus(focus: QueueFocus): Promise<number> {
  const res = await assignments.page({
    application_status: FOCUS_APPLICATION_STATUSES.join(','),
    application_decided: 0,
    status: FOCUS_ASSIGNMENT_STATUSES,
    ...(focus === 'overdue' ? { overdue: 1 as const } : {}),
    per_page: 1,
  })

  return res.meta.total
}

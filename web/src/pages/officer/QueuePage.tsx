import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { InboxIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, SortFilter, StatusChip } from '../../components/ui/Proto'
import { assignments } from '../../lib/resources'
import { formatDateTime } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import type { ApplicationStatus, Assignment } from '../../lib/types'

/*
 * Application Verification (PDF p61/p80) — the officer queue restyled to the
 * prototype: pill filters, white shadow rows with the solid payment chip block.
 * Data stays the department-scoped /assignments feed.
 */

type Tab = 'approval' | 'inspection'

const TABS: { value: Tab; label: string }[] = [
  { value: 'approval', label: 'For Approval' },
  { value: 'inspection', label: 'For Inspection' },
]

/**
 * Application statuses that still sit on the review/approval side of the flow.
 *
 * `pending_payment` appeared twice here and in PENDING_PAYMENT_STATUSES, which is
 * the signature of a rename that collapsed two statuses onto one value. Checked
 * against ApplicationStatus: draft, submitted, pending_payment, under_review,
 * for_inspection, approved, rejected, returned, cancelled. Every pre-decision
 * status except draft is covered, and draft belongs out — an unfiled draft is not
 * an officer's work.
 */
const APPROVAL_STATUSES = ['submitted', 'pending_payment', 'under_review', 'returned'] as const
/** Statuses on the inspection/approved side. */
const INSPECTION_STATUSES = ['for_inspection', 'approved', 'issued'] as const

const TAB_STATUSES: Record<Tab, readonly ApplicationStatus[]> = {
  approval: APPROVAL_STATUSES,
  inspection: INSPECTION_STATUSES,
}

/** Pre-payment statuses show the orange block; everything else is paid. */
const PENDING_PAYMENT_STATUSES = ['submitted', 'pending_payment']

/**
 * How many rows to put on the page at once.
 *
 * The feed behind this screen is every assignment the office has ever held —
 * 1,649 for BPLO and 4,620 for an admin who sees all of them. It used to be
 * fetched whole and rendered whole, which is the same 2.2 MB that took the
 * browser down on Inspections; the "For Inspection" tab is almost entirely
 * completed work, so it is the larger of the two.
 */
const PAGE_SIZE = 25

/**
 * The business behind a filing, when there still is one.
 *
 * A business can be removed from the register after its filings are decided —
 * 375 of the 4,620 assignments on this system point at one that is gone, and the
 * API sends `business: null` for every one of them. The row still has to render:
 * the filing happened, and an officer looking for it should find it rather than
 * meet a blank page. (`Assignment['application']['business']` is typed
 * non-nullable, which is why nothing caught this — see the report.)
 */
function businessNameOf(app: Assignment['application']): { name: string; removed: boolean } {
  const name = app.business?.name
  return name ? { name, removed: false } : { name: app.tracking_id, removed: true }
}

function QueueRow({ item }: { item: Assignment }) {
  const app = item.application
  const pendingPayment = PENDING_PAYMENT_STATUSES.includes(app.status)
  const business = businessNameOf(app)
  return (
    <li>
      <Link
        to={`/queue/${item.id}`}
        className="flex items-stretch overflow-hidden rounded-lg bg-white shadow-card transition-shadow hover:shadow-raised"
      >
        <div className="min-w-0 flex-1 px-6 py-4">
          <p className="truncate text-[17px] font-bold text-ink">{business.name}</p>
          {business.removed && (
            <p className="mt-0.5 text-xs font-semibold uppercase tracking-wide text-ink-muted">
              Business removed from the register
            </p>
          )}
          <p className="mt-0.5 text-sm italic text-ink-muted">{formatDateTime(item.assigned_at)}</p>
        </div>
        <StatusChip
          tone={pendingPayment ? 'orange' : 'green'}
          className="w-28 shrink-0 rounded-none! px-4 py-3 text-sm"
        >
          {pendingPayment ? (
            <span>
              Pending
              <br />
              Payment
            </span>
          ) : (
            'Paid'
          )}
        </StatusChip>
      </Link>
    </li>
  )
}

/**
 * The officer queue.
 *
 * Both tabs are server-side filters over a paged feed. Splitting the tabs in the
 * browser is what made this fragile: it pulled the office's whole assignment
 * history to show a screenful, and against a paged feed it would have counted
 * one page's rows and presented that as the queue — a number that is always
 * plausible and always wrong. The tab totals come from
 * `meta.application_status_counts`, which is counted over the whole
 * department-scoped set.
 */
export function QueuePage() {
  const [tab, setTab] = useState<Tab>('approval')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<Assignment[]>([])

  const statuses = TAB_STATUSES[tab].join(',')
  const { data, loading, error, reload } = useAsync(
    () => assignments.page({ application_status: statuses, page, per_page: PAGE_SIZE }),
    [statuses, page],
  )

  // Paging in extends the list being read; a new tab starts its own list. Merged
  // by id so that Try again after a failed page cannot show its rows twice.
  useEffect(() => {
    if (!data) return
    setRows((prev) => {
      if (data.meta.current_page === 1) return data.data
      const seen = new Set(prev.map((r) => r.id))
      return [...prev, ...data.data.filter((r) => !seen.has(r.id))]
    })
  }, [data])

  function selectTab(next: Tab) {
    if (next === tab) return
    setTab(next)
    setPage(1)
    setRows([])
  }

  // Counted over the whole set, not the page in hand.
  const counts = data?.meta.application_status_counts
  const total = counts
    ? TAB_STATUSES[tab].reduce((sum, s) => sum + (counts[s] ?? 0), 0)
    : (data?.meta.total ?? 0)
  const hasMore = data ? data.meta.current_page < data.meta.last_page : false
  const firstLoad = loading && rows.length === 0

  return (
    <div>
      <PageTitle right={<SortFilter />}>Application Verification</PageTitle>

      <div className="mb-5">
        <FilterPills options={TABS} value={tab} onChange={selectTab} />
      </div>

      {firstLoad ? (
        <SkeletonList rows={5} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={InboxIcon}
          title="Your queue is clear"
          description={
            tab === 'approval'
              ? 'Nothing is waiting for approval in your department right now.'
              : 'No applications are on the inspection side right now.'
          }
        />
      ) : (
        <>
          {/* A first page must never read as the whole list. */}
          <p className="mb-3 text-sm text-ink-muted">
            Showing {rows.length.toLocaleString()} of {total.toLocaleString()}, newest first.
          </p>
          <ul className="space-y-4">
            {rows.map((item) => (
              <QueueRow key={item.id} item={item} />
            ))}
          </ul>
          {hasMore && (
            <button
              type="button"
              onClick={() => setPage((p) => p + 1)}
              disabled={loading}
              className="mt-5 w-full rounded-xl border border-line bg-white py-3 text-sm font-semibold text-royal transition-colors hover:bg-canvas disabled:cursor-wait disabled:text-ink-muted"
            >
              {loading ? 'Loading…' : 'Load more'}
            </button>
          )}
        </>
      )}
    </div>
  )
}

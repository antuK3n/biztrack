import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { InboxIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { FilterPills, PageTitle, SortFilter, StatusChip } from '../../components/ui/Proto'
import { assignments } from '../../lib/resources'
import { formatDateTime } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import type { Assignment } from '../../lib/types'

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

/** Application statuses that still sit on the review/approval side of the flow. */
const APPROVAL_STATUSES = ['submitted', 'pending_payment', 'pending_payment', 'under_review', 'returned']
/** Statuses on the inspection/approved side. */
const INSPECTION_STATUSES = ['for_inspection', 'approved', 'issued']

/** Pre-payment statuses show the orange block; everything else is paid. */
const PENDING_PAYMENT_STATUSES = ['submitted', 'pending_payment', 'pending_payment']

function QueueRow({ item }: { item: Assignment }) {
  const app = item.application
  const pendingPayment = PENDING_PAYMENT_STATUSES.includes(app.status)
  return (
    <li>
      <Link
        to={`/queue/${item.id}`}
        className="flex items-stretch overflow-hidden rounded-lg bg-white shadow-card transition-shadow hover:shadow-raised"
      >
        <div className="min-w-0 flex-1 px-6 py-4">
          <p className="truncate text-[17px] font-bold text-ink">{app.business.name}</p>
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

export function QueuePage() {
  const [tab, setTab] = useState<Tab>('approval')
  const { data, loading, error, reload } = useAsync(() => assignments.list(), [])

  const items = useMemo(() => {
    const all = data ?? []
    const wanted = tab === 'approval' ? APPROVAL_STATUSES : INSPECTION_STATUSES
    return all.filter((a) => wanted.includes(a.application.status))
  }, [data, tab])

  return (
    <div>
      <PageTitle right={<SortFilter />}>Application Verification</PageTitle>

      <div className="mb-5">
        <FilterPills options={TABS} value={tab} onChange={setTab} />
      </div>

      {loading ? (
        <SkeletonList rows={5} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : items.length === 0 ? (
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
        <ul className="space-y-4">
          {items.map((item) => (
            <QueueRow key={item.id} item={item} />
          ))}
        </ul>
      )}
    </div>
  )
}

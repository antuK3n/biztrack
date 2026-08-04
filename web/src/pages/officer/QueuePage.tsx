import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { InboxIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FilterPills,
  PageTitle,
  SortFilter,
  StatusChip,
  type SortFilterOption,
} from '../../components/ui/Proto'
import { assignments } from '../../lib/resources'
import { formatDateTime } from '../../lib/format'
import { applicationStatusMeta } from '../../lib/status'
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

/*
 * ── Where sort, filter and search run on this screen ───────────────────────
 *
 * Not the same answer for all three, because the feed is not the same shape
 * for all three. The applicant's Track page holds its whole list in memory and
 * does all three in the browser; this one is paged server-side over as many as
 * 4,620 rows, and filtering a page in the browser is precisely the bug the tab
 * split already had (see the class comment on QueuePage).
 *
 *  - Filter → server. `/assignments` takes `application_status`, so narrowing
 *    the tab to one status is a query change and the totals stay exact.
 *  - Search and sort → browser, because `/assignments` accepts neither a `q`
 *    nor an ordering parameter (AssignmentController::index validates only
 *    status / application_status / page / per_page, and orders assigned_at
 *    DESC unconditionally). Adding them server-side is the real fix and is
 *    left for the API; what this screen can do without lying is search and
 *    sort the rows it has and say exactly how many that is — see the status
 *    line below, which reports coverage rather than implying the whole queue.
 *
 * While either is active the page asks for the API's ceiling instead of a
 * screenful, so a search usually does cover the whole queue in one request: an
 * office's "For Approval" tab is tens of rows, not thousands. `maxPerPage` is
 * 200 (PaginatesLists) and asking for more is clamped, not obeyed.
 */
const DEEP_PAGE_SIZE = 200

type SortKey = 'newest' | 'waiting' | 'business'

const SORTS: SortFilterOption[] = [
  { value: 'newest', label: 'Newest first' },
  { value: 'waiting', label: 'Waiting longest' },
  { value: 'business', label: 'Business name (A–Z)' },
]

/** Is the browser being asked to do work the current page of rows cannot answer? */
function isDeep(query: string, sort: SortKey): boolean {
  return query.trim() !== '' || sort !== 'newest'
}

/**
 * What an officer types into the queue search.
 *
 * Tracking ID and business name — the two things on the row, and the two the
 * assignment feed carries. There is no applicant-given title in
 * `AssignmentResource.application`, so unlike the applicant's Track page this
 * one cannot search on it.
 */
function matchesSearch(item: Assignment, needle: string): boolean {
  if (!needle) return true
  const app = item.application
  return `${app.tracking_id} ${app.business?.name ?? ''}`.toLowerCase().includes(needle)
}

/** Assignment age for ordering; a missing `assigned_at` sorts as brand new. */
function assignedAt(item: Assignment): number {
  return item.assigned_at ? new Date(item.assigned_at).getTime() : 0
}

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
        to={`/staff/queue/${item.id}`}
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
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<SortKey>('newest')
  /** '' means the whole tab; otherwise one status inside it. */
  const [statusFilter, setStatusFilter] = useState('')

  // The filter narrows the tab's status list rather than replacing it, so
  // "For Approval → Under review" cannot silently show inspection work.
  const tabStatuses = TAB_STATUSES[tab]
  const activeStatuses: readonly ApplicationStatus[] = statusFilter
    ? [statusFilter as ApplicationStatus]
    : tabStatuses
  const statuses = activeStatuses.join(',')
  const perPage = isDeep(search, sort) ? DEEP_PAGE_SIZE : PAGE_SIZE

  const { data, loading, error, reload } = useAsync(
    () => assignments.page({ application_status: statuses, page, per_page: perPage }),
    [statuses, page, perPage],
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

  /*
   * Any change to what is being asked for restarts the list.
   * Done in the handlers rather than an effect on purpose: an effect would see
   * the new query with the old page number first and fire a request for page 3
   * of a list that no longer exists before correcting itself.
   */
  function restart() {
    setPage(1)
    setRows([])
  }

  function selectTab(next: Tab) {
    if (next === tab) return
    setTab(next)
    // The status filter belongs to the tab it was chosen in — "Under review" is
    // not one of the inspection tab's statuses, and carrying it across would
    // hand the server a status list that matches nothing.
    setStatusFilter('')
    restart()
  }

  function selectStatus(next: string) {
    if (next === statusFilter) return
    setStatusFilter(next)
    restart()
  }

  /** Search and sort only restart the list when they change how deep it is fetched. */
  function changeSearch(next: string) {
    setSearch(next)
    if (isDeep(next, sort) !== isDeep(search, sort)) restart()
  }

  function changeSort(next: SortKey) {
    setSort(next)
    if (isDeep(search, next) !== isDeep(search, sort)) restart()
  }

  // Counted over the whole set, not the page in hand.
  const counts = data?.meta.application_status_counts
  const total = counts
    ? activeStatuses.reduce((sum, s) => sum + (counts[s] ?? 0), 0)
    : (data?.meta.total ?? 0)
  const hasMore = data ? data.meta.current_page < data.meta.last_page : false
  const firstLoad = loading && rows.length === 0

  /** Status options for the tab in hand — a tab never offers a status it excludes. */
  const statusOptions: SortFilterOption[] = [
    { value: '', label: tab === 'approval' ? 'All in For Approval' : 'All in For Inspection' },
    ...tabStatuses.map((s) => ({ value: s, label: applicationStatusMeta(s).label })),
  ]

  const needle = search.trim().toLowerCase()
  const visible = rows
    .filter((item) => matchesSearch(item, needle))
    // Copied before sorting: `rows` is state, and Array.prototype.sort is in
    // place — sorting it directly would rewrite the accumulated pages.
    .slice()
    .sort((a, b) => {
      if (sort === 'waiting') return assignedAt(a) - assignedAt(b)
      if (sort === 'business') {
        // Rows whose business is gone are keyed by tracking ID on screen, so
        // that is what they sort by too — otherwise they all collide on ''.
        const na = a.application.business?.name ?? a.application.tracking_id
        const nb = b.application.business?.name ?? b.application.tracking_id
        return na.localeCompare(nb)
      }
      return assignedAt(b) - assignedAt(a)
    })

  const sortLabel = SORTS.find((s) => s.value === sort)?.label.toLowerCase() ?? 'newest first'
  const partial = rows.length < total
  const narrowed = Boolean(needle || statusFilter)

  /*
   * What this screen is actually showing, in one sentence, announced.
   *
   * A first page must never read as the whole list — and neither must a search
   * over one. When search or sort is on, the count is stated against the rows
   * loaded and the queue total is given beside it, so an officer can see that
   * "2 matches" means two out of 200 read, not two in the register.
   */
  const summary = firstLoad
    ? 'Loading the queue…'
    : error
      ? ''
      : rows.length === 0
        ? 'Nothing in this queue right now.'
        : needle || sort !== 'newest'
          ? `Showing ${visible.length.toLocaleString()} of the ${rows.length.toLocaleString()} loaded` +
            `${partial ? ` (${total.toLocaleString()} in this queue)` : ''}, ${sortLabel}.` +
            `${partial ? ' Load more to reach the rest.' : ''}`
          : `Showing ${rows.length.toLocaleString()} of ${total.toLocaleString()}, newest first.`

  function clearSearchAndFilter() {
    setSearch('')
    if (statusFilter) selectStatus('')
    if (isDeep('', sort) !== isDeep(search, sort)) restart()
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-2 pb-1">
            {/*
              * A placeholder is not an accessible name — it vanishes on the
              * first keystroke — so the field carries a real label, hidden
              * only because the magnifying-glass context is obvious visually.
              */}
            <label htmlFor="queue-search" className="sr-only">
              Search this queue by tracking ID or business name
            </label>
            <input
              id="queue-search"
              type="search"
              value={search}
              onChange={(e) => changeSearch(e.target.value)}
              placeholder="Search tracking ID or business…"
              className="w-64 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <SortFilter
              sort={{ value: sort, options: SORTS, onChange: (v) => changeSort(v as SortKey) }}
              filter={{ value: statusFilter, options: statusOptions, onChange: selectStatus }}
            />
          </span>
        }
      >
        Application Verification
      </PageTitle>

      <div className="mb-5">
        <FilterPills options={TABS} value={tab} onChange={selectTab} />
      </div>

      {/*
        * Mounted unconditionally, not tucked inside the list branch: an
        * aria-live region only announces changes to text it already owns, so
        * one that is unmounted whenever the list is empty stays silent on the
        * single result that matters most — the search that found nothing.
        */}
      <p role="status" aria-live="polite" className={summary ? 'mb-3 text-sm text-ink-muted' : ''}>
        {summary}
      </p>

      {firstLoad ? (
        <SkeletonList rows={5} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={InboxIcon}
          title={narrowed ? 'Nothing matches these filters' : 'Your queue is clear'}
          description={
            narrowed
              ? 'No application in this queue has that status. Try a different filter.'
              : tab === 'approval'
                ? 'Nothing is waiting for approval in your department right now.'
                : 'No applications are on the inspection side right now.'
          }
        />
      ) : visible.length === 0 ? (
        <>
          <EmptyState
            icon={InboxIcon}
            title={`Nothing matches “${search.trim()}”`}
            description={
              partial
                ? `Searched the ${rows.length.toLocaleString()} rows loaded so far. Load more to search deeper, or check the tracking ID.`
                : 'Check the tracking ID, or search by the business name instead.'
            }
          />
          {/* A dead end needs a way out, not just an explanation of itself. */}
          <div className="mt-4 flex justify-center gap-3">
            <button
              type="button"
              onClick={clearSearchAndFilter}
              className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-royal transition-colors hover:bg-canvas"
            >
              Clear search
            </button>
            {hasMore && (
              <button
                type="button"
                onClick={() => setPage((p) => p + 1)}
                disabled={loading}
                className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-royal transition-colors hover:bg-canvas disabled:cursor-wait disabled:text-ink-muted"
              >
                {loading ? 'Loading…' : 'Load more and keep searching'}
              </button>
            )}
          </div>
        </>
      ) : (
        <>
          <ul className="space-y-4">
            {visible.map((item) => (
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

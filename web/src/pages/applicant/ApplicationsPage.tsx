import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { TrackIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FilterPills,
  PageTitle,
  SortFilter,
  StatusChip,
  type ChipTone,
  type SortFilterOption,
} from '../../components/ui/Proto'
import { businessName, formatDate } from '../../lib/format'
import { applications, reference } from '../../lib/resources'
import { applicationStatusMeta } from '../../lib/status'
import { useAsync } from '../../lib/useAsync'
import type {
  Application,
  ApplicationListItem,
  ApplicationStatus,
  PermitType,
} from '../../lib/types'

/*
 * Permit Tracking (PDF p48–49): type filter pills, white accordion rows per
 * application with an orange "Pay Online" / green "Paid" block, and expanded
 * per-permit rows with a status chip + submitted date + message icon.
 *
 * This list is the applicant's open work. An approved filing has no next step
 * left, so it moves to Profile, where the permits it produced are listed under
 * "Approved Businesses" (tester item 44). The count of what moved is shown
 * below the list so nothing silently disappears.
 */

type TypeFilter = '' | 'new' | 'renewal' | 'amendment'

/**
 * Finished filings: approval and issuance happen in one transaction
 * (WorkflowService::approveAndIssue), so an approved application always has its
 * permits waiting in Profile. Rejected stays here — it still needs a re-apply.
 */
const FINISHED: ApplicationStatus[] = ['approved', 'issued']

const FILTERS: { label: string; value: TypeFilter }[] = [
  { label: 'All', value: '' },
  { label: 'New Permit', value: 'new' },
  { label: 'Renewal', value: 'renewal' },
  { label: 'Amendment', value: 'amendment' },
]

/*
 * Sort, filter and search all run in the browser here, and that is the right
 * choice for this screen rather than a shortcut: `applications.list()` already
 * fetches this applicant's filings in one request up to the API's 200-row
 * ceiling — an owner has a handful, not the register's 1,668 — and the page
 * already filters that list in the browser twice over, drafts out and finished
 * out, before the type pills touch it. Sending these three to the server would
 * be a round trip per keystroke to reorder a list already sitting in memory.
 * The officer queue is the opposite case and is wired the opposite way; see
 * QueuePage.
 */

type SortKey = 'newest' | 'oldest' | 'deadline'

const SORTS: SortFilterOption[] = [
  { value: 'newest', label: 'Newest first' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'deadline', label: 'Deadline (soonest)' },
]

/**
 * Statuses a filing on this list can actually be in.
 *
 * Offering a status that can never match is a dead end that reads like a bug,
 * so approved/issued are left out (those filings have moved to Profile — see
 * FINISHED) and so is draft (drafts have their own page). Labels come from the
 * shared status table rather than being written again here, so the filter and
 * the chips cannot drift into two vocabularies for one status.
 */
const FILTERABLE_STATUSES: ApplicationStatus[] = [
  'submitted',
  'under_review',
  'pending_payment',
  'for_inspection',
  'returned',
  'rejected',
  'cancelled',
]

/** "All" stays first: SortFilter marks Filter active by comparing to `options[0]`. */
const STATUS_FILTERS: SortFilterOption[] = [
  { value: '', label: 'All statuses' },
  ...FILTERABLE_STATUSES.map((s) => ({ value: s, label: applicationStatusMeta(s).label })),
]

/**
 * When a filing "happened", for ordering.
 *
 * `submitted_at` is null on anything not yet filed, and ordering on a null
 * silently piles those rows at one end; `created_at` is always set, so it is
 * the honest fallback rather than a defensive one.
 */
function filedAt(a: ApplicationListItem): number {
  return new Date(a.submitted_at ?? a.created_at).getTime()
}

/**
 * Does this filing match what the applicant typed?
 *
 * The three things a person actually looks up: the tracking ID they were given
 * (BIZ-2026-00123), the business name, and their own title for the filing when
 * they gave it one. Matched case-insensitively on substrings so that typing
 * "00123" or "bakery" both work — nobody retypes a whole tracking ID.
 */
function matchesSearch(a: ApplicationListItem, needle: string): boolean {
  if (!needle) return true
  const haystack = [a.tracking_id, a.business?.name ?? '', a.title ?? ''].join(' ').toLowerCase()
  return haystack.includes(needle)
}

interface Chip {
  tone: ChipTone
  label: string
}

/**
 * Per-permit chip (prototype p49). Derived from the assignment of the permit
 * type's issuing department:
 *  - app-level rejected → all red "Rejected"
 *  - assignment completed (or app approved/issued) → green "Approved"
 *  - assignment returned → red "Returned"
 *  - app for_inspection AND the type requires inspection → yellow "For Inspection"
 *  - otherwise → orange "For Approval"
 * Falls back to the coarse app-status chip when the full application (with
 * assignments + the permit type's department) isn't available yet.
 */
function permitChip(
  appStatus: ApplicationStatus,
  permitType: PermitType | undefined,
  assignmentStatus: string | undefined,
): Chip {
  if (appStatus === 'rejected') return { tone: 'red', label: 'Rejected' }
  if (assignmentStatus === 'completed' || appStatus === 'approved' || appStatus === 'issued')
    return { tone: 'green', label: 'Approved' }
  if (assignmentStatus === 'returned') return { tone: 'red', label: 'Returned' }
  if (appStatus === 'for_inspection' && permitType?.requires_inspection)
    return { tone: 'yellow', label: 'For Inspection' }
  return { tone: 'orange', label: 'For Approval' }
}

/** Coarse fallback chip from application status alone (before detail loads). */
function fallbackChip(status: ApplicationStatus): Chip {
  if (status === 'rejected') return { tone: 'red', label: 'Rejected' }
  if (status === 'for_inspection') return { tone: 'yellow', label: 'For Inspection' }
  if (status === 'approved' || status === 'issued') return { tone: 'green', label: 'Approved' }
  return { tone: 'orange', label: 'For Approval' }
}

/** Solid accordion triangle (p48). */
function Triangle({ open }: { open: boolean }) {
  return (
    <svg
      width="20"
      height="20"
      viewBox="0 0 24 24"
      aria-hidden="true"
      className={`shrink-0 text-ink-secondary transition-transform ${open ? 'rotate-180' : ''}`}
    >
      <path d="M5 8h14l-7 9L5 8Z" fill="currentColor" />
    </svg>
  )
}

/** Message bubble icon (p49). */
function MessageIcon({ size = 22 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <rect x="3" y="4.5" width="18" height="13" rx="3" stroke="currentColor" strokeWidth="1.75" />
      <path d="M8 17.5v3l3.5-3" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx="8.5" cy="11" r="1" fill="currentColor" />
      <circle cx="12" cy="11" r="1" fill="currentColor" />
      <circle cx="15.5" cy="11" r="1" fill="currentColor" />
    </svg>
  )
}

/** Cache of loaded application detail per row id (survives collapse/re-expand). */
type DetailCache = Record<number, Application>

/**
 * Why a filing was rejected, on the row itself (tester item 80).
 *
 * The officer writes a reason into `rejection_reason` when they reject, and
 * this page used to show a red "Rejected" chip and nothing else — a verdict
 * with no grounds, which leaves the applicant with no move except to phone the
 * LGU and ask. It is shown on the collapsed row rather than inside the
 * accordion because it is the one thing on a rejected row worth reading, and a
 * reason nobody expands to find is a reason nobody reads.
 *
 * The reason is not on the list payload (`ApplicationListResource` omits it);
 * it comes from the detail endpoint the page already fetches, which is why this
 * renders three states rather than one — loading, present, and recorded-empty.
 * The empty case still has to say something actionable: "rejected, no reason
 * given" is a worse silence than the chip alone if it looks like a blank box.
 */
function RejectionNote({ app, detail }: { app: ApplicationListItem; detail: Application | undefined }) {
  return (
    /*
     * Pulled up tight under its own row: the list puts 16px between filings
     * and the accordion 12px inside one, so at the default gap this read as a
     * seventh card rather than as a note about the sixth.
     */
    <div className="mt-1! rounded-xl border border-s-red/30 bg-s-red-tint px-5 py-3.5">
      <p className="text-sm font-bold text-s-red">Rejected</p>
      {detail === undefined ? (
        <p className="mt-1 text-sm text-ink-secondary">Loading the reason…</p>
      ) : detail.rejection_reason ? (
        <p className="mt-1 whitespace-pre-line text-sm text-ink">{detail.rejection_reason}</p>
      ) : (
        <p className="mt-1 text-sm text-ink-secondary">
          No reason was recorded with this decision. Message the office handling it for the details.
        </p>
      )}
      <Link
        to={`/applications/${app.id}`}
        // Named for the filing it opens: a list of links all reading "Open
        // this application" is a list a screen reader user cannot choose from.
        aria-label={`Open the rejected application for ${businessName(app.business)}`}
        className="mt-1.5 inline-block text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline"
      >
        Open this application
      </Link>
    </div>
  )
}

function ApplicationRow({
  app,
  permitTypesByCode,
  detail,
  onExpand,
}: {
  app: ApplicationListItem
  permitTypesByCode: Map<string, PermitType>
  detail: Application | undefined
  onExpand: (id: number) => void
}) {
  const [open, setOpen] = useState(false)
  const pending = app.status === 'pending_payment'
  const rejected = app.status === 'rejected'
  const payBlockCls =
    'flex w-28 shrink-0 items-center justify-center self-stretch px-3 text-center text-base font-semibold leading-tight text-white'

  function toggle() {
    setOpen((o) => {
      const nextOpen = !o
      if (nextOpen) onExpand(app.id)
      return nextOpen
    })
  }

  /** Assignment status for a permit type's issuing department, if loaded. */
  function assignmentStatusFor(code: string): string | undefined {
    const pt = permitTypesByCode.get(code)
    if (!pt || !detail) return undefined
    const deptCode = pt.department.code
    return detail.assignments.find((a) => a.department.code === deptCode)?.status
  }

  return (
    <li className="space-y-3">
      <div className="flex items-stretch overflow-hidden rounded-xl bg-white shadow-card">
        <button
          type="button"
          onClick={toggle}
          aria-expanded={open}
          className="flex min-w-0 flex-1 items-center gap-5 px-6 py-5 text-left"
        >
          <Triangle open={open} />
          <span className="truncate text-lg font-bold text-ink">{businessName(app.business)}</span>
        </button>
        {pending ? (
          <Link to={`/applications/${app.id}/pay`} className={`${payBlockCls} bg-s-orange hover:brightness-95`}>
            Pay Online
          </Link>
        ) : (
          <span className={`${payBlockCls} bg-s-green`}>Paid</span>
        )}
      </div>

      {rejected && <RejectionNote app={app} detail={detail} />}

      {open && (
        <ul className="space-y-2.5">
          {(app.permit_types.length > 0 ? app.permit_types : [{ code: '—', name: 'Business Permit' }]).map(
            (pt) => {
              // Once detail loads, derive per-permit chip from the issuing
              // department's assignment; otherwise fall back to app status.
              const chip = detail
                ? permitChip(app.status, permitTypesByCode.get(pt.code), assignmentStatusFor(pt.code))
                : fallbackChip(app.status)
              return (
                <li
                  key={pt.code}
                  className="flex items-center gap-4 rounded-lg bg-white px-4 py-2.5 shadow-card"
                >
                  <StatusChip tone={chip.tone} className="w-24 shrink-0 py-1.5">
                    {chip.label}
                  </StatusChip>
                  <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink">{pt.name}</span>
                  <span className="shrink-0 text-xs italic text-ink-muted">
                    Submitted: {formatDate(app.submitted_at)}
                  </span>
                  <Link
                    to={`/applications/${app.id}`}
                    className="shrink-0 text-ink-secondary transition-colors hover:text-royal"
                    aria-label={`View ${pt.name} status`}
                  >
                    <MessageIcon />
                  </Link>
                </li>
              )
            },
          )}
        </ul>
      )}
    </li>
  )
}

export function ApplicationsPage() {
  const [type, setType] = useState<TypeFilter>('')
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState<SortKey>('newest')
  const [status, setStatus] = useState('')
  const { data, loading, error, reload } = useAsync(() => applications.list(), [])
  // Reference permit types carry `department` + `requires_inspection`, which we
  // need to map each permit type to its issuing department's assignment.
  const permitTypesRef = useAsync(() => reference.permitTypes(), [])
  const permitTypesByCode = new Map<string, PermitType>(
    (permitTypesRef.data ?? []).map((pt) => [pt.code, pt]),
  )
  // Lazily-loaded full application detail per expanded row (cached).
  const [detailCache, setDetailCache] = useState<DetailCache>({})
  /*
   * Ids already asked for, whether or not the answer is back yet.
   * `detailCache` alone cannot guard this: it is captured per render, so two
   * calls in the same tick — which is exactly what the rejected-row effect
   * below does — both see an empty cache and both fetch.
   */
  const [requested] = useState(() => new Set<number>())

  function loadDetail(id: number) {
    if (requested.has(id)) return
    requested.add(id)
    applications
      .get(id)
      .then((full) => setDetailCache((c) => ({ ...c, [id]: full })))
      .catch(() => {
        // Non-fatal: fall back to the coarse app-status chip. Cleared from
        // `requested` so expanding the row again retries.
        requested.delete(id)
      })
  }

  // Drafts have their own page; keep this list to submitted work still in play.
  const submitted = (data ?? []).filter((a) => a.status !== 'draft')
  const byType = (a: ApplicationListItem) => !type || a.application_type === type
  const inPlay = submitted.filter((a) => !FINISHED.includes(a.status)).filter(byType)
  const finishedCount = submitted.filter((a) => FINISHED.includes(a.status)).filter(byType).length

  /*
   * The rejection reason lives on the detail payload, not the list one, so the
   * rejected rows have to be fetched before they can explain themselves. Only
   * the rejected ones — this is the exception on a filing list, not the rule,
   * and eager-loading every row would put a request per row on every visit.
   */
  const rejectedIds = inPlay.filter((a) => a.status === 'rejected').map((a) => a.id)
  const rejectedKey = rejectedIds.join(',')
  // Keyed on the ids themselves rather than on the array, which is rebuilt
  // every render and would make this run every render.
  useEffect(() => {
    for (const id of rejectedIds) loadDetail(id)
  }, [rejectedKey])

  const needle = search.trim().toLowerCase()
  const items = inPlay
    .filter((a) => !status || a.status === status)
    .filter((a) => matchesSearch(a, needle))
    // Copied before sorting: `inPlay` is derived per render, but sorting the
    // array the filters returned is still a mutation of a value other code on
    // this render reads (`finishedCount` counts a different array, but the
    // habit is what keeps that true).
    .slice()
    .sort((a, b) => {
      if (sort === 'oldest') return filedAt(a) - filedAt(b)
      if (sort === 'deadline') {
        // A filing with no deadline is not "due first" — nulls go last, in
        // their own newest-first order, rather than heading the list at epoch 0.
        const da = a.deadline_at ? new Date(a.deadline_at).getTime() : Infinity
        const db = b.deadline_at ? new Date(b.deadline_at).getTime() : Infinity
        if (da !== db) return da - db
        return filedAt(b) - filedAt(a)
      }
      return filedAt(b) - filedAt(a)
    })

  /** True when the empty list is the doing of a control, not of an empty account. */
  const narrowed = Boolean(needle || status || type)

  function clearSearchAndFilters() {
    setSearch('')
    setStatus('')
    setType('')
  }

  /** Pointer to where an approved filing went, so it is never simply gone. */
  const movedNote = finishedCount > 0 && (
    <p className="mt-6 text-sm text-ink-secondary">
      {finishedCount === 1
        ? '1 approved application is now in your '
        : `${finishedCount} approved applications are now in your `}
      <Link to="/permits" className="font-semibold text-royal underline underline-offset-2 hover:no-underline">
        Profile
      </Link>
      , with the permits they produced.
    </p>
  )

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-4 pb-1">
            {/*
              * Labelled, not just placeheld: a placeholder disappears the moment
              * the field is used and is not an accessible name, so the field
              * would be announced as an unnamed edit box.
              */}
            <label htmlFor="track-search" className="sr-only">
              Search your applications by tracking ID, business name, or title
            </label>
            <input
              id="track-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search tracking ID or business…"
              className="w-64 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <SortFilter
              sort={{ value: sort, options: SORTS, onChange: (v) => setSort(v as SortKey) }}
              filter={{ value: status, options: STATUS_FILTERS, onChange: setStatus }}
            />
          </span>
        }
      >
        Permit Tracking
      </PageTitle>

      <div className="mb-6">
        <FilterPills options={FILTERS} value={type} onChange={setType} />
      </div>

      {/*
        * The result count, announced.
        *
        * Without this a sighted reader watches the list shrink as they type and
        * a screen reader user hears nothing at all — the search would be a
        * control whose entire feedback is visual. `role="status"` is polite, so
        * it waits for a pause in typing rather than interrupting each keystroke.
        */}
      <p role="status" aria-live="polite" className="sr-only">
        {loading
          ? 'Loading applications'
          : `${items.length} ${items.length === 1 ? 'application' : 'applications'} shown${
              narrowed ? ' for the current search and filters' : ''
            }.`}
      </p>

      {loading ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <>
          <EmptyState
            icon={TrackIcon}
            title={
              needle
                ? `Nothing matches “${search.trim()}”`
                : narrowed
                  ? 'Nothing matches these filters'
                  : finishedCount > 0
                    ? 'Nothing needs your attention'
                    : 'No applications yet'
            }
            description={
              needle
                ? 'Check the tracking ID, or search by the business name instead.'
                : narrowed
                  ? 'Try a different filter, or start a new application.'
                  : finishedCount > 0
                    ? 'Every application you have filed has been approved. The permits are in your Profile.'
                    : 'When you submit an application, it appears here with its live status and next step.'
            }
          />
          {/* A dead end needs a way out, not just an explanation of itself. */}
          {narrowed && (
            <div className="mt-4 text-center">
              <button
                type="button"
                onClick={clearSearchAndFilters}
                className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold text-royal transition-colors hover:bg-canvas"
              >
                Clear search and filters
              </button>
            </div>
          )}
          {movedNote}
        </>
      ) : (
        <>
          <ul className="space-y-4">
            {items.map((app) => (
              <ApplicationRow
                key={app.id}
                app={app}
                permitTypesByCode={permitTypesByCode}
                detail={detailCache[app.id]}
                onExpand={loadDetail}
              />
            ))}
          </ul>
          {movedNote}
        </>
      )}
    </div>
  )
}

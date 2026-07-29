import { useState } from 'react'
import { Link } from 'react-router-dom'
import { TrackIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import {
  FilterPills,
  PageTitle,
  SortFilter,
  StatusChip,
  type ChipTone,
} from '../../components/ui/Proto'
import { formatDate } from '../../lib/format'
import { applications, reference } from '../../lib/resources'
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
          <span className="truncate text-lg font-bold text-ink">{app.business.name}</span>
        </button>
        {pending ? (
          <Link to={`/applications/${app.id}/pay`} className={`${payBlockCls} bg-s-orange hover:brightness-95`}>
            Pay Online
          </Link>
        ) : (
          <span className={`${payBlockCls} bg-s-green`}>Paid</span>
        )}
      </div>

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
  const { data, loading, error, reload } = useAsync(() => applications.list(), [])
  // Reference permit types carry `department` + `requires_inspection`, which we
  // need to map each permit type to its issuing department's assignment.
  const permitTypesRef = useAsync(() => reference.permitTypes(), [])
  const permitTypesByCode = new Map<string, PermitType>(
    (permitTypesRef.data ?? []).map((pt) => [pt.code, pt]),
  )
  // Lazily-loaded full application detail per expanded row (cached).
  const [detailCache, setDetailCache] = useState<DetailCache>({})

  function loadDetail(id: number) {
    if (detailCache[id]) return
    applications
      .get(id)
      .then((full) => setDetailCache((c) => ({ ...c, [id]: full })))
      .catch(() => {
        /* Non-fatal: fall back to the coarse app-status chip. */
      })
  }

  // Drafts have their own page; keep this list to submitted work still in play.
  const submitted = (data ?? []).filter((a) => a.status !== 'draft')
  const byType = (a: ApplicationListItem) => !type || a.application_type === type
  const items = submitted.filter((a) => !FINISHED.includes(a.status)).filter(byType)
  const finishedCount = submitted.filter((a) => FINISHED.includes(a.status)).filter(byType).length

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
      <PageTitle right={<SortFilter />}>Permit Tracking</PageTitle>

      <div className="mb-6">
        <FilterPills options={FILTERS} value={type} onChange={setType} />
      </div>

      {loading ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <>
          <EmptyState
            icon={TrackIcon}
            title={
              finishedCount > 0
                ? 'Nothing needs your attention'
                : type
                  ? 'Nothing matches this filter'
                  : 'No applications yet'
            }
            description={
              finishedCount > 0
                ? 'Every application you have filed has been approved. The permits are in your Profile.'
                : type
                  ? 'Try a different filter, or start a new application.'
                  : 'When you submit an application, it appears here with its live status and next step.'
            }
          />
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

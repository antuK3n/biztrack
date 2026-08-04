import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  CalendarIcon,
  DownloadIcon,
  EyeIcon,
  XCircleIcon,
  XIcon,
} from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { MessagesPanel } from '../../components/MessagesPanel'
import { TaxOrderBreakdown } from '../../components/TaxOrderBreakdown'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton, ProtoModal, StatusCard } from '../../components/ui/Proto'
import { formatDate, formatDateTime, formatMoney } from '../../lib/format'
import { applications } from '../../lib/resources'
import { applicationStatusMeta } from '../../lib/status'
import type { Application, TimelineEntry } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'

/*
 * Application status page (PDF p50/p52/p54–55/p57–58): centered serif
 * "Application Status" (or "Payment Status"), a StatusCard with the colored
 * top bar + big status glyph, the royal-italic officer line under the card,
 * Remarks rows for rejected/returned, and the blue "eye" chips for the
 * Tax Order of Payment / issued Business Permit.
 */

function HourglassIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10" strokeDasharray="55 8" />
      <path d="M9 7h6M9 17h6M9.5 7c0 2.2 1 3.4 2.5 4.4 1.5-1 2.5-2.2 2.5-4.4M9.5 17c0-2.2 1-3.4 2.5-4.4 1.5 1 2.5 2.2 2.5 4.4" />
    </svg>
  )
}

function MagnifierCheckIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="13.5" cy="9.5" r="6" />
      <path d="M4 21l5.2-6.4" />
      <path d="M11 9.6l1.8 1.8 3.2-3.4" />
    </svg>
  )
}

function CheckRingIcon({ size = 44 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10" />
      <path d="M7.5 12.3l3 3 5.8-6.2" />
    </svg>
  )
}

function AvatarGlyph({ size = 30 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="12" cy="12" r="11" fill="currentColor" />
      <circle cx="12" cy="9.5" r="3.4" fill="#fff" />
      <path d="M5.5 19a7 7 0 0 1 13 0 11 11 0 0 1-13 0Z" fill="#fff" />
    </svg>
  )
}

function MessageIcon({ size = 26 }: { size?: number }) {
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

/** Royal "eye" chip (👁 Tax Order of Payment / 👁 Business Permit). */
function EyeChip({
  label,
  onClick,
  to,
}: {
  label: string
  onClick?: () => void
  to?: string
}) {
  const inner = (
    <>
      <EyeIcon size={20} />
      <span className="underline underline-offset-4">{label}</span>
    </>
  )
  const cls =
    'inline-flex items-center gap-2.5 bg-royal px-6 py-2.5 text-base font-medium text-white transition-colors hover:bg-royal-hover'
  return to ? (
    <Link to={to} className={cls}>
      {inner}
    </Link>
  ) : (
    <button type="button" onClick={onClick} className={cls}>
      {inner}
    </button>
  )
}

/** Tax Order of Payment overlay (p51 card language). */
function FeeDialog({ app, onClose }: { app: Application; onClose: () => void }) {
  const fee = app.fee_assessment
  const panelRef = useRef<HTMLDivElement>(null)
  const openerRef = useRef<HTMLElement | null>(null)

  /*
   * This dialog announced itself as aria-modal but behaved like a panel: focus
   * stayed on the button behind it, Tab walked straight out into the page it
   * was covering, and Escape did nothing — so a keyboard user could open their
   * Tax Order of Payment and have no way to close it. ProtoModal already does
   * this properly; this one is hand-rolled, so it has to do it itself.
   */
  useEffect(() => {
    openerRef.current = document.activeElement as HTMLElement | null
    panelRef.current?.focus()

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
        return
      }
      if (event.key !== 'Tab') return
      const panel = panelRef.current
      if (!panel) return
      const focusable = panel.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      )
      // Nothing to cycle between: keep focus on the panel rather than letting
      // it fall through to the page underneath.
      if (focusable.length === 0) {
        event.preventDefault()
        panel.focus()
        return
      }
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      const active = document.activeElement
      if (event.shiftKey && (active === first || active === panel)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && active === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      // Put the user back where they were, not at the top of the document.
      openerRef.current?.focus?.()
    }
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      onClick={onClose}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label="Tax Order of Payment"
        tabIndex={-1}
        className="w-full max-w-xl rounded-md bg-white px-8 py-7 shadow-overlay focus:outline-none"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between">
          <h2 className="text-xl font-bold text-ink">Tax Order of Payment</h2>
          <button type="button" onClick={onClose} aria-label="Close" className="text-ink-secondary hover:text-ink">
            <XIcon size={20} />
          </button>
        </div>
        <p className="display-serif mt-4 text-lg text-ink">
          Reference No: <span className="ml-3">{app.tracking_id}</span>
        </p>
        <div className="display-serif mt-5 flex items-baseline justify-between border-b border-ink/40 pb-2 text-lg text-ink">
          <span>Description</span>
          <span>Charge</span>
        </div>
        <div className="mt-3 max-h-[50vh] overflow-y-auto pr-1">
          <TaxOrderBreakdown fee={fee} />
        </div>
        <div className="display-serif mt-6 flex items-baseline justify-between border-t border-ink/40 pt-4 text-2xl text-ink">
          <span>Total Amount:</span>
          {/*
            No assessment is not a bill for nothing. "₱0.00" here reads as
            "you owe nothing", which is the opposite of "the offices have not
            worked out what you owe yet".
          */}
          {fee ? (
            <span className="tnum">{formatMoney(fee.total_amount)}</span>
          ) : (
            <span className="text-base text-ink-muted">Not assessed yet</span>
          )}
        </div>
      </div>
    </div>
  )
}

export function ApplicationDetailPage() {
  const { id = '' } = useParams()
  const appId = Number(id)
  const navigate = useNavigate()
  const [banner, setBanner] = useState<string | null>(null)

  const { data: app, loading, error, reload, setData } = useAsync<Application>(
    () => applications.get(appId),
    [appId],
  )
  const { data: timeline } = useAsync<TimelineEntry[]>(() => applications.timeline(appId), [appId])

  const [confirmCancel, setConfirmCancel] = useState(false)
  const [showFees, setShowFees] = useState(false)
  const [action, setAction] = useState<'resubmit' | 'cancel' | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  async function runResubmit() {
    setAction('resubmit')
    setActionError(null)
    try {
      const updated = await applications.resubmit(appId)
      setData(updated)
      setBanner('Your application was resubmitted. An officer will review it again.')
      reload()
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setAction(null)
    }
  }

  async function runCancel() {
    setAction('cancel')
    setActionError(null)
    try {
      const updated = await applications.cancel(appId)
      setData(updated)
      setConfirmCancel(false)
      setBanner('This application was cancelled.')
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setAction(null)
    }
  }

  if (loading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="mx-auto h-64 w-full max-w-3xl rounded-lg" />
      </div>
    )
  }
  if (error || !app) return <ErrorState error={error ?? new Error('Not found')} onRetry={reload} />

  const status = app.status
  const isPayment = status === 'pending_payment'
  const issuedPermit = app.permits[0]
  const inspection = app.inspections.find((i) => i.scheduled_at) ?? app.inspections[0]
  const withRemarks = status === 'rejected' || status === 'returned'

  /* Remarks rows: rejection reason + any assignment remarks (p54–55). */
  const remarks: { who: string; text: string }[] = [
    ...(app.rejection_reason
      ? [{ who: 'Reason for rejection', text: app.rejection_reason }]
      : []),
    ...app.assignments
      .filter((a) => a.remarks)
      .map((a) => ({ who: a.officer?.name ?? a.department.name, text: a.remarks as string })),
  ]

  /* Officer line under the card (p50). */
  const assignment = app.assignments.find((a) => a.officer) ?? app.assignments[0]
  // "Officer - Department" when assigned; just the department name otherwise.
  const officerLine = assignment
    ? assignment.officer
      ? `${assignment.officer.name} - ${assignment.department.name}`
      : assignment.department.name
    : null

  return (
    <div className="mx-auto max-w-4xl">
      <div className="mb-8 border-b-2 border-ink/50 pb-2">
        <h1 className="text-2xl font-bold text-ink">{app.business.name}</h1>
      </div>

      {banner && (
        <div className="mb-4">
          <Alert variant="success">{banner}</Alert>
        </div>
      )}
      {actionError && (
        <div className="mb-4">
          <Alert variant="error">{actionError}</Alert>
        </div>
      )}

      <h2 className="display-serif mb-5 text-center text-3xl text-ink">
        {isPayment ? 'Payment Status' : 'Application Status'}
      </h2>

      <div className="mx-auto max-w-3xl">
        {/* ── The prototype status card ────────────────────────────────── */}
        {isPayment && (
          <StatusCard tone="orange">
            <div className="flex items-center gap-5 py-2 text-ink">
              <HourglassIcon />
              <span className="text-4xl font-medium">Pending</span>
            </div>
            <div className="mt-6 flex items-center gap-4">
              <EyeChip label="Tax Order of Payment" onClick={() => setShowFees(true)} />
              <button
                type="button"
                onClick={() => setShowFees(true)}
                aria-label="View Tax Order of Payment"
                className="text-royal hover:text-royal-hover"
              >
                <DownloadIcon size={26} />
              </button>
            </div>
            <PillButton className="mt-4" onClick={() => navigate(`/applications/${app.id}/pay`)}>
              Pay Online
            </PillButton>
          </StatusCard>
        )}

        {(status === 'submitted' || status === 'under_review') && (
          <StatusCard tone="orange">
            <div className="flex items-center gap-5 py-2 text-ink">
              <HourglassIcon />
              <span className="text-4xl font-medium">For Approval</span>
            </div>
            {app.deadline_at && (
              <p className="mt-3 text-base italic text-ink-secondary">
                Deadline: {formatDate(app.deadline_at)}
              </p>
            )}
          </StatusCard>
        )}

        {status === 'for_inspection' && (
          <StatusCard tone="yellow">
            <div className="flex items-center gap-5 py-2 text-ink">
              <MagnifierCheckIcon />
              <span className="text-4xl font-medium">For Inspection</span>
            </div>
            {inspection?.scheduled_at && (
              <p className="mt-3 flex items-center gap-2 text-base italic text-ink-secondary">
                <CalendarIcon size={18} />
                Scheduled Date: {formatDateTime(inspection.scheduled_at)}
              </p>
            )}
          </StatusCard>
        )}

        {(status === 'approved' || status === 'issued') && (
          <StatusCard tone="green">
            <div className="flex items-center gap-5 py-2 text-ink">
              <CheckRingIcon />
              <span className="text-4xl font-medium">Approved</span>
            </div>
            {issuedPermit ? (
              <div className="mt-6 flex items-center gap-4">
                <EyeChip label="Business Permit" to={`/permits/${issuedPermit.id}`} />
                <Link
                  to={`/permits/${issuedPermit.id}`}
                  aria-label="Download Business Permit"
                  className="text-royal hover:text-royal-hover"
                >
                  <DownloadIcon size={26} />
                </Link>
              </div>
            ) : (
              <p className="mt-3 text-sm italic text-ink-secondary">
                Your permit is being issued. It will appear here shortly.
              </p>
            )}
          </StatusCard>
        )}

        {(status === 'rejected' || status === 'cancelled') && (
          <StatusCard tone="red">
            <div className="flex items-center gap-5 py-2 text-ink">
              <XCircleIcon size={44} strokeWidth={1.6} />
              <span className="text-4xl font-medium">
                {status === 'cancelled' ? 'Cancelled' : 'Rejected'}
              </span>
            </div>
          </StatusCard>
        )}

        {status === 'returned' && (
          <StatusCard tone="red">
            <div className="flex items-center gap-5 py-2 text-ink">
              <XCircleIcon size={44} strokeWidth={1.6} />
              <span className="text-4xl font-medium">Returned</span>
            </div>
            <p className="mt-2 text-sm italic text-ink-secondary">
              Fix the remarks below, then resubmit. Your tracking ID stays the same.
            </p>
          </StatusCard>
        )}

        {status === 'draft' && (
          <StatusCard tone="orange">
            <div className="flex items-center gap-5 py-2 text-ink">
              <HourglassIcon />
              <span className="text-4xl font-medium">Draft</span>
            </div>
            <p className="mt-2 text-sm italic text-ink-secondary">This application has not been submitted yet.</p>
          </StatusCard>
        )}

        {/* ── Officer line (royal italic + avatar + message icons) ─────── */}
        {officerLine && (
          <div className="mt-3 flex items-center justify-end gap-3 text-royal">
            <span className="text-base">
              {assignment?.officer ? 'Mr/Ms ' : ''}
              <span className="italic">{officerLine}</span>
            </span>
            <AvatarGlyph />
            <MessageIcon />
          </div>
        )}

        {/* ── LGU Clearances · what this filing asked for ────────────────
          *
          * The clearances are chosen in the wizard now, as the last step
          * before Review & Submit, so this is no longer a stage still to come:
          * it is the record of what was decided. The link goes to the same
          * screen, which states in the API's own words why the six can no
          * longer be changed.
          *
          * Shown on a rejected or cancelled filing too, because that screen has
          * a specific sentence for each ("file a new application if you still
          * need these clearances") and a link that quietly disappears when a
          * filing fails teaches nothing. Not shown on a draft: a draft belongs
          * in the wizard, where the cards are editable and a read-only copy of
          * them would be the wrong door.
          */}
        {status !== 'draft' && (
          <section className="mt-8 rounded-2xl bg-white px-6 py-5 shadow-card">
            <h2 className="text-lg font-bold text-ink">LGU Clearances</h2>
            <p className="mt-1 text-sm text-ink-secondary">
              Sanitary, fire, zoning, environmental, occupancy and market. Each goes to a separate
              office with its own fee, and every one you chose is billed on this filing’s Tax Order
              of Payment.
            </p>
            <Link
              to={`/applications/${app.id}/clearances`}
              className="mt-3 inline-block text-sm font-semibold text-royal underline underline-offset-2 hover:text-royal-hover"
            >
              See your LGU Clearances
            </Link>
          </section>
        )}

        {/* ── Remarks (p54–55) ─────────────────────────────────────────── */}
        {withRemarks && (
          <section className="mt-8">
            <div className="border-b border-ink/50 pb-2">
              <h2 className="text-2xl font-bold text-ink">Remarks</h2>
            </div>
            <ul className="mt-5 space-y-4">
              {(remarks.length > 0
                ? remarks
                : [{ who: 'Reviewing office', text: 'No detailed remarks were recorded.' }]
              ).map((r, i) => (
                <li
                  key={i}
                  className="flex flex-wrap items-baseline gap-x-8 gap-y-1 rounded-xl bg-white px-6 py-4 shadow-card"
                >
                  <span className="text-sm italic text-ink-muted underline underline-offset-2">{r.who}:</span>
                  <span className="text-sm text-ink">{r.text}</span>
                </li>
              ))}
            </ul>
            <div className="mt-5 flex justify-end">
              {status === 'returned' ? (
                <PillButton onClick={runResubmit} disabled={action === 'resubmit'}>
                  {action === 'resubmit' ? 'Resubmitting…' : 'Resubmit'}
                </PillButton>
              ) : (
                <PillButton onClick={() => navigate('/apply')}>Re-apply</PillButton>
              )}
            </div>
          </section>
        )}

        {/* ── Compact secondary timeline ───────────────────────────────── */}
        {(timeline?.length ?? 0) > 0 && (
          <section className="mt-10">
            <p className="mb-3 text-xs font-bold uppercase tracking-wide text-ink-muted">History</p>
            <ol className="space-y-2.5 rounded-2xl bg-white px-6 py-5 shadow-card">
              {[...(timeline ?? [])].reverse().map((entry, i) => {
                const meta = applicationStatusMeta(entry.to_status)
                return (
                  <li key={`${entry.to_status}-${entry.created_at}-${i}`} className="flex items-baseline gap-3">
                    <span
                      className={`h-2 w-2 shrink-0 translate-y-[-1px] rounded-full ${
                        i === 0 ? 'bg-royal' : 'bg-line'
                      }`}
                    />
                    <span className="text-sm font-semibold text-ink">{meta.label}</span>
                    <span className="text-xs text-ink-muted">
                      {formatDateTime(entry.created_at)}
                      {entry.changed_by ? ` · ${entry.changed_by.name}` : ''}
                    </span>
                  </li>
                )
              })}
            </ol>
          </section>
        )}

        {['draft', 'submitted', 'under_review', 'returned'].includes(status) && (
          <div className="mt-8 text-center">
            <button
              type="button"
              onClick={() => setConfirmCancel(true)}
              className="text-sm font-semibold text-s-red underline underline-offset-2"
            >
              Cancel application
            </button>
          </div>
        )}

        {/* ── Messages thread (v2) ─────────────────────────────────────── */}
        {status !== 'draft' && <MessagesPanel applicationId={app.id} />}
      </div>

      {showFees && <FeeDialog app={app} onClose={() => setShowFees(false)} />}

      {confirmCancel && (
        <ProtoModal
          title="WARNING"
          tone="red"
          cancelLabel="Keep it"
          confirmLabel="Cancel application"
          confirmDisabled={action === 'cancel'}
          onCancel={() => setConfirmCancel(false)}
          onConfirm={runCancel}
        >
          <p className="text-center text-base">
            Cancelling stops all processing for <span className="tnum font-semibold">{app.tracking_id}</span>.
            This can’t be undone.
          </p>
        </ProtoModal>
      )}
    </div>
  )
}

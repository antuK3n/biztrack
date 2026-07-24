import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeftIcon, CalendarIcon, CheckCircleFilledIcon, SearchIcon, XCircleIcon, XIcon } from '../../components/icons'
import { EmptyState, ErrorState, Skeleton, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoModal, SortFilter, StatusCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { formatDate } from '../../lib/format'
import { inspections } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { Inspection } from '../../lib/types'

/*
 * Inspections (PDF p79, p81–82): queue-style white rows with the solid status
 * chip, then the "Application Status" card detail with the red remarks-doc
 * button + green Approve, and the red REMARKS FOR REJECTION modal on fail.
 */

function chipFor(item: Inspection): { tone: ChipTone; label: string } {
  if (item.result === 'passed') return { tone: 'green', label: 'Approved' }
  if (item.result === 'failed') return { tone: 'red', label: 'Rejected' }
  if (item.result === 'conditional') return { tone: 'orange', label: 'Conditional' }
  return { tone: 'yellow', label: 'For Inspection' }
}

function InspectionRow({ item }: { item: Inspection }) {
  const chip = chipFor(item)
  return (
    <li>
      <Link
        to={`/inspections/${item.id}`}
        className="flex items-stretch overflow-hidden rounded-lg bg-white shadow-card transition-shadow hover:shadow-raised"
      >
        <div className="min-w-0 flex-1 px-6 py-4">
          <p className="truncate text-[17px] font-bold text-ink">{item.application.business.name}</p>
          <p className="mt-0.5 text-sm italic text-ink-muted">{formatDate(item.scheduled_at)}</p>
        </div>
        <StatusChip tone={chip.tone} className="w-28 shrink-0 rounded-none! px-4 py-3 text-sm">
          {chip.label}
        </StatusChip>
      </Link>
    </li>
  )
}

export function InspectionsPage() {
  const { data, loading, error, reload } = useAsync(() => inspections.list(), [])

  return (
    <div>
      <PageTitle right={<SortFilter />}>Inspections</PageTitle>

      {loading ? (
        <SkeletonList rows={5} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : !data || data.length === 0 ? (
        <EmptyState
          icon={SearchIcon}
          title="No inspections scheduled"
          description="When an application needs a site visit, it will show up here."
        />
      ) : (
        <ul className="space-y-4">
          {data.map((item) => (
            <InspectionRow key={item.id} item={item} />
          ))}
        </ul>
      )}
    </div>
  )
}

/* ── Detail (p81–82) ─────────────────────────────────────────────────── */

/** Magnifier-with-check glyph beside "For Inspection" (p79). */
function MagnifierGlyph() {
  return (
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" className="text-ink" aria-hidden="true">
      <circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" strokeWidth="1.8" />
      <path d="m15.5 15.5 4.5 4.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
      <path d="m8 10.6 1.8 1.8 3.2-3.2" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

/** White document glyph inside the red reject button (p81). */
function DocGlyph() {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" className="text-white" aria-hidden="true">
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" fill="currentColor" />
      <path d="M8.5 12h7M8.5 15h7M8.5 9H11" stroke="#c11212" strokeWidth="1.4" strokeLinecap="round" />
    </svg>
  )
}

interface RemarkRow {
  complaint: string
  description: string
}

const remarkInput =
  'w-full rounded-full border border-ink-muted/40 bg-s-red-tint px-4 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-s-red'

/** REMARKS FOR REJECTION (p82): paired Complaint/Description rows + green Add+. */
function FailModal({
  onCancel,
  onProceed,
  submitting,
  error,
}: {
  onCancel: () => void
  onProceed: (findings: string) => void
  submitting: boolean
  error: string | null
}) {
  const [rows, setRows] = useState<RemarkRow[]>([
    { complaint: '', description: '' },
    { complaint: '', description: '' },
  ])

  const filled = rows.filter((r) => r.complaint.trim() || r.description.trim())
  const findings = filled
    .map((r) => (r.complaint.trim() && r.description.trim() ? `${r.complaint.trim()}: ${r.description.trim()}` : (r.complaint.trim() || r.description.trim())))
    .join('\n')

  function setRow(i: number, key: keyof RemarkRow, value: string) {
    setRows((prev) => prev.map((row, idx) => (idx === i ? { ...row, [key]: value } : row)))
  }

  return (
    <ProtoModal
      title="REMARKS FOR REJECTION"
      tone="red"
      wide
      cancelLabel="Cancel"
      confirmLabel="Proceed"
      onCancel={onCancel}
      onConfirm={() => onProceed(findings)}
      confirmDisabled={submitting || filled.length === 0}
    >
      <p className="text-lg text-ink">Add remarks about the inspection:</p>
      <div className="mt-5 grid grid-cols-[1fr_2fr_auto] items-center gap-x-4 gap-y-3">
        <span className="text-sm font-medium text-ink">Complaint</span>
        <span className="text-sm font-medium text-ink">Description</span>
        <span aria-hidden="true" />
        {rows.map((row, i) => (
          <div key={i} className="contents">
            <input
              className={remarkInput}
              placeholder="Type here…"
              value={row.complaint}
              onChange={(e) => setRow(i, 'complaint', e.target.value)}
              aria-label={`Complaint ${i + 1}`}
            />
            <input
              className={remarkInput}
              placeholder="Type here…"
              value={row.description}
              onChange={(e) => setRow(i, 'description', e.target.value)}
              aria-label={`Description ${i + 1}`}
            />
            <button
              type="button"
              aria-label={`Remove row ${i + 1}`}
              onClick={() => setRows((prev) => prev.filter((_, idx) => idx !== i))}
              disabled={rows.length <= 1}
              className="text-ink-muted hover:text-ink disabled:opacity-40"
            >
              <XIcon size={20} />
            </button>
          </div>
        ))}
      </div>
      <div className="mt-4 flex justify-end">
        <button
          type="button"
          onClick={() => setRows((prev) => [...prev, { complaint: '', description: '' }])}
          className="rounded-md bg-s-green px-4 py-2 text-sm font-semibold text-white hover:brightness-110"
        >
          Add +
        </button>
      </div>
      {error && <p className="mt-3 text-sm font-medium text-s-red">{error}</p>}
    </ProtoModal>
  )
}

function DetailSkeleton() {
  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <Skeleton className="mx-auto h-8 w-56" />
      <Skeleton className="h-56 w-full" />
    </div>
  )
}

export function InspectionDetailPage() {
  const { id } = useParams<{ id: string }>()
  const inspectionId = Number(id)
  const { data, loading, error, reload, setData } = useAsync(() => inspections.get(inspectionId), [inspectionId])
  const [failOpen, setFailOpen] = useState(false)
  const [reschedOpen, setReschedOpen] = useState(false)
  const [reschedValue, setReschedValue] = useState('')
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const backLink = (
    <Link
      to="/inspections"
      className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-royal hover:underline"
    >
      <ArrowLeftIcon size={16} />
      Back to Inspections
    </Link>
  )

  if (loading)
    return (
      <div>
        {backLink}
        <DetailSkeleton />
      </div>
    )
  if (error)
    return (
      <div>
        {backLink}
        <ErrorState error={error} onRetry={reload} />
      </div>
    )
  if (!data)
    return (
      <div>
        {backLink}
        <p className="rounded-lg bg-white px-5 py-6 text-sm text-ink-secondary shadow-card">
          This inspection may have been reassigned. Return to the list.
        </p>
      </div>
    )

  const done = Boolean(data.conducted_at) || ['completed', 'passed', 'failed'].includes(data.status.toLowerCase())
  const passed = data.result === 'passed'

  async function conduct(result: 'passed' | 'failed', findings?: string) {
    setBusy(true)
    setActionError(null)
    try {
      const updated = await inspections.conduct(inspectionId, { result, findings })
      setData(updated)
      setFailOpen(false)
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  async function reschedule() {
    if (!reschedValue) return
    setBusy(true)
    setActionError(null)
    try {
      const updated = await inspections.reschedule(inspectionId, new Date(reschedValue).toISOString())
      setData(updated)
      setReschedOpen(false)
      setReschedValue('')
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      {backLink}
      <PageTitle>Business Permit</PageTitle>

      <div className="mx-auto max-w-3xl">
        <h2 className="display-serif mb-6 mt-4 text-center text-3xl text-ink">Application Status</h2>

        {done ? (
          <StatusCard tone={passed ? 'green' : 'red'}>
            <div className="flex items-center gap-3">
              {passed ? (
                <CheckCircleFilledIcon size={44} className="text-s-green" />
              ) : (
                <XCircleIcon size={44} className="text-s-red" />
              )}
              <span className="text-3xl font-medium text-ink">{passed ? 'Approved' : 'Rejected'}</span>
            </div>
            <p className="text-base italic text-ink-secondary">
              Finished Date: {formatDate(data.conducted_at ?? data.scheduled_at)}
            </p>
            {data.findings && (
              <div className="mt-2 w-full rounded-lg bg-canvas px-5 py-4 text-left">
                <p className="text-sm font-bold text-ink">Findings</p>
                <p className="mt-1 whitespace-pre-wrap text-sm text-ink-secondary">{data.findings}</p>
              </div>
            )}
          </StatusCard>
        ) : (
          <StatusCard tone="yellow">
            <div className="flex items-center gap-3">
              <MagnifierGlyph />
              <span className="text-3xl font-medium text-ink">For Inspection</span>
            </div>
            <p className="flex items-center gap-2 text-base italic text-ink-secondary">
              Scheduled Date: {formatDate(data.scheduled_at)}
              <CalendarIcon size={20} className="not-italic text-ink-secondary" />
            </p>
          </StatusCard>
        )}

        {actionError && (
          <p className="mt-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{actionError}</p>
        )}

        <div className="mt-6 flex flex-wrap items-center justify-between gap-4">
          {!done ? (
            <div className="flex items-center gap-3">
              <button
                type="button"
                title="Reject with remarks"
                aria-label="Reject with remarks"
                onClick={() => setFailOpen(true)}
                disabled={busy}
                className="flex h-12 w-12 items-center justify-center rounded-lg bg-s-red shadow-card hover:brightness-110 disabled:opacity-60"
              >
                <DocGlyph />
              </button>
              <button
                type="button"
                onClick={() => conduct('passed')}
                disabled={busy}
                className="rounded-lg bg-s-green px-8 py-3 text-base font-semibold text-white underline underline-offset-2 shadow-card hover:brightness-110 disabled:opacity-60"
              >
                Approve
              </button>
            </div>
          ) : (
            <span />
          )}

          <div className="flex items-center gap-2.5 text-royal">
            <span className="text-base font-medium">{data.inspector?.name ?? data.department.name}</span>
            <span className="flex h-9 w-9 items-center justify-center rounded-full bg-royal text-white">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14Z" />
              </svg>
            </span>
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <rect x="3" y="4" width="18" height="14" rx="3" stroke="currentColor" strokeWidth="2" />
              <circle cx="8.5" cy="11" r="1.1" fill="currentColor" />
              <circle cx="12" cy="11" r="1.1" fill="currentColor" />
              <circle cx="15.5" cy="11" r="1.1" fill="currentColor" />
              <path d="m7 18-2 3v-3" fill="currentColor" />
            </svg>
          </div>
        </div>

        {!done && (
          <div className="mt-5">
            {reschedOpen ? (
              <div className="flex flex-wrap items-center gap-2.5">
                <input
                  type="datetime-local"
                  value={reschedValue}
                  onChange={(e) => setReschedValue(e.target.value)}
                  aria-label="New inspection date and time"
                  className="rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
                />
                <button
                  type="button"
                  onClick={reschedule}
                  disabled={busy || !reschedValue}
                  className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover disabled:opacity-60"
                >
                  Save new date
                </button>
                <button
                  type="button"
                  onClick={() => setReschedOpen(false)}
                  className="text-sm font-semibold text-ink-muted underline underline-offset-2"
                >
                  Never mind
                </button>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => setReschedOpen(true)}
                className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline"
              >
                Reschedule this inspection
              </button>
            )}
          </div>
        )}
      </div>

      {failOpen && (
        <FailModal
          onCancel={() => setFailOpen(false)}
          onProceed={(findings) => conduct('failed', findings || undefined)}
          submitting={busy}
          error={actionError}
        />
      )}
    </div>
  )
}

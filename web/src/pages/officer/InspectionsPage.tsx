import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeftIcon, CalendarIcon, CheckCircleFilledIcon, SearchIcon, XCircleIcon, XIcon } from '../../components/icons'
import { EmptyState, ErrorState, Skeleton, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoModal, SortFilter, StatusCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { applicationTypeLabel, businessName, formatDate } from '../../lib/format'
import { inspections } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { Inspection, InspectionParticulars } from '../../lib/types'

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

/**
 * What to head a row with, and what to put under it.
 *
 * A business can be removed from the register after its permits are decided, and
 * the API sends `business: null` for every inspection that pointed at one — 237
 * of the 2,852 visits on record. The visit stays on the record and still has to
 * be readable, so the heading falls back to the shared wording from
 * `businessName()` rather than blanking or crashing.
 *
 * The tracking ID rides along on every row, not only the removed ones. It is the
 * only handle an officer has on a filing whose business is gone, and it was
 * already the thing they read out to a caller — hiding it behind a failure case
 * meant the common row lost it for no reason.
 *
 * `application` itself is nullable because InspectionResource refuses to
 * lazy-load the relation; this list eager-loads it, so in practice it is there,
 * but the type is honest and so is this.
 */
function headingFor(item: Inspection): { name: string; trackingId: string | null; removed: boolean } {
  const app = item.application
  return {
    name: businessName(app?.business ?? null),
    trackingId: app?.tracking_id ?? null,
    removed: !app?.business,
  }
}

function InspectionRow({ item }: { item: Inspection }) {
  const chip = chipFor(item)
  const heading = headingFor(item)
  return (
    <li>
      <Link
        to={`/staff/inspections/${item.id}`}
        className="flex items-stretch overflow-hidden rounded-lg bg-white shadow-card transition-shadow hover:shadow-raised"
      >
        <div className="min-w-0 flex-1 px-6 py-4">
          <p
            className={`truncate text-[17px] font-bold ${heading.removed ? 'text-ink-muted italic' : 'text-ink'}`}
          >
            {heading.name}
          </p>
          {heading.trackingId && (
            <p className="mt-0.5 truncate text-xs font-semibold uppercase tracking-wide text-ink-muted">
              {heading.trackingId}
            </p>
          )}
          <p className="mt-0.5 text-sm italic text-ink-muted">{formatDate(item.scheduled_at)}</p>
        </div>
        <StatusChip tone={chip.tone} className="w-28 shrink-0 rounded-none! px-4 py-3 text-sm">
          {chip.label}
        </StatusChip>
      </Link>
    </li>
  )
}

/**
 * The inspection list.
 *
 * Paged, because this register carries thousands of visits and rendering the
 * whole set is what took the page down. One page arrives at a time, and the
 * reader is told the total so a first page is never mistaken for the whole list.
 */
export function InspectionsPage() {
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState<Inspection[]>([])
  const { data, loading, error, reload } = useAsync(() => inspections.list({ page }), [page])

  // Append rather than replace: paging in should extend the list an officer is
  // reading, not drop them back at the top of a fresh one. Appending by id keeps
  // that idempotent — Try again after a failed page 3 re-fetches page 3, and a
  // blind concat would have shown every one of those rows twice under duplicate
  // React keys.
  useEffect(() => {
    if (!data) return
    setRows((prev) => {
      if (data.meta.current_page === 1) return data.data
      const seen = new Set(prev.map((r) => r.id))
      return [...prev, ...data.data.filter((r) => !seen.has(r.id))]
    })
  }, [data])

  const total = data?.meta.total ?? 0
  const hasMore = data ? data.meta.current_page < data.meta.last_page : false
  const firstLoad = loading && rows.length === 0

  return (
    <div>
      <PageTitle right={<SortFilter />}>Inspections</PageTitle>

      {firstLoad ? (
        <SkeletonList rows={5} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={SearchIcon}
          title="No inspections scheduled"
          description="When an application needs a site visit, it will show up here."
        />
      ) : (
        <>
          <p className="mb-3 text-sm text-ink-muted">
            Showing {rows.length.toLocaleString()} of {total.toLocaleString()}, most recent first.
          </p>
          <ul className="space-y-4">
            {rows.map((item) => (
              <InspectionRow key={item.id} item={item} />
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

/* ── What the applicant filed (GUI updated-gui/68.png, 69.png) ────────── */

/** One submitted answer, presented as a record and never as a control. */
function Fact({ label, value, className = '' }: { label: string; value: string | null; className?: string }) {
  return (
    <div className={className}>
      <dt className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">{label}</dt>
      <dd className="mt-0.5 text-sm text-ink">{value || '—'}</dd>
    </div>
  )
}

/** Royal tick sub-section label, matching the review sheet's "Main Office Address". */
function FactHeading({ children }: { children: ReactNode }) {
  return (
    <div className="col-span-full mb-0.5 mt-2 flex items-center gap-2 first:mt-0">
      <span className="h-3.5 w-1 rounded-full bg-royal" aria-hidden="true" />
      <h3 className="text-[13px] font-bold text-ink">{children}</h3>
    </div>
  )
}

/**
 * The filing's particulars, on the visit that is meant to verify them.
 *
 * The screen used to carry a status, a date and a name, which meant an officer
 * standing outside the premises could not see the address they had come to
 * inspect, who owned it, or what trade it claimed to be in. The client asked for
 * "the same as what was submitted in the application form; refer to the modal in
 * the GUI".
 *
 * There is no such modal in the mockups — updated-gui/ has no dialog listing a
 * filing's answers. What it does have is the full-page BPLO admin review sheet
 * (68.png / 69.png / 76.png, "Application for New Business Permit"), which this
 * app already renders as ReviewPage. So the labels below are that sheet's, to
 * the letter — "Trade Name / Franchise", "DTI / SEC / CDA Registration Number",
 * "Main Office Address", "City / Municipality". An officer who reads the review
 * sheet and then this card must not have to work out that two different phrases
 * name the same box on the paper form.
 *
 * Deliberately compact, and deliberately below the decision controls — see the
 * comment at the call site for why the reading order loses that argument.
 */
function SubmittedDetails({ particulars }: { particulars: InspectionParticulars }) {
  const p = particulars

  // House/building number and street are stored apart (line2 is the number) and
  // are read as one line by anybody trying to find the place. Joined here rather
  // than shown as two fields for that reason; either half can be absent.
  const street = [p.address_line2, p.address].filter(Boolean).join(' ') || null

  /*
   * `businessName()`, not `p.business_name`, for the same reason every other
   * officer screen uses it: `Business` soft-deletes while its inspections stay,
   * so this is null on visits against a business that has left the register.
   * "Business removed from register" tells the officer why the rest of the card
   * is empty; a blank heading would send them looking for a bug.
   */
  const removed = !p.business_name

  return (
    <section
      aria-label="Application details"
      className="mt-6 rounded-lg bg-white px-6 py-5 text-left shadow-card"
    >
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-b border-line pb-3">
        <h2 className="text-[15px] font-bold text-ink">Application Details</h2>
        <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
          {p.application_type ? applicationTypeLabel(p.application_type) : 'As submitted'}
        </p>
      </div>

      <dl className="grid grid-cols-1 gap-x-6 gap-y-3.5 sm:grid-cols-3">
        <Fact label="Owner" value={p.owner_name} />
        <Fact
          label="Business Name"
          value={removed ? businessName(null) : p.business_name}
          className={removed ? 'italic text-ink-muted' : ''}
        />
        <Fact label="Trade Name / Franchise" value={p.trade_name} />

        <Fact label="DTI / SEC / CDA Registration Number" value={p.registration_number} />
        <Fact label="Tax Identification Number (TIN)" value={p.tin} />
        <Fact label="Line of Business" value={p.line_of_business} />

        <FactHeading>Main Office Address</FactHeading>
        <Fact label="Address" value={street} className="sm:col-span-2" />
        <Fact label="Barangay" value={p.barangay} />
        <Fact label="City / Municipality" value={p.city} />
        <Fact label="Province" value={p.province} />
        <Fact label="Postal Code" value={p.postal_code} />
      </dl>

      {/*
        Which permits this visit is for. A filing can carry several and the
        checklist differs per permit, so the officer is told all of them rather
        than left to infer it from whichever office they happen to work in.
      */}
      <div className="mt-5 border-t border-line pt-4">
        <p className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
          Permits applied for
        </p>
        {p.permit_types.length > 0 ? (
          <ul className="mt-2 flex flex-wrap gap-2">
            {p.permit_types.map((pt) => (
              <li
                key={pt.code}
                className="rounded-full bg-royal-tint px-3 py-1 text-xs font-semibold text-royal"
              >
                {pt.name}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-1 text-sm text-ink">—</p>
        )}
      </div>
    </section>
  )
}

/**
 * Fold an action reply into the record on screen without losing the filing.
 *
 * `conduct` and `reschedule` answer with a plain InspectionResource — the same
 * eager set as the list, which does not reach the owner, the address or the
 * declared lines, so `particulars` comes back null. Assigning that reply
 * straight over the loaded record made the whole Application Details card
 * vanish the moment an officer pressed Approve, which is precisely when they
 * are most likely to want to re-read what they just approved.
 *
 * The reply wins on every field it actually carries; `particulars` only falls
 * back when the reply has none. Nothing here invents state — the filing's
 * particulars cannot change as a side effect of recording a visit, so the copy
 * already fetched is still the truth.
 */
function keepParticulars(updated: Inspection): (prev: Inspection | null) => Inspection {
  return (prev) => ({ ...updated, particulars: updated.particulars ?? prev?.particulars ?? null })
}

function DetailSkeleton() {
  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <Skeleton className="mx-auto h-8 w-56" />
      <Skeleton className="h-56 w-full" />
    </div>
  )
}

/**
 * The filing's own status, when the response carried it.
 *
 * `GET /inspections/{id}` swaps the stub `application` for the whole
 * ApplicationResource, so the filing's status is on this screen's payload and on
 * no other inspection payload. The shared `Inspection` type describes the stub,
 * which is the honest description of what the LIST sends, so this reads the
 * extra field through a local widening rather than adding a property the list
 * would then be claiming to send and does not.
 *
 * `undefined` means "this response did not carry it" and never "the filing has
 * no status" — the caller below treats it as unknown rather than as a reason to
 * withhold the action, because the API refuses the action anyway and a control
 * that vanishes on a thin payload is harder to explain than one that answers.
 */
function filingStatus(item: Inspection): string | undefined {
  return (item.application as { status?: unknown } | null)?.status as string | undefined
}

export function InspectionDetailPage() {
  const { id } = useParams<{ id: string }>()
  const inspectionId = Number(id)
  const navigate = useNavigate()
  const { data, loading, error, reload, setData } = useAsync(() => inspections.get(inspectionId), [inspectionId])
  const [failOpen, setFailOpen] = useState(false)
  const [reschedOpen, setReschedOpen] = useState(false)
  const [reschedValue, setReschedValue] = useState('')
  const [reinspectOpen, setReinspectOpen] = useState(false)
  const [reinspectValue, setReinspectValue] = useState('')
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const backLink = (
    <Link
      to="/staff/inspections"
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

  /*
   * The one control a conducted visit may still offer.
   *
   * `done` hides Approve and Reject, correctly — the visit has happened and its
   * result is recorded. But a FAILED visit used to leave this screen with no
   * control at all, on a filing the workflow had also left in `for_inspection`
   * forever: six live filings sat in exactly that dead end, openable and
   * unactionable from both ends. This is the way out, and it is the only action
   * offered here because it is the only one that makes sense — the visit is not
   * being re-judged, a second visit is being booked.
   *
   * Not offered on a decided filing (nothing left to inspect for). It IS still
   * offered on a failure that a later visit has already replaced, because this
   * payload cannot see that later visit; the API refuses that one with a 422
   * saying so, which surfaces in the same place every other action error does.
   */
  const filing = filingStatus(data)
  /*
   * The server's answer wins whenever it gave one.
   *
   * The local test below can see two of the three conditions — the visit
   * failed, the filing is still for inspection — but not the third, which is
   * whether a LATER visit has already replaced this one. Nothing in this
   * payload says another row exists, so an office that had failed twice went on
   * offering "Schedule re-inspection" on the older failure, and the API
   * answered every press with a 422. A control whose only outcome is an error
   * message is not a control.
   *
   * `can_reinspect` is null when the response was not asked to load the filing
   * (the paginated list nests this resource without it), and null means "not
   * computed" rather than "no" — so the local test stays as the fallback for
   * exactly that case, and is not deleted.
   */
  const canReinspect =
    data.can_reinspect ??
    (done && data.result === 'failed' && (filing === undefined || filing === 'for_inspection'))

  async function conduct(result: 'passed' | 'failed', findings?: string) {
    setBusy(true)
    setActionError(null)
    try {
      const updated = await inspections.conduct(inspectionId, { result, findings })
      setData(keepParticulars(updated))
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
      setData(keepParticulars(updated))
      setReschedOpen(false)
      setReschedValue('')
    } catch (err) {
      setActionError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  /**
   * Book the follow-up visit, then go to it.
   *
   * The reply is a DIFFERENT inspection — the failed one is left on the record
   * untouched — so this navigates instead of folding the reply into the record
   * on screen the way conduct and reschedule do. Staying put would leave the
   * officer looking at the failure they just acted on while the visit they
   * created, and will have to conduct, sits at another URL.
   */
  async function reinspect() {
    if (!reinspectValue) return
    setBusy(true)
    setActionError(null)
    try {
      const created = await inspections.reinspect(inspectionId, new Date(reinspectValue).toISOString())
      setReinspectOpen(false)
      setReinspectValue('')
      navigate(`/staff/inspections/${created.id}`)
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
        {/*
          Which visit this is. The page used to open on "Business Permit ·
          Application Status" and nothing else — an officer arriving from a list
          of 1,249 rows had no confirmation they had opened the right one, and
          approving or rejecting a site visit is not a decision to make on the
          strength of the row you thought you clicked.
        */}
        <p className="mt-4 text-center text-xl font-bold text-ink">
          {businessName(data.application?.business ?? null)}
        </p>
        {data.application?.tracking_id && (
          <p className="mt-1 text-center text-sm font-semibold uppercase tracking-wide text-ink-muted">
            {data.application.tracking_id}
          </p>
        )}

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
          ) : canReinspect ? (
            /*
              The failed visit's one action, in the slot Approve and Reject
              occupy on a visit that has not happened yet — this is the primary
              thing to do on this screen, and the complaint upstream of the whole
              change was a control nobody could find.
            */
            <div className="flex flex-wrap items-center gap-2.5">
              {reinspectOpen ? (
                <>
                  <input
                    type="datetime-local"
                    value={reinspectValue}
                    onChange={(e) => setReinspectValue(e.target.value)}
                    aria-label="Re-inspection date and time"
                    className="rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
                  />
                  <button
                    type="button"
                    onClick={reinspect}
                    disabled={busy || !reinspectValue}
                    className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover disabled:opacity-60"
                  >
                    Book this visit
                  </button>
                  <button
                    type="button"
                    onClick={() => setReinspectOpen(false)}
                    className="text-sm font-semibold text-ink-muted underline underline-offset-2"
                  >
                    Never mind
                  </button>
                </>
              ) : (
                <button
                  type="button"
                  onClick={() => setReinspectOpen(true)}
                  disabled={busy}
                  className="rounded-lg bg-royal px-8 py-3 text-base font-semibold text-white shadow-card hover:bg-royal-hover disabled:opacity-60"
                >
                  Schedule re-inspection
                </button>
              )}
            </div>
          ) : (
            <span />
          )}

          <div className="flex items-center gap-2.5 text-royal">
            {/*
              Every link in this chain is nullable on the wire: an inspection can
              be scheduled before an inspector is named, and InspectionResource
              emits `department: null` for a relation it was not asked to load.
              The old fallback guarded the inspector and then dereferenced the
              department, which is the half-guard that made this page white-screen
              rather than merely look thin.
            */}
            <span className="text-base font-medium">
              {data.inspector?.name ?? data.department?.name ?? 'Unassigned'}
            </span>
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

        {/*
          What the button will actually do, said before it is pressed.

          "Re-inspection" is a word an officer could reasonably read as "undo
          the failure" or "let it through this time", and it is neither. The
          three facts that matter are that the failure is kept, that the
          applicant's filing does not move, and that nothing is approved without
          somebody conducting the new visit.
        */}
        {canReinspect && !reinspectOpen && (
          <p className="mt-3 max-w-xl text-sm text-ink-secondary">
            This visit stays on the record as a failure. Scheduling a re-inspection books a fresh visit
            {data.department ? ` for ${data.department.name}` : ''} once the owner has put the finding
            right — the application stays “For Inspection” until that visit passes, and nothing is
            approved on its own.
          </p>
        )}

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

        {/*
          What the applicant filed, below the decision controls rather than
          above them.

          Reading order says the record should come first — you approve a site
          visit because of these facts. It is here anyway, because the complaint
          this screen is answering came in two halves and the other half was
          "there is no approval button". The mock (updated-gui/82.png) puts
          Approve directly under the status card, and a record block of this
          height in between pushed it to the very bottom of a 1000px viewport
          and off a shorter one. Losing the button again to make the sheet read
          better would be trading one half of the complaint for the other.

          Rendered only when the response actually carried it. `particulars` is
          null on any payload that did not eager-load the filing — the list, and
          the conduct/reschedule replies — and null there means "not fetched",
          not "nothing was submitted". An empty card would be a claim about the
          applicant that the response never made.
        */}
        {data.particulars && <SubmittedDetails particulars={data.particulars} />}
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

import { useId, useState } from 'react'
import { Link } from 'react-router-dom'
import { CalendarIcon, CheckCircleFilledIcon, XCircleIcon, XIcon } from './icons'
import { ProtoModal } from './ui/Proto'
import { toApiError } from '../lib/api'
import { formatDate } from '../lib/format'
import { inspections as inspectionsApi } from '../lib/resources'
import { useAuth } from '../stores/auth'
import type { Inspection } from '../lib/types'

/*
 * The site visit, decided from wherever the officer opened the filing.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * A filing sitting in `for_inspection` has, by definition, finished every
 * paperwork review: WorkflowService::afterReviewProgress only moves it there
 * once the last office assignment completes. So ReviewPage saw `completed_at`
 * set on the assignment, concluded the review was `decided`, printed a static
 * green "Approved" in the header, and offered no controls at all — while the
 * thing actually outstanding, the inspection, had nowhere on that page to be
 * approved or rejected. The officer's own words: "there's no thing to approve
 * something that's for inspection".
 *
 * The visit already had a home at /staff/inspections/{id}, but nothing on the
 * queue linked there, and an officer who clicks a For Inspection row in
 * Application Verification lands on the review sheet, not there.
 *
 * ── Why it is its own file ──────────────────────────────────────────────────
 *
 * InspectionsPage renders the same decision — the same red remarks glyph, the
 * same green Approve, the same REMARKS FOR REJECTION modal (updated-gui/82.png
 * and 83.png), the same re-inspection booking — from its own private copies of
 * this markup.
 *
 * TODO: fold InspectionsPage's detail view onto these components. It is NOT
 * done here on purpose: that file is being edited concurrently, and two agents
 * rewriting the same 800 lines is how a merge eats one of them. When it is
 * folded in, the pieces to delete there are `MagnifierGlyph`, `DocGlyph`,
 * `FailModal` and the status-card block around line 586 — they are the same
 * shapes as `MagnifierGlyph`, `RejectGlyph`, `InspectionRemarksModal` and
 * `InspectionDecisionCard` below. If the two drift apart before then, this
 * file is the one that matches the mock; check it first.
 *
 * ── Deliberately NOT a copy of the inspection detail page ───────────────────
 *
 * 82.png is one card on a page of its own. A filing can carry several visits —
 * only SANITARY and FSIC permit types set `requires_inspection`, but that is
 * still two offices on a typical filing, and the seeder has produced three —
 * so this renders a card each, and each is a good deal shorter than the
 * single-visit page's `StatusCard` (px-8 py-10). Three of those stacked is a
 * screenful of padding before the officer reaches the second decision.
 */

/** Magnifier-with-check glyph beside "For Inspection" (PDF p79). */
function MagnifierGlyph({ size = 34 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" className="text-ink" aria-hidden="true">
      <circle cx="10.5" cy="10.5" r="6.5" stroke="currentColor" strokeWidth="1.8" />
      <path d="m15.5 15.5 4.5 4.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
      <path
        d="m8 10.6 1.8 1.8 3.2-3.2"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

/** White document glyph inside the red reject button (PDF p81). */
function RejectGlyph() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" className="text-white" aria-hidden="true">
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

/**
 * REMARKS FOR REJECTION (updated-gui/83.png): paired Complaint/Description
 * rows and a green Add+ that grows the list.
 *
 * The remarks are the whole point of the dialog and not decoration. A failed
 * visit that records no finding leaves the owner a rejected permit and no
 * statement of what to put right, and leaves the next inspector nothing to
 * re-check.
 *
 * Proceed therefore stays REACHABLE while the rows are empty and points at the
 * sentence saying why it will not do anything yet — ProtoModal's own
 * `confirmDescribedBy` path. Shutting it with `disabled` would drop the one
 * control that explains the situation out of the tab order, so a screen-reader
 * user would meet a dialog with no visible way forward and no stated reason
 * (WCAG 3.3.1/3.3.3). `confirmDisabled` is reserved for the in-flight moment,
 * where the reason is that the request is already on its way.
 *
 * `subject` names the office whose visit is being rejected. Without it, a
 * filing with a sanitary and a fire visit opens two identical red dialogs and
 * the officer has only their own memory of which glyph they pressed.
 */
export function InspectionRemarksModal({
  subject,
  onCancel,
  onProceed,
  submitting,
  error,
}: {
  subject: string
  onCancel: () => void
  onProceed: (findings: string) => void
  submitting: boolean
  error: string | null
}) {
  const [rows, setRows] = useState<RemarkRow[]>([
    { complaint: '', description: '' },
    { complaint: '', description: '' },
  ])
  const hintId = useId()

  const filled = rows.filter((r) => r.complaint.trim() || r.description.trim())
  const findings = filled
    .map((r) =>
      r.complaint.trim() && r.description.trim()
        ? `${r.complaint.trim()}: ${r.description.trim()}`
        : r.complaint.trim() || r.description.trim(),
    )
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
      // The guard is here as well as in the hint: a reachable button must still
      // refuse to send an empty rejection.
      onConfirm={() => filled.length > 0 && onProceed(findings)}
      confirmDisabled={submitting}
      confirmDescribedBy={filled.length === 0 ? hintId : undefined}
    >
      <p className="text-lg text-ink">Add remarks about the inspection:</p>
      <p className="mt-1 text-sm text-ink-secondary">{subject}</p>
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
              // Kept reachable at one row so the reason below is reachable too.
              aria-disabled={rows.length <= 1}
              className="text-ink-muted hover:text-ink aria-disabled:opacity-40"
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
      {filled.length === 0 && (
        <p id={hintId} aria-live="polite" className="mt-3 text-sm font-medium text-ink-muted">
          Write at least one remark to continue.
        </p>
      )}
      {error && <p className="mt-3 text-sm font-medium text-s-red">{error}</p>}
    </ProtoModal>
  )
}

/** Has this visit happened? Mirrors the inspection detail screen's own test. */
export function inspectionDone(item: Inspection): boolean {
  return (
    Boolean(item.conducted_at) || ['completed', 'passed', 'failed'].includes(item.status.toLowerCase())
  )
}

/** The office that owns a visit, for headings and accessible names. */
function officeOf(item: Inspection): string {
  return item.department?.name ?? 'Inspecting office'
}

const STATE = {
  pending: { bar: 'bg-s-yellow', label: 'For Inspection' },
  passed: { bar: 'bg-s-green', label: 'Approved' },
  failed: { bar: 'bg-s-red', label: 'Rejected' },
  conditional: { bar: 'bg-s-orange', label: 'Conditional' },
} as const

/**
 * One site visit, said the way the mock says it (updated-gui/82.png): a bar of
 * colour, the state in words next to its glyph, the date in italics, and —
 * while the visit is still outstanding — the red remarks button and the green
 * Approve, with the inspector named on the right.
 *
 * Presentational on purpose. It reports which button was pressed and lets the
 * caller own the request, so the same card can sit on the review sheet, on the
 * inspection detail page, or in a test that never touches the network.
 *
 * `canAct` false renders NO decision buttons rather than shut ones. That is
 * not the same choice as hiding a control the reader could otherwise use: the
 * API refuses a conduct from another department with a 403
 * (InspectionController::authorizeDepartment), so for a fire officer looking
 * at a sanitary visit there is no action here at all, and an `aria-disabled`
 * Approve would be advertising one that will never open. The card says whose
 * visit it is instead.
 */
export function InspectionDecisionCard({
  item,
  canAct,
  busy,
  onApprove,
  onReject,
  reinspect,
}: {
  item: Inspection
  canAct: boolean
  busy: boolean
  onApprove: () => void
  onReject: () => void
  /** Absent when a re-inspection is not on offer for this visit. */
  reinspect?: {
    open: boolean
    value: string
    onOpen: () => void
    onClose: () => void
    onChange: (value: string) => void
    onBook: () => void
  }
}) {
  const done = inspectionDone(item)
  const state = !done
    ? STATE.pending
    : item.result === 'passed'
      ? STATE.passed
      : item.result === 'failed'
        ? STATE.failed
        : item.result === 'conditional'
          ? STATE.conditional
          : /*
             * Conducted, but the payload carries no result. Nothing in the API
             * writes that combination today — `conduct` requires a result — but
             * a card that fell through to "For Inspection" here would offer
             * Approve on a visit that has already happened, which is worse than
             * admitting the record is odd.
             */
            { bar: 'bg-line', label: item.status_label || 'Recorded' }

  const office = officeOf(item)
  /*
   * Every repeated control names its own inspection. Six buttons all called
   * "Approve" is a list a screen-reader user cannot navigate — the same defect
   * that was fixed on the document rows of the review sheet (DocumentActions),
   * so it is not being reintroduced one section further down.
   */
  const approveLabel = `Approve the ${office} inspection`
  const rejectLabel = `Reject the ${office} inspection with remarks`

  return (
    <li className="overflow-hidden rounded-2xl bg-white shadow-card">
      <div className={`h-2.5 ${state.bar}`} />
      <div className="px-6 py-5">
        <p className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">{office}</p>

        <div className="mt-2 flex flex-wrap items-center gap-3">
          {done && item.result === 'passed' ? (
            <CheckCircleFilledIcon size={34} className="text-s-green" />
          ) : done && item.result === 'failed' ? (
            <XCircleIcon size={34} className="text-s-red" />
          ) : (
            <MagnifierGlyph />
          )}
          <span className="text-2xl font-medium text-ink">{state.label}</span>
        </div>

        <p className="mt-1.5 flex items-center gap-2 text-sm italic text-ink-secondary">
          {done
            ? `Finished Date: ${formatDate(item.conducted_at ?? item.scheduled_at)}`
            : `Scheduled Date: ${formatDate(item.scheduled_at)}`}
          {!done && <CalendarIcon size={18} className="not-italic text-ink-secondary" />}
        </p>

        {item.findings && (
          <div className="mt-3 rounded-lg bg-canvas px-4 py-3">
            <p className="text-sm font-bold text-ink">Findings</p>
            <p className="mt-1 whitespace-pre-wrap text-sm text-ink-secondary">{item.findings}</p>
          </div>
        )}

        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          {!done && canAct ? (
            <div className="flex items-center gap-3">
              {/*
               * `aria-disabled`, never `disabled`, on a control that is only
               * shut for the moment a request is in flight: a disabled button
               * leaves the tab order, so the keyboard focus a user had on it
               * jumps somewhere else mid-action and does not come back. The
               * click handler carries the same guard, so a press that lands
               * during the request does nothing rather than sending it twice.
               */}
              <button
                type="button"
                title={rejectLabel}
                aria-label={rejectLabel}
                aria-disabled={busy}
                onClick={() => !busy && onReject()}
                className="flex h-11 w-11 items-center justify-center rounded-lg bg-s-red shadow-card hover:brightness-110 aria-disabled:opacity-60"
              >
                <RejectGlyph />
              </button>
              <button
                type="button"
                aria-label={approveLabel}
                aria-disabled={busy}
                onClick={() => !busy && onApprove()}
                className="rounded-lg bg-s-green px-7 py-2.5 text-sm font-semibold text-white underline underline-offset-2 shadow-card hover:brightness-110 aria-disabled:opacity-60"
              >
                Approve
              </button>
            </div>
          ) : !done ? (
            /*
             * Said, rather than shown as a dead button. The reader is not being
             * refused for a reason they can fix by clicking harder — the visit
             * belongs to somebody else — so the sentence IS the control's
             * replacement, not an apology for it.
             */
            <p className="max-w-md text-sm text-ink-secondary">
              {office} records this result. Your office cannot approve or reject it.
            </p>
          ) : reinspect ? (
            /*
             * The way out of a failed visit.
             *
             * A failure used to leave the filing in `for_inspection` with
             * nothing left to press, from either screen. Booking a fresh visit
             * is the only action that makes sense here: the failure is not
             * being re-judged, it stays on the record, and a second visit is
             * added beside it.
             */
            <div className="flex flex-wrap items-center gap-2.5">
              {reinspect.open ? (
                <>
                  <input
                    type="datetime-local"
                    value={reinspect.value}
                    onChange={(e) => reinspect.onChange(e.target.value)}
                    aria-label={`Re-inspection date and time for the ${office} visit`}
                    className="rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
                  />
                  <button
                    type="button"
                    aria-label={`Book the new ${office} visit`}
                    aria-disabled={busy || !reinspect.value}
                    onClick={() => busy || !reinspect.value || reinspect.onBook()}
                    className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover aria-disabled:opacity-60"
                  >
                    Book this visit
                  </button>
                  <button
                    type="button"
                    onClick={reinspect.onClose}
                    className="text-sm font-semibold text-ink-muted underline underline-offset-2"
                  >
                    Never mind
                  </button>
                </>
              ) : (
                <button
                  type="button"
                  aria-label={`Schedule a re-inspection for the ${office} visit`}
                  onClick={reinspect.onOpen}
                  className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover"
                >
                  Schedule re-inspection
                </button>
              )}
            </div>
          ) : (
            /*
             * A conducted visit with no action left here still has a record
             * worth opening — photos, the reschedule history, the particulars
             * the officer verified — and on a FAILED visit that record is also
             * where the way out lives when this payload could not work out
             * whether to offer it (see `canReinspect` below). So the link is on
             * every card rather than conditioned on a flag: it is never wrong,
             * and it means a failure is never a dead end on this screen even
             * when the flag is unavailable.
             */
            <Link
              to={`/staff/inspections/${item.id}`}
              aria-label={`Open the ${office} inspection record`}
              className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline"
            >
              Open the inspection record
            </Link>
          )}

          {/*
            Every link in this chain is nullable on the wire: a visit can be
            scheduled before an inspector is named, and InspectionResource emits
            `department: null` for a relation nobody asked it to load.
          */}
          <span className="flex items-center gap-2.5 text-royal">
            <span className="text-sm font-medium">
              {item.inspector?.name ?? item.department?.name ?? 'Unassigned'}
            </span>
            <span className="flex h-8 w-8 items-center justify-center rounded-full bg-royal text-white">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14Z" />
              </svg>
            </span>
          </span>
        </div>

        {reinspect && !reinspect.open && (
          /*
           * What the button will actually do, said before it is pressed.
           * "Re-inspection" is a word an officer could reasonably read as "undo
           * the failure" or "let it through this time", and it is neither.
           */
          <p className="mt-3 max-w-xl text-sm text-ink-secondary">
            This visit stays on the record as a failure. Scheduling a re-inspection books a fresh
            visit for {office} once the owner has put the finding right — nothing is approved on its
            own.
          </p>
        )}
      </div>
    </li>
  )
}

/**
 * Every visit on a filing, with the decisions wired to the API.
 *
 * This is what a screen embeds. It owns the conduct and reinspect requests and
 * the rejection dialog, so a caller only has to say "here are the inspections,
 * reload when one of them changes" — recording a result can move the filing out
 * of `for_inspection` entirely (WorkflowService::recordInspection issues the
 * permit once the last visit passes), so the parent has to re-fetch rather than
 * patch its copy.
 *
 * `onChanged` is deliberately not optional. A caller that forgets it leaves an
 * officer looking at a card that still says For Inspection after they approved
 * it, which reads as the button having done nothing — which is the exact
 * complaint this component was written to answer.
 *
 * No heading of its own: the caller places it, because the mock puts a centred
 * serif "Application Status" above the card and this component has no business
 * deciding that for every screen that embeds it.
 */
export function InspectionDecisionPanel({
  inspections,
  onChanged,
  className = '',
}: {
  inspections: Inspection[]
  onChanged: () => void
  className?: string
}) {
  const user = useAuth((s) => s.user)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [rejecting, setRejecting] = useState<Inspection | null>(null)
  const [reinspectId, setReinspectId] = useState<number | null>(null)
  const [reinspectValue, setReinspectValue] = useState('')
  const [error, setError] = useState<string | null>(null)

  /*
   * Who may record this result, mirroring InspectionController's own rule so
   * the screen does not offer a button the server will answer 403 to: an admin
   * anywhere, the inspecting department, or the named inspector personally.
   *
   * Mirroring means it can drift. It is a courtesy, not the control — the API
   * is still the thing that decides, and a mismatch shows up as an error
   * message rather than as an unauthorised write.
   */
  function canAct(item: Inspection): boolean {
    if (!user) return false
    if (user.roles.includes('admin')) return true
    if (user.department && item.department && user.department.code === item.department.code) return true
    return item.inspector?.id === user.id
  }

  /*
   * Only ever the server's own `true`.
   *
   * The third condition — whether a LATER visit has already replaced this one —
   * is nowhere in this payload, and guessing it locally is exactly what left
   * the button showing on a superseded failure that the API then refused with a
   * 422. So there is no local fallback here: anything other than a definite
   * `true` means the inline control is not offered, and the "Open the
   * inspection record" link on every card carries the officer to
   * /staff/inspections/{id}, where the flag IS computed and the same booking
   * control is offered. A failure is therefore never a dead end, and no press
   * on this screen can produce a 422.
   *
   * KNOWN API GAP — the reason that matters in practice. `can_reinspect` is
   * documented as three-valued, with null meaning "the response was not asked
   * to load the filing". The assignment payload this screen runs on DOES load
   * it, but only as `application:id,tracking_id,business_id`
   * (AssignmentController::show) — no `status` column. Inspection::
   * canBeReinspected() then reads `$this->application?->status === ForInspection`
   * against a null status and returns FALSE, which the contract reads as a
   * definite refusal rather than as "not computed". GET /inspections/{id}
   * answers `true` for the very same visit. Until `status` is added to that
   * select, this flag is never true here and the link is doing all the work.
   * Reported rather than patched: AssignmentController is another agent's file.
   */
  function canReinspect(item: Inspection): boolean {
    return item.can_reinspect === true
  }

  async function conduct(item: Inspection, result: 'passed' | 'failed', findings?: string) {
    setBusyId(item.id)
    setError(null)
    try {
      await inspectionsApi.conduct(item.id, { result, findings })
      setRejecting(null)
      onChanged()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusyId(null)
    }
  }

  /*
   * The reply is a DIFFERENT inspection — the failed one is left untouched — so
   * this reloads rather than navigating away the way the standalone inspection
   * screen does. On this screen the new visit simply appears as another card
   * beside the failure, which is where the officer already is.
   */
  async function bookReinspection(item: Inspection) {
    if (!reinspectValue) return
    setBusyId(item.id)
    setError(null)
    try {
      await inspectionsApi.reinspect(item.id, new Date(reinspectValue).toISOString())
      setReinspectId(null)
      setReinspectValue('')
      onChanged()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusyId(null)
    }
  }

  if (inspections.length === 0) {
    /*
     * The filing says For Inspection and carries no visit. That is a real
     * state — the schedule can be pending — and it is worth naming, because the
     * alternative is a heading with nothing under it and an officer wondering
     * which half of the page failed to load.
     */
    return (
      <section className={className} aria-label="Application status">
        <p className="rounded-2xl bg-white px-6 py-5 text-center text-sm text-ink-secondary shadow-card">
          This application is waiting on a site visit, but none has been scheduled yet.
        </p>
      </section>
    )
  }

  return (
    <section className={className} aria-label="Application status">
      {error && (
        <p className="mb-3 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{error}</p>
      )}

      <ul className="space-y-4">
        {inspections.map((item) => (
          <InspectionDecisionCard
            key={item.id}
            item={item}
            canAct={canAct(item)}
            busy={busyId === item.id}
            onApprove={() => conduct(item, 'passed')}
            onReject={() => {
              setError(null)
              setRejecting(item)
            }}
            reinspect={
              canAct(item) && canReinspect(item)
                ? {
                    open: reinspectId === item.id,
                    value: reinspectValue,
                    onOpen: () => {
                      setError(null)
                      setReinspectValue('')
                      setReinspectId(item.id)
                    },
                    onClose: () => setReinspectId(null),
                    onChange: setReinspectValue,
                    onBook: () => bookReinspection(item),
                  }
                : undefined
            }
          />
        ))}
      </ul>

      {rejecting && (
        <InspectionRemarksModal
          subject={`${officeOf(rejecting)} · scheduled ${formatDate(rejecting.scheduled_at)}`}
          submitting={busyId === rejecting.id}
          error={error}
          onCancel={() => setRejecting(null)}
          onProceed={(findings) => conduct(rejecting, 'failed', findings || undefined)}
        />
      )}
    </section>
  )
}

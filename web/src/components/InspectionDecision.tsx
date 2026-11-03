import { useEffect, useId, useState } from 'react'
import { CalendarIcon, CheckCircleFilledIcon, XCircleIcon, XIcon } from './icons'
import { InspectorNameField } from './InspectorNameField'
import { ProtoModal } from './ui/Proto'
import { toApiError } from '../lib/api'
import { formatCalendarDate, formatDate } from '../lib/format'
import { inspections as inspectionsApi } from '../lib/resources'
import { useAuth } from '../stores/auth'
import type { Inspection, InspectorSuggestion } from '../lib/types'

/*
 * The site visit, decided from wherever the officer opened the filing.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * An office that has finished its paperwork review sees `completed_at` set on
 * its assignment, so ReviewPage concluded the review was `decided`, printed a
 * static green "Approved" in the header, and offered no controls at all — while
 * the thing actually outstanding, the inspection, had nowhere on that page to
 * be approved or rejected. The officer's own words: "there's no thing to
 * approve something that's for inspection".
 *
 * NOTE, because an earlier version of this comment said otherwise and it cost a
 * deadlock: a filing in `for_inspection` has NOT, by definition, finished every
 * paperwork review. Since commit 5da4daa, afterReviewProgress books a visit and
 * flips the filing on the FIRST office's approval, so `for_inspection` and
 * "five offices have not started" are a normal pair. ReviewPage therefore shows
 * this panel only to an office whose OWN review is done; see the branch there
 * (INS-1). This component is handed every visit on the filing regardless, which
 * is why each card has to say whose it is.
 *
 * The visit used to have a second home at /staff/inspections/{id}, but nothing
 * on the queue linked there, and an officer who clicks a For Inspection row in
 * Manage Applications lands on the review sheet, not there.
 *
 * ── The screen this replaced ────────────────────────────────────────────────
 *
 * There WERE two screens doing this job. `pages/officer/InspectionsPage.tsx`
 * held a register-wide list at /staff/inspections and a per-visit detail page
 * under it, both from private copies of this markup, and the client called the
 * duplication out: "The Track page -> For Inspection is redundant with the
 * Inspections page. Remove the Inspections page. All inspections will happen in
 * The Track page -> For Inspection". That file is deleted; this component is
 * now the only place a site visit is decided, so everything the old detail page
 * could do had to arrive here first:
 *
 *  - Approve, and Reject through the REMARKS FOR REJECTION dialog — already
 *    here, and in a better shape (see `InspectionRemarksModal` on why Proceed
 *    stays reachable rather than `disabled`).
 *  - Findings, the scheduled/finished date and the inspector's name — on the
 *    card below. The name is typed by the office for the record since
 *    5 October 2026 (see InspectorNameField).
 *  - "Schedule re-inspection", the way out of a failed visit — here, and the
 *    gate that kept it invisible on this payload is fixed in `canReinspect`.
 *  - "Reschedule this inspection", moving a visit that has not happened yet —
 *    MOVED here as the `reschedule` prop. It existed nowhere else, so deleting
 *    that page without it would have taken a working control off the product.
 *
 * One thing was deliberately NOT brought across: the old page's "Application
 * Details" card, the filing's particulars restated under the decision. The
 * client asked for that to go in the same breath as the box itself — "In
 * reviewing the inspections (admin side), I can still see the application
 * details. Please remove this." — so reinstating it on the screen that survived
 * would be undoing a fix, not preserving a feature. The officer still reaches
 * every one of those fields from the filing itself.
 *
 * The old register-wide LIST is not replaced either, on purpose. Track's For
 * Inspection tab is the list now; that is what the client asked for.
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

/**
 * The one date-and-time control every booking on this screen uses.
 *
 * Three acts pick a date here — opening an office's first visit, moving one that
 * has not happened yet, and booking a fresh one after a failure — and they were
 * on their way to three hand-written `<input type="datetime-local">`s with three
 * copies of the same class list. They are still three separate acts with three
 * separate handlers, which is the point of keeping the callers apart (see the
 * `reschedule` prop's note on why sharing a HANDLER between them is how the
 * failed visit gets overwritten by accident). Sharing the FIELD costs nothing
 * and keeps the focus ring, the border and the padding from drifting apart.
 *
 * `label` is required rather than defaulted because it is the accessible name
 * and it has to say WHICH visit: a filing carries a visit per inspecting office,
 * so three fields called "Date and time" are three identical stops for anyone
 * moving through the page by form control.
 */
/**
 * Today at 00:00 on the officer's own clock, in the shape `datetime-local`
 * wants for `min`. The API refuses a past visit (5 October 2026: one was
 * booked in the past); this stops the picker offering one at all.
 */
function localTodayMin(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T00:00`
}

function InspectionDateInput({
  label,
  value,
  onChange,
}: {
  label: string
  value: string
  onChange: (value: string) => void
}) {
  return (
    <input
      type="datetime-local"
      min={localTodayMin()}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      aria-label={label}
      className="rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
    />
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

/**
 * ── The OBO confirms the certificate's names before the release ────────────
 *
 * [Client, 3 November 2026: "ilagay sa after inspection … bago irelease the
 * admin has to confirm the names".] Passing the OBO's visit issues the
 * Certificate of Occupancy, so the Approve on it asks first who signs: the
 * Building Official, and an evaluator for each line this building needs —
 * the lines it marks N/A are named apart, not asked. Each name starts as the
 * office's own (Permits → Certificate names); a change here prints on this
 * permit only and is written to the audit log. Every name must be there:
 * the button stays reachable and says what is missing (aria-describedby).
 */
function CertificateNamesConfirm({
  item,
  submitting,
  error,
  onCancel,
  onConfirm,
}: {
  item: Inspection
  submitting: boolean
  error: string | null
  onCancel: () => void
  onConfirm: (signatories: { role: string; name: string }[]) => void
}) {
  const [rows, setRows] = useState<{ role: string; name: string; was: string }[] | null>(null)
  const [notNeeded, setNotNeeded] = useState<string[]>([])
  const [loadError, setLoadError] = useState<string | null>(null)
  const hintId = useId()

  useEffect(() => {
    let live = true
    inspectionsApi
      .certificateNames(item.id)
      .then((data) => {
        if (!live) return
        setRows(data.rows.map((r) => ({ role: r.role, name: r.name ?? '', was: r.name ?? '' })))
        setNotNeeded(data.not_needed)
      })
      .catch((err) => live && setLoadError(toApiError(err).message))
    return () => {
      live = false
    }
  }, [item.id])

  const missing = (rows ?? []).filter((r) => r.name.trim() === '')
  const changed = (rows ?? []).filter((r) => r.was !== '' && r.name.trim() !== '' && r.name.trim() !== r.was)

  return (
    <ProtoModal
      title="Confirm the certificate names"
      tone="green"
      wide
      onCancel={onCancel}
      confirmLabel={submitting ? 'Approving…' : 'Confirm names and approve'}
      confirmDisabled={submitting || rows === null}
      confirmDescribedBy={missing.length > 0 ? hintId : undefined}
      onConfirm={() =>
        rows !== null &&
        missing.length === 0 &&
        onConfirm(rows.map((r) => ({ role: r.role, name: r.name.trim() })))
      }
    >
      <p className="text-sm text-ink-secondary">
        The inspection passes and the Certificate of Occupancy is issued with these names. Check each one. A change here
        prints on this permit only; your office&rsquo;s names stay as they are.
      </p>

      {loadError ? (
        <p role="alert" className="mt-4 text-sm font-medium text-s-red">
          {loadError}
        </p>
      ) : rows === null ? (
        <p className="mt-4 text-sm text-ink-muted">Loading the names…</p>
      ) : (
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          {rows.map((r, i) => (
            <label key={r.role} className={`block ${i === 0 ? 'sm:col-span-2' : ''}`}>
              <span className="text-xs font-semibold text-ink-secondary">
                {r.role.replace(/^Evaluator — /, 'Evaluator · ')}
                <span className="text-s-red"> *</span>
              </span>
              <input
                value={r.name}
                onChange={(e) =>
                  setRows((prev) => prev && prev.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))
                }
                maxLength={120}
                aria-invalid={r.name.trim() === '' || undefined}
                className={`mt-1 w-full rounded-lg border px-3 py-2 text-sm text-ink outline-none focus:border-royal ${
                  r.name.trim() === '' ? 'border-s-red' : 'border-line'
                }`}
              />
              {r.was !== '' && r.name.trim() !== '' && r.name.trim() !== r.was && (
                <span className="mt-0.5 block text-xs text-amber-800">For this permit only. Office name: {r.was}</span>
              )}
            </label>
          ))}
        </div>
      )}

      {notNeeded.length > 0 && (
        <p className="mt-4 text-xs text-ink-muted">
          Marked N/A for this building, so no evaluator signs: {notNeeded.join(', ')}.
        </p>
      )}
      {changed.length > 0 && (
        <p className="mt-2 text-xs text-ink-secondary">
          {changed.length} name{changed.length === 1 ? '' : 's'} changed for this permit. The change is recorded in the
          audit log.
        </p>
      )}
      {missing.length > 0 && (
        <p id={hintId} aria-live="polite" className="mt-3 text-sm font-medium text-s-red">
          Fill in {missing.length === 1 ? 'the name' : `all ${missing.length} names`} to approve. A certificate is not
          issued with a blank signatory.
        </p>
      )}
      {error && <p className="mt-3 text-sm font-medium text-s-red">{error}</p>}
    </ProtoModal>
  )
}

/** Has this visit happened? Mirrors the inspection detail screen's own test. */
export function inspectionDone(item: Inspection): boolean {
  return Boolean(item.conducted_at) || ['completed', 'passed', 'failed'].includes(item.status.toLowerCase())
}

/** The office that owns a visit, for headings and accessible names. */
function officeOf(item: Inspection): string {
  return item.department?.name ?? 'Inspecting office'
}

/*
 * What one visit's result is called on the card (INS-4).
 *
 * `passed` read simply "Approved" and `failed` simply "Rejected", in 24px over
 * a full-width green or red bar, on a panel that is handed EVERY office's
 * visits on the filing. A sanitary officer opening a filing where Fire had
 * passed its visit read a green bar saying "Approved" and reported that
 * "ABCD Trading's For inspection for other offices too got approved as well".
 * Nothing of the kind had happened — WorkflowService::recordInspection writes
 * exactly one row, and the API would have 403'd a cross-department conduct —
 * but the word on the screen said otherwise.
 *
 * "Approved" and "Rejected" are what the FILING gets called elsewhere in this
 * product (ApplicationStatus, the review sheet's own header), which is exactly
 * why they cannot also be what a single office's site visit is called. The
 * wording now matches the API's own vocabulary for the field it is rendering,
 * `inspections.result`: passed / failed / conditional.
 *
 * `pending` keeps "For Inspection" — it is the mock's word (updated-gui/82.png)
 * and it is unambiguous, because no filing-level state is called that from an
 * officer's seat while a visit is outstanding.
 */
const STATE = {
  pending: { bar: 'bg-s-yellow', label: 'For Inspection' },
  passed: { bar: 'bg-s-green', label: 'Inspection passed' },
  failed: { bar: 'bg-s-red', label: 'Inspection failed' },
  conditional: { bar: 'bg-s-orange', label: 'Inspection conditional' },
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
  mine,
  canAct,
  busy,
  onApprove,
  onReject,
  reinspect,
  reschedule,
}: {
  item: Inspection
  /**
   * Is this the READER'S OWN office's visit? (INS-4)
   *
   * Deliberately separate from `canAct`, which is a wider question — admin
   * anywhere, the inspecting department, or the personally named inspector.
   * This one is only ever "does the department badge on this card say me", and
   * it is used for reading, never for gating: every control below is still
   * drawn behind `canAct`.
   *
   * Two of them would drift into each other if this were folded in. An admin
   * has `canAct` on every card and owns none of them, and an inspector moved
   * between departments keeps `canAct` on their old office's open visits — in
   * both cases "you may record this" is true and "this is your office's visit"
   * is false, and the card has to say the second thing.
   */
  mine: boolean
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
    /** The pool inspector for the new visit, suggested for the day picked. */
    inspectorId: number | null
    onInspectorIdChange: (id: number | null) => void
    typed: string
    onTypedChange: (name: string) => void
  }
  /**
   * Moving a visit that has not happened yet. Absent when it is not on offer —
   * a conducted visit cannot be moved, and another office's cannot be touched.
   *
   * Deliberately a separate prop from `reinspect` even though the two render
   * near-identical date pickers, because they are opposite acts on the record:
   * this OVERWRITES `scheduled_at` on a visit with nothing yet to lose, and
   * that one adds a second row and leaves the failure standing. Sharing one
   * control between them is how "reschedule the failed visit" gets written by
   * accident, which erases the exact fact the client asked to keep.
   */
  reschedule?: {
    open: boolean
    value: string
    onOpen: () => void
    onClose: () => void
    onChange: (value: string) => void
    onSave: () => void
    /** Who takes the moved visit: a pool inspector, or null to keep whoever holds it. */
    inspectorId: number | null
    onInspectorIdChange: (id: number | null) => void
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
  const rescheduleLabel = `Reschedule the ${office} inspection`

  return (
    <li className="overflow-hidden rounded-2xl bg-white shadow-card" aria-label={`${office} inspection`}>
      <div className={`h-2.5 ${state.bar}`} />
      <div className="px-6 py-5">
        {/*
         * Whose visit this is, said before the result rather than in 11px above
         * it (INS-4). The office name was already here, but as an eyebrow over
         * a 24px verdict, so the verdict is what the eye took and the
         * attribution is what it skipped — and on a panel carrying every
         * office's visits that is how one office's passed inspection came to be
         * read as the whole filing being approved.
         */}
        <div className="flex flex-wrap items-center gap-2">
          <p className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">{office}</p>
          <span
            className={`rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${
              mine ? 'bg-royal-tint text-royal' : 'bg-canvas text-ink-secondary'
            }`}
          >
            {mine ? 'Your office' : 'Another office'}
          </span>
        </div>

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

        {/*
         * Who inspected, typed for the record (client, 5 October 2026). Only
         * where the server sent it — the visit's own office, BPLO and the
         * super admin; the officer in charge still decides the visit. Falls
         * back to the account an older visit was booked to.
         */}
        {(mine || item.inspector_name || item.inspector) && (
          <div className="mt-2">
            <InspectorNameField
              inspectionId={item.id}
              name={item.inspector_name ?? item.inspector?.name ?? null}
              canEdit={item.can_name_inspector === true}
              context={`${office} inspection`}
            />
          </div>
        )}

        {/*
         * What a finished visit does and does not settle.
         *
         * The outstanding case already says whose visit it is, further down,
         * because it has to explain a missing button. A CONDUCTED one said
         * nothing at all — it was a coloured bar and a date — so this is the
         * half that was silent when the client read it as everyone's approval.
         */}
        {done && (
          <p className="mt-1.5 text-sm text-ink-secondary">
            {mine
              ? 'Your office recorded this result. It closes your visit; the filing is decided once every office’s clearance is in.'
              : `${office} recorded this result on its own visit. It is not a decision on the filing, and it is not your office’s clearance.`}
          </p>
        )}

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
                  <InspectionDateInput
                    label={`Re-inspection date and time for the ${office} visit`}
                    value={reinspect.value}
                    onChange={reinspect.onChange}
                  />
                  <div className="-mt-2 basis-full">
                    <InspectorPicker
                      source={{ kind: 'visit', inspectionId: item.id, moving: false }}
                      day={dayOf(reinspect.value)}
                      inspectorId={reinspect.inspectorId}
                      onInspectorIdChange={reinspect.onInspectorIdChange}
                      typed={reinspect.typed}
                      onTypedChange={reinspect.onTypedChange}
                      onPickDay={(ymd) => reinspect.onChange(nineOn(ymd))}
                    />
                  </div>
                  <button
                    type="button"
                    aria-label={`Book the new ${office} visit`}
                    aria-disabled={busy || !reinspect.value}
                    onClick={() => busy || !reinspect.value || reinspect.onBook()}
                    className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover aria-disabled:opacity-60"
                  >
                    Book this visit
                  </button>
                  {/*
                    Named, like every other repeated control on this card. Two
                    bare "Never mind"s can now be on one filing — this one, and
                    the reschedule picker's below — and a screen-reader user
                    moving by button would meet them as the same word twice.
                  */}
                  <button
                    type="button"
                    aria-label={`Cancel booking the new ${office} visit`}
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
          ) : /*
           * A conducted visit with nothing left to do on it.
           *
           * This used to be a link reading "Open the inspection record",
           * pointing at /staff/inspections/{id} — the escape hatch for a
           * failure whose re-inspection flag this payload could not work out.
           * That page is gone (see the note at the top of this file), and the
           * flag is worked out here now, so the hatch has nothing left to
           * escape to and nothing left to escape from. The card already shows
           * every field that page did: the result, the date, the findings and
           * the inspector.
           *
           * The inspector's name chip that sat to the right of this row
           * went on 5 October 2026: the name is the typed field under the
           * date now, and saying it twice on one card is the restatement
           * the client keeps asking to cut.
           */
          null}
        </div>

        {reinspect && !reinspect.open && (
          /*
           * What the button will actually do, said before it is pressed.
           * "Re-inspection" is a word an officer could reasonably read as "undo
           * the failure" or "let it through this time", and it is neither.
           */
          <p className="mt-3 max-w-xl text-sm text-ink-secondary">
            This visit stays on the record as a failure. Scheduling a re-inspection books a fresh visit for {office}{' '}
            once the owner has put the finding right — nothing is approved on its own.
          </p>
        )}

        {reschedule && (
          /*
           * Moving the appointment, which is not a decision about it.
           *
           * Kept visually quieter than Approve and Reject — a text link until
           * it is opened — because it is the secondary act on an outstanding
           * visit and the mock gives the two decision buttons the weight. It
           * sat in exactly this relationship on the page this replaced.
           */
          <div className="mt-4">
            {reschedule.open ? (
              <div className="flex flex-wrap items-center gap-2.5">
                <InspectionDateInput
                  label={`New date and time for the ${office} inspection`}
                  value={reschedule.value}
                  onChange={reschedule.onChange}
                />
                <div className="-mt-2 basis-full">
                  <InspectorPicker
                    source={{ kind: 'visit', inspectionId: item.id, moving: true }}
                    day={dayOf(reschedule.value)}
                    inspectorId={reschedule.inspectorId}
                    onInspectorIdChange={reschedule.onInspectorIdChange}
                    onPickDay={(ymd) => reschedule.onChange(nineOn(ymd))}
                    otherLabel={`Keep ${item.inspector_name ? item.inspector_name : 'as is'}`}
                  />
                </div>
                <button
                  type="button"
                  aria-label={`Save the new ${office} inspection date`}
                  aria-disabled={busy || !reschedule.value}
                  onClick={() => busy || !reschedule.value || reschedule.onSave()}
                  className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:opacity-60"
                >
                  Save new date
                </button>
                <button
                  type="button"
                  aria-label={`Leave the ${office} inspection where it is`}
                  onClick={reschedule.onClose}
                  className="text-sm font-semibold text-ink-muted underline underline-offset-2"
                >
                  Never mind
                </button>
              </div>
            ) : (
              <button
                type="button"
                aria-label={rescheduleLabel}
                onClick={reschedule.onOpen}
                className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline"
              >
                Reschedule this inspection
              </button>
            )}
          </div>
        )}
      </div>
    </li>
  )
}

/**
 * The permit an office has approved on paper and not yet sent anyone to look at.
 *
 * ── Why there is a card here at all ─────────────────────────────────────────
 *
 * Approving a clearance's paperwork moves that permit to `for_inspection` and
 * books NOTHING (WorkflowService::approveClearance). The scheduler that used to
 * pick a date two working days out is gone on purpose — "an automatic date is a
 * promise made to the applicant by a scheduler that does not know whether anyone
 * is free" — and the client's procedure is two steps, "Select Inspection Date
 * and Approve Inspection". The second step had no screen: the API route existed,
 * no client called it, and a permit that reached `for_inspection` stayed there,
 * which held the whole filing out of For Final Approval.
 *
 * ── Not a visit, so not an InspectionDecisionCard ───────────────────────────
 *
 * Every prop on that card reads off an `Inspection` row, and the entire point of
 * this state is that no such row exists. What is shared is the date field, which
 * is `InspectionDateInput` above.
 *
 * `permit` is named on the card and in every accessible name. A filing carries a
 * clearance per office and this panel already renders their visits side by side,
 * so a bare "Book this visit" would be one more identical stop — the same defect
 * the Approve and Reject glyphs on the card above were fixed for.
 *
 * ── The date field is open on arrival, unlike reschedule and reinspect ──────
 *
 * Those two hide behind a link because they are the SECOND thing to do to a
 * visit that already exists, and the decision on it is meant to carry the
 * weight. Here there is nothing else on the card: an office that has just
 * accepted the paperwork has exactly one act left, and putting it behind a
 * disclosure would be a click whose only content is "yes, the thing this card is
 * about". `flow-lifecycle.spec.ts` asserts the booking control is reachable
 * without one, and that is the right shape.
 *
 * Presentational, like InspectionDecisionCard: the caller owns the request, so
 * the same markup can be driven by a test that never reaches the network.
 */
/*
 * ── Who should go, for the date picked [client, 27 October 2026] ───────────
 *
 * Once the officer picks a date the office's pool is asked who covers the
 * business's barangay, works that day, is not away and still has room; the
 * answer is filled in with its reason, and the rest of the pool is one press
 * away in the same control. A typed name stays possible ("Someone else") —
 * the pool is a convenience, not a gate. When nobody is free, the next day
 * somebody is appears as a button that moves the date.
 *
 * One control for the three places a visit gets a date: the first booking
 * (keyed by filing and permit), a re-inspection and a move (keyed by the
 * visit). A move leaves the visit's own slot out of the count, or the
 * inspector holding it would look one visit busier than they are.
 */
type PickerSource =
  { kind: 'first'; applicationId: number; code: string } | { kind: 'visit'; inspectionId: number; moving: boolean }

function InspectorPicker({
  source,
  day,
  inspectorId,
  onInspectorIdChange,
  typed,
  onTypedChange,
  onPickDay,
  otherLabel = 'Someone else (type a name)',
}: {
  source: PickerSource
  /** YYYY-MM-DD of the date picked, or '' before one is. */
  day: string
  inspectorId: number | null
  onInspectorIdChange: (id: number | null) => void
  /** The typed name, where one is offered; absent on a move (the card's own field edits it). */
  typed?: string
  onTypedChange?: (name: string) => void
  /** Pressed on "Next free day": the caller moves its date to this day. */
  onPickDay: (ymd: string) => void
  otherLabel?: string
}) {
  const pickId = useId()
  const nameId = useId()
  const listId = useId()
  const [names, setNames] = useState<string[]>([])
  const [suggestion, setSuggestion] = useState<InspectorSuggestion | null>(null)
  const [suggesting, setSuggesting] = useState(false)
  const sourceKey =
    source.kind === 'first'
      ? `first:${source.applicationId}:${source.code}`
      : `visit:${source.inspectionId}:${source.moving}`

  // The office's past inspectors, as InspectorNameField offers them.
  useEffect(() => {
    if (onTypedChange === undefined) return
    let live = true
    inspectionsApi
      .inspectorNames()
      .then((list) => live && setNames(list))
      .catch(() => {
        // An autocomplete that fails is a plain text box, not an error.
      })
    return () => {
      live = false
    }
  }, [onTypedChange])

  useEffect(() => {
    if (!day) {
      setSuggestion(null)
      return
    }
    let live = true
    setSuggesting(true)
    const ask =
      source.kind === 'first'
        ? inspectionsApi.suggest(source.applicationId, source.code, day)
        : inspectionsApi.suggestFor(source.inspectionId, day, source.moving)
    ask
      .then((next) => {
        if (!live) return
        setSuggestion(next)
        // Follow the suggestion unless the officer has already chosen by hand.
        const handPicked = inspectorId !== null && next.candidates.some((c) => c.id === inspectorId && c.available)
        const typing = inspectorId === null && (typed ?? '').trim() !== ''
        if (next.suggested && !handPicked && !typing) onInspectorIdChange(next.suggested.id)
      })
      .catch(() => {
        if (live) setSuggestion(null)
      })
      .finally(() => live && setSuggesting(false))
    return () => {
      live = false
    }
    // Re-asked on the day, not the minute: the pool's day is what changes who is free.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [day, sourceKey])

  /*
   * ── Before a date is picked: the earliest day somebody can go ────────────
   *
   * [Client, 28 October 2026: "make sure yung automation sa mga bagong
   * applications".] The card used to wait for a date before it could say who
   * was free. It now asks the pool straight away for the next working day,
   * and offers that day with its inspector as one press — the officer still
   * presses "Book this visit", so nothing is promised to the applicant until
   * somebody at the office has looked at it.
   */
  const [quick, setQuick] = useState<{ date: string; name: string; id: number; why: string } | null>(null)
  useEffect(() => {
    if (day) return
    let live = true
    const first = nextWorkingDay()
    const ask =
      source.kind === 'first'
        ? inspectionsApi.suggest(source.applicationId, source.code, first)
        : inspectionsApi.suggestFor(source.inspectionId, first, source.moving)
    ask
      .then((next) => {
        if (!live) return
        if (next.suggested)
          setQuick({ date: first, name: next.suggested.name, id: next.suggested.id, why: next.suggested.why })
        else if (next.next_available)
          setQuick({
            date: next.next_available.date,
            name: next.next_available.inspector.name,
            id: next.next_available.inspector.id,
            why: next.next_available.inspector.why,
          })
        else setQuick(null)
      })
      .catch(() => live && setQuick(null))
    return () => {
      live = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [day, sourceKey])

  const chosen = suggestion?.candidates.find((c) => c.id === inspectorId) ?? null
  const hasPool = (suggestion?.pool_size ?? 0) > 0
  const typedField =
    onTypedChange !== undefined ? (
      <>
        <label htmlFor={nameId} className="mt-3 block text-sm text-ink-muted">
          Inspector (for the record)
        </label>
        <input
          id={nameId}
          type="text"
          value={typed ?? ''}
          maxLength={120}
          list={listId}
          placeholder="Full name"
          onChange={(e) => onTypedChange(e.target.value)}
          className="mt-1 w-64 max-w-full rounded-lg border border-input-border bg-input px-2.5 py-1.5 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
        />
        <datalist id={listId}>
          {names.map((n) => (
            <option key={n} value={n} />
          ))}
        </datalist>
      </>
    ) : null

  if (!day && quick) {
    return (
      <div className="mt-3 rounded-lg border border-royal/30 bg-royal-tint/40 px-3.5 py-2.5">
        <p className="text-xs font-semibold uppercase tracking-wide text-royal">Suggested</p>
        <p className="mt-0.5 text-sm text-ink">
          <b>{formatCalendarDate(quick.date)}, 9:00 AM</b> — {quick.name}
        </p>
        <p className="text-xs text-ink-secondary">{quick.why}</p>
        <button
          type="button"
          onClick={() => {
            onInspectorIdChange(quick.id)
            onPickDay(quick.date)
          }}
          className="mt-2 rounded-full bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
        >
          Use this date and inspector
        </button>
        <span className="ml-2 text-xs text-ink-muted">or pick a date above</span>
      </div>
    )
  }

  if (!day || !hasPool) return typedField

  return (
    <div className="mt-3">
      <label htmlFor={pickId} className="block text-sm text-ink-muted">
        Inspector
        {suggestion?.barangay && <span> · business is in {suggestion.barangay.name}</span>}
      </label>
      <select
        id={pickId}
        value={inspectorId ?? ''}
        onChange={(e) => onInspectorIdChange(e.target.value ? Number(e.target.value) : null)}
        className="mt-1 w-full max-w-md rounded-lg border border-input-border bg-input px-2.5 py-1.5 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
      >
        {suggestion?.candidates.map((c) => (
          <option key={c.id} value={c.id} disabled={!c.available}>
            {c.name}
            {suggestion.suggested?.id === c.id ? ' — suggested' : ''}
            {c.available ? '' : ' — not available'}
          </option>
        ))}
        <option value="">{otherLabel}</option>
      </select>
      {suggesting ? (
        <p className="mt-1 text-xs text-ink-muted">Checking who is free…</p>
      ) : chosen ? (
        <p className="mt-1 text-xs text-ink-secondary">
          {chosen.why}
          {chosen.barangays.length > 0 && (
            <span className="text-ink-muted"> · covers {chosen.barangays.join(', ')}</span>
          )}
        </p>
      ) : suggestion && !suggestion.suggested ? (
        <p className="mt-1 text-xs text-s-red">
          Nobody in your pool is free on {formatCalendarDate(day)}.
          {suggestion.next_available && (
            <>
              {' '}
              <button
                type="button"
                onClick={() => onPickDay(suggestion.next_available!.date)}
                className="font-semibold text-royal underline-offset-2 hover:underline"
              >
                Next free day: {formatCalendarDate(suggestion.next_available.date)} —{' '}
                {suggestion.next_available.inspector.name}
              </button>
            </>
          )}
        </p>
      ) : null}
      {inspectorId === null && typedField}
    </div>
  )
}

/** Tomorrow, or Monday when tomorrow is a weekend, as YYYY-MM-DD in local time. */
function nextWorkingDay(): string {
  const d = new Date()
  do {
    d.setDate(d.getDate() + 1)
  } while (d.getDay() === 0 || d.getDay() === 6)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** The YYYY-MM-DD of a datetime-local value, or '' before one is picked. */
function dayOf(value: string): string {
  return value ? value.slice(0, 10) : ''
}

/** A datetime-local value for 9:00 AM on that day — where "Next free day" lands. */
function nineOn(ymd: string): string {
  return `${ymd}T09:00`
}

function FirstInspectionCard({
  permit,
  value,
  busy,
  hintId,
  onChange,
  inspector,
  onInspectorChange,
  inspectorId,
  onInspectorIdChange,
  applicationId,
  code,
  onBook,
}: {
  permit: string
  value: string
  busy: boolean
  /** Ties the "pick a date first" sentence to the button it explains. */
  hintId: string
  onChange: (value: string) => void
  /** The inspector's name, typed with the booking — optional, see below. */
  inspector: string
  onInspectorChange: (value: string) => void
  /** The pool inspector picked, or null when a name is typed instead. */
  inspectorId: number | null
  onInspectorIdChange: (id: number | null) => void
  applicationId: number
  code: string
  onBook: () => void
}) {
  return (
    <li className="overflow-hidden rounded-2xl bg-white shadow-card" aria-label={`${permit} inspection booking`}>
      {/*
        Royal, not the yellow the outstanding visits wear. This is the office's
        own next action rather than a state the premises are in, and the bar is
        never the only thing saying so — the line below says it in words.
      */}
      <div className="h-2.5 bg-royal" />
      <div className="px-6 py-5">
        <p className="text-[11px] font-bold uppercase tracking-wide text-ink-muted">{permit}</p>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <CalendarIcon size={30} className="text-royal" />
          <span className="text-2xl font-medium text-ink">No inspection booked</span>
        </div>
        <p className="mt-1.5 max-w-xl text-sm text-ink-secondary">
          Your office has accepted the paperwork for this permit. Say when an inspector will visit the premises — the
          permit cannot be issued until a visit passes.
        </p>

        <div className="mt-4 flex flex-wrap items-end gap-2.5">
          <InspectionDateInput label={`Date and time of the ${permit} inspection`} value={value} onChange={onChange} />
        </div>

        {/*
          Who goes: suggested from the office's pool for the day picked, or a
          name typed for the record (client, 6 October 2026). Optional either
          way — the officer in charge still decides the visit, and the name
          stays editable on the card afterwards (InspectorNameField).
        */}
        <InspectorPicker
          source={{ kind: 'first', applicationId, code }}
          day={dayOf(value)}
          inspectorId={inspectorId}
          onInspectorIdChange={onInspectorIdChange}
          typed={inspector}
          onTypedChange={onInspectorChange}
          onPickDay={(ymd) => onChange(nineOn(ymd))}
        />

        <div className="mt-4 flex flex-wrap items-center gap-2.5">
          {/*
            `aria-disabled`, never `disabled`. The button is shut only while
            there is no date or a request is already on its way, and both are
            reasons worth reading — a disabled control leaves the tab order and
            takes the sentence explaining it along. The handler carries the same
            guard, so a press that lands anyway does nothing rather than sending
            an empty booking.
          */}
          <button
            type="button"
            aria-label={`Book the ${permit} visit`}
            aria-disabled={busy || !value}
            aria-describedby={value ? undefined : hintId}
            onClick={() => busy || !value || onBook()}
            className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover aria-disabled:opacity-60"
          >
            {busy ? 'Booking…' : 'Book this visit'}
          </button>
        </div>
        {!value && (
          <p id={hintId} className="mt-2 text-sm text-ink-muted">
            Choose a date and time to book the visit.
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
  filingStatus,
  onChanged,
  book,
  className = '',
}: {
  inspections: Inspection[]
  /**
   * The FILING's status, e.g. `for_inspection`.
   *
   * Required, not optional, and it is not decoration: it is one of the three
   * conditions on whether a fresh visit may be booked (see `canReinspect`), and
   * a caller who omitted it would silently get a panel that never offers the
   * way out of a failed visit — which is the precise bug this panel was last
   * changed to fix. Better to make every caller say it.
   */
  filingStatus: string
  onChanged: () => void
  /**
   * The reader's OWN clearance, awaiting its first visit — absent when there is
   * nothing here for this office to book.
   *
   * The caller decides, not this panel, and that is deliberate. The rule the
   * server applies is `permit_types.issuing_department_id === the caller's
   * department` (InspectionController::schedule, 403 otherwise), and the screen
   * that knows which permit THIS office issues on THIS filing is the one holding
   * the assignment payload — `AssignmentResource::clearanceRow()` answers
   * exactly that question, matched on exactly that column. Recomputing it here
   * would mean a second, weaker copy: this panel is handed every office's
   * visits and has no way to tell which permit belongs to whom.
   *
   * `applicationId` and `code` are what the endpoint is keyed on; `permit` is
   * what the card and its accessible names say.
   */
  book?: { applicationId: number; code: string; permit: string }
  className?: string
}) {
  const user = useAuth((s) => s.user)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [rejecting, setRejecting] = useState<Inspection | null>(null)
  /** The visit whose Approve is waiting on "Yes, approve". */
  const [approving, setApproving] = useState<Inspection | null>(null)
  const [reinspectId, setReinspectId] = useState<number | null>(null)
  const [reinspectValue, setReinspectValue] = useState('')
  const [reinspectInspectorId, setReinspectInspectorId] = useState<number | null>(null)
  const [reinspectTyped, setReinspectTyped] = useState('')
  const [reschedId, setReschedId] = useState<number | null>(null)
  const [reschedValue, setReschedValue] = useState('')
  const [reschedInspectorId, setReschedInspectorId] = useState<number | null>(null)
  const [bookValue, setBookValue] = useState('')
  const [bookInspector, setBookInspector] = useState('')
  /** The pool inspector chosen for the first visit, or null for a typed name. */
  const [bookInspectorId, setBookInspectorId] = useState<number | null>(null)
  const [booking, setBooking] = useState(false)
  const [bookNote, setBookNote] = useState<string | null>(null)
  /** The visit this panel has just opened, before the caller has re-fetched. */
  const [booked, setBooked] = useState<Inspection | null>(null)
  const bookHintId = useId()
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
   * Is this visit the reader's own office's? (INS-4)
   *
   * A narrower question than `canAct` and asked for a different purpose: this
   * one only decides what the card SAYS, never what it offers. No admin
   * exemption and no named-inspector disjunct, because neither makes a visit
   * belong to the reader's office — an admin owns none of them, and an officer
   * moved between departments is still not the office that booked the visit.
   *
   * Both codes must be present, never two absences: `undefined === undefined`
   * would badge an unrouted visit "Your office", which is the exact misreading
   * this is here to stop.
   */
  function isMine(item: Inspection): boolean {
    const mineCode = user?.department?.code
    const theirs = item.department?.code
    return Boolean(mineCode && theirs && mineCode === theirs)
  }

  /**
   * Is this visit still this office's CURRENT one, or has a later one replaced
   * it?
   *
   * Mirrors Inspection::scopeCurrentPerDepartment exactly — "no row exists with
   * the same application, the same department, and a higher id". The API has to
   * ask that as a query because a single-visit payload cannot see its siblings.
   * This panel is handed every visit on the filing, so here it is a scan of an
   * array of at most six.
   *
   * A visit whose department did not come down the wire answers false. That is
   * a refusal on unknown data, which is normally the wrong call — but the whole
   * question is "which of this OFFICE'S visits is the latest", and it cannot be
   * asked at all without knowing the office. Answering true would offer to book
   * a second visit off a superseded failure, which is the 422 this is here to
   * avoid.
   */
  function isCurrentForDepartment(item: Inspection): boolean {
    const code = item.department?.code
    if (!code) return false
    // `visits`, not the prop: a visit this panel has just booked supersedes the
    // office's older ones exactly as one arriving down the wire would.
    return !visits.some((other) => other.department?.code === code && other.id > item.id)
  }

  /*
   * May a fresh visit be booked off the back of this one?
   *
   * The server's own `true` short-circuits. Everything else falls through to
   * the same three conditions the server checks (Inspection::canBeReinspected),
   * asked of what this screen can see — and on this screen it can see all
   * three:
   *
   *  - the visit FAILED: on the card's own payload.
   *  - the FILING is still for inspection: the caller passes it in, from the
   *    full ApplicationResource this screen is already built on.
   *  - this is the office's CURRENT visit: `isCurrentForDepartment` above,
   *    because the whole filing's visits are in hand here.
   *
   * ── Why there is a fallback at all ──────────────────────────────────────
   *
   * `can_reinspect` is documented as three-valued and null means "not
   * computed", never "no", so a fallback is required by the contract. But the
   * case that bites in practice is a `false` that is just as uninformed:
   * AssignmentController::show loads the nested filing as
   * `application:id,tracking_id,business_id` — no `status` column — so
   * canBeReinspected() compares a null status against ForInspection and
   * answers FALSE for a visit that GET /inspections/{id} calls re-inspectable.
   * Reading that as a refusal is what left "Schedule re-inspection" invisible
   * on this screen, and the officer's only route to it was a link to a page
   * that no longer exists. So the fallback runs on any answer that is not a
   * definite `true`.
   *
   * That is safe rather than a guess, and the difference matters: when the
   * server's `false` IS informed, this test evaluates the same three conditions
   * against the same facts and answers false too. It can only diverge where the
   * server was working from a filing it had not loaded — exactly the case the
   * contract says to treat as unknown.
   */
  function canReinspect(item: Inspection): boolean {
    if (item.can_reinspect === true) return true
    return (
      inspectionDone(item) &&
      item.result === 'failed' &&
      filingStatus === 'for_inspection' &&
      isCurrentForDepartment(item)
    )
  }

  async function conduct(
    item: Inspection,
    result: 'passed' | 'failed',
    findings?: string,
    signatories?: { role: string; name: string }[],
  ): Promise<boolean> {
    setBusyId(item.id)
    setError(null)
    try {
      await inspectionsApi.conduct(item.id, { result, findings, signatories })
      setRejecting(null)
      onChanged()
      return true
    } catch (err) {
      setError(toApiError(err).message)
      return false
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
      await inspectionsApi.reinspect(item.id, new Date(reinspectValue).toISOString(), reinspectInspectorId)
      setReinspectId(null)
      setReinspectValue('')
      setReinspectInspectorId(null)
      setReinspectTyped('')
      onChanged()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusyId(null)
    }
  }

  /*
   * Moving a visit that has not happened yet.
   *
   * The only control on the deleted /staff/inspections/{id} that had no home
   * anywhere else. Without it a filing whose visit was booked for a date the
   * officer cannot make could only be approved, rejected, or left — the
   * appointment itself was frozen.
   *
   * `reload` like the rest: rescheduling writes InspectionStatus::Rescheduled
   * as well as the new date, so the card's own status line changes and the copy
   * on screen is stale the moment this returns.
   */
  async function saveReschedule(item: Inspection) {
    if (!reschedValue) return
    setBusyId(item.id)
    setError(null)
    try {
      // A chosen pool inspector moves with the visit; "keep" sends nothing.
      await inspectionsApi.reschedule(item.id, new Date(reschedValue).toISOString(), reschedInspectorId ?? undefined)
      setReschedId(null)
      setReschedValue('')
      setReschedInspectorId(null)
      onChanged()
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusyId(null)
    }
  }

  /*
   * Opening this office's FIRST visit on its own permit.
   *
   * ── Why this one does NOT call `onChanged()` ───────────────────────────────
   *
   * Every other write here reloads, because every other write changes something
   * this panel cannot see: a recorded result issues the permit and can move the
   * filing off the inspection stage entirely. Booking changes exactly one thing
   * — `WorkflowService::scheduleClearanceInspection` creates the visit, audits
   * it and notifies the applicant, and writes no status at all. The permit stays
   * `for_inspection` and the filing stays where it was. So the reply IS the
   * whole change, and holding it is not a shortcut around a reload; it is the
   * complete new state.
   *
   * Reloading would also destroy the one thing this act has to leave behind.
   * `reload()` on the review screen sets `loading` back to true and the sheet
   * renders its skeleton, which unmounts this panel — taking the confirmation
   * with it before it can be announced. A screen-reader user would get a card
   * silently replaced by a different card and no statement that anything
   * happened (WCAG 4.1.3), which is the whole reason the note exists.
   *
   * The sentence is read off the SERVER's copy of the visit rather than off the
   * input, so a date the API normalised is the date reported back.
   */
  async function bookFirstVisit() {
    if (!book || !bookValue || booking) return
    setBooking(true)
    setError(null)
    setBookNote(null)
    try {
      const visit = await inspectionsApi.schedule(
        book.applicationId,
        book.code,
        new Date(bookValue).toISOString(),
        bookInspectorId ? null : bookInspector.trim() || null,
        bookInspectorId,
      )
      setBookValue('')
      setBookInspector('')
      setBookInspectorId(null)
      setBooked(visit)
      setBookNote(
        `${book.permit} inspection booked for ${formatDate(visit.scheduled_at)}. The applicant has been told the date.`,
      )
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBooking(false)
    }
  }

  /*
   * The visits to draw: what the caller was given, plus one this panel has just
   * opened and the caller has not re-fetched.
   *
   * De-duplicated by id, because the caller MAY reload for its own reasons — a
   * result recorded on another card, an officer reassigned — and the booked
   * visit would then arrive down the wire as well. Two identical cards for one
   * appointment is a worse bug than the stale card this fixes.
   */
  const allVisits =
    booked && !inspections.some((item) => item.id === booked.id) ? [...inspections, booked] : inspections

  /*
   * ── An office sees its OWN visits, and no one else's (INS-6) ──────────────
   *
   * The client, from the OBO account, 17 September 2026: *"I am using OBO's
   * account and they should not see the application process for the CHO. Remove
   * the inspection details from other office. I told you that Clearance Office
   * are independent of each other."*
   *
   * Another office's visits were drawn deliberately, badged "Another office"
   * and captioned *"recorded this result on its own visit. It is not a decision
   * on the filing, and it is not your office's clearance."* That copy is
   * accurate and it is answering a question nobody in an office chair asked:
   * the five clearances are independent, so CHO's visit is not context for
   * OBO's decision, it is somebody else's file.
   *
   * ── Filtered HERE and not on the server, deliberately ─────────────────────
   *
   * The payload is not a leak and this is not a confidentiality fix.
   * `ApplicationVisibility`'s line is that PROGRESS is readable across offices
   * and PROSE is not — an office can see that another office's permit is moving
   * and cannot read its remarks — and BPLO legitimately needs every visit,
   * because coordinating the five is BPLO's job. Narrowing the API would take
   * that away from BPLO to tidy a card for CHO.
   *
   * So the server keeps sending what it sends, and the panel shows the reader
   * what is theirs. `application.view_any_office` is the same flag the queue
   * and the review sheet's two disclosures key on: BPLO and the super admin see
   * all six, the five offices see one.
   *
   * `isMine` is reused rather than a second department comparison — it already
   * refuses the `undefined === undefined` case that would badge an unrouted
   * visit as the reader's own, and that refusal matters more when it decides
   * whether a card appears at all.
   */
  const seesEveryOffice = Boolean(user?.permissions.includes('application.view_any_office'))
  const visits = seesEveryOffice ? allVisits : allVisits.filter(isMine)

  /*
   * The card, and the live region that says what came of it.
   *
   * Withdrawn the moment a visit is booked, whether the news came from the
   * caller's payload or from this panel's own request: there is no longer a
   * FIRST visit to open, and the way on from the one that now exists is
   * Reschedule — which the visit's own card carries.
   *
   * Rendered by both branches below. The empty state is the COMMON case for a
   * first booking, because an office that has just accepted its paperwork is
   * usually the only one on the filing with anything to schedule.
   */
  const booker =
    book && !booked ? (
      <FirstInspectionCard
        permit={book.permit}
        value={bookValue}
        busy={booking}
        hintId={bookHintId}
        onChange={(next) => {
          setError(null)
          setBookValue(next)
        }}
        inspector={bookInspector}
        onInspectorChange={setBookInspector}
        inspectorId={bookInspectorId}
        onInspectorIdChange={setBookInspectorId}
        applicationId={book.applicationId}
        code={book.code}
        onBook={bookFirstVisit}
      />
    ) : null

  /*
   * `role="status"` on a node that is in the tree from the first render.
   * A live region mounted at the same moment its text appears is not reliably
   * announced — the assistive technology has nothing to observe changing.
   */
  const bookingAnnouncement = (
    <p role="status" className="mt-3 text-sm font-medium text-s-green empty:mt-0">
      {bookNote}
    </p>
  )

  if (visits.length === 0) {
    /*
     * The filing says For Inspection and carries no visit. That is a real
     * state — the schedule can be pending — and it is worth naming, because the
     * alternative is a heading with nothing under it and an officer wondering
     * which half of the page failed to load.
     *
     * For the office that can DO something about it, the sentence is not enough
     * and never was: this is where a filing dead-ended, because the one act that
     * moves it on — booking the visit — had no control anywhere in the product.
     * So the reader who may book sees the booking card, and the reader who may
     * not still gets the sentence.
     */
    return (
      <section className={className} aria-label="Application status">
        {error && (
          <p role="alert" className="mb-3 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
            {error}
          </p>
        )}
        {booker ? (
          <ul>{booker}</ul>
        ) : (
          <p className="rounded-2xl bg-white px-6 py-5 text-center text-sm text-ink-secondary shadow-card">
            This application is waiting on a site visit, but none has been scheduled yet.
          </p>
        )}
        {bookingAnnouncement}
      </section>
    )
  }

  return (
    <section className={className} aria-label="Application status">
      {error && (
        <p role="alert" className="mb-3 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {error}
        </p>
      )}

      <ul className="space-y-4">
        {/*
          The office's own unbooked permit comes FIRST, above other offices'
          visits. It is the only thing on this panel the reader can act on at
          all — everything below is somebody else's progress — and it was the
          thing with no control at all until now.
        */}
        {booker}
        {visits.map((item) => (
          <InspectionDecisionCard
            key={item.id}
            item={item}
            mine={isMine(item)}
            canAct={canAct(item)}
            busy={busyId === item.id}
            onApprove={() => {
              setError(null)
              setApproving(item)
            }}
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
                      setReinspectInspectorId(null)
                      setReinspectTyped('')
                      setReinspectId(item.id)
                    },
                    onClose: () => setReinspectId(null),
                    onChange: setReinspectValue,
                    onBook: () => bookReinspection(item),
                    inspectorId: reinspectInspectorId,
                    onInspectorIdChange: setReinspectInspectorId,
                    typed: reinspectTyped,
                    onTypedChange: setReinspectTyped,
                  }
                : undefined
            }
            /*
             * Offered only on a visit that has not happened yet, and only to
             * the office that owns it. A conducted visit has no appointment
             * left to move — the way on from a failure is a re-inspection, not
             * a new date on the failed row — and `canAct` is the same
             * department test the API applies (InspectionController::
             * authorizeDepartment covers reschedule too).
             */
            reschedule={
              canAct(item) && !inspectionDone(item)
                ? {
                    open: reschedId === item.id,
                    value: reschedValue,
                    onOpen: () => {
                      setError(null)
                      setReschedValue('')
                      setReschedInspectorId(null)
                      setReschedId(item.id)
                    },
                    onClose: () => setReschedId(null),
                    onChange: setReschedValue,
                    onSave: () => saveReschedule(item),
                    inspectorId: reschedInspectorId,
                    onInspectorIdChange: setReschedInspectorId,
                  }
                : undefined
            }
          />
        ))}
      </ul>

      {bookingAnnouncement}

      {/*
        Approve's confirmation, the same dialog every other Approve uses
        (ReviewPage). It was one click with no way back: passing the visit
        issues the office's permit at once.
      */}
      {approving && approving.department?.code === 'OBO' && (
        <CertificateNamesConfirm
          item={approving}
          submitting={busyId === approving.id}
          error={error}
          onCancel={() => {
            setError(null)
            setApproving(null)
          }}
          onConfirm={async (signatories) => {
            const item = approving
            if (await conduct(item, 'passed', undefined, signatories)) setApproving(null)
          }}
        />
      )}

      {approving && approving.department?.code !== 'OBO' && (
        <ProtoModal
          title="Approve this inspection?"
          tone="green"
          onCancel={() => setApproving(null)}
          confirmLabel={busyId === approving.id ? 'Approving…' : 'Yes, approve'}
          confirmDisabled={busyId === approving.id}
          onConfirm={() => {
            const item = approving
            setApproving(null)
            void conduct(item, 'passed')
          }}
        >
          <p className="text-sm text-ink-secondary">
            The inspection passes and your office’s permit is issued. This cannot be undone.
          </p>
        </ProtoModal>
      )}

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

import { useId, useState } from 'react'
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
 * Application Verification lands on the review sheet, not there.
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
 *  - Findings, the scheduled/finished date, the inspector's name with the
 *    department fallback — already on the card below.
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
          ) : (
            /*
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
             * An empty span rather than nothing, so the inspector's name stays
             * pinned to the right of a `justify-between` row instead of sliding
             * across to the left on conducted visits only.
             */
            <span />
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
                <input
                  type="datetime-local"
                  value={reschedule.value}
                  onChange={(e) => reschedule.onChange(e.target.value)}
                  aria-label={`New date and time for the ${office} inspection`}
                  className="rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
                />
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
  className?: string
}) {
  const user = useAuth((s) => s.user)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [rejecting, setRejecting] = useState<Inspection | null>(null)
  const [reinspectId, setReinspectId] = useState<number | null>(null)
  const [reinspectValue, setReinspectValue] = useState('')
  const [reschedId, setReschedId] = useState<number | null>(null)
  const [reschedValue, setReschedValue] = useState('')
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
    return !inspections.some((other) => other.department?.code === code && other.id > item.id)
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
      await inspectionsApi.reschedule(item.id, new Date(reschedValue).toISOString())
      setReschedId(null)
      setReschedValue('')
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
            mine={isMine(item)}
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
                      setReschedId(item.id)
                    },
                    onClose: () => setReschedId(null),
                    onChange: setReschedValue,
                    onSave: () => saveReschedule(item),
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

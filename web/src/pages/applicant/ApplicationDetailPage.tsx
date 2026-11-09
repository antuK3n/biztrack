import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Alert } from '../../components/ui/Alert'
import { RequestedChanges } from '../../components/RequestedChanges'
import { ReturnHistory } from '../../components/ReturnHistory'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton } from '../../components/ui/Proto'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { formatDate, formatDateTime } from '../../lib/format'
import { mainFormTargets, targetCodes } from '../../lib/returnTargets'
import { applications } from '../../lib/resources'
import {
  filingStatusMeta,
  isGatheringOtherPermits,
  labelHistory,
  otherPermitProgress,
} from '../../lib/status'
import type { Application, TimelineEntry } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { MainFormCorrections } from './MainFormCorrections'

/*
 * Application status page, trimmed to what only it does (client, 10 November
 * 2026: "not that important… it has links/buttons that can be accessed
 * anywhere, making it only a redundant page").
 *
 * It cannot go: it holds the only Fix-and-resubmit flow, and notifications,
 * emails, Track's "Fix and resubmit", PayPage's "Back to application" and the
 * wizard's "Track this application" all land here. So it keeps a compact
 * status line, the officers in charge, the amendment's changes, the
 * correction dialog and remarks, the return history and Re-apply.
 *
 * What went, and where it still lives (git history has every piece):
 *  - the Tax Order of Payment dialog and Pay Online → Track and
 *    /applications/:id/pay; a one-line "Pay online" link stays while unpaid
 *  - the issued permits' view/download chips → My Permits (one link here)
 *  - the LGU Clearances card → Track and /applications/:id/clearances
 *  - "Inspections by office" and the next-visit date → Track's permit rows
 *  - the read-only copies of the office forms (checklist item 24) → the
 *    clearance stage's "View submitted form"
 *  - the Messages thread → /messages?application=…, linked from the officer
 *    list
 * The big prototype StatusCards (PDF p50–58) went with them: one badge, the
 * same `filingStatusMeta` chip Track wears, says the status now.
 */

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

  /*
   * Is the correction dialog up?
   *
   * Open on arrival, because a returned filing is why the applicant is on
   * this page — "Fix and resubmit" from the list lands here. Closable to
   * read the rest of the filing, and reopened from the card below.
   */
  const [correctionsOpen, setCorrectionsOpen] = useState(true)
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
  /*
   * An amendment carries the Business Permit, plus ZONING when the premises
   * move or the trade or area grows (`WorkflowService::amendmentNeedsLocationalClearance`).
   * Read off the filing's own permit list, so this page and the server cannot
   * disagree about whether a Zoning Clearance is owed.
   */
  const isAmendment = app.application_type === 'amendment'
  const amendmentNeedsZoning = isAmendment && app.permit_types.some((pt) => pt.code === 'ZONING')
  const changes = isAmendment ? (app.requested_changes ?? []) : []
  /* Every row is applied in one transaction, so the first date is the date. */
  const changesAppliedAt = (app.requested_changes ?? []).find((c) => c.applied_at)?.applied_at ?? null
  /*
   * The next-visit date ("Next inspection: …") was read here and is gone with
   * the visits list (client, 10 November 2026); Track's permit rows carry it.
   */
  const withRemarks = status === 'rejected' || status === 'returned'
  /* Shared with the officer's progress rail so the two cannot count differently. */
  const otherPermits = otherPermitProgress(app.permit_types)

  /*
   * ── The fields BPLO ticked when it returned this filing ──────────────────
   *
   * Read off the assignments rather than held in their own key: the pointer
   * has always lived on `remarks_target`, and one column that every reader
   * parses the same way is what stops the officer's tick and the applicant's
   * boxes drifting apart.
   *
   * Only while the filing is RETURNED. The pointer survives the resubmission —
   * `returnMainForm` replaces it rather than clearing it, so the officer can
   * still see what the last round was about — and drawing correction boxes for
   * a filing already back with BPLO would invite an edit that cannot be sent.
   */
  const returnedFields =
    status === 'returned'
      ? app.assignments.flatMap((a) => mainFormTargets(a.remarks_target))
      : []
  /*
   * ── Two fields a sole proprietor does not type ────────────────────────
   *
   * The form derives and locks item 15 from the proprietor's own name and
   * item 17 from their citizenship, and the corrections endpoint re-derives
   * both on write. Offering a box here would take an answer, send it, and
   * silently replace it — worse than not offering one, because the
   * applicant would believe they had answered.
   *
   * Moved to the SECTION list instead, where they are corrected on the form
   * beside the field that actually drives them. Only for a sole
   * proprietorship: a corporation's president and pooled capital are real
   * answers nobody else knows.
   */
  const DERIVED_FOR_SOLE_PROPRIETOR = ['form:president_officer_name', 'form:capital_participation']
  const derivedHere =
    app.business?.registration_type === 'sole_proprietorship'
      ? DERIVED_FOR_SOLE_PROPRIETOR
      : []

  const returnedScalars = returnedFields.filter(
    (t) => t.kind === 'scalar' && !derivedHere.includes(t.value),
  )

  /*
   * ── Documents BPLO sent back ──────────────────────────────────────────
   *
   * A document target is a bare `document_types.code`, which
   * `mainFormTargets` drops — it resolves the wizard's `form:` codes and
   * nothing else. Matched against what the applicant UPLOADED, which is
   * also the only list the officer could have picked from.
   */
  const returnedDocuments =
    status === 'returned'
      ? (() => {
          const named = new Set(
            app.assignments.flatMap((a) => targetCodes(a.remarks_target)),
          )

          return [
            ...new Map(
              app.documents
                .filter((d) => named.has(d.document_type.code))
                .map((d) => [d.document_type.code, d.document_type]),
            ).values(),
          ]
        })()
      : []


  /* Remarks rows: rejection reason + any assignment remarks (p54–55). */
  const remarks: {
    who: string
    text: string
    fields: string[]
    items: { label: string; note: string }[]
  }[] = [
    ...(app.rejection_reason
      ? [{ who: 'Reason for rejection', text: app.rejection_reason, fields: [], items: [] }]
      : []),
    ...app.assignments
      .filter((a) => a.remarks)
      .map((a) => ({
        who: a.officer?.name ?? a.department.name,
        text: a.remarks as string,
        /*
         * Which fields the office named, when it named any.
         *
         * `mainFormTargets` — plural — because the column has held a
         * comma-separated list since 27 September 2026. The singular
         * `mainFormTargetLabel` was left here when the picker became a
         * checklist and looked the whole joined string up as one key, so a
         * return naming three fields showed the applicant none of them.
         *
         * It still drops anything that is not one of the wizard's own
         * fields: the same column also carries document codes and permit
         * codes, and printing `CHO_SANITARY_PERMIT` under a heading that
         * says "Fix" would read as a field name to somebody who has never
         * seen one.
         */
        fields: mainFormTargets(a.remarks_target).map((t) => t.label),
        /*
         * The office's note per named field, which is what the composed
         * `text` above is a flattening of. Kept apart so the screen can
         * show two returned fields as two things rather than as one
         * sentence with a semicolon in it.
         *
         * Empty for a return written as plain prose, and for one whose
         * notes predate them — both fall back to `text`.
         */
        items: mainFormTargets(a.remarks_target)
          .map((t) => ({ label: t.label, note: (app.return_notes ?? {})[t.value] ?? '' }))
          .filter((it) => it.note.trim() !== ''),
      })),
  ]

  /*
   * Who is handling this filing — one line per OFFICE, not one for the filing.
   *
   * This used to print a single name, picked with
   * `assignments.find(a => a.officer) ?? assignments[0]`. On a filing carrying
   * several clearances that is one of six offices chosen by list order, and the
   * applicant read it as "the officer handling my application" — so a business
   * whose sanitary review had been taken and whose fire review had not was told
   * about one and never about the other, with no way to tell which.
   *
   * Each office holds its own review and its own officer (client §9), so the
   * honest rendering is the list. Sorted by office name for a stable order:
   * assignment ids reorder as offices are routed, and a line that moves between
   * visits reads as a change when nothing has changed.
   */
  const handling = [...app.assignments]
    .sort((a, b) => a.department.name.localeCompare(b.department.name))
    .map((a) => ({
      key: a.id,
      office: a.department.name,
      /*
       * Three states, not two. `officer_withheld` means the name exists and is
       * not this reader's to see — never true for the applicant, who authors
       * every sheet on their own filing, but the resource can say it and a
       * screen that ignored it would print "not yet taken" over a review that
       * had been.
       */
      officer: a.officer?.name ?? null,
      withheld: a.officer_withheld === true,
    }))
  /*
   * Messaging opens once an officer has taken the filing for some office
   * (checklist 2026-09-27, apply item 23). Before that nobody is there to
   * answer, and the server refuses the message and offers no office - so the
   * link and the panel wait too, rather than opening on an empty conversation.
   */
  const taken = handling.some((row) => row.officer !== null || row.withheld)

  /*
   * ── The status line (client, 10 November 2026) ──────────────────────────
   *
   * One badge and one sentence instead of a 4xl prototype card per status.
   * The badge is `filingStatusMeta`, the chip Track draws on the same filing,
   * so the two screens cannot name it differently: a filing still gathering
   * its other permits reads "Approved", a decided one "Completed".
   *
   * The sentence is what the badge cannot say, and the only links left are the
   * one next step each status has: pay, apply for the outstanding clearances,
   * or find the issued permits. Everything they point at lives elsewhere.
   */
  const meta = filingStatusMeta(app)
  const gathering = isGatheringOtherPermits(app)
  const inlineLink = 'font-semibold text-royal underline underline-offset-2 hover:text-royal-hover'
  /* Built only on the branches that use it, which are the ones with permits. */
  const permitsLink = () => (
    <Link to="/permits" className={inlineLink}>
      {app.permits.length > 1 ? 'See them in My Permits' : 'See it in My Permits'}
    </Link>
  )

  let note: ReactNode = null
  if (status === 'draft') {
    note = 'This application has not been submitted yet.'
  } else if (status === 'returned') {
    note = 'Fix the remarks below, then resubmit. Your tracking ID stays the same.'
  } else if (isPayment) {
    note = (
      <>
        Your Tax Order of Payment is ready.{' '}
        <Link to={`/applications/${app.id}/pay`} className={inlineLink}>
          Pay online
        </Link>
      </>
    )
  } else if (status === 'for_approval') {
    /*
     * BPLO reads the form first, and only then is there anything to pay. Not
     * on an amendment: no fee is assessed on one — it joins the next renewal
     * (docs/amendment-2026-09-19.md §3; tester, 5 October 2026). A new
     * filing's other permits are BPLO's to list here since 5 October 2026
     * (client: "BPLO decides, no rules").
     */
    note = isAmendment
      ? 'BPLO is reading your amendment.'
      : app.application_type === 'annual_inspection'
        ? // Opened by the system, not filed (client, 20 October 2026).
          'Your Occupancy Permit is due for its yearly inspection. OBO will set the date. There is no fee.'
        : app.application_type === 'new'
          ? 'BPLO is reading your form and will list the other permits you need. Your fees are assessed once it is approved.'
          : 'BPLO is reading your form. Your fees are assessed once it is approved.'
  } else if (gathering) {
    /*
     * The "N of M other permits approved" count lives here and on no other
     * owner screen in this form — Track's badge counts every permit, the
     * Business Permit included (`otherPermitProgress` vs its `rows`). The
     * Business Permit is released at payment (24 September 2026), and a
     * rejected clearance suspends it, so both are said.
     */
    note =
      otherPermits.outstanding.length === 0 ? (
        <>
          Your Business Permit is issued, and all {otherPermits.total} other permits are
          approved. {permitsLink()}
        </>
      ) : (
        <>
          Your Business Permit is issued. {otherPermits.approved} of {otherPermits.total} other
          permits approved · still to come: {otherPermits.outstanding.join(', ')}. Apply for each
          one in{' '}
          <Link to={`/applications/${app.id}/clearances`} className={inlineLink}>
            LGU Clearances
          </Link>
          . If one is rejected or fails its inspection, your Business Permit is suspended until
          it is settled.
        </>
      )
  } else if (status === 'for_final_approval') {
    note = 'BPLO is finishing your application. Nothing is needed from you.'
  } else if (status === 'approved' || status === 'issued') {
    note =
      app.permits.length > 0
        ? permitsLink()
        : 'Your permits are being issued. They will appear in My Permits shortly.'
  }

  /*
   * ── An amendment that carries ZONING ────────────────────────────────────
   *
   * Was its own "LGU Clearances" card, marked required; one sentence now. BPLO
   * cannot approve the amendment until the Zoning Clearance is issued
   * (`WorkflowService::approveAmendment`; Ken, 6 October 2026), so it stays on
   * the page that the filing's notifications open. Any part of the address
   * counts, as `WorkflowService::amendmentMovesPremises` reads it — the box's
   * note (`address_details`) changes nothing on the register.
   */
  const zoningNote =
    status !== 'draft' && amendmentNeedsZoning ? (
      <>
        {(app.requested_changes ?? []).some(
          (c) => c.field.startsWith('address_') && c.field !== 'address_details' && c.new_value !== null,
        )
          ? 'A new Zoning Clearance is needed for the new address.'
          : 'A new Zoning Clearance is needed for this change.'}{' '}
        <Link to={`/applications/${app.id}/clearances`} className={inlineLink}>
          Apply for it here.
        </Link>
      </>
    ) : null

  return (
    <div className="mx-auto max-w-3xl">
      <div className="mb-5 border-b-2 border-ink/50 pb-2">
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

      <div>
        <section aria-labelledby="status-heading" className="rounded-xl bg-white px-5 py-4 shadow-card">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
            <h2 id="status-heading" className="text-sm font-bold uppercase tracking-wide text-ink-muted">
              {isPayment ? 'Payment Status' : 'Application Status'}
            </h2>
            <StatusBadge tone={meta.tone} label={meta.label} icon={meta.icon} />
            {status === 'for_approval' && app.deadline_at && (
              <span className="text-sm text-ink-secondary sm:ml-auto">
                Deadline: <span className="tnum">{formatDate(app.deadline_at)}</span>
              </span>
            )}
          </div>
          {note && <p className="mt-2 text-sm text-ink-secondary">{note}</p>}
          {zoningNote && (
            <p className="mt-2 text-sm text-ink-secondary">
              {zoningNote}
              <span className="text-s-red" aria-hidden="true"> *</span>
              <span className="sr-only"> (required)</span>
            </p>
          )}
        </section>

        {/*
          ── What the amendment changes ──────────────────────────────────

          This page never read `requested_changes`, so the applicant could
          not see on their own filing what they had asked to change (tester,
          5 October 2026). Old → new while BPLO decides; once approved, what
          the change actually replaced (`old_value`) and the day it landed.
        */}
        {changes.length > 0 && (
          <section aria-labelledby="changes-heading" className="mt-4 rounded-xl bg-white px-5 py-4 shadow-card">
            <h3 id="changes-heading" className="text-sm font-bold uppercase tracking-wide text-ink-muted">
              {changesAppliedAt ? `Changes applied on ${formatDate(changesAppliedAt)}` : 'Changes requested'}
            </h3>
            <div className="mt-2">
              <RequestedChanges requested={changes} />
            </div>
          </section>
        )}

        {/* ── Who is handling it, office by office ─────────────────────── */}
        {/*
          * One row per office, the office on the left and the person on the
          * right — the shape the client asked for (§4: Office / Officer in
          * Charge) and the one the super admin's register uses. It replaced a
          * single right-aligned italic line that named one office of six.
          *
          * The Messages thread that sat at the foot of this page went on
          * 10 November 2026 (client); the link below is the way to it.
          */}
        {handling.length > 0 && (
          <section aria-labelledby="handling-heading" className="mt-4 rounded-xl bg-white px-5 py-4 shadow-card">
            <h3 id="handling-heading" className="text-sm font-bold uppercase tracking-wide text-ink-muted">
              Officer in charge
            </h3>
            <ul className="mt-2 divide-y divide-line">
              {handling.map((row) => (
                <li key={row.key} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-1.5">
                  <span className="text-sm font-semibold text-ink">{row.office}</span>
                  {row.officer ? (
                    <span className="flex items-center gap-2 text-sm text-royal">
                      <AvatarGlyph size={22} />
                      <span className="font-semibold">{row.officer}</span>
                    </span>
                  ) : (
                    /*
                      * Said plainly rather than left blank. "Not yet taken" is
                      * not bad news — an office picks a filing up when it
                      * reaches the top of its queue — and an empty cell reads
                      * as a name that failed to load.
                      */
                    <span className="text-sm italic text-ink-muted">
                      {row.withheld ? 'Assigned' : 'Not yet taken'}
                    </span>
                  )}
                </li>
              ))}
            </ul>
            {/*
              * One link, not one per office: the conversation is keyed by
              * FILING and carries an office picker inside it.
              */}
            {taken && status !== 'draft' && (
              <Link
                to={`/messages?application=${app.id}`}
                aria-label="Message an office about this application"
                className="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-royal hover:underline"
              >
                <MessageIcon size={16} />
                Message
              </Link>
            )}
          </section>
        )}

        {/*
          ── What BPLO asked you to correct ────────────────────────────────

          Above Remarks on purpose. A returned filing is stopped until this is
          answered, so it is the only thing on the page the applicant has to
          ACT on; everything below it is history and status. See the note at
          the head of this patch for why it lives on this page at all.
        */}
        {/*
          The same dialog the five office sheets use. Its CONTENTS are
          unchanged — the boxes, the uploads, the section note, the
          completeness rule — and only the shell around them moved, because
          the shell is what kept diverging from the clearance copy of it.
        */}
        {/*
          A way back in. Closing the dialog to read the filing behind it must
          not strand somebody with a return they can no longer answer.
        */}
        {!correctionsOpen && (returnedFields.length > 0 || returnedDocuments.length > 0) && (
          <section
            aria-labelledby="correct-heading"
            className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-5 py-4 shadow-card"
          >
            <div>
              <h2 id="correct-heading" className="text-sm font-bold uppercase tracking-wide text-ink-muted">
                What you need to correct
              </h2>
              <p className="mt-1 text-sm text-ink-secondary">
                BPLO returned this application about{' '}
                {returnedFields.length + returnedDocuments.length === 1
                  ? 'one item'
                  : `${returnedFields.length + returnedDocuments.length} items`}
                .
              </p>
            </div>
            <button
              type="button"
              onClick={() => setCorrectionsOpen(true)}
              className="rounded-md bg-royal px-5 py-2 text-sm font-semibold text-white shadow-card hover:bg-royal-hover"
            >
              Fix and resubmit
            </button>
          </section>
        )}
        {correctionsOpen && (
          <MainFormCorrections
            app={app}
            onClose={() => setCorrectionsOpen(false)}
            onDone={reload}
          />
        )}
        {/*
          ── Every return, kept after it is complied (client, 19 October 2026) ──

          The box above and the Remarks below are the CURRENT round and go
          when it is answered. This keeps each round — what was asked, by
          which office, and what changed — so a complied return still reads
          as asked and answered rather than vanishing. Renders nothing on a
          filing that was never returned.
        */}
        <ReturnHistory
          applicationId={app.id}
          audience="owner"
          refreshKey={`${app.status}|${app.permit_types.map((p) => p.status).join(',')}`}
          className="mt-4"
        />
        {/* ── Remarks (p54–55) ─────────────────────────────────────────── */}
        {withRemarks && (
          <section aria-labelledby="remarks-heading" className="mt-4">
            <h2 id="remarks-heading" className="text-sm font-bold uppercase tracking-wide text-ink-muted">
              Remarks
            </h2>
            <ul className="mt-2 space-y-3">
              {(remarks.length > 0
                ? remarks
                : [
                    {
                      who: 'Reviewing office',
                      text: 'No detailed remarks were recorded.',
                      fields: [],
                      items: [],
                    },
                  ]
              ).map((r, i) => (
                <li
                  key={i}
                  className="flex flex-wrap items-baseline gap-x-6 gap-y-1 rounded-xl bg-white px-5 py-3 shadow-card"
                >
                  <span className="text-sm italic text-ink-muted underline underline-offset-2">{r.who}:</span>
                  {/*
                    One box per field the office named. The single `text`
                    below is the same thing flattened — "…: Blurred; …:
                    Blurred" — which is right for a notification and wrong
                    here, where the applicant is working through the items
                    one at a time.
                  */}
                  {r.items.length > 0 ? (
                    <ul className="basis-full space-y-2">
                      {r.items.map((it) => (
                        <li
                          key={it.label}
                          className="rounded-lg border-l-4 border-s-rose bg-s-rose-tint/40 px-3 py-2"
                        >
                          <p className="text-sm font-semibold text-ink">{it.label}</p>
                          <p className="mt-0.5 text-sm italic text-ink-secondary">“{it.note}”</p>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <span className="text-sm text-ink">{r.text}</span>
                  )}
                  {/*
                    The field the office named, when it named one.

                    A chip rather than a sentence, and AFTER the remark rather
                    than before it: the remark is what the applicant has to
                    understand, and the field is where they have to go. Reading
                    it the other way round — "5. Trade Name / Franchise: the
                    name does not match your DTI certificate" — buries the
                    reason behind a label.

                    It carries the wizard's own numbering, so "5. Trade Name /
                    Franchise" here is the question headed "5. Trade Name /
                    Franchise" on the form that reopens. That is the whole point
                    of the pointer: a lookup instead of a hunt.
                  */}
                  {r.items.length === 0 &&
                    r.fields.map((label) => (
                    <span
                      key={label}
                      className="rounded-md bg-s-orange-tint px-2.5 py-1 text-xs font-semibold text-ink"
                    >
                      Fix: {label}
                      </span>
                    ))}
                </li>
              ))}
            </ul>
            <div className="mt-3 flex justify-end">
              {/*
                ── The bare Resubmit is withheld while fields are open ────

                It predates the corrections card and calls `resubmit`
                straight out, so an applicant could ignore the boxes above
                and hand the filing back unchanged: BPLO returns it over a
                wrong registration number, they press this, and it arrives
                at For Approval with the same wrong number. Client,
                28 September 2026: *"DO NOT ALLOW RESUBMISSION UNTIL A FIELD
                IS FILLED."*

                Gated on there being NO boxes at all, not on the boxes being
                filled. Gating on "filled" meant both buttons appeared the
                moment the field was valid — two ways to do one thing, which
                is what the client saw next. When this page has correction
                boxes, Submit corrections IS the resubmit: it writes and
                resubmits in one transaction.

                Not deleted, because a return naming only SECTIONS — the
                line-of-business table, the uploaded documents — has no boxes
                here. That filing is fixed in the wizard and still needs a
                way back.
              */}
              {status === 'returned' ? (
                /*
                  Withheld whenever the card above is showing anything to
                  answer — scalars OR documents. Gated on the scalars alone
                  it appeared on a document-only return and skipped the
                  upload, handing the filing back unchanged.
                */
                returnedScalars.length === 0 && returnedDocuments.length === 0 && (
                  <PillButton onClick={runResubmit} disabled={action === 'resubmit'}>
                    {action === 'resubmit' ? 'Resubmitting…' : 'Resubmit'}
                  </PillButton>
                )
              ) : (
                <PillButton onClick={() => navigate('/apply')}>Re-apply</PillButton>
              )}
            </div>
          </section>
        )}

        {/*
          ── Compact secondary timeline ─────────────────────────────────────

          Folded shut since 10 November 2026 (client: trim this page). Track
          draws each permit's own history; this is the filing's, and the one
          place a whole-filing record is kept, so it stays one line until
          opened.
        */}
        {(timeline?.length ?? 0) > 0 && (
          <section className="mt-4">
            <details className="rounded-xl bg-white px-5 py-3 shadow-card">
              <summary className="cursor-pointer text-xs font-bold uppercase tracking-wide text-ink-muted">
                History
              </summary>
              <ol className="mt-3 space-y-2">
                {/*
                  Named per filing, not per status: the payment row of a filing
                  still gathering its other permits is "Approved", not the
                  status table's "Completed". See `labelHistory`.
                */}
                {labelHistory(timeline ?? [], app).reverse().map(({ entry, to }, i) => {
                  return (
                    <li key={`${entry.to_status}-${entry.created_at}-${i}`} className="flex items-baseline gap-3">
                      <span
                        className={`h-2 w-2 shrink-0 translate-y-[-1px] rounded-full ${
                          i === 0 ? 'bg-royal' : 'bg-line'
                        }`}
                      />
                      <span className="text-sm font-semibold text-ink">{to}</span>
                      <span className="text-xs text-ink-muted">
                        {formatDateTime(entry.created_at)}
                        {entry.changed_by ? ` · ${entry.changed_by.name}` : ''}
                      </span>
                    </li>
                  )
                })}
              </ol>
            </details>
          </section>
        )}

        {/*
          The Cancel control stood here and is gone — 30 September 2026, on
          the client's decision, alongside the abandonment sweep that now
          removes a filing left untouched for its window
          (`applications:purge-abandoned`).

          The ENDPOINT is still routed and still owner-authorised, so this
          is reversible without touching the server. If it comes back, the
          list of statuses must be derived from one source: this one read
          ['draft', 'submitted', 'pending_payment'] while the API accepted
          draft, for_approval, returned and pending_payment — `submitted`
          had not been a status since September and never matched, and the
          button was missing from the two states where a filing is actually
          abandoned.
        */}
      </div>
    </div>
  )
}

import { useEffect, useState } from 'react'
import type { SVGProps } from 'react'
import { ArrowLeftIcon, DownloadIcon } from '../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  PillButton,
  ProtoCard,
  ProtoModal,
  SortFilter,
  StatusChip,
  inputCls,
} from '../components/ui/Proto'
import type { ChipTone } from '../components/ui/Proto'
import { toApiError } from '../lib/api'
import { businessName, formatDate, formatDateTime } from '../lib/format'
import { applications, documents, requests } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'
import type {
  ApplicationListItem,
  OfficerRequest,
  RequestStatus,
} from '../lib/types'

/*
 * Other Requirements — PDF p23–25, now wired to the real /requests feed.
 * Owner view: read a request letter, then respond (textarea + optional file).
 * A request accepts MANY responses — one requirement often needs several
 * uploads or a follow-up note — so replies render as a chronological thread
 * and stay open until the officer closes the request.
 * Officer view (request.create): the same list plus a "Request" compose modal
 * and fulfil/reject close actions on submitted requests.
 *
 * On recipients (checklist item 89, "the admin should choose who will receive
 * this"): the composer names the recipient and does not offer to change it,
 * because there is nothing to change it to. A request is answered through
 * `POST /requests/{id}/respond`, gated on `request.respond` — a permission the
 * business_owner role holds and no office role does — and the list scoping in
 * OfficerRequestController::index hands an owner the requests on their own
 * filings. So the recipient is the applicant, always, and it moves only when
 * the chosen application does. A picker offering an office would be a control
 * that cannot do what it says: the request would arrive nowhere, be answerable
 * by nobody, and notify the applicant regardless.
 *
 * Routing a requirement to another OFFICE is a real feature and this is not it.
 * It needs `officer_requests.recipient_user_id` / `recipient_department_id`,
 * `request.respond` for office roles, and scoping + notification rewrites — a
 * schema change, not a select element. Item 57 gave the admin the FROM side and
 * that control is real; this is the TO side, and the honest version of it is a
 * name rather than a choice.
 */

/*
 * `created_by` and `application` are nullable on the wire and were read
 * straight through.
 *
 * OfficerRequestResource emits `created_by: null` for a request whose officer
 * has left (User soft-deletes) and `application: null` for one whose filing has
 * gone (Application soft-deletes). Both are the documented shape, not a fault.
 * With no error boundary above this route, either one threw on the first field
 * the screen prints and blanked the whole page rather than one row — the same
 * failure `business: null` caused in the composer, and the reason
 * `businessName()` exists. Naming what is missing is also the useful answer:
 * an officer chasing a stalled requirement needs to know the filing behind it
 * was removed, which is usually why it stalled.
 */
function senderName(request: OfficerRequest): string {
  return request.created_by?.name ?? 'Officer removed from register'
}

/*
 * Tone only — the WORDS come from the API's `status_label`.
 *
 * This map used to carry both, so the screen had its own private vocabulary:
 * it said "Submitted" and "Fulfilled" while the client's spec says "For Review"
 * and "Approved", and `needs_resubmission` was missing altogether, which in a
 * `Record<RequestStatus, …>` meant an undefined lookup and a chip rendering as
 * blank. One source of truth for the label; the colour is presentation and
 * stays here.
 *
 * Needs Resubmission is orange, the same as Pending, because to a business
 * owner they are the same situation: you owe us a document.
 */
const STATUS_TONE: Record<RequestStatus, ChipTone> = {
  pending: 'orange',
  needs_resubmission: 'orange',
  submitted: 'tint-purple',
  fulfilled: 'green',
  rejected: 'red',
}

const STATUS_DOT: Record<RequestStatus, string> = {
  pending: 'bg-s-orange',
  needs_resubmission: 'bg-s-orange',
  submitted: 'bg-royal',
  fulfilled: 'bg-s-green',
  rejected: 'bg-s-red',
}

function StatusDot({ status, label }: { status: RequestStatus; label: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-secondary">
      <span className={`h-2 w-2 shrink-0 rounded-full ${STATUS_DOT[status] ?? 'bg-line'}`} aria-hidden="true" />
      {label}
    </span>
  )
}

/** Blue avatar circle with a white person glyph. */
function AvatarCircle({ size = 'md' }: { size?: 'md' | 'sm' }) {
  const cls = size === 'sm' ? 'h-10 w-10' : 'h-12 w-12'
  return (
    <span
      aria-hidden="true"
      className={`flex ${cls} shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#7796c5]`}
    >
      <svg viewBox="0 0 24 24" className="mt-2 h-full w-full fill-white">
        <circle cx="12" cy="8" r="4" />
        <path d="M12 13.5c-4.4 0-7 2.6-7 6.5h14c0-3.9-2.6-6.5-7-6.5Z" />
      </svg>
    </span>
  )
}

function ShareIcon({ size = 22, ...props }: SVGProps<SVGSVGElement> & { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      <path d="M12 14V3.5M8 7l4-3.5L16 7" />
      <path d="M5 11v8.5A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V11" />
    </svg>
  )
}

/* ── Letter view (PDF p24–25) ─────────────────────────────────────────── */
function LetterView({
  request,
  isOfficer,
  onBack,
  onUpdated,
}: {
  request: OfficerRequest
  isOfficer: boolean
  onBack: () => void
  onUpdated: (updated: OfficerRequest) => void
}) {
  const [replying, setReplying] = useState(false)
  const [replyText, setReplyText] = useState('')
  const [replyFile, setReplyFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectReason, setRejectReason] = useState('')

  const tone = STATUS_TONE[request.status] ?? 'tint-gray'
  const thread = request.responses ?? []
  /*
   * Taken from the API's own answer, not re-derived from the status string.
   *
   * This read `status === 'pending' || status === 'submitted'`, which silently
   * excluded `needs_resubmission` — so after an office sent a document back
   * asking for a clearer copy, the owner had no button to send one. The API
   * accepted the resubmission the whole time; the screen just never offered it,
   * which is the worst shape for this bug to take because nothing errors.
   */
  const canRespond = !isOfficer && request.accepts_response
  const canClose = isOfficer && request.awaits_office

  async function submitResponse() {
    setBusy(true)
    setError(null)
    try {
      const updated = await requests.respond(request.id, replyText.trim(), replyFile)
      onUpdated(updated)
      setReplying(false)
      setReplyText('')
      setReplyFile(null)
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  /*
   * Approve, or send it back.
   *
   * "Reject" here means `needs_resubmission`, not the terminal `rejected`:
   * "Do NOT mark the requirement as completed after rejection ... the
   * requirement should remain active until the Admin approves a valid
   * submission." Turning down a DOCUMENT is not the same act as withdrawing a
   * REQUIREMENT, and only the second one ends the matter — so only the first is
   * offered on this screen.
   */
  async function close(outcome: 'fulfilled' | 'needs_resubmission', remarks?: string) {
    setBusy(true)
    setError(null)
    try {
      onUpdated(await requests.close(request.id, outcome, remarks))
      setRejectOpen(false)
      setRejectReason('')
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <button
        type="button"
        onClick={onBack}
        className="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-ink-secondary hover:text-ink"
      >
        <ArrowLeftIcon size={18} /> Other Requirements
      </button>

      <div className="rounded-2xl bg-white px-6 py-8 shadow-card sm:px-10">
        <div className="flex flex-wrap items-start justify-between gap-3 border-b-2 border-ink/40 pb-3">
          <h1 className="text-3xl font-bold text-ink">{request.subject}</h1>
          <StatusChip tone={tone}>{request.status_label}</StatusChip>
        </div>

        <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <AvatarCircle />
            <div>
              <span className="block text-lg font-bold text-ink">{senderName(request)}</span>
              {/*
               * The office the composer attributed this to, which is not always
               * the requester's own — the super admin has none and picks one.
               * `created_by.department` is the fallback for rows written before
               * the picker existed.
               */}
              {(request.from_office?.name ?? request.created_by?.department) && (
                <span className="block text-xs italic text-ink-muted">
                  {request.from_office?.name ?? request.created_by?.department}
                </span>
              )}
            </div>
          </div>
          <span className="text-sm italic text-ink-muted">{formatDateTime(request.created_at)}</span>
        </div>

        {/*
         * Addressed to, on the letter itself. An officer who has sent a dozen of
         * these needs to see which one went to whom without opening the filing,
         * and the applicant reading it should see their own name on it — a
         * letter with no addressee reads like a broadcast.
         */}
        <p className="mt-4 text-sm text-ink-secondary sm:pl-16">
          <span className="font-semibold text-ink">To:</span>{' '}
          {request.recipient?.name ?? 'The business owner on file'}
          <span className="text-ink-muted"> · applicant</span>
        </p>

        {/*
          What this requirement IS, before what it says.

          The letter used to open straight into the body text, with the business
          only mentioned in a grey "re ..." line. An owner with two shops could
          not tell at a glance which one a Health Certificate was for, and the
          deadline and the office's reference file had nowhere to appear at all.
        */}
        <dl className="mt-6 grid gap-x-6 gap-y-3 rounded-xl bg-canvas px-5 py-4 sm:ml-16 sm:grid-cols-2">
          <div>
            <dt className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Business</dt>
            <dd className="text-sm font-bold text-ink">
              {businessName(
                request.application?.business_name ? { name: request.application.business_name } : null,
              )}
            </dd>
          </div>
          <div>
            <dt className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Business No.</dt>
            <dd className="tnum text-sm font-bold text-ink">
              {request.application?.tracking_id || 'Draft — not yet filed'}
            </dd>
          </div>
          <div>
            <dt className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Requesting office</dt>
            <dd className="text-sm font-bold text-ink">
              {request.from_office?.name ?? request.created_by?.department ?? '—'}
            </dd>
          </div>
          <div>
            <dt className="text-[10px] font-semibold uppercase tracking-wide text-ink-muted">Deadline</dt>
            <dd className="text-sm font-bold text-ink">
              {request.due_date ? formatDate(request.due_date) : 'No deadline set'}
            </dd>
          </div>
        </dl>

        <div className="mt-6 space-y-3 pl-0 text-[15px] leading-relaxed text-ink sm:pl-16">
          <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Instructions</p>
          <p className="whitespace-pre-wrap">{request.body || 'No instructions were given.'}</p>

          {request.additional_remarks && (
            <div className="rounded-xl bg-input px-5 py-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                Additional remarks
              </p>
              <p className="mt-1 whitespace-pre-wrap text-sm">{request.additional_remarks}</p>
            </div>
          )}

          {request.reference && (
            <button
              type="button"
              onClick={() => requests.viewReference(request.id, window.open('', '_blank'))}
              className="inline-flex items-center gap-1.5 text-sm font-semibold text-royal underline hover:text-royal-hover"
            >
              <DownloadIcon size={16} />
              {request.reference.name ?? 'Reference file'}
            </button>
          )}
        </div>

        {/*
          The office's verdict on the latest submission, said where the owner is
          already reading. Without it a status of "Needs Resubmission" is an
          instruction with no content - it moves the question to a phone call.
        */}
        {request.remarks && !request.awaits_office && (
          <div
            className={`mt-6 rounded-xl px-5 py-4 sm:ml-16 ${
              request.status === 'fulfilled' ? 'bg-s-green-tint' : 'bg-s-red-tint'
            }`}
          >
            <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
              {request.status === 'fulfilled' ? 'Approved with a note' : `${request.status_label} — why`}
            </p>
            <p className="mt-1 whitespace-pre-wrap text-sm font-medium text-ink">{request.remarks}</p>
          </div>
        )}

        {thread.length > 0 && (
          <div className="mt-6 sm:ml-16">
            <h2 className="text-xs font-bold uppercase tracking-wide text-royal">
              {thread.length === 1 ? 'Applicant response' : `Applicant responses (${thread.length})`}
            </h2>
            <ol className="mt-2 flex flex-col gap-3">
              {thread.map((r) => (
                <li key={r.id} className="rounded-xl bg-input px-5 py-4">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <span className="text-sm font-bold text-ink">
                      {thread.length > 1 && (
                        <span className="mr-1.5 font-semibold text-ink-muted">
                          Submission #{r.number}
                        </span>
                      )}
                      {r.author.name ?? 'Applicant'}
                    </span>
                    <span className="text-xs italic text-ink-muted">{formatDateTime(r.created_at)}</span>
                  </div>
                  {/*
                    The verdict on THIS submission, not the requirement's current
                    one. The parent carries a single remark that is always the
                    latest, so a history rendered from it told the owner that
                    every earlier attempt was refused for today's reason.
                  */}
                  {r.review_outcome && (
                    <p className="mt-1.5 text-xs font-semibold text-ink-secondary">
                      <span
                        className={r.review_outcome === 'fulfilled' ? 'text-s-green' : 'text-s-red'}
                      >
                        {r.review_status_label}
                      </span>
                      {r.review_remarks && <span className="font-normal"> — {r.review_remarks}</span>}
                    </p>
                  )}
                  {r.body && <p className="mt-1.5 whitespace-pre-wrap text-sm text-ink">{r.body}</p>}
                  {r.document && (
                    <button
                      type="button"
                      onClick={() => documents.download(r.document!.id, r.document!.filename ?? 'attachment')}
                      className="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-royal underline hover:text-royal-hover"
                    >
                      <DownloadIcon size={14} />
                      {r.document.filename ?? 'Attachment'}
                    </button>
                  )}
                </li>
              ))}
            </ol>
          </div>
        )}

        {error && <p className="mt-4 text-sm font-medium text-s-red">{error}</p>}

        <div className="mt-8 flex flex-wrap items-center gap-4 sm:pl-16">
          {canRespond && !replying && (
            <>
              <PillButton onClick={() => setReplying(true)} className="px-9">
                {thread.length > 0 ? 'Add another response' : 'Respond'}
              </PillButton>
              {thread.length > 0 && (
                <p className="text-sm text-ink-secondary">
                  You can keep adding responses until this office closes the request.
                </p>
              )}
            </>
          )}
          {canClose && (
            <>
              <button
                type="button"
                onClick={() => close('fulfilled')}
                disabled={busy}
                className="rounded-md bg-s-green px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:brightness-110 disabled:opacity-60"
              >
                {busy ? 'Working…' : 'Approve'}
              </button>
              <button
                type="button"
                onClick={() => setRejectOpen(true)}
                disabled={busy}
                className="rounded-md bg-s-red px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:brightness-110 disabled:opacity-60"
              >
                Reject — ask again
              </button>
            </>
          )}
          {!canRespond && !canClose && (
            <StatusDot status={request.status} label={`Status: ${request.status_label}`} />
          )}
        </div>
      </div>

      {rejectOpen && (
        <ProtoModal
          title="Send this back"
          tone="red"
          cancelLabel="Cancel"
          confirmLabel="Send back for resubmission"
          onCancel={() => setRejectOpen(false)}
          onConfirm={() => close('needs_resubmission', rejectReason.trim())}
          confirmDisabled={busy || !rejectReason.trim()}
        >
          <p className="mb-4 border-b border-line pb-3 text-sm text-ink-secondary">
            {request.subject} ·{' '}
            {businessName(
              request.application?.business_name ? { name: request.application.business_name } : null,
            )}
          </p>
          <label className="block">
            <FieldLabel required>Why is this being sent back?</FieldLabel>
            <textarea
              className={`${inputCls} min-h-24`}
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              placeholder="e.g. Please submit a clearer copy of the certificate."
            />
            <p className="mt-1.5 text-xs text-ink-secondary">
              The owner sees this word for word, and the requirement stays open so they
              can submit a replacement.
            </p>
          </label>
        </ProtoModal>
      )}

      {replying && (
        <div className="mt-6 flex items-start gap-4">
          <AvatarCircle size="sm" />
          <div className="flex-1 rounded-2xl bg-white px-6 py-5 shadow-card">
            <textarea
              value={replyText}
              onChange={(e) => setReplyText(e.target.value)}
              placeholder="Write your response…"
              rows={5}
              aria-label="Response"
              className="w-full resize-none border-none bg-transparent text-[15px] text-ink placeholder:text-ink-secondary focus:outline-none"
            />
            {replyFile && (
              <p className="mt-2 flex items-center gap-2 text-xs text-ink-secondary">
                <ShareIcon size={14} /> {replyFile.name}
                <button
                  type="button"
                  onClick={() => setReplyFile(null)}
                  className="font-semibold text-s-red underline"
                >
                  Remove
                </button>
              </p>
            )}
            <div className="mt-3 flex items-center gap-5">
              <PillButton
                disabled={busy || (!replyText.trim() && !replyFile)}
                onClick={submitResponse}
                className="px-9"
              >
                {busy ? 'Sending…' : 'Send'}
              </PillButton>
              <label
                className="flex cursor-pointer items-center gap-1.5 text-sm text-ink-muted hover:text-ink"
                aria-label="Attach a document"
              >
                <ShareIcon size={22} />
                Attach
                <input
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png"
                  className="sr-only"
                  onChange={(e) => {
                    setReplyFile(e.target.files?.[0] ?? null)
                    e.target.value = ''
                  }}
                />
              </label>
              <button
                type="button"
                onClick={() => {
                  setReplying(false)
                  setReplyText('')
                  setReplyFile(null)
                }}
                className="ml-auto text-sm font-semibold text-ink-muted underline hover:text-ink"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/* ── Compose modal (officer) ──────────────────────────────────────────── */
function ComposeModal({
  apps,
  onClose,
  onCreated,
}: {
  apps: ApplicationListItem[]
  onClose: () => void
  onCreated: (created: OfficerRequest) => void
}) {
  const [appId, setAppId] = useState('')
  /*
   * No office picker and no type picker.
   *
   * The office is taken from the signed-in account by the API — sending one is
   * ignored — so offering a dropdown here would be a control that looks like it
   * decides something and does not. It used to genuinely decide: a City Health
   * officer could raise a requirement the applicant saw as coming from the Fire
   * Office, which then appeared in the fire office's list and not in City
   * Health's own. The office is shown, not chosen.
   *
   * Type is gone for a simpler reason: an Other Requirement is a document
   * request. There was nothing else it could be.
   */
  const user = useAuth((s) => s.user)
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [additionalRemarks, setAdditionalRemarks] = useState('')
  const [reference, setReference] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  /*
   * The recipient, resolved from the chosen application. Shown, not chosen —
   * see the note at the top of this file for why there is nothing else to
   * choose. `applicant` is nullable because User soft-deletes, and `business`
   * is nullable for the same reason on its own table, so both are guarded.
   */
  const selectedApp = apps.find((a) => String(a.id) === appId) ?? null
  const recipientName = selectedApp
    ? (selectedApp.applicant?.name ?? 'The business owner on file')
    : ''
  const recipientLine = selectedApp
    ? `${recipientName} · applicant for ${businessName(selectedApp.business)}`
    : ''

  async function submit() {
    if (!appId || !subject.trim()) return
    setBusy(true)
    setError(null)
    try {
      const created = await requests.create(Number(appId), {
        subject: subject.trim(),
        ...(body.trim() ? { body: body.trim() } : {}),
        ...(dueDate ? { due_date: dueDate } : {}),
        ...(additionalRemarks.trim() ? { additional_remarks: additionalRemarks.trim() } : {}),
        ...(reference ? { reference } : {}),
      })
      onCreated(created)
    } catch (err) {
      setError(toApiError(err).message)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title="Create Other Requirement"
      wide
      cancelLabel="Cancel"
      confirmLabel="Create requirement request"
      onCancel={onClose}
      onConfirm={submit}
      confirmDisabled={busy || !appId || !subject.trim()}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">
        Ask a business owner for a document your office needs.
      </p>
      {error && <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{error}</p>}
      <div className="space-y-4">
        <label className="block">
          <FieldLabel required>Requirement / Document name</FieldLabel>
          <input
            className={inputCls}
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            placeholder="e.g. Health Certificate"
          />
        </label>

        <label className="block">
          <FieldLabel required>Business</FieldLabel>
          <select className={inputCls} value={appId} onChange={(e) => setAppId(e.target.value)}>
            <option value="">Select a business…</option>
            {apps.map((a) => (
              <option key={a.id} value={a.id}>
                {/*
                 * Business name AND number. A tracking ID is minted on submit,
                 * so a draft has none and this read "Nena Sari-Sari Store · " —
                 * a separator pointing at nothing. The number is what tells one
                 * owner's two businesses apart, so it is never dropped silently.
                 */}
                {businessName(a.business)} · {a.tracking_id || 'Draft (not yet filed)'}
              </option>
            ))}
          </select>
        </label>

        {/*
         * readOnly, not disabled: a disabled input is skipped by the keyboard
         * and often unread by screen readers, and this is information the
         * officer needs before they send — WCAG 2.1 AA, and the same rule the
         * review sheet follows for its record fields.
         */}
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel>To (recipient)</FieldLabel>
            <input
              className={inputCls}
              value={recipientLine}
              readOnly
              placeholder="Choose a business above"
              aria-describedby="request-recipient-note"
            />
            <p id="request-recipient-note" className="mt-1.5 text-xs text-ink-secondary">
              Requirements go to the business owner who filed the application.
            </p>
          </label>
          <label className="block">
            <FieldLabel>From office</FieldLabel>
            <input
              className={inputCls}
              value={user?.department?.name ?? 'Your office'}
              readOnly
              aria-describedby="request-office-note"
            />
            <p id="request-office-note" className="mt-1.5 text-xs text-ink-secondary">
              Set automatically from your account, and recorded as a document request.
            </p>
          </label>
        </div>

        <label className="block">
          <FieldLabel>Description / instructions</FieldLabel>
          <textarea
            className={`${inputCls} min-h-28`}
            value={body}
            onChange={(e) => setBody(e.target.value)}
            placeholder="Describe exactly what the owner needs to submit."
          />
        </label>

        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <FieldLabel>Deadline</FieldLabel>
            <input
              type="date"
              className={inputCls}
              value={dueDate}
              onChange={(e) => setDueDate(e.target.value)}
            />
          </label>
          <label className="block">
            <FieldLabel>Attachment / reference file</FieldLabel>
            <input
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              className={inputCls}
              onChange={(e) => setReference(e.target.files?.[0] ?? null)}
            />
            <p className="mt-1.5 text-xs text-ink-secondary">
              Optional — a blank form or a sample, for the owner to work from.
            </p>
          </label>
        </div>

        <label className="block">
          <FieldLabel>Additional remarks</FieldLabel>
          <textarea
            className={`${inputCls} min-h-20`}
            value={additionalRemarks}
            onChange={(e) => setAdditionalRemarks(e.target.value)}
            placeholder="Anything else the owner should know."
          />
        </label>
      </div>
    </ProtoModal>
  )
}

/* ── Page ─────────────────────────────────────────────────────────────── */
export function RequestsPage() {
  const user = useAuth((s) => s.user)
  const isOfficer = Boolean(user?.permissions.includes('request.create'))

  /*
   * Paged, and the total is on the screen.
   *
   * `/requests` has been bounded at 50 rows a page since the lists were capped,
   * and this screen asked for one page and rendered it as though it were the
   * whole feed. BPLO's register holds 124 requests today: 74 of them — every
   * one older than the fiftieth — had no row, no scroll and no control that
   * would reach them, and nothing on the page said so. A reader cannot tell
   * "you have fifty requests" from "we loaded fifty of your requests", which is
   * why the count is stated rather than implied.
   */
  const [page, setPage] = useState(1)
  const [list, setList] = useState<OfficerRequest[]>([])
  const { data, loading, error, reload } = useAsync(() => requests.page({ page }), [page])

  // Append rather than replace, so paging in extends the list being read
  // instead of dropping the reader back at the top. De-duplicated by id: a
  // retried page would otherwise render its rows twice under duplicate keys.
  useEffect(() => {
    if (!data) return
    setList((prev) => {
      if (data.meta.current_page === 1) return data.data
      const seen = new Set(prev.map((r) => r.id))
      return [...prev, ...data.data.filter((r) => !seen.has(r.id))]
    })
  }, [data])

  const total = data?.meta.total ?? 0
  const hasMore = data ? data.meta.current_page < data.meta.last_page : false
  const firstLoad = loading && list.length === 0

  const [openId, setOpenId] = useState<number | null>(null)
  const [composing, setComposing] = useState(false)

  // Officer compose select needs the visible applications.
  const { data: apps } = useAsync<ApplicationListItem[]>(
    () => (isOfficer ? applications.list() : Promise.resolve([])),
    [isOfficer],
  )

  const open = list.find((r) => r.id === openId) ?? null

  function patch(updated: OfficerRequest) {
    setList((prev) => prev.map((r) => (r.id === updated.id ? updated : r)))
  }

  if (open) {
    return (
      <LetterView
        request={open}
        isOfficer={isOfficer}
        onBack={() => setOpenId(null)}
        onUpdated={patch}
      />
    )
  }

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-4 gap-y-2 pb-1">
            {isOfficer && (
              <button
                type="button"
                onClick={() => setComposing(true)}
                className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white shadow-card hover:bg-royal-hover"
              >
                Request
              </button>
            )}
            <SortFilter />
          </span>
        }
      >
        Other Requirements
      </PageTitle>

      {firstLoad ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : list.length === 0 ? (
        <EmptyState
          icon={DownloadIcon}
          title="No requests yet"
          description={
            isOfficer
              ? 'Requests you send to applicants for missing documents appear here.'
              : 'When an office needs more from you, their requests appear here.'
          }
          action={
            isOfficer ? (
              <PillButton onClick={() => setComposing(true)}>New request</PillButton>
            ) : undefined
          }
        />
      ) : (
        <>
          <p className="mb-3 text-sm text-ink-muted">
            Showing {list.length.toLocaleString()} of {total.toLocaleString()}, newest first.
          </p>
          {/*
            A table, because the columns ARE the information.
            
            This was a card list showing sender, subject and status — and never
            the business. One owner with two businesses got two identical rows,
            and an office looking at its queue could not tell which shop a
            "Health Certificate" belonged to without opening it. Business name
            and business number are what keep them apart, so they are columns.
          */}
          <ProtoCard className="overflow-hidden rounded-xl">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[46rem] text-left text-sm">
                <thead>
                  <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                    <th className="px-5 py-3">Business</th>
                    <th className="px-5 py-3">Business No.</th>
                    <th className="px-5 py-3">Requirement</th>
                    <th className="px-5 py-3">{isOfficer ? 'Submitted' : 'Office'}</th>
                    <th className="px-5 py-3">Status</th>
                    <th className="px-5 py-3">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {list.map((r) => {
                    const rowTone = STATUS_TONE[r.status] ?? 'tint-gray'
                    const latest = r.responses?.[r.responses.length - 1]
                    return (
                      <tr key={r.id} className="border-t border-line align-top">
                        <td className="px-5 py-3.5 font-bold text-ink">
                          {businessName(
                            r.application?.business_name ? { name: r.application.business_name } : null,
                          )}
                        </td>
                        <td className="tnum px-5 py-3.5 text-ink-secondary">
                          {r.application?.tracking_id || 'Draft'}
                        </td>
                        <td className="px-5 py-3.5">
                          <span className="block font-semibold text-ink">{r.subject}</span>
                          {r.body && (
                            <span className="mt-0.5 block max-w-md truncate text-xs text-ink-secondary">
                              {r.body}
                            </span>
                          )}
                        </td>
                        <td className="px-5 py-3.5 text-ink-secondary">
                          {/*
                            The office is the useful column for an owner — "who
                            is asking me for this" — and for an office reading
                            its own queue it is always itself, so that side gets
                            the submission date instead.
                          */}
                          {isOfficer
                            ? latest
                              ? formatDate(latest.created_at)
                              : '—'
                            : (r.from_office?.name ?? r.created_by?.department ?? '—')}
                        </td>
                        <td className="px-5 py-3.5">
                          <StatusChip tone={rowTone}>{r.status_label}</StatusChip>
                        </td>
                        <td className="px-5 py-3.5">
                          <button
                            type="button"
                            onClick={() => setOpenId(r.id)}
                            className="rounded-full border border-transparent bg-royal px-4 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
                          >
                            {/* An office with something waiting is being asked to
                                review; everyone else is being offered a read. */}
                            {isOfficer && r.awaits_office ? 'Review' : 'View'}
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </ProtoCard>
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

      {composing && (
        <ComposeModal
          apps={apps ?? []}
          onClose={() => setComposing(false)}
          onCreated={(created) => {
            setList((prev) => [created, ...prev])
            setComposing(false)
          }}
        />
      )}
    </div>
  )
}

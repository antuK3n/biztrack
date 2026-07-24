import { useMemo, useState } from 'react'
import type { SVGProps } from 'react'
import { ArrowLeftIcon, ChevronRightIcon, DownloadIcon } from '../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../components/ui/primitives'
import {
  FieldLabel,
  PageTitle,
  PillButton,
  ProtoModal,
  SortFilter,
  StatusChip,
  inputCls,
} from '../components/ui/Proto'
import type { ChipTone } from '../components/ui/Proto'
import { toApiError } from '../lib/api'
import { formatDate, formatDateTime } from '../lib/format'
import { applications, requests } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'
import type { ApplicationListItem, OfficerRequest, RequestStatus, RequestType } from '../lib/types'

/*
 * Other Requirements — PDF p23–25, now wired to the real /requests feed.
 * Owner view: read a request letter, then respond (textarea + optional file).
 * Officer view (request.create): the same list plus a "Request" compose modal
 * and fulfil/reject close actions on submitted requests.
 */

/* Status chip tone (pending=orange, submitted=royal-ish, fulfilled=green, rejected=red). */
const STATUS_CHIP: Record<RequestStatus, { tone: ChipTone; label: string }> = {
  pending: { tone: 'orange', label: 'Pending' },
  submitted: { tone: 'tint-purple', label: 'Submitted' },
  fulfilled: { tone: 'green', label: 'Fulfilled' },
  rejected: { tone: 'red', label: 'Rejected' },
}

function StatusDot({ status, label }: { status: RequestStatus; label: string }) {
  const dot =
    status === 'pending'
      ? 'bg-s-orange'
      : status === 'fulfilled'
        ? 'bg-s-green'
        : status === 'rejected'
          ? 'bg-s-red'
          : 'bg-royal'
  return (
    <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-secondary">
      <span className={`h-2 w-2 shrink-0 rounded-full ${dot}`} aria-hidden="true" />
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

  const chip = STATUS_CHIP[request.status]
  const canRespond = !isOfficer && request.status === 'pending'
  const canClose = isOfficer && request.status === 'submitted'

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

  async function close(outcome: 'fulfilled' | 'rejected') {
    setBusy(true)
    setError(null)
    try {
      onUpdated(await requests.close(request.id, outcome))
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
          <StatusChip tone={chip.tone}>{chip.label}</StatusChip>
        </div>

        <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <AvatarCircle />
            <div>
              <span className="block text-lg font-bold text-ink">{request.created_by.name}</span>
              {request.created_by.department && (
                <span className="block text-xs italic text-ink-muted">{request.created_by.department}</span>
              )}
            </div>
          </div>
          <span className="text-sm italic text-ink-muted">{formatDateTime(request.created_at)}</span>
        </div>

        <div className="mt-6 space-y-3 pl-0 text-[15px] leading-relaxed text-ink sm:pl-16">
          <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
            {request.request_type === 'document' ? 'Document request' : 'Message'} · re{' '}
            {request.application.business_name} ({request.application.tracking_id})
          </p>
          <p className="whitespace-pre-wrap">{request.body}</p>
        </div>

        {request.response_body && (
          <div className="mt-6 rounded-xl bg-input px-5 py-4 sm:ml-16">
            <p className="text-xs font-bold uppercase tracking-wide text-royal">Applicant response</p>
            <p className="mt-1.5 whitespace-pre-wrap text-sm text-ink">{request.response_body}</p>
            {request.responded_at && (
              <p className="mt-2 text-xs italic text-ink-muted">
                Responded {formatDateTime(request.responded_at)}
              </p>
            )}
          </div>
        )}

        {error && <p className="mt-4 text-sm font-medium text-s-red">{error}</p>}

        <div className="mt-8 flex flex-wrap items-center gap-4 sm:pl-16">
          {canRespond && !replying && (
            <PillButton onClick={() => setReplying(true)} className="px-9">
              Respond
            </PillButton>
          )}
          {canClose && (
            <>
              <button
                type="button"
                onClick={() => close('fulfilled')}
                disabled={busy}
                className="rounded-md bg-s-green px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:brightness-110 disabled:opacity-60"
              >
                {busy ? 'Working…' : 'Mark fulfilled'}
              </button>
              <button
                type="button"
                onClick={() => close('rejected')}
                disabled={busy}
                className="rounded-md bg-s-red px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:brightness-110 disabled:opacity-60"
              >
                Reject
              </button>
            </>
          )}
          {!canRespond && !canClose && (
            <StatusDot status={request.status} label={`Status: ${chip.label}`} />
          )}
        </div>
      </div>

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
  const [type, setType] = useState<RequestType>('document')
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit() {
    if (!appId || !subject.trim() || !body.trim()) return
    setBusy(true)
    setError(null)
    try {
      const created = await requests.create(Number(appId), {
        request_type: type,
        subject: subject.trim(),
        body: body.trim(),
      })
      onCreated(created)
    } catch (err) {
      setError(toApiError(err).message)
      setBusy(false)
    }
  }

  return (
    <ProtoModal
      title="Request"
      wide
      cancelLabel="Cancel"
      confirmLabel="Send request"
      onCancel={onClose}
      onConfirm={submit}
      confirmDisabled={busy || !appId || !subject.trim() || !body.trim()}
    >
      <p className="mb-5 border-b border-line pb-3 text-sm text-ink-secondary">
        Ask an applicant for a document or send them a message.
      </p>
      {error && <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">{error}</p>}
      <div className="space-y-4">
        <label className="block">
          <FieldLabel required>Application</FieldLabel>
          <select className={inputCls} value={appId} onChange={(e) => setAppId(e.target.value)}>
            <option value="">Select an application…</option>
            {apps.map((a) => (
              <option key={a.id} value={a.id}>
                {a.business.name} · {a.tracking_id}
              </option>
            ))}
          </select>
        </label>
        <label className="block">
          <FieldLabel required>Type</FieldLabel>
          <select className={inputCls} value={type} onChange={(e) => setType(e.target.value as RequestType)}>
            <option value="document">Document request</option>
            <option value="message">Message</option>
          </select>
        </label>
        <label className="block">
          <FieldLabel required>Subject</FieldLabel>
          <input
            className={inputCls}
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
            placeholder="e.g. Additional Documents Required"
          />
        </label>
        <label className="block">
          <FieldLabel required>Body</FieldLabel>
          <textarea
            className={`${inputCls} min-h-32`}
            value={body}
            onChange={(e) => setBody(e.target.value)}
            placeholder="Describe what the applicant needs to do."
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

  const { data, loading, error, reload, setData } = useAsync(() => requests.list(), [])
  const list = useMemo(() => data ?? [], [data])

  const [openId, setOpenId] = useState<number | null>(null)
  const [composing, setComposing] = useState(false)

  // Officer compose select needs the visible applications.
  const { data: apps } = useAsync<ApplicationListItem[]>(
    () => (isOfficer ? applications.list() : Promise.resolve([])),
    [isOfficer],
  )

  const open = list.find((r) => r.id === openId) ?? null

  function patch(updated: OfficerRequest) {
    setData((prev) => (prev ?? []).map((r) => (r.id === updated.id ? updated : r)))
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
          <span className="flex items-center gap-4 pb-1">
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

      {loading ? (
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
        <ul className="flex flex-col gap-4">
          {list.map((r) => {
            const chip = STATUS_CHIP[r.status]
            return (
              <li key={r.id}>
                <button
                  type="button"
                  onClick={() => setOpenId(r.id)}
                  className="flex w-full items-center gap-5 rounded-xl bg-white px-5 py-4 text-left shadow-card transition-shadow hover:shadow-raised"
                >
                  <AvatarCircle />
                  <span className="w-44 shrink-0 truncate text-[15px] font-bold text-ink">
                    {r.created_by.name}
                  </span>
                  <span className="min-w-0 flex-1 truncate text-[15px] text-ink">
                    <span className="font-bold">{r.subject} - </span>
                    {r.body}
                  </span>
                  <StatusDot status={r.status} label={chip.label} />
                  <span className="hidden shrink-0 text-sm italic text-ink-muted sm:inline">
                    {formatDate(r.created_at)}
                  </span>
                  <ChevronRightIcon size={18} className="shrink-0 text-ink-secondary" />
                </button>
              </li>
            )
          })}
        </ul>
      )}

      {composing && (
        <ComposeModal
          apps={apps ?? []}
          onClose={() => setComposing(false)}
          onCreated={(created) => {
            setData((prev) => [created, ...(prev ?? [])])
            setComposing(false)
          }}
        />
      )}
    </div>
  )
}

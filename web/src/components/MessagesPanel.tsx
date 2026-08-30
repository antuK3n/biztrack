import { useEffect, useRef, useState } from 'react'
import type { SVGProps } from 'react'
import { DownloadIcon } from './icons'
import { toApiError } from '../lib/api'
import { formatBytes, formatDateTime } from '../lib/format'
import { messages as messagesApi } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'
import type { Message, MessageOffice, MessageTranscriptMeta } from '../lib/types'

function PaperclipIcon({ size = 18, ...props }: SVGProps<SVGSVGElement> & { size?: number }) {
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
      <path d="M21 8.5 12 17.5a4 4 0 0 1-5.7-5.7l8.5-8.5a2.6 2.6 0 0 1 3.7 3.7l-8.5 8.5a1.2 1.2 0 0 1-1.7-1.7l7.8-7.8" />
    </svg>
  )
}

/*
 * Shared message thread, used by the applicant ApplicationDetailPage, the
 * officer ReviewPage and the dedicated Messages page. Chat-style: the viewer's
 * own bubbles are right-aligned royal and attributed to "You", the other
 * party's are left-aligned white cards behind an avatar and carry the sender's
 * name and role (p52 attribution language). Sender identity is the user id, so
 * the same thread mirrors exactly for whoever is reading it. Polls every 30s
 * while mounted.
 *
 * A conversation is with an OFFICE, not with a filing — the client's
 * "make sure the business owner can only contact the correct offices". So the
 * screen has to say which office, and offer the applicant a choice between the
 * offices ACTUALLY on their filing. `meta.offices` is that list and it comes
 * from the API, not from a client-side filter over every department in the
 * city: the server refuses a message to an office that is not on the filing, so
 * anything this component offers beyond that list would be an option that only
 * produces an error.
 *
 * An officer sees one office — their own — so the picker collapses to a line
 * naming it. That line is not decoration: an officer reading a conversation
 * needs to know they are reading their office's, not the filing's.
 */

const POLL_MS = 30_000

/** Person glyph in a royal circle, the prototype's attribution avatar (p56/p71). */
function SenderAvatar() {
  return (
    <span
      aria-hidden="true"
      className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-royal text-white"
    >
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-4 0-7 2-7 4.5V20h14v-1.5C19 16 16 14 12 14Z" />
      </svg>
    </span>
  )
}

function AttachmentChip({
  attachment,
  mine,
  onError,
}: {
  attachment: Message['attachments'][number]
  mine: boolean
  onError: (message: string) => void
}) {
  const name = attachment.original_filename

  // The API needs the bearer token, so both actions fetch the file first: a
  // plain link would open the 401 JSON envelope in a new tab.
  function view() {
    const tab = window.open('', '_blank')
    messagesApi.attachmentView(attachment.id, tab).catch((err) => {
      tab?.close()
      onError(toApiError(err).message)
    })
  }

  function save() {
    messagesApi.attachmentDownload(attachment.id, name).catch((err) => {
      onError(toApiError(err).message)
    })
  }

  const tone = mine
    ? 'border-white/40 bg-white/15 text-white hover:bg-white/25'
    : 'border-line bg-canvas text-ink-secondary hover:bg-royal-tint'

  return (
    <span className={`mt-2 inline-flex max-w-full items-center gap-1 rounded-lg border ${tone}`}>
      <button
        type="button"
        onClick={view}
        title={`View ${name}`}
        className="flex min-w-0 items-center gap-2 py-1.5 pl-3 text-xs font-medium underline underline-offset-2"
      >
        <PaperclipIcon size={14} className="shrink-0" />
        <span className="truncate">{name}</span>
      </button>
      <button
        type="button"
        onClick={save}
        aria-label={`Download ${name}`}
        title={`Download ${name}`}
        className="py-1.5 pr-3 pl-1"
      >
        <DownloadIcon size={14} className="shrink-0" />
      </button>
    </span>
  )
}

/** What to call the other party in the thread, from the viewer's seat. */
function roleLabel(sender: Message['sender']): string {
  return sender.is_officer ? 'Officer' : 'Applicant'
}

function Bubble({
  message,
  mine,
  onAttachmentError,
}: {
  message: Message
  mine: boolean
  onAttachmentError: (message: string) => void
}) {
  return (
    <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
      <div className="max-w-[80%] min-w-0">
        {mine ? (
          <p className="mb-1 text-right text-xs font-semibold text-ink-secondary">You</p>
        ) : (
          <div className="mb-1 flex items-center gap-2">
            <SenderAvatar />
            <p className="min-w-0 truncate text-xs font-bold text-royal">
              {message.sender.name}
              <span className="ml-1 font-normal italic text-ink-muted">
                · {roleLabel(message.sender)}
              </span>
            </p>
          </div>
        )}
        <div
          className={`rounded-2xl px-4 py-2.5 text-sm ${
            mine
              ? 'rounded-br-sm bg-royal text-white'
              : 'rounded-bl-sm border border-line bg-white text-ink'
          }`}
        >
          {message.body && <p className="whitespace-pre-wrap break-words">{message.body}</p>}
          {message.attachments.map((a) => (
            <AttachmentChip key={a.id} attachment={a} mine={mine} onError={onAttachmentError} />
          ))}
        </div>
        <p className={`mt-1 text-[11px] text-ink-muted ${mine ? 'text-right' : 'text-left'}`}>
          {formatDateTime(message.created_at)}
        </p>
      </div>
    </div>
  )
}

/**
 * Which office this conversation is with — and, when there is a choice, the
 * choice itself.
 *
 * One office is a sentence, not a control: an officer has exactly one
 * conversation on a filing and giving them a single dead button to press would
 * be a control that does nothing. Several offices is a real choice, so it gets
 * real buttons, each carrying how much has been said to that office — an
 * applicant deciding who to chase wants to see which office they have already
 * written to twice.
 */
function OfficePicker({
  offices,
  activeId,
  onPick,
}: {
  offices: MessageOffice[]
  activeId: number | null
  onPick: (departmentId: number) => void
}) {
  if (offices.length === 0) return null

  if (offices.length === 1) {
    return (
      <p className="mb-3 text-xs font-semibold text-royal">
        Conversation with <span className="font-bold">{offices[0].name}</span>
      </p>
    )
  }

  return (
    <div className="mb-3">
      <p id="message-office-label" className="mb-1.5 text-xs font-semibold text-ink-secondary">
        Which office is this about?
      </p>
      <div role="group" aria-labelledby="message-office-label" className="flex flex-wrap gap-2">
        {offices.map((office) => {
          const active = office.department_id === activeId
          return (
            <button
              key={office.department_id}
              type="button"
              onClick={() => onPick(office.department_id)}
              aria-pressed={active}
              /*
               * Spelled out for a screen reader, because the visible label is
               * a name with a bare number beside it — "Office of the Building
               * Official 6" is read as one string and sounds like a room
               * number rather than a message count.
               */
              aria-label={
                office.messages_count > 0
                  ? `${office.name}, ${office.messages_count} messages`
                  : `${office.name}, no messages yet`
              }
              className={`rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors ${
                active
                  ? 'bg-royal text-white'
                  : 'bg-royal-tint text-royal hover:bg-royal/15'
              }`}
            >
              {office.name}
              {office.messages_count > 0 && (
                <span className={active ? 'ml-1.5 text-white/80' : 'ml-1.5 text-ink-secondary'}>
                  {office.messages_count}
                </span>
              )}
            </button>
          )
        })}
      </div>
    </div>
  )
}

/**
 * The conversation itself: scrollback plus composer, with no chrome of its own.
 * The in-page panel wraps it in its card; the dedicated Messages page drops it
 * straight into the right-hand pane.
 */
export function MessageThreadView({
  applicationId,
  className = '',
  scrollClassName = 'max-h-96',
  onSent,
}: {
  applicationId: number
  className?: string
  scrollClassName?: string
  onSent?: () => void
}) {
  const user = useAuth((s) => s.user)
  const viewerIsOfficer = Boolean(user?.permissions.includes('application.view_all'))

  /*
   * Outbound is "this account sent it". The old side-of-the-house test
   * (officer vs applicant) mislabelled anyone whose seat did not match their
   * department — the super admin has no department, so their own messages came
   * back as inbound. Fall back to it only when the session has not loaded.
   */
  const isMine = (message: Message) =>
    user ? message.sender.id === user.id : message.sender.is_officer === viewerIsOfficer

  /*
   * Null means "whatever the API opens on". The first response names the
   * offices; the effect below then pins the busiest one, so the applicant lands
   * in the conversation that has moved most recently rather than on a merged
   * view of all of them. Pinning it in state rather than deriving it is what
   * lets them switch away and stay switched.
   */
  const [officeId, setOfficeId] = useState<number | null>(null)

  const { data, loading, error, reload, setData } = useAsync<{
    data: Message[]
    meta: MessageTranscriptMeta
  }>(() => messagesApi.listWithMeta(applicationId, officeId), [applicationId, officeId])

  const [body, setBody] = useState('')
  const [attachment, setAttachment] = useState<File | null>(null)
  const [sending, setSending] = useState(false)
  const [sendError, setSendError] = useState<string | null>(null)
  const endRef = useRef<HTMLDivElement | null>(null)

  // 30s polling while mounted — refresh the thread quietly.
  useEffect(() => {
    const timer = setInterval(() => reload(), POLL_MS)
    return () => clearInterval(timer)
  }, [reload])

  const list = data?.data ?? []
  const offices = data?.meta.offices ?? []
  // Depended on by id and not by the array: `offices` is a fresh array on every
  // render, and an effect that watches it would re-run for ever.
  const firstOfficeId = offices.length > 0 ? offices[0].department_id : null
  const active = offices.find((o) => o.department_id === officeId) ?? null

  useEffect(() => {
    if (officeId === null && firstOfficeId !== null) setOfficeId(firstOfficeId)
  }, [officeId, firstOfficeId])

  /*
   * An office that has come off the filing keeps its correspondence readable
   * and closes to new messages. Saying so is better than a disabled box with no
   * explanation, and far better than letting them type a message the API will
   * refuse.
   */
  const closed = active !== null && !active.can_message

  // Keep the newest message in view when the thread grows.
  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'nearest' })
  }, [list.length])

  async function send() {
    const text = body.trim()
    if (!text && !attachment) return
    // Belt and braces with the disabled controls: Enter still fires the handler
    // on a textarea some browsers let you focus while disabled.
    if (closed) return
    setSending(true)
    setSendError(null)
    try {
      const sent = await messagesApi.send(applicationId, text, attachment, officeId)
      /*
       * Append rather than refetch, so the message appears instantly — but only
       * onto a transcript that has loaded. With nothing to append to (the first
       * fetch failed and the reader retried by sending) a refetch is the only
       * way to get a coherent `meta`, and inventing one would put a made-up
       * office list on the screen.
       */
      if (data) setData({ ...data, data: [...data.data, sent] })
      else reload()
      setBody('')
      setAttachment(null)
      onSent?.()
    } catch (err) {
      setSendError(toApiError(err).message)
    } finally {
      setSending(false)
    }
  }

  return (
    <div className={`flex min-h-0 flex-col ${className}`}>
      <OfficePicker offices={offices} activeId={officeId} onPick={setOfficeId} />

      <div className={`flex-1 space-y-4 overflow-y-auto pr-1 ${scrollClassName}`}>
        {loading ? (
          <p className="py-6 text-center text-sm text-ink-muted">Loading messages…</p>
        ) : error ? (
          <p className="py-6 text-center text-sm text-s-red">
            {toApiError(error).message}{' '}
            <button type="button" onClick={reload} className="font-semibold underline">
              Retry
            </button>
          </p>
        ) : list.length === 0 ? (
          <p className="py-8 text-center text-sm text-ink-muted">
            {active
              ? `No messages yet. Start the conversation with ${active.name} below.`
              : 'No messages yet. Start the conversation below.'}
          </p>
        ) : (
          list.map((m) => (
            <Bubble key={m.id} message={m} mine={isMine(m)} onAttachmentError={setSendError} />
          ))
        )}
        <div ref={endRef} />
      </div>

      <div className="mt-4 border-t border-line pt-4">
        {closed && (
          <p className="mb-2 text-xs font-medium text-ink-secondary">
            {active?.name} is no longer handling this application. You can still read what was
            said, but not reply.
          </p>
        )}
        {sendError && <p className="mb-2 text-xs font-medium text-s-red">{sendError}</p>}
        {attachment && (
          <div className="mb-2 flex items-center gap-2 text-xs text-ink-secondary">
            <PaperclipIcon size={14} />
            <span className="truncate">
              {attachment.name} · {formatBytes(attachment.size)}
            </span>
            <button
              type="button"
              onClick={() => setAttachment(null)}
              className="font-semibold text-s-red underline"
            >
              Remove
            </button>
          </div>
        )}
        <div className="flex items-end gap-2.5">
          <textarea
            value={body}
            onChange={(e) => setBody(e.target.value)}
            onKeyDown={(e) => {
              // Enter sends (so does the old Cmd/Ctrl+Enter habit);
              // Shift+Enter writes a new line. Never cut an IME composition
              // short — Enter is how those candidates get committed.
              if (e.key !== 'Enter' || e.shiftKey || e.nativeEvent.isComposing) return
              e.preventDefault()
              void send()
            }}
            rows={2}
            disabled={closed}
            placeholder={active ? `Write to ${active.name}…` : 'Write a message…'}
            aria-label={active ? `Message to ${active.name}` : 'Message'}
            aria-describedby="message-send-hint"
            className="min-w-0 flex-1 resize-none rounded-lg border border-input-border bg-white px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
          />
          <label
            className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-input-border bg-white text-ink-secondary ${
              closed ? 'opacity-60' : 'cursor-pointer hover:bg-canvas'
            }`}
            aria-label="Attach a file"
          >
            <PaperclipIcon size={18} />
            <input
              type="file"
              className="sr-only"
              disabled={closed}
              onChange={(e) => {
                setAttachment(e.target.files?.[0] ?? null)
                e.target.value = ''
              }}
            />
          </label>
          <button
            type="button"
            onClick={() => void send()}
            disabled={closed || sending || (!body.trim() && !attachment)}
            className="h-10 shrink-0 rounded-lg bg-royal px-5 text-sm font-semibold text-white hover:bg-royal-hover disabled:opacity-60"
          >
            {sending ? 'Sending…' : 'Send'}
          </button>
        </div>
        <p id="message-send-hint" className="mt-2 text-[11px] text-ink-muted">
          Press Enter to send. Shift + Enter starts a new line.
        </p>
      </div>
    </div>
  )
}

/** Per-application thread as a section of the application/review screen. */
export function MessagesPanel({ applicationId }: { applicationId: number }) {
  return (
    <section className="mt-10">
      <div className="mb-4 border-b border-ink/50 pb-2">
        <h2 className="text-xl font-bold text-ink">Messages</h2>
      </div>

      <div className="rounded-2xl bg-canvas/60 p-4 shadow-card sm:p-5">
        <MessageThreadView applicationId={applicationId} />
      </div>
    </section>
  )
}

import { useEffect, useRef, useState } from 'react'
import type { SVGProps } from 'react'
import { DownloadIcon } from './icons'
import { toApiError } from '../lib/api'
import { formatBytes, formatDateTime } from '../lib/format'
import { messages as messagesApi } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import { useAuth } from '../stores/auth'
import type { Message } from '../lib/types'

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
 * Shared per-application message thread (v2 messaging contract), used by the
 * applicant ApplicationDetailPage and the officer ReviewPage. Chat-style: the
 * viewer's own bubbles are right-aligned royal, the other party's are white
 * (p52 attribution language). Polls every 30s while mounted.
 */

const POLL_MS = 30_000

function AttachmentChip({
  attachment,
}: {
  attachment: Message['attachments'][number]
}) {
  return (
    <a
      href={attachment.download_url}
      target="_blank"
      rel="noopener noreferrer"
      className="mt-2 inline-flex max-w-full items-center gap-2 rounded-lg border border-white/40 bg-white/15 px-3 py-1.5 text-xs font-medium hover:bg-white/25"
    >
      <PaperclipIcon size={14} className="shrink-0" />
      <span className="truncate">{attachment.original_filename}</span>
      <DownloadIcon size={14} className="shrink-0" />
    </a>
  )
}

function Bubble({ message, mine }: { message: Message; mine: boolean }) {
  return (
    <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
      <div className="max-w-[80%]">
        <div
          className={`rounded-2xl px-4 py-2.5 text-sm ${
            mine
              ? 'rounded-br-sm bg-royal text-white'
              : 'rounded-bl-sm border border-line bg-white text-ink'
          }`}
        >
          {!mine && (
            <p className="mb-0.5 text-xs font-bold text-royal">
              {message.sender.name}
              {message.sender.is_officer && (
                <span className="ml-1 font-normal italic text-ink-muted">· Officer</span>
              )}
            </p>
          )}
          {message.body && <p className="whitespace-pre-wrap break-words">{message.body}</p>}
          {message.attachments.map((a) => (
            <AttachmentChip key={a.id} attachment={a} />
          ))}
        </div>
        <p className={`mt-1 text-[11px] text-ink-muted ${mine ? 'text-right' : 'text-left'}`}>
          {formatDateTime(message.created_at)}
        </p>
      </div>
    </div>
  )
}

export function MessagesPanel({ applicationId }: { applicationId: number }) {
  const user = useAuth((s) => s.user)
  const viewerIsOfficer = Boolean(user?.permissions.includes('application.view_all'))

  const { data, loading, error, reload, setData } = useAsync<Message[]>(
    () => messagesApi.list(applicationId),
    [applicationId],
  )

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

  const list = data ?? []

  // Keep the newest message in view when the thread grows.
  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'nearest' })
  }, [list.length])

  async function send() {
    const text = body.trim()
    if (!text && !attachment) return
    setSending(true)
    setSendError(null)
    try {
      const sent = await messagesApi.send(applicationId, text, attachment)
      setData((prev) => [...(prev ?? []), sent])
      setBody('')
      setAttachment(null)
    } catch (err) {
      setSendError(toApiError(err).message)
    } finally {
      setSending(false)
    }
  }

  return (
    <section className="mt-10">
      <div className="mb-4 border-b border-ink/50 pb-2">
        <h2 className="text-xl font-bold text-ink">Messages</h2>
      </div>

      <div className="rounded-2xl bg-canvas/60 p-4 shadow-card sm:p-5">
        <div className="max-h-96 space-y-4 overflow-y-auto pr-1">
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
              No messages yet. Start the conversation below.
            </p>
          ) : (
            list.map((m) => (
              <Bubble key={m.id} message={m} mine={m.sender.is_officer === viewerIsOfficer} />
            ))
          )}
          <div ref={endRef} />
        </div>

        <div className="mt-4 border-t border-line pt-4">
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
                if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                  e.preventDefault()
                  void send()
                }
              }}
              rows={2}
              placeholder="Write a message…"
              aria-label="Message"
              className="min-w-0 flex-1 resize-none rounded-lg border border-input-border bg-white px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            <label
              className="flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-input-border bg-white text-ink-secondary hover:bg-canvas"
              aria-label="Attach a file"
            >
              <PaperclipIcon size={18} />
              <input
                type="file"
                className="sr-only"
                onChange={(e) => {
                  setAttachment(e.target.files?.[0] ?? null)
                  e.target.value = ''
                }}
              />
            </label>
            <button
              type="button"
              onClick={() => void send()}
              disabled={sending || (!body.trim() && !attachment)}
              className="h-10 shrink-0 rounded-lg bg-royal px-5 text-sm font-semibold text-white hover:bg-royal-hover disabled:opacity-60"
            >
              {sending ? 'Sending…' : 'Send'}
            </button>
          </div>
        </div>
      </div>
    </section>
  )
}

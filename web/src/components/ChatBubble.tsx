import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../lib/api'
import { PlusIcon, XIcon } from './icons'

/* Chatbot bubble + slide-in panel (owner screens, p7-p8). Rule-based assistant
 * backed by /chatbot/messages; one conversation per user.
 *
 * The thread lives on the server, so this component only mirrors it. Two things
 * used to make it look like it did not:
 *   - history was fetched once and marked loaded even when the request failed,
 *     so a single blip left the panel blank for the rest of the page load;
 *   - the response replaced state wholesale, so a turn sent while the fetch was
 *     still in flight vanished from view the moment the fetch landed.
 * History now loads on mount, retries on the next open if it failed, and merges
 * by message id rather than overwriting. */

type ChatMessage = {
  id: number | string
  sender: 'user' | 'bot'
  body: string
}

/**
 * Server history plus anything sent since the request went out. Turns already
 * saved server-side arrive with a real id, so matching on id keeps a message
 * that raced the fetch without ever showing it twice.
 */
function mergeThread(history: ChatMessage[], local: ChatMessage[]): ChatMessage[] {
  const known = new Set(history.map((m) => String(m.id)))
  return [...history, ...local.filter((m) => !known.has(String(m.id)))]
}

const WELCOME =
  "Kumusta! I'm the BizTrack assistant. Ask me about one permit at a time (requirements, fees, processing time, renewal), or what a field on an application form is for, and I'll keep the answer short. You can also send me a tracking number like BIZ-2026-00123."

/* Starter questions, shown only on an empty thread. They double as a hint that
 * naming the permit gets a scoped answer instead of a rundown of all of them,
 * and that a question about a box on a form is fair game too. */
const STARTERS = [
  'Requirements for a sanitary permit',
  'How much is the fire safety fee?',
  'What is the water source field for?',
  'Where is my application?',
]

function MessageRow({ message }: { message: ChatMessage }) {
  const mine = message.sender === 'user'
  return (
    <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
      <div
        className={`max-w-[85%] whitespace-pre-wrap break-words rounded-xl px-3 py-2 text-sm shadow-card ${
          mine ? 'bg-royal text-white' : 'bg-white/85 text-ink'
        }`}
      >
        {message.body}
      </div>
    </div>
  )
}

function TypingDots() {
  return (
    <div className="flex justify-start" aria-label="BizTrack ChatBot is typing">
      <div className="flex items-center gap-1 rounded-xl bg-white/85 px-3 py-2.5 shadow-card">
        {[0, 1, 2].map((i) => (
          <span
            key={i}
            className="h-1.5 w-1.5 animate-pulse rounded-full bg-royal/60"
            style={{ animationDelay: `${i * 150}ms` }}
          />
        ))}
      </div>
    </div>
  )
}

export function ChatBubble() {
  const [open, setOpen] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [draft, setDraft] = useState('')
  const [sending, setSending] = useState(false)
  const scrollRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  /* History loads on mount, so the thread is already there when the panel opens.
   * loaded is set on success only: a failed load leaves it false so the next
   * open tries again instead of showing an empty thread for the whole session. */
  useEffect(() => {
    if (loaded) return
    let cancelled = false
    api
      .get<{ data: ChatMessage[] }>('/chatbot/messages')
      .then((res) => {
        if (cancelled) return
        setMessages((prev) => mergeThread(res.data.data, prev))
        setLoaded(true)
      })
      .catch(() => {
        /* Leave loaded false: opening the panel retries. */
      })
    return () => {
      cancelled = true
    }
  }, [loaded, attempt])

  // Retry a history load that never landed, each time the panel is opened.
  useEffect(() => {
    if (open && !loaded) setAttempt((n) => n + 1)
  }, [open, loaded])

  useEffect(() => {
    const el = scrollRef.current
    if (el) el.scrollTop = el.scrollHeight
  }, [messages, sending, open])

  async function send(body: string) {
    if (!body || sending) return
    const pendingId = `tmp-${Date.now()}`
    setMessages((prev) => [...prev, { id: pendingId, sender: 'user', body }])
    setSending(true)
    try {
      const res = await api.post<{ data: ChatMessage; meta?: { user_message?: ChatMessage } }>(
        '/chatbot/messages',
        { message: body },
      )
      const asked = res.data.meta?.user_message
      // Take the saved id for the bubble we drew ahead of the round trip, so a
      // history load landing later recognises this turn instead of repeating it.
      setMessages((prev) => [
        ...prev.map((m) => (m.id === pendingId && asked ? asked : m)),
        res.data.data,
      ])
    } catch {
      setMessages((prev) => [
        ...prev,
        {
          id: `err-${Date.now()}`,
          sender: 'bot',
          body: "Sorry, I couldn't reach the assistant just now. Please try again, or message your assigned office from any application.",
        },
      ])
    } finally {
      setSending(false)
      inputRef.current?.focus()
    }
  }

  function onSubmit(e: FormEvent) {
    e.preventDefault()
    const body = draft.trim()
    if (!body) return
    setDraft('')
    void send(body)
  }

  return (
    <>
      {/* The bubble hides while the panel is open: it used to sit on top of the
       * send button. Closing is done from the panel header. */}
      {!open && (
        <button
          type="button"
          aria-label="Open BizTrack ChatBot"
          onClick={() => setOpen(true)}
          className="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-royal shadow-raised transition-transform hover:scale-105"
        >
          <svg viewBox="0 0 24 24" width={26} height={26} fill="none" aria-hidden="true">
            <path
              d="M4 6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H9l-4.2 3.36A.75.75 0 0 1 3.6 18.8V16A3 3 0 0 1 4 13V6Z"
              fill="#fff"
            />
          </svg>
        </button>
      )}
      {open && (
        <div className="fixed bottom-0 right-0 top-0 z-30 flex w-80 flex-col bg-chatbody shadow-overlay">
          <div className="flex items-center justify-between bg-royal px-4 py-3">
            <span className="flex items-center gap-2 text-sm font-bold text-white">
              <span className="flex h-6 w-6 items-center justify-center rounded bg-white/20 text-[10px]">🤖</span>
              BizTrack ChatBot
            </span>
            <button type="button" aria-label="Close BizTrack ChatBot" onClick={() => setOpen(false)}>
              <XIcon size={18} className="text-white" />
            </button>
          </div>
          <div ref={scrollRef} className="flex flex-1 flex-col gap-2 overflow-y-auto p-4">
            <MessageRow message={{ id: 'welcome', sender: 'bot', body: WELCOME }} />
            {messages.map((m) => (
              <MessageRow key={m.id} message={m} />
            ))}
            {sending && <TypingDots />}
            {loaded && messages.length === 0 && !sending && (
              <div className="flex flex-col items-start gap-1.5 pt-1">
                {STARTERS.map((s) => (
                  <button
                    key={s}
                    type="button"
                    onClick={() => void send(s)}
                    className="rounded-full border border-royal/30 bg-white/70 px-3 py-1.5 text-left text-xs font-medium text-royal transition-colors hover:bg-white"
                  >
                    {s}
                  </button>
                ))}
              </div>
            )}
          </div>
          <form onSubmit={onSubmit} className="flex items-center gap-2 p-3">
            <span className="flex h-8 w-8 items-center justify-center rounded-full bg-white/70 text-royal">
              <PlusIcon size={18} />
            </span>
            <input
              ref={inputRef}
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              aria-label="Message BizTrack ChatBot"
              placeholder="Type here…"
              className="h-9 flex-1 rounded-full bg-white/85 px-4 text-sm outline-none"
            />
            <button
              type="submit"
              aria-label="Send"
              disabled={sending || !draft.trim()}
              className="flex h-8 w-8 items-center justify-center rounded-full bg-royal text-white disabled:opacity-40"
            >
              <svg viewBox="0 0 24 24" width={14} height={14} fill="currentColor" aria-hidden="true">
                <path d="M3.4 20.4 21.8 12 3.4 3.6l-.01 6.53L15 12 3.39 13.87l.01 6.53Z" />
              </svg>
            </button>
          </form>
        </div>
      )}
    </>
  )
}

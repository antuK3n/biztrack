import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { api } from '../lib/api'
import { PlusIcon, XIcon } from './icons'

/* Chatbot bubble + slide-in panel (owner screens, p7-p8). Rule-based assistant
 * backed by /chatbot/messages; one conversation per user. */

type ChatMessage = {
  id: number | string
  sender: 'user' | 'bot'
  body: string
}

const WELCOME =
  "Kumusta! I'm the BizTrack assistant. Ask me about requirements, fees, payments, renewal deadlines, or where your application is. You can also send me a tracking number like BIZ-2026-00123."

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
  const [messages, setMessages] = useState<ChatMessage[]>([])
  const [draft, setDraft] = useState('')
  const [sending, setSending] = useState(false)
  const scrollRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  // History loads once, on first open.
  useEffect(() => {
    if (!open || loaded) return
    let cancelled = false
    api
      .get<{ data: ChatMessage[] }>('/chatbot/messages')
      .then((res) => {
        if (!cancelled) setMessages(res.data.data)
      })
      .catch(() => {
        /* History is a nicety; the welcome bubble still shows. */
      })
      .finally(() => {
        if (!cancelled) setLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [open, loaded])

  useEffect(() => {
    const el = scrollRef.current
    if (el) el.scrollTop = el.scrollHeight
  }, [messages, sending, open])

  async function send(e: FormEvent) {
    e.preventDefault()
    const body = draft.trim()
    if (!body || sending) return
    setDraft('')
    setMessages((prev) => [...prev, { id: `tmp-${Date.now()}`, sender: 'user', body }])
    setSending(true)
    try {
      const res = await api.post<{ data: ChatMessage }>('/chatbot/messages', { message: body })
      setMessages((prev) => [...prev, res.data.data])
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

  return (
    <>
      <button
        type="button"
        aria-label={open ? 'Close BizTrack ChatBot' : 'Open BizTrack ChatBot'}
        onClick={() => setOpen((v) => !v)}
        className="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-royal shadow-raised transition-transform hover:scale-105"
      >
        {open ? (
          <XIcon size={24} className="text-white" />
        ) : (
          <svg viewBox="0 0 24 24" width={26} height={26} fill="none" aria-hidden="true">
            <path
              d="M4 6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H9l-4.2 3.36A.75.75 0 0 1 3.6 18.8V16A3 3 0 0 1 4 13V6Z"
              fill="#fff"
            />
          </svg>
        )}
      </button>
      {open && (
        <div className="fixed bottom-0 right-0 top-0 z-30 flex w-80 flex-col bg-chatbody shadow-overlay">
          <div className="flex items-center justify-between bg-royal px-4 py-3">
            <span className="flex items-center gap-2 text-sm font-bold text-white">
              <span className="flex h-6 w-6 items-center justify-center rounded bg-white/20 text-[10px]">🤖</span>
              BizTrack ChatBot
            </span>
            <button type="button" aria-label="Close" onClick={() => setOpen(false)}>
              <XIcon size={18} className="text-white" />
            </button>
          </div>
          <div ref={scrollRef} className="flex flex-1 flex-col gap-2 overflow-y-auto p-4">
            <MessageRow message={{ id: 'welcome', sender: 'bot', body: WELCOME }} />
            {messages.map((m) => (
              <MessageRow key={m.id} message={m} />
            ))}
            {sending && <TypingDots />}
          </div>
          <form onSubmit={send} className="flex items-center gap-2 p-3">
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

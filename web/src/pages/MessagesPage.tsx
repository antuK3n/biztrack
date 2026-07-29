import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { MailIcon, SearchIcon, XIcon } from '../components/icons'
import { MessageThreadView } from '../components/MessagesPanel'
import { EmptyState, ErrorState, SkeletonList } from '../components/ui/primitives'
import { PageTitle, SortFilter } from '../components/ui/Proto'
import { formatDate } from '../lib/format'
import { messages as messagesApi } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import type { MessageThreadSummary } from '../lib/types'

/*
 * Messages (revised GUI screens 8-10 applicant, 101-102 staff): the dedicated
 * page the in-application panel was standing in for. Conversation list on the
 * left — search, sort, one card per application — and the open thread on the
 * right behind its light-blue name bar. Both sides read the same screen; the
 * conversation is named after whoever the reader is talking to.
 */

type Sort = 'recent' | 'oldest'

/** Royal circle with a person glyph, the GUI's conversation avatar. */
function Avatar({ size = 44 }: { size?: number }) {
  return (
    <span
      aria-hidden="true"
      className="flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-royal text-white"
      style={{ width: size, height: size }}
    >
      <svg viewBox="0 0 24 24" className="h-full w-full fill-current" style={{ marginTop: size * 0.16 }}>
        <circle cx="12" cy="8.5" r="3.6" />
        <path d="M12 13.6c-4.1 0-6.6 2.5-6.6 6.4h13.2c0-3.9-2.5-6.4-6.6-6.4Z" />
      </svg>
    </span>
  )
}

function ThreadCard({
  thread,
  active,
  onOpen,
}: {
  thread: MessageThreadSummary
  active: boolean
  onOpen: () => void
}) {
  const last = thread.last_message
  const preview = last
    ? `${last.mine ? 'You' : (last.sender_name ?? thread.counterparty.name)}: ${last.body}`
    : 'No messages yet. Start the conversation.'

  return (
    <li>
      <button
        type="button"
        onClick={onOpen}
        aria-current={active ? 'true' : undefined}
        className={`flex w-full items-center gap-4 rounded-xl bg-white px-5 py-4 text-left shadow-card transition-shadow hover:shadow-raised ${
          active ? 'ring-2 ring-royal' : ''
        }`}
      >
        <Avatar />
        <span className="min-w-0 flex-1">
          <span className="flex items-baseline gap-2">
            <span className="min-w-0 flex-1 truncate text-[15px] font-bold text-ink">
              {thread.counterparty.name}
              {thread.counterparty.subtitle && (
                <span className="font-semibold italic text-ink-secondary">
                  {' '}
                  · {thread.counterparty.subtitle}
                </span>
              )}
            </span>
            <span className="shrink-0 text-xs italic text-ink-muted">
              {formatDate(thread.updated_at)}
            </span>
          </span>
          <span className="mt-0.5 block truncate text-sm text-ink-secondary">{preview}</span>
        </span>
      </button>
    </li>
  )
}

export function MessagesPage() {
  const { data, loading, error, reload } = useAsync<MessageThreadSummary[]>(
    () => messagesApi.threads(),
    [],
  )
  const [params, setParams] = useSearchParams()
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<Sort>('recent')

  const threads = data ?? []
  const selectedId = Number(params.get('application')) || null
  const selected = threads.find((t) => t.application_id === selectedId) ?? null

  // On a wide screen an empty pane is wasted space: open the newest thread.
  useEffect(() => {
    if (selectedId || threads.length === 0) return
    if (window.matchMedia('(min-width: 1024px)').matches) {
      setParams({ application: String(threads[0].application_id) }, { replace: true })
    }
  }, [selectedId, threads, setParams])

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const matched = needle
      ? threads.filter((t) =>
          [
            t.counterparty.name,
            t.counterparty.subtitle,
            t.business_name,
            t.tracking_id,
            t.last_message?.body,
          ]
            .filter(Boolean)
            .some((field) => (field as string).toLowerCase().includes(needle)),
        )
      : threads
    const ordered = [...matched].sort(
      (a, b) => Date.parse(a.updated_at ?? '') - Date.parse(b.updated_at ?? ''),
    )
    return sort === 'recent' ? ordered.reverse() : ordered
  }, [threads, query, sort])

  function open(applicationId: number) {
    setParams({ application: String(applicationId) })
  }

  const list = (
    <div className={selected ? 'hidden lg:block' : ''}>
      <PageTitle
        right={
          <SortFilter
            sort={{
              value: sort,
              options: [
                { value: 'recent', label: 'Most recent' },
                { value: 'oldest', label: 'Oldest first' },
              ],
              onChange: (v) => setSort(v as Sort),
            }}
          />
        }
      >
        Messages
      </PageTitle>

      <label className="relative mb-5 block">
        <span className="sr-only">Search messages</span>
        <SearchIcon
          size={18}
          className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-ink-secondary"
        />
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search messages"
          className="w-full rounded-full bg-royal-tint py-2.5 pl-11 pr-4 text-sm text-ink placeholder:text-ink-secondary focus:outline-none focus:ring-2 focus:ring-royal"
        />
      </label>

      {loading ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : visible.length === 0 ? (
        <EmptyState
          icon={MailIcon}
          title={query ? 'No conversations match' : 'No conversations yet'}
          description={
            query
              ? 'Try a different name, business, or tracking number.'
              : 'Messages about an application will show up here once the conversation starts.'
          }
        />
      ) : (
        <ul className="flex flex-col gap-4">
          {visible.map((t) => (
            <ThreadCard
              key={t.application_id}
              thread={t}
              active={t.application_id === selectedId}
              onOpen={() => open(t.application_id)}
            />
          ))}
        </ul>
      )}
    </div>
  )

  // "Central Perk · BIZ-2026-00005", never the same value twice.
  const paneSubtitle = selected
    ? [selected.counterparty.subtitle, selected.tracking_id]
        .filter((part, i, all): part is string => Boolean(part) && all.indexOf(part) === i)
        .join(' · ')
    : ''

  const pane = selected ? (
    <section
      aria-label={`Conversation with ${selected.counterparty.name}`}
      className="flex min-h-[32rem] flex-col overflow-hidden rounded-xl bg-white shadow-card lg:h-[calc(100dvh-9rem)]"
    >
      <header className="flex items-center gap-3 bg-royal-tint px-5 py-4">
        <Avatar size={38} />
        <div className="min-w-0 flex-1">
          <p className="truncate text-base font-bold text-ink">{selected.counterparty.name}</p>
          <p className="truncate text-sm italic text-ink-secondary">{paneSubtitle}</p>
        </div>
        <button
          type="button"
          onClick={() => setParams({})}
          aria-label="Close conversation"
          className="shrink-0 rounded-md p-1 text-royal hover:bg-white/60"
        >
          <XIcon size={22} />
        </button>
      </header>

      <MessageThreadView
        key={selected.application_id}
        applicationId={selected.application_id}
        className="flex-1 px-5 pb-5 pt-4"
        scrollClassName="min-h-0"
        onSent={reload}
      />
    </section>
  ) : (
    <div className="hidden items-center justify-center rounded-xl bg-white p-10 shadow-card lg:flex">
      <p className="text-sm text-ink-secondary">
        Choose a conversation on the left to read and reply.
      </p>
    </div>
  )

  return (
    <div className="grid gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
      {list}
      {pane}
    </div>
  )
}

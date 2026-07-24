import { useState } from 'react'
import { Link } from 'react-router-dom'
import type { ReactNode } from 'react'
import { BellIcon, ChevronRightIcon } from '../components/icons'
import { PageTitle } from '../components/ui/Proto'
import { EmptyState, ErrorState, SkeletonList } from '../components/ui/primitives'
import { formatDateTime } from '../lib/format'
import { notifications } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import type { Notification } from '../lib/types'

/** Blue avatar circle with a white person glyph, per the prototype rows (PDF p19). */
function AvatarCircle() {
  return (
    <span
      aria-hidden="true"
      className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#7796c5]"
    >
      <svg viewBox="0 0 24 24" className="mt-2 h-9 w-9 fill-white">
        <circle cx="12" cy="8" r="4" />
        <path d="M12 13.5c-4.4 0-7 2.6-7 6.5h14c0-3.9-2.6-6.5-7-6.5Z" />
      </svg>
    </span>
  )
}

export function NotificationsPage() {
  const { data, loading, error, reload, setData } = useAsync(() => notifications.list(), [])
  const [markingAll, setMarkingAll] = useState(false)
  const items: Notification[] = data?.data ?? []
  const unread = data?.unread ?? 0

  async function markAll() {
    setMarkingAll(true)
    try {
      await notifications.readAll()
      setData((prev) =>
        prev
          ? { data: prev.data.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })), unread: 0 }
          : prev!,
      )
    } finally {
      setMarkingAll(false)
    }
  }

  async function markOne(n: Notification) {
    if (n.read_at) return
    try {
      await notifications.read(n.id)
      setData((prev) =>
        prev
          ? {
              data: prev.data.map((x) => (x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x)),
              unread: Math.max(0, prev.unread - 1),
            }
          : prev!,
      )
    } catch {
      // Non-fatal: reading is best-effort.
    }
  }

  return (
    <div>
      <PageTitle
        right={
          unread > 0 ? (
            <button
              type="button"
              onClick={markAll}
              disabled={markingAll}
              className="pb-1 text-sm font-semibold text-royal hover:underline disabled:opacity-60"
            >
              Mark all as read
            </button>
          ) : undefined
        }
      >
        Notifications
      </PageTitle>

      {loading ? (
        <SkeletonList rows={4} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <EmptyState
          icon={BellIcon}
          title="No notifications"
          description="Updates about your applications, payments, and permits will show up here."
        />
      ) : (
        <ul className="flex flex-col gap-4">
          {items.map((n) => {
            const row: ReactNode = (
              <div className="flex items-center gap-4 px-5 py-4">
                <AvatarCircle />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[15px] font-bold text-ink">
                    {n.title}
                    {!n.read_at && <span className="sr-only"> (unread)</span>}
                  </p>
                  <p className="mt-0.5 truncate text-sm text-ink-secondary">{n.body}</p>
                </div>
                <span className="shrink-0 text-sm italic text-ink-muted">{formatDateTime(n.created_at)}</span>
                <ChevronRightIcon size={18} className="shrink-0 text-ink-secondary" />
              </div>
            )
            return (
              <li key={n.id} onClick={() => markOne(n)} className="rounded-xl bg-white shadow-card">
                {n.link ? (
                  <Link to={n.link} className="block rounded-xl transition-shadow hover:shadow-raised">
                    {row}
                  </Link>
                ) : (
                  <div className="rounded-xl transition-shadow hover:shadow-raised">{row}</div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

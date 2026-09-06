import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import type { ComponentType, ReactNode, SVGProps } from 'react'
import {
  AlertCircleIcon,
  AlertTriangleIcon,
  BellIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  ClipboardIcon,
  ClockIcon,
  EyeIcon,
  FileTextIcon,
  MailIcon,
  PaymentsIcon,
  ShieldCheckIcon,
  XCircleIcon,
} from '../components/icons'
import { PageTitle } from '../components/ui/Proto'
import { EmptyState, ErrorState, SkeletonList } from '../components/ui/primitives'
import { formatDateTime } from '../lib/format'
import { notifications } from '../lib/resources'
import { useAsync } from '../lib/useAsync'
import type { Notification, User } from '../lib/types'
import { useAuth } from '../stores/auth'
import { useNotifications } from '../stores/notifications'

/*
 * Every row used to carry the same person-avatar glyph, which told the reader
 * nothing: a permit about to lapse looked exactly like a new message. The
 * mockup (`updated-gui/120.png`) gives each kind of news its own icon and
 * colour, so a list can be triaged at a glance instead of read line by line.
 *
 * Colour never carries the meaning on its own (PRODUCT.md, WCAG 2.1 AA): each
 * tone has a distinct glyph shape and the title says in words what happened.
 */

type Tone = 'warning' | 'pending' | 'success' | 'info'

/** Tinted disc, ring, and glyph per tone — all from the tokens in index.css. */
const TONES: Record<Tone, string> = {
  warning: 'bg-red-50 ring-red-200 text-red-600',
  pending: 'bg-amber-50 ring-amber-200 text-amber-800',
  success: 'bg-green-50 ring-green-200 text-green-700',
  info: 'bg-blue-50 ring-blue-100 text-royal',
}

type Glyph = ComponentType<SVGProps<SVGSVGElement> & { size?: number }>

/**
 * The status label a status-change notification is about.
 *
 * NotificationService writes the body as `{tracking} is now “{Label}”.`, so the
 * stage is in there — it just is not a field. Reading it back is a coupling to
 * that copy, and a deliberate one: the alternative is re-typing ten thousand
 * existing rows on a database with live testers in it. If the copy ever drifts,
 * the match fails and the row falls back to the neutral "update" styling, which
 * is the same thing every row looked like before this change.
 */
function statusLabelIn(body: string): string | null {
  return /is now [“"]([^”"]+)[”"]/.exec(body)?.[1] ?? null
}

/** Which icon and colour a notification gets, and why. */
function appearanceOf(n: Notification): { tone: Tone; Glyph: Glyph } {
  // Renewal reminders and lapse notices (r-integration-spec.md §3).
  if (n.type === 'expiry') {
    return { tone: 'warning', Glyph: AlertTriangleIcon }
  }

  if (n.type === 'decision') {
    return /reject/i.test(n.title)
      ? { tone: 'warning', Glyph: XCircleIcon }
      : { tone: 'success', Glyph: CheckCircleIcon }
  }

  if (n.type === 'issuance') {
    return { tone: 'success', Glyph: ShieldCheckIcon }
  }

  if (n.type === 'status_change') {
    switch (statusLabelIn(n.body)) {
      case 'Approved':
        return { tone: 'success', Glyph: CheckCircleIcon }
      case 'Rejected':
      case 'Cancelled':
        return { tone: 'warning', Glyph: XCircleIcon }
      /*
       * Both spellings, deliberately.
       *
       * The statuses were renamed to the LGU's vocabulary — "Awaiting payment"
       * became "Pending Payment", "Under review" became "For Approval" — but a
       * notification body is a sentence written at the time it was sent, and
       * thousands of them are already sitting in the database saying the old
       * words. Matching only the new ones would have quietly greyed out every
       * notification a live tester had already received, which is exactly the
       * kind of regression a rename is expected not to cause. Old rows keep
       * their icon; new rows get the same icon under the new name.
       */
      case 'Returned':
      case 'Returned for revision':
        // Waiting on the applicant, not on the office — worth flagging.
        return { tone: 'pending', Glyph: AlertCircleIcon }
      case 'For Inspection':
      case 'For inspection':
        return { tone: 'pending', Glyph: ClockIcon }
      case 'Pending Payment':
      case 'Awaiting payment':
        return { tone: 'pending', Glyph: PaymentsIcon }
      case 'For Approval':
      case 'Under review':
        return { tone: 'pending', Glyph: EyeIcon }
      default:
        // Submitted, Draft, and anything the copy stops matching.
        return { tone: 'info', Glyph: FileTextIcon }
    }
  }

  // An officer is asking the applicant for something: their move.
  if (n.type === 'request') {
    return { tone: 'pending', Glyph: ClipboardIcon }
  }
  if (n.type === 'fee') {
    return { tone: 'pending', Glyph: PaymentsIcon }
  }
  if (n.type === 'message') {
    return { tone: 'info', Glyph: MailIcon }
  }

  return { tone: 'info', Glyph: BellIcon }
}

function NotificationIcon({ notification }: { notification: Notification }) {
  const { tone, Glyph } = appearanceOf(notification)

  return (
    <span
      aria-hidden="true"
      className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full ring-1 ring-inset ${TONES[tone]}`}
    >
      <Glyph size={22} />
    </span>
  )
}

/**
 * The empty state has to speak to whoever is reading it. An officer is not
 * waiting on "your applications, payments, and permits"; they are waiting on
 * work arriving in their queue.
 */
function emptyStateFor(user: User | null): string {
  const can = (permission: string) => user?.permissions.includes(permission) ?? false
  if (can('user.manage')) {
    return 'System alerts, officer assignments, and escalations will show up here.'
  }
  if (can('application.review')) {
    return 'Applications routed to your office, applicant replies, and inspection updates will show up here.'
  }
  return 'Updates about your applications, payments, and permits will show up here.'
}

export function NotificationsPage() {
  const { data, loading, error, reload, setData } = useAsync(() => notifications.list(), [])
  const [markingAll, setMarkingAll] = useState(false)
  const user = useAuth((s) => s.user)
  const emptyDescription = emptyStateFor(user)
  const items: Notification[] = data?.data ?? []
  const unread = data?.unread ?? 0
  const setUnread = useNotifications((s) => s.setUnread)

  /*
   * This page's own count is the freshest one in the app — it was just fetched
   * with the list — so it seeds the badge rather than waiting for the poller's
   * next tick. It also corrects the badge after somebody reads a notification in
   * another tab: this fetch sees the true figure, the store may not have yet.
   */
  useEffect(() => {
    if (data) setUnread(data.unread)
  }, [data, setUnread])

  /*
   * Both handlers below tell the shared store as well as their own state.
   *
   * The bell's badge reads that store, and it is refreshed by a 30-second
   * poller. Without these two lines, marking everything read here would leave a
   * count sitting over the bell for up to half a minute — on the one screen that
   * has just finished proving it wrong. Setting it directly is not a duplicate
   * of the poll; it is the answer arriving before the question is next asked.
   */
  async function markAll() {
    setMarkingAll(true)
    try {
      await notifications.readAll()
      setData((prev) =>
        prev
          ? { data: prev.data.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })), unread: 0 }
          : prev!,
      )
      setUnread(0)
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
      setUnread(unread - 1)
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
        <EmptyState icon={BellIcon} title="No notifications" description={emptyDescription} />
      ) : (
        <ul className="flex flex-col gap-4">
          {items.map((n) => {
            const unreadRow = !n.read_at

            /*
             * ── Telling read from unread, which the list could not do ───────
             *
             * Every row was `bg-white shadow-card` with a bold title, read or
             * not. The ONLY difference was an `sr-only` "(unread)" — so a screen
             * reader was told and a sighted reader was not, which is the
             * accessibility trade backwards: the accessible name was carrying a
             * distinction the design never made.
             *
             * Gmail's convention, adapted to this palette. The page is canvas
             * (#d1dbeb) and cards are white, so "forward" is white and
             * "recessed" is the tint: unread rows keep the white card and its
             * shadow, read rows drop to `royal-tint` and lose the lift. On a
             * blue-grey page that reads as settled rather than as disabled.
             *
             * ── Three carriers, so it is never colour alone ─────────────────
             *
             * DESIGN.md's rule, and a list where one third of the rows differ
             * only by background is exactly what it is for:
             *
             *   1. WEIGHT — bold title unread, medium and muted read. Survives
             *      greyscale, survives every kind of colour blindness.
             *   2. THE DOT — a filled royal disc before the title, present only
             *      when unread. A shape, not a shade.
             *   3. THE ACCENT BAR — a royal left border, transparent when read
             *      so the text still lines up and rows do not jump 4px as they
             *      are opened.
             *
             * The `sr-only` "(unread)" stays. It is now saying the same thing as
             * the visuals rather than standing in for them.
             */
            const row: ReactNode = (
              <div className="flex items-center gap-4 px-5 py-4">
                <NotificationIcon notification={n} />
                <div className="min-w-0 flex-1">
                  <p
                    className={`flex items-center gap-2 truncate text-[15px] ${
                      unreadRow ? 'font-bold text-ink' : 'font-medium text-ink-secondary'
                    }`}
                  >
                    {unreadRow && (
                      <span
                        aria-hidden="true"
                        className="h-2 w-2 shrink-0 rounded-full bg-royal"
                      />
                    )}
                    <span className="truncate">{n.title}</span>
                    {unreadRow && <span className="sr-only"> (unread)</span>}
                  </p>
                  <p
                    className={`mt-0.5 truncate text-sm ${
                      unreadRow ? 'text-ink-secondary' : 'text-ink-muted'
                    }`}
                  >
                    {n.body}
                  </p>
                </div>
                <span className="shrink-0 text-sm italic text-ink-muted">{formatDateTime(n.created_at)}</span>
                <ChevronRightIcon size={18} className="shrink-0 text-ink-secondary" />
              </div>
            )
            const shell = `overflow-hidden rounded-xl border-l-4 ${
              unreadRow ? 'border-royal bg-white shadow-card' : 'border-transparent bg-royal-tint'
            }`
            return (
              <li key={n.id} onClick={() => markOne(n)} className={shell}>
                {n.link ? (
                  <Link to={n.link} className="block transition-shadow hover:shadow-raised">
                    {row}
                  </Link>
                ) : (
                  <div className="transition-shadow hover:shadow-raised">{row}</div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { notifications } from '../lib/resources'
import type { Notification } from '../lib/types'
import { useNotifications } from '../stores/notifications'
import { AlertTriangleIcon, CheckCircleIcon, XCircleIcon } from './icons'
import { Modal } from './ui/Modal'

/**
 * A revoked permit is told to the owner in a modal, not only in the list.
 *
 * [Client, 5 October 2026: "once it happened it should be may modal din sa
 * side ng business owner na lalabas for notif, at magrereflect din sa notif".]
 * A revocation means the business may no longer trade on that certificate —
 * the one notice an owner must not scroll past — so the first screen they open
 * after it raises this, whichever page that is (it sits in the AppShell).
 *
 * It reads the same notice the bell counts (type `permit_revoked`, written by
 * NotificationService::permitRevoked), so the two cannot disagree. Closing it,
 * or following it to the permits page, marks that notice read: the modal says
 * it once, and the notification stays in the list for the record. Several
 * revoked at once are shown one after another, oldest first.
 *
 * Since 5 October 2026 it raises every status change an office makes from
 * Change status, not only a revocation: suspended, retired, rejected, and
 * active again — each typed by NotificationService, each said once.
 *
 * It looks again whenever the unread count changes, so a revocation that lands
 * while the owner is signed in appears on the next poll rather than at the
 * next sign-in.
 */
/** The notices this modal raises, and how each one looks and reads. */
const KINDS: Record<
  string,
  { description: string; tone: string; Icon: typeof XCircleIcon; scan: string | null }
> = {
  permit_revoked: {
    description: 'This permit is no longer valid.',
    tone: 'bg-s-red-tint text-s-red',
    Icon: XCircleIcon,
    scan: 'revoked',
  },
  permit_rejected: {
    description: 'This permit is no longer valid.',
    tone: 'bg-s-red-tint text-s-red',
    Icon: XCircleIcon,
    scan: 'rejected',
  },
  permit_suspended: {
    description: 'This permit is not valid while the suspension stands.',
    tone: 'bg-s-purple-tint text-s-purple',
    Icon: AlertTriangleIcon,
    scan: 'suspended',
  },
  permit_retired: {
    description: 'This permit is closed.',
    tone: 'bg-canvas text-ink-secondary',
    Icon: XCircleIcon,
    scan: 'retired',
  },
  permit_reactivated: {
    description: 'This permit is valid again.',
    tone: 'bg-s-green-tint text-s-green',
    Icon: CheckCircleIcon,
    scan: null,
  },
}

export function PermitRevokedModal() {
  const unread = useNotifications((s) => s.unread)
  const setUnread = useNotifications((s) => s.setUnread)
  const navigate = useNavigate()
  const [queue, setQueue] = useState<Notification[]>([])

  useEffect(() => {
    if (unread === 0) return
    let cancelled = false
    notifications
      .list({ per_page: 50 })
      .then((res) => {
        if (cancelled) return
        const revoked = res.data
          .filter((n) => n.type in KINDS && n.read_at === null)
          .reverse()
        setQueue(revoked)
      })
      .catch(() => {
        /* A failed look is not something the owner asked for; the bell still has it. */
      })
    return () => {
      cancelled = true
    }
  }, [unread])

  const current = queue[0] ?? null

  const acknowledge = useCallback(
    async (then?: string) => {
      if (!current) return
      setQueue((q) => q.slice(1))
      setUnread(Math.max(0, unread - 1))
      try {
        await notifications.read(current.id)
      } catch {
        /* Marked on screen; the next poll corrects the count if the write failed. */
      }
      if (then) navigate(then)
    },
    [current, navigate, setUnread, unread],
  )

  if (!current) return null
  const kind = KINDS[current.type] ?? KINDS.permit_revoked

  return (
    <Modal
      open
      onClose={() => acknowledge()}
      title={current.title}
      description={kind.description}
      footer={
        <>
          <button
            type="button"
            onClick={() => acknowledge()}
            className="rounded-full border border-line bg-white px-5 py-2 text-sm font-semibold text-ink-secondary hover:border-ink hover:text-ink"
          >
            {/* Not "Close": the X in the header is already called that. */}
            I understand
          </button>
          <button
            type="button"
            onClick={() => acknowledge(current.link ?? '/permits')}
            className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal/90"
          >
            View my permits
          </button>
        </>
      }
    >
      <div className="flex gap-3">
        <span className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${kind.tone}`}>
          <kind.Icon size={22} />
        </span>
        <div className="min-w-0 space-y-2 text-sm leading-relaxed text-ink-secondary">
          <p>{current.body}</p>
          <p className="text-xs text-ink-muted">
            {kind.scan
              ? `Anyone who scans this permit’s QR code is now told it is ${kind.scan}. `
              : 'Anyone who scans this permit’s QR code is told it is valid. '}
            This notice stays in your notifications.
          </p>
        </div>
      </div>
    </Modal>
  )
}

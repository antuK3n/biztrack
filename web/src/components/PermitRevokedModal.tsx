import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { notifications } from '../lib/resources'
import type { Notification } from '../lib/types'
import { useNotifications } from '../stores/notifications'
import { XCircleIcon } from './icons'
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
 * It looks again whenever the unread count changes, so a revocation that lands
 * while the owner is signed in appears on the next poll rather than at the
 * next sign-in.
 */
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
          .filter((n) => n.type === 'permit_revoked' && n.read_at === null)
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

  return (
    <Modal
      open
      onClose={() => acknowledge()}
      title={current.title}
      description="This permit is no longer valid."
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
        <span className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-s-red-tint text-s-red">
          <XCircleIcon size={22} />
        </span>
        <div className="min-w-0 space-y-2 text-sm leading-relaxed text-ink-secondary">
          <p>{current.body}</p>
          <p className="text-xs text-ink-muted">
            Anyone who scans this permit&rsquo;s QR code is now told it has been revoked. This notice stays in your
            notifications.
          </p>
        </div>
      </div>
    </Modal>
  )
}

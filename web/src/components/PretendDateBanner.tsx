import { useEffect, useState } from 'react'
import { SYSTEM_NOTICES_CHANGED, systemNotices } from '../lib/resources'
import { formatCalendarDate } from '../lib/format'
import { CalendarIcon } from './icons'

/*
 * "Renewal dates are being simulated as …", over every signed-in screen while
 * the Debug page's pretend date is set [Ken, 2026-10-04].
 *
 * The pretend date changes what a renewal costs — a late surcharge appears, a
 * permit shows as expired — so nobody looking at a screen may mistake it for
 * the real date: an officer reviewing a bill, an owner reading their permit, a
 * panelist watching. Hence on every screen, for every account, and with no
 * dismiss button: unlike the office-hours notice, it is not something a reader
 * can be done with.
 *
 * Asked every 30 seconds (the same pace as the unread badges) and at once when
 * the Debug page changes it, so it appears and disappears without a reload.
 * Nothing while the date is real, or if the question cannot be answered.
 */
const POLL_MS = 30_000

export function PretendDateBanner({ className = '' }: { className?: string }) {
  const [date, setDate] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    const ask = () => {
      systemNotices
        .get()
        .then((n) => {
          if (!cancelled) setDate(n.pretend_date)
        })
        .catch(() => {
          /* keep what was last known */
        })
    }

    ask()
    const timer = window.setInterval(ask, POLL_MS)
    window.addEventListener(SYSTEM_NOTICES_CHANGED, ask)
    return () => {
      cancelled = true
      window.clearInterval(timer)
      window.removeEventListener(SYSTEM_NOTICES_CHANGED, ask)
    }
  }, [])

  if (!date) return null

  return (
    <div
      role="status"
      className={`mb-5 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-ink print:hidden ${className}`}
    >
      <CalendarIcon size={18} aria-hidden="true" className="mt-0.5 shrink-0 text-amber-800" />
      <p>
        <span className="font-semibold">Renewal dates are being simulated as {formatCalendarDate(date)}.</span>{' '}
        Renewal deadlines, late surcharges and the days left on permits use that date; everything
        else is real.
      </p>
    </div>
  )
}

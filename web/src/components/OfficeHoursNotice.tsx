import { useEffect, useState } from 'react'
import { officeHours } from '../lib/resources'
import type { OfficeHoursStatus } from '../lib/resources'
import { ClockIcon, XIcon } from './icons'

/*
 * "City offices are closed now" [checklist 2026-09-27, Login 6].
 *
 * The answer comes from GET /office-hours, which works it out on the server in
 * Manila time from config/office_hours.php. Nothing here reads the browser's
 * clock or knows the hours: a laptop on another timezone, or hours BPLO later
 * changes, would otherwise make the notice wrong in a way nobody could see.
 *
 * Non-blocking by design. Owners can file at any hour; the notice only says
 * when the City will pick it up. Officers are told their access is recorded,
 * because it is (AuthController writes `user.signed_in_outside_hours`).
 *
 * Informational, so the quiet neutral surface and a clock — not amber, not
 * red. Nothing is wrong. It can be dismissed for the rest of the visit; the
 * choice is kept in sessionStorage, so a new visit shows it again.
 *
 * If the request fails the notice simply does not appear. It is a courtesy,
 * and an error box about office hours would be louder than the thing it failed
 * to say.
 */

const DISMISSED_KEY = 'biztrack.office_hours_dismissed'

const DAY_NAMES = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

/** "8:00 AM" from "08:00". */
function clock(hhmm: string): string {
  const [h, m] = hhmm.split(':').map(Number)
  if (!Number.isFinite(h) || !Number.isFinite(m)) return hhmm
  const suffix = h >= 12 ? 'PM' : 'AM'
  const hour = h % 12 === 0 ? 12 : h % 12
  return `${hour}:${String(m).padStart(2, '0')} ${suffix}`
}

/** "Monday to Friday, 8:00 AM to 5:00 PM", from the server's config. */
function hoursLabel(status: OfficeHoursStatus): string {
  const days = [...status.days].sort((a, b) => a - b)
  const contiguous = days.every((d, i) => i === 0 || d === days[i - 1] + 1)
  const dayText =
    days.length === 0
      ? ''
      : contiguous && days.length > 1
        ? `${DAY_NAMES[days[0]]} to ${DAY_NAMES[days[days.length - 1]]}`
        : days.map((d) => DAY_NAMES[d]).join(', ')
  return `${dayText}, ${clock(status.opens)} to ${clock(status.closes)}`
}

export function OfficeHoursNotice({ audience, className = '' }: { audience: 'owner' | 'staff'; className?: string }) {
  const [status, setStatus] = useState<OfficeHoursStatus | null>(null)
  const [dismissed, setDismissed] = useState(() => {
    try {
      return sessionStorage.getItem(DISMISSED_KEY) === '1'
    } catch {
      return false
    }
  })

  useEffect(() => {
    if (dismissed) return
    let active = true
    officeHours
      .get()
      .then((s) => {
        if (active) setStatus(s)
      })
      .catch(() => {
        /* a courtesy notice; say nothing rather than an error */
      })
    return () => {
      active = false
    }
  }, [dismissed])

  if (dismissed || !status || status.open) return null

  function dismiss() {
    try {
      sessionStorage.setItem(DISMISSED_KEY, '1')
    } catch {
      /* private mode: dismissed for this page only */
    }
    setDismissed(true)
  }

  return (
    <div
      role="status"
      className={`mb-5 flex items-start gap-2.5 rounded-md border border-line bg-white px-3.5 py-3 text-sm text-ink ${className}`}
    >
      <ClockIcon size={20} className="mt-px shrink-0 text-ink-secondary" />
      <p className="min-w-0 flex-1">
        {audience === 'owner' ? (
          <>City offices are closed now. You can still file; offices will process it on the next working day.</>
        ) : (
          <>
            City offices are closed now ({hoursLabel(status)}). Sign-ins outside office hours are recorded in the
            audit log.
          </>
        )}
      </p>
      <button
        type="button"
        onClick={dismiss}
        aria-label="Dismiss the office hours notice"
        className="-m-1 shrink-0 rounded p-1 text-ink-muted hover:bg-canvas hover:text-ink"
      >
        <XIcon size={16} />
      </button>
    </div>
  )
}
